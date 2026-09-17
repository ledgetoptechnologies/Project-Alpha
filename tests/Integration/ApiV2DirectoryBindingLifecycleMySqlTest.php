<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Disposable MySQL 8.4 coverage for the generic binding lifecycle boundary. */
final class ApiV2DirectoryBindingLifecycleMySqlTest extends TestCase
{
    private PDO $first;
    private PDO $second;

    protected function setUp(): void
    {
        $dsn = getenv('API_V2_ORG_PROFILE_MYSQL_DSN');
        $user = getenv('API_V2_ORG_PROFILE_MYSQL_USER');
        $password = getenv('API_V2_ORG_PROFILE_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) {
            self::markTestSkipped('Run tools/run-api-v2-organization-profile-mysql-integration.ps1 for disposable MySQL 8.4 lifecycle tests.');
        }
        $database = trim((string)(getenv('API_V2_ORG_PROFILE_MYSQL_DATABASE') ?: ''));
        if (getenv('API_V2_ORG_PROFILE_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only'
            || preg_match('/^api_v2_org_profile_test_[a-f0-9]{32}$/D', $database) !== 1) {
            throw new \RuntimeException('Lifecycle MySQL tests require the disposable runner and destructive-test sentinel.');
        }
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        if (!hash_equals($database, (string)$this->first->query('SELECT DATABASE()')->fetchColumn())) throw new \RuntimeException('Refusing an unrecognized MySQL database.');
        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->second->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; SET SESSION innodb_lock_wait_timeout=1');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_binding_status.php';
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $pdo) if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    }

