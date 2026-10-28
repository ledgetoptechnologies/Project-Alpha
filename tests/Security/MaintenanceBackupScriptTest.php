<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MaintenanceBackupScriptTest extends TestCase
{
    public function testBackupAndRestoreKeepCredentialsOutOfCommandArguments(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['db_backup.sh', 'db_restore.sh'] as $script) {
            $source = (string)file_get_contents($root . '/tools/' . $script);
            self::assertStringContainsString('umask 077', $source);
            self::assertStringContainsString('MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec', $source);
            self::assertStringContainsString('--passphrase-fd 3', $source);
            self::assertStringNotContainsString('-p"${MYSQL_ROOT_PASSWORD}"', $source);
            self::assertStringNotContainsString('--passphrase "${BACKUP_ENCRYPTION_KEY}"', $source);
        }
    }

    public function testRestoreVerifiesSafetyCopyBeforeChangingDatabase(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/tools/db_restore.sh');
        self::assertStringContainsString('SAFETY="${SAFETY}.gpg"', $source);
        self::assertLessThan(strpos($source, 'echo "Restoring..."'), strpos($source, 'safety dump verification failed; restore was not started'));
        self::assertLessThan(strpos($source, 'echo "Restoring..."'), strpos($source, 'encrypted safety dump verification failed; restore was not started'));
    }
}
