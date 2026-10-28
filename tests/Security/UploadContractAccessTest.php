<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/upload_access.php';

use PHPUnit\Framework\TestCase;

final class UploadContractAccessTest extends TestCase
{
    public function testKnownSignedDocumentPathRequiresContractOwnership(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
        $pdo->exec('CREATE TABLE user_permissions_overrides (user_id INTEGER, organization_id INTEGER, permission TEXT, allowed INTEGER)');
        $pdo->exec('CREATE TABLE contracts (id INTEGER PRIMARY KEY, created_by INTEGER, signed_pdf_path TEXT)');
        $pdo->exec('CREATE TABLE contract_amendments (id INTEGER PRIMARY KEY, contract_id INTEGER, signed_document_path TEXT)');
        $pdo->exec("INSERT INTO users VALUES (601, 'member'), (602, 'member')");
        $pdo->exec("INSERT INTO user_permissions_overrides VALUES (601, NULL, 'contracts.view', 1), (602, NULL, 'contracts.view', 1)");
        $signed = 'signed_contracts/private.pdf';
        $amendment = 'contract_amendments/private.pdf';
        $pdo->prepare('INSERT INTO contracts VALUES (1, 601, ?)')->execute(['/?page=serve-upload&file=' . rawurlencode($signed)]);
        $pdo->prepare('INSERT INTO contract_amendments VALUES (1, 1, ?)')->execute(['/?page=serve-upload&file=' . rawurlencode($amendment)]);
        $_SESSION['user'] = ['id' => 601, 'role' => 'member'];

        foreach (['signed_contracts' => $signed, 'contract_amendments' => $amendment] as $category => $path) {
            self::assertTrue(upload_contract_file_accessible($pdo, $category, $path, 601));
            self::assertFalse(upload_contract_file_accessible($pdo, $category, $path, 602));
            self::assertFalse(upload_contract_file_accessible($pdo, $category, $path . '.other', 601));
        }
        self::assertFalse(upload_contract_file_accessible($pdo, 'unknown', $signed, 601));
        unset($_SESSION['user']);
    }
}
