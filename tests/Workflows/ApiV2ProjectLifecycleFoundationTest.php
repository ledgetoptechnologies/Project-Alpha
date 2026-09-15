<?php

declare(strict_types=1);

use App\Services\ProjectRevisionService;
use PHPUnit\Framework\TestCase;

final class ApiV2ProjectLifecycleFoundationTest extends TestCase
{
    private PDO $pdo;
    private array $headers = [
        'source'=>'123e4567-e89b-42d3-a456-426614174000',
        'application'=>'223e4567-e89b-42d3-a456-426614174000',
        'epoch'=>'323e4567-e89b-42d3-a456-426614174000',
    ];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        require_once dirname(__DIR__,2).'/src/utils/api_v2_project_lifecycle.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_project_backfill.php';
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER);CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,client_id INTEGER,organization_id INTEGER,name TEXT,description TEXT,status TEXT,
                completed_at TEXT,archived_at TEXT,portal_publish_enabled INTEGER,revision INTEGER,estimated_start TEXT,estimated_end TEXT,source_version TEXT,updated_at TEXT);
            CREATE TABLE project_retention_guards(project_public_id TEXT PRIMARY KEY,established_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE project_changes(project_public_id TEXT,revision INTEGER,action_name TEXT,projection_sha256 TEXT,application_pk INTEGER,command_id TEXT,actor_user_id INTEGER,changed_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(project_public_id,revision));
            CREATE TABLE api_v2_project_lifecycle_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,
                project_public_id TEXT,expected_revision INTEGER,result_revision INTEGER,result_status TEXT,result_completed_at TEXT,result_archived_at TEXT,outcome TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(application_pk,history_epoch,command_id));
            CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT);
            CREATE TABLE contracts(id INTEGER PRIMARY KEY,project_id INTEGER,doc_number TEXT,status TEXT,contract_type TEXT);
            CREATE TABLE invoices(id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,balance_due TEXT,collection_mode TEXT,finalized_at TEXT);
            CREATE TABLE project_invoices(id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,balance_due TEXT,finalized_at TEXT);
            CREATE TABLE project_invoice_items(id INTEGER PRIMARY KEY,project_invoice_id INTEGER,invoice_id INTEGER);
            CREATE TABLE system_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,organization_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT,ip_address TEXT,user_agent TEXT);
            CREATE TABLE project_service_locations(id INTEGER PRIMARY KEY,project_id INTEGER,service_location_id INTEGER,is_default INTEGER);
            CREATE TABLE schedule_entries(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,job_id INTEGER,service_location_id INTEGER,title TEXT,starts_at TEXT,ends_at TEXT,timezone TEXT,status TEXT,source_type TEXT,source_id INTEGER,created_by INTEGER);
            INSERT INTO api_keys VALUES(7,3,NULL);INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');
            INSERT INTO app_config VALUES(0,'contract_settlement_enabled','0');
            INSERT INTO projects VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',NULL,NULL,'Exact Project',NULL,'active',NULL,NULL,1,1,'2026-01-01','2026-01-02','v1',NULL);");
        $this->pdo->beginTransaction();(new ProjectRevisionService($this->pdo))->initialize(1,'baseline');$this->pdo->commit();
    }

    public function testExactReadUsesPermanentRevisionAndDerivedOverdueWarning(): void
    {
        $payload=api_v2_project_read($this->pdo,str_repeat('a',32),7,$this->headers,'r1');
        self::assertSame('1',$payload['resource']['revision']);self::assertSame('active',$payload['data']['status']);
        self::assertTrue($payload['data']['overdueWarning']);self::assertFalse($payload['data']['archived']);
        $wrong=$this->headers;$wrong['application']='423e4567-e89b-42d3-a456-426614174000';
        self::assertNull(api_v2_project_read($this->pdo,str_repeat('a',32),7,$wrong,'r2'));
    }

    public function testArchiveRestoreAreIdempotentAndPreserveTheProject(): void
    {
        $archive=['commandId'=>'423e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1'];
        $first=api_v2_project_lifecycle_write($this->pdo,str_repeat('a',32),'archive',$archive,7,$this->headers,'r1');
        self::assertSame(200,$first['status']);self::assertSame('2',$first['payload']['resource']['revision']);
        self::assertTrue($first['payload']['result']['archived']);self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        $replay=api_v2_project_lifecycle_write($this->pdo,str_repeat('a',32),'archive',$archive,7,$this->headers,'r2');
        self::assertTrue($replay['payload']['replayed']);self::assertSame('2',$replay['payload']['resource']['revision']);
        self::assertSame(409,api_v2_project_lifecycle_write($this->pdo,str_repeat('a',32),'restore',[
            'commandId'=>'523e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1'],7,$this->headers,'r3')['status']);
        $restore=['commandId'=>'623e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'2'];
        self::assertSame('3',api_v2_project_lifecycle_write($this->pdo,str_repeat('a',32),'restore',$restore,7,$this->headers,'r4')['payload']['resource']['revision']);
        self::assertNull($this->pdo->query('SELECT archived_at FROM projects')->fetchColumn());
    }

    public function testCompletionUsesSharedCloseoutAndTransactionalSchedule(): void
    {
        $command=['commandId'=>'723e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1'];
        $out=api_v2_project_lifecycle_write($this->pdo,str_repeat('a',32),'complete',$command,7,$this->headers,'r');
        self::assertSame(200,$out['status']);self::assertSame('completed',$out['payload']['result']['status']);
        self::assertSame('completed',$this->pdo->query('SELECT status FROM schedule_entries')->fetchColumn());
        $audit=json_decode((string)$this->pdo->query('SELECT details FROM system_audit')->fetchColumn(),true);
        self::assertSame('223e4567-e89b-42d3-a456-426614174000',$audit['api_v2']['applicationId']);
        self::assertSame($command['commandId'],$audit['api_v2']['commandId']);
    }

    public function testStrictParserCapabilitiesAndNoHardDeleteSurface(): void
    {
        self::assertNull(api_v2_project_lifecycle_command_parse('{"expectedRevision":"1","commandId":"423e4567-e89b-42d3-a456-426614174000"}'));
        self::assertNotNull(api_v2_project_lifecycle_command_parse('{"commandId":"423e4567-e89b-42d3-a456-426614174000","expectedRevision":"1"}'));
        require_once dirname(__DIR__,2).'/src/utils/api_scopes.php';
        foreach(['projects.v2.read','projects.lifecycle.complete','projects.lifecycle.cancel','projects.lifecycle.archive','projects.lifecycle.restore']as$scope){
            self::assertArrayHasKey($scope,api_scope_catalog());self::assertFalse(api_key_has_scope('full',$scope,false));
        }
        $root=dirname(__DIR__,2);$router=(string)file_get_contents($root.'/public/index.php');
        self::assertStringNotContainsString('/delete/commands',$router);
        self::assertStringNotContainsString('DELETE FROM projects',(string)file_get_contents($root.'/src/controllers/project/projects_delete.php'));
        self::assertStringContainsString('APP_API_V2_PROJECTS_READ_ENABLED=false',(string)file_get_contents($root.'/config/.env.example'));
    }

    public function testBackfillDryRunAndApplyUseCanonicalProjector(): void
    {
        $this->pdo->exec('DELETE FROM project_changes');
        $dry=api_v2_project_backfill($this->pdo,null,10,true);self::assertSame(1,$dry['inserted']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM project_changes')->fetchColumn());
        $apply=api_v2_project_backfill($this->pdo,null,10,false);self::assertSame(1,$apply['inserted']);
        self::assertNotNull(api_v2_project_read($this->pdo,str_repeat('a',32),7,$this->headers,'r'));
    }
}
