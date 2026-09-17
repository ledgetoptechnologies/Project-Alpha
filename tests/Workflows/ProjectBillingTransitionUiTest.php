<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectBillingTransitionUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProjectEditExplainsAndCollectsTheBillingTransitionDecision(): void
    {
        $edit = (string)file_get_contents($this->root . '/src/views/pages/project/projects-edit.php');
        $script = (string)file_get_contents($this->root . '/public/assets/js/project-settings.js');

        self::assertStringContainsString('LEFT JOIN project_invoice_items pii ON pii.invoice_id = i.id', $edit);
        self::assertStringContainsString('pii.invoice_id IS NULL', $edit);
        self::assertStringContainsString('AS effective_amount_paid', $edit);
        self::assertStringContainsString('pay.status = "succeeded"', $edit);
        self::assertStringContainsString('name="billing_transition_strategy" value="final_project_statement"', $edit);
        self::assertStringContainsString('name="billing_transition_strategy" value="convert_to_direct"', $edit);
        self::assertStringContainsString('name="delivery_action" value="review"', $edit);
        self::assertStringContainsString('name="delivery_action" value="send_all"', $edit);
        self::assertStringContainsString('Resolve invoices from previous monthly billing', $edit);
        self::assertStringContainsString('Existing links stay valid.', $edit);
        self::assertStringNotContainsString('public-link-revoke', $edit);

        self::assertStringContainsString('data-project-billing-transition', $edit);
        self::assertStringContainsString('data-original-billing-period', $edit);
        self::assertStringContainsString('name="invoice_billing_period_original"', $edit);
        self::assertStringContainsString('data-monthly-auto-email-confirmed', $edit);
        self::assertStringContainsString('transitionPanel.dataset.hasUnresolved', $script);
        self::assertStringContainsString("selected === 'per_invoice' && (original === 'monthly' || hasUnresolved)", $script);
        self::assertStringContainsString("autoEmailInput.disabled = selected !== 'monthly'", $script);
        self::assertStringContainsString('Existing public links will remain unchanged.', $script);
        self::assertStringContainsString('automatic Project Invoice email', $script);
        self::assertStringContainsString("' and send them after the transition'", $script);
    }

    public function testProjectDetailsShowsOnlyModeAppropriateBillingActions(): void
    {
        $details = (string)file_get_contents($this->root . '/src/views/pages/project/projects-details.php');

        self::assertStringContainsString('assigned_project_invoice_id', $details);
        self::assertStringContainsString('Included in PI-', $details);
        self::assertStringContainsString('Pending billing-transition review', $details);
        self::assertStringContainsString('Resolve prior monthly invoices', $details);
        self::assertStringContainsString('<?php if ($monthlyBilling): ?>', $details);
        self::assertStringContainsString('New invoices are billed and sent individually.', $details);
        self::assertStringContainsString('Existing Project Invoices remain available and payable through their original links.', $details);
        self::assertStringContainsString("(string)\$_GET['billing_transition'] === 'success'", $details);
        self::assertStringContainsString("\$_GET['transition_error']", $details);
        self::assertStringContainsString('Review or change billing', $details);
        self::assertStringNotContainsString('<select name="invoice_billing_period">', $details);
    }

    public function testPublicProjectPortalShowsOneCollectibleDocumentPerCharge(): void
    {
        $portal = (string)file_get_contents($this->root . '/src/controllers/public_view/public_project.php');

        self::assertStringContainsString('LEFT JOIN project_invoice_items pii ON pii.invoice_id = i.id', $portal);
        self::assertStringContainsString('COALESCE(i.collection_mode, "direct") = "direct"', $portal);
        self::assertStringContainsString('pii.invoice_id IS NULL', $portal);
        self::assertStringContainsString("'project_invoice'", $portal);
    }

    public function testAggregateChildrenDoNotExposeDirectPaidAction(): void
    {
        $list = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoices-list.php');
        $details = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoice-details.php');

        self::assertStringContainsString("&& (\$r['collection_mode'] ?? 'direct') === 'direct'", $list);
        self::assertStringContainsString("&& \$invoiceCollectionMode === 'direct'", $details);
        self::assertStringContainsString('Mark as Paid', $details);
    }
}
