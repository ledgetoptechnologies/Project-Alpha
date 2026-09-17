<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContractScopeWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testEveryContractCreationPathPersistsScope(): void
    {
        foreach ([
            'contracts_create.php',
            'long_term_contracts_create.php',
            'on_demand_contracts_create.php',
        ] as $controller) {
            $source = $this->read('src/controllers/contract/' . $controller);
            self::assertStringContainsString("\$_POST['scope']", $source, $controller);
            self::assertMatchesRegularExpression('/INSERT INTO contracts[\\s\\S]*?scope/i', $source, $controller);
        }

        $regular = $this->read('src/controllers/contract/contracts_create.php');
        self::assertMatchesRegularExpression('/INSERT INTO invoices[\\s\\S]*?scope/i', $regular);
    }

    public function testEditRoundTripLoadsAndUpdatesScope(): void
    {
        $edit = $this->read('src/views/pages/contract/contracts-edit.php');
        self::assertStringContainsString('SELECT co.*', $edit);
        self::assertStringContainsString('name="scope"', $edit);
        self::assertStringContainsString("\$contract['scope']", $edit);

        $update = $this->read('src/controllers/contract/contracts_update.php');
        self::assertStringContainsString("\$_POST['scope']", $update);
        self::assertStringContainsString('scope=?', $update);
    }

    public function testPdfAndPublicLinksUseScopeAwareContractViews(): void
    {
        $pdf = $this->read('src/controllers/contract/contract_pdf.php');
        self::assertStringContainsString('long-term-contract-details.php', $pdf);
        self::assertStringContainsString('contract-details.php', $pdf);

        $public = $this->read('src/views/public/doc-wrapper.php');
        self::assertStringContainsString('long-term-contract-details.php', $public);
        self::assertStringContainsString('contract-details.php', $public);

        foreach ([
            'contract-details.php',
            'long-term-contract-details.php',
        ] as $view) {
            $source = $this->read('src/views/pages/contract/' . $view);
            self::assertStringContainsString("trim((string)(\$contract['scope'] ?? ''))", $source, $view);
            self::assertStringContainsString('contract_scope_enabled', $source, $view);
            self::assertStringContainsString("\$scopeText !== ''", $source, $view);
            self::assertStringContainsString('Scope of Work', $source, $view);
        }
    }

    public function testScopeSettingDescribesAllSupportedContractTypes(): void
    {
        $settings = $this->read('src/views/pages/settings/documents.php');
        self::assertStringContainsString('regular, long-term, and on-demand contracts', $settings);
        self::assertStringContainsString('excluded from PDF and public links', $settings);
    }

    private function read(string $path): string
    {
        $source = file_get_contents($this->root . '/' . $path);
        self::assertNotFalse($source, $path);
        return $source;
    }
}
