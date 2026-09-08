<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class PortalProjectionOutboxSender
{
    private const CLAIM_TTL_SECONDS=300;

    /**
     * @param null|callable(string,list<string>,string,int):array{status:int,body?:string,error?:string} $transport
     * @return array{processed:int,delivered:int,failed:int,dead_lettered:int}
     */
    public function deliverDue(PDO $pdo,int $limit=25,?callable $transport=null,int $maxRuntimeSeconds=50):array
    {
        $runtime=(new PortalProjectionDeliveryConfigService())->runtime($pdo);if(!$runtime['outbound_enabled'])return['processed'=>0,'delivered'=>0,'failed'=>0,'dead_lettered'=>0];
        $limit=max(1,min(100,$limit));$deadline=microtime(true)+max(1,min(300,$maxRuntimeSeconds));$summary=['processed'=>0,'delivered'=>0,'failed'=>0,'dead_lettered'=>0];$transport??=[$this,'curlTransport'];
        for($index=0;$index<$limit;$index++){
            if(microtime(true)>=$deadline)break;
            $claim=$this->claimNext($pdo);if($claim===null)break;$summary['processed']++;
            try{$response=$this->sendClaim($pdo,$claim,$transport);$status=(int)($response['status']??0);if($status>=200&&$status<300){$this->finish($pdo,$claim,true,$status,null,false);$summary['delivered']++;continue;}
                $code=$status>=300&&$status<400?'redirect_rejected':($status===429?'http_429':($status>=400&&$status<500?'http_4xx':($status>=500?'http_5xx':'transport_failed')));$dead=$this->shouldDeadLetter($claim,$status);$this->finish($pdo,$claim,false,$status,$code,$dead);$dead?$summary['dead_lettered']++:$summary['failed']++;
            }catch(Throwable$error){$code=$this->safeErrorCode($error);$dead=((int)$claim['attempts']+1)>=(int)$claim['delivery_max_attempts'];$this->finish($pdo,$claim,false,0,$code,$dead);$dead?$summary['dead_lettered']++:$summary['failed']++;}
        }return$summary;
    }

    /** @return array<string,mixed>|null */
    private function claimNext(PDO $pdo):?array
    {
        $now=self::dbNow();$expired=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.self::CLAIM_TTL_SECONDS.' seconds')->format('Y-m-d H:i:s.u');
        $candidate=$pdo->prepare("SELECT o.id,o.integration_profile_id FROM portal_projection_outbox o JOIN portal_integration_profiles p ON p.id=o.integration_profile_id WHERE p.delivery_enabled=1 AND (o.is_revocation=1 OR (p.enabled=1 AND ((o.route_type='portal' AND p.portal_projection_enabled=1) OR (o.route_type='catalog' AND p.catalog_projection_enabled=1) OR (o.route_type='service_assignments' AND p.service_assignment_projection_enabled=1)))) AND o.delivered_at IS NULL AND o.dead_lettered_at IS NULL AND o.next_attempt_at<=? AND (o.claimed_at IS NULL OR o.claimed_at<?) AND o.attempts<p.delivery_max_attempts AND NOT EXISTS(SELECT 1 FROM portal_projection_outbox prior WHERE prior.integration_profile_id=o.integration_profile_id AND prior.workspace_public_id=o.workspace_public_id AND prior.route_type=o.route_type AND prior.id<o.id AND prior.delivered_at IS NULL AND prior.dead_lettered_at IS NULL AND (o.is_revocation=0 OR prior.is_revocation=1 OR (prior.claimed_at IS NOT NULL AND prior.claimed_at>=?))) ORDER BY o.id LIMIT 1");$candidate->execute([$now,$expired,$expired]);$id=$candidate->fetch(PDO::FETCH_ASSOC);if(!$id)return null;
        $pdo->beginTransaction();try{$profile=PortalProjectionService::lockProfileContract($pdo,(int)$id['integration_profile_id']);if(empty($profile['delivery_enabled'])){$pdo->rollBack();return null;}$suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=$pdo->prepare('SELECT * FROM portal_projection_outbox WHERE id=?'.$suffix);$row->execute([(int)$id['id']]);$claim=$row->fetch(PDO::FETCH_ASSOC);if(!$claim||!$this->profileAllows($profile,$claim)||!empty($claim['delivered_at'])||!empty($claim['dead_lettered_at'])||(!empty($claim['claimed_at'])&&(string)$claim['claimed_at']>=$expired)){$pdo->rollBack();return null;}if(!empty($claim['is_revocation'])){$pdo->prepare("UPDATE portal_projection_outbox SET dead_lettered_at=?,last_error_code='profile_disabled_superseded',claim_token=NULL,claimed_at=NULL WHERE integration_profile_id=? AND workspace_public_id=? AND route_type=? AND id<? AND is_revocation=0 AND delivered_at IS NULL AND dead_lettered_at IS NULL AND (claimed_at IS NULL OR claimed_at<?)")->execute([$now,(int)$claim['integration_profile_id'],(string)$claim['workspace_public_id'],(string)$claim['route_type'],(int)$claim['id'],$expired]);}$token=self::uuid();$pdo->prepare('UPDATE portal_projection_outbox SET claim_token=?,claimed_at=? WHERE id=?')->execute([$token,$now,(int)$claim['id']]);$pdo->commit();return array_merge($profile,$claim,['claim_token'=>$token,'profile_id'=>(int)$profile['id']]);}catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    /** @param array<string,mixed> $claim @return array{status:int,body?:string,error?:string} */
    private function sendClaim(PDO $pdo,array $claim,callable $transport):array
    {
        $config=(new ExternalOpsConfigService())->load($pdo);
        $issues=(array)($config['delivery_issues']??ExternalOpsConfigService::deliveryIssues($config));
        if(empty($config['configured_enabled'])||$issues!==[])throw new RuntimeException('external-operations-delivery-unavailable');
        if(!hash_equals((string)$config['application_key'],(string)$claim['application_key']))throw new RuntimeException('external-operations-application-mismatch');
        $route=(string)$config['webhook_url'];PortalProjectionDeliveryConfigService::validateDestination($route);
        $projection=json_decode((string)$claim['payload_json'],true,64,JSON_THROW_ON_ERROR);if(!is_array($projection))throw new RuntimeException('portal-projection-envelope-invalid');
        $projectionKind=(string)($claim['route_type']??'');if(!in_array($projectionKind,['portal','catalog','service_assignments'],true))throw new RuntimeException('portal-projection-kind-invalid');
        $deliveryId=(string)$claim['delivery_id'];$occurredAt=(string)($projection['occurredAt']??(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'));
        $event=['event_id'=>$deliveryId,'event_type'=>'portal.projection','occurred_at'=>$occurredAt,'schema_version'=>1,'application_key'=>(string)$config['application_key'],'projection_kind'=>$projectionKind,'projection'=>$projection];
        $body=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$timestamp=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $headers=['Content-Type: application/json','CF-Access-Client-Id: '.(string)$config['access_client_id'],'CF-Access-Client-Secret: '.(string)$config['access_client_secret'],'X-PA-Event-ID: '.$deliveryId,'X-PA-Timestamp: '.$timestamp];
        $signingHeaders=(string)($config['signing_mode']??ExternalOpsSigner::HMAC_SHA256)===ExternalOpsSigner::HMAC_SHA256
            ? ExternalOpsSigner::headersFromCredentials(['hmac_secret'=>(string)$config['hmac_secret']],$timestamp,$body)
            : (new ExternalOpsConfigService())->signingHeaders($pdo,$timestamp,$body);
        $headers=array_merge($headers,$signingHeaders);
        return$transport($route,$headers,$body,max(2,min(30,(int)$config['timeout_seconds'])));
    }

    /** @param array<string,mixed> $claim */
    private function finish(PDO $pdo,array $claim,bool $delivered,int $status,?string $errorCode,bool $dead):void
    {
        $attempt=(int)$claim['attempts']+1;$now=self::dbNow();$base=min(3600,30*(2**min(7,max(0,$attempt-1))));$next=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.($base+random_int(0,max(1,(int)floor($base*.25)))).' seconds')->format('Y-m-d H:i:s.u');
        $pdo->beginTransaction();try{
            PortalProjectionService::lockProfileContract($pdo,(int)$claim['integration_profile_id']);
            $updated=$pdo->prepare('UPDATE portal_projection_outbox SET attempts=?,next_attempt_at=?,delivered_at=?,dead_lettered_at=?,last_http_status=?,last_error_code=?,claim_token=NULL,claimed_at=NULL WHERE id=? AND claim_token=? AND delivered_at IS NULL AND dead_lettered_at IS NULL');
            $updated->execute([$attempt,$next,$delivered?$now:null,$dead?$now:null,$status?:null,$errorCode,(int)$claim['id'],(string)$claim['claim_token']]);
            if($updated->rowCount()===1&&(string)($claim['route_type']??'')==='portal'&&str_starts_with((string)($claim['delivery_kind']??''),'snapshot.')){
                $payload=json_decode((string)$claim['payload_json'],true,64,JSON_THROW_ON_ERROR);$generation=is_array($payload)?(string)($payload['sourceGeneration']??''):'';
                if($generation!==''){
                    if($dead){
                        $recovery=$pdo->prepare("UPDATE portal_projection_recoveries SET state='failed',failed_at=?,last_error_code=? WHERE integration_profile_id=? AND workspace_public_id=? AND source_generation=? AND state='queued'");
                        $recovery->execute([$now,$errorCode?:'terminal_failure',(int)$claim['integration_profile_id'],(string)$claim['workspace_public_id'],$generation]);
                    }elseif($delivered&&(string)$claim['delivery_kind']==='snapshot.activate'){
                        $recovery=$pdo->prepare("UPDATE portal_projection_recoveries SET state='complete',completed_at=?,failed_at=NULL,last_error_code=NULL WHERE activation_delivery_id=? AND integration_profile_id=? AND workspace_public_id=? AND source_generation=? AND state='queued'");
                        $recovery->execute([$now,(string)$claim['delivery_id'],(int)$claim['integration_profile_id'],(string)$claim['workspace_public_id'],$generation]);
                    }
                }
            }
            $pdo->commit();
        }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    /** @param array<string,mixed> $claim */
    private function shouldDeadLetter(array $claim,int $status):bool{return((int)$claim['attempts']+1)>=(int)$claim['delivery_max_attempts']||($status>=400&&$status<500&&$status!==408&&$status!==409&&$status!==425&&$status!==429);}
    /** @param array<string,mixed> $profile @param array<string,mixed> $row */
    private function profileAllows(array$profile,array$row):bool{if(empty($profile['delivery_enabled']))return false;if(!empty($row['is_revocation']))return true;if(empty($profile['enabled']))return false;return match($row['route_type']??''){'catalog'=>!empty($profile['catalog_projection_enabled']),'service_assignments'=>!empty($profile['service_assignment_projection_enabled']),'portal'=>!empty($profile['portal_projection_enabled']),default=>false};}
    private function safeErrorCode(Throwable $error):string{$message=strtolower($error->getMessage());foreach(['dns_no_public_address','redirect_rejected','external-operations-delivery-unavailable','external-operations-application-mismatch','portal-projection-envelope-invalid','portal-projection-kind-invalid','curl_unavailable']as$code)if(str_contains($message,$code))return$code;return'transport_failed';}

    /** @return array{status:int,body:string,error:string} */
    public function curlTransport(string $url,array $headers,string $body,int $timeout):array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('curl_unavailable');$parts=PortalProjectionDeliveryConfigService::validateDestination($url);$host=strtolower((string)$parts['host']);$dnsHost=trim($host,'[]');$port=(int)($parts['port']??443);$addresses=$this->publicAddresses($dnsHost);if($addresses===[])throw new RuntimeException('dns_no_public_address');$ip=$addresses[array_rand($addresses)];$resolve=$dnsHost.':'.$port.':'.(str_contains($ip,':')?'['.$ip.']':$ip);$responseBody='';$handle=curl_init($url);$options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>min(10,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$resolve],CURLOPT_WRITEFUNCTION=>static function($curl,string$chunk)use(&$responseBody):int{$remaining=4096-strlen($responseBody);if($remaining>0)$responseBody.=substr($chunk,0,$remaining);return strlen($chunk);}];if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;curl_setopt_array($handle,$options);$ok=curl_exec($handle);$result=['status'=>(int)curl_getinfo($handle,CURLINFO_HTTP_CODE),'body'=>$responseBody,'error'=>$ok===false?(string)curl_error($handle):''];curl_close($handle);return$result;
    }

    /** @return list<string> */
    private function publicAddresses(string $host,?callable$resolver=null):array
    {
        $addresses=[];$host=trim($host,'[]');if(filter_var($host,FILTER_VALIDATE_IP))$addresses[]=$host;else{$records=$resolver!==null?$resolver($host):(array)@dns_get_record($host,DNS_A|DNS_AAAA);foreach((array)$records as$row){$ip=(string)($row['ip']??$row['ipv6']??'');if($ip!=='')$addresses[]=$ip;}}if($addresses===[])return[];foreach($addresses as$ip)if(!self::isPublicAddress($ip))return[];return array_values(array_unique($addresses));
    }
    private static function isPublicAddress(string$ip):bool
    {
        if(filter_var($ip,FILTER_VALIDATE_IP)===false)return false;if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false)return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
        $packed=@inet_pton($ip);if($packed===false||strlen($packed)!==16)return false;
        // PHP's public-range filter treats IPv4-mapped IPv6 as public. Reject
        // mapped forms outright so ::ffff:127.0.0.1 can never be cURL-pinned.
        if(substr($packed,0,10)===str_repeat("\0",10)&&substr($packed,10,2)==="\xff\xff")return false;
        $embedded=null;if(substr($packed,0,12)===str_repeat("\0",12))$embedded=substr($packed,12,4);elseif(substr($packed,0,12)==="\x00\x64\xff\x9b".str_repeat("\0",8))$embedded=substr($packed,12,4);elseif(substr($packed,0,6)==="\x00\x64\xff\x9b\x00\x01")return false;elseif(substr($packed,0,8)===str_repeat("\0",8)&&substr($packed,8,4)==="\xff\xff\x00\x00")$embedded=substr($packed,12,4);elseif(in_array(substr($packed,8,4),["\x00\x00\x5e\xfe","\x02\x00\x5e\xfe"],true))$embedded=substr($packed,12,4);elseif(substr($packed,0,2)==="\x20\x02")$embedded=substr($packed,2,4);elseif(substr($packed,0,4)==="\x20\x01\x00\x00")return false;
        if($embedded!==null){$v4=inet_ntop($embedded);if($v4===false||filter_var($v4,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return false;}
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
    }
    private static function dbNow():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
    private static function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}
