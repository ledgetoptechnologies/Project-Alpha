<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/tax_import_chunk_limits.php';

final class TaxImportChunkLimitsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pa-tax-limit-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            foreach (['upload.part', 'source.csv', 'source.txt', 'meta.json'] as $name) {
                @unlink($dir . DIRECTORY_SEPARATOR . $name);
            }
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    public function testCountsOnlyCurrentUsersActiveUploadsAndDeletesStaleFiles(): void
    {
        $now = time();
        $active = str_repeat('a', 32);
        $stale = str_repeat('b', 32);
        $foreign = str_repeat('c', 32);
        $this->upload($active, 7, $now, '12345');
        $this->upload($stale, 7, $now - TAX_IMPORT_CHUNK_TTL_SECONDS - 1, '12345678');
        $this->upload($foreign, 8, $now, '123456789');

        tax_import_chunk_cleanup_stale($this->root, $now);
        self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . $stale);
        self::assertDirectoryExists($this->root . DIRECTORY_SEPARATOR . $active);
        self::assertDirectoryExists($this->root . DIRECTORY_SEPARATOR . $foreign);
        self::assertSame(['count' => 1, 'bytes' => 5, 'current_bytes' => 5], tax_import_chunk_usage($this->root, 7, $active));
        self::assertSame(['count' => 1, 'bytes' => 9, 'current_bytes' => 0], tax_import_chunk_usage($this->root, 8, $active));
    }

    public function testRejectsOversizedFileTooManyUploadIdsAndCumulativeBytes(): void
    {
        $usage = ['count' => 2, 'bytes' => 100, 'current_bytes' => 0];
        self::assertTrue(tax_import_chunk_within_quota($usage, true, 0, 10, 10));
        self::assertFalse(tax_import_chunk_within_quota(array_replace($usage, ['count' => 3]), true, 0, 10, 10));
        self::assertFalse(tax_import_chunk_within_quota($usage, true, 0, 10, TAX_IMPORT_MAX_FILE_BYTES + 1));
        self::assertFalse(tax_import_chunk_within_quota($usage, true, 0, 11, 10));
        $nearLimit = ['count' => 1, 'bytes' => TAX_IMPORT_MAX_USER_BYTES - 5, 'current_bytes' => 0];
        self::assertFalse(tax_import_chunk_within_quota($nearLimit, true, 0, 6, 10));
    }

    private function upload(string $id, int $userId, int $updatedAt, string $contents): void
    {
        $dir = $this->root . DIRECTORY_SEPARATOR . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'upload.part', $contents);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'meta.json', json_encode(['user_id' => $userId, 'updated_at' => $updatedAt]));
    }
}
