<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryReleaseSafetyTest extends TestCase
{
    private function database(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite unavailable');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_release_safety.php';
        $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,filename TEXT); INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql'),(96,'0096_api_v2_directory_backfill_attestations.sql');
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,general_email TEXT,general_phone TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_backfill_attestations(attestation_sha256 TEXT PRIMARY KEY,attestation_json TEXT NOT NULL,created_at TEXT);
            INSERT INTO clients(id,public_id,name) VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','One');
            INSERT INTO organizations(id,public_id,name) VALUES(2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Two');");
        return $pdo;
    }

    public function testAttestationRequiresCompleteCurrentProjectionAndHistory(): void
    {
        $pdo = $this->database();
        $initial = \api_v2_directory_backfill_attestation($pdo);
        self::assertFalse($initial['complete']); self::assertSame(2, $initial['violations']['missing_state']);
        \api_v2_directory_backfill($pdo, 'all', null, 10, false);
        $complete = \api_v2_directory_backfill_attestation($pdo);
        self::assertTrue($complete['complete']); self::assertSame(1, $complete['resources']['client']['covered']);
        $pdo->exec("UPDATE clients SET name='Changed' WHERE id=1");
        $drifted = \api_v2_directory_backfill_attestation($pdo);
        self::assertFalse($drifted['complete']); self::assertSame(1, $drifted['violations']['projection_drift']);
    }

    public function testOnlyACompleteAttestationCanBecomeAnImmutableCurrentReceipt(): void
    {
        $pdo = $this->database();
        try { \api_v2_directory_backfill_attestation_persist($pdo); self::fail('incomplete receipt accepted'); } catch (\RuntimeException) {}
        \api_v2_directory_backfill($pdo, 'all', null, 10, false);
        $digest = \api_v2_directory_backfill_attestation_persist($pdo);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        self::assertTrue(\api_v2_directory_backfill_attestation_receipt_is_current($pdo, $digest));
        self::assertSame($digest, \api_v2_directory_backfill_attestation_persist($pdo));
        $pdo->exec("UPDATE organizations SET name='Changed' WHERE id=2");
        self::assertFalse(\api_v2_directory_backfill_attestation_receipt_is_current($pdo, $digest));
    }

    public function testReceiptDigestChangesForAProperlyRecordedSameCountMutation(): void
    {
        $pdo = $this->database(); \api_v2_directory_backfill($pdo, 'all', null, 10, false);
        $first = \api_v2_directory_backfill_attestation_persist($pdo);
        $pdo->beginTransaction();
        $pdo->exec("UPDATE clients SET name='Revised' WHERE id=1");
        \api_v2_directory_record($pdo, 'client', 1); $pdo->commit();
        $second = \api_v2_directory_backfill_attestation_persist($pdo);
        self::assertNotSame($first, $second); self::assertFalse(\api_v2_directory_backfill_attestation_receipt_is_current($pdo, $first));
        self::assertTrue(\api_v2_directory_backfill_attestation_receipt_is_current($pdo, $second));
    }

    public function testReceiptMigrationIsRequiredOnlyAtThroughVersionNinetySix(): void
    {
        $pdo = $this->database();
        self::assertTrue(\api_v2_directory_backfill_attestation_receipt_schema_ready($pdo, 96));
        $pdo->exec('DROP TABLE api_v2_directory_backfill_attestations; CREATE TABLE api_v2_directory_backfill_attestations(attestation_sha256 TEXT PRIMARY KEY,attestation_json TEXT NOT NULL)');
        self::assertFalse(\api_v2_directory_backfill_attestation_receipt_schema_ready($pdo, 96), 'Migration 96 requires every receipt column.');
        $pdo->exec('DELETE FROM schema_migrations WHERE version=96; DROP TABLE api_v2_directory_backfill_attestations');
        self::assertTrue(\api_v2_directory_backfill_schema_ready($pdo), 'The existing backfill remains usable through migration 95.');
        self::assertTrue(\api_v2_directory_backfill_attestation_receipt_schema_ready($pdo, 95));
        self::assertFalse(\api_v2_directory_backfill_attestation_receipt_schema_ready($pdo, 96));
        self::assertSame(2, \api_v2_directory_backfill($pdo, 'all', null, 10, true)['scanned']);
    }

    public function testAttestationRejectsOrphanedActiveStateAndHistoryBeyondState(): void
    {
        $pdo = $this->database(); \api_v2_directory_backfill($pdo, 'all', null, 10, false);
        $pdo->exec("INSERT INTO api_v2_directory_resource_state VALUES('client','cccccccccccccccccccccccccccccccc',1,'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',1)");
        $pdo->exec("INSERT INTO api_v2_directory_resource_changes VALUES('client','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',2,'delete')");
        $proof = \api_v2_directory_backfill_attestation($pdo);
        self::assertFalse($proof['complete']); self::assertSame(1, $proof['violations']['orphaned_state']); self::assertSame(1, $proof['violations']['history_gap']);
    }

    public function testAttestationRejectsMissingIntermediateRevision(): void
    {
        $pdo = $this->database(); \api_v2_directory_backfill($pdo, 'all', null, 10, false);
        $hash = \api_v2_directory_projection_hash('client', ['name' => 'One']);
        $pdo->prepare("UPDATE api_v2_directory_resource_state SET revision=3,projection_sha256=? WHERE resource_type='client'")->execute([$hash]);
        $pdo->exec("INSERT INTO api_v2_directory_resource_changes VALUES('client','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',3,'upsert')");
        $proof = \api_v2_directory_backfill_attestation($pdo);
        self::assertFalse($proof['complete']); self::assertSame(1, $proof['violations']['history_gap']);
    }

    public function testWriterInventoryCoversEveryRuntimeSqlWriterAndRequiresGovernance(): void
    {
        $root = dirname(__DIR__, 2); $inventory = \api_v2_directory_writer_inventory(); $listed = [];
        foreach ($inventory as $entry) {
            self::assertFileExists($root . '/' . $entry['path']); self::assertArrayNotHasKey($entry['path'], $listed);
            $listed[$entry['path']] = $entry; $source = (string)file_get_contents($root . '/' . $entry['path']);
            self::assertStringContainsString($entry['evidence'], $source, $entry['path']);
            self::assertSame($entry['mutationCount'], preg_match_all('/(?:INSERT\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+[`]?(?:clients|organizations)[`]?/i', $source), $entry['path']);
            if ($entry['governance'] === 'revision') self::assertMatchesRegularExpression('/api_v2_directory_record(?:_delete)?\\s*\\(/', $source, $entry['path']);
        }
        $writers = [];
        foreach (['controllers', 'services'] as $area) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/' . $area, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                $source = (string)file_get_contents($file->getPathname());
                if (preg_match('/(?:INSERT\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+[`]?(?:clients|organizations)[`]?/i', $source) === 1) {
                    $writers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        sort($writers); $declared = array_keys($listed); sort($declared);
        self::assertSame($writers, $declared, 'Every runtime SQL writer must be classified before release.');
        $serviceReferences = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $source = (string)file_get_contents($file->getPathname());
            if (str_contains($source, '->consumeAndRestore(')) $serviceReferences[] = $file->getPathname();
        }
        self::assertCount(1, $serviceReferences, 'Every restoration-service caller must be reviewed.');
        $restore = (string)file_get_contents($root . '/src/controllers/client/clients_restore.php');
        self::assertStringContainsString('ClientArchivePortalStateService', $restore);
        self::assertMatchesRegularExpression('/consumeAndRestore[\\s\\S]*api_v2_directory_record\\s*\\(/', $restore);
    }
}
