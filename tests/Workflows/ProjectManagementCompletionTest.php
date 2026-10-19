<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ProjectWorkPlanningService;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectManagementCompletionTest extends TestCase
{
    public function testProjectViewsUseRealUserNameSourcesAndExposeManagerControls(): void
    {
        $root = dirname(__DIR__, 2);
        $details = (string)file_get_contents($root . '/src/views/pages/project/projects-details.php');
        $create = (string)file_get_contents($root . '/src/views/pages/project/projects-create.php');
        $edit = (string)file_get_contents($root . '/src/views/pages/project/projects-edit.php');
        $createController = (string)file_get_contents($root . '/src/controllers/project/projects_create.php');
        $updateController = (string)file_get_contents($root . '/src/controllers/project/projects_update.php');

        self::assertStringNotContainsString('u.display_name', $details);
        self::assertStringContainsString('LEFT JOIN worker_profiles wp ON wp.user_id=u.id', $details);
        self::assertStringContainsString('LEFT JOIN team_members tm ON tm.user_id=u.id', $details);
        self::assertStringContainsString("[projects-details] planning query failed:", $details);
        self::assertStringContainsString('Team and work planning is temporarily unavailable.', $details);
        self::assertStringContainsString('name="manager_user_id"', $create);
        self::assertStringContainsString('name="manager_user_id"', $edit);
        self::assertStringNotContainsString('name="business_unit_id"', $create);
        self::assertStringNotContainsString('name="business_unit_id"', $edit);
        self::assertStringContainsString('manager_user_id', $createController);
        self::assertStringContainsString('manager_user_id', $updateController);
        self::assertStringContainsString('addTeamMember($pdo, $project_id, $managerUserId', $createController);
        self::assertStringContainsString('addTeamMember($pdo, $id, $managerUserId', $updateController);
        self::assertStringNotContainsString("default_business_unit_id", $updateController);
        self::assertStringNotContainsString('business_unit_id=?', $updateController);
        self::assertStringNotContainsString('UPDATE operations SET business_unit_id', $updateController);

        $accountUpdate = (string)file_get_contents($root . '/src/controllers/accounts/accounts_update.php');
        self::assertStringContainsString('Choose a different Project Manager for ', $accountUpdate);
        self::assertStringContainsString('p.manager_user_id<>?', $accountUpdate);
    }

    public function testManagerIgnoresHistoricalUnitAndRemainsOnProjectTeam(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,is_disabled INTEGER,deleted_at TEXT)');
        $pdo->exec('CREATE TABLE business_units (id INTEGER PRIMARY KEY,is_active INTEGER)');
        $pdo->exec('CREATE TABLE business_unit_memberships (id INTEGER PRIMARY KEY,business_unit_id INTEGER,user_id INTEGER,is_primary INTEGER,ended_at TEXT)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,status TEXT,manager_user_id INTEGER,business_unit_id INTEGER,updated_at TEXT)');
        $pdo->exec('CREATE TABLE project_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,user_id INTEGER,created_by INTEGER,assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,ends_at TEXT)');
        $pdo->exec('CREATE TABLE operations (id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,business_unit_id INTEGER)');
        $pdo->exec('CREATE TABLE operation_assignments (operation_id INTEGER,user_id INTEGER)');
        $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,business_unit_id INTEGER)');
        $pdo->exec('CREATE TABLE task_assignments (task_id INTEGER,user_id INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (1,0,NULL),(2,0,NULL)");
        $pdo->exec('INSERT INTO business_units VALUES (10,1)');
        $pdo->exec('INSERT INTO business_unit_memberships VALUES (100,10,2,1,NULL)');
        $pdo->exec("INSERT INTO projects VALUES (20,'active',NULL,NULL,NULL)");

        $service = new ProjectWorkPlanningService();
        self::assertSame(0, $service->primaryBusinessUnitForUser($pdo, 2));

        $service->setProjectManager($pdo, 20, 2, 1);
        self::assertSame(2, (int)$pdo->query('SELECT manager_user_id FROM projects WHERE id=20')->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM project_assignments WHERE project_id=20 AND user_id=2 AND ends_at IS NULL')->fetchColumn());

        $service->setProjectManager($pdo, 20, 1, 1);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM project_assignments WHERE project_id=20 AND ends_at IS NULL')->fetchColumn());
        $pdo->exec("UPDATE project_assignments SET assigned_at='2026-01-01 00:00:00',created_by=1 WHERE project_id=20 AND user_id=1");
        $service->setProjectManager($pdo, 20, 1, 2);
        $unchangedAssignment = $pdo->query('SELECT assigned_at,created_by FROM project_assignments WHERE project_id=20 AND user_id=1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('2026-01-01 00:00:00', $unchangedAssignment['assigned_at']);
        self::assertSame(1, (int)$unchangedAssignment['created_by']);
        $service->endTeamMember($pdo, 20, 2);
        self::assertNotFalse($pdo->query('SELECT ends_at FROM project_assignments WHERE project_id=20 AND user_id=2')->fetchColumn());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Choose a different Project Manager');
        $service->endTeamMember($pdo, 20, 1);
    }
}
