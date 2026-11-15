<?php
declare(strict_types=1);

// Destructive fixture harness. Never run against an installation database.
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/services/OpsSnapshotReadKeyProvisioner.php';
require_once dirname(__DIR__,2).'/src/services/LocalOperatorKeyReceipt.php';
require_once dirname(__DIR__,2).'/src/services/EncryptedOpsSnapshotKeyReceipt.php';
use App\Services\OpsSnapshotReadKeyProvisioner as Provisioner;
use App\Services\LocalOperatorKeyReceipt as Receipt;
use App\Services\EncryptedOpsSnapshotKeyReceipt as EncryptedReceipt;

if(getenv('PA_TEST_DISPOSABLE_SNAPSHOT_KEYS')!=='1' || !preg_match('/^pa_snapshot_proof_[a-z0-9]+$/D',getenv('MYSQL_DATABASE')?:'')
    || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
    fwrite(STDERR,"Requires explicitly disposable pa_snapshot_proof_* MySQL database and Linux root CLI.\n");exit(2);
}
final class SnapshotMySqlLostAckPDO extends PDO
{
    public bool $loseAck=false;
    public function commit(): bool { $result=parent::commit();if($this->loseAck){$this->loseAck=false;throw new RuntimeException('synthetic_lost_commit_ack');}return $result; }
}
function check(bool $value,string $message='assertion_failed'): void {if(!$value)throw new RuntimeException($message);}
function refuses(callable $fn,string $code): void {try{$fn();}catch(Throwable $error){check($error->getMessage()===$code,'unexpected_refusal');return;}throw new RuntimeException('expected_refusal');}
function connection(): SnapshotMySqlLostAckPDO {
    return new SnapshotMySqlLostAckPDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:'3306').';dbname='.getenv('MYSQL_DATABASE').';charset=utf8mb4',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function input(): array {return ['requestId'=>'523e4567-e89b-42d3-a456-426614174000','sourceInstanceId'=>'123e4567-e89b-42d3-a456-426614174000','applicationKey'=>'synthetic_ops_staging','stagingOrigin'=>'https://pa-stage-one.ledgetoptechnologies.com'];}
function secret(): string {return 'pa_live_'.str_repeat('a',12).'_'.str_repeat('b',64);}
function rows(PDO $db,string $table): int {return(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function resetFixture(PDO $db): void {
    check($db->query('SELECT DATABASE()')->fetchColumn()===getenv('MYSQL_DATABASE'),'wrong_database');
    foreach(['system_audit','api_keys','app_config','sync_source_identity','api_v2_directory_authorization_state','api_v2_project_authorization_state'] as $table)$db->exec('DROP TABLE IF EXISTS '.$table);
    $sql=[
        'CREATE TABLE sync_source_identity(singleton INT PRIMARY KEY,source_instance_id CHAR(36) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE app_config(organization_id BIGINT NOT NULL,config_key VARCHAR(100) NOT NULL,config_value TEXT NOT NULL,PRIMARY KEY(organization_id,config_key)) ENGINE=InnoDB',
        'CREATE TABLE api_keys(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,organization_id BIGINT NOT NULL DEFAULT 0,name VARCHAR(255) NOT NULL,key_prefix VARCHAR(12) NOT NULL,key_hash CHAR(64) NOT NULL UNIQUE,scopes TEXT NOT NULL,allowed_ips TEXT NULL,revoked_at DATETIME NULL,api_v2_application_id BIGINT NULL) ENGINE=InnoDB',
        'CREATE TABLE system_audit(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NULL,action VARCHAR(100) NOT NULL,entity_type VARCHAR(50) NOT NULL,entity_id BIGINT NOT NULL,details JSON NOT NULL,ip_address VARCHAR(45) NULL,user_agent TEXT NULL) ENGINE=InnoDB',
        'CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT PRIMARY KEY,authorization_generation BIGINT NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE api_v2_project_authorization_state(application_pk BIGINT PRIMARY KEY,authorization_generation BIGINT NOT NULL) ENGINE=InnoDB',
        "INSERT INTO sync_source_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000')",
        "INSERT INTO app_config VALUES(0,'external_ops_application_key','synthetic_ops_staging'),(0,'external_ops_enabled','1'),(0,'external_ops_webhook_url','https://synthetic.invalid/ingress')",
        "INSERT INTO api_keys(id,name,key_prefix,key_hash,scopes,api_v2_application_id) VALUES(7,'Other existing key','existing','synthetic-existing-hash','api.capabilities.read',3)",
        'INSERT INTO api_v2_directory_authorization_state VALUES(3,4)', 'INSERT INTO api_v2_project_authorization_state VALUES(3,5)',
    ];foreach($sql as $statement)$db->exec($statement);
}
function command(array $args): array {
    $process=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process),'child_start_failed');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$err];
}
function cli(): string {return dirname(__DIR__,2).'/bin/provision-ops-snapshot-read-key.php';}
function dryArgs(string $dir,string $suffix=''): array {
    $args=[PHP_BINARY,cli(),'--dry-run','--plan-file='.$dir.'/plan'.$suffix.'.json','--secret-file='.$dir.'/key'.$suffix.'.json'];
    foreach(input() as $key=>$value){$names=['requestId'=>'request-id','sourceInstanceId'=>'source-instance-id','applicationKey'=>'application-key','stagingOrigin'=>'staging-origin'];$args[]='--'.$names[$key].'='.$value;}return $args;
}
function applyArgs(string $dir,string $suffix=''): array {return [PHP_BINARY,cli(),'--apply','--confirm-create-staging-snapshot-key','--plan-file='.$dir.'/plan'.$suffix.'.json','--reviewed-plan-sha256='.hash_file('sha256',$dir.'/plan'.$suffix.'.json')];}
function decoded(array $result): array {check($result[0]===0,'cli_failed');return json_decode($result[1],true,512,JSON_THROW_ON_ERROR);}
function noSecret(array $result,string $secret): void {check(!str_contains($result[1].$result[2],$secret) && !str_contains($result[1].$result[2],'pa_live_'),'secret_output');}
function encryptedMaterial(string $path,array $plan): array {$receipt=new EncryptedReceipt($path);$receipt->lock();return $receipt->material($plan);}

