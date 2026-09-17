<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2ExistingApplicationBindingTest extends TestCase
{
    private const FIRST_APPLICATION = '323e4567-e89b-42d3-a456-426614174000';
    private const SECOND_APPLICATION = '423e4567-e89b-42d3-a456-426614174000';

    private function database(string $scopes = 'api.capabilities.read'): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite unavailable');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_application_provisioning.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,filename TEXT);
            INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql'),(102,'0102_api_v2_project_synchronization.sql');
            CREATE TABLE api_keys(id INTEGER PRIMARY KEY,scopes TEXT,revoked_at TEXT,api_v2_application_id INTEGER NULL);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT UNIQUE,name TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
            CREATE TABLE api_v2_project_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','223e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_applications VALUES(3,'" . self::FIRST_APPLICATION . "','First'),(4,'" . self::SECOND_APPLICATION . "','Second');
            INSERT INTO api_v2_directory_authorization_state VALUES(3,4),(4,8);
            INSERT INTO api_v2_project_authorization_state VALUES(3,5),(4,9);
            INSERT INTO api_keys VALUES(7,'" . str_replace("'", "''", $scopes) . "',NULL,NULL);");
        return $pdo;
    }

    public function testDryRunAndApplyBindAnEligibleUnboundKeyWithoutCreatingAnApplication(): void
    {
        $pdo = $this->database();
        $dryRun = \api_v2_application_bind_existing($pdo, 7, self::FIRST_APPLICATION, true);
        self::assertSame(['dryRun' => true, 'apiKeyId' => 7, 'applicationId' => self::FIRST_APPLICATION, 'alreadyBound' => false, 'rebound' => false], $dryRun);
        self::assertNull($pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_applications')->fetchColumn());

        $applied = \api_v2_application_bind_existing($pdo, 7, self::FIRST_APPLICATION, false);
        self::assertFalse($applied['dryRun']);
        self::assertSame(3, (int)$pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
        self::assertSame(5, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        self::assertSame(6, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn());
    }

    public function testSameApplicationIsAnIdempotentNoOpButStillRequiresBothAuthorizationStates(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE api_keys SET api_v2_application_id=3 WHERE id=7');
        $result = \api_v2_application_bind_existing($pdo, 7, self::FIRST_APPLICATION, false);
        self::assertTrue($result['alreadyBound']);
        self::assertSame(4, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        self::assertSame(5, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn());
        $pdo->exec('DELETE FROM api_v2_project_authorization_state WHERE application_pk=3');
        $this->expectException(\RuntimeException::class);
        \api_v2_application_bind_existing($pdo, 7, self::FIRST_APPLICATION, true);
    }

    public function testRebindRequiresSelectedCurrentApplicationAndApplyAcknowledgement(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE api_keys SET api_v2_application_id=3 WHERE id=7');
        foreach ([[null, false], [self::SECOND_APPLICATION, false], ['523e4567-e89b-42d3-a456-426614174000', true]] as [$from, $acknowledged]) {
            try {
                \api_v2_application_bind_existing($pdo, 7, self::SECOND_APPLICATION, false, $from, $acknowledged);
                self::fail('expected rebind refusal');
            } catch (\RuntimeException) {
                self::assertSame(3, (int)$pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
                self::assertSame(4, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
                self::assertSame(8, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=4')->fetchColumn());
            }
        }
    }

    public function testSelectedRebindDryRunIsReadOnlyAndApplyAdvancesDirectoryAndProjectGenerations(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE api_keys SET api_v2_application_id=3 WHERE id=7');
        $dryRun = \api_v2_application_bind_existing($pdo, 7, self::SECOND_APPLICATION, true, self::FIRST_APPLICATION);
        self::assertTrue($dryRun['rebound']);
        self::assertSame(3, (int)$pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
        $applied = \api_v2_application_bind_existing($pdo, 7, self::SECOND_APPLICATION, false, self::FIRST_APPLICATION, true);
        self::assertTrue($applied['rebound']);
        self::assertSame(4, (int)$pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
        self::assertSame(5, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        self::assertSame(9, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=4')->fetchColumn());
        self::assertSame(6, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn());
        self::assertSame(10, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=4')->fetchColumn());
    }

    public function testRejectsUnsafeKeysAndIncompleteProjectMigrationWithoutWriting(): void
    {
        foreach (['full', 'directory.clients.read'] as $scopes) {
            $pdo = $this->database($scopes);
            try { \api_v2_application_bind_existing($pdo, 7, self::FIRST_APPLICATION, true); self::fail('expected scope refusal'); }
            catch (\RuntimeException) { self::assertNull($pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn()); }
        }
        $revoked = $this->database(); $revoked->exec("UPDATE api_keys SET revoked_at='2026-01-01' WHERE id=7");
        try { \api_v2_application_bind_existing($revoked, 7, self::FIRST_APPLICATION, true); self::fail('expected revoked-key refusal'); }
        catch (\RuntimeException) { self::assertNull($revoked->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn()); }
        $missingProjectMigration = $this->database(); $missingProjectMigration->exec('DELETE FROM schema_migrations WHERE version=102');
        try { \api_v2_application_bind_existing($missingProjectMigration, 7, self::FIRST_APPLICATION, true); self::fail('expected migration refusal'); }
        catch (\RuntimeException) { self::assertNull($missingProjectMigration->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn()); }
    }

    public function testApplyRollsBackAuthorizationAdvancementWhenKeyUpdateFails(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE api_keys SET api_v2_application_id=3 WHERE id=7');
        $pdo->exec("CREATE TRIGGER reject_key_binding BEFORE UPDATE OF api_v2_application_id ON api_keys BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        try { \api_v2_application_bind_existing($pdo, 7, self::SECOND_APPLICATION, false, self::FIRST_APPLICATION, true); self::fail('expected failure'); }
        catch (\PDOException) {
            self::assertSame(3, (int)$pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
            self::assertSame(4, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
            self::assertSame(8, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=4')->fetchColumn());
            self::assertSame(5, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn());
            self::assertSame(9, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=4')->fetchColumn());
        }
    }

    public function testRebindRefusesExhaustedOrMissingAuthorizationStateBeforeChangingAnything(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE api_keys SET api_v2_application_id=3 WHERE id=7');
        $pdo->exec("UPDATE api_v2_project_authorization_state SET authorization_generation=9223372036854775807 WHERE application_pk=4");
        try { \api_v2_application_bind_existing($pdo, 7, self::SECOND_APPLICATION, false, self::FIRST_APPLICATION, true); self::fail('expected refusal'); }
        catch (\RuntimeException) {
            self::assertSame(3, (int)$pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
            self::assertSame(4, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
            self::assertSame(8, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=4')->fetchColumn());
        }
    }

    public function testFirstBindRefusesAnExhaustedTargetAuthorizationGenerationWithoutBindingTheKey(): void
    {
        $pdo = $this->database();
        $pdo->exec("UPDATE api_v2_directory_authorization_state SET authorization_generation=9223372036854775807 WHERE application_pk=3");
        try { \api_v2_application_bind_existing($pdo, 7, self::FIRST_APPLICATION, false); self::fail('expected refusal'); }
        catch (\RuntimeException) {
            self::assertNull($pdo->query('SELECT api_v2_application_id FROM api_keys WHERE id=7')->fetchColumn());
            self::assertSame(9223372036854775807, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
            self::assertSame(5, (int)$pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn());
        }
    }

    public function testCliKeepsSecretsOutOfArgumentsAndDatabaseErrorsOutOfOutput(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/bin/bind-api-v2-key-to-application.php');
        self::assertStringNotContainsString('--api-key=', $source);
        self::assertStringContainsString('--api-key-id=', $source);
        self::assertLessThan(strpos($source, 'catch (InvalidArgumentException|RuntimeException'), strpos($source, 'catch (PDOException)'));
        self::assertStringContainsString('internal database error', $source);
    }
}
