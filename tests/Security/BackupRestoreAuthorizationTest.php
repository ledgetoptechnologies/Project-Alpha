<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BackupRestoreAuthorizationTest extends TestCase
{
    public function testOnlyDatabaseAdministratorCanReachRestoreFlow(): void
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/pa-backup-auth-' . bin2hex(random_bytes(8));
        foreach (['', '/src/controllers', '/src/config', '/src/utils'] as $suffix) {
            mkdir($temporary . $suffix, 0700, true);
        }
        try {
            foreach (['controllers/backup_handler.php', 'utils/acl.php'] as $file) {
                copy($root . '/src/' . $file, $temporary . '/src/' . $file);
            }
            file_put_contents($temporary . '/src/config/db.php', '<?php $pdo = new PDO("sqlite:" . __DIR__ . "/../../users.sqlite");');
            file_put_contents($temporary . '/src/config/app.php', '<?php $appConfig = [];');
            file_put_contents($temporary . '/src/utils/backup_archive.php', '<?php');
            $pdo = new PDO('sqlite:' . $temporary . '/users.sqlite');
            $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, role TEXT);
                INSERT INTO users VALUES(1,'admin'),(2,'owner')");
            unset($pdo);
            file_put_contents($temporary . '/driver.php', <<<'PHP'
<?php
session_start();
$_SESSION['user'] = ['id' => (int)$argv[1], 'role' => $argv[2]];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'restore'];
register_shutdown_function(static function (): void {
    echo '|status=' . (http_response_code() ?: 200);
    echo '|flash=' . ($_SESSION['flash_backup']['message'] ?? '');
});
require __DIR__ . '/src/controllers/backup_handler.php';
PHP);

            $owner = $this->runDriver($temporary, 2, 'admin'); // A stale session must not grant restore.
            self::assertStringContainsString('Only an installation administrator can restore', $owner);
            self::assertStringContainsString('|status=403', $owner);
            $admin = $this->runDriver($temporary, 1, 'admin');
            self::assertStringContainsString('|status=302', $admin);
            self::assertStringContainsString('You must confirm the restore operation.', $admin);
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($temporary);
        }
    }

    private function runDriver(string $directory, int $userId, string $sessionRole): string
    {
        $process = proc_open([PHP_BINARY, $directory . '/driver.php', (string)$userId, $sessionRole],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        return $output;
    }
}
