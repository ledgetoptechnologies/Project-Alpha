<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/public_project_links.php';

final class PublicProjectFileVisibilityTest extends TestCase
{
    public function testPublicListAndDirectLookupRequireFileAndFolderPublication(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE project_file_folders (id INTEGER PRIMARY KEY, project_id INTEGER, client_visible INTEGER)');
        $pdo->exec('CREATE TABLE project_files (id INTEGER PRIMARY KEY, project_id INTEGER, folder_id INTEGER, client_visible INTEGER, uploaded_at TEXT)');
        $pdo->exec('INSERT INTO project_file_folders VALUES (10, 1, 0), (11, 1, 1), (12, 2, 1)');
        $pdo->exec("INSERT INTO project_files VALUES
            (1, 1, NULL, 0, '2026-01-01'),
            (2, 1, NULL, 1, '2026-01-02'),
            (3, 1, 10, 1, '2026-01-03'),
            (4, 1, 11, 1, '2026-01-04'),
            (5, 1, 12, 1, '2026-01-05'),
            (6, 2, NULL, 1, '2026-01-06')");

        self::assertSame([4, 2], array_map(
            static fn(array $file): int => (int)$file['id'],
            pa_project_public_visible_files($pdo, 1)
        ));
        foreach ([1, 3, 5, 6] as $privateId) {
            self::assertSame([], pa_project_public_visible_files($pdo, 1, $privateId));
        }
        self::assertSame(2, (int)pa_project_public_visible_files($pdo, 1, 2)[0]['id']);
        self::assertSame(4, (int)pa_project_public_visible_files($pdo, 1, 4)[0]['id']);
    }

    public function testBothPublicControllersUseTheSameVisibilityBoundary(): void
    {
        $root = dirname(__DIR__, 2) . '/src/controllers/public_view/';
        foreach (['public_project.php', 'public_project_file.php'] as $controller) {
            self::assertStringContainsString(
                'pa_project_public_visible_files($pdo,',
                (string)file_get_contents($root . $controller)
            );
        }
    }
}
