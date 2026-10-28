<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/project_notes_access.php';

use PHPUnit\Framework\TestCase;

final class ProjectNotesAccessTest extends TestCase
{
    public function testJobCodeAndClientMustIdentifyOneAccessibleJob(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, job_code TEXT, client_id INTEGER, project_id INTEGER, created_by INTEGER, archived INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (701, 'member'), (702, 'member')");
        $pdo->exec("INSERT INTO jobs VALUES (1, 'JOB-A', 10, NULL, 701, 0), (2, 'JOB-B', 11, NULL, 702, 0), (3, 'OLD', 10, NULL, 701, 1)");
        $_SESSION['user'] = ['id' => 701, 'role' => 'member'];

        self::assertSame(1, (int)project_notes_authorized_job($pdo, 'JOB-A', 701, 10)['id']);
        self::assertNull(project_notes_authorized_job($pdo, 'JOB-A', 702, 10));
        self::assertNull(project_notes_authorized_job($pdo, 'JOB-A', 701, 11));
        self::assertNull(project_notes_authorized_job($pdo, 'JOB-B', 701, 11));
        self::assertNull(project_notes_authorized_job($pdo, 'OLD', 701, 10));

        $pdo->exec("INSERT INTO jobs VALUES (4, 'JOB-A', 12, NULL, 702, 0)");
        self::assertNull(project_notes_authorized_job($pdo, 'JOB-A', 701, 10));
        $pdo->exec("INSERT INTO jobs VALUES (5, 'JOB-B', 12, NULL, 701, 1)");
        self::assertNull(project_notes_authorized_job($pdo, 'JOB-B', 702, 11));
        unset($_SESSION['user']);
    }
}
