<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/csv.php';

final class CsvFormulaNeutralizationTest extends TestCase
{
    public function testSharedWriterNeutralizesFormulaPrefixesWithoutChangingNumericCells(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        try {
            csv_write_row($stream, [
                '=HYPERLINK("https://example.test")',
                "\t+cmd", "\r\n@SUM(1,2)", ' -not-a-number', '-12.50', 42, 'ordinary text',
            ]);
            rewind($stream);
            self::assertSame([
                "'=HYPERLINK(\"https://example.test\")",
                "'\t+cmd", "'\r\n@SUM(1,2)", "' -not-a-number", '-12.50', '42', 'ordinary text',
            ], fgetcsv($stream, 0, ',', '"', ''));
        } finally {
            fclose($stream);
        }
    }

    public function testFinancialExportsUseTheSharedWriter(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['audit_export.php', 'expense_export.php'] as $file) {
            $source = (string)file_get_contents($root . '/src/controllers/financial/' . $file);
            self::assertStringContainsString('csv_write_row(', $source, $file);
            self::assertStringNotContainsString('fputcsv(', $source, $file);
        }
    }
}
