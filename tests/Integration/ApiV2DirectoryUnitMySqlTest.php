<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Disposable MySQL 8.4 acceptance for the generic API-v2 unit contract. */
final class ApiV2DirectoryUnitMySqlTest extends TestCase
{
    private const SOURCE = '123e4567-e89b-42d3-a456-426614174000';
    private const APPLICATION = '223e4567-e89b-42d3-a456-426614174000';
    private const FIRST_EPOCH = '323e4567-e89b-42d3-a456-426614174000';
    private const SECOND_EPOCH = '923e4567-e89b-42d3-a456-426614174000';

    private PDO $first;
    private PDO $second;

    protected function setUp(): void
    {
        $dsn = getenv('API_V2_DIRECTORY_UNIT_MYSQL_DSN');
        $user = getenv('API_V2_DIRECTORY_UNIT_MYSQL_USER');
        $password = getenv('API_V2_DIRECTORY_UNIT_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) {
            self::markTestSkipped('Run tools/run-api-v2-directory-unit-mysql-integration.ps1 for isolated MySQL tests.');
        }
        $database = trim((string)(getenv('API_V2_DIRECTORY_UNIT_MYSQL_DATABASE') ?: ''));
        if (getenv('API_V2_DIRECTORY_UNIT_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only'
            || preg_match('/^api_v2_unit_test_[a-f0-9]{32}$/D', $database) !== 1) {
            throw new RuntimeException('Directory unit MySQL tests require the disposable runner sentinel.');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        if (!hash_equals($database, (string)$this->first->query('SELECT DATABASE()')->fetchColumn())) {
            throw new RuntimeException('Refusing to reset a non-disposable database.');
        }
        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->second->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; SET SESSION innodb_lock_wait_timeout=1');

        require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_create_command.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_command.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_revision_refresh.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_revoke_command.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_unit_profile_command.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_unit_contact_command.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_lifecycle_command.php';
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $pdo) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testBaselineUpgradeSchemaCommandsEpochsAndApplicationSerialization(): void
    {
        $this->resetDatabase();
        $this->installBaselineAndMigrationsThrough(103);

        // Legacy duplicates must be refused before the first non-transactional
        // 0104 ALTER. Repairing the data makes the same upgrade retry safe.
        $defaultOrganization = (int)$this->first->query("SELECT id FROM organizations WHERE name='Default Organization'")->fetchColumn();
        $insertClient = $this->first->prepare('INSERT INTO clients(name,organization_id) VALUES(?,?)');
        $insertClient->execute(['Legacy Primary One', $defaultOrganization]);
        $legacyOne = (int)$this->first->lastInsertId();
        $insertClient->execute(['Legacy Primary Two', $defaultOrganization]);
        $legacyTwo = (int)$this->first->lastInsertId();
        $insertDepartment = $this->first->prepare('INSERT INTO organization_departments(organization_id,name) VALUES(?,?)');
        $insertDepartment->execute([$defaultOrganization, 'Legacy Duplicate Primary']);
        $legacyDepartment = (int)$this->first->lastInsertId();
        $contacts = $this->first->prepare('INSERT INTO organization_department_contacts(department_id,client_id,role,is_primary) VALUES(?,?,?,1)');
        $contacts->execute([$legacyDepartment, $legacyOne, 'contact']);
        $contacts->execute([$legacyDepartment, $legacyTwo, 'contact']);
        try {
            \migration_preflight($this->first, 104);
            self::fail('Migration 0104 preflight accepted duplicate primary contacts.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('multiple primary contacts', $error->getMessage());
        }
        self::assertSame(0, $this->columnCount('organization_departments', 'archived'));
        self::assertSame(0, (int)$this->first->query('SELECT COUNT(*) FROM schema_migrations WHERE version>=104')->fetchColumn());

        $this->first->prepare('UPDATE organization_department_contacts SET is_primary=0 WHERE department_id=? AND client_id=?')
            ->execute([$legacyDepartment, $legacyTwo]);
        \migration_preflight($this->first, 104);
        $this->applyMigrations(104, 106);
        $this->assertUnitSchemaContract($legacyDepartment, $legacyTwo);
        $this->seedApplication();

        $headers = $this->headers(self::FIRST_EPOCH);
        $organization = \api_v2_directory_create_command_write(
            $this->first,
            'organization',
            $this->organizationCreate('423e4567-e89b-42d3-a456-426614174000', 'org/mysql', $this->generation()),
            700,
            $headers,
            'organization-create'
        );
        self::assertSame(201, $organization['status']);
        $organizationPublicId = $organization['payload']['result']['resource']['publicId'];

        $client = \api_v2_directory_create_command_write(
            $this->first,
            'client',
            $this->clientCreate('523e4567-e89b-42d3-a456-426614174000', 'client/mysql', $this->generation()),
            700,
            $headers,
            'client-create'
        );
        self::assertSame(201, $client['status']);
        $clientPublicId = $client['payload']['result']['resource']['publicId'];

        $unitCreateCommand = $this->unitCreate('623e4567-e89b-42d3-a456-426614174000', 'unit/mysql', $this->generation(), 'Operations');
        $unit = \api_v2_directory_create_command_write($this->first, 'unit', $unitCreateCommand, 700, $headers, 'unit-create');
        self::assertSame(201, $unit['status']);
        $unitPublicId = $unit['payload']['result']['resource']['publicId'];
        self::assertSame(200, \api_v2_directory_create_command_write($this->second, 'unit', $unitCreateCommand, 700, $headers, 'unit-replay')['status']);

        $profile = [
            'commandId' => '723e4567-e89b-42d3-a456-426614174000',
            'expectedRevision' => $this->revision('unit', $unitPublicId),
            'expectedAuthorizationGeneration' => $this->generation(),
            'profile' => ['name' => 'Operations and Delivery'],
        ];
        $profileResult = \api_v2_directory_unit_profile_command_write($this->first, $unitPublicId, $profile, 700, $headers, 'unit-profile');
        self::assertSame(200, $profileResult['status']);
        self::assertFalse($profileResult['payload']['replayed']);
        $profileReplay = \api_v2_directory_unit_profile_command_write($this->second, $unitPublicId, $profile, 700, $headers, 'unit-profile-replay');
        self::assertTrue($profileReplay['payload']['replayed']);
        $refreshCommand = $this->refreshCommand('823e4567-e89b-42d3-a456-426614174000', 'unit/mysql');
        self::assertSame(200, \api_v2_directory_binding_revision_refresh_write($this->first, 'unit', $refreshCommand, 700, $headers, 'refresh-after-profile')['status']);

        $contact = fn(string $commandId): array => [
            'commandId' => $commandId,
            'expectedUnitRevision' => $this->revision('unit', $unitPublicId),
            'expectedAuthorizationGeneration' => $this->generation(),
            'client' => [
                'externalId' => 'client/mysql',
                'expectedPublicId' => $clientPublicId,
                'expectedRevision' => $this->revision('client', $clientPublicId),
            ],
        ];
        $assign = $contact('a23e4567-e89b-42d3-a456-426614174000');
        $assign['role'] = 'dispatcher';
        self::assertSame(200, \api_v2_directory_unit_contact_command_write($this->first, $unitPublicId, 'assign', $assign, 700, $headers, 'contact-assign')['status']);
        $this->refreshUnitBinding($unitPublicId, 'unit/mysql', 'b23e4567-e89b-42d3-a456-426614174000', $headers);
        self::assertSame(200, \api_v2_directory_unit_contact_command_write($this->first, $unitPublicId, 'set-primary', $contact('c23e4567-e89b-42d3-a456-426614174000'), 700, $headers, 'contact-primary')['status']);
        self::assertSame(1, (int)$this->first->query('SELECT is_primary FROM organization_department_contacts WHERE client_id=(SELECT id FROM clients WHERE public_id=' . $this->first->quote($clientPublicId) . ') AND department_id=(SELECT id FROM organization_departments WHERE public_id=' . $this->first->quote($unitPublicId) . ')')->fetchColumn());
        $this->refreshUnitBinding($unitPublicId, 'unit/mysql', 'd23e4567-e89b-42d3-a456-426614174000', $headers);
        self::assertSame(200, \api_v2_directory_unit_contact_command_write($this->first, $unitPublicId, 'remove', $contact('e23e4567-e89b-42d3-a456-426614174000'), 700, $headers, 'contact-remove')['status']);
        $this->refreshUnitBinding($unitPublicId, 'unit/mysql', 'f23e4567-e89b-42d3-a456-426614174000', $headers);

        $archive = $this->lifecycle('133e4567-e89b-42d3-a456-426614174000', $unitPublicId);
        self::assertSame(200, \api_v2_directory_lifecycle_command_write($this->first, 'unit', $unitPublicId, 'archive', $archive, 700, $headers, 'unit-archive')['status']);
        self::assertSame('tombstoned', $this->bindingStatus('unit', 'unit/mysql'));
        $restore = $this->lifecycle('233e4567-e89b-42d3-a456-426614174000', $unitPublicId);
        self::assertSame(200, \api_v2_directory_lifecycle_command_write($this->first, 'unit', $unitPublicId, 'restore', $restore, 700, $headers, 'unit-restore')['status']);
        self::assertSame('tombstoned', $this->bindingStatus('unit', 'unit/mysql'));

        // Generic bind receipts are epoch-scoped. After revocation, the same
        // command ID is allowed to rebind in the replacement epoch and both
        // immutable receipts coexist. Create/refresh commands whose live
        // preconditions are already consumed fail instead of replaying.
        $legacyUnitPublicId = $this->insertUnboundUnit($organizationPublicId, 'Epoch Rebind Unit');
        $bindingCommand = [
            'commandId' => '333e4567-e89b-42d3-a456-426614174000',
            'externalId' => 'unit/epoch-rebind',
            'expectedPublicId' => $legacyUnitPublicId,
            'expectedRevision' => '1',
        ];
        $bound = \api_v2_directory_binding_command_write($this->first, 'unit', $bindingCommand, 700, $headers, 'bind-first-epoch');
        self::assertSame(200, $bound['status']);
        self::assertFalse($bound['payload']['replayed']);
        $revoke = [
            'commandId' => '433e4567-e89b-42d3-a456-426614174000',
            'externalId' => 'unit/epoch-rebind',
            'expectedPublicId' => $legacyUnitPublicId,
            'expectedRevision' => '1',
            'expectedAuthorizationGeneration' => $this->generation(),
        ];
        self::assertSame(200, \api_v2_directory_binding_revoke_command_write($this->first, 'unit', $revoke, 700, $headers, 'unbind-first-epoch')['status']);

        $this->first->prepare('UPDATE api_v2_history_identity SET history_epoch=? WHERE singleton=1')->execute([self::SECOND_EPOCH]);
        $secondEpochHeaders = $this->headers(self::SECOND_EPOCH);
        $rebound = \api_v2_directory_binding_command_write($this->first, 'unit', $bindingCommand, 700, $secondEpochHeaders, 'bind-second-epoch');
        self::assertSame(200, $rebound['status']);
        self::assertFalse($rebound['payload']['replayed']);
        self::assertSame(2, $this->receiptEpochCount('api_v2_directory_binding_command_receipts', $bindingCommand['commandId']));
        self::assertSame(409, \api_v2_directory_create_command_write($this->first, 'unit', $unitCreateCommand, 700, $secondEpochHeaders, 'create-second-epoch')['status']);
        self::assertSame(1, $this->receiptEpochCount('api_v2_directory_create_command_receipts', $unitCreateCommand['commandId']));
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_write($this->first, 'unit', $refreshCommand, 700, $secondEpochHeaders, 'refresh-second-epoch')['status']);
        self::assertSame(1, $this->receiptEpochCount('api_v2_directory_binding_revision_refresh_receipts', $refreshCommand['commandId']));

        $this->assertApplicationRowSerializesCreateAndBind($organizationPublicId, $secondEpochHeaders);
    }

    private function assertUnitSchemaContract(int $legacyDepartment, int $legacyNonPrimaryClient): void
    {
        self::assertSame(1, $this->columnCount('organization_departments', 'archived'));
        self::assertSame(1, $this->columnCount('organization_departments', 'deleted_at'));
        self::assertSame(1, (int)$this->first->query('SELECT COUNT(*) FROM schema_migrations WHERE version=104')->fetchColumn());
        self::assertSame(1, (int)$this->first->query('SELECT COUNT(*) FROM schema_migrations WHERE version=105')->fetchColumn());
        self::assertSame(1, (int)$this->first->query('SELECT COUNT(*) FROM schema_migrations WHERE version=106')->fetchColumn());
        foreach ([
            'api_v2_directory_resource_state',
            'api_v2_directory_resource_changes',
            'api_v2_directory_external_bindings',
            'api_v2_directory_binding_command_receipts',
            'api_v2_directory_binding_revision_refresh_receipts',
            'api_v2_directory_create_command_receipts',
            'api_v2_directory_lifecycle_command_receipts',
            'api_v2_directory_binding_revoke_command_receipts',
        ] as $table) {
            $columnType = $this->first->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='{$table}' AND column_name='resource_type'")->fetchColumn();
            self::assertIsString($columnType, $table);
            self::assertStringContainsString("'unit'", $columnType, $table);
        }
        foreach ([
            'api_v2_directory_binding_command_receipts',
            'api_v2_directory_binding_revision_refresh_receipts',
            'api_v2_directory_create_command_receipts',
        ] as $table) {
            self::assertSame(1, $this->columnCount($table, 'history_epoch'), $table);
            $primaryColumns = $this->first->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{$table}' AND index_name='PRIMARY'")->fetchColumn();
            self::assertStringContainsString('history_epoch', (string)$primaryColumns, $table);
        }
        $generated = $this->first->query("SELECT extra,generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='organization_department_contacts' AND column_name='primary_department_id'")->fetch(PDO::FETCH_NUM);
        self::assertIsArray($generated);
        self::assertStringContainsString('VIRTUAL GENERATED', strtoupper((string)$generated[0]));
        self::assertStringContainsString('is_primary', (string)$generated[1]);
        self::assertSame(2, (int)$this->first->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND constraint_name IN ('fk_api_v2_directory_change_state','fk_api_v2_directory_external_binding_resource')")->fetchColumn());

        try {
            $this->first->prepare('UPDATE organization_department_contacts SET is_primary=1 WHERE department_id=? AND client_id=?')
                ->execute([$legacyDepartment, $legacyNonPrimaryClient]);
            self::fail('Generated primary uniqueness allowed two primaries for one unit.');
        } catch (PDOException $error) {
            self::assertSame(1062, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        }
        try {
            $this->first->exec("INSERT INTO api_v2_directory_resource_changes(resource_type,public_id,revision,action) VALUES('unit','ffffffffffffffffffffffffffffffff',1,'upsert')");
            self::fail('Restored change-state foreign key accepted an orphan.');
        } catch (PDOException $error) {
            self::assertSame(1452, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        }
    }

    private function assertApplicationRowSerializesCreateAndBind(string $organizationPublicId, array $headers): void
    {
        // Every generic directory writer first locks the joined application
        // identity row. Holding that exact row makes create/bind wait before
        // either reaches its later auth/source/state/binding locks, preventing
        // the previously suspected inverse-order deadlock.
        $create = $this->unitCreate('533e4567-e89b-42d3-a456-426614174000', 'unit/serialized-create', $this->generation(), 'Serialized Create');
        $this->lockApplicationRow();
        try {
            \api_v2_directory_create_command_write($this->second, 'unit', $create, 700, $headers, 'blocked-create');
            self::fail('Unit create crossed the application serialization lock.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int)($error->errorInfo[1] ?? 0), 'Expected lock timeout, never deadlock 1213: ' . $error->getMessage());
        } finally {
            $this->first->commit();
        }
        self::assertSame(0, (int)$this->second->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE resource_type='unit' AND external_id='unit/serialized-create'")->fetchColumn());
        self::assertSame(201, \api_v2_directory_create_command_write($this->second, 'unit', $create, 700, $headers, 'serialized-create')['status']);

        $unboundPublicId = $this->insertUnboundUnit($organizationPublicId, 'Serialized Bind');
        $bind = [
            'commandId' => '633e4567-e89b-42d3-a456-426614174000',
            'externalId' => 'unit/serialized-bind',
            'expectedPublicId' => $unboundPublicId,
            'expectedRevision' => '1',
        ];
        $this->lockApplicationRow();
        try {
            \api_v2_directory_binding_command_write($this->second, 'unit', $bind, 700, $headers, 'blocked-bind');
            self::fail('Unit bind crossed the application serialization lock.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int)($error->errorInfo[1] ?? 0), 'Expected lock timeout, never deadlock 1213: ' . $error->getMessage());
        } finally {
            $this->first->commit();
        }
        self::assertSame(200, \api_v2_directory_binding_command_write($this->second, 'unit', $bind, 700, $headers, 'serialized-bind')['status']);
    }

    private function lockApplicationRow(): void
    {
        $this->first->beginTransaction();
        $statement = $this->first->prepare('SELECT app.id FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id WHERE api_key.id=? FOR UPDATE');
        $statement->execute([700]);
        self::assertSame('700', (string)$statement->fetchColumn());
    }

    private function seedApplication(): void
    {
        $this->first->prepare('UPDATE api_v2_history_identity SET source_instance_id=?,history_epoch=? WHERE singleton=1')
            ->execute([self::SOURCE, self::FIRST_EPOCH]);
        $this->first->prepare('INSERT INTO api_v2_applications(id,application_id,name) VALUES(?,?,?)')
            ->execute([700, self::APPLICATION, 'Unit MySQL acceptance']);
        $this->first->prepare('INSERT INTO api_keys(id,organization_id,name,key_prefix,key_hash,scopes,api_v2_application_id) VALUES(700,NULL,?,?,?, ?,700)')
            ->execute(['Unit MySQL acceptance', 'unit_accept', str_repeat('7', 64), '']);
        $this->first->exec('INSERT INTO api_v2_directory_authorization_state(application_pk,authorization_generation) VALUES(700,0)');
    }

    private function organizationCreate(string $commandId, string $externalId, string $generation): array
    {
        return [
            'commandId' => $commandId,
            'externalId' => $externalId,
            'expectedAuthorizationGeneration' => $generation,
            'profile' => [
                'name' => 'MySQL API Organization', 'generalEmail' => 'mysql-org@example.test', 'generalPhone' => '555-0100',
                'addressLine1' => '1 Integration Way', 'addressLine2' => '', 'city' => 'Madison', 'state' => 'WI',
                'postalCode' => '53703', 'country' => 'US',
            ],
        ];
    }

    private function clientCreate(string $commandId, string $externalId, string $generation): array
    {
        return [
            'commandId' => $commandId,
            'externalId' => $externalId,
            'expectedAuthorizationGeneration' => $generation,
            'profile' => [
                'name' => 'MySQL API Client', 'email' => 'mysql-client@example.test', 'phone' => '555-0101', 'clientType' => 'business',
                'addressLine1' => '2 Integration Way', 'addressLine2' => '', 'city' => 'Madison', 'state' => 'WI',
                'postalCode' => '53703', 'country' => 'US',
            ],
            'organization' => ['externalId' => 'org/mysql', 'expectedRevision' => '1'],
        ];
    }

    private function unitCreate(string $commandId, string $externalId, string $generation, string $name): array
    {
        return [
            'commandId' => $commandId,
            'externalId' => $externalId,
            'expectedAuthorizationGeneration' => $generation,
            'profile' => ['name' => $name],
            'organization' => ['externalId' => 'org/mysql', 'expectedRevision' => '1'],
        ];
    }

    private function refreshCommand(string $commandId, string $externalId): array
    {
        $binding = $this->first->prepare("SELECT CAST(resource_revision AS CHAR) FROM api_v2_directory_external_bindings WHERE application_pk=700 AND resource_type='unit' AND external_id=?");
        $binding->execute([$externalId]);
        $publicId = (string)$this->first->query("SELECT public_id FROM api_v2_directory_external_bindings WHERE application_pk=700 AND resource_type='unit' AND external_id=" . $this->first->quote($externalId))->fetchColumn();
        return [
            'commandId' => $commandId,
            'externalId' => $externalId,
            'expectedPriorRevision' => (string)$binding->fetchColumn(),
            'expectedLiveRevision' => $this->revision('unit', $publicId),
            'expectedAuthorizationGeneration' => $this->generation(),
        ];
    }

    private function refreshUnitBinding(string $publicId, string $externalId, string $commandId, array $headers): void
    {
        $command = $this->refreshCommand($commandId, $externalId);
        self::assertSame($publicId, (string)$this->first->query("SELECT public_id FROM api_v2_directory_external_bindings WHERE application_pk=700 AND resource_type='unit' AND external_id=" . $this->first->quote($externalId))->fetchColumn());
        self::assertSame(200, \api_v2_directory_binding_revision_refresh_write($this->first, 'unit', $command, 700, $headers, 'unit-refresh')['status']);
    }

    private function lifecycle(string $commandId, string $publicId): array
    {
        return [
            'commandId' => $commandId,
            'expectedRevision' => $this->revision('unit', $publicId),
            'expectedAuthorizationGeneration' => $this->generation(),
        ];
    }

    private function insertUnboundUnit(string $organizationPublicId, string $name): string
    {
        $organization = $this->first->prepare('SELECT id FROM organizations WHERE public_id=?');
        $organization->execute([$organizationPublicId]);
        $organizationId = (int)$organization->fetchColumn();
        self::assertGreaterThan(0, $organizationId);
        $publicId = bin2hex(random_bytes(16));
        $insert = $this->first->prepare("INSERT INTO organization_departments(public_id,organization_id,name,source_version,archived,deleted_at) VALUES(?,?,?,'mysql-acceptance',0,NULL)");
        $insert->execute([$publicId, $organizationId, $name]);
        $localId = (int)$this->first->lastInsertId();
        $this->first->beginTransaction();
        self::assertTrue(\api_v2_directory_record($this->first, 'unit', $localId, false));
        $this->first->commit();
        return $publicId;
    }

    private function headers(string $epoch): array
    {
        return ['source' => self::SOURCE, 'application' => self::APPLICATION, 'epoch' => $epoch];
    }

    private function generation(): string
    {
        return (string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=700')->fetchColumn();
    }

    private function revision(string $type, string $publicId): string
    {
        $statement = $this->first->prepare('SELECT CAST(revision AS CHAR) FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?');
        $statement->execute([$type, $publicId]);
        return (string)$statement->fetchColumn();
    }

    private function bindingStatus(string $type, string $externalId): string
    {
        $statement = $this->first->prepare('SELECT status FROM api_v2_directory_external_bindings WHERE application_pk=700 AND resource_type=? AND external_id=?');
        $statement->execute([$type, $externalId]);
        return (string)$statement->fetchColumn();
    }

    private function receiptEpochCount(string $table, string $commandId): int
    {
        $allowed = [
            'api_v2_directory_binding_command_receipts',
            'api_v2_directory_binding_revision_refresh_receipts',
            'api_v2_directory_create_command_receipts',
        ];
        if (!in_array($table, $allowed, true)) throw new RuntimeException('Unexpected receipt table.');
        $statement = $this->first->prepare("SELECT COUNT(DISTINCT history_epoch) FROM {$table} WHERE application_pk=700 AND resource_type='unit' AND command_id=?");
        $statement->execute([$commandId]);
        return (int)$statement->fetchColumn();
    }

    private function columnCount(string $table, string $column): int
    {
        $statement = $this->first->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $statement->execute([$table, $column]);
        return (int)$statement->fetchColumn();
    }

    private function resetDatabase(): void
    {
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0');
        $views = $this->first->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='VIEW'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($views as $view) $this->first->exec('DROP VIEW IF EXISTS `' . str_replace('`', '``', (string)$view) . '`');
        $tables = $this->first->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) $this->first->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string)$table) . '`');
        $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function installBaselineAndMigrationsThrough(int $throughVersion): void
    {
        $root = dirname(__DIR__, 2);
        $baseline = file_get_contents($root . '/database/baseline.sql');
        self::assertNotFalse($baseline);
        foreach (\migration_statements((string)$baseline) as $statement) $this->executeMigrationStatement($statement, 'baseline.sql');
        $this->applyMigrations(1, $throughVersion);
    }

    private function applyMigrations(int $fromVersion, int $throughVersion): void
    {
        $files = \migration_files(dirname(__DIR__, 2) . '/database/migrations');
        foreach ($files as $file) {
            $version = (int)$file['version'];
            if ($version < $fromVersion || $version > $throughVersion) continue;
            $sql = file_get_contents($file['path']);
            self::assertNotFalse($sql, $file['filename']);
            foreach (\migration_statements((string)$sql) as $statement) $this->executeMigrationStatement($statement, $file['filename']);
            $record = $this->first->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(?,?,?)');
            $record->execute([$version, $file['filename'], $file['checksum']]);
        }
    }

    private function executeMigrationStatement(string $statement, string $filename): void
    {
        try {
            $result = $this->first->query($statement);
            if ($result !== false) $result->closeCursor();
        } catch (PDOException $error) {
            throw new RuntimeException($filename . ' failed at: ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 240), 0, $error);
        }
    }
}
