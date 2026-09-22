<?php

declare(strict_types=1);

namespace Tests\Workflows;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DirectoryCutoverGateTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_management.php';
    }

    public function testLocalGateFailsClosedAndApiAuthorityCanShareTheSameGate(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE app_config(organization_id INTEGER, config_key TEXT, config_value TEXT, PRIMARY KEY(organization_id,config_key))');
        $pdo->prepare('INSERT INTO app_config VALUES(0,?,?)')->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY, '1']);

        $pdo->beginTransaction();
        try {
            try {
                api_v2_directory_management_acquire_shared_gate($pdo);
                self::fail('A local writer must be denied while ownership is active.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }

        $pdo->beginTransaction();
        api_v2_directory_management_acquire_shared_gate($pdo, false);
        $pdo->commit();
    }

    public function testActivePolicyAndPostMigrationPolicyFaultsFailClosedForLocalWriters(): void
    {
        $pdo = $this->policyGateDatabase();
        $pdo->exec("INSERT INTO api_v2_directory_management_policy VALUES(1, '1', '1')");
        $pdo->beginTransaction();
        try {
            try {
                api_v2_directory_management_acquire_shared_gate($pdo);
                self::fail('A stale local sentinel must not override active policy ownership.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }

        // API authority shares the sentinel but deliberately bypasses local
        // ownership denial.
        $pdo->beginTransaction();
        api_v2_directory_management_acquire_shared_gate($pdo, false);
        $pdo->commit();

        $pdo->exec('DROP TABLE api_v2_directory_management_policy');
        $pdo->beginTransaction();
        try {
            $this->expectException(\RuntimeException::class);
            api_v2_directory_management_acquire_shared_gate($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testPostMigrationCorruptPolicyFailsClosedForLocalWriters(): void
    {
        $pdo = $this->policyGateDatabase();
        $pdo->exec("INSERT INTO api_v2_directory_management_policy VALUES(1, '1', 'unexpected')");
        $pdo->beginTransaction();
        try {
            $this->expectException(\RuntimeException::class);
            api_v2_directory_management_acquire_shared_gate($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testGateUsesMysqlSharedAndExclusiveLocksAndActivationRevalidatesUnderExclusiveGate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/utils/api_v2_directory_management.php');
        self::assertStringContainsString("' FOR SHARE'", $source);
        self::assertStringContainsString("' FOR UPDATE'", $source);
        self::assertStringContainsString('api_v2_directory_management_lock_policy_after_sentinel', $source);
        self::assertStringContainsString('api_v2_directory_management_activation_attestation_ready($pdo, $policy)', $source);
        self::assertLessThan(
            strpos($source, "ownership_active=1,last_effective=1"),
            strpos($source, 'api_v2_directory_management_activation_attestation_ready($pdo, $policy)'),
            'Activation must revalidate the mutable attestation while exclusive ownership is held.'
        );
    }

    public function testAllCanonicalCommandKindsAcquireAnExplicitSharedGate(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'src/utils/api_v2_directory_create_command.php',
            'src/utils/api_v2_directory_client_profile_command.php',
            'src/utils/api_v2_directory_organization_profile_command.php',
            'src/utils/api_v2_directory_lifecycle_command.php',
            'src/utils/api_v2_directory_relationship_command.php',
        ] as $path) {
            self::assertMatchesRegularExpression('/api_v2_directory_management_acquire_shared_gate\(\$pdo,\s*false\)/', (string) file_get_contents($root . '/' . $path), $path);
        }
        self::assertStringContainsString('api_v2_directory_management_acquire_shared_gate($pdo, false);', (string) file_get_contents($root . '/src/utils/api_v2_directory_backfill.php'));
    }

    private function policyGateDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE app_config(organization_id INTEGER, config_key TEXT, config_value TEXT, PRIMARY KEY(organization_id,config_key));
            CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY, filename TEXT);
            CREATE TABLE api_v2_directory_management_policy(singleton INTEGER PRIMARY KEY, configured_enabled TEXT, ownership_active TEXT);');
        $pdo->prepare('INSERT INTO app_config VALUES(0,?,?)')->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY, '0']);
        $pdo->exec("INSERT INTO schema_migrations VALUES(103, '0103_external_directory_management_sentinel.sql')");
        return $pdo;
    }
}
