<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ExternalOpsIntegrationService;
use App\Services\ExternalOpsOutboxSender;
use App\Services\ExternalOpsConfigService;
use App\Services\OperationsPlanningService;
use App\Services\ProjectWorkPlanningService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExternalOpsIntegrationTest extends TestCase
{
    private PDO $pdo;

    public function testSchemaAndSettingsRemainExplicitlyOptIn(): void
    {
        $root = dirname(__DIR__, 2);
        $baseline = (string)file_get_contents($root . '/database/baseline.sql');
        $migration = (string)file_get_contents($root . '/database/migrations/0049_external_ops_integration.sql');
        foreach (['application_entitlements', 'application_entitlement_business_units', 'integration_outbox', 'operations', 'operation_assignments', 'tasks'] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $baseline);
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $migration);
        }
        $planningMigration = (string)file_get_contents($root . '/database/migrations/0054_project_work_planning_and_generic_ops.sql');
        self::assertStringContainsString('@has_projects_business_unit', $planningMigration);
        self::assertStringContainsString('@has_entitlement_manual', $planningMigration);

        $config = (string)file_get_contents($root . '/src/services/ExternalOpsConfigService.php');
        $registry = (string)file_get_contents($root . '/src/views/pages/settings/registry.php');
        $handler = (string)file_get_contents($root . '/src/controllers/settings/external_ops_handler.php');
        $page = (string)file_get_contents($root . '/src/views/pages/settings/external-ops.php');
        $compose = (string)file_get_contents($root . '/docker-compose.yml');
        $envExample = (string)file_get_contents($root . '/config/.env.example');
        $cron = (string)file_get_contents($root . '/cron/crontab');
        self::assertStringContainsString("'external_ops_enabled'", $config);
        self::assertStringContainsString("'external_ops_credentials_enc'", $config);
        self::assertStringContainsString("'title' => 'Custom integrations'", $registry);
        self::assertStringContainsString("'permission' => 'settings.manage'", $registry);
        self::assertStringContainsString("\$action === 'save-config'", $handler);
        self::assertStringContainsString('The integration database schema does not match this Project Alpha release.', $handler);
        self::assertStringContainsString("error_code='.(string)\$error->getCode()", $handler);
        self::assertStringNotContainsString('rawurlencode($error->getMessage())', $handler);
        self::assertStringContainsString('csrf_validate()', $handler);
        self::assertStringContainsString('name="access_client_secret"', $page);
        self::assertStringContainsString('name="hmac_secret"', $page);
        self::assertStringContainsString('name="application_key"', $page);
        self::assertStringContainsString('LEFT JOIN worker_profiles wp ON wp.user_id=u.id', $page);
        self::assertStringContainsString('wp.display_name', $page);
        self::assertStringNotContainsString('u.display_name', $page);
        self::assertStringContainsString('Integration settings could not be loaded.', $page);
        self::assertStringNotContainsString('Fixed by', $page);
        self::assertStringNotContainsString('name="role_key"', $page);
        self::assertStringContainsString("'role-admin'", $migration);
        self::assertStringContainsString("'role-admin'", $baseline);
        self::assertStringContainsString('grantAccountAccess(', $handler);
        self::assertStringContainsString('revokeAccountAccess(', $handler);
        self::assertStringContainsString("'resend-access'", $handler);
        self::assertStringContainsString('resendAccountAccess(', $handler);
        self::assertStringContainsString("user_can(\$pdo, \$actorUserId, 'users.manage'", $handler);
        self::assertStringContainsString('Resend <?=$h($grantAccessLabel)?> access', $page);
        self::assertStringContainsString('data-external-ops-detail-link', $page);
        self::assertStringContainsString('data-external-ops-detail-region', $page);
        self::assertStringContainsString('type="search" list="external-ops-eligible-users"', $page);
        self::assertStringNotContainsString('<select class="input" name="user_id"', $page);
        $accountCreate = (string)file_get_contents($root . '/src/views/pages/auth/accounts.php');
        $accountEdit = (string)file_get_contents($root . '/src/views/pages/auth/account-edit.php');
        $accountUpdate = (string)file_get_contents($root . '/src/controllers/accounts/accounts_update.php');
        self::assertStringNotContainsString('name="external_ops_enabled"', $accountCreate);
        self::assertStringNotContainsString('name="external_ops_enabled"', $accountEdit);
        self::assertStringContainsString('Custom integrations access', $accountEdit);
        self::assertStringContainsString('name="custom_integration_access"', $accountEdit);
        self::assertStringNotContainsString('$externalOpsLabel', $accountEdit);
        self::assertStringContainsString('$externalOps->grantAccountAccess(', $accountUpdate);
        self::assertStringContainsString('$externalOps->revokeAccountAccess(', $accountUpdate);
        $completionMigration = (string)file_get_contents($root . '/database/migrations/0055_business_unit_memberships_and_project_managers.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS business_unit_memberships', $completionMigration);
        self::assertStringContainsString('membership_role', $completionMigration);
        self::assertStringContainsString('manager_user_id', $completionMigration);
        self::assertStringNotContainsString('OPS_SYNC_', $compose);
        self::assertStringNotContainsString('OPS_SYNC_', $envExample);
        self::assertStringContainsString(
            '* * * * * root . /etc/environment && php /var/www/src/cron/send_external_ops_outbox.php',
            $cron
        );
        self::assertStringContainsString('UNIQUE KEY uq_integration_outbox_event (event_id)', $baseline);
    }

    public function testUiConfigurationEncryptsAndReloadsCredentials(): void
    {
        $previousKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=' . base64_encode(str_repeat('K', 32)));
        try {
            $service = new ExternalOpsConfigService();
            $saved = $service->save($this->pdo, [
                'enabled' => '1',
                'label' => 'Field Operations',
                'application_key' => 'Customer_Ops',
                'webhook_url' => 'https://ops.example.test/api/provisioning/events',
                'access_client_id' => 'access-client-id',
                'access_client_secret' => 'access-client-secret',
                'hmac_secret' => str_repeat('h', 40),
                'timeout_seconds' => '20',
                'max_attempts' => '8',
            ]);

            self::assertTrue($saved['enabled']);
            self::assertSame('Field Operations', $saved['label']);
            self::assertSame('customer_ops', $saved['application_key']);
            self::assertSame('access-client-id', $saved['access_client_id']);
            self::assertSame('access-client-secret', $saved['access_client_secret']);
            self::assertSame(str_repeat('h', 40), $saved['hmac_secret']);
            self::assertSame(20, $saved['timeout_seconds']);
            self::assertSame(8, $saved['max_attempts']);

            $encrypted = (string)$this->pdo->query(
                "SELECT config_value FROM app_config WHERE config_key='external_ops_credentials_enc'"
            )->fetchColumn();
            self::assertStringStartsWith('enc::', $encrypted);
            self::assertStringNotContainsString('access-client-secret', $encrypted);
            self::assertStringNotContainsString(str_repeat('h', 40), $encrypted);

            $retained = $service->save($this->pdo, [
                'enabled' => '1',
                'label' => 'Field Operations',
                'application_key' => 'customer_ops',
                'webhook_url' => 'https://ops.example.test/api/provisioning/events',
                'timeout_seconds' => '20',
                'max_attempts' => '8',
            ]);
            self::assertSame('access-client-secret', $retained['access_client_secret']);

            try {
                $service->save($this->pdo, [
                    'enabled' => '1',
                    'label' => 'Field Operations',
                    'application_key' => 'another_ops',
                    'webhook_url' => 'https://ops.example.test/api/provisioning/events',
                ]);
                self::fail('A live integration key change should have been rejected.');
            } catch (\DomainException $error) {
                self::assertSame('Disable the integration before changing its application key.', $error->getMessage());
            }
        } finally {
            if ($previousKey === false) {
                putenv('APP_ENCRYPTION_KEY');
            } else {
                putenv('APP_ENCRYPTION_KEY=' . $previousKey);
            }
        }
    }

    public function testApplicationKeysAreDeploymentSpecificAndValidated(): void
    {
        self::assertSame(
            'community_ops-2',
            ExternalOpsIntegrationService::normalizeApplicationKey(' Community_Ops-2 ')
        );

        foreach (['', 'a', 'contains spaces', 'bad/key', str_repeat('a', 65)] as $invalidKey) {
            try {
                ExternalOpsIntegrationService::normalizeApplicationKey($invalidKey);
                self::fail('Invalid application key was accepted: ' . $invalidKey);
            } catch (\DomainException $error) {
                self::assertStringContainsString('2 to 64 characters', $error->getMessage());
            }
        }
    }

    public function testOutboxCronBootstrapsComposerBeforeLoadingDatabaseUtilities(): void
    {
        $root = dirname(__DIR__, 2);
        $cron = (string)file_get_contents($root . '/src/cron/send_external_ops_outbox.php');
        $autoload = "require_once dirname(__DIR__, 2) . '/vendor/autoload.php';";
        $database = "require_once __DIR__ . '/../config/db.php';";

        self::assertStringContainsString($autoload, $cron);
        self::assertStringContainsString($database, $cron);
        self::assertLessThan(strpos($cron, $database), strpos($cron, $autoload));
        self::assertTrue(class_exists(ExternalOpsConfigService::class));
        self::assertTrue(class_exists(ExternalOpsOutboxSender::class));
    }

    public function testApiServicePrincipalRetainsReadScopeWithoutAnAdminSession(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/acl.php';
        $previousPrincipal = $GLOBALS['pa_service_principal'] ?? null;
        $previousUser = $_SESSION['user'] ?? null;
        $GLOBALS['pa_service_principal'] = [
            'type' => 'api_key',
            'api_key_id' => 7,
            'name' => 'Ops sync',
            'scopes' => ['ops.sync.read'],
        ];
        unset($_SESSION['user']);

        try {
            self::assertSame(['', []], scope_clause($this->pdo, 'projects', 0));
        } finally {
            if ($previousPrincipal === null) {
                unset($GLOBALS['pa_service_principal']);
            } else {
                $GLOBALS['pa_service_principal'] = $previousPrincipal;
            }
            if ($previousUser === null) {
                unset($_SESSION['user']);
            } else {
                $_SESSION['user'] = $previousUser;
            }
        }
    }

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ([
            'CREATE TABLE app_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER DEFAULT 0,
                config_key TEXT, config_value TEXT, UNIQUE(organization_id, config_key)
            )',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role TEXT, is_disabled INTEGER, deleted_at TEXT, auth_version INTEGER DEFAULT 1)',
            'CREATE TABLE worker_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, display_name TEXT, status TEXT, ended_at TEXT)',
            'CREATE TABLE employee_profiles (user_id INTEGER PRIMARY KEY, employment_status TEXT, terminated_at TEXT)',
            'CREATE TABLE team_members (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,display_name TEXT,email TEXT,is_active INTEGER,profile_source TEXT)',
            'CREATE TABLE system_audit (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT)',
            'CREATE TABLE worker_business_units (
                worker_profile_id INTEGER, business_unit_id INTEGER, ends_at TEXT,
                PRIMARY KEY(worker_profile_id,business_unit_id)
            )',
            'CREATE TABLE business_units (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER)',
            'CREATE TABLE application_entitlements (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, application_key TEXT, enabled INTEGER,
                manual_enabled INTEGER DEFAULT 0, automatic_enabled INTEGER DEFAULT 0, oversight_enabled INTEGER DEFAULT 0,
                role_key TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, application_key)
            )',
            'CREATE TABLE application_entitlement_business_units (
                entitlement_id INTEGER, business_unit_id INTEGER, PRIMARY KEY(entitlement_id, business_unit_id)
            )',
            'CREATE TABLE application_entitlement_oversight_units (
                entitlement_id INTEGER, business_unit_id INTEGER, PRIMARY KEY(entitlement_id, business_unit_id)
            )',
            'CREATE TABLE integration_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT UNIQUE, integration_key TEXT, event_type TEXT,
                schema_version INTEGER, payload_json TEXT, occurred_at TEXT, attempts INTEGER DEFAULT 0,
                next_attempt_at TEXT, delivered_at TEXT, last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, status TEXT, business_unit_id INTEGER, manager_user_id INTEGER)',
            'CREATE TABLE project_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, user_id INTEGER, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, ends_at TEXT, created_by INTEGER, UNIQUE(project_id,user_id))',
            'CREATE TABLE operations (
                id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, business_unit_id INTEGER, title TEXT,
                status TEXT, scheduled_start_at TEXT, scheduled_end_at TEXT, location TEXT, notes TEXT,
                created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE operation_assignments (
                operation_id INTEGER, user_id INTEGER, assignment_role TEXT, assigned_by INTEGER,
                assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(operation_id, user_id)
            )',
            'CREATE TABLE tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT, operation_id INTEGER, project_id INTEGER,
                business_unit_id INTEGER, assignee_user_id INTEGER, title TEXT, status TEXT, due_at TEXT,
                notes TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE task_assignments (task_id INTEGER, user_id INTEGER, assigned_by INTEGER, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(task_id,user_id))',
        ] as $statement) {
            $this->pdo->exec($statement);
        }

        $this->pdo->exec("INSERT INTO users (id,email,username,role,is_disabled,deleted_at) VALUES
            (1,'admin@example.test','admin','admin',0,NULL),
            (2,'OPERATOR@EXAMPLE.TEST','operator','employee',0,NULL),
            (3,'owner@example.test','owner','owner',0,NULL)");
        $this->pdo->exec("INSERT INTO employee_profiles VALUES (2,'active',NULL)");
        $this->pdo->exec("INSERT INTO team_members (user_id,display_name,email,is_active,profile_source) VALUES
            (1,'Admin','admin@example.test',1,'pa'),
            (2,'Field Operator','operator@example.test',1,'pa'),
            (3,'Business Owner','owner@example.test',1,'pa')");
        $this->pdo->exec("INSERT INTO worker_profiles VALUES
            (20,2,'Field Operator','active',NULL),
            (21,3,'Business Owner','active',NULL)");
        $this->pdo->exec("INSERT INTO business_units VALUES
            (30,'North Division',1),
            (31,'South Division',1),
            (32,'Retired Division',0)");
        $this->pdo->exec("INSERT INTO worker_business_units VALUES
            (20,30,NULL),
            (21,31,NULL)");
        $this->pdo->exec("INSERT INTO projects VALUES (40,'Aerial Survey','active',30,NULL)");
    }

    public function testEntitlementStateIsQueuedWithoutCredentialsAndOwnerMapsToOperator(): void
    {
        $result = (new ExternalOpsIntegrationService())->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [30]);

        self::assertGreaterThan(0, $result['entitlement_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result['event_id']);
        self::assertSame([], array_map('intval', $this->pdo->query(
            'SELECT business_unit_id FROM application_entitlement_oversight_units'
        )->fetchAll(PDO::FETCH_COLUMN)));

        $payload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('operator@example.test', $payload['user']['email']);
        self::assertSame('Field Operator', $payload['user']['display_name']);
        self::assertTrue($payload['user']['active']);
        self::assertTrue($payload['entitlement']['enabled']);
        self::assertSame('role-operator', $payload['entitlement']['role_key']);
        self::assertSame([], $payload['entitlement']['business_unit_ids']);
        self::assertArrayNotHasKey('password', $payload['user']);

    }

    public function testDisablingGrantedUserProducesAtomicRevocationOnRefresh(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [30]);
        $this->pdo->exec('UPDATE users SET is_disabled=1 WHERE id=2');
        $service->refreshGrantedAccount($this->pdo, 2, 'ltds_ops', 1);

        $payload = $this->latestPayload();
        self::assertSame('application_entitlement.revoked', $payload['event_type']);
        self::assertFalse($payload['user']['active']);
        self::assertFalse($payload['entitlement']['enabled']);
        self::assertFalse($payload['entitlement']['manual_access']);
    }

    public function testAccountAccessDerivesAdminAndWorkerScopesWithoutTreatingOwnerAsAdmin(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 1, 'ltds_ops', true, 1);
        $adminPayload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($adminPayload['entitlement']['enabled']);
        self::assertSame('role-admin', $adminPayload['entitlement']['role_key']);
        self::assertSame([], $adminPayload['entitlement']['business_unit_ids']);

        $service->saveAccountAccess($this->pdo, 3, 'ltds_ops', true, 1, [31]);
        $ownerPayload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('role-operator', $ownerPayload['entitlement']['role_key']);
        self::assertSame([], $ownerPayload['entitlement']['business_unit_ids']);
    }

    public function testGrantRevokeAndInactiveGrantAreConsistent(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->grantAccountAccess($this->pdo, 2, 'ltds_ops', 1);
        $revoked = $service->revokeAccountAccess($this->pdo, 2, 'ltds_ops', 1);
        self::assertTrue($revoked['changed']);
        self::assertSame('application_entitlement.revoked', $this->latestPayload()['event_type']);
        $this->pdo->exec('UPDATE users SET is_disabled=1 WHERE id=2');
        $this->pdo->exec("UPDATE worker_profiles SET status='inactive' WHERE user_id=2");
        $this->pdo->exec("UPDATE employee_profiles SET employment_status='inactive' WHERE user_id=2");
        $granted = $service->grantAccountAccess($this->pdo, 2, 'ltds_ops', 1);
        self::assertTrue($granted['effective_enabled']);
        $payload = $this->latestPayload();
        self::assertTrue($payload['user']['active']);
        self::assertTrue($payload['entitlement']['enabled']);
        self::assertSame(0, (int)$this->pdo->query('SELECT is_disabled FROM users WHERE id=2')->fetchColumn());
        self::assertSame('active', $this->pdo->query('SELECT status FROM worker_profiles WHERE user_id=2')->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query(
            "SELECT enabled FROM application_entitlements WHERE user_id=2 AND application_key='ltds_ops'"
        )->fetchColumn());
    }

    public function testExplicitSelectionIgnoresDeprecatedBusinessUnitScopes(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [30]);
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [31]);

        $payload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('role-operator', $payload['entitlement']['role_key']);
        self::assertSame([], $payload['entitlement']['business_unit_ids']);
        self::assertSame([], array_map('intval', $this->pdo->query(
            'SELECT business_unit_id FROM application_entitlement_business_units ORDER BY business_unit_id'
        )->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame([], array_map('intval', $this->pdo->query(
            'SELECT business_unit_id FROM application_entitlement_oversight_units ORDER BY business_unit_id'
        )->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testPromotionAndDemotionDeriveRoleAndPreserveFinalAclAndScope(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [31]);

        $this->pdo->exec("UPDATE users SET role='admin' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [31]);
        $promoted = $this->latestPayload();
        self::assertSame('role-admin', $promoted['entitlement']['role_key']);
        self::assertSame([], $promoted['entitlement']['business_unit_ids']);

        $this->pdo->exec("UPDATE users SET role='employee' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [31]);
        $demotedEnabled = $this->latestPayload();
        self::assertTrue($demotedEnabled['entitlement']['enabled']);
        self::assertSame('role-operator', $demotedEnabled['entitlement']['role_key']);
        self::assertSame([], $demotedEnabled['entitlement']['business_unit_ids']);

        $this->pdo->exec("UPDATE users SET role='admin' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [31]);
        $this->pdo->exec("UPDATE users SET role='employee' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', false, 1);
        $demotedRevoked = $this->latestPayload();
        self::assertFalse($demotedRevoked['entitlement']['enabled']);
        self::assertSame('role-operator', $demotedRevoked['entitlement']['role_key']);
        self::assertSame([], $demotedRevoked['entitlement']['business_unit_ids']);
    }

    public function testAdminAclCanBeDisabledWithoutAllowingRoleOverride(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->grantAccountAccess($this->pdo, 1, 'ltds_ops', 1);
        $service->revokeAccountAccess($this->pdo, 1, 'ltds_ops', 1);
        $payload = $this->latestPayload();

        self::assertFalse($payload['entitlement']['enabled']);
        self::assertSame('role-admin', $payload['entitlement']['role_key']);
        self::assertSame([], $payload['entitlement']['business_unit_ids']);
    }

    public function testTerminatedWorkerProducesEffectiveRevocation(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [30]);
        $this->pdo->exec("UPDATE worker_profiles SET status='terminated' WHERE user_id=2");
        $service->refreshGrantedAccount($this->pdo, 2, 'ltds_ops', 1);

        $payload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['user']['active']);
        self::assertFalse($payload['entitlement']['enabled']);
        self::assertSame('application_entitlement.revoked', $payload['event_type']);
    }

    public function testRepeatedGrantAndRevokeAreIdempotent(): void
    {
        $service = new ExternalOpsIntegrationService();
        $first = $service->grantAccountAccess($this->pdo, 2, 'external_operations', 1);
        $second = $service->grantAccountAccess($this->pdo, 2, 'external_operations', 1);
        self::assertTrue($first['changed']);
        self::assertFalse($second['changed']);
        self::assertNull($second['event_id']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn());

        $firstRevoke = $service->revokeAccountAccess($this->pdo, 2, 'external_operations', 1);
        $secondRevoke = $service->revokeAccountAccess($this->pdo, 2, 'external_operations', 1);
        self::assertTrue($firstRevoke['changed']);
        self::assertFalse($secondRevoke['changed']);
        self::assertNull($secondRevoke['event_id']);
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn());
    }

    public function testResendQueuesTheCurrentStateForAnAlreadyGrantedActiveAccount(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->grantAccountAccess($this->pdo, 2, 'external_operations', 1);
        $entitlementId = (int)$this->pdo->query(
            "SELECT id FROM application_entitlements WHERE user_id=2 AND application_key='external_operations'"
        )->fetchColumn();
        $this->pdo->exec("INSERT INTO application_entitlement_business_units VALUES ({$entitlementId},30)");
        $this->pdo->exec("INSERT INTO application_entitlement_oversight_units VALUES ({$entitlementId},31)");
        $beforeEntitlement = $this->pdo->query(
            "SELECT * FROM application_entitlements WHERE id={$entitlementId}"
        )->fetch(PDO::FETCH_ASSOC);
        $beforeBusinessUnits = $this->pdo->query(
            "SELECT business_unit_id FROM application_entitlement_business_units WHERE entitlement_id={$entitlementId} ORDER BY business_unit_id"
        )->fetchAll(PDO::FETCH_COLUMN);
        $beforeOversightUnits = $this->pdo->query(
            "SELECT business_unit_id FROM application_entitlement_oversight_units WHERE entitlement_id={$entitlementId} ORDER BY business_unit_id"
        )->fetchAll(PDO::FETCH_COLUMN);

        $result = $service->resendAccountAccess($this->pdo, 2, 'external_operations', 1);

        self::assertNotNull($result);
        self::assertNotSame('', $result['event_id']);
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn());
        self::assertSame($beforeEntitlement, $this->pdo->query(
            "SELECT * FROM application_entitlements WHERE id={$entitlementId}"
        )->fetch(PDO::FETCH_ASSOC));
        self::assertSame($beforeBusinessUnits, $this->pdo->query(
            "SELECT business_unit_id FROM application_entitlement_business_units WHERE entitlement_id={$entitlementId} ORDER BY business_unit_id"
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($beforeOversightUnits, $this->pdo->query(
            "SELECT business_unit_id FROM application_entitlement_oversight_units WHERE entitlement_id={$entitlementId} ORDER BY business_unit_id"
        )->fetchAll(PDO::FETCH_COLUMN));

        $payload = $this->latestPayload();
        self::assertSame('application_entitlement.changed', $payload['event_type']);
        self::assertTrue($payload['user']['active']);
        self::assertTrue($payload['entitlement']['enabled']);
        self::assertTrue($payload['entitlement']['manual_access']);
        self::assertSame([], $payload['entitlement']['business_unit_ids']);
        self::assertSame([], $payload['entitlement']['oversight_business_unit_ids']);
    }

    public function testResendRejectsInactiveOrMissingExplicitAccess(): void
    {
        $service = new ExternalOpsIntegrationService();
        self::assertNull($service->resendAccountAccess($this->pdo, 2, 'external_operations', 1));

        $service->grantAccountAccess($this->pdo, 2, 'external_operations', 1);
        $this->pdo->exec('UPDATE users SET is_disabled=1 WHERE id=2');
        self::assertNull($service->resendAccountAccess($this->pdo, 2, 'external_operations', 1));
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn());
    }

    public function testEnabledRowWithoutExplicitSelectionIsNotEffectiveAccess(): void
    {
        $this->pdo->exec(
            "INSERT INTO application_entitlements
             (user_id,application_key,enabled,manual_enabled,automatic_enabled,oversight_enabled,role_key,created_by,updated_by)
             VALUES (2,'external_operations',1,0,0,0,'role-operator',1,1)"
        );
        $service = new ExternalOpsIntegrationService();
        self::assertNull($service->refreshGrantedAccount($this->pdo, 2, 'external_operations', 1));
        $service->enqueueCurrentState($this->pdo, 2, 'external_operations', 'application_entitlement.changed');
        $payload = $this->latestPayload();
        self::assertFalse($payload['entitlement']['enabled']);
        self::assertFalse($payload['entitlement']['manual_access']);
    }

    public function testWorkerProfileAndExternalRevocationRollbackTogetherWhenOutboxOrAuditFails(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->grantAccountAccess($this->pdo, 2, 'external_operations', 1);
        $initialOutboxCount = (int)$this->pdo->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn();
        $initialAuditCount = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit')->fetchColumn();
        $this->pdo->exec(
            "CREATE TRIGGER reject_external_outbox_insert
             BEFORE INSERT ON integration_outbox
             BEGIN
                 SELECT RAISE(ABORT, 'forced outbox failure');
             END"
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE worker_profiles SET status='terminated',ended_at='2026-07-22' WHERE user_id=2");
            $service->refreshGrantedAccount($this->pdo, 2, 'external_operations', 1);
            self::fail('The forced outbox failure did not abort the worker update.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('forced outbox failure', $error->getMessage());
            $this->pdo->rollBack();
        }

        self::assertSame('active', $this->pdo->query(
            'SELECT status FROM worker_profiles WHERE user_id=2'
        )->fetchColumn());
        self::assertNull($this->pdo->query(
            'SELECT ended_at FROM worker_profiles WHERE user_id=2'
        )->fetchColumn());
        $entitlement = $this->pdo->query(
            "SELECT enabled,manual_enabled FROM application_entitlements
             WHERE user_id=2 AND application_key='external_operations'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int)$entitlement['enabled']);
        self::assertSame(1, (int)$entitlement['manual_enabled']);
        self::assertSame($initialOutboxCount, (int)$this->pdo->query(
            'SELECT COUNT(*) FROM integration_outbox'
        )->fetchColumn());

        $this->pdo->exec('DROP TRIGGER reject_external_outbox_insert');
        $this->pdo->exec(
            "CREATE TRIGGER reject_external_audit_insert
             BEFORE INSERT ON system_audit
             BEGIN
                 SELECT RAISE(ABORT, 'forced audit failure');
             END"
        );
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE worker_profiles SET status='terminated',ended_at='2026-07-22' WHERE user_id=2");
            $service->refreshGrantedAccount($this->pdo, 2, 'external_operations', 1);
            self::fail('The forced audit failure did not abort the worker update.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('forced audit failure', $error->getMessage());
            $this->pdo->rollBack();
        }
        self::assertSame('active', $this->pdo->query(
            'SELECT status FROM worker_profiles WHERE user_id=2'
        )->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query(
            "SELECT enabled FROM application_entitlements
             WHERE user_id=2 AND application_key='external_operations'"
        )->fetchColumn());
        self::assertSame($initialOutboxCount, (int)$this->pdo->query(
            'SELECT COUNT(*) FROM integration_outbox'
        )->fetchColumn());
        self::assertSame($initialAuditCount, (int)$this->pdo->query(
            'SELECT COUNT(*) FROM system_audit'
        )->fetchColumn());

        $handler = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/controllers/settings/workforce_catalog_handler.php'
        );
        $branchStart = strpos($handler, "if(\$action==='save-worker-profile')");
        $branchEnd = strpos($handler, "}elseif(\$action==='save-work-type')", $branchStart ?: 0);
        self::assertNotFalse($branchStart);
        self::assertNotFalse($branchEnd);
        $workerProfileBranch = substr($handler, (int)$branchStart, (int)$branchEnd - (int)$branchStart);
        self::assertStringContainsString('$pdo->beginTransaction();', $workerProfileBranch);
        self::assertStringContainsString('$syncOpsAccount($previousAccountId)', $workerProfileBranch);
        self::assertStringContainsString('reconcileVerifiedOwnerEntries($accountId)', $workerProfileBranch);
        self::assertStringContainsString('$pdo->commit();', $workerProfileBranch);
    }

    public function testGrantRefusesTerminatedAndDeletedAccounts(): void
    {
        $service = new ExternalOpsIntegrationService();
        $this->pdo->exec("UPDATE worker_profiles SET status='terminated' WHERE user_id=2");
        try {
            $service->grantAccountAccess($this->pdo, 2, 'external_operations', 1);
            self::fail('Terminated worker was granted access.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Terminated workers', $error->getMessage());
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM application_entitlements')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit')->fetchColumn());
        $this->pdo->exec("UPDATE worker_profiles SET status='active' WHERE user_id=2");
        $this->pdo->exec("UPDATE users SET deleted_at='2026-01-01' WHERE id=2");
        $this->expectException(\DomainException::class);
        $service->grantAccountAccess($this->pdo, 2, 'external_operations', 1);
    }

    public function testOutboxDeliveryUsesAccessAndHmacHeaders(): void
    {
        (new ExternalOpsIntegrationService())->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [30]);
        $captured = [];
        $config = [
            'enabled' => true,
            'application_key' => 'ltds_ops',
            'webhook_url' => 'https://ops-sync.ledgetopdroneservices.com/v1/project-alpha/events',
            'access_client_id' => 'client-id',
            'access_client_secret' => 'client-secret',
            'hmac_secret' => str_repeat('h', 32),
            'timeout_seconds' => 5,
            'max_attempts' => 3,
        ];

        $summary = (new ExternalOpsOutboxSender())->deliverDue(
            $this->pdo,
            $config,
            1,
            static function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = compact('url', 'headers', 'body', 'timeout');
                return ['status' => 204];
            }
        );

        self::assertSame(['processed' => 1, 'delivered' => 1, 'failed' => 0], $summary);
        self::assertSame($config['webhook_url'], $captured['url']);
        self::assertContains('CF-Access-Client-Id: client-id', $captured['headers']);
        self::assertContains('CF-Access-Client-Secret: client-secret', $captured['headers']);
        $eventIdHeader = $this->headerValue($captured['headers'], 'X-PA-Event-ID: ');
        $timestampHeader = $this->headerValue($captured['headers'], 'X-PA-Timestamp: ');
        $signatureHeader = $this->headerValue($captured['headers'], 'X-PA-Signature: ');
        $payload = json_decode($captured['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload['event_id'], $eventIdHeader);
        self::assertSame(
            'sha256=' . hash_hmac('sha256', $timestampHeader . '.' . $captured['body'], str_repeat('h', 32)),
            $signatureHeader
        );
        self::assertNotFalse($this->pdo->query('SELECT delivered_at FROM integration_outbox')->fetchColumn());
    }

    public function testOutboxFailureIsRetriedWithAStoredBoundedError(): void
    {
        (new ExternalOpsIntegrationService())->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [30]);
        $before = gmdate('Y-m-d H:i:s.u');
        $summary = (new ExternalOpsOutboxSender())->deliverDue($this->pdo, [
            'enabled' => true,
            'application_key' => 'ltds_ops',
            'webhook_url' => 'https://ops.example.test/api/provisioning/events',
            'access_client_id' => 'client-id',
            'access_client_secret' => 'client-secret',
            'hmac_secret' => str_repeat('h', 32),
            'timeout_seconds' => 5,
            'max_attempts' => 3,
        ], 1, static fn(): array => ['status' => 503, 'body' => "temporary\nerror"]);

        self::assertSame(['processed' => 1, 'delivered' => 0, 'failed' => 1], $summary);
        $row = $this->pdo->query(
            'SELECT attempts,next_attempt_at,delivered_at,last_error FROM integration_outbox LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int)$row['attempts']);
        self::assertNull($row['delivered_at']);
        self::assertGreaterThan($before, (string)$row['next_attempt_at']);
        self::assertSame('HTTP 503: temporary error', $row['last_error']);
    }

    public function testOperationsAndTasksRemainOwnedByProjectAlpha(): void
    {
        $this->pdo->exec('INSERT INTO project_assignments (project_id,user_id,created_by) VALUES (40,2,1)');
        $service = new OperationsPlanningService();
        $operationId = $service->saveOperation($this->pdo, [
            'project_id' => 40,
            'business_unit_id' => 30,
            'title' => 'Capture flight',
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-07-20 09:00:00',
            'scheduled_end_at' => '2026-07-20 11:00:00',
        ], [2], 1);
        $taskId = $service->saveTask($this->pdo, [
            'operation_id' => $operationId,
            'project_id' => 40,
            'business_unit_id' => 30,
            'assignee_user_id' => 2,
            'title' => 'Upload imagery',
            'status' => 'todo',
            'due_at' => '2026-07-20 17:00:00',
        ], 1);

        self::assertGreaterThan(0, $operationId);
        self::assertGreaterThan(0, $taskId);
        self::assertSame('2', (string)$this->pdo->query(
            'SELECT user_id FROM operation_assignments WHERE operation_id=' . $operationId
        )->fetchColumn());
        self::assertSame((string)$operationId, (string)$this->pdo->query(
            'SELECT operation_id FROM tasks WHERE id=' . $taskId
        )->fetchColumn());
    }

    public function testProjectTeamGatesMultiAssigneeWorkWithoutGrantingExternalAccess(): void
    {
        $team = new ProjectWorkPlanningService();
        $team->addTeamMember($this->pdo, 40, 2, 1);
        $team->addTeamMember($this->pdo, 40, 3, 1);
        $integration = new ExternalOpsIntegrationService();
        self::assertNull($integration->resyncAccountAccess($this->pdo, 2, 'field_operations', 1));
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM application_entitlements WHERE user_id=2 AND application_key='field_operations'")->fetchColumn());

        $operations = new OperationsPlanningService();
        $operationId = $operations->saveOperation($this->pdo, ['project_id'=>40,'title'=>'Site visit','status'=>'scheduled'], [2,3], 1);
        $taskId = $operations->saveTask($this->pdo, ['project_id'=>40,'operation_id'=>$operationId,'title'=>'Map site','status'=>'todo'], 1, [2,3]);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM task_assignments WHERE task_id={$taskId}")->fetchColumn());
        self::assertSame(30, (int)$this->pdo->query("SELECT business_unit_id FROM tasks WHERE id={$taskId}")->fetchColumn());

        $this->expectException(\DomainException::class);
        $team->endTeamMember($this->pdo, 40, 2);
    }

    public function testEndingFinalProjectMembershipDoesNotRevokeExplicitAccess(): void
    {
        $team = new ProjectWorkPlanningService();
        $team->addTeamMember($this->pdo, 40, 2, 1);
        $integration = new ExternalOpsIntegrationService();
        $integration->saveAccountAccess($this->pdo, 2, 'field_operations', true, 1);
        $team->endTeamMember($this->pdo, 40, 2);
        $integration->resyncAccountAccess($this->pdo, 2, 'field_operations', 1);
        $state=$this->pdo->query("SELECT enabled,automatic_enabled FROM application_entitlements WHERE user_id=2 AND application_key='field_operations'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1,(int)$state['enabled']);
        self::assertSame(0,(int)$state['automatic_enabled']);
    }

    public function testIncrementalProjectionEventsExcludeSensitiveAndUnrelatedFields(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->enqueueProjectionChange($this->pdo, 'field_operations', 'project', 40, 'upsert', [
            'id' => 40,
            'name' => 'Safe project name',
            'business_unit_id' => 30,
            'public_project_token' => 'private-sharing-token',
            'public_project_password_hash' => 'private-password-hash',
            'invoice_net_terms_days' => 30,
            'updated_at' => '2026-07-22T05:00:00.123456Z',
        ]);
        $projectPayload = $this->latestPayload();
        self::assertSame('Safe project name', $projectPayload['projection']['data']['name']);
        self::assertArrayNotHasKey('public_project_token', $projectPayload['projection']['data']);
        self::assertArrayNotHasKey('public_project_password_hash', $projectPayload['projection']['data']);
        self::assertArrayNotHasKey('invoice_net_terms_days', $projectPayload['projection']['data']);

        $service->enqueueProjectionChange($this->pdo, 'field_operations', 'project_assignment', 1, 'upsert', [
            'id' => 1,
            'project_id' => 40,
            'user_id' => 2,
            'pay_rate_override' => '250.00',
            'updated_at' => '2026-07-22T05:00:01.123456Z',
        ]);
        $assignmentPayload = $this->latestPayload();
        self::assertArrayNotHasKey('pay_rate_override', $assignmentPayload['projection']['data']);
        self::assertSame(40, $assignmentPayload['projection']['data']['project_id']);
    }

    public function testIncrementalProjectionRejectsUnknownEntityTypes(): void
    {
        $this->expectException(\DomainException::class);
        (new ExternalOpsIntegrationService())->enqueueProjectionChange(
            $this->pdo,
            'field_operations',
            'payment',
            1,
            'upsert',
            ['amount' => '100.00']
        );
    }

    /** @param list<string> $headers */
    private function headerValue(array $headers, string $prefix): string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $prefix)) {
                return substr($header, strlen($prefix));
            }
        }
        self::fail('Missing header: ' . $prefix);
    }

    /** @return array<string,mixed> */
    private function latestPayload(): array
    {
        return json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }
}
