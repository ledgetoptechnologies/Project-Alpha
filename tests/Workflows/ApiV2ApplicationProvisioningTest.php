<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2ApplicationProvisioningTest extends TestCase
{
    private function database(string $scopes = 'api.capabilities.read'): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite unavailable');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_application_provisioning.php';
        $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,filename TEXT); INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql');
            CREATE TABLE api_keys(id INTEGER PRIMARY KEY,scopes TEXT,revoked_at TEXT,api_v2_application_id INTEGER NULL);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY AUTOINCREMENT,application_id TEXT UNIQUE,name TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
            CREATE TABLE api_v2_project_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','223e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_keys(id,scopes,revoked_at,api_v2_application_id) VALUES(7,'" . str_replace("'", "''", $scopes) . "',NULL,NULL);");
        return $pdo;
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $pdo = $this->database(); $result = \api_v2_application_provision($pdo, 7, 'Generic application', true);
        self::assertSame(['dryRun' => true, 'apiKeyId' => 7], $result);
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_applications')->fetchColumn());
    }

    public function testApplyBindsKeyAndInitializesGenerationAtomically(): void
    {
        $pdo = $this->database(); $result = \api_v2_application_provision($pdo, 7, 'Generic application', false);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $result['applicationId']);
        self::assertSame(0, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state')->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
        $again = \api_v2_application_provision($pdo, 7, 'Generic application', false);
        self::assertTrue($again['alreadyProvisioned']); self::assertSame($result['applicationId'], $again['applicationId']);
        $this->expectException(\RuntimeException::class); \api_v2_application_provision($pdo, 7, 'Different name', false);
    }

    public function testRefusesLegacyFullAndIncompleteMigrationState(): void
    {
        $legacy = $this->database('full');
        try { \api_v2_application_provision($legacy, 7, 'Generic application', true); self::fail('expected refusal'); }
        catch (\RuntimeException $error) { self::assertSame(0, (int)$legacy->query('SELECT COUNT(*) FROM api_v2_applications')->fetchColumn()); }
        $missing = $this->database(); $missing->exec('DELETE FROM schema_migrations WHERE version=89');
        $this->expectException(\RuntimeException::class); \api_v2_application_provision($missing, 7, 'Generic application', true);
    }

    public function testApplyRequiresUsableV2IdentityAndNonblankName(): void
    {
        $pdo = $this->database(); $pdo->exec("UPDATE api_v2_history_identity SET history_epoch='invalid'");
        try { \api_v2_application_provision($pdo, 7, 'Generic application', true); self::fail('expected refusal'); }
        catch (\RuntimeException) { self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_applications')->fetchColumn()); }
        $this->expectException(\InvalidArgumentException::class); \api_v2_application_provision($this->database(), 7, '   ', true);
    }

    public function testAuthorizationInsertFailureRollsBackApplicationAndBinding(): void
    {
        $pdo = $this->database(); $pdo->exec("CREATE TRIGGER fail_auth BEFORE INSERT ON api_v2_directory_authorization_state BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        try { \api_v2_application_provision($pdo, 7, 'Generic application', false); self::fail('expected failure'); }
        catch (\PDOException) {}
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_applications')->fetchColumn());
        self::assertNull($pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
    }

    public function testCliDoesNotExposePdoExceptionMessages(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/bin/provision-api-v2-application.php');
        self::assertLessThan(strpos($source, 'catch (InvalidArgumentException|RuntimeException'), strpos($source, 'catch (PDOException)'));
        self::assertStringContainsString('internal database error', $source);
    }
}
