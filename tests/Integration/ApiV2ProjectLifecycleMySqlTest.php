<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ProjectRevisionService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ApiV2ProjectLifecycleMySqlTest extends TestCase
{
    private PDO $first;
    private PDO $second;

    private array $headers = [
        'source' => '123e4567-e89b-42d3-a456-426614174000',
        'application' => '223e4567-e89b-42d3-a456-426614174000',
        'epoch' => '323e4567-e89b-42d3-a456-426614174000',
    ];

    protected function setUp(): void
    {
        $dsn = getenv('API_V2_PROJECT_MYSQL_DSN');
        $user = getenv('API_V2_PROJECT_MYSQL_USER');
        $password = getenv('API_V2_PROJECT_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) {
            self::markTestSkipped('Run tools/run-api-v2-project-lifecycle-mysql-integration.ps1 for isolated MySQL tests.');
        }
        $database = trim((string)(getenv('API_V2_PROJECT_MYSQL_DATABASE') ?: ''));
        if (getenv('API_V2_PROJECT_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only'
            || preg_match('/^api_v2_project_test_[a-f0-9]{32}$/D', $database) !== 1) {
            throw new \RuntimeException('Project lifecycle MySQL tests require the disposable runner sentinel.');
        }
        $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        if (!hash_equals($database, (string)$this->first->query('SELECT DATABASE()')->fetchColumn())) {
            throw new \RuntimeException('Refusing to reset a non-disposable database.');
        }
        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->second->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; SET SESSION innodb_lock_wait_timeout=1');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_project_lifecycle.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_project_sync.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_project_release_safety.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_application_provisioning.php';
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $pdo) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testMigrationNormalizesOverdueAndHardDeleteIsRestricted(): void
    {
        self::assertSame('active', $this->first->query('SELECT status FROM projects')->fetchColumn());
        self::assertSame(1, (int)$this->first->query('SELECT COUNT(*) FROM project_retention_guards')->fetchColumn());
        $this->expectException(PDOException::class);
        $this->first->exec('DELETE FROM projects WHERE id=1');
    }

    public function testArchiveReplayAndRevisionFence(): void
    {
        $command = ['commandId'=>'423e4567-e89b-42d3-a456-426614174000', 'expectedRevision'=>'1'];
        $first = \api_v2_project_lifecycle_write($this->first, str_repeat('a', 32), 'archive', $command, 7, $this->headers, 'one');
        self::assertSame(200, $first['status']);
        self::assertSame('2', $first['payload']['resource']['revision']);
        self::assertTrue($first['payload']['result']['archived']);
        self::assertSame(['portalPublished'=>false,'publicLinkEnabled'=>false], $first['payload']['result']['presentation']);
        $replay = \api_v2_project_lifecycle_write($this->second, str_repeat('a', 32), 'archive', $command, 7, $this->headers, 'two');
        self::assertSame(200, $replay['status']);
        self::assertTrue($replay['payload']['replayed']);
        $stale = ['commandId'=>'523e4567-e89b-42d3-a456-426614174000', 'expectedRevision'=>'1'];
        self::assertSame(409, \api_v2_project_lifecycle_write($this->second, str_repeat('a', 32), 'restore', $stale, 7, $this->headers, 'three')['status']);
    }

    public function testReceiptFailureRollsBackLifecycleHistoryAuditAndSchedule(): void
    {
        $this->first->exec("CREATE TRIGGER stop_project_receipt BEFORE INSERT ON api_v2_project_lifecycle_command_receipts FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='blocked receipt'");
        $command = ['commandId'=>'623e4567-e89b-42d3-a456-426614174000', 'expectedRevision'=>'1'];
        self::assertSame(409, \api_v2_project_lifecycle_write($this->first, str_repeat('a', 32), 'archive', $command, 7, $this->headers, 'rollback')['status']);
        $project = $this->first->query('SELECT revision,archived_at FROM projects WHERE id=1')->fetch();
        self::assertSame(1, (int)$project['revision']);
        self::assertNull($project['archived_at']);
        self::assertSame(1, (int)$this->first->query('SELECT COUNT(*) FROM project_changes')->fetchColumn());
        foreach (['system_audit', 'schedule_entries', 'api_v2_project_lifecycle_command_receipts'] as $table) {
            self::assertSame(0, (int)$this->first->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(), $table);
        }
    }

    public function testApplicationIdentityLockSerializesConcurrentCommands(): void
    {
        $this->first->beginTransaction();
        $this->first->query('SELECT id FROM api_v2_applications WHERE id=3 FOR UPDATE')->fetchColumn();
        $command = ['commandId'=>'723e4567-e89b-42d3-a456-426614174000', 'expectedRevision'=>'1'];
        try {
            \api_v2_project_lifecycle_write($this->second, str_repeat('a', 32), 'archive', $command, 7, $this->headers, 'blocked');
            self::fail('Project lifecycle command crossed the application identity lock.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        }
        self::assertFalse($this->second->inTransaction());
        $this->first->commit();
        self::assertSame(200, \api_v2_project_lifecycle_write($this->second, str_repeat('a', 32), 'archive', $command, 7, $this->headers, 'after')['status']);
    }

    public function testProjectBindingReceiptFailureRollsBackBindingAndGeneration(): void
    {
        $this->first->exec("CREATE TRIGGER stop_project_sync_receipt BEFORE INSERT ON api_v2_project_command_receipts FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='blocked sync receipt'");
        $project=$this->first->query('SELECT * FROM projects WHERE id=1')->fetch(PDO::FETCH_ASSOC);$project=\api_v2_project_hydrate_relations($this->first,$project);$hash=ProjectRevisionService::projectionHash($project);
        $command=['commandId'=>'823e4567-e89b-42d3-a456-426614174000','externalId'=>'mysql-project','expectedPublicId'=>str_repeat('a',32),'expectedRevision'=>'1','expectedProjectionSha256'=>$hash,'expectedAuthorizationGeneration'=>'0'];
        self::assertSame(409,\api_v2_project_sync_write($this->first,'bind',$command,7,$this->headers,'rollback')['status']);
        self::assertSame(0,(int)$this->first->query('SELECT COUNT(*) FROM api_v2_project_external_bindings')->fetchColumn());
        self::assertSame(0,(int)$this->first->query('SELECT authorization_generation FROM api_v2_project_authorization_state')->fetchColumn());
    }

    public function testProjectBindingSerializesOnApplicationLock(): void
    {
        $project=$this->first->query('SELECT * FROM projects WHERE id=1')->fetch(PDO::FETCH_ASSOC);$project=\api_v2_project_hydrate_relations($this->first,$project);$hash=ProjectRevisionService::projectionHash($project);
        $command=['commandId'=>'923e4567-e89b-42d3-a456-426614174000','externalId'=>'mysql-project','expectedPublicId'=>str_repeat('a',32),'expectedRevision'=>'1','expectedProjectionSha256'=>$hash,'expectedAuthorizationGeneration'=>'0'];
        $this->first->beginTransaction();$this->first->query('SELECT id FROM api_v2_applications WHERE id=3 FOR UPDATE')->fetchColumn();
        try{\api_v2_project_sync_write($this->second,'bind',$command,7,$this->headers,'blocked');self::fail('Project binding crossed the application lock.');}catch(PDOException$error){self::assertSame(1205,(int)($error->errorInfo[1]??0));}
        self::assertFalse($this->second->inTransaction());$this->first->commit();$result=\api_v2_project_sync_write($this->second,'bind',$command,7,$this->headers,'after');self::assertSame(200,$result['status']);self::assertSame('1',$result['payload']['result']['authorizationGeneration']);
    }

    public function testExistingApplicationBindingPreservesApplicationIdentityAndAdvancesBothMySqlStatesOnRebind(): void
    {
        $this->first->exec("INSERT INTO api_keys(id,scopes,api_v2_application_id,revoked_at) VALUES(8,'api.capabilities.read',NULL,NULL)");
        $bound=\api_v2_application_bind_existing($this->first,8,'423e4567-e89b-42d3-a456-426614174000',false);
        self::assertFalse($bound['rebound']);
        self::assertSame(4,(int)$this->first->query('SELECT api_v2_application_id FROM api_keys WHERE id=8')->fetchColumn());
        self::assertSame(2,(int)$this->first->query('SELECT COUNT(*) FROM api_v2_applications')->fetchColumn());
        foreach(['api_v2_directory_authorization_state','api_v2_project_authorization_state']as$table)self::assertSame(1,(int)$this->first->query("SELECT authorization_generation FROM `$table` WHERE application_pk=4")->fetchColumn(),$table.' first bind');
        $rebound=\api_v2_application_bind_existing($this->first,8,'223e4567-e89b-42d3-a456-426614174000',false,'423e4567-e89b-42d3-a456-426614174000',true);
        self::assertTrue($rebound['rebound']);
        self::assertSame(3,(int)$this->first->query('SELECT api_v2_application_id FROM api_keys WHERE id=8')->fetchColumn());
        foreach(['api_v2_directory_authorization_state','api_v2_project_authorization_state']as$table){
            self::assertSame(1,(int)$this->first->query("SELECT authorization_generation FROM `$table` WHERE application_pk=3")->fetchColumn(),$table.' old');
            self::assertSame(2,(int)$this->first->query("SELECT authorization_generation FROM `$table` WHERE application_pk=4")->fetchColumn(),$table.' new');
        }
    }

    public function testReleaseAttestationRequiresAndReadsBackRealMySqlSchema(): void
    {
        $before=\api_v2_project_backfill_attestation($this->first);self::assertTrue($before['schemaReady'],json_encode($before,JSON_THROW_ON_ERROR));self::assertTrue($before['evidenceComplete'],json_encode($before,JSON_THROW_ON_ERROR));self::assertFalse($before['complete']);
        $digest=\api_v2_project_backfill_attestation_persist($this->first);self::assertTrue(\api_v2_project_backfill_attestation_receipt_is_current($this->first,$digest));
        $this->first->exec("UPDATE schema_migrations SET checksum='".str_repeat('0',64)."' WHERE version=102");self::assertFalse(\api_v2_project_backfill_attestation_receipt_is_current($this->first,$digest));
    }

    public function testReleaseAttestationFailsClosedWithoutBindingOneToOneUniqueKey(): void
    {
        $digest=\api_v2_project_backfill_attestation_persist($this->first);$this->first->exec('ALTER TABLE api_v2_project_external_bindings DROP INDEX uq_api_v2_project_binding_public');
        $proof=\api_v2_project_backfill_attestation($this->first);self::assertGreaterThan(0,$proof['violations']['index']);self::assertFalse($proof['evidenceComplete']);self::assertFalse($proof['complete']);self::assertFalse(\api_v2_project_backfill_attestation_receipt_is_current($this->first,$digest));
    }

    public function testReleaseAttestationFailsClosedWithoutReceiptProjectForeignKey(): void
    {
        $digest=\api_v2_project_backfill_attestation_persist($this->first);$this->first->exec('ALTER TABLE api_v2_project_command_receipts DROP FOREIGN KEY fk_api_v2_project_command_project');
        $proof=\api_v2_project_backfill_attestation($this->first);self::assertGreaterThan(0,$proof['violations']['foreign_key']);self::assertFalse($proof['evidenceComplete']);self::assertFalse($proof['complete']);self::assertFalse(\api_v2_project_backfill_attestation_receipt_is_current($this->first,$digest));
    }

    private function resetSchema(): void
    {
        $tables = ['api_v2_project_command_receipts','api_v2_project_external_bindings','api_v2_project_authorization_state','api_v2_directory_authorization_state','api_v2_project_backfill_attestations','api_v2_project_lifecycle_command_receipts','project_changes','project_retention_guards','managed_delivery_intent_outbox','schedule_entries','project_service_locations','system_audit','project_invoice_items','project_invoices','invoices','contracts','projects','clients','organizations','app_config','api_keys','api_v2_history_identity','api_v2_applications','schema_migrations','users'];
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) $this->first->exec("DROP TABLE IF EXISTS `$table`");
        $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec("CREATE TABLE users(id INT PRIMARY KEY) ENGINE=InnoDB;
            CREATE TABLE schema_migrations(version INT UNSIGNED PRIMARY KEY,filename VARCHAR(255),checksum CHAR(64)) ENGINE=InnoDB;
            CREATE TABLE api_v2_applications(id BIGINT UNSIGNED PRIMARY KEY,application_id CHAR(36),name VARCHAR(191)) ENGINE=InnoDB;
            CREATE TABLE api_v2_history_identity(singleton TINYINT UNSIGNED PRIMARY KEY,source_instance_id CHAR(36),history_epoch CHAR(36)) ENGINE=InnoDB;
            CREATE TABLE api_keys(id BIGINT UNSIGNED PRIMARY KEY,scopes TEXT,api_v2_application_id BIGINT UNSIGNED,revoked_at DATETIME NULL) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT UNSIGNED PRIMARY KEY,authorization_generation BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
            CREATE TABLE app_config(organization_id INT,config_key VARCHAR(191),config_value TEXT,PRIMARY KEY(organization_id,config_key)) ENGINE=InnoDB;
            CREATE TABLE organizations(id INT PRIMARY KEY,public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin) ENGINE=InnoDB;
            CREATE TABLE clients(id INT PRIMARY KEY,public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,organization_id INT NULL) ENGINE=InnoDB;
            CREATE TABLE projects(id INT PRIMARY KEY,public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,client_id INT NULL,organization_id INT NULL,name VARCHAR(255),description TEXT,status ENUM('not_started','active','overdue','completed','cancelled') NOT NULL DEFAULT 'not_started',completed_at DATETIME(6) NULL,source_version VARCHAR(191),public_project_enabled TINYINT NOT NULL DEFAULT 0,estimated_start DATE NULL,estimated_end DATE NULL,updated_at DATETIME(6) NULL) ENGINE=InnoDB;
            CREATE TABLE contracts(id INT PRIMARY KEY,project_id INT,doc_number VARCHAR(191),status VARCHAR(32),contract_type VARCHAR(32)) ENGINE=InnoDB;
            CREATE TABLE invoices(id INT PRIMARY KEY,project_id INT,status VARCHAR(32),balance_due DECIMAL(12,2),collection_mode VARCHAR(32),finalized_at DATETIME NULL) ENGINE=InnoDB;
            CREATE TABLE project_invoices(id INT PRIMARY KEY,project_id INT,status VARCHAR(32),balance_due DECIMAL(12,2),finalized_at DATETIME NULL) ENGINE=InnoDB;
            CREATE TABLE project_invoice_items(id INT PRIMARY KEY,project_invoice_id INT,invoice_id INT) ENGINE=InnoDB;
            CREATE TABLE system_audit(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL,organization_id INT NULL,action VARCHAR(191),entity_type VARCHAR(191),entity_id INT,details JSON,ip_address VARCHAR(64),user_agent VARCHAR(255)) ENGINE=InnoDB;
            CREATE TABLE project_service_locations(id INT PRIMARY KEY,project_id INT,service_location_id INT,is_default TINYINT) ENGINE=InnoDB;
            CREATE TABLE schedule_entries(id INT AUTO_INCREMENT PRIMARY KEY,project_id INT,job_id INT NULL,service_location_id INT NULL,title VARCHAR(255),starts_at DATETIME NULL,ends_at DATETIME NULL,timezone VARCHAR(64),status VARCHAR(32),source_type VARCHAR(32),source_id INT,created_by INT NULL,UNIQUE KEY uq_schedule_source(source_type,source_id)) ENGINE=InnoDB;
            CREATE TABLE managed_delivery_intent_outbox(id BIGINT AUTO_INCREMENT PRIMARY KEY,delivery_id CHAR(36),intent_type VARCHAR(16),target_delivery_id CHAR(36),scope_type VARCHAR(32),scope_public_id VARCHAR(128),delivered_at DATETIME(6) NULL,dead_lettered_at DATETIME(6) NULL,revoked_at DATETIME(6) NULL,last_error_code VARCHAR(64) NULL,claim_token CHAR(36) NULL,claimed_at DATETIME(6) NULL) ENGINE=InnoDB;
            INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000','test'),(4,'423e4567-e89b-42d3-a456-426614174000','second test');
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0),(4,0);
            INSERT INTO api_keys VALUES(7,'api.capabilities.read',3,NULL);INSERT INTO app_config VALUES(0,'contract_settlement_enabled','0');
            INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled','0');
            INSERT INTO projects VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',NULL,NULL,'MySQL Project',NULL,'overdue',NULL,'v1',1,'2026-01-01','2026-01-02',NULL);");
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0100_project_lifecycle_api_foundation.sql');
        self::assertNotFalse($migration);
        $this->first->exec($migration);
        $presentationMigration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0101_project_archive_presentation_revocation.sql');
        self::assertNotFalse($presentationMigration);
        $this->first->exec($presentationMigration);
        $syncMigration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0102_api_v2_project_synchronization.sql');
        self::assertNotFalse($syncMigration);$this->first->exec($syncMigration);
        $this->first->exec("INSERT INTO schema_migrations(version,filename,checksum) VALUES(88,'0088_api_v2_application_identity.sql',NULL),(89,'0089_api_v2_directory_revision_foundation.sql',NULL)");
        $checksum=hash_file('sha256',dirname(__DIR__,2).'/database/migrations/0102_api_v2_project_synchronization.sql');$insertMigration=$this->first->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(102,?,?)');$insertMigration->execute(['0102_api_v2_project_synchronization.sql',$checksum]);
        $this->first->beginTransaction();
        (new ProjectRevisionService($this->first))->initialize(1, 'baseline');
        $this->first->commit();
    }
}
