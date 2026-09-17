<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryOrganizationProfileCommandMySqlTest extends TestCase
{
    private PDO $first;
    private PDO $second;

    protected function setUp(): void
    {
        $dsn = getenv('API_V2_ORG_PROFILE_MYSQL_DSN');
        $user = getenv('API_V2_ORG_PROFILE_MYSQL_USER');
        $password = getenv('API_V2_ORG_PROFILE_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) {
            self::markTestSkipped('Run tools/run-api-v2-organization-profile-mysql-integration.ps1 for the isolated MySQL command tests.');
        }
        $expectedDatabase = trim((string) (getenv('API_V2_ORG_PROFILE_MYSQL_DATABASE') ?: ''));
        if (getenv('API_V2_ORG_PROFILE_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only'
            || preg_match('/^api_v2_org_profile_test_[a-f0-9]{32}$/D', $expectedDatabase) !== 1) {
            throw new \RuntimeException('Organization profile MySQL tests require the disposable runner and its destructive-test sentinel.');
        }
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        if (!hash_equals($expectedDatabase, (string) $this->first->query('SELECT DATABASE()')->fetchColumn())) {
            throw new \RuntimeException('Refusing to reset a MySQL database that was not created by the disposable test runner.');
        }
        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->second->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; SET SESSION innodb_lock_wait_timeout=1');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_organization_profile_command.php';
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $pdo) if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    }

    public function testRealMySqlLockContentionStaleRejectionAndExactReplay(): void
    {
        $command = $this->command('423e4567-e89b-42d3-a456-426614174000');
        $this->first->beginTransaction();
        $lock = $this->first->prepare('SELECT id FROM organizations WHERE public_id=? FOR UPDATE');
        $lock->execute([str_repeat('a', 32)]);
        try {
            \api_v2_directory_organization_profile_command_write($this->second, str_repeat('a', 32), $command, 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
            self::fail('A competing profile command unexpectedly crossed the organization lock.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int) ($error->errorInfo[1] ?? 0), $error->getMessage());
        }
        self::assertFalse($this->second->inTransaction());
        $this->first->commit();

        $first = \api_v2_directory_organization_profile_command_write($this->second, str_repeat('a', 32), $command, 7, $this->headers(), '623e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $first['status']);
        self::assertFalse($first['payload']['replayed']);
        self::assertSame('2', $first['payload']['result']['resource']['revision']);
        self::assertSame('private note', $this->first->query('SELECT notes FROM organizations WHERE id=9')->fetchColumn());
        self::assertSame('place-hidden', $this->first->query('SELECT google_place_id FROM addresses ORDER BY id DESC LIMIT 1')->fetchColumn());

        $stale = $this->command('523e4567-e89b-42d3-a456-426614174000');
        self::assertSame(409, \api_v2_directory_organization_profile_command_write($this->first, str_repeat('a', 32), $stale, 7, $this->headers(), '723e4567-e89b-42d3-a456-426614174000')['status']);
        self::assertSame('Updated Org', $this->first->query('SELECT name FROM organizations WHERE id=9')->fetchColumn());
        self::assertSame(1, (int) $this->first->query('SELECT COUNT(*) FROM api_v2_directory_organization_profile_command_receipts')->fetchColumn());

        $replay = \api_v2_directory_organization_profile_command_write($this->first, str_repeat('a', 32), $command, 7, $this->headers(), '823e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $replay['status']);
        self::assertTrue($replay['payload']['replayed']);
        self::assertSame('2', $replay['payload']['result']['resource']['revision']);
    }

    public function testRealMySqlReceiptFailureRollsBackProfileAddressAndRevision(): void
    {
        $this->first->exec("CREATE TRIGGER stop_org_profile_receipt BEFORE INSERT ON api_v2_directory_organization_profile_command_receipts FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='blocked receipt'");
        $result = \api_v2_directory_organization_profile_command_write($this->first, str_repeat('a', 32), $this->command('423e4567-e89b-42d3-a456-426614174000'), 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
        self::assertSame(409, $result['status']);
        self::assertSame('Example', $this->first->query('SELECT name FROM organizations WHERE id=9')->fetchColumn());
        self::assertSame('private note', $this->first->query('SELECT notes FROM organizations WHERE id=9')->fetchColumn());
        self::assertSame(1, (int) $this->first->query('SELECT COUNT(*) FROM addresses')->fetchColumn());
        self::assertSame('1', (string) $this->first->query("SELECT revision FROM api_v2_directory_resource_state WHERE resource_type='organization' AND public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetchColumn());
        self::assertSame(1, (int) $this->first->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
        self::assertSame(0, (int) $this->first->query('SELECT COUNT(*) FROM api_v2_directory_organization_profile_command_receipts')->fetchColumn());
    }

    private function headers(): array
    {
        return ['source' => '123e4567-e89b-42d3-a456-426614174000', 'application' => '223e4567-e89b-42d3-a456-426614174000', 'epoch' => '323e4567-e89b-42d3-a456-426614174000'];
    }

    private function command(string $commandId): array
    {
        $raw = ['commandId' => $commandId, 'expectedRevision' => '1', 'expectedAuthorizationGeneration' => '0', 'profile' => ['name' => 'Updated Org', 'generalEmail' => 'ops@example.test', 'generalPhone' => '555', 'addressLine1' => '1 Main', 'addressLine2' => '', 'city' => 'Austin', 'state' => 'TX', 'postalCode' => '78701', 'country' => 'US']];
        return \api_v2_directory_organization_profile_command_parse(json_encode($raw, JSON_THROW_ON_ERROR));
    }

    private function resetSchema(): void
    {
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['api_v2_directory_organization_profile_command_receipts', 'api_v2_directory_resource_changes', 'api_v2_directory_resource_state', 'api_v2_directory_authorization_state', 'api_keys', 'api_v2_applications', 'api_v2_history_identity', 'address_assignments', 'addresses', 'organizations', 'app_config'] as $table) $this->first->exec("DROP TABLE IF EXISTS `$table`");
        $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec("CREATE TABLE api_v2_applications(id BIGINT UNSIGNED NOT NULL PRIMARY KEY, application_id CHAR(36) NOT NULL, name VARCHAR(191) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE api_keys(id BIGINT UNSIGNED NOT NULL PRIMARY KEY, api_v2_application_id BIGINT UNSIGNED NULL, revoked_at DATETIME NULL, CONSTRAINT fk_test_key_application FOREIGN KEY(api_v2_application_id) REFERENCES api_v2_applications(id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_history_identity(singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY, source_instance_id CHAR(36) NOT NULL, history_epoch CHAR(36) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY, authorization_generation BIGINT UNSIGNED NOT NULL, CONSTRAINT fk_test_authorization_application FOREIGN KEY(application_pk) REFERENCES api_v2_applications(id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_state(resource_type ENUM('client','organization') NOT NULL, public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, revision BIGINT UNSIGNED NOT NULL, projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, present TINYINT(1) NOT NULL, PRIMARY KEY(resource_type,public_id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_changes(resource_type ENUM('client','organization') NOT NULL, public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, revision BIGINT UNSIGNED NOT NULL, action ENUM('upsert','delete') NOT NULL, PRIMARY KEY(resource_type,public_id,revision), CONSTRAINT fk_test_change_state FOREIGN KEY(resource_type,public_id) REFERENCES api_v2_directory_resource_state(resource_type,public_id)) ENGINE=InnoDB;
            CREATE TABLE organizations(id INT NOT NULL PRIMARY KEY, public_id CHAR(32) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, general_email VARCHAR(255) NULL, general_phone VARCHAR(50) NULL, notes TEXT NULL, address_line1 VARCHAR(255) NULL, address_line2 VARCHAR(255) NULL, city VARCHAR(100) NULL, state VARCHAR(100) NULL, postal_code VARCHAR(32) NULL, country VARCHAR(100) NULL, source_version VARCHAR(64) NULL) ENGINE=InnoDB;
            CREATE TABLE addresses(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(255) NULL, address_line1 VARCHAR(255) NULL, address_line2 VARCHAR(255) NULL, city VARCHAR(100) NULL, state VARCHAR(100) NULL, postal_code VARCHAR(32) NULL, country VARCHAR(100) NULL, google_place_id VARCHAR(255) NULL, source VARCHAR(32) NULL, created_by INT NULL, archived TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB;
            CREATE TABLE address_assignments(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, address_id INT NOT NULL, entity_type VARCHAR(32) NOT NULL, entity_id INT NOT NULL, purpose VARCHAR(32) NOT NULL, is_default TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_test_address_assignment(entity_type,entity_id,purpose,address_id)) ENGINE=InnoDB;
            CREATE TABLE app_config(organization_id INT NOT NULL, config_key VARCHAR(191) NOT NULL, config_value TEXT NULL) ENGINE=InnoDB;
            INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000','Disposable test application');
            INSERT INTO api_keys VALUES(7,3,NULL);
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0);
            INSERT INTO organizations VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example','old@example.test','old','private note','','','','','','','v-old');
            INSERT INTO addresses VALUES(4,'Private label','old','','','','','','place-hidden','google',NULL,0);
            INSERT INTO address_assignments(address_id,entity_type,entity_id,purpose,is_default) VALUES(4,'organization',9,'billing',1);
            INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled','0')");
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0093_api_v2_directory_organization_profile_command_receipts.sql');
        self::assertNotFalse($migration);
        $this->first->exec($migration);
        $this->first->beginTransaction();
        \api_v2_directory_record($this->first, 'organization', 9);
        $this->first->commit();
    }
}
