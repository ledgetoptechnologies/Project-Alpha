<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/upload_access.php';

use PHPUnit\Framework\TestCase;

final class UploadFinancialAccessTest extends TestCase
{
    public function testKnownFilenameDoesNotGrantAnotherUserAccessToFinancialUploads(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
        $pdo->exec('CREATE TABLE user_permissions_overrides (user_id INTEGER, organization_id INTEGER, permission TEXT, allowed INTEGER)');
        $pdo->exec('CREATE TABLE receipts (id INTEGER PRIMARY KEY, organization_id INTEGER, uploaded_by INTEGER, file_path TEXT)');
        $pdo->exec('CREATE TABLE form_documents (id INTEGER PRIMARY KEY, organization_id INTEGER, uploaded_by INTEGER, file_path TEXT)');
        $pdo->exec("INSERT INTO users VALUES (501, 'member'), (502, 'member')");
        $pdo->exec("INSERT INTO user_permissions_overrides VALUES (501, NULL, 'financial.view', 1), (502, NULL, 'financial.view', 1)");
        $pdo->exec("INSERT INTO receipts VALUES (1, 7, 501, '/src/uploads/receipts/2026/10/private.pdf')");
        $pdo->exec("INSERT INTO form_documents VALUES (2, 7, 501, '/src/uploads/forms/tax/private.pdf')");
        $_SESSION['user'] = ['id' => 501, 'role' => 'member'];

        foreach (['receipts' => '/src/uploads/receipts/2026/10/private.pdf',
            'forms' => '/src/uploads/forms/tax/private.pdf'] as $category => $path) {
            self::assertTrue(upload_financial_file_accessible($pdo, $category, $path, 501, 7));
            self::assertFalse(upload_financial_file_accessible($pdo, $category, $path, 502, 7));
            self::assertFalse(upload_financial_file_accessible($pdo, $category, $path, 501, 8));
            self::assertFalse(upload_financial_file_accessible($pdo, $category, $path . '.other', 501, 7));
        }
        self::assertFalse(upload_financial_file_accessible($pdo, 'unknown', '/src/uploads/receipts/2026/10/private.pdf', 501));
        unset($_SESSION['user']);
    }
}
