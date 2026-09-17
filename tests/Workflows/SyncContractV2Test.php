<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\OpsSnapshotV2Service;
use App\Services\SyncContractV2Service;
use DateTimeImmutable;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class SyncContractV2Test extends TestCase
{
    private string $root;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seedData();
    }

    public function testSnapshotPagesAStableProviderNeutralConsumerContract(): void
    {
        $service = new OpsSnapshotV2Service();
        $now = new DateTimeImmutable('2026-07-30T18:00:00Z');
        $first = $service->snapshot($this->pdo, 7, 2, null, null, $now);

        self::assertSame('2.0', $first['contract_version']);
        self::assertSame('11111111-1111-4111-8111-111111111111', $first['source_instance_id']);
        self::assertSame('0', $first['snapshot']['high_water_sequence']);
        self::assertSame('2026-07-30T18:00:00.000000Z', $first['snapshot']['generated_at']);
        self::assertSame('2026-07-30T18:30:00.000000Z', $first['snapshot']['expires_at']);
        self::assertNotNull($first['next_cursor']);
        self::assertCount(2, $first['items']);

        $items = $first['items'];
        $cursor = $first['next_cursor'];
        while ($cursor !== null) {
            $page = $service->snapshot(
                $this->pdo,
                7,
                2,
                $first['snapshot']['id'],
                $cursor,
                $now
            );
            self::assertSame($first['snapshot'], $page['snapshot']);
            self::assertSame($first['source_instance_id'], $page['source_instance_id']);
            array_push($items, ...$page['items']);
            $cursor = $page['next_cursor'];
        }

        self::assertSame(
            ['organization', 'client', 'project', 'service_location', 'job'],
            array_column(array_column($items, 'resource'), 'type')
        );
        foreach ($items as $item) {
            self::assertIsString($item['resource']['id']);
            self::assertSame('1', $item['resource']['version']);
            self::assertArrayNotHasKey('id', $item['data']);
        }

        $client = $this->itemByType($items, 'client');
        self::assertSame('10', $client['resource']['id']);
        self::assertSame(str_repeat('b', 32), $client['data']['public_id']);
        self::assertSame('1', $client['data']['organization_id']);
        self::assertFalse($client['data']['archived']);

        $organization = $this->itemByType($items, 'organization');
        self::assertSame(str_repeat('a', 32), $organization['data']['public_id']);

        $project = $this->itemByType($items, 'project');
        self::assertSame(str_repeat('c', 32), $project['data']['public_id']);
        self::assertSame('10', $project['data']['client_id']);
        self::assertSame('1', $project['data']['organization_id']);

        $job = $this->itemByType($items, 'job');
        self::assertSame('40', $job['resource']['id']);
        self::assertSame('20', $job['data']['project_id']);
        self::assertSame('active', $job['data']['status']);

        $serialized = json_encode($items, JSON_THROW_ON_ERROR);
        foreach ([
            'stripe_customer_id',
            'stripe_payment_method_id',
            'budget',
            'invoice_net_terms_days',
            'public_project_token',
            'tax_exempt_file',
            'archive_payload',
            'custom_fields',
            'storage_path',
        ] as $forbiddenField) {
            self::assertStringNotContainsString($forbiddenField, $serialized);
        }
        self::assertSame(5, (int)$this->pdo->query('SELECT COUNT(*) FROM sync_resource_state')->fetchColumn());
    }

    public function testSourcePublicIdsFailClosedWithoutBeingSynthesizedOrRepaired(): void
    {
        foreach ([
            ['organization', 'organizations', 1, str_repeat('a', 32)],
            ['client', 'clients', 10, str_repeat('b', 32)],
            ['project', 'projects', 20, str_repeat('c', 32)],
        ] as [$type, $table, $id, $valid]) {
            foreach ([null, strtoupper($valid), 'not-a-source-public-id'] as $invalid) {
                $statement = $this->pdo->prepare("UPDATE {$table} SET public_id=? WHERE id=?");
                $statement->execute([$invalid, $id]);
                try {
                    (new OpsSnapshotV2Service())->projectResource($this->pdo, $type, $id);
                    self::fail("{$type} accepted a missing or malformed source public ID.");
                } catch (UnexpectedValueException $error) {
                    self::assertStringContainsString('public ID', $error->getMessage());
                }
                $stored = $this->pdo->query("SELECT public_id FROM {$table} WHERE id={$id}")->fetchColumn();
                self::assertSame($invalid, $stored);
                $statement->execute([$valid, $id]);
            }
        }
    }

    public function testSnapshotSessionIsBoundToApiKeyAndCursorIsBoundToSnapshot(): void
    {
        $service = new OpsSnapshotV2Service();
        $first = $service->snapshot(
            $this->pdo,
            7,
            1,
            null,
            null,
            new DateTimeImmutable('2026-07-30T18:00:00Z')
        );

        try {
            $service->snapshot(
                $this->pdo,
                8,
                1,
                $first['snapshot']['id'],
                $first['next_cursor'],
                new DateTimeImmutable('2026-07-30T18:00:00Z')
            );
            self::fail('A different API key must not resume the snapshot.');
        } catch (DomainException $error) {
            self::assertStringContainsString('different API principal', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->snapshot(
            $this->pdo,
            7,
            1,
            $first['snapshot']['id'],
            rtrim(strtr(base64_encode(json_encode([
                'v' => 2,
                'snapshot_id' => '22222222-2222-4222-8222-222222222222',
                'type_index' => 0,
                'after_id' => '1',
            ], JSON_THROW_ON_ERROR)), '+/', '-_'), '='),
            new DateTimeImmutable('2026-07-30T18:00:00Z')
        );
    }

    public function testSnapshotFailsClosedWhenMutationBypassesVersionState(): void
    {
        $service = new OpsSnapshotV2Service();
        $now = new DateTimeImmutable('2026-07-30T18:00:00Z');
        $first = $service->snapshot($this->pdo, 7, 1, null, null, $now);
        $this->pdo->exec("UPDATE organizations SET name='Changed outside sync transaction' WHERE id=1");

        $this->expectException(UnexpectedValueException::class);
        $service->snapshot($this->pdo, 7, 1, $first['snapshot']['id'], null, $now);
    }

    public function testEventAppendRequiresAndRollsBackWithAuthoritativeTransaction(): void
    {
        $contract = new SyncContractV2Service();
        $projection = (new OpsSnapshotV2Service())->projectResource($this->pdo, 'project', 20);
        self::assertNotNull($projection);

        try {
            $contract->recordEvent($this->pdo, 'project', '20', 'upsert', $projection);
            self::fail('Events outside a transaction must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('authoritative database transaction', $error->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->pdo->exec("UPDATE projects SET name='Rolled back project' WHERE id=20");
        $rolledBackProjection = (new OpsSnapshotV2Service())->projectResource($this->pdo, 'project', 20);
        $contract->recordEvent(
            $this->pdo,
            'project',
            '20',
            'upsert',
            $rolledBackProjection,
            new DateTimeImmutable('2026-07-30T18:10:00Z')
        );
        $this->pdo->rollBack();

        self::assertSame('Project One', $this->pdo->query('SELECT name FROM projects WHERE id=20')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM sync_resource_state')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM sync_event_log')->fetchColumn());

        $this->pdo->beginTransaction();
        $this->pdo->exec("UPDATE projects SET name='Committed project' WHERE id=20");
        $committedProjection = (new OpsSnapshotV2Service())->projectResource($this->pdo, 'project', 20);
        $event = $contract->recordEvent(
            $this->pdo,
            'project',
            '20',
            'upsert',
            $committedProjection,
            new DateTimeImmutable('2026-07-30T18:11:00Z')
        );
        $this->pdo->commit();

        self::assertNotNull($event);
        self::assertSame('2.0', $event['contract_version']);
        self::assertSame('1', $event['sequence']);
        self::assertSame(
            ['type' => 'project', 'id' => '20', 'version' => '1'],
            $event['resource']
        );
        self::assertSame('upsert', $event['action']);
        self::assertSame('Committed project', $event['data']['name']);

        $this->pdo->beginTransaction();
        self::assertNull($contract->recordEvent(
            $this->pdo,
            'project',
            '20',
            'upsert',
            $committedProjection
        ));
        $this->pdo->commit();

        $this->pdo->beginTransaction();
        $deleteEvent = $contract->recordEvent(
            $this->pdo,
            'project',
            '20',
            'delete',
            null,
            new DateTimeImmutable('2026-07-30T18:12:00Z')
        );
        $this->pdo->commit();

        self::assertSame('2', $deleteEvent['sequence']);
        self::assertSame('2', $deleteEvent['resource']['version']);
        self::assertSame('delete', $deleteEvent['action']);
        self::assertNull($deleteEvent['data']);
    }

    public function testMigrationRoutesScopeAndFixturesDescribeParallelV2Only(): void
    {
        require_once $this->root . '/src/utils/api_scopes.php';

        self::assertSame('ops.sync.read', api_scope_endpoint_map()['api-ops-snapshot']);
        self::assertSame('ops.sync.read', api_scope_endpoint_map()['api-ops-snapshot-v2']);

        $front = (string)file_get_contents($this->root . '/public/index.php');
        self::assertStringContainsString("'/api/v1/ops/snapshot' => 'api-ops-snapshot'", $front);
        self::assertStringContainsString("\$moduleRoutes['/api/v2/ops/snapshot'] = 'api-ops-snapshot-v2'", $front);
        self::assertStringContainsString('APP_SYNC_CONTRACT_V2_ENABLED', $front);
        self::assertStringContainsString('ops_snapshot_v2.php', $front);

        $controller = (string)file_get_contents($this->root . '/src/controllers/api/ops_snapshot_v2.php');
        self::assertStringContainsString('JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR', $controller);
        self::assertStringNotContainsString('api_json_response($response)', $controller);

        $htaccess = (string)file_get_contents($this->root . '/public/.htaccess');
        self::assertStringContainsString('RewriteRule ^api/v1/ops/snapshot/?$', $htaccess);
        self::assertStringContainsString('RewriteRule ^api/v2/ops/snapshot/?$', $htaccess);

        $migration = (string)file_get_contents(
            $this->root . '/database/migrations/0064_sync_contract_v2_foundation.sql'
        );
        foreach ([
            'sync_source_identity',
            'sync_resource_state',
            'sync_event_log',
            'sync_snapshot_sessions',
            'pa_integration_identity',
            'api_key_id',
            'high_water_sequence',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }
        self::assertStringNotContainsString('ltds', strtolower($migration));
        self::assertStringNotContainsString('drone', strtolower($migration));
        self::assertStringNotContainsString('cloudflare', strtolower($migration));
    }

    public function testSnapshotSessionsAreExpiredAndRateLimitedPerApiKey(): void
    {
        $source = (string)file_get_contents($this->root . '/src/services/SyncContractV2Service.php');
        self::assertStringContainsString('DELETE FROM sync_snapshot_sessions WHERE expires_at <= ?', $source);
        self::assertStringContainsString('MAX_ACTIVE_SNAPSHOTS_PER_KEY = 10', $source);
        self::assertStringContainsString('WHERE api_key_id = ? AND expires_at > ?', $source);
    }

    public function testConsumerFixturesAreValidAndContainNoProviderFields(): void
    {
        $schema = json_decode(
            (string)file_get_contents($this->root . '/docs/reference/sync-contract-v2.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertArrayHasKey('snapshot', $schema['$defs']);
        self::assertArrayHasKey('event', $schema['$defs']);
        self::assertSame('^[0-9a-f]{32}$', $schema['$defs']['sourcePublicId']['pattern']);

        $fixtureDirectory = $this->root . '/tests/fixtures/sync-contract-v2';
        foreach (['snapshot-page.json', 'event-upsert.json', 'event-delete.json'] as $filename) {
            $json = (string)file_get_contents($fixtureDirectory . '/' . $filename);
            $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('2.0', $fixture['contract_version']);
            self::assertMatchesRegularExpression(
                '/^[0-9a-f-]{36}$/',
                $fixture['source_instance_id']
            );
            self::assertStringNotContainsString('ltds', strtolower($json));
            self::assertStringNotContainsString('drone', strtolower($json));
            self::assertStringNotContainsString('cloudflare', strtolower($json));
            self::assertStringNotContainsString('stripe', strtolower($json));
            self::assertStringNotContainsString('storage_path', strtolower($json));
        }

        $snapshotFixture = json_decode(
            (string)file_get_contents($fixtureDirectory . '/snapshot-page.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(str_repeat('b', 32), $snapshotFixture['items'][0]['data']['public_id']);

        $upsert = json_decode(
            (string)file_get_contents($fixtureDirectory . '/event-upsert.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            ['type', 'id', 'version'],
            array_keys($upsert['resource'])
        );
        self::assertSame('upsert', $upsert['action']);

        $delete = json_decode(
            (string)file_get_contents($fixtureDirectory . '/event-delete.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('delete', $delete['action']);
        self::assertNull($delete['data']);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function itemByType(array $items, string $type): array
    {
        foreach ($items as $item) {
            if (($item['resource']['type'] ?? null) === $type) {
                return $item;
            }
        }
        self::fail("Missing fixture item type {$type}.");
    }

    private function createSchema(): void
    {
        $statements = [
            'CREATE TABLE sync_source_identity (
                singleton INTEGER PRIMARY KEY,
                source_instance_id TEXT NOT NULL UNIQUE,
                created_at TEXT
            )',
            'CREATE TABLE sync_resource_state (
                resource_type TEXT NOT NULL,
                resource_id TEXT NOT NULL,
                resource_version INTEGER NOT NULL,
                content_sha256 TEXT NOT NULL,
                present INTEGER NOT NULL,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (resource_type, resource_id)
            )',
            'CREATE TABLE sync_event_log (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id TEXT NOT NULL UNIQUE,
                source_instance_id TEXT NOT NULL,
                resource_type TEXT NOT NULL,
                resource_id TEXT NOT NULL,
                resource_version INTEGER NOT NULL,
                action TEXT NOT NULL,
                payload TEXT,
                occurred_at TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE sync_snapshot_sessions (
                snapshot_id TEXT PRIMARY KEY,
                source_instance_id TEXT NOT NULL,
                api_key_id INTEGER NOT NULL,
                high_water_sequence INTEGER NOT NULL,
                generated_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE organizations (
                id INTEGER PRIMARY KEY, public_id TEXT UNIQUE, name TEXT, address_line1 TEXT, address_line2 TEXT,
                city TEXT, state TEXT, postal_code TEXT, country TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE clients (
                id INTEGER PRIMARY KEY, public_id TEXT UNIQUE, name TEXT, email TEXT, phone TEXT, address_line1 TEXT,
                address_line2 TEXT, city TEXT, state TEXT, postal_code TEXT, country TEXT,
                organization_id INTEGER, client_type TEXT, archived INTEGER, deleted_at TEXT,
                created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE projects (
                id INTEGER PRIMARY KEY, public_id TEXT UNIQUE, client_id INTEGER, parent_id INTEGER, organization_id INTEGER,
                business_unit_id INTEGER, manager_user_id INTEGER, name TEXT, description TEXT, status TEXT,
                start_date TEXT, end_date TEXT, estimated_start TEXT, estimated_end TEXT,
                created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE service_locations (
                id INTEGER PRIMARY KEY, organization_id INTEGER, client_id INTEGER, project_id INTEGER,
                name TEXT, address_line1 TEXT, address_line2 TEXT, city TEXT, state TEXT,
                postal_code TEXT, country TEXT, archived INTEGER, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE jobs (
                id INTEGER PRIMARY KEY, client_id INTEGER, organization_id INTEGER, project_id INTEGER,
                job_code TEXT, job_origin TEXT, status TEXT, completed_at TEXT,
                default_service_location_id INTEGER, archived INTEGER, created_at TEXT, updated_at TEXT
            )',
        ];
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function seedData(): void
    {
        $this->pdo->exec(
            "INSERT INTO sync_source_identity VALUES
                (1,'11111111-1111-4111-8111-111111111111','2026-07-30 18:00:00.000000')"
        );
        $this->pdo->exec(
            "INSERT INTO organizations VALUES
                (1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Organization One','1 Main',NULL,'Eau Claire','WI','54701','US','2026-01-01','2026-07-01')"
        );
        $this->pdo->exec(
            "INSERT INTO clients VALUES
                (10,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Client One','client@example.invalid','555-0100','1 Main',NULL,'Eau Claire','WI',
                 '54701','US',1,'business',0,NULL,'2026-01-01','2026-07-01')"
        );
        $this->pdo->exec(
            "INSERT INTO projects VALUES
                (20,'cccccccccccccccccccccccccccccccc',10,NULL,1,30,50,'Project One','Provider-neutral field work','active',
                 '2026-07-01',NULL,NULL,NULL,'2026-01-01','2026-07-01')"
        );
        $this->pdo->exec(
            "INSERT INTO service_locations VALUES
                (30,1,10,20,'Service Site','100 Site Rd',NULL,'Eau Claire','WI','054701','US',0,
                 '2026-01-01','2026-07-01')"
        );
        $this->pdo->exec(
            "INSERT INTO jobs VALUES
                (40,10,1,20,'JOB-100','planned','active',NULL,30,0,'2026-01-01','2026-07-01')"
        );
    }
}
