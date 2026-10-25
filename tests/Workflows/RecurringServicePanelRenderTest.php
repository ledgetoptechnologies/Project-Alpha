<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/csrf.php';

final class RecurringServicePanelRenderTest extends TestCase
{
    private function render(array $changes = [], string $pricing = 'per_invoice'): string
    {
        $id = 22;
        $contract = ['pricing_type' => $pricing];
        $contractStatus = 'active';
        $recurringServices = [[
            'id'=>9, 'name'=>'Hosting <unsafe>', 'description'=>'Service & support',
            'amount'=>'1200.00', 'monthly_rate'=>'100.00', 'rate_basis'=>'monthly',
            'billing_interval_count'=>1, 'billing_interval_unit'=>'year',
            'effective_from'=>'2026-01-01', 'effective_until'=>null,
            'next_invoice_date'=>'2027-01-01', 'status'=>'active', 'approval_status'=>'approved',
        ]];
        $scheduledServiceChanges = $changes;
        $contractAmendments = [];
        ob_start();
        require dirname(__DIR__, 2) . '/src/views/components/recurring_service_panel.php';
        return (string)ob_get_clean();
    }

    public function testCurrentTermsAndQueuedTermsAreDistinctAndEscaped(): void
    {
        $html = $this->render([['id'=>3, 'recurring_service_id'=>9, 'state'=>'approved', 'effective_date'=>'2027-01-01', 'new_values'=>json_encode(['amount'=>'100.00','billing_interval_count'=>1,'billing_interval_unit'=>'month'])]]);
        self::assertStringContainsString('$1,200.00', $html);
        self::assertStringContainsString('Scheduled change', $html);
        self::assertStringContainsString('$100.00 every 1 month', $html);
        self::assertStringContainsString('Hosting &lt;unsafe&gt;', $html);
        self::assertStringNotContainsString('name="service_id" value="9"><input type="hidden" name="generation_key"', $html);
        self::assertStringContainsString('Cancel scheduled change', $html);
    }

    public function testNewApprovalIsNotInferredFromPreviouslyApprovedService(): void
    {
        $html = $this->render();
        self::assertStringContainsString('Change rate or billing frequency at renewal', $html);
        self::assertStringContainsString('name="next_invoice_date" value="2027-01-01"', $html);
        self::assertDoesNotMatchRegularExpression('/name="client_approved"[^>]*checked/', $html);
        self::assertStringContainsString('Save change for renewal', $html);
    }

    public function testInstallmentsDoNotOfferConversionToRecurringAmounts(): void
    {
        $html = $this->render([], 'fixed_total');
        self::assertStringContainsString('existing installment schedule is preserved', $html);
        self::assertStringNotContainsString('name="monthly_rate"', $html);
        self::assertStringNotContainsString('Add service', $html);
    }
}