    public function testDeleteStatusDenialRestoreAndExternalIdReservationAreAtomic(): void
    {
        $this->first->beginTransaction();
        \api_v2_directory_record_delete($this->first, 'client', str_repeat('a', 32));
        $this->first->exec('DELETE FROM clients WHERE id=9');
        $this->first->commit();
        self::assertSame('tombstoned', $this->first->query('SELECT status FROM api_v2_directory_external_bindings')->fetchColumn());
        self::assertSame('1', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        self::assertSame(410, \api_v2_binding_status_read($this->first, 'client', 'old/external', 7, $this->headers(), '423e4567-e89b-42d3-a456-426614174000')['status']);

        $this->first->exec("INSERT INTO clients VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Restored',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)");
        $this->first->beginTransaction();
        \api_v2_directory_record($this->first, 'client', 9);
        $this->first->commit();
        self::assertSame('3', (string)$this->first->query("SELECT revision FROM api_v2_directory_resource_state WHERE public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetchColumn());
        self::assertSame(0, (int)$this->first->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE status='active'")->fetchColumn());
        // Existing external IDs remain reserved by their tombstones until a
        // new explicit command rebinds this same external ID after restore.
        self::assertSame(1, (int)$this->first->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE external_id='old/external' AND status='tombstoned'")->fetchColumn());
    }

    public function testConcurrentDeleteAndFailedGenerationAdvanceDoNotPartiallyMutate(): void
    {
        $this->first->beginTransaction();
        $lock = $this->first->prepare("SELECT public_id FROM api_v2_directory_resource_state WHERE resource_type='client' AND public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' FOR UPDATE");
        $lock->execute();
        $this->second->beginTransaction();
        try {
            \api_v2_directory_record_delete($this->second, 'client', str_repeat('a', 32));
            self::fail('Expected competing delete to wait on the source row in a real mutation path.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        } finally {
            if ($this->second->inTransaction()) $this->second->rollBack();
        }
        $this->first->rollBack();

        $this->first->exec("CREATE TRIGGER stop_lifecycle_generation BEFORE UPDATE ON api_v2_directory_authorization_state FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='blocked'");
        $this->first->beginTransaction();
        try {
            \api_v2_directory_record_delete($this->first, 'client', str_repeat('a', 32));
            self::fail('Expected generation failure.');
        } catch (PDOException) {
            $this->first->rollBack();
        }
        self::assertSame('1', (string)$this->first->query("SELECT revision FROM api_v2_directory_resource_state WHERE public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetchColumn());
        self::assertSame('active', $this->first->query('SELECT status FROM api_v2_directory_external_bindings')->fetchColumn());
        self::assertSame('0', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
    }

    public function testStatusReadSerializesWithApiKeyRevocationLock(): void
    {
        $this->first->beginTransaction();
        $lock = $this->first->prepare('SELECT id FROM api_keys WHERE id=? FOR UPDATE');
        $lock->execute([7]);
        try {
            \api_v2_binding_status_read($this->second, 'client', 'old/external', 7, $this->headers(), '423e4567-e89b-42d3-a456-426614174000');
            self::fail('Expected the status read to wait behind the API-key revocation lock.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        } finally {
            if ($this->first->inTransaction()) $this->first->rollBack();
            if ($this->second->inTransaction()) $this->second->rollBack();
        }
    }

    public function testLifecycleMigrationRejectsLegacyLedgerBeforeTombstoningAuthority(): void
    {
        require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
        $missingAuthorizationPublicId = str_repeat('e', 32);
        $hash = str_repeat('d', 64);
        $this->first->exec("UPDATE api_v2_directory_authorization_state SET authorization_generation=9223372036854775807 WHERE application_pk=3;
            UPDATE api_v2_directory_resource_state SET present=0 WHERE resource_type='client' AND public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
            INSERT INTO api_v2_applications VALUES(99,'523e4567-e89b-42d3-a456-426614174000','Missing authorization state');
            INSERT INTO api_v2_directory_resource_state VALUES('client','{$missingAuthorizationPublicId}',1,'{$hash}',0);
            CREATE TABLE api_v2_directory_binding_lifecycle_repairs(application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY,target_authorization_generation BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
            INSERT INTO api_v2_directory_binding_lifecycle_repairs VALUES(3,0),(99,1);");
        $this->first->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status) VALUES(99,'client','missing/auth',?,1,?,'active')")
            ->execute([$missingAuthorizationPublicId, $hash]);
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0095_api_v2_directory_binding_lifecycle.sql');
        $statements = \migration_statements($sql);
        self::assertCount(10, $statements);
        // The durable table is deliberately the unconstrained pre-fix shape.
        // Copying it into the temporary validator must fail before statement 6
        // can tombstone either active binding.
        foreach ([0, 1, 2] as $index) $this->first->exec($statements[$index]);
        try {
            $this->first->exec($statements[3]);
            self::fail('Expected legacy zero/missing authorization ledger validation to fail.');
        } catch (PDOException $error) {
            self::assertContains((int)($error->errorInfo[1] ?? 0), [3819, 4025, 1048], $error->getMessage());
        }
        self::assertSame('active', $this->first->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='old/external'")->fetchColumn());
        self::assertSame('active', $this->first->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='missing/auth'")->fetchColumn());
        self::assertSame('9223372036854775807', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        $this->first->exec($statements[9]);
    }

    public function testLifecycleMigrationPreflightsAndRetriesAfterTheBindingUpdate(): void
    {
        require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
        $publicId = str_repeat('a', 32);
        $secondPublicId = str_repeat('b', 32);
        $controlPublicId = str_repeat('c', 32);
        $missingAuthorizationPublicId = str_repeat('e', 32);
        $hash = str_repeat('d', 64);
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0095_api_v2_directory_binding_lifecycle.sql');
        $statements = \migration_statements($sql);
        self::assertCount(10, $statements);
        // The staging statement is a fail-closed preflight. An affected
        // binding without a watermark must not reach the following UPDATE.
        // The production binding FK permits this application but the damaged
        // installation lacks its required authorization-state row.
        $this->first->exec("INSERT INTO api_v2_applications VALUES(99,'523e4567-e89b-42d3-a456-426614174000','Missing authorization state');
            INSERT INTO api_v2_directory_resource_state VALUES('client','{$missingAuthorizationPublicId}',1,'{$hash}',0)");
        $this->first->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status) VALUES(99,'client','missing/auth',?,1,?,'active')")
            ->execute([$missingAuthorizationPublicId, $hash]);
        foreach ([0, 1, 2, 3] as $index) $this->first->exec($statements[$index]);
        try {
            $this->first->exec($statements[4]);
            self::fail('Expected the migration preflight to reject missing authorization state.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
        }
        self::assertSame('active', $this->first->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='missing/auth'")->fetchColumn());
        $this->first->exec($statements[9]);
        // Isolate the following interruption/retry case from the deliberately
        // invalid preflight fixture, including any legacy runner residue.
        $this->first->exec('DROP TABLE IF EXISTS api_v2_directory_binding_lifecycle_repairs');
        $this->first->exec("DELETE FROM api_v2_directory_external_bindings WHERE external_id='missing/auth'; DELETE FROM api_v2_directory_resource_state WHERE public_id='{$missingAuthorizationPublicId}'");
        $this->first->exec("UPDATE api_v2_directory_resource_state SET present=0 WHERE resource_type='client' AND public_id='{$publicId}';
            INSERT INTO api_v2_applications VALUES(4,'423e4567-e89b-42d3-a456-426614174000','Second disposable');
            INSERT INTO api_v2_directory_authorization_state VALUES(4,5);
            INSERT INTO api_v2_directory_resource_state VALUES('client','{$secondPublicId}',1,'{$hash}',0);
            INSERT INTO api_v2_directory_resource_state VALUES('client','{$controlPublicId}',1,'{$hash}',1);");
        $this->first->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status) VALUES(4,'client','second/missing',?,1,?,'active'),(3,'client','control/present',?,1,?,'active')")
            ->execute([$secondPublicId, $hash, $controlPublicId, $hash]);

        // The runner can stop after any individually committed statement. Stop
        // immediately after tombstoning bindings, then execute the real file
        // again to prove the retained repair ledger advances each app once.
        for ($index = 0; $index <= 6; $index++) $this->first->exec($statements[$index]);
        self::assertSame(2, (int)$this->first->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE status='tombstoned'")->fetchColumn());
        self::assertSame('0', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        foreach ($statements as $statement) $this->first->exec($statement);
        self::assertSame('tombstoned', $this->first->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='old/external'")->fetchColumn());
        self::assertSame('tombstoned', $this->first->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='second/missing'")->fetchColumn());
        self::assertSame('active', $this->first->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='control/present'")->fetchColumn());
        self::assertSame('1', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        self::assertSame('6', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=4')->fetchColumn());
        self::assertSame(0, (int)$this->first->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='api_v2_directory_binding_lifecycle_repairs'")->fetchColumn());
        foreach ($statements as $statement) $this->first->exec($statement);
        self::assertSame('1', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        self::assertSame('6', (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=4')->fetchColumn());
    }

    private function headers(): array
    {
        return ['source' => '123e4567-e89b-42d3-a456-426614174000', 'application' => '223e4567-e89b-42d3-a456-426614174000', 'epoch' => '323e4567-e89b-42d3-a456-426614174000'];
    }

    private function resetSchema(): void
    {
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['api_v2_directory_binding_lifecycle_repairs','api_v2_directory_external_bindings','api_v2_directory_resource_changes','api_v2_directory_resource_state','api_v2_directory_authorization_state','api_keys','api_v2_applications','api_v2_history_identity','clients'] as $table) $this->first->exec("DROP TABLE IF EXISTS `$table`");
        $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec("CREATE TABLE api_v2_applications(id BIGINT UNSIGNED PRIMARY KEY,application_id CHAR(36) NOT NULL,name VARCHAR(191) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE api_keys(id BIGINT UNSIGNED PRIMARY KEY,api_v2_application_id BIGINT UNSIGNED,revoked_at DATETIME NULL,FOREIGN KEY(api_v2_application_id) REFERENCES api_v2_applications(id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_history_identity(singleton TINYINT UNSIGNED PRIMARY KEY,source_instance_id CHAR(36) NOT NULL,history_epoch CHAR(36) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT UNSIGNED PRIMARY KEY,authorization_generation BIGINT UNSIGNED NOT NULL,FOREIGN KEY(application_pk) REFERENCES api_v2_applications(id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_state(resource_type ENUM('client','organization') NOT NULL,public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,revision BIGINT UNSIGNED NOT NULL,projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,present TINYINT(1) NOT NULL,PRIMARY KEY(resource_type,public_id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_changes(resource_type ENUM('client','organization') NOT NULL,public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,revision BIGINT UNSIGNED NOT NULL,action ENUM('upsert','delete') NOT NULL,PRIMARY KEY(resource_type,public_id,revision),FOREIGN KEY(resource_type,public_id) REFERENCES api_v2_directory_resource_state(resource_type,public_id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_external_bindings(application_pk BIGINT UNSIGNED NOT NULL,resource_type ENUM('client','organization') NOT NULL,external_id VARBINARY(764) NOT NULL,public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,resource_revision BIGINT UNSIGNED NOT NULL,resource_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,status ENUM('active','tombstoned') NOT NULL,tombstoned_at DATETIME(6) NULL,created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE KEY uq_binding_public(application_pk,resource_type,public_id),FOREIGN KEY(resource_type,public_id) REFERENCES api_v2_directory_resource_state(resource_type,public_id)) ENGINE=InnoDB;
            CREATE TABLE clients(id INT PRIMARY KEY,public_id CHAR(32) NOT NULL UNIQUE,name VARCHAR(150),email VARCHAR(255),phone VARCHAR(50),client_type VARCHAR(50),organization_id INT,address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(100),postal_code VARCHAR(32),country VARCHAR(100)) ENGINE=InnoDB;
            INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000','Disposable'); INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO clients VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)");
        $this->first->beginTransaction();
        \api_v2_directory_record($this->first, 'client', 9);
        $this->first->commit();
        $hash = (string)$this->first->query('SELECT projection_sha256 FROM api_v2_directory_resource_state')->fetchColumn();
        $this->first->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status) VALUES(3,'client','old/external','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,?,'active')")->execute([$hash]);
    }
}
