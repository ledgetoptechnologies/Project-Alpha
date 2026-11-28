<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/views/components/document_chain.php';

final class DocumentChainTest extends TestCase
{
    private function chain(string $current, array $overrides = []): array
    {
        return $overrides + [
            'current' => $current,
            'quote' => ['id' => 5, 'doc_number' => 1042, 'quote_type' => 'long_term', 'status' => 'approved', 'client_id' => 1],
            'contract' => ['id' => 9, 'doc_number' => 311, 'contract_type' => 'long_term', 'status' => 'active', 'quote_id' => 5, 'client_id' => 1],
            'invoice' => null,
            'invoices' => [
                ['id' => 20, 'doc_number' => 7, 'invoice_type' => 'long_term', 'status' => 'paid', 'total' => '1200.00'],
                ['id' => 21, 'doc_number' => 8, 'invoice_type' => 'long_term', 'status' => 'unpaid', 'total' => '1200.00'],
            ],
            'paid' => 1200.0,
            'payment_count' => 1,
        ];
    }

    public function testRecurringContractLinksEveryStep(): void
    {
        $html = doc_chain_html($this->chain('contract'));

        self::assertStringContainsString('href="/?page=quote/quote-details&amp;id=5"', $html);
        self::assertStringContainsString('LQ-1042', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('LTC-311', $html);
        self::assertStringNotContainsString('page=contract/long-term-contract-details', $html, 'The current step is not a link.');
        self::assertStringContainsString('page=invoice/invoices-list&amp;kind=recurring&amp;contract_id=9', $html);
        self::assertStringContainsString('1 of 2 paid', $html);
        self::assertStringContainsString('$1,200.00', $html);
        self::assertStringContainsString('of $2,400.00', $html);
    }

    public function testQuoteWithoutContractShowsWhatComesNext(): void
    {
        $html = doc_chain_html($this->chain('quote', [
            'quote' => ['id' => 3, 'doc_number' => 12, 'quote_type' => null, 'status' => 'pending', 'client_id' => 1],
            'contract' => null,
            'invoices' => [],
            'paid' => 0.0,
            'payment_count' => 0,
        ]));

        self::assertStringContainsString('Q-12', $html);
        self::assertStringContainsString('Not yet', $html);
        self::assertStringContainsString('After approval', $html);
        self::assertStringContainsString('None yet', $html);
    }

    public function testStandaloneInvoiceHasNoQuoteOrContract(): void
    {
        $invoice = ['id' => 30, 'doc_number' => 4, 'invoice_type' => 'regular', 'status' => 'sent', 'total' => '300.00'];
        $html = doc_chain_html($this->chain('invoice', [
            'quote' => null, 'contract' => null, 'invoice' => $invoice, 'invoices' => [$invoice], 'paid' => 0.0, 'payment_count' => 0,
        ]));

        self::assertStringContainsString('Created directly', $html);
        self::assertStringNotContainsString('Not yet', $html, 'A standalone invoice never gets a contract.');
        self::assertStringContainsString('I-4', $html);
        self::assertStringNotContainsString('page=invoice/invoice-details', $html);
    }

    public function testEveryDetailPageRendersTheChainOutsidePdfs(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'quote/quote-details' => 'quote', 'quote/long-term-quote-details' => 'quote',
            'contract/contract-details' => 'contract', 'contract/long-term-contract-details' => 'contract',
            'invoice/invoice-details' => 'invoice',
        ] as $page => $type) {
            $source = (string)file_get_contents("{$root}/src/views/pages/{$page}.php");
            self::assertStringContainsString("doc_chain_render(\$pdo, '{$type}', \$id)", $source, $page);
        }
        $component = (string)file_get_contents("{$root}/src/views/components/document_chain.php");
        self::assertStringContainsString("defined('PDF_MODE') || defined('PUBLIC_VIEW')", $component);
    }
}
