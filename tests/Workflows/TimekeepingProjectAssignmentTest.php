<?php

declare(strict_types=1);

use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\TimekeepingService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class TimekeepingProjectAssignmentTest extends TestCase
{
    public function testProjectlessJobDoesNotGrantUnassignedProjectTime(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'));
        $pdo->exec("CREATE TABLE jobs(id INTEGER PRIMARY KEY,client_id INTEGER,project_id INTEGER,archived INTEGER,status TEXT,created_by INTEGER);
            CREATE TABLE job_work_components(id INTEGER,job_id INTEGER);
            CREATE TABLE work_assignments(job_work_component_id INTEGER,worker_profile_id INTEGER,status TEXT);
            CREATE TABLE worker_profiles(id INTEGER,user_id INTEGER);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,client_id INTEGER,status TEXT);
            CREATE TABLE project_assignments(project_id INTEGER,user_id INTEGER,ends_at TEXT);
            CREATE TABLE clients(id INTEGER PRIMARY KEY,archived INTEGER,deleted_at TEXT);
            INSERT INTO jobs VALUES(3,7,NULL,0,'active',11);
            INSERT INTO projects VALUES(5,7,'active');
            INSERT INTO clients VALUES(7,0,NULL)");
        $service = new TimekeepingService($pdo, new AuditRecorder($pdo));
        $resolve = new ReflectionMethod($service, 'resolveBillingContext');

        try {
            $resolve->invoke($service, 11, ['job_id'=>3, 'project_id'=>5], false);
            self::fail('A projectless Job bypassed project assignment.');
        } catch (DomainException $error) {
            self::assertSame('The selected project is not assigned to this employee.', $error->getMessage());
        }

        $pdo->exec('INSERT INTO project_assignments VALUES(5,11,NULL)');
        $context = $resolve->invoke($service, 11, ['job_id'=>3, 'project_id'=>5], false);
        self::assertSame(5, $context['project_id']);
        self::assertSame(3, $context['job_id']);
        $pdo->exec('DELETE FROM project_assignments');
        self::assertSame(5, $resolve->invoke($service, 11, ['job_id'=>3, 'project_id'=>5], true)['project_id']);

        $pdo->exec("INSERT INTO projects VALUES(6,7,'active'); UPDATE jobs SET project_id=6 WHERE id=3");
        try {
            $resolve->invoke($service, 11, ['job_id'=>3, 'project_id'=>5], false);
            self::fail('A Job was paired with a different project.');
        } catch (DomainException $error) {
            self::assertSame('The selected Job does not belong to that Project.', $error->getMessage());
        }
        self::assertSame(6, $resolve->invoke($service, 11, ['job_id'=>3, 'project_id'=>6], false)['project_id']);
    }
}
