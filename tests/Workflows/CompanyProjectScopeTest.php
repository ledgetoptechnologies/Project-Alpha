<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ProjectWorkPlanningService;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/utils/acl.php';

final class CompanyProjectScopeTest extends TestCase
{
    private array $sessionBefore;
    protected function setUp(): void { $this->sessionBefore = $_SESSION ?? []; }
    protected function tearDown(): void { $_SESSION = $this->sessionBefore; }

    public function testCompanyStaffAndOwnerUseExistingAuthorityWithoutDivisionMemberships(): void
    {
        $pdo=new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,role TEXT)');
        $pdo->exec("INSERT INTO users VALUES(78101,'owner'),(78102,'staff'),(78103,'employee')");
        $_SESSION['user']=['role'=>'employee'];
        // No business_units or membership table exists in this fixture.
        self::assertSame(['',[]],scope_clause($pdo,'projects',78101));
        self::assertSame(['',[]],scope_clause($pdo,'projects',78102));
        self::assertSame(['projects.created_by = ?',[78103]],scope_clause($pdo,'projects',78103));
        $_SESSION['user']=['role'=>'admin'];
        self::assertSame(['',[]],scope_clause($pdo,'projects',78103));
    }

    public function testManagerCanBeAssignedWithoutInternalUnitAndExistingTeamIsPreserved(): void
    {
        $pdo=new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,is_disabled INTEGER,deleted_at TEXT)');
        $pdo->exec("INSERT INTO users VALUES(1,0,NULL),(2,0,NULL)");
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,status TEXT,manager_user_id INTEGER,business_unit_id INTEGER,updated_at TEXT)');
        $pdo->exec("INSERT INTO projects VALUES(1,'active',NULL,NULL,NULL)");
        $pdo->exec('CREATE TABLE project_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,user_id INTEGER,created_by INTEGER,assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,ends_at TEXT)');
        $service=new ProjectWorkPlanningService();
        $service->setProjectManager($pdo,1,2,1);
        self::assertSame(2,(int)$pdo->query('SELECT manager_user_id FROM projects')->fetchColumn());
        self::assertNull($pdo->query('SELECT business_unit_id FROM projects')->fetchColumn());
        self::assertSame(2,(int)$pdo->query('SELECT user_id FROM project_assignments')->fetchColumn());
    }
    public function testRetiredDivisionSetterPreservesHistoricalIdsAndNeverAssignsANewScope(): void
    {
        $pdo=new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE projects(id INTEGER PRIMARY KEY,business_unit_id INTEGER)');
        $pdo->exec('CREATE TABLE operations(id INTEGER PRIMARY KEY,project_id INTEGER,business_unit_id INTEGER)');
        $pdo->exec('CREATE TABLE tasks(id INTEGER PRIMARY KEY,project_id INTEGER,business_unit_id INTEGER)');
        $pdo->exec('INSERT INTO projects VALUES(1,10)');
        $pdo->exec('INSERT INTO operations VALUES(1,1,10)');
        $pdo->exec('INSERT INTO tasks VALUES(1,1,10)');
        $service=new ProjectWorkPlanningService();
        try {
            $service->setProjectBusinessUnit($pdo,1,20);
            self::fail('Retired division assignment was accepted.');
        } catch (\DomainException $error) {
            self::assertSame('Internal divisions have been retired.',$error->getMessage());
        }
        foreach (['projects','operations','tasks'] as $table) self::assertSame(10,(int)$pdo->query("SELECT business_unit_id FROM {$table}")->fetchColumn());
        self::assertSame(0,$service->primaryBusinessUnitForUser($pdo,2));
    }

}
