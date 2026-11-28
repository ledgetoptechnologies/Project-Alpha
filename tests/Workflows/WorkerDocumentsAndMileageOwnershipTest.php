<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WorkerDocumentsAndMileageOwnershipTest extends TestCase
{
    private function source(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($path, '/'));
        self::assertIsString($contents, 'Unable to read ' . $path);
        return $contents;
    }

    public function testPersonnelDocumentsAreImmutableAuditedWorkerRecords(): void
    {
        $migration = $this->source('database/migrations/0044_worker_documents_and_mileage_ownership.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS worker_documents', $migration);
        self::assertStringContainsString('worker_name_snapshot', $migration);
        self::assertStringContainsString('content_sha256 CHAR(64) NOT NULL', $migration);
        self::assertStringContainsString("ENUM('current','archived')", $migration);
        self::assertStringContainsString('version_number INT UNSIGNED', $migration);

        $controller = $this->source('src/controllers/accounts/worker_documents.php');
        self::assertStringContainsString('validate_and_store_upload', $controller);
        self::assertStringContainsString("reject_pdf_active_content' => true", $controller);
        self::assertStringContainsString('worker_document.archived', $controller);
        self::assertStringNotContainsString('DELETE FROM worker_documents', $controller);

        $view = $this->source('src/views/pages/auth/account-edit.php');
        self::assertStringContainsString('Personnel Documents &amp; Agreements', $view);
        self::assertStringContainsString('Equipment use agreement', $this->source('src/utils/worker_documents.php'));
        self::assertStringContainsString('Archiving preserves the original file and audit history', $view);
    }

    public function testWorkerDocumentRoutesArePermissionedAndPrivate(): void
    {
        $router = $this->source('public/index.php');
        self::assertStringContainsString("'worker-documents'", $router);
        self::assertStringContainsString("'worker-document-download'", $router);

        $acl = $this->source('src/utils/acl_middleware.php');
        self::assertStringContainsString("'worker-documents'       => 'users.manage'", $acl);

        $download = $this->source('src/controllers/accounts/worker_document_download.php');
        self::assertStringContainsString("'Cache-Control: private, no-store'", $download);
        self::assertStringContainsString('worker_document_absolute_path', $download);
        self::assertStringContainsString('worker_visible', $download);
    }

    public function testMileageSeparatesRecorderAndTraveler(): void
    {
        $migration = $this->source('database/migrations/0044_worker_documents_and_mileage_ownership.sql');
        self::assertStringContainsString('recorded_by_user_id', $migration);
        self::assertStringContainsString('traveler_user_id', $migration);

        $handler = $this->source('src/controllers/financial/mileage_handler.php');
        self::assertStringContainsString('recorded_by_user_id,traveler_user_id', $handler);

        $list = $this->source('src/utils/mileage.php');
        self::assertStringContainsString("\$driver = \$allDrivers ? (string)(\$filters['driver'] ?? 'mine') : 'mine';", $list);
        self::assertStringContainsString('traveler_user_id', $list);
        self::assertStringContainsString("\$driverContext='My mileage';", $this->source('src/views/pages/financial/mileage-list.php'));
    }

    public function testGoogleEmailRemainsOutboundOnly(): void
    {
        $settings = $this->source('src/views/pages/settings/system.php');
        self::assertStringContainsString('Sign in with Google to send email', $settings);
        self::assertStringContainsString('This affects outgoing email only', $settings);

        $oauth = $this->source('src/controllers/settings/gmail_oauth.php');
        self::assertStringContainsString("'scope' => 'openid email https://www.googleapis.com/auth/gmail.send'", $oauth);
        self::assertStringContainsString("'code_challenge_method' => 'S256'", $oauth);
    }
}
