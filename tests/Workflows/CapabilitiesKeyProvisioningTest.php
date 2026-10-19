<?php
declare(strict_types=1);
namespace Tests\Workflows;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Tests\Fixtures\CapabilityLostAckPDO;
use Tests\Fixtures\CapabilitiesKeyProvisioningCases;
require_once dirname(__DIR__).'/fixtures/CapabilitiesKeyProvisioningCases.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CapabilitiesKeyProvisioningTest extends TestCase
{
    use CapabilitiesKeyProvisioningCases;
    private CapabilityLostAckPDO $pdo;
    private $oldSession;
    protected function setUp(): void
    {
        if(!function_exists('posix_geteuid')||posix_geteuid()!==0)self::markTestSkipped('Local operator tests require a real root CLI container.');
        $this->oldSession=$_SESSION??null;$_SESSION=[];
        $this->pdo=new CapabilityLostAckPDO('sqlite::memory:');$this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,filename TEXT); INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql'),(102,'0102_api_v2_project_synchronization.sql');
          CREATE TABLE api_keys(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,key_prefix TEXT,key_hash TEXT UNIQUE,scopes TEXT,allowed_ips TEXT,revoked_at TEXT,api_v2_application_id INTEGER);
          CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
          CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT UNIQUE,name TEXT);
          CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
          CREATE TABLE api_v2_project_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
          CREATE TABLE system_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT,ip_address TEXT,user_agent TEXT);
          INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','223e4567-e89b-42d3-a456-426614174000');
          INSERT INTO api_v2_applications VALUES(3,'323e4567-e89b-42d3-a456-426614174000','Existing app'),(4,'423e4567-e89b-42d3-a456-426614174000','Unrelated app');
          INSERT INTO api_v2_directory_authorization_state VALUES(3,4),(4,8); INSERT INTO api_v2_project_authorization_state VALUES(3,5),(4,9);
          INSERT INTO api_keys(id,name,key_prefix,key_hash,scopes,api_v2_application_id) VALUES(7,'Existing command key','existing','synthetic-existing-hash','full',3)");
    }
    protected function tearDown():void{if(isset($this->oldSession))$_SESSION=$this->oldSession;else unset($_SESSION);}
}
