<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/public_project_links.php';

final class PublicProjectDocumentReadOnlyTest extends TestCase
{
    public function testProjectDocumentUrlCarriesOnlyProjectAuthority(): void
    {
        $token = str_repeat('a', 48);
        $url = pa_project_public_document_url($token, 'contract', 7, []);
        self::assertStringContainsString('page=public-project-doc', (string)$url);
        self::assertStringContainsString('token=' . $token, (string)$url);
        self::assertStringNotContainsString('page=public-doc', (string)$url);
        self::assertNull(pa_project_public_document_url('bad', 'contract', 7, []));
    }

    public function testReadOnlyEligibilityRequiresProjectBindingAndSpecificCapability(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, project_id INTEGER, status TEXT);
            INSERT INTO contracts VALUES (7,1,'pending'),(8,2,'pending'),(9,1,'void'),(12,1,'draft');
            CREATE TABLE invoices (id INTEGER PRIMARY KEY, project_id INTEGER, status TEXT, finalized_at TEXT, collection_mode TEXT);
            INSERT INTO invoices VALUES (10,1,'sent','2026-01-01','direct'),(11,1,'draft',NULL,'direct');
            CREATE TABLE quotes (id INTEGER PRIMARY KEY, project_id INTEGER, status TEXT);
            INSERT INTO quotes VALUES (13,1,'draft'),(14,1,'pending');
            CREATE TABLE project_invoices (id INTEGER PRIMARY KEY, project_id INTEGER, status TEXT, finalized_at TEXT);");
        $project = ['id' => 1, 'public_project_can_view_documents' => 1, 'public_project_can_view_invoices' => 0];

        self::assertTrue(pa_project_public_document_eligible($pdo, $project, 'contract', 7));
        self::assertFalse(pa_project_public_document_eligible($pdo, $project, 'contract', 8));
        self::assertFalse(pa_project_public_document_eligible($pdo, $project, 'contract', 9));
        self::assertFalse(pa_project_public_document_eligible($pdo, $project, 'contract', 12));
        self::assertFalse(pa_project_public_document_eligible($pdo, $project, 'quote', 13));
        self::assertTrue(pa_project_public_document_eligible($pdo, $project, 'quote', 14));
        self::assertFalse(pa_project_public_document_eligible($pdo, $project, 'invoice', 10));
        $project['public_project_can_view_invoices'] = 1;
        self::assertTrue(pa_project_public_document_eligible($pdo, $project, 'invoice', 10));
        self::assertFalse(pa_project_public_document_eligible($pdo, $project, 'invoice', 11));
        $project['public_project_can_view_documents'] = 0;
        self::assertFalse(pa_project_public_document_eligible($pdo, $project, 'contract', 7));
    }

    public function testRevokedAndPasswordedProjectTokensDoNotAuthorizeViewing(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE projects (id INTEGER PRIMARY KEY, client_id INTEGER, organization_id INTEGER, department_id INTEGER,
            public_project_token TEXT, public_project_enabled INTEGER, archived_at TEXT, portal_publish_enabled INTEGER,
            public_project_require_password INTEGER, public_project_password_hash TEXT);
            CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE organization_departments (id INTEGER PRIMARY KEY, name TEXT);
            INSERT INTO projects VALUES (1,NULL,NULL,NULL,'" . str_repeat('b', 48) . "',1,NULL,1,1,'password-hash');");
        $token = str_repeat('b', 48);
        $project = pa_project_public_resolve($pdo, $token);
        self::assertIsArray($project);
        self::assertFalse(pa_project_public_is_unlocked($project, $token));
        $pdo->exec('UPDATE projects SET public_project_enabled=0 WHERE id=1');
        self::assertNull(pa_project_public_resolve($pdo, $token));
    }
}
