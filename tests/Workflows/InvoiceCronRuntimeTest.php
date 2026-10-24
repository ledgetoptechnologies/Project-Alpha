<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceCronRuntimeTest extends TestCase
{
    public function testBothCronImagesInstallPdfRuntimeExtensions(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['Dockerfile', 'cron/Dockerfile'] as $path) {
            $dockerfile = (string)file_get_contents($root . '/' . $path);
            if ($path === 'Dockerfile') {
                $stages = preg_split('/^FROM\s+\S+\s+AS\s+cron\s*$/mi', $dockerfile, 2);
                self::assertCount(2, $stages, 'Canonical cron stage must exist.');
                $dockerfile = $stages[1];
            }
            self::assertStringContainsString('docker-php-ext-configure gd --with-freetype --with-jpeg', $dockerfile, $path);
            self::assertMatchesRegularExpression('/docker-php-ext-install[^\n]*\bgd\s+mbstring\b/', $dockerfile, $path);
        }
    }

    public function testPdfRendererSupportsTransparentPngLogos(): void
    {
        self::assertTrue(extension_loaded('gd'), 'Invoice PDFs with transparent PNG logos require GD.');
        self::assertTrue(extension_loaded('mbstring'), 'Invoice PDFs require mbstring.');
        require_once dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/lib/Cpdf.php';
        $renderer = new Dompdf\Dompdf();
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLttAAAAABJRU5ErkJggg==';
        $renderer->loadHtml('<p>Invoice</p><img src="data:image/png;base64,' . $png . '" width="32" height="32">');
        $renderer->render();
        self::assertStringStartsWith('%PDF-', $renderer->output());
        self::assertGreaterThan(500, strlen($renderer->output()));
    }
}
