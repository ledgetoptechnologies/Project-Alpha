<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/utils/document_organization.php';
require_once __DIR__ . '/../../src/utils/acl.php';

final class DocumentOrganizationResolutionTest extends TestCase
{
    private PDO $pdo;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, organization_id INTEGER)');
        $this->pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, organization_id INTEGER)');
        foreach (['quotes', 'contracts', 'invoices'] as $table) {
            $this->pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, client_id INTEGER, project_id INTEGER, organization_id INTEGER)");
        }
        $this->pdo->exec('CREATE TABLE project_invoices (id INTEGER PRIMARY KEY, primary_client_id INTEGER, project_id INTEGER, organization_id INTEGER)');
        $this->pdo->exec("INSERT INTO organizations VALUES (1,'Legacy Wrong Org'),(2,'Project Customer')");
        $this->pdo->exec('INSERT INTO clients VALUES (10,2),(11,2)');
        $this->pdo->exec('INSERT INTO projects VALUES (20,2)');
    }

    public function testProjectOrganizationOverridesStaleDocumentOrganizationEverywhere(): void
    {
        foreach (['quotes' => 'quote', 'contracts' => 'contract', 'invoices' => 'invoice'] as $table => $type) {
            $this->pdo->exec("INSERT INTO {$table} VALUES (100,10,20,1)");
            self::assertSame(2, pa_document_effective_organization_id($this->pdo, $type, 100));
            $row = $this->pdo->query(
                "SELECT o.name FROM {$table} d JOIN clients c ON c.id=d.client_id"
                . pa_document_effective_organization_joins('d', 'c')
                . ' WHERE d.id=100'
            )->fetchColumn();
            self::assertSame('Project Customer', $row);
            $this->pdo->exec("DELETE FROM {$table}");
        }

        $this->pdo->exec('INSERT INTO project_invoices VALUES (200,10,20,1)');
        self::assertSame(2, pa_document_effective_organization_id($this->pdo, 'project_invoice', 200));
    }

    public function testStandaloneDocumentKeepsSavedOrganizationAndUsesClientOnlyAsFallback(): void
    {
        $this->pdo->exec('INSERT INTO invoices VALUES (101,10,NULL,1),(102,10,NULL,NULL)');
        self::assertSame(1, pa_document_effective_organization_id($this->pdo, 'invoice', 101));
        self::assertSame(2, pa_document_effective_organization_id($this->pdo, 'invoice', 102));
    }

    public function testPostedOrganizationCannotOverrideProjectOrClientRelationships(): void
    {
        self::assertSame(2, resolve_client_context_org_id($this->pdo, 10, 20, 1));
        self::assertSame(2, resolve_client_context_org_id($this->pdo, 10, null, 1));
        self::assertSame(1, resolve_client_context_org_id($this->pdo, 0, null, 1));
    }

    public function testAllRenderingAndDerivationPathsUseProjectFirstResolution(): void
    {
        $renderers = [
            'src/views/pages/quote/quote-details.php',
            'src/views/pages/quote/long-term-quote-details.php',
            'src/views/pages/contract/contract-details.php',
            'src/views/pages/contract/long-term-contract-details.php',
            'src/views/pages/invoice/invoice-details.php',
        ];
        foreach ($renderers as $path) {
            self::assertStringContainsString('pa_document_effective_organization_joins', (string)file_get_contents($this->root . '/' . $path), $path);
        }

        foreach ([
            'src/controllers/contract/on_demand_invoice_generate.php',
            'src/controllers/quote/quote_approve.php',
            'src/controllers/public_view/public_quote_action.php',
            'src/utils/recurring_billing.php',
        ] as $path) {
            self::assertStringContainsString('pa_document_effective_organization_id', (string)file_get_contents($this->root . '/' . $path), $path);
        }

        $links = (string)file_get_contents($this->root . '/src/utils/invoice_links.php');
        self::assertStringContainsString(
            "\$invoice['project_organization_id'] ?: \$invoice['organization_id']",
            $links
        );
        $projectInvoice = (string)file_get_contents($this->root . '/src/views/pages/project/project-invoice-details.php');
        self::assertStringContainsString('COALESCE(p.organization_id, pi.organization_id)', $projectInvoice);
    }
}
