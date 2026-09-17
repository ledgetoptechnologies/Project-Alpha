<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\OpsSnapshotV2Service;
use App\Services\SyncContractV2Service;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

final class SyncContractV2MySqlTest extends TestCase
{
    private PDO $reader;
    private PDO $writer;
    private OpsSnapshotV2Service $snapshots;
    private SyncContractV2Service $contract;

    protected function setUp(): void
    {
        $dsn = getenv('SYNC_V2_MYSQL_DSN');
        $user = getenv('SYNC_V2_MYSQL_USER');
        $password = getenv('SYNC_V2_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) {
            self::markTestSkipped(
                'Run tools/run-sync-v2-mysql-integration.ps1 for the isolated MySQL integration test.'
            );
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->reader = new PDO($dsn, $user, $password, $options);
        $this->writer = new PDO($dsn, $user, $password, $options);
        $this->snapshots = new OpsSnapshotV2Service();
        $this->contract = new SyncContractV2Service();
        $this->resetSyntheticSchema();
    }

    public function testRealMySqlBootstrapBindingRollbackAndConcurrentConvergence(): void
    {
        $this->applyMigration0057();
        $this->assertMigrationState();
        $this->seedAuthoritativeResources();

        $now = new DateTimeImmutable('2026-07-30T12:00:00Z', new DateTimeZone('UTC'));
        $first = $this->snapshots->snapshot($this->reader, 7, 1, null, null, $now);

        self::assertSame('2.0', $first['contract_version']);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $first['source_instance_id']);
        self::assertSame('0', $first['snapshot']['high_water_sequence']);
        self::assertCount(1, $first['items']);
        self::assertSame('organization', $first['items'][0]['resource']['type']);
        self::assertSame('1', $first['items'][0]['resource']['id']);
        self::assertSame('1', $first['items'][0]['resource']['version']);
        self::assertNotNull($first['next_cursor']);

        try {
            $this->snapshots->snapshot(
                $this->reader,
                8,
                1,
                $first['snapshot']['id'],
                $first['next_cursor'],
                $now
            );
            self::fail('A cursor must not be reusable by another API-key principal.');
        } catch (DomainException $error) {
            self::assertSame(
                'The snapshot session belongs to a different API principal.',
                $error->getMessage()
            );
        }

        $this->writer->beginTransaction();
        $this->writer->exec(
            "UPDATE organizations
             SET name = 'Organization Two Concurrent', updated_at = '2026-07-30 12:01:00.000000'
             WHERE id = 2"
        );
        $organizationTwo = $this->snapshots->projectResource($this->writer, 'organization', 2);
        self::assertNotNull($organizationTwo);
        $organizationTwoEvent = $this->contract->recordEvent(
            $this->writer,
            'organization',
            '2',
            'upsert',
            $organizationTwo,
            new DateTimeImmutable('2026-07-30T12:01:00Z')
        );
        $this->writer->commit();
        self::assertSame('1', $organizationTwoEvent['resource']['version']);
        self::assertSame('1', $organizationTwoEvent['sequence']);

        $this->writer->beginTransaction();
        $this->writer->exec(
            "UPDATE organizations
             SET name = 'Organization One Concurrent', updated_at = '2026-07-30 12:02:00.000000'
             WHERE id = 1"
        );
        $organizationOne = $this->snapshots->projectResource($this->writer, 'organization', 1);
        self::assertNotNull($organizationOne);
        $organizationOneEvent = $this->contract->recordEvent(
            $this->writer,
            'organization',
            '1',
            'upsert',
            $organizationOne,
            new DateTimeImmutable('2026-07-30T12:02:00Z')
        );
        $this->writer->commit();
        self::assertSame('2', $organizationOneEvent['resource']['version']);
        self::assertSame('2', $organizationOneEvent['sequence']);

        $consumer = [];
        $this->applySnapshotItems($consumer, $first['items']);
        $cursor = $first['next_cursor'];
        do {
            $page = $this->snapshots->snapshot(
                $this->reader,
                7,
                1,
                $first['snapshot']['id'],
                $cursor,
                $now
            );
            self::assertSame($first['snapshot'], $page['snapshot']);
            $this->applySnapshotItems($consumer, $page['items']);
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        self::assertCount(6, $consumer);
        self::assertSame(
            'Organization Two Concurrent',
            $consumer['organization:2']['data']['name']
        );
        self::assertSame('1', $consumer['organization:2']['version']);
        self::assertSame(
            'Organization One Before',
            $consumer['organization:1']['data']['name']
        );
        self::assertSame('1', $consumer['organization:1']['version']);

        $events = $this->reader->query(
            'SELECT sequence, resource_type, resource_id, resource_version, action, payload
             FROM sync_event_log
             WHERE sequence > 0
             ORDER BY sequence'
        )->fetchAll();
        self::assertCount(2, $events);
        foreach ($events as $event) {
            $this->applyEvent($consumer, $event);
        }
        self::assertSame(
            'Organization One Concurrent',
            $consumer['organization:1']['data']['name']
        );
        self::assertSame('2', $consumer['organization:1']['version']);
        self::assertSame('1', $consumer['organization:2']['version']);
        $this->assertConsumerConverged($consumer);

        $projectBefore = $this->snapshots->projectResource($this->reader, 'project', 20);
        $projectVersionBefore = $this->resourceVersion('project', '20');
        $eventCountBefore = $this->eventCount();
        $this->writer->beginTransaction();
        $this->writer->exec(
            "UPDATE projects
             SET name = 'Rolled Back Project', updated_at = '2026-07-30 12:03:00.000000'
             WHERE id = 20"
        );
        $rolledBackProjection = $this->snapshots->projectResource($this->writer, 'project', 20);
        self::assertNotNull($rolledBackProjection);
        self::assertNotNull($this->contract->recordEvent(
            $this->writer,
            'project',
            '20',
            'upsert',
            $rolledBackProjection,
            new DateTimeImmutable('2026-07-30T12:03:00Z')
        ));
        $this->writer->rollBack();

        self::assertSame(
            $this->contract->canonicalJson($projectBefore),
            $this->contract->canonicalJson(
                $this->snapshots->projectResource($this->reader, 'project', 20)
            )
        );
        self::assertSame($projectVersionBefore, $this->resourceVersion('project', '20'));
        self::assertSame($eventCountBefore, $this->eventCount());

        $sessionCountBefore = (int)$this->reader
            ->query('SELECT COUNT(*) FROM sync_snapshot_sessions')
            ->fetchColumn();
        $this->writer->beginTransaction();
        $this->writer->exec(
            "UPDATE jobs
             SET status = 'completed',
                 completed_at = '2026-07-30 12:04:00.000000',
                 updated_at = '2026-07-30 12:04:00.000000'
             WHERE id = 40"
        );
        $this->writer->commit();

        try {
            $this->snapshots->snapshot($this->reader, 7, 100, null, null, $now);
            self::fail('An uninstrumented authoritative mutation must fail closed.');
        } catch (UnexpectedValueException $error) {
            self::assertStringContainsString(
                'the authoritative mutation was not recorded through Sync Contract v2',
                $error->getMessage()
            );
            self::assertStringContainsString('job:40', $error->getMessage());
        }
        self::assertSame($eventCountBefore, $this->eventCount());
        self::assertSame(
            $sessionCountBefore,
            (int)$this->reader->query('SELECT COUNT(*) FROM sync_snapshot_sessions')->fetchColumn()
        );
    }

    private function applyMigration0057(): void
    {
        $path = dirname(__DIR__, 2)
            . '/database/migrations/0064_sync_contract_v2_foundation.sql';
        $sql = file_get_contents($path);
        self::assertNotFalse($sql);
        $statements = migration_statements($sql);
        self::assertCount(6, $statements);
        foreach ($statements as $statement) {
            $this->reader->exec($statement);
        }
    }

    private function assertMigrationState(): void
    {
        $tables = $this->reader->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name LIKE 'sync\\_%'
             ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'sync_event_log',
            'sync_resource_state',
            'sync_snapshot_sessions',
            'sync_source_identity',
        ], $tables);
        self::assertSame(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            $this->reader->query(
                'SELECT source_instance_id FROM sync_source_identity WHERE singleton = 1'
            )->fetchColumn()
        );
    }

    private function resetSyntheticSchema(): void
    {
        $this->reader->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'sync_snapshot_sessions',
            'sync_event_log',
            'sync_resource_state',
            'sync_source_identity',
            'jobs',
            'service_locations',
            'projects',
            'clients',
            'organizations',
            'pa_integration_identity',
            'api_keys',
        ] as $table) {
            $this->reader->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->reader->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->reader->exec(
            'CREATE TABLE api_keys (
                id INT NOT NULL PRIMARY KEY
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->reader->exec(
            'CREATE TABLE pa_integration_identity (
                singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                business_id CHAR(36) NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->reader->exec(
            "INSERT INTO api_keys (id) VALUES (7), (8);
             INSERT INTO pa_integration_identity (singleton, business_id)
             VALUES (1, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')"
        );

        $this->reader->exec(
            'CREATE TABLE organizations (
                id INT NOT NULL PRIMARY KEY,
                public_id CHAR(32) NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                address_line1 VARCHAR(191) NULL,
                address_line2 VARCHAR(191) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(100) NULL,
                postal_code VARCHAR(32) NULL,
                country VARCHAR(100) NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->reader->exec(
            'CREATE TABLE clients (
                id INT NOT NULL PRIMARY KEY,
                public_id CHAR(32) NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                email VARCHAR(191) NULL,
                phone VARCHAR(64) NULL,
                address_line1 VARCHAR(191) NULL,
                address_line2 VARCHAR(191) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(100) NULL,
                postal_code VARCHAR(32) NULL,
                country VARCHAR(100) NULL,
                organization_id INT NULL,
                client_type VARCHAR(64) NULL,
                archived TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->reader->exec(
            'CREATE TABLE projects (
                id INT NOT NULL PRIMARY KEY,
                public_id CHAR(32) NULL UNIQUE,
                client_id INT NULL,
                parent_id INT NULL,
                organization_id INT NULL,
                business_unit_id INT NULL,
                manager_user_id INT NULL,
                name VARCHAR(191) NOT NULL,
                description TEXT NULL,
                status VARCHAR(64) NOT NULL,
                start_date DATE NULL,
                end_date DATE NULL,
                estimated_start DATE NULL,
                estimated_end DATE NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->reader->exec(
            'CREATE TABLE service_locations (
                id INT NOT NULL PRIMARY KEY,
                organization_id INT NULL,
                client_id INT NULL,
                project_id INT NULL,
                name VARCHAR(191) NOT NULL,
                address_line1 VARCHAR(191) NULL,
                address_line2 VARCHAR(191) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(100) NULL,
                postal_code VARCHAR(32) NULL,
                country VARCHAR(100) NULL,
                archived TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->reader->exec(
            'CREATE TABLE jobs (
                id INT NOT NULL PRIMARY KEY,
                client_id INT NULL,
                organization_id INT NULL,
                project_id INT NULL,
                job_code VARCHAR(100) NOT NULL,
                job_origin VARCHAR(64) NULL,
                status VARCHAR(64) NOT NULL,
                completed_at DATETIME(6) NULL,
                default_service_location_id INT NULL,
                archived TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function seedAuthoritativeResources(): void
    {
        $timestamp = '2026-07-30 12:00:00.000000';
        $organization = $this->reader->prepare(
            'INSERT INTO organizations
                (id, public_id, name, address_line1, city, state, postal_code, country, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $organization->execute([
            1, str_repeat('a', 32), 'Organization One Before', '1 First Street', 'Example', 'IL', '60001',
            'US', $timestamp, $timestamp,
        ]);
        $organization->execute([
            2, str_repeat('d', 32), 'Organization Two Before', '2 Second Street', 'Example', 'IL', '60002',
            'US', $timestamp, $timestamp,
        ]);
        $this->reader->prepare(
            'INSERT INTO clients
                (id, public_id, name, email, phone, organization_id, client_type, archived, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            10, str_repeat('b', 32), 'Synthetic Client', 'client@example.invalid', '555-0100', 1, 'business',
            0, $timestamp, $timestamp,
        ]);
        $this->reader->prepare(
            'INSERT INTO projects
                (id, public_id, client_id, organization_id, name, description, status, start_date,
                 estimated_start, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            20, str_repeat('c', 32), 10, 1, 'Synthetic Project', 'Disposable integration fixture', 'active',
            '2026-07-30', '2026-07-30', $timestamp, $timestamp,
        ]);
        $this->reader->prepare(
            'INSERT INTO service_locations
                (id, organization_id, client_id, project_id, name, address_line1, city,
                 state, postal_code, country, archived, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            30, 1, 10, 20, 'Synthetic Site', '30 Test Way', 'Example', 'IL', '60003',
            'US', 0, $timestamp, $timestamp,
        ]);
        $this->reader->prepare(
            'INSERT INTO jobs
                (id, client_id, organization_id, project_id, job_code, job_origin, status,
                 default_service_location_id, archived, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            40, 10, 1, 20, 'SYN-40', 'manual', 'scheduled', 30, 0, $timestamp, $timestamp,
        ]);
    }

    /**
     * @param array<string,array{version:string,data:array<string,mixed>|null}> $consumer
     * @param list<array<string,mixed>> $items
     */
    private function applySnapshotItems(array &$consumer, array $items): void
    {
        foreach ($items as $item) {
            $key = $item['resource']['type'] . ':' . $item['resource']['id'];
            $version = (string)$item['resource']['version'];
            if (!isset($consumer[$key]) || (int)$version > (int)$consumer[$key]['version']) {
                $consumer[$key] = ['version' => $version, 'data' => $item['data']];
            }
        }
    }

    /**
     * @param array<string,array{version:string,data:array<string,mixed>|null}> $consumer
     * @param array<string,mixed> $event
     */
    private function applyEvent(array &$consumer, array $event): void
    {
        $key = $event['resource_type'] . ':' . $event['resource_id'];
        $version = (string)$event['resource_version'];
        if (isset($consumer[$key]) && (int)$version <= (int)$consumer[$key]['version']) {
            return;
        }
        $consumer[$key] = [
            'version' => $version,
            'data' => $event['action'] === 'delete'
                ? null
                : json_decode((string)$event['payload'], true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string,array{version:string,data:array<string,mixed>|null}> $consumer
     */
    private function assertConsumerConverged(array $consumer): void
    {
        foreach ([
            ['organization', 1],
            ['organization', 2],
            ['client', 10],
            ['project', 20],
            ['service_location', 30],
            ['job', 40],
        ] as [$type, $id]) {
            $key = $type . ':' . $id;
            self::assertArrayHasKey($key, $consumer);
            self::assertSame($this->resourceVersion($type, (string)$id), $consumer[$key]['version']);
            self::assertSame(
                $this->contract->canonicalJson($this->snapshots->projectResource($this->reader, $type, $id)),
                $this->contract->canonicalJson($consumer[$key]['data']),
                "Consumer projection did not converge for $key."
            );
        }
    }

    private function resourceVersion(string $type, string $id): string
    {
        $statement = $this->reader->prepare(
            'SELECT resource_version
             FROM sync_resource_state
             WHERE resource_type = ? AND resource_id = ?'
        );
        $statement->execute([$type, $id]);
        return (string)$statement->fetchColumn();
    }

    private function eventCount(): int
    {
        return (int)$this->reader->query('SELECT COUNT(*) FROM sync_event_log')->fetchColumn();
    }
}
