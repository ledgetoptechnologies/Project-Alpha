<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2AuthorizationGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_authorization_generation.php';
    }

    private function database(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite unavailable');
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL)');
        return $pdo;
    }

    public function testOnlyScopeOrIpChangesRequireAuthorizationAdvance(): void
    {
        self::assertFalse(\api_v2_key_authorization_changed('clients.read,api.capabilities.read', ' 203.0.113.7 ', 'api.capabilities.read,clients.read', '203.0.113.7'));
        self::assertTrue(\api_v2_key_authorization_changed('clients.read', null, 'api.capabilities.read', null));
        self::assertTrue(\api_v2_key_authorization_changed('api.capabilities.read', null, 'api.capabilities.read', '203.0.113.7'));
    }

    public function testAdvanceIncrementsExistingStateWithoutLostUpdates(): void
    {
        $pdo = $this->database(); $pdo->prepare('INSERT INTO api_v2_directory_authorization_state VALUES(?,?)')->execute([9, 0]);
        $pdo->beginTransaction(); \api_v2_advance_authorization_generation($pdo, 9); $pdo->commit();
        self::assertSame(1, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=9')->fetchColumn());
        $pdo->beginTransaction(); \api_v2_advance_authorization_generation($pdo, 9); $pdo->commit();
        self::assertSame(2, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=9')->fetchColumn());
    }

    public function testMissingStateFailsClosedAndDoesNotCreateAReplacementWatermark(): void
    {
        $pdo = $this->database(); $pdo->beginTransaction();
        try { \api_v2_advance_authorization_generation($pdo, 9); self::fail('expected missing-state refusal'); }
        catch (\RuntimeException) { $pdo->rollBack(); }
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_authorization_state')->fetchColumn());
    }

    public function testOverflowRefusesAndLeavesStateUnchanged(): void
    {
        $pdo = $this->database();
        $pdo->prepare('INSERT INTO api_v2_directory_authorization_state VALUES(?,?)')->execute([9, PA_API_V2_AUTHORIZATION_GENERATION_MAX]);
        $pdo->beginTransaction();
        try { \api_v2_advance_authorization_generation($pdo, 9); self::fail('expected overflow refusal'); }
        catch (\RuntimeException) { $pdo->rollBack(); }
        self::assertSame(PA_API_V2_AUTHORIZATION_GENERATION_MAX, (string)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=9')->fetchColumn());
    }

    public function testControllersPreserveV2DefaultOffAndFirstRevocationOnly(): void
    {
        $root = dirname(__DIR__, 2);
        $update = (string)file_get_contents($root . '/src/controllers/api_keys_update.php');
        $revoke = (string)file_get_contents($root . '/src/controllers/api_keys_revoke.php');
        self::assertStringContainsString('api_v2_application_id', $update);
        self::assertStringContainsString('if ($authorizationChanged && $hasV2Binding', $update);
        self::assertStringContainsString('WHERE id=? AND revoked_at IS NULL', $revoke);
        self::assertStringContainsString("\$current['revoked_at'] === null", $revoke);
    }
}
