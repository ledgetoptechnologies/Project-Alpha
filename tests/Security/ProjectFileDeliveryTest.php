<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/project_files.php';

final class ProjectFileDeliveryTest extends TestCase
{
    public function testActiveAndUnknownTypesCannotRenderInline(): void
    {
        foreach (['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/xml', 'text/xml', 'application/javascript', 'application/octet-stream'] as $mime) {
            $policy = project_files_delivery_policy($mime, false);
            self::assertSame('attachment', $policy['disposition'], $mime);
            self::assertSame('application/octet-stream', $policy['content_type'], $mime);
            self::assertFalse($policy['safe_inline'], $mime);
        }
    }

    public function testPassiveTypesRemainPreviewableAndExplicitDownloadsStayAttachments(): void
    {
        foreach (['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'text/plain', 'text/csv'] as $mime) {
            self::assertSame('inline', project_files_delivery_policy($mime, false)['disposition'], $mime);
            self::assertSame('attachment', project_files_delivery_policy($mime, true)['disposition'], $mime);
        }
    }

    public function testDisguisedHtmlUsesDetectedContentType(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pa-html-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, '<!doctype html><script>document.body.dataset.x = 1</script>');
            $mime = project_files_detect_mime($path, 'text/plain');
            self::assertSame('text/html', $mime);
            self::assertSame('attachment', project_files_delivery_policy($mime, false)['disposition']);
        } finally {
            unlink($path);
        }
    }

    public function testStaffAndPublicDeliveryUseTheSamePolicy(): void
    {
        $root = dirname(__DIR__, 2) . '/src/controllers/';
        foreach (['project/project_file_download.php', 'public_view/public_project_file.php'] as $controller) {
            $source = (string)file_get_contents($root . $controller);
            self::assertStringContainsString('project_files_delivery_policy($mimeType, $download)', $source);
            self::assertStringContainsString("Content-Security-Policy: sandbox; default-src 'none'", $source);
        }
    }
}
