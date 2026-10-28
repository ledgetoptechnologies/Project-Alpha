<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/backup_archive.php';

final class BackupArchiveEncryptionTest extends TestCase
{
    public function testFullBackupRefusesMissingEncryptionKeyBeforeOpeningArchive(): void
    {
        $cron = (string)file_get_contents(dirname(__DIR__, 2) . '/src/cron/backup_database.php');
        self::assertLessThan(strpos($cron, 'gzopen($filepath'), strpos($cron, "\$backupMode === 'full' && \$encryptionKey === ''"));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Full backups require BACKUP_ENCRYPTION_KEY');
        backup_create_archive('missing.sql.gz', 'missing.zip', true, '');
    }

    public function testDatabaseOnlyArchiveCanRemainUnencrypted(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('PHP zip extension is unavailable.');
        }
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pa-backup-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $dump = $root . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $archive = $root . DIRECTORY_SEPARATOR . 'database.zip';
        try {
            file_put_contents($dump, 'test database contents');
            backup_create_archive($dump, $archive, false, '');
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive) === true);
            self::assertSame('test database contents', $zip->getFromName('database.sql.gz'));
            $zip->close();
        } finally {
            @unlink($archive);
            @unlink($dump);
            @rmdir($root);
        }
    }

    public function testConfigurationEntriesInFullArchiveRequirePassword(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('PHP zip extension is unavailable.');
        }
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pa-backup-test-' . bin2hex(random_bytes(8));
        $config = $root . DIRECTORY_SEPARATOR . 'config';
        mkdir($root, 0700);
        mkdir($config, 0700);
        $archive = $root . DIRECTORY_SEPARATOR . 'full.zip';
        try {
            file_put_contents($config . DIRECTORY_SEPARATOR . '.encryption_key', 'sensitive-key');
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
            backup_add_tree($zip, $config, 'config', 'backup-passphrase');
            $zip->close();

            self::assertTrue($zip->open($archive) === true);
            self::assertFalse($zip->getFromName('config/.encryption_key'));
            $zip->setPassword('backup-passphrase');
            self::assertSame('sensitive-key', $zip->getFromName('config/.encryption_key'));
            $zip->close();
        } finally {
            @unlink($archive);
            @unlink($config . DIRECTORY_SEPARATOR . '.encryption_key');
            @rmdir($config);
            @rmdir($root);
        }
    }
}
