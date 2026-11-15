<?php
declare(strict_types=1);

// Synthetic, network-free Linux-root test runner; no application/vendor bootstrap.
require_once dirname(__DIR__,2).'/src/services/OpsSnapshotReadKeyProvisioner.php';
require_once dirname(__DIR__,2).'/src/services/LocalOperatorKeyReceipt.php';
require_once dirname(__DIR__,2).'/src/services/EncryptedOpsSnapshotKeyReceipt.php';
use App\Services\OpsSnapshotReadKeyProvisioner as Provisioner;
use App\Services\LocalOperatorKeyReceipt as Receipt;
use App\Services\EncryptedOpsSnapshotKeyReceipt as EncryptedReceipt;

final class SnapshotLostAckPDO extends PDO
{
    public bool $loseAck=false;
    public function commit(): bool
    {
        $result=parent::commit();
        if($this->loseAck){$this->loseAck=false;throw new RuntimeException('synthetic_lost_commit_ack');}
        return $result;
    }
}
function check(bool $value,string $message='assertion_failed'): void { if(!$value)throw new RuntimeException($message); }
function refuses(callable $fn,?string $code=null): void
{
    try{$fn();}catch(Throwable $error){if($code!==null)check($error->getMessage()===$code,'unexpected_refusal:'.$error->getMessage());return;}
    throw new RuntimeException('expected_refusal');
}
function input(): array { return ['requestId'=>'523e4567-e89b-42d3-a456-426614174000','sourceInstanceId'=>'123e4567-e89b-42d3-a456-426614174000','applicationKey'=>'synthetic_ops_staging','stagingOrigin'=>'https://pa-stage-one.ledgetoptechnologies.com']; }
function secret(): string { return 'pa_live_'.str_repeat('a',12).'_'.str_repeat('b',64); }
function fixture(): SnapshotLostAckPDO
{
    $pdo=new SnapshotLostAckPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE sync_source_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT);
      CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT);
      CREATE TABLE api_keys(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,key_prefix TEXT,key_hash TEXT UNIQUE,scopes TEXT,allowed_ips TEXT,revoked_at TEXT,api_v2_application_id INTEGER);
      CREATE TABLE system_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT,ip_address TEXT,user_agent TEXT);
      CREATE TABLE api_v2_history_identity(singleton INTEGER,source_instance_id TEXT,history_epoch TEXT);
      CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER,authorization_generation INTEGER);
      CREATE TABLE api_v2_project_authorization_state(application_pk INTEGER,authorization_generation INTEGER);
      INSERT INTO sync_source_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000');
      INSERT INTO app_config VALUES(0,'external_ops_application_key','synthetic_ops_staging'),(0,'external_ops_enabled','1'),(0,'external_ops_webhook_url','https://synthetic.invalid/ingress');
      INSERT INTO api_v2_history_identity VALUES(1,'923e4567-e89b-42d3-a456-426614174000','823e4567-e89b-42d3-a456-426614174000');
      INSERT INTO api_v2_directory_authorization_state VALUES(3,4);INSERT INTO api_v2_project_authorization_state VALUES(3,5);
      INSERT INTO api_keys(id,name,key_prefix,key_hash,scopes,api_v2_application_id) VALUES(7,'Existing unrelated key','existing','synthetic-existing-hash','api.capabilities.read',3);
      CREATE TRIGGER no_directory_generation BEFORE UPDATE ON api_v2_directory_authorization_state BEGIN SELECT RAISE(ABORT,'unexpected_generation_write'); END;
      CREATE TRIGGER no_project_generation BEFORE UPDATE ON api_v2_project_authorization_state BEGIN SELECT RAISE(ABORT,'unexpected_generation_write'); END;");
    return $pdo;
}
function countRows(PDO $pdo,string $table): int { return (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }

if(!function_exists('posix_geteuid') || posix_geteuid()!==0) {fwrite(STDERR,"Requires disposable Linux root CLI with PDO SQLite.\n");exit(2);}
$_SESSION=[];putenv(EncryptedReceipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));$cases=[];
$cases['dry_run_no_write_or_secret']=function(): void {
    $db=fixture();$plan=(new Provisioner($db))->dryRun(input());check(countRows($db,'api_keys')===1 && countRows($db,'system_audit')===0);
    check($plan['scope']==='ops.sync.read' && $plan['contract']==='legacy_instance_scoped');check(!str_contains(json_encode($plan),'pa_live_'));
};
$cases['legacy_identity_not_v2_identity']=function(): void {
    $db=fixture();$service=new Provisioner($db);$service->dryRun(input());$wrong=input();$wrong['sourceInstanceId']='923e4567-e89b-42d3-a456-426614174000';
    refuses(fn()=>$service->dryRun($wrong),'legacy_source_identity_changed');
};
$cases['canonical_legacy_uuid_v1_supported_with_exact_equality_request_still_v4']=function(): void {
    $db=fixture();$service=new Provisioner($db);$source='f47ac10b-58cc-11cf-a447-001122334455';$db->prepare('UPDATE sync_source_identity SET source_instance_id=?')->execute([$source]);$selected=input();$selected['sourceInstanceId']=$source;
    $plan=$service->dryRun($selected);check($service->apply($plan,secret())['state']==='created');
    $bad=$selected;$bad['requestId']=$source;refuses(fn()=>$service->dryRun($bad),'invalid_request');
    foreach(['F47ac10b-58cc-11cf-a447-001122334455','f47ac10b-58cc-61cf-a447-001122334455','f47ac10b-58cc-11cf-7447-001122334455',$source.' ',str_replace('-','',$source)] as $invalid){$bad=$selected;$bad['sourceInstanceId']=$invalid;refuses(fn()=>$service->dryRun($bad),'invalid_request');}
    $bad=$selected;$bad['sourceInstanceId']='f47ac10b-58cc-11cf-a447-001122334456';refuses(fn()=>$service->dryRun($bad),'legacy_source_identity_changed');
};
$cases['fixed_scope_unbound_audited_without_other_key_or_generations_changes']=function(): void {
    $db=fixture();$before=$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC);$service=new Provisioner($db);$plan=$service->dryRun(input());$result=$service->apply($plan,secret());
    check($result['state']==='created' && countRows($db,'api_keys')===2 && countRows($db,'system_audit')===1);
    $key=$db->query('SELECT * FROM api_keys WHERE id<>7')->fetch(PDO::FETCH_ASSOC);check($key['scopes']==='ops.sync.read' && $key['api_v2_application_id']===null && $key['allowed_ips']===null);
    check($before===$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC));
    check((int)$db->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn()===4);
    check((int)$db->query('SELECT authorization_generation FROM api_v2_project_authorization_state')->fetchColumn()===5);
    $audit=$db->query('SELECT * FROM system_audit')->fetch(PDO::FETCH_ASSOC);check($audit['user_id']===null && !str_contains($audit['details'],secret()));
    check(json_decode($audit['details'],true)['actor']['kind']==='local_operator');
};
$cases['lost_commit_ack_reconciles_without_duplicate']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->loseAck=true;
    refuses(fn()=>$service->apply($plan,secret()),'synthetic_lost_commit_ack');
    check($service->apply($plan,secret(),true)['state']==='already_committed');check(countRows($db,'api_keys')===2 && countRows($db,'system_audit')===1);
};
$cases['absent_reconcile_does_not_insert_then_explicit_same_material_apply']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());check($service->apply($plan,secret(),true)['state']==='not_committed');
    check(countRows($db,'api_keys')===1);check($service->apply($plan,secret())['state']==='created');
};
$cases['same_request_different_secret_refused']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$service->apply($plan,secret());
    refuses(fn()=>$service->apply($plan,'pa_live_'.str_repeat('c',12).'_'.str_repeat('d',64)),'reconciliation_required');check(countRows($db,'api_keys')===2);
};
$cases['same_request_different_origin_refused']=function(): void {
    $db=fixture();$service=new Provisioner($db);$service->apply($service->dryRun(input()),secret());$other=input();$other['stagingOrigin']='https://pa-stage-two.ledgetoptechnologies.com';
    $otherPlan=$service->dryRun($other);refuses(fn()=>$service->apply($otherPlan,'pa_live_'.str_repeat('c',12).'_'.str_repeat('d',64)),'reconciliation_required');
};
$cases['revoked_key_never_reactivated']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$result=$service->apply($plan,secret());$db->exec("UPDATE api_keys SET revoked_at='2026-01-01' WHERE id=".$result['apiKeyId']);
    refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');check(countRows($db,'api_keys')===2);
};
$cases['scope_or_binding_tamper_held']=function(): void {
    foreach(['scopes'=>'full','api_v2_application_id'=>'3'] as $field=>$value){$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$result=$service->apply($plan,secret());$db->prepare('UPDATE api_keys SET '.$field.'=? WHERE id=?')->execute([$value,$result['apiKeyId']]);refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');}
};
$cases['missing_or_duplicate_audit_held']=function(): void {
    foreach(['DELETE FROM system_audit','INSERT INTO system_audit(user_id,action,entity_type,entity_id,details) SELECT user_id,action,entity_type,entity_id,details FROM system_audit'] as $sql){$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$service->apply($plan,secret());$db->exec($sql);refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');check(countRows($db,'api_keys')===2);}
};
$cases['audit_failure_rolls_back_key']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON system_audit BEGIN SELECT RAISE(ABORT,'synthetic'); END;");
    refuses(fn()=>$service->apply($plan,secret()),'audit_required');check(countRows($db,'api_keys')===1 && countRows($db,'system_audit')===0);
};
$cases['trigger_scope_mutation_rolls_back']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("CREATE TRIGGER widen_key AFTER INSERT ON api_keys BEGIN UPDATE api_keys SET scopes='full' WHERE id=NEW.id; END;");
    refuses(fn()=>$service->apply($plan,secret()),'audit_or_key_verification_failed');check(countRows($db,'api_keys')===1 && countRows($db,'system_audit')===0);
};
$cases['changed_app_and_config_refused']=function(): void {
    foreach(['external_ops_application_key'=>'other_app','external_ops_enabled'=>'0','external_ops_webhook_url'=>'https://changed.invalid'] as $field=>$value){$db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->prepare('UPDATE app_config SET config_value=? WHERE config_key=?')->execute([$value,$field]);refuses(fn()=>$service->apply($plan,secret()),$field==='external_ops_application_key'?'configured_application_changed':'application_configuration_changed');check(countRows($db,'api_keys')===1);}
};
$cases['changed_source_refused']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("UPDATE sync_source_identity SET source_instance_id='623e4567-e89b-42d3-a456-426614174000'");refuses(fn()=>$service->apply($plan,secret()),'legacy_source_identity_changed');
};
$cases['scope_plan_and_production_origin_refused']=function(): void {
    $db=fixture();$service=new Provisioner($db);$plan=$service->dryRun(input());$plan['scope']='full';refuses(fn()=>$service->apply($plan,secret()),'invalid_plan');
    $bad=input();$bad['stagingOrigin']='https://production.invalid';refuses(fn()=>$service->dryRun($bad),'invalid_staging_target');
    $bad=input();$bad['applicationId']='723e4567-e89b-42d3-a456-426614174000';refuses(fn()=>$service->dryRun($bad),'invalid_request');
};
$cases['fake_session_and_nested_transaction_refused']=function(): void {
    $db=fixture();$service=new Provisioner($db);$_SESSION['user']=['role'=>'admin'];try{refuses(fn()=>$service->dryRun(input()),'local_root_cli_required');}finally{$_SESSION=[];}
    $db->beginTransaction();try{refuses(fn()=>$service->dryRun(input()),'nested_transaction_refused');}finally{$db->rollBack();}
};
$cases['unsafe_private_paths_refused']=function(): void {
    $dir='/tmp/snapshot-unsafe-'.bin2hex(random_bytes(4));mkdir($dir,0700);$path=$dir.'/public';file_put_contents($path,'synthetic');chmod($path,0644);symlink($path,$dir.'/link');
    try{refuses(fn()=>new Receipt($path),'unsafe_private_file');refuses(fn()=>new Receipt($dir.'/link'),'unsafe_private_file');refuses(fn()=>Receipt::safePath('/var/www/public/key.json'),'webroot_output_forbidden');}
    finally{unlink($dir.'/link');unlink($path);rmdir($dir);}
};
$cases['private_receipt_persists_before_database_apply_and_is_immutable']=function(): void {
    $dir='/tmp/snapshot-receipt-'.bin2hex(random_bytes(4));mkdir($dir,0700);$db=fixture();$service=new Provisioner($db);$plan=['version'=>1,'provisioning'=>$service->dryRun(input()),'secretFile'=>$dir.'/key.json'];$vault=new EncryptedReceipt($plan['secretFile']);$vault->lock();
    try{$material=$vault->create($plan);$bytes=file_get_contents($plan['secretFile']);check((fileperms($plan['secretFile'])&0777)===0600);check(!str_contains($bytes,$material['secret']) && !str_contains($bytes,'pa_live_'));check(countRows($db,'api_keys')===1);$vault->journal(['state'=>'attempting']);$result=$service->apply($plan['provisioning'],$material['secret']);$vault->journal($result);check(file_get_contents($plan['secretFile'])===$bytes);check($vault->material($plan)===$material);refuses(fn()=>$vault->create($plan));$changed=$plan;$changed['version']=2;refuses(fn()=>$vault->material($changed),'encrypted_receipt_unavailable');$second=new EncryptedReceipt($plan['secretFile']);refuses(fn()=>$second->lock(),'request_in_progress');unset($second);}
    finally{unset($vault);foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
};
$cases['encrypted_receipt_missing_invalid_and_wrong_wrapping_keys_fail_closed']=function(): void {
    $dir='/tmp/snapshot-key-tests-'.bin2hex(random_bytes(4));mkdir($dir,0700);$path=$dir.'/key.json';$plan=['reviewed'=>'synthetic'];$correct=base64_encode(str_repeat('k',32));
    try{
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';$noncanonical=$correct;$noncanonical[42]=$alphabet[strpos($alphabet,$correct[42])+1];
        foreach([null,'',base64_encode(str_repeat('k',31)),$correct."\n",str_repeat('a',44),$noncanonical] as $bad){if($bad===null)putenv(EncryptedReceipt::KEY_ENV);else putenv(EncryptedReceipt::KEY_ENV.'='.$bad);refuses(fn()=>new EncryptedReceipt($path),'encrypted_receipt_key_unavailable');check(!file_exists($path));}
        putenv(EncryptedReceipt::KEY_ENV.'='.$correct);$vault=new EncryptedReceipt($path);$vault->lock();$vault->create($plan);unset($vault);$bytes=file_get_contents($path);
        putenv(EncryptedReceipt::KEY_ENV.'='.base64_encode(str_repeat('w',32)));$wrong=new EncryptedReceipt($path);$wrong->lock();refuses(fn()=>$wrong->material($plan),'encrypted_receipt_unavailable');unset($wrong);check(file_get_contents($path)===$bytes);
    }finally{putenv(EncryptedReceipt::KEY_ENV.'='.$correct);foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
};
$cases['encrypted_receipt_plan_path_nonce_tag_ciphertext_and_format_tamper_refused']=function(): void {
    $dir='/tmp/snapshot-tamper-'.bin2hex(random_bytes(4));mkdir($dir,0700);$path=$dir.'/key.json';$plan=['version'=>1,'input'=>['source'=>'synthetic']];$vault=new EncryptedReceipt($path);$vault->lock();$vault->create($plan);unset($vault);$bytes=file_get_contents($path);$original=json_decode($bytes,true);
    try{
        foreach(['nonce','tag','ciphertext','format','version','algorithm'] as $field){$changed=$original;if(in_array($field,['nonce','tag','ciphertext'],true)){$raw=base64_decode($changed[$field]);$raw[0]=chr(ord($raw[0])^1);$changed[$field]=base64_encode($raw);}else $changed[$field]=$field==='version'?2:'changed';file_put_contents($path,json_encode($changed));$check=new EncryptedReceipt($path);$check->lock();refuses(fn()=>$check->material($plan),'encrypted_receipt_unavailable');unset($check);}
        file_put_contents($path,$bytes);$check=new EncryptedReceipt($path);$check->lock();refuses(fn()=>$check->material(['version'=>1,'input'=>['source'=>'other']]),'encrypted_receipt_unavailable');unset($check);
        $copy=$dir.'/copy.json';copy($path,$copy);chmod($copy,0600);$check=new EncryptedReceipt($copy);$check->lock();refuses(fn()=>$check->material($plan),'encrypted_receipt_unavailable');unset($check);
        file_put_contents($path,json_encode(['version'=>1,'plan'=>$plan,'secret'=>secret()]));$check=new EncryptedReceipt($path);$check->lock();refuses(fn()=>$check->material($plan),'encrypted_receipt_unavailable');refuses(fn()=>$check->create($plan),'encrypted_receipt_already_exists');unset($check);
    }finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
};
$cases['encrypted_receipt_uses_fresh_gcm_nonces']=function(): void {
    $dir='/tmp/snapshot-nonces-'.bin2hex(random_bytes(4));mkdir($dir,0700);$nonces=[];$tags=[];
    try{foreach(['one','two'] as $name){$path=$dir.'/'.$name.'.json';$vault=new EncryptedReceipt($path);$vault->lock();$vault->create(['same'=>'review']);unset($vault);$envelope=Receipt::read($path);$nonces[]=$envelope['nonce'];$tags[]=$envelope['tag'];check(strlen(base64_decode($envelope['nonce']))===12 && strlen(base64_decode($envelope['tag']))===16);}check($nonces[0]!==$nonces[1] && $tags[0]!==$tags[1]);}
    finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
};
$cases['encrypted_destructor_releases_lock_with_optional_sodium_cleanup']=function(): void {
    $dir='/tmp/snapshot-cleanup-'.bin2hex(random_bytes(4));mkdir($dir,0700);$path=$dir.'/key.json';$plan=['review'=>'synthetic'];
    try{$vault=new EncryptedReceipt($path);$vault->lock();$material=$vault->create($plan);unset($vault);$again=new EncryptedReceipt($path);$again->lock();check($again->material($plan)===$material);unset($again);}
    finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
};
$passed=0;
foreach($cases as $name=>$case){try{$case();$passed++;echo 'PASS '.$name.PHP_EOL;}catch(Throwable $error){fwrite(STDERR,'FAIL '.$name.': '.$error->getMessage().PHP_EOL);exit(1);}}
echo $passed.'/'.count($cases).' synthetic cases passed'.PHP_EOL;
