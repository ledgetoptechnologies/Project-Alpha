<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DocumentPdfRouteParityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRouterDispatchesEverySupportedDocumentPdfRoute(): void
    {
        $router = $this->read('public/index.php');
        $acl = $this->read('src/utils/acl_middleware.php');

        $canonicalRoutes = [
            'quote/quote-pdf' => 'quotes.view',
            'quote/long-term-quote-pdf' => 'quotes.view',
            'contract/contract-pdf' => 'contracts.view',
            'contract/long-term-contract-pdf' => 'contracts.view',
            'invoice/invoice-pdf' => 'invoices.view',
            'project/project-invoice-pdf' => 'projects.view',
        ];
        foreach ($canonicalRoutes as $route => $permission) {
            self::assertStringContainsString("'{$route}'", $router, "Missing PDF dispatcher route: {$route}");
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($route, '/') . "'\\s*=>\\s*'" . preg_quote($permission, '/') . "'/",
                $acl,
                "Missing {$permission} protection for {$route}"
            );
        }

        foreach ([
            'quote-pdf' => 'quotes.view',
            'long-term-quote-pdf' => 'quotes.view',
            'contract-pdf' => 'contracts.view',
            'long-term-contract-pdf' => 'contracts.view',
            'invoice-pdf' => 'invoices.view',
        ] as $alias => $permission) {
            self::assertStringContainsString("'{$alias}'", $router, "Missing PDF compatibility alias: {$alias}");
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($alias, '/') . "'\\s*=>\\s*'" . preg_quote($permission, '/') . "'/",
                $acl,
                "PDF compatibility alias bypasses normal ACL mapping: {$alias}"
            );
        }

        foreach ([
            'quote/long-term-quote-details' => 'quotes.view',
            'contract/long-term-contract-details' => 'contracts.view',
        ] as $route => $permission) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($route, '/') . "'\\s*=>\\s*'" . preg_quote($permission, '/') . "'/",
                $acl,
                "Missing {$permission} protection for {$route}"
            );
        }
    }

    public function testDocumentViewsAndListsUseRoutableDestinations(): void
    {
        $expectedByFile = [
            'src/views/pages/quote/quote-details.php' => 'page=quote/quote-pdf',
            'src/views/pages/quote/long-term-quote-details.php' => 'page=quote/long-term-quote-pdf',
            'src/views/pages/quote/quotes-list.php' => 'page=quote/long-term-quote-pdf',
            'src/views/pages/contract/contract-details.php' => 'page=contract/contract-pdf',
            'src/views/pages/contract/long-term-contract-details.php' => 'page=contract/long-term-contract-pdf',
            'src/views/pages/invoice/invoice-details.php' => 'page=invoice/invoice-pdf',
            'src/views/pages/project/project-invoice-details.php' => 'page=project/project-invoice-pdf',
        ];

        foreach ($expectedByFile as $file => $expectedRoute) {
            self::assertStringContainsString($expectedRoute, $this->read($file), "Invalid document destination in {$file}");
        }
    }

    public function testPdfControllersSelectTheLayoutForTheStoredDocumentType(): void
    {
        $quoteController = $this->read('src/controllers/quote/quote_pdf.php');
        self::assertStringContainsString("(\$doc['quote_type'] ?? '') === 'long_term'", $quoteController);
        self::assertStringContainsString("'/../../views/pages/quote/long-term-quote-details.php'", $quoteController);
        self::assertStringContainsString("'/../../views/pages/quote/quote-details.php'", $quoteController);

        $contractController = $this->read('src/controllers/contract/contract_pdf.php');
        self::assertStringContainsString("(\$doc['contract_type'] ?? '') === 'long_term'", $contractController);
        self::assertStringContainsString("'/../../views/pages/contract/long-term-contract-details.php'", $contractController);
        self::assertStringContainsString("'/../../views/pages/contract/contract-details.php'", $contractController);

        self::assertStringContainsString(
            "'/../../views/pages/invoice/invoice-details.php'",
            $this->read('src/controllers/invoice/invoice_pdf.php')
        );
    }

    public function testProductionCodeHasNoRetiredDocumentPrintDestinations(): void
    {
        $productionFiles = [
            'src/controllers/email_send.php',
            'src/views/pages/client/clients-list.php',
            'src/views/pages/jobs/jobs-list.php',
            'src/views/pages/quote/quotes-list.php',
            'src/views/pages/contract/contracts-list.php',
            'src/views/pages/invoice/invoices-list.php',
        ];

        foreach ($productionFiles as $file) {
            self::assertDoesNotMatchRegularExpression(
                '/page=(?:(?:quote|contract|invoice)\/)?(?:long-term-quote|long-term-contract|quote|contract|invoice)-print/',
                $this->read($file),
                "Retired document route remains in {$file}"
            );
        }
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertNotFalse($contents, "Unable to read {$relativePath}");

        return $contents;
    }
}
