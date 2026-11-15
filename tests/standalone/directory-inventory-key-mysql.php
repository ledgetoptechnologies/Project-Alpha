<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/services/DirectoryInventoryKeyProvisioner.php';
require_once dirname(__DIR__,2).'/src/services/EncryptedDirectoryInventoryKeyReceipt.php';
use App\Services\DirectoryInventoryKeyProvisioner as Provisioner;
use App\Services\EncryptedDirectoryInventoryKeyReceipt as Receipt;
use App\Services\LocalOperatorKeyReceipt as Files;

if(getenv('PA_TEST_DISPOSABLE_DIRECTORY_KEYS')!=='1' || !preg_match('/^pa_snapshot_proof_[a-z0-9]+$/D',getenv('MYSQL_DATABASE')?:'')
    || !function_exists('posix_geteuid') || posix_geteuid()!==0){fwrite(STDERR,"Requires explicit disposable pa_snapshot_proof_* database and Linux root.\n");exit(2);}
function check(bool $value,string $why='assertion_failed'): void {if(!$value)throw new RuntimeException($why);}
function refuses(callable $fn,string $why): void {try{$fn();}catch(Throwable $e){check($e->getMessage()===$why,'unexpected_refusal');return;}throw new RuntimeException('expected_refusal');}
final class InventoryMysqlLostAckPDO extends PDO {public bool $loseAck=false;public function commit():bool{$result=parent::commit();if($this->loseAck){$this->loseAck=false;throw new RuntimeException('synthetic_lost_ack');}return $result;}}
function connection():InventoryMysqlLostAckPDO{return new InventoryMysqlLostAckPDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:'3306').';dbname='.getenv('MYSQL_DATABASE').';charset=utf8mb4',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
function input():array{return ['requestId'=>'523e4567-e89b-42d3-a456-426614174000','sourceInstanceId'=>'123e4567-e89b-42d3-a456-426614174000','historyEpoch'=>'223e4567-e89b-42d3-a456-426614174000','applicationId'=>'323e4567-e89b-42d3-a456-426614174000'];}
function secret():string{return 'pa_live_'.str_repeat('a',12).'_'.str_repeat('b',64);}
function rows(PDO $db,string $table):int{return(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function generations(PDO $db):array{return[(int)$db->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn(),(int)$db->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn()];}
function fixture(PDO $db):void{
    check($db->query('SELECT DATABASE()')->fetchColumn()===getenv('MYSQL_DATABASE'),'wrong_database');
    foreach(['system_audit','api_keys','api_v2_directory_authorization_state','api_v2_project_authorization_state','api_v2_applications','api_v2_history_identity','schema_migrations'] as $table)$db->exec('DROP TABLE IF EXISTS '.$table);
    $sql=[
      'CREATE TABLE schema_migrations(version INT PRIMARY KEY,filename VARCHAR(200) NOT NULL) ENGINE=InnoDB',
      'CREATE TABLE api_keys(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,organization_id BIGINT NOT NULL DEFAULT 0,name VARCHAR(255) NOT NULL,key_prefix VARCHAR(12) NOT NULL,key_hash CHAR(64) UNIQUE NOT NULL,scopes TEXT NOT NULL,allowed_ips TEXT NULL,revoked_at DATETIME NULL,api_v2_application_id BIGINT NULL) ENGINE=InnoDB',
      'CREATE TABLE system_audit(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NULL,action VARCHAR(100) NOT NULL,entity_type VARCHAR(50) NOT NULL,entity_id BIGINT NOT NULL,details JSON NOT NULL,ip_address VARCHAR(45) NULL,user_agent TEXT NULL) ENGINE=InnoDB',
      'CREATE TABLE api_v2_history_identity(singleton INT PRIMARY KEY,source_instance_id CHAR(36) NOT NULL,history_epoch CHAR(36) NOT NULL) ENGINE=InnoDB',
      'CREATE TABLE api_v2_applications(id BIGINT PRIMARY KEY,application_id CHAR(36) UNIQUE NOT NULL,name VARCHAR(100) NOT NULL) ENGINE=InnoDB',
      'CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT PRIMARY KEY,authorization_generation BIGINT NOT NULL) ENGINE=InnoDB',
      'CREATE TABLE api_v2_project_authorization_state(application_pk BIGINT PRIMARY KEY,authorization_generation BIGINT NOT NULL) ENGINE=InnoDB',
      "INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql'),(102,'0102_api_v2_project_synchronization.sql')",
      "INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','223e4567-e89b-42d3-a456-426614174000')",
      "INSERT INTO api_v2_applications VALUES(3,'323e4567-e89b-42d3-a456-426614174000','Selected'),(4,'423e4567-e89b-42d3-a456-426614174000','Other')",
      'INSERT INTO api_v2_directory_authorization_state VALUES(3,4),(4,8)','INSERT INTO api_v2_project_authorization_state VALUES(3,5),(4,9)',
      "INSERT INTO api_keys(id,name,key_prefix,key_hash,scopes,api_v2_application_id) VALUES(7,'Existing unrelated key','existing','synthetic-existing','full',4)",
    ];foreach($sql as $statement)$db->exec($statement);
}
function command(array $args):array{$process=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process),'child_start');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($process),$out,$err];}
function decoded(array $result):array{check($result[0]===0,'cli_failed');return json_decode($result[1],true,512,JSON_THROW_ON_ERROR);}
function cli():string{return dirname(__DIR__,2).'/bin/provision-directory-inventory-read-key.php';}
function dry(string $dir,string $name):array{$args=[PHP_BINARY,cli(),'--dry-run','--plan-file='.$dir.'/'.$name.'-plan.json','--secret-file='.$dir.'/'.$name.'-key.json'];foreach(input() as $key=>$value){$option=['requestId'=>'request-id','sourceInstanceId'=>'source-instance-id','historyEpoch'=>'history-epoch','applicationId'=>'application-id'][$key];$args[]='--'.$option.'='.$value;}return $args;}
function apply(string $dir,string $name):array{return[PHP_BINARY,cli(),'--apply','--confirm-create-directory-inventory-key','--plan-file='.$dir.'/'.$name.'-plan.json','--reviewed-plan-sha256='.hash_file('sha256',$dir.'/'.$name.'-plan.json')];}
function material(string $dir,string $name):array{$plan=Files::read($dir.'/'.$name.'-plan.json');$receipt=new Receipt($plan['secretFile']);$receipt->lock();return $receipt->material($plan);}
function noSecret(array $result,string $secret):void{check(!str_contains($result[1].$result[2],$secret) && !str_contains($result[1].$result[2],'pa_live_'),'secret_output');}

