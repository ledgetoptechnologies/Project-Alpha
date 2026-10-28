<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrganizationMutationAuthorizationTest extends TestCase
{
    public function testMemberCannotWriteOtherOrganizationNotesOrUpload(): void
    {
        $temporary = $this->fixture();
        try {
            $notes = $this->runController($temporary, 'organization-update-notes.php', 2, 'member');
            self::assertStringContainsString('|status=403', $notes);
            self::assertStringContainsString('|notes=original', $notes);

            $upload = $this->runController($temporary, 'organizations_upload.php', 2, 'member');
            self::assertStringContainsString('|status=403', $upload);
            self::assertStringContainsString('|notes=original', $upload);

            $owner = $this->runController($temporary, 'organization-update-notes.php', 1, 'owner');
            self::assertStringContainsString('|notes=updated', $owner);
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($temporary);
        }
    }

    private function fixture(): string
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/pa-org-auth-' . bin2hex(random_bytes(8));
        foreach (['', '/src/controllers/organization', '/src/config', '/src/utils'] as $suffix) {
            mkdir($temporary . $suffix, 0700, true);
        }
        foreach (['organization-update-notes.php', 'organizations_upload.php'] as $file) {
            copy($root . '/src/controllers/organization/' . $file, $temporary . '/src/controllers/organization/' . $file);
        }
        copy($root . '/src/utils/acl.php', $temporary . '/src/utils/acl.php');
        file_put_contents($temporary . '/src/config/db.php', '<?php $pdo = new PDO("sqlite:" . __DIR__ . "/../../data.sqlite"); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);');
        file_put_contents($temporary . '/src/utils/csrf.php', '<?php function csrf_validate(): bool { return true; }');
        file_put_contents($temporary . '/src/utils/acl_middleware.php', '<?php function deny_response(string $page): void { http_response_code(403); exit("denied"); }');
        $pdo = new PDO('sqlite:' . $temporary . '/data.sqlite');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT);
            INSERT INTO users VALUES (1,'owner'),(2,'member');
            CREATE TABLE organizations (id INTEGER PRIMARY KEY, notes TEXT);
            INSERT INTO organizations VALUES (10,'original');");
        file_put_contents($temporary . '/driver.php', <<<'PHP'
<?php
session_start();
$_SESSION['user'] = ['id' => (int)$argv[2], 'role' => $argv[3]];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['id' => 10, 'notes' => 'updated'];
register_shutdown_function(static function (): void {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data.sqlite');
    echo '|status=' . (http_response_code() ?: 200);
    echo '|notes=' . $pdo->query('SELECT notes FROM organizations WHERE id=10')->fetchColumn();
});
require __DIR__ . '/src/controllers/organization/' . $argv[1];
PHP);
        return $temporary;
    }

    private function runController(string $directory, string $file, int $userId, string $role): string
    {
        $process = proc_open([PHP_BINARY, $directory . '/driver.php', $file, (string)$userId, $role],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        return $output;
    }
}
