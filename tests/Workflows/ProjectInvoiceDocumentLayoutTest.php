<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectInvoiceDocumentLayoutTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testStandardAndProjectInvoicesUseSharedPresentationChrome(): void
    {
        $standard = $this->read('src/views/pages/invoice/invoice-details.php');
        $project = $this->read('src/views/pages/project/project-invoice-details.php');

        foreach ([
            "components/document_brand_header.php",
            "components/document_parties.php",
            "components/document_totals.php",
        ] as $partial) {
            self::assertStringContainsString($partial, $standard, $partial);
            self::assertStringContainsString($partial, $project, $partial);
        }

        self::assertStringContainsString('document_sender_for_document($pdo, $appConfig', $project);
        self::assertStringContainsString('$documentPartyRecipient = $billingRecipient;', $project);
        self::assertStringContainsString('invoice_content_links_html($contentLinks)', $project);
        self::assertStringContainsString("'Project Invoice PI-' . \$docNum", $project);
    }

    public function testProjectLayoutRetainsAggregateSnapshotsRecipientsAndPaymentState(): void
    {
        $project = $this->read('src/views/pages/project/project-invoice-details.php');

        foreach ([
            'project_invoice_refresh_status($pdo, $id)',
            'FROM project_invoice_items pii',
            "\$pi['amount_paid']",
            "\$pi['balance_due']",
            'name="recipient_keys[]"',
            'Included Invoices',
            'project/project-invoice-payment',
        ] as $marker) {
            self::assertStringContainsString($marker, $project, $marker);
        }
        $section = $this->read('src/views/components/project_invoice_item.php');
        self::assertStringContainsString("\$item['amount_due_at_generation']", $section);
        self::assertStringContainsString('page-break-inside:avoid', $section);
        self::assertStringContainsString('page-break-inside:auto', $section);
        self::assertSame(1, substr_count($project, 'foreach ($items as '), 'Render each invoice in a single grouped section.');
        self::assertStringContainsString('components/project_invoice_item.php', $project);
        self::assertStringNotContainsString("['label' => 'Subtotal'", $project, 'Statement balances already include child taxes and discounts.');
    }

    public function testProjectPdfUsesStatementPeriodEndAndDocumentNumber(): void
    {
        $pdf = $this->read('src/controllers/project/project_invoice_pdf.php');

        self::assertStringContainsString('SELECT project_id,doc_number,billing_period_end FROM project_invoices', $pdf);
        self::assertStringContainsString("strtotime((string)\$projectInvoice['billing_period_end'])", $pdf);
        self::assertStringContainsString("\$documentNumber = (int)(\$projectInvoice['doc_number'] ?? 0) ?: \$id;", $pdf);
        self::assertStringContainsString("'project-invoice_PI-' . \$documentNumber . '.pdf'", $pdf);
        self::assertStringNotContainsString("page_text(54, 22, date('m/d/Y')", $pdf);
    }

    public function testSharedChromeRendersEscapedNormalizedFixtureData(): void
    {
        require_once $this->root . '/src/utils/format.php';
        require_once $this->root . '/src/utils/document_sender.php';

        $appConfig = ['brand_name' => 'Alpha & Company', 'logo_path' => '/missing-test-logo.png'];
        $documentBrandLabel = 'Project Invoice PI-42';
        $documentBrandMetaLines = ['Project North <Campus>', 'Billing Period Jul 1, 2026 - Jul 31, 2026'];
        ob_start();
        require $this->root . '/src/views/components/document_brand_header.php';
        $header = (string)ob_get_clean();

        $documentPartySender = [
            'name' => 'Sender <Admin>',
            'company' => 'Alpha & Company',
            'phone' => '9205550100',
            'email' => 'billing@example.test',
        ];
        $documentPartyRecipient = [
            'lines' => ['Customer <One>', '1 Main Street'],
            'phone' => '9205550199',
            'email' => 'customer@example.test',
        ];
        ob_start();
        require $this->root . '/src/views/components/document_parties.php';
        $parties = (string)ob_get_clean();

        $documentTotalRows = [
            ['label' => 'Subtotal', 'value' => '$125.00'],
            ['label' => 'Amount Due', 'value' => '$100.00', 'tone' => 'due'],
        ];
        ob_start();
        require $this->root . '/src/views/components/document_totals.php';
        $totals = (string)ob_get_clean();

        self::assertStringContainsString('Alpha &amp; Company', $header);
        self::assertStringContainsString('Project North &lt;Campus&gt;', $header);
        self::assertStringContainsString('Project Invoice PI-42', $header);
        self::assertStringContainsString('Sender &lt;Admin&gt;', $parties);
        self::assertStringContainsString('Customer &lt;One&gt;', $parties);
        self::assertStringContainsString('From', $parties);
        self::assertStringContainsString('To', $parties);
        self::assertStringContainsString('Amount Due', $totals);
        self::assertStringContainsString('$100.00', $totals);
        self::assertStringContainsString('border-top:2px solid #f59e0b', $totals);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, 'Expected readable source: ' . $path);
        return $contents;
    }
}
