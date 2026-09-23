<?php

declare(strict_types=1);

use App\Services\TimeApprovalPolicy;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/acl.php';

final class AdministrativeTimeSelfConfirmationWorkflowTest extends TestCase
{
    public function testOnlyBuiltInAdministrativeRolesReceiveTheSelfConfirmationBypass(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, deleted_at TEXT, is_disabled INTEGER DEFAULT 0)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, organization_id INTEGER, is_system INTEGER)',
            'CREATE TABLE role_permissions (role_id INTEGER, permission TEXT, allowed INTEGER)',
            'CREATE TABLE user_permissions_overrides (user_id INTEGER, organization_id INTEGER, permission TEXT, allowed INTEGER)',
            'CREATE TABLE app_config (organization_id INTEGER, config_key TEXT, config_value TEXT)',
            'CREATE TABLE worker_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, relationship_type TEXT, status TEXT, relationship_review_required INTEGER DEFAULT 0)',
            'CREATE TABLE worker_capability_scopes (worker_profile_id INTEGER, capability TEXT, access_scope TEXT, allowed INTEGER)',
            'CREATE TABLE worker_business_units (worker_profile_id INTEGER, business_unit_id INTEGER, is_lead INTEGER, ends_at TEXT)',
            'CREATE TABLE client_business_units (client_id INTEGER, business_unit_id INTEGER)',
            'CREATE TABLE project_assignments (project_id INTEGER, user_id INTEGER, ends_at TEXT)',
            'CREATE TABLE jobs (id INTEGER PRIMARY KEY, client_id INTEGER)',
            'CREATE TABLE work_time_entries (id TEXT PRIMARY KEY,user_id INTEGER,client_id INTEGER,project_id INTEGER,job_id INTEGER,work_assignment_id INTEGER,status TEXT,workflow_status TEXT,end_time TEXT)',
        ] as $sql) {
            $pdo->exec($sql);
        }

        $pdo->exec("INSERT INTO app_config VALUES (0,'workforce_allow_non_admin_time_approval','1')");
        $pdo->exec("INSERT INTO roles VALUES (1,'manager',NULL,0)");
        $pdo->exec("INSERT INTO role_permissions VALUES (1,'approvals.review',1)");
        $pdo->exec("INSERT INTO users (id,role) VALUES
            (1,'admin'),(2,'owner'),(3,'manager'),(4,'employee')");
        $pdo->exec("INSERT INTO worker_profiles (id,user_id,relationship_type,status) VALUES
            (11,1,'employee','active'),(12,2,'contractor','active'),
            (13,3,'employee','active'),(14,4,'employee','active')");
        $pdo->exec("INSERT INTO worker_capability_scopes VALUES (13,'approvals.review','all',1)");
        $pdo->exec("INSERT INTO work_time_entries VALUES
            ('admin-own',1,NULL,NULL,NULL,NULL,'review','draft','2026-07-20 10:00:00'),
            ('owner-role-own',2,NULL,NULL,NULL,NULL,'review','submitted','2026-07-20 10:00:00'),
            ('manager-own',3,NULL,NULL,NULL,NULL,'review','submitted','2026-07-20 10:00:00'),
            ('employee',4,NULL,NULL,NULL,NULL,'review','submitted','2026-07-20 10:00:00')");

        $policy = new TimeApprovalPolicy($pdo);

        self::assertTrue($policy->canAdministrativelySelfConfirm(1));
        self::assertTrue($policy->canAdministrativelySelfConfirm(2));
        self::assertFalse($policy->canAdministrativelySelfConfirm(3), 'Review permission and global scope must not grant self-confirmation.');
        self::assertTrue($policy->canAdministrativelySelfConfirmEntry(1, 'admin-own'));
        self::assertTrue($policy->canAdministrativelySelfConfirmEntry(2, 'owner-role-own'));
        self::assertFalse($policy->canAdministrativelySelfConfirmEntry(1, 'employee'), 'Administrative self-confirmation is limited to the actor\'s own entry.');
        self::assertFalse($policy->canAdministrativelySelfConfirmEntry(3, 'manager-own'));
        self::assertTrue($policy->canReviewEntry(1, 'admin-own', 'correct'));
        self::assertTrue($policy->canReviewEntry(1, 'admin-own', 'approve'), 'Admin-owned submitted entries must remain visible in the review queue.');
    }

    public function testControllerAndApiUseTheExplicitAdministrativePath(): void
    {
        $root = dirname(__DIR__, 2);
        $approval = (string)file_get_contents($root . '/src/Modules/Timekeeping/ApprovalService.php');
        $policy = (string)file_get_contents($root . '/src/services/TimeApprovalPolicy.php');
        $controller = (string)file_get_contents($root . '/src/controllers/workforce/action.php');
        $api = (string)file_get_contents($root . '/src/controllers/api/workforce_v1.php');

        self::assertStringContainsString('selfConfirmAdministrator', $approval);
        self::assertStringContainsString('administrative_self_confirm', $policy);
        self::assertStringNotContainsString('$effectivePayable = !$ownerSelfConfirmation', $approval);
        self::assertStringContainsString("(string)(\$entry['compensation_policy'] ?? '') === 'rules'", $approval);
        self::assertStringContainsString('$billingRateOverride ?? $this->billingRate($entry)', $approval);
        self::assertStringContainsString('time_entry.administratively_self_confirmed', $approval);
        self::assertStringContainsString('workforce_self_confirm_completed', $controller);
        self::assertStringContainsString('workforce_validate_pending_invoice_link', $controller);
        self::assertStringContainsString('ensureAdministratorProjection', $controller);
        self::assertLessThan(
            strpos($controller, '$approval->ensureAdministratorProjection'),
            strrpos($controller, 'workforce_validate_pending_invoice_link('),
            'Invoice destination and rate must be validated before a pending admin entry is confirmed.'
        );
        self::assertStringContainsString('$approval->selfConfirmAdministrator', $api);
    }
}