$_SESSION=[];putenv(EncryptedReceipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));$db=connection();check($db->query('SELECT DATABASE()')->fetchColumn()===getenv('MYSQL_DATABASE'),'wrong_database');
$directory='/tmp/pa-snapshot-mysql-'.bin2hex(random_bytes(6));mkdir($directory,0700);$cases=[];
$cases['mysql_fixed_scope_json_audit_and_no_v2_generation_change']=function()use($db): void {
    $service=new Provisioner($db);$old=$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC);$result=$service->apply($service->dryRun(input()),secret());
    check($result['state']==='created' && rows($db,'api_keys')===2 && rows($db,'system_audit')===1);
    $key=$db->query('SELECT * FROM api_keys WHERE id<>7')->fetch(PDO::FETCH_ASSOC);check($key['scopes']==='ops.sync.read' && $key['api_v2_application_id']===null);
    check($old===$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC));
    check((int)$db->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn()===4);
    check((int)$db->query('SELECT authorization_generation FROM api_v2_project_authorization_state')->fetchColumn()===5);
    $details=$db->query('SELECT details FROM system_audit')->fetchColumn();check(json_decode($details,true)['contract']==='legacy_instance_scoped' && !str_contains($details,secret()));
};
$cases['cli_realistic_legacy_uuid_v1_and_strict_request_uuid_v4']=function()use($db,$directory): void {
    $source='f47ac10b-58cc-11cf-a447-001122334455';$db->prepare('UPDATE sync_source_identity SET source_instance_id=?')->execute([$source]);$args=dryArgs($directory,'uuidv1');
    foreach($args as &$arg){if(str_starts_with($arg,'--source-instance-id='))$arg='--source-instance-id='.$source;}unset($arg);
    decoded(command($args));check(decoded(command(applyArgs($directory,'uuidv1')))['state']==='created' && rows($db,'api_keys')===2);
    $bad=dryArgs($directory,'badrequest');foreach($bad as &$arg){if(str_starts_with($arg,'--source-instance-id='))$arg='--source-instance-id='.$source;if(str_starts_with($arg,'--request-id='))$arg='--request-id='.$source;}unset($arg);check(command($bad)[0]===1 && !file_exists($directory.'/planbadrequest.json') && rows($db,'api_keys')===2);
};
$cases['mysql_lost_commit_ack_reconciliation_same_encrypted_receipt']=function()use($db,$directory): void {
    $service=new Provisioner($db);$plan=['version'=>1,'provisioning'=>$service->dryRun(input()),'secretFile'=>$directory.'/keylostack.json'];$vault=new EncryptedReceipt($plan['secretFile']);$vault->lock();$material=$vault->create($plan);unset($vault);$bytes=file_get_contents($plan['secretFile']);
    $db->loseAck=true;refuses(fn()=>$service->apply($plan['provisioning'],$material['secret']),'synthetic_lost_commit_ack');
    $restored=encryptedMaterial($plan['secretFile'],$plan);check($restored===$material && !str_contains($bytes,$material['secret']));
    check($service->apply($plan['provisioning'],$restored['secret'],true)['state']==='already_committed');check(rows($db,'api_keys')===2 && rows($db,'system_audit')===1 && file_get_contents($plan['secretFile'])===$bytes);
};
$cases['mysql_audit_failure_rolls_back']=function()use($db): void {
    $service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("CREATE TRIGGER fail_snapshot_audit BEFORE INSERT ON system_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_refusal'");
    refuses(fn()=>$service->apply($plan,secret()),'audit_required');check(rows($db,'api_keys')===1 && rows($db,'system_audit')===0);
};
$cases['mysql_nontransactional_tables_refused']=function()use($db): void {
    $db->exec('ALTER TABLE system_audit ENGINE=MyISAM');refuses(fn()=>(new Provisioner($db))->dryRun(input()),'transactional_tables_required');check(rows($db,'api_keys')===1);
};
$cases['cli_dry_run_reviewed_apply_immutable_private_receipt_secret_free_replay']=function()use($db,$directory): void {
    putenv(EncryptedReceipt::KEY_ENV);$dry=command(dryArgs($directory));$data=decoded($dry);check($data['scope']==='ops.sync.read' && !file_exists($directory.'/key.json') && rows($db,'api_keys')===1);
    $args=applyArgs($directory);$noConfirm=$args;unset($noConfirm[3]);check(command(array_values($noConfirm))[0]===1 && !file_exists($directory.'/key.json'));
    $unreviewed=$args;$unreviewed[5]='--reviewed-plan-sha256='.str_repeat('0',64);check(command($unreviewed)[0]===1 && !file_exists($directory.'/key.json'));
    check(command($args)[0]===1 && !file_exists($directory.'/key.json') && rows($db,'api_keys')===1,'missing_key_generated_material');putenv(EncryptedReceipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));
    $created=command($args);check(decoded($created)['state']==='created');$bytes=file_get_contents($directory.'/key.json');$secret=encryptedMaterial($directory.'/key.json',Receipt::read($directory.'/plan.json'))['secret'];noSecret($created,$secret);check(!str_contains($bytes,$secret) && !str_contains($bytes,'pa_live_'));check((fileperms($directory.'/key.json')&0777)===0600);
    $replay=command($args);check(decoded($replay)['state']==='already_committed');noSecret($replay,$secret);check(file_get_contents($directory.'/key.json')===$bytes && rows($db,'api_keys')===2 && rows($db,'system_audit')===1);
};
$cases['cli_prepared_receipt_defaults_to_read_only_and_explicit_resume']=function()use($db,$directory): void {
    decoded(command(dryArgs($directory,'prepared')));$path=$directory.'/planprepared.json';$plan=Receipt::read($path);$vault=new EncryptedReceipt($plan['secretFile']);$vault->lock();$material=$vault->create($plan);unset($vault);$bytes=file_get_contents($plan['secretFile']);check(!str_contains($bytes,$material['secret']));
    $args=applyArgs($directory,'prepared');$notCommitted=command($args);check($notCommitted[0]===3 && json_decode($notCommitted[1],true)['state']==='not_committed' && rows($db,'api_keys')===1);noSecret($notCommitted,$material['secret']);
    $resumed=command([...$args,'--resume-prepared']);check(decoded($resumed)['state']==='created');noSecret($resumed,$material['secret']);check(file_get_contents($plan['secretFile'])===$bytes);
};
$cases['cli_changed_config_preserves_material_for_reconciliation']=function()use($db,$directory): void {
    decoded(command(dryArgs($directory,'changed')));$db->exec("UPDATE app_config SET config_value='0' WHERE config_key='external_ops_enabled'");$result=command(applyArgs($directory,'changed'));check($result[0]===1 && rows($db,'api_keys')===1);
    $material=encryptedMaterial($directory.'/keychanged.json',Receipt::read($directory.'/planchanged.json'));noSecret($result,$material['secret']);
    $reconcile=command([PHP_BINARY,cli(),'--reconcile','--plan-file='.$directory.'/planchanged.json','--reviewed-plan-sha256='.hash_file('sha256',$directory.'/planchanged.json')]);check($reconcile[0]===3 && rows($db,'api_keys')===1);noSecret($reconcile,$material['secret']);
};
$cases['concurrent_cli_different_receipts_same_request_one_grant']=function()use($db,$directory): void {
    decoded(command(dryArgs($directory,'left')));decoded(command(dryArgs($directory,'right')));
    $children=[];$db->beginTransaction();$db->query('SELECT singleton FROM sync_source_identity WHERE singleton=1 FOR UPDATE');
    try {
        foreach(['left','right'] as $suffix){$process=proc_open(applyArgs($directory,$suffix),[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process),'child_start_failed');$children[]=[$process,$pipes];}
        $until=microtime(true)+5;while((!file_exists($directory.'/keyleft.json') || !file_exists($directory.'/keyright.json')) && microtime(true)<$until)usleep(20000);
        check(file_exists($directory.'/keyleft.json') && file_exists($directory.'/keyright.json'),'children_not_prepared');check(rows($db,'api_keys')===1);
    } finally {if($db->inTransaction())$db->rollBack();}
    $results=[];
    foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$results[]=[proc_close($process),$out,$err];}
    $exitCodes=array_column($results,0);sort($exitCodes);check($exitCodes===[0,1],'duplicate_request_not_serialized');
    foreach(['left','right'] as $suffix){$secret=encryptedMaterial($directory.'/key'.$suffix.'.json',Receipt::read($directory.'/plan'.$suffix.'.json'))['secret'];check(!str_contains(file_get_contents($directory.'/key'.$suffix.'.json'),$secret));foreach($results as $result)noSecret($result,$secret);}
    check(rows($db,'api_keys')===2 && rows($db,'system_audit')===1);
};
$cases['mysql_revocation_and_duplicate_material_never_repaired']=function()use($db): void {
    $service=new Provisioner($db);$plan=$service->dryRun(input());$result=$service->apply($plan,secret());
    refuses(fn()=>$service->apply($plan,'pa_live_'.str_repeat('c',12).'_'.str_repeat('d',64)),'reconciliation_required');
    $db->exec('UPDATE api_keys SET revoked_at=NOW() WHERE id='.$result['apiKeyId']);refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');check(rows($db,'api_keys')===2);
};
$cases['cli_wrong_wrapping_key_or_tampered_ciphertext_cannot_add_grant']=function()use($db,$directory): void {
    decoded(command(dryArgs($directory,'cipher')));$args=applyArgs($directory,'cipher');decoded(command($args));$path=$directory.'/keycipher.json';$plan=Receipt::read($directory.'/plancipher.json');$material=encryptedMaterial($path,$plan);$bytes=file_get_contents($path);
    try {
        putenv(EncryptedReceipt::KEY_ENV.'='.base64_encode(str_repeat('w',32)));$wrong=command($args);check($wrong[0]===1 && file_get_contents($path)===$bytes);noSecret($wrong,$material['secret']);
        putenv(EncryptedReceipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));$envelope=json_decode($bytes,true);$envelope['version']=2;file_put_contents($path,json_encode($envelope));$tamper=command($args);check($tamper[0]===1);noSecret($tamper,$material['secret']);check(rows($db,'api_keys')===2 && rows($db,'system_audit')===1);
    }finally{putenv(EncryptedReceipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));}
};
$cases['cli_existing_plaintext_receipt_is_held_without_migration_or_issuance']=function()use($db,$directory): void {
    decoded(command(dryArgs($directory,'plaintext')));$plan=Receipt::read($directory.'/planplaintext.json');Receipt::writeNew($plan['secretFile'],['version'=>1,'plan'=>$plan,'secret'=>secret()]);$bytes=file_get_contents($plan['secretFile']);
    foreach([applyArgs($directory,'plaintext'),[...applyArgs($directory,'plaintext'),'--resume-prepared']] as $args){$result=command($args);check($result[0]===1 && rows($db,'api_keys')===1 && rows($db,'system_audit')===0 && file_get_contents($plan['secretFile'])===$bytes);noSecret($result,secret());}
};
$passed=0;
try {
    foreach($cases as $name=>$case){resetFixture($db);try{$case();$passed++;echo 'PASS '.$name.PHP_EOL;}catch(Throwable){fwrite(STDERR,'FAIL '.$name.' (diagnostics withheld)'.PHP_EOL);exit(1);}}
    echo $passed.'/'.count($cases).' disposable MySQL cases passed'.PHP_EOL;
} finally {
    foreach(glob($directory.'/*')?:[] as $path)unlink($path);rmdir($directory);
}
