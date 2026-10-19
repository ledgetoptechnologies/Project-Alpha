<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

/** Root-owned material is immutable; outcome updates never rewrite the secret. */
final class LocalOperatorKeyReceipt
{
    private $handle=null;
    public function __construct(private string $path)
    {
        ApiV2CapabilitiesKeyProvisioner::operator();self::safePath($path);
    }
    public static function safePath(string $path): void
    {
        if (!str_starts_with($path,'/') || preg_match('/[\x00-\x1f\x7f]/',$path) || str_contains($path,'//')
            || preg_match('#/(?:\.|\.\.)(?:/|$)#',$path)) throw new RuntimeException('unsafe_private_path');
        $publicRoots=[dirname(__DIR__,2).'/public','/var/www/public','/var/www/html'];
        foreach($publicRoots as $root)if($path===$root||str_starts_with($path,$root.'/'))throw new RuntimeException('webroot_output_forbidden');
        $parent=dirname($path);$parts=explode('/',trim($parent,'/'));$current='';
        foreach($parts as $part){$current.='/'.$part;$st=@lstat($current);
            if(!$st||($st['mode']&0170000)!==0040000||$st['uid']!==0)throw new RuntimeException('unsafe_private_ancestor');
            // A root-owned sticky /tmp cannot have a root-owned child replaced
            // by another user; every other writable ancestor is refused.
            if(($st['mode']&0022)!==0&&($st['mode']&01000)===0)throw new RuntimeException('writable_private_ancestor');
        }
        $st=lstat($parent);if(($st['mode']&0077)!==0)throw new RuntimeException('private_parent_must_be_0700');
        if(file_exists($path)||is_link($path))self::safeFile($path);
    }
    private static function safeFile(string $path): void
    {
        $s=@lstat($path);if(!$s||($s['mode']&0170000)!==0100000||$s['uid']!==0||($s['mode']&0077)!==0||$s['nlink']!==1)throw new RuntimeException('unsafe_private_file');
    }
    private static function syncDirectory(string $parent): void
    {
        $h=@fopen($parent,'r');if(!$h||!fsync($h))throw new RuntimeException('private_directory_sync_failed');fclose($h);
    }
    public static function writeNew(string $path,#[\SensitiveParameter] array $value): void
    {
        self::safePath($path);$old=umask(0077);
        try{$h=@fopen($path,'x+b');}finally{umask($old);}
        if(!$h)throw new RuntimeException('private_file_already_exists_or_unwritable');
        try{
            if(!chmod($path,0600))throw new RuntimeException('private_file_permissions_failed');
            $bytes=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if(fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h))throw new RuntimeException('private_file_sync_failed');
            self::safeFile($path);self::syncDirectory(dirname($path));
        }finally{fclose($h);}
    }
    public static function read(string $path): array
    {
        self::safePath($path);self::safeFile($path);$bytes=file_get_contents($path);
        if($bytes===false||strlen($bytes)>65536)throw new RuntimeException('invalid_private_receipt');
        $value=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);if(!is_array($value))throw new RuntimeException('invalid_private_receipt');return $value;
    }
    public function lock(): void
    {
        $lock=$this->path.'.lock';self::safePath($lock);$old=umask(0077);
        try{$h=@fopen($lock,'c+b');}finally{umask($old);}
        if(!$h||!chmod($lock,0600)){if($h)fclose($h);throw new RuntimeException('private_lock_unavailable');}
        self::safeFile($lock);
        if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);throw new RuntimeException('request_in_progress');}$this->handle=$h;
    }
    public function exists(): bool{return file_exists($this->path);}
    public function create(array $plan): array
    {
        if(!$this->handle)throw new RuntimeException('private_lock_required');
        $secret='pa_live_'.bin2hex(random_bytes(6)).'_'.bin2hex(random_bytes(32));
        $receipt=['version'=>1,'plan'=>$plan,'secret'=>$secret];self::writeNew($this->path,$receipt);return self::read($this->path);
    }
    public function material(array $plan): array
    {
        if(!$this->handle)throw new RuntimeException('private_lock_required');$r=self::read($this->path);
        if(array_keys($r)!==['version','plan','secret']||$r['version']!==1||$r['plan']!==$plan||!is_string($r['secret']))throw new RuntimeException('private_receipt_request_mismatch');return $r;
    }
    public function journal(array $result): void
    {
        if(!$this->handle)throw new RuntimeException('private_lock_required');
        // Each outcome is a new immutable file. No prior secret/status is replaced.
        self::writeNew($this->path.'.outcome-'.bin2hex(random_bytes(8)).'.json',$result);
    }
    public function __destruct(){if(is_resource($this->handle)){flock($this->handle,LOCK_UN);fclose($this->handle);}}
}
