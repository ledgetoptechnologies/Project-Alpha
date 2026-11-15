<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/services/DirectoryInventoryKeyProvisioner.php';
require_once dirname(__DIR__,2).'/src/services/EncryptedDirectoryInventoryKeyReceipt.php';
require_once dirname(__DIR__,2).'/src/services/EncryptedOpsSnapshotKeyReceipt.php';
use App\Services\DirectoryInventoryKeyProvisioner as Provisioner;
use App\Services\EncryptedDirectoryInventoryKeyReceipt as InventoryReceipt;
use App\Services\EncryptedOpsSnapshotKeyReceipt as SnapshotReceipt;
use App\Services\LocalOperatorKeyReceipt as Files;

function check(bool $value,string $why='assertion_failed'): void {if(!$value)throw new RuntimeException($why);}
function refuses(callable $fn,string $why): void {try{$fn();}catch(Throwable $e){check($e->getMessage()===$why,'unexpected_refusal');return;}throw new RuntimeException('expected_refusal');}
final class InventoryLostAckPDO extends PDO {
    public bool $loseAck=false;
    public function commit(): bool {$result=parent::commit();if($this->loseAck){$this->loseAck=false;throw new RuntimeException('synthetic_lost_ack');}return $result;}
}
function input(): array {return ['requestId'=>'523e4567-e89b-42d3-a456-426614174000','sourceInstanceId'=>'123e4567-e89b-42d3-a456-426614174000','historyEpoch'=>'223e4567-e89b-42d3-a456-426614174000','applicationId'=>'323e4567-e89b-42d3-a456-426614174000'];}
function secret(): string {return 'pa_live_'.str_repeat('a',12).'_'.str_repeat('b',64);}
function fixture(): InventoryLostAckPDO {
    $db=new InventoryLostAckPDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,filename TEXT);INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql'),(102,'0102_api_v2_project_synchronization.sql');
      CREATE TABLE api_keys(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,key_prefix TEXT,key_hash TEXT UNIQUE,scopes TEXT,allowed_ips TEXT,revoked_at TEXT,api_v2_application_id INTEGER);
      CREATE TABLE system_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT,ip_address TEXT,user_agent TEXT);
      CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
      CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT UNIQUE,name TEXT);
      CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
      CREATE TABLE api_v2_project_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
      INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','223e4567-e89b-42d3-a456-426614174000');
      INSERT INTO api_v2_applications VALUES(3,'323e4567-e89b-42d3-a456-426614174000','Selected'),(4,'423e4567-e89b-42d3-a456-426614174000','Other');
      INSERT INTO api_v2_directory_authorization_state VALUES(3,4),(4,8);INSERT INTO api_v2_project_authorization_state VALUES(3,5),(4,9);
      INSERT INTO api_keys(id,name,key_prefix,key_hash,scopes,api_v2_application_id) VALUES(7,'Existing command key','existing','synthetic-existing','full',4);");return $db;
}
function countRows(PDO $db,string $table): int {return(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function generations(PDO $db): array {return [(int)$db->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn(),(int)$db->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn()];}
function canonical(mixed $value): mixed {if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$entry)$entry=canonical($entry);unset($entry);return $value;}
function oldSnapshotAad(string $path,array $plan): string {return json_encode(canonical(['format'=>'pa.ops-snapshot-key.encrypted','version'=>1,'algorithm'=>'aes-256-gcm','path'=>$path,'plan'=>$plan]),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}

if(!function_exists('posix_geteuid') || posix_geteuid()!==0){fwrite(STDERR,"Requires disposable Linux root PHP with PDO SQLite.\n");exit(2);}
$_SESSION=[];putenv(InventoryReceipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));putenv(SnapshotReceipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));$cases=[];
$cases['dry_run_and_fixed_two_scopes_bind_existing_application']=function(): void {
    $db=fixture();$service=new Provisioner($db);$other=$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC);$plan=$service->dryRun(input());check(countRows($db,'api_keys')===1 && countRows($db,'system_audit')===0 && generations($db)===[4,5]);
    $result=$service->apply($plan,secret());$key=$db->query('SELECT * FROM api_keys WHERE id<>7')->fetch(PDO::FETCH_ASSOC);check($result['state']==='created' && $key['scopes']==='api.capabilities.read,directory.inventory.read' && (int)$key['api_v2_application_id']===3);
    foreach(array_keys(api_scope_catalog()) as $scope)check(api_key_has_scope($key['scopes'],$scope)===in_array($scope,Provisioner::SCOPES,true),'scope_catalog_expansion');
    check(generations($db)===[5,6] && countRows($db,'api_v2_applications')===2 && $other===$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC));
    check((int)$db->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=4')->fetchColumn()===8);
    check((int)$db->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=4')->fetchColumn()===9);
    $audit=$db->query('SELECT * FROM system_audit')->fetch(PDO::FETCH_ASSOC);check($audit['user_id']===null && json_decode($audit['details'],true)['actor']['kind']==='local_operator' && !str_contains($audit['details'],secret()));
};
$cases['actual_v2_identity_contract_and_request_require_canonical_v4']=function(): void {
    $service=new Provisioner(fixture());foreach(array_keys(input()) as $field){$bad=input();$bad[$field]='f47ac10b-58cc-11cf-a447-001122334455';refuses(fn()=>$service->dryRun($bad),'invalid_request');$bad=input();$bad[$field]=strtoupper($bad[$field]);refuses(fn()=>$service->dryRun($bad),'invalid_request');}
    $bad=input();$bad['applicationId']='723e4567-e89b-42d3-a456-426614174000';refuses(fn()=>$service->dryRun($bad),'existing_application_required');
};
$cases['identity_epoch_generation_and_plan_substitution_fail_closed']=function(): void {
    foreach(['source_instance_id','history_epoch'] as $column){$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("UPDATE api_v2_history_identity SET {$column}='723e4567-e89b-42d3-a456-426614174000'");refuses(fn()=>$service->apply($plan,secret()),'source_identity_changed');check(countRows($db,'api_keys')===1);}
    foreach(['api_v2_directory_authorization_state','api_v2_project_authorization_state'] as $table){$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec('UPDATE '.$table.' SET authorization_generation=10 WHERE application_pk=3');refuses(fn()=>$service->apply($plan,secret()),'authorization_generation_changed');check(countRows($db,'api_keys')===1);}
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$plan['scope']='full';refuses(fn()=>$service->apply($plan,secret()),'invalid_plan');
};
$cases['lost_ack_and_replay_do_not_advance_again']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->loseAck=true;refuses(fn()=>$service->apply($plan,secret()),'synthetic_lost_ack');check($service->apply($plan,secret(),true)['state']==='already_committed');check(generations($db)===[5,6] && countRows($db,'api_keys')===2 && countRows($db,'system_audit')===1);
    $db->exec('UPDATE api_v2_directory_authorization_state SET authorization_generation=10 WHERE application_pk=3');check($service->apply($plan,secret(),true)['state']==='already_committed' && generations($db)===[10,6]);
};
$cases['absent_reconcile_never_creates']=function(): void {$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());check($service->apply($plan,secret(),true)['state']==='not_committed' && countRows($db,'api_keys')===1 && generations($db)===[4,5]);};
$cases['audit_failure_rolls_back_key_and_both_generations']=function(): void {$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON system_audit BEGIN SELECT RAISE(ABORT,'synthetic'); END");refuses(fn()=>$service->apply($plan,secret()),'audit_required');check(countRows($db,'api_keys')===1 && generations($db)===[4,5]);};
$cases['scope_trigger_failure_rolls_back_key_and_generations']=function(): void {$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("CREATE TRIGGER widen_key AFTER INSERT ON api_keys BEGIN UPDATE api_keys SET scopes='full' WHERE id=NEW.id; END");refuses(fn()=>$service->apply($plan,secret()),'key_verification_failed');check(countRows($db,'api_keys')===1 && generations($db)===[4,5] && countRows($db,'system_audit')===0);};
$cases['duplicate_secret_request_application_and_revocation_never_repair']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$created=$service->apply($plan,secret());refuses(fn()=>$service->apply($plan,'pa_live_'.str_repeat('c',12).'_'.str_repeat('d',64)),'reconciliation_required');
    $other=input();$other['applicationId']='423e4567-e89b-42d3-a456-426614174000';$otherPlan=$service->dryRun($other);refuses(fn()=>$service->apply($otherPlan,'pa_live_'.str_repeat('c',12).'_'.str_repeat('d',64)),'reconciliation_required');
    $db->exec("UPDATE api_keys SET revoked_at='2026-01-01' WHERE id=".$created['apiKeyId']);refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');check(countRows($db,'api_keys')===2 && generations($db)===[5,6]);
};
$cases['scope_binding_or_missing_audit_tamper_is_held']=function(): void {
    foreach(["UPDATE api_keys SET scopes='projects.v2.read' WHERE id<>7",'UPDATE api_keys SET api_v2_application_id=4 WHERE id<>7','DELETE FROM system_audit'] as $sql){$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$service->apply($plan,secret());$db->exec($sql);refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');}
};
$cases['fake_session_and_partial_schema_refused']=function(): void {$db=fixture();$service=new Provisioner($db);$_SESSION['user']=['role'=>'admin'];try{refuses(fn()=>$service->dryRun(input()),'local_root_cli_required');}finally{$_SESSION=[];}$db->exec('DELETE FROM schema_migrations WHERE version=102');refuses(fn()=>$service->dryRun(input()),'provisioning_schema_unavailable');};
$cases['snapshot_926_codec_compatibility_both_directions_and_immutable_bytes']=function(): void {
    $dir='/tmp/inventory-oldcodec-'.bin2hex(random_bytes(5));mkdir($dir,0700);$path=$dir.'/old.json';$plan=['version'=>1,'provisioning'=>['synthetic'=>'old926'],'secretFile'=>$path];$key=str_repeat('k',32);$nonce=str_repeat('n',12);$tag='';
    try{$cipher=openssl_encrypt(json_encode(['secret'=>secret()],JSON_THROW_ON_ERROR),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,oldSnapshotAad($path,$plan),16);Files::writeNew($path,['format'=>'pa.ops-snapshot-key.encrypted','version'=>1,'algorithm'=>'aes-256-gcm','nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)]);$bytes=file_get_contents($path);$snapshot=new SnapshotReceipt($path);$snapshot->lock();check($snapshot->material($plan)['secret']===secret());unset($snapshot);check(file_get_contents($path)===$bytes && SnapshotReceipt::KEY_ENV==='PA_OPS_SNAPSHOT_RECEIPT_KEY_B64');
        $newPath=$dir.'/new.json';$newPlan=['version'=>1,'secretFile'=>$newPath];$snapshot=new SnapshotReceipt($newPath);$snapshot->lock();$new=$snapshot->create($newPlan);unset($snapshot);$envelope=Files::read($newPath);$decoded=openssl_decrypt(base64_decode($envelope['ciphertext']),'aes-256-gcm',$key,OPENSSL_RAW_DATA,base64_decode($envelope['nonce']),base64_decode($envelope['tag']),oldSnapshotAad($newPath,$newPlan));check(json_decode($decoded,true)['secret']===$new['secret']);
    }finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
};
$cases['purpose_separation_even_same_wrapping_key_plan_and_path']=function(): void {
    $dir='/tmp/inventory-purpose-'.bin2hex(random_bytes(5));mkdir($dir,0700);$path=$dir.'/key.json';$plan=['same'=>'plan'];
    try{$snapshot=new SnapshotReceipt($path);$snapshot->lock();$snapshot->create($plan);unset($snapshot);$bytes=file_get_contents($path);$inventory=new InventoryReceipt($path);$inventory->lock();refuses(fn()=>$inventory->material($plan),'encrypted_receipt_unavailable');unset($inventory);
        $forged=json_decode($bytes,true);$forged['format']='pa.directory-inventory-key.encrypted';file_put_contents($path,json_encode($forged));$inventory=new InventoryReceipt($path);$inventory->lock();refuses(fn()=>$inventory->material($plan),'encrypted_receipt_unavailable');unset($inventory);
        unlink($path);$inventory=new InventoryReceipt($path);$inventory->lock();$inventory->create($plan);unset($inventory);$envelope=Files::read($path);$envelope['format']='pa.ops-snapshot-key.encrypted';file_put_contents($path,json_encode($envelope));$snapshot=new SnapshotReceipt($path);$snapshot->lock();refuses(fn()=>$snapshot->material($plan),'encrypted_receipt_unavailable');unset($snapshot);
    }finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
};
$passed=0;foreach($cases as $name=>$case){try{$case();$passed++;echo 'PASS '.$name.PHP_EOL;}catch(Throwable $e){fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage().PHP_EOL);exit(1);}}echo $passed.'/'.count($cases).' inventory synthetic cases passed'.PHP_EOL;
