<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

require_once __DIR__.'/OpsSnapshotReadKeyProvisioner.php';
require_once __DIR__.'/LocalOperatorKeyReceipt.php';

/** Snapshot secret storage only. Wrapping-key custody stays with the operator. */
final class EncryptedOpsSnapshotKeyReceipt
{
    public const KEY_ENV = 'PA_OPS_SNAPSHOT_RECEIPT_KEY_B64';
    private const FORMAT = 'pa.ops-snapshot-key.encrypted';
    private const ALGORITHM = 'aes-256-gcm';
    private LocalOperatorKeyReceipt $file;
    private string $key;
    private bool $locked = false;

    public function __construct(private string $path)
    {
        // Existing root/operator and safe-path checks; no plaintext create/material.
        $this->file=new LocalOperatorKeyReceipt($path);
        if(realpath(dirname($path))!==dirname($path))throw new RuntimeException('unsafe_private_path');
        $encoded=getenv(self::KEY_ENV);
        if(!is_string($encoded) || !preg_match('/^[A-Za-z0-9+\/]{43}=$/D',$encoded))throw new RuntimeException('encrypted_receipt_key_unavailable');
        $key=base64_decode($encoded,true);
        if($key===false || strlen($key)!==32 || base64_encode($key)!==$encoded)throw new RuntimeException('encrypted_receipt_key_unavailable');
        $this->key=$key;
        if(!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')
            || !in_array(self::ALGORITHM,openssl_get_cipher_methods(),true))throw new RuntimeException('encrypted_receipt_unavailable');
    }
    public function lock(): void { $this->file->lock(); $this->locked=true; }
    public function exists(): bool { return $this->file->exists(); }
    public function journal(array $result): void { $this->file->journal($result); }

    private static function canonical(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as &$entry)$entry=self::canonical($entry);
        unset($entry);return $value;
    }
    private function aad(array $plan): string
    {
        if(!$this->locked)throw new RuntimeException('private_lock_required');
        return json_encode(self::canonical(['format'=>self::FORMAT,'version'=>1,'algorithm'=>self::ALGORITHM,
            'path'=>$this->path,'plan'=>$plan]),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function decode(mixed $encoded,?int $size=null): string
    {
        if(!is_string($encoded) || strlen($encoded)>8192)throw new RuntimeException('encrypted_receipt_unavailable');
        $bytes=base64_decode($encoded,true);
        if($bytes===false || base64_encode($bytes)!==$encoded || ($size!==null && strlen($bytes)!==$size))throw new RuntimeException('encrypted_receipt_unavailable');
        return $bytes;
    }
    public function create(array $plan): array
    {
        $aad=$this->aad($plan);
        if($this->exists())throw new RuntimeException('encrypted_receipt_already_exists');
        $secret='pa_live_'.bin2hex(random_bytes(6)).'_'.bin2hex(random_bytes(32));
        $nonce=random_bytes(12);$tag='';
        $ciphertext=openssl_encrypt(json_encode(['secret'=>$secret],JSON_THROW_ON_ERROR),self::ALGORITHM,$this->key,
            OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if($ciphertext===false || strlen($tag)!==16)throw new RuntimeException('encrypted_receipt_unavailable');
        LocalOperatorKeyReceipt::writeNew($this->path,['format'=>self::FORMAT,'version'=>1,'algorithm'=>self::ALGORITHM,
            'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($ciphertext)]);
        // Read and authenticate the durable bytes before allowing database issuance.
        return $this->material($plan);
    }
    public function material(array $plan): array
    {
        $aad=$this->aad($plan);$envelope=LocalOperatorKeyReceipt::read($this->path);
        if(array_keys($envelope)!==['format','version','algorithm','nonce','tag','ciphertext']
            || $envelope['format']!==self::FORMAT || $envelope['version']!==1 || $envelope['algorithm']!==self::ALGORITHM)throw new RuntimeException('encrypted_receipt_unavailable');
        $plaintext=openssl_decrypt(self::decode($envelope['ciphertext']),self::ALGORITHM,$this->key,OPENSSL_RAW_DATA,
            self::decode($envelope['nonce'],12),self::decode($envelope['tag'],16),$aad);
        if($plaintext===false)throw new RuntimeException('encrypted_receipt_unavailable');
        $payload=json_decode($plaintext,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($payload) || array_keys($payload)!==['secret'] || !is_string($payload['secret'])
            || !preg_match('/^pa_live_[0-9a-f]{12}_[0-9a-f]{64}$/D',$payload['secret']))throw new RuntimeException('encrypted_receipt_unavailable');
        return ['version'=>1,'plan'=>$plan,'secret'=>$payload['secret']];
    }
    public function __destruct()
    {
        // Best effort only: PHP/environment copies are outside this object's custody.
        if(isset($this->key)){if(function_exists('sodium_memzero'))sodium_memzero($this->key);else $this->key='';}
    }
}
