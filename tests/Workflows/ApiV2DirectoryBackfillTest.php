<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryBackfillTest extends TestCase
{
    private function database(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite unavailable');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_backfill.php';
        $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,filename TEXT); INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql');
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,general_email TEXT,general_phone TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            INSERT INTO clients(id,public_id,name) VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','One'),(2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Two');
            INSERT INTO organizations(id,public_id,name) VALUES(3,'cccccccccccccccccccccccccccccccc','Three');");
        return $pdo;
    }

    public function testDryRunIsBoundedAndApplyIsIdempotent(): void
    {
        $pdo = $this->database();
        $dry = \api_v2_directory_backfill($pdo, 'client', null, 1, true);
        self::assertSame(1, $dry['scanned']); self::assertSame(1, $dry['inserted']); self::assertSame('client:1', $dry['nextCursor']);
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_state')->fetchColumn());
        $applied = \api_v2_directory_backfill($pdo, 'client', null, 2, false);
        self::assertSame(2, $applied['inserted']); self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
        $again = \api_v2_directory_backfill($pdo, 'client', null, 2, false);
        self::assertSame(0, $again['inserted']); self::assertSame(2, $again['skippedCurrent']);
    }

    public function testConflictingOrTombstonedLiveStateFailsWithoutOverwrite(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO api_v2_directory_resource_state VALUES('client','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',9,'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',1);
            INSERT INTO api_v2_directory_resource_state VALUES('client','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',8,'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',0);");
        try { \api_v2_directory_backfill($pdo, 'client', null, 2, false); self::fail('expected refusal'); } catch (\RuntimeException) {}
        self::assertSame([9, 1], array_map('intval', $pdo->query("SELECT revision,present FROM api_v2_directory_resource_state WHERE public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetch(PDO::FETCH_NUM)));
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
    }

    public function testTombstonedLiveStateFailsWithoutOverwrite(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO api_v2_directory_resource_state VALUES('client','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',8,'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',0)");
        try { \api_v2_directory_backfill($pdo, 'client', 'client:1', 1, false); self::fail('expected refusal'); } catch (\RuntimeException) {}
        self::assertSame([8, 0], array_map('intval', $pdo->query("SELECT revision,present FROM api_v2_directory_resource_state WHERE public_id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'")->fetch(PDO::FETCH_NUM)));
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
    }

    public function testCurrentStateWithoutItsUpsertChangeFailsClosed(): void
    {
        $pdo = $this->database(); $hash = \api_v2_directory_projection_hash('client', ['name' => 'One']);
        $pdo->prepare("INSERT INTO api_v2_directory_resource_state VALUES('client',?,1,?,1)")->execute(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $hash]);
        $this->expectException(\RuntimeException::class); \api_v2_directory_backfill($pdo, 'client', null, 1, false);
    }

    public function testFutureChangeBeyondCurrentRevisionFailsClosed(): void
    {
        $pdo = $this->database();
        $hash = \api_v2_directory_projection_hash('client', ['name' => 'One']);
        $pdo->prepare("INSERT INTO api_v2_directory_resource_state VALUES('client',?,1,?,1)")
            ->execute(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $hash]);
        $pdo->exec("INSERT INTO api_v2_directory_resource_changes VALUES('client','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'upsert');
            INSERT INTO api_v2_directory_resource_changes VALUES('client','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',2,'delete');");
        try { \api_v2_directory_backfill($pdo, 'client', null, 1, false); self::fail('expected refusal'); }
        catch (\RuntimeException) {
            self::assertSame(1, (int)$pdo->query("SELECT revision FROM api_v2_directory_resource_state WHERE public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetchColumn());
            self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
        }
    }

    public function testAllModeBoundaryUsesZeroCursorAndDoesNotSkipFirstOrganization(): void
    {
        $pdo = $this->database(); $first = \api_v2_directory_backfill($pdo, 'all', null, 2, true);
        self::assertSame('organization:0', $first['nextCursor']);
        $second = \api_v2_directory_backfill($pdo, 'all', $first['nextCursor'], 1, true);
        self::assertSame(1, $second['scanned']); self::assertSame(1, $second['inserted']);
    }

    public function testMalformedIdentityAndMigrationStateFailClosed(): void
    {
        $pdo = $this->database(); $pdo->exec("UPDATE clients SET public_id='bad' WHERE id=2");
        try { \api_v2_directory_backfill($pdo, 'client', null, 2, false); self::fail('expected refusal'); }
        catch (\RuntimeException) { self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_state')->fetchColumn()); }
        $missing = $this->database(); $missing->exec('DELETE FROM schema_migrations WHERE version=89');
        $this->expectException(\RuntimeException::class); \api_v2_directory_backfill($missing, 'all', null, 1, true);
    }

    public function testCliDefaultsToDryRunAndRequiresMaintenanceConfirmation(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/bin/backfill-api-v2-directory.php');
        self::assertStringContainsString('$apply = false', $source);
        self::assertStringContainsString('$attest = false', $source);
        self::assertStringContainsString("\$argument === '--attest'", $source);
        self::assertStringContainsString('api_v2_directory_backfill_attestation_persist', $source);
        self::assertStringContainsString("'Attestation: ' . \$digest", $source);
        self::assertStringContainsString("require_once __DIR__ . '/../src/utils/api_v2_directory_release_safety.php'", $source);
        self::assertStringContainsString('Directory attestation refused due to an internal database error.', $source);
        self::assertStringContainsString('Directory attestation refused due to an internal error.', $source);
        self::assertStringContainsString('--confirm-api-v2-directory-backfill', $source);
        self::assertStringContainsString('--maintenance-window-confirmed', $source);
        self::assertStringContainsString('$apply && ($dryRunRequested || !$confirmed || !$maintenanceConfirmed)', $source);
        self::assertStringContainsString('[--dry-run] [--attest]', $source);
        self::assertStringNotContainsString('attestation_json', $source);
        self::assertStringNotContainsString("json_encode(\$attestation", $source);
        self::assertStringNotContainsString('curl_', $source);
    }
}
