<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceVoidWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testInvoiceVoidLifecycleIsRoutedAndPermissioned(): void
    {
        $router = (string)file_get_contents($this->root . '/public/index.php');
        $acl = (string)file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $audit = (string)file_get_contents($this->root . '/src/utils/audit_middleware.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/invoice/invoice_void.php');
        $reenable = (string)file_get_contents($this->root . '/src/controllers/invoice/invoice_reenable.php');

        self::assertStringContainsString("'invoice/invoice-void'", $router);
        self::assertStringContainsString("'invoice/invoice-reenable'", $router);
        self::assertStringContainsString("'invoice/invoice-void'      => 'invoices.void'", $acl);
        self::assertStringContainsString("'invoice/invoice-reenable'  => 'invoices.void'", $acl);
        self::assertStringContainsString("'invoice/invoice-void'", $audit);
        self::assertStringContainsString("require_record_ownership(\$pdo, 'invoices', \$id)", $controller);
        self::assertStringContainsString('invoice_void($pdo, $id, $appConfig, $reason, $userId)', $controller);
        self::assertStringContainsString('invoice_reenable_void($pdo, $id)', $reenable);
    }

    public function testVoidMetadataAndActionsAreVisibleButAccountingHistoryIsPreserved(): void
    {
        $baseline = (string)file_get_contents($this->root . '/database/baseline.sql');
        $migration = (string)file_get_contents($this->root . '/database/migrations/0030_invoice_void_workflow.sql');
        $lifecycle = (string)file_get_contents($this->root . '/src/utils/invoice_lifecycle.php');
        $details = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoice-details.php');
        $list = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoices-list.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/invoice/invoice_void.php');

        foreach (['voided_at', 'voided_by', 'void_reason', 'void_previous_status'] as $column) {
            self::assertStringContainsString($column, $baseline);
            self::assertStringContainsString($column, $migration);
        }
        self::assertStringContainsString('function invoice_void(', $lifecycle);
        self::assertStringContainsString('Paid or partially paid invoices cannot be voided', $lifecycle);
        self::assertStringContainsString('project invoice PI-', $lifecycle);
        self::assertStringContainsString('pa_public_link_terminalize($pdo, \'invoice\', $invoiceId, \'void\', true)', $lifecycle);
        self::assertStringContainsString('UPDATE time_entries SET billed=0', $lifecycle);
        self::assertStringContainsString('function invoice_reenable_void(', $lifecycle);
        self::assertStringContainsString('/?page=invoice/invoice-void', $details);
        self::assertStringContainsString('Reason for voiding', $details);
        self::assertStringContainsString('View PDF', $details);
        self::assertStringContainsString('Void reason', $details);
        self::assertStringContainsString('Confirm Void Invoice', $list);
        self::assertStringContainsString('name="redirect_to"', $list);
        self::assertStringContainsString('class="invoice-void-dialog"', $list);
        self::assertStringContainsString('.showModal()', $list);
        self::assertStringContainsString('Fully refunded zero-balance history will not block voiding.', $list);
        self::assertStringContainsString("!str_starts_with(\$redirectTo, '//')", $controller);
        self::assertStringContainsString("\$appendResult(\$redirectBase, 'voided', '1')", $controller);
    }

    public function testRecurringHistoryActionPreservesEveryFilterAndScrollsToResults(): void
    {
        // Recurring invoice history is the unified invoice list on its Recurring tab,
        // optionally narrowed to one contract.
        $history = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoices-list.php');
        $contractDetails = (string)file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php');
        $servicePanel = (string)file_get_contents($this->root . '/src/views/components/recurring_service_panel.php');
        $navigation = (string)file_get_contents($this->root . '/public/assets/navigation.js');

        self::assertStringContainsString('/?page=invoice/invoices-list&kind=recurring&contract_id=<?php echo (int)$id; ?>', $contractDetails);
        self::assertStringContainsString('/?page=invoice/invoices-list&amp;kind=recurring&amp;contract_id=<?= (int)$id ?>', $servicePanel);
        self::assertStringContainsString("'i.contract_id = ?'", $history);
        self::assertStringContainsString('Showing invoices for', $history);
        self::assertStringContainsString("doc_list_url(\$page, ['contract_id' => null, 'p' => null])", $history);
        self::assertStringContainsString('/?page=invoice/invoice-details&amp;id=', $history);
        self::assertStringContainsString("const rest = separatorIndex >= 0 ? page.slice(separatorIndex + 1) : '';", $navigation);
        self::assertStringContainsString('navigateToPage(fullPage, true, linkUrl.hash)', $navigation);
        self::assertStringContainsString('scrollToPageHash(targetHash)', $navigation);
        self::assertStringContainsString("window.scrollTo({ top: 0, left: 0, behavior: 'auto' });", $navigation);
    }
}
