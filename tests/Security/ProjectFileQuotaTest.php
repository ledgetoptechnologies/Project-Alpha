<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/project_files.php';

final class ProjectFileQuotaTest extends TestCase
{
    public function testFileAndProjectStorageLimitsApplyInsideTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY);
            CREATE TABLE project_files (project_id INTEGER, file_size INTEGER);
            INSERT INTO projects VALUES (1),(2)');

        $this->expectException(LogicException::class);
        project_files_assert_upload_quota($pdo, 1, 1);
    }

    public function testQuotaRejectsOversizedFileAndFullProject(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY);
            CREATE TABLE project_files (project_id INTEGER, file_size INTEGER);
            INSERT INTO projects VALUES (1),(2);
            INSERT INTO project_files VALUES (1,' . (PROJECT_STORAGE_MAX_BYTES - 10) . ')');
        $pdo->beginTransaction();
        project_files_assert_upload_quota($pdo, 1, 10);
        project_files_assert_upload_quota($pdo, 2, PROJECT_FILE_MAX_BYTES);
        try {
            project_files_assert_upload_quota($pdo, 1, 11);
            self::fail('An upload exceeding the remaining project quota should fail.');
        } catch (LengthException $error) {
            self::assertStringContainsString('storage is full', $error->getMessage());
        }
        try {
            project_files_assert_upload_quota($pdo, 2, PROJECT_FILE_MAX_BYTES + 1);
            self::fail('A file above the per-file cap should fail.');
        } catch (LengthException $error) {
            self::assertStringContainsString('250 MB', $error->getMessage());
        }
        $pdo->rollBack();

        $privateHandler = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/project/project_files_handler.php');
        $publicHandler = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/public_view/public_project_upload.php');
        self::assertStringContainsString('project_files_assert_upload_quota($pdo, $projectId, $fileSize)', $privateHandler);
        self::assertStringContainsString('project_files_assert_upload_quota($pdo, $projectId, $fileSize)', $publicHandler);
    }
}