$_SESSION=[];putenv(Receipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));$db=connection();$dir='/tmp/pa-inventory-mysql-'.bin2hex(random_bytes(5));mkdir($dir,0700);$cases=[];
$cases['mysql_exact_two_scopes_binding_both_generations_and_json_audit']=function()use($db):void{
    $service=new Provisioner($db);$other=$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC);$result=$service->apply($service->dryRun(input()),secret());$key=$db->query('SELECT * FROM api_keys WHERE id<>7')->fetch(PDO::FETCH_ASSOC);
    check($result['state']==='created' && $key['scopes']==='api.capabilities.read,directory.inventory.read' && (int)$key['api_v2_application_id']===3 && generations($db)===[5,6]);
    check($other===$db->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC) && rows($db,'api_v2_applications')===2);$audit=$db->query('SELECT details FROM system_audit')->fetchColumn();check(json_decode($audit,true)['actor']['kind']==='local_operator' && !str_contains($audit,secret()));
};
$cases['mysql_lost_ack_replay_no_second_generation_advance']=function()use($db):void{$service=new Provisioner($db);$plan=$service->dryRun(input());$db->loseAck=true;refuses(fn()=>$service->apply($plan,secret()),'synthetic_lost_ack');check($service->apply($plan,secret(),true)['state']==='already_committed' && generations($db)===[5,6] && rows($db,'api_keys')===2 && rows($db,'system_audit')===1);};
$cases['mysql_audit_failure_rolls_back_binding_and_generations']=function()use($db):void{$service=new Provisioner($db);$plan=$service->dryRun(input());$db->exec("CREATE TRIGGER fail_inventory_audit BEFORE INSERT ON system_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic'");refuses(fn()=>$service->apply($plan,secret()),'audit_required');check(rows($db,'api_keys')===1 && generations($db)===[4,5]);};
$cases['mysql_incomplete_or_nontransactional_schema_refused']=function()use($db):void{$db->exec('ALTER TABLE system_audit ENGINE=MyISAM');refuses(fn()=>(new Provisioner($db))->dryRun(input()),'transactional_tables_required');};
$cases['cli_dryrun_without_key_missing_apply_refuses_encrypted_replay']=function()use($db,$dir):void{
    putenv(Receipt::KEY_ENV);$result=decoded(command(dry($dir,'basic')));check($result['scopes']===['api.capabilities.read','directory.inventory.read'] && rows($db,'api_keys')===1 && !file_exists($dir.'/basic-key.json'));
    $args=apply($dir,'basic');check(command($args)[0]===1 && !file_exists($dir.'/basic-key.json'));putenv(Receipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));
    $noConfirmation=$args;unset($noConfirmation[3]);check(command(array_values($noConfirmation))[0]===1 && !file_exists($dir.'/basic-key.json'));$unreviewed=$args;$unreviewed[5]='--reviewed-plan-sha256='.str_repeat('0',64);check(command($unreviewed)[0]===1 && !file_exists($dir.'/basic-key.json'));check(command([...$args,'--scopes=full'])[0]===1 && !file_exists($dir.'/basic-key.json'));
    $created=command($args);check(decoded($created)['state']==='created');$secret=material($dir,'basic')['secret'];$bytes=file_get_contents($dir.'/basic-key.json');check(!str_contains($bytes,$secret) && (fileperms($dir.'/basic-key.json')&0777)===0600);noSecret($created,$secret);
    $again=command($args);check(decoded($again)['state']==='already_committed' && generations($db)===[5,6] && file_get_contents($dir.'/basic-key.json')===$bytes);noSecret($again,$secret);
};
$cases['cli_prepared_ciphertext_readonly_reconcile_then_explicit_resume']=function()use($db,$dir):void{
    decoded(command(dry($dir,'prepared')));$plan=Files::read($dir.'/prepared-plan.json');$vault=new Receipt($plan['secretFile']);$vault->lock();$secret=$vault->create($plan)['secret'];unset($vault);$bytes=file_get_contents($plan['secretFile']);$args=apply($dir,'prepared');$held=command($args);check($held[0]===3 && json_decode($held[1],true)['state']==='not_committed' && generations($db)===[4,5]);noSecret($held,$secret);
    $created=command([...$args,'--resume-prepared']);check(decoded($created)['state']==='created' && generations($db)===[5,6] && file_get_contents($plan['secretFile'])===$bytes);noSecret($created,$secret);
};
$cases['cli_generation_changed_wrong_key_and_plaintext_never_issue']=function()use($db,$dir):void{
    decoded(command(dry($dir,'changed')));$db->exec('UPDATE api_v2_project_authorization_state SET authorization_generation=6 WHERE application_pk=3');$held=command(apply($dir,'changed'));check($held[0]===1 && rows($db,'api_keys')===1);$material=material($dir,'changed');noSecret($held,$material['secret']);
    putenv(Receipt::KEY_ENV.'='.base64_encode(str_repeat('w',32)));$wrong=command(apply($dir,'changed'));check($wrong[0]===1 && rows($db,'api_keys')===1);noSecret($wrong,$material['secret']);putenv(Receipt::KEY_ENV.'='.base64_encode(str_repeat('k',32)));
    decoded(command(dry($dir,'plaintext')));$plan=Files::read($dir.'/plaintext-plan.json');Files::writeNew($plan['secretFile'],['version'=>1,'plan'=>$plan,'secret'=>secret()]);$bytes=file_get_contents($plan['secretFile']);check(command([...apply($dir,'plaintext'),'--resume-prepared'])[0]===1 && file_get_contents($plan['secretFile'])===$bytes && rows($db,'api_keys')===1);
};
$cases['concurrent_cli_distinct_cipher_receipts_one_key_one_generation_advance']=function()use($db,$dir):void{
    decoded(command(dry($dir,'left')));decoded(command(dry($dir,'right')));$db->beginTransaction();$db->query('SELECT singleton FROM api_v2_history_identity WHERE singleton=1 FOR UPDATE');$children=[];
    try{foreach(['left','right'] as $name){$process=proc_open(apply($dir,$name),[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process),'child_start');$children[]=[$process,$pipes];}$deadline=microtime(true)+5;while((!file_exists($dir.'/left-key.json') || !file_exists($dir.'/right-key.json')) && microtime(true)<$deadline)usleep(20000);check(file_exists($dir.'/left-key.json') && file_exists($dir.'/right-key.json'),'children_not_prepared');}finally{if($db->inTransaction())$db->rollBack();}
    $results=[];foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$results[]=[proc_close($process),$out,$err];}$codes=array_column($results,0);sort($codes);check($codes===[0,1] && generations($db)===[5,6] && rows($db,'api_keys')===2 && rows($db,'system_audit')===1);
    foreach(['left','right'] as $name){$secret=material($dir,$name)['secret'];foreach($results as $result)noSecret($result,$secret);check(!str_contains(file_get_contents($dir.'/'.$name.'-key.json'),$secret));}
};
$cases['mysql_revoked_or_wrong_application_key_never_repaired']=function()use($db):void{$service=new Provisioner($db);$plan=$service->dryRun(input());$key=$service->apply($plan,secret());$db->exec('UPDATE api_keys SET api_v2_application_id=4 WHERE id='.$key['apiKeyId']);refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');$db->exec('UPDATE api_keys SET api_v2_application_id=3,revoked_at=NOW() WHERE id='.$key['apiKeyId']);refuses(fn()=>$service->apply($plan,secret(),true),'reconciliation_required');check(generations($db)===[5,6] && rows($db,'api_keys')===2);};
$passed=0;try{foreach($cases as $name=>$case){fixture($db);try{$case();$passed++;echo 'PASS '.$name.PHP_EOL;}catch(Throwable){fwrite(STDERR,'FAIL '.$name.' (diagnostics withheld)'.PHP_EOL);exit(1);}}echo $passed.'/'.count($cases).' inventory MySQL cases passed'.PHP_EOL;}finally{foreach(glob($dir.'/*')?:[] as $path)unlink($path);rmdir($dir);}
