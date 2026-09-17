<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class PortalProjectionDeliveryConfigService
{
    public const OUTBOUND_FLAG='portal_outbound_delivery_enabled';
    public const HOOKS_FLAG='portal_authoritative_hooks_enabled';

    /** @return array{outbound_enabled:bool,hooks_enabled:bool} */
    public function runtime(PDO $pdo):array
    {
        $values=[];$s=$pdo->prepare('SELECT config_key,config_value FROM app_config WHERE organization_id=0 AND config_key IN (?,?)');$s->execute([self::OUTBOUND_FLAG,self::HOOKS_FLAG]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row)$values[(string)$row['config_key']]=(string)$row['config_value'];
        return['outbound_enabled'=>filter_var($values[self::OUTBOUND_FLAG]??'0',FILTER_VALIDATE_BOOLEAN),'hooks_enabled'=>filter_var($values[self::HOOKS_FLAG]??'0',FILTER_VALIDATE_BOOLEAN)];
    }

    /** @param array<string,mixed> $input */
    public function saveRuntime(PDO $pdo,array $input):array
    {
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO app_config(organization_id,config_key,config_value)VALUES(0,?,?) ON CONFLICT(organization_id,config_key)DO UPDATE SET config_value=excluded.config_value':'INSERT INTO app_config(organization_id,config_key,config_value)VALUES(0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';$save=$pdo->prepare($sql);
        $save->execute([self::OUTBOUND_FLAG,!empty($input['outbound_enabled'])?'1':'0']);$save->execute([self::HOOKS_FLAG,!empty($input['hooks_enabled'])?'1':'0']);return$this->runtime($pdo);
    }

    /** @param array<string,mixed> $input */
    public function saveProfile(PDO $pdo,int $profileId,array $input,int $actorId):void
    {
        $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();$profile=PortalProjectionService::lockProfileContract($pdo,$profileId);$credentials=$this->credentials($profile,false);
            $enabled=!empty($input['delivery_enabled']);$keyId=$this->keyId((string)($input['delivery_key_id']??($profile['delivery_key_id']??'')));$submittedSecret=trim((string)($input['delivery_secret']??''));$authHeaders=$this->authHeaders((string)($input['delivery_auth_headers_json']??''),$credentials['authHeaders']??[]);
            $currentKey=(string)($profile['delivery_key_id']??'');$currentSecret=(string)($credentials['currentSecret']??'');$rotating=$currentKey!==''&&!hash_equals($currentKey,$keyId);
            if($currentKey!==''&&!$rotating&&$submittedSecret!==''&&($currentSecret===''||!hash_equals($currentSecret,$submittedSecret)))throw new DomainException('Changing the signing secret requires a new signing key ID.');
            if($rotating){$pending=$pdo->prepare('SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id=? AND delivered_at IS NULL AND (is_revocation=1 OR dead_lettered_at IS NULL)');$pending->execute([$profileId]);if((int)$pending->fetchColumn()>0)throw new DomainException('Deliver or administratively resolve pending projection records before rotating the signing key.');}
            $secret=$submittedSecret;if($secret==='')$secret=$rotating?'':$currentSecret;if($secret!==''&&(strlen($secret)<32||strlen($secret)>1000))throw new DomainException('The signing secret must be 32 to 1000 characters.');
            $previousKey=(string)($profile['delivery_previous_key_id']??'');$previousSecret=(string)($credentials['previousSecret']??'');$previousUntil=$profile['delivery_previous_valid_until']??null;
            if($rotating){if($secret==='')throw new DomainException('A new signing secret is required when rotating the key ID.');if($currentSecret!==''&&hash_equals($currentSecret,$secret))throw new DomainException('A rotated signing key ID requires a new signing secret.');$previousKey=$currentKey;$previousSecret=$currentSecret;$overlap=max(1,min(168,(int)($input['delivery_overlap_hours']??48)));$previousUntil=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$overlap.' hours')->format('Y-m-d H:i:s.u');}
            if($enabled){if($keyId===''||$secret==='')throw new DomainException('A key ID and encrypted signing secret are required before delivery can be enabled.');if(empty($profile['portal_route'])&&empty($profile['catalog_route']))throw new DomainException('Configure at least one HTTPS receiver route before enabling delivery.');}
            require_once __DIR__.'/../utils/crypto.php';$encrypted=crypto_encrypt(json_encode(['currentSecret'=>$secret,'previousSecret'=>$previousSecret,'authHeaders'=>$authHeaders],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));if($encrypted===null)throw new RuntimeException('APP_ENCRYPTION_KEY is required to save delivery credentials.');
            $timeout=max(2,min(30,(int)($input['delivery_timeout_seconds']??15)));$attempts=max(1,min(50,(int)($input['delivery_max_attempts']??12)));
            $pdo->prepare('UPDATE portal_integration_profiles SET delivery_enabled=?,delivery_key_id=?,delivery_previous_key_id=?,delivery_previous_valid_until=?,delivery_credentials_enc=?,delivery_timeout_seconds=?,delivery_max_attempts=?,updated_by=? WHERE id=?')->execute([$enabled?1:0,$keyId?:null,$previousKey?:null,$previousUntil,$encrypted,$timeout,$attempts,$actorId,$profileId]);if($owns)$pdo->commit();
        }catch(Throwable$error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    /** @return array{currentSecret:string,previousSecret:string,authHeaders:array<string,string>} */
    public function credentials(array $profile,bool $required=true):array
    {
        $encrypted=trim((string)($profile['delivery_credentials_enc']??''));if($encrypted===''){if($required)throw new RuntimeException('portal-delivery-credentials-unavailable');return['currentSecret'=>'','previousSecret'=>'','authHeaders'=>[]];}
        require_once __DIR__.'/../utils/crypto.php';$json=crypto_decrypt($encrypted);$data=is_string($json)?json_decode($json,true):null;if(!is_array($data)){if($required)throw new RuntimeException('portal-delivery-credentials-unreadable');return['currentSecret'=>'','previousSecret'=>'','authHeaders'=>[]];}
        $headers=is_array($data['authHeaders']??null)?$data['authHeaders']:[];return['currentSecret'=>(string)($data['currentSecret']??''),'previousSecret'=>(string)($data['previousSecret']??''),'authHeaders'=>array_map('strval',$headers)];
    }

    public static function validateDestination(string $url):array
    {
        $parts=parse_url($url);if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||isset($parts['query']))throw new DomainException('Receiver routes must be HTTPS URLs without credentials, query strings, or fragments.');return$parts;
    }

    private function keyId(string $value):string{$value=trim($value);if($value!==''&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D',$value)!==1)throw new DomainException('The signing key ID is invalid.');return$value;}
    /** @param array<string,string> $existing @return array<string,string> */
    private function authHeaders(string $json,array $existing):array
    {
        if(trim($json)==='')return$existing;$decoded=json_decode($json,true,16,JSON_THROW_ON_ERROR);if(!is_array($decoded)||array_is_list($decoded)||count($decoded)>4)throw new DomainException('Authentication headers must be a JSON object with at most four entries.');$out=[];
        foreach($decoded as$name=>$value){$name=(string)$name;$value=(string)$value;if(preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D',$name)!==1||in_array(strtolower($name),['host','content-length','content-type','connection','transfer-encoding','x-portal-integration-application-key','x-portal-integration-timestamp','x-portal-integration-body-sha256','x-portal-integration-key-id','x-portal-integration-delivery-id','x-portal-integration-signature'],true)||$value===''||strlen($value)>2048||preg_match('/[\r\n]/',$value))throw new DomainException('An authentication header is invalid or reserved.');$out[$name]=$value;}return$out;
    }
}
