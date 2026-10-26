<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/csrf.php';

final class RecurringServicePanelRenderTest extends TestCase
{
    private function render(array $changes = [], string $pricing = 'per_invoice', array $serviceOverrides = [], string $contractStatus = 'active'): string
    {
        $id = 22;
        $contract = ['pricing_type' => $pricing];
        $recurringServices = [[
            'id'=>9, 'name'=>'Hosting <unsafe>', 'description'=>'Service & support',
            'amount'=>'1200.00', 'monthly_rate'=>'100.00', 'rate_basis'=>'monthly',
            'billing_interval_count'=>1, 'billing_interval_unit'=>'year',
            'effective_from'=>'2026-01-01', 'effective_until'=>null,
            'next_invoice_date'=>'2027-01-01', 'status'=>'active', 'approval_status'=>'approved',
        ]];
        $recurringServices[0] = array_replace($recurringServices[0], $serviceOverrides);
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

    public function testMonthlyHistoryRequiresEligibleServiceAndExplicitUnfilledCoverage(): void
    {
        $monthly = ['billing_interval_unit'=>'month'];
        $html = $this->render([], 'per_invoice', $monthly);
        self::assertStringContainsString('Record paid monthly periods', $html);
        self::assertStringContainsString('name="first_period_start">', $html);
        self::assertStringContainsString('name="paid_amount"', $html);
        self::assertStringContainsString('data-history-record disabled', $html);
        self::assertMatchesRegularExpression('/name="generation_key" value="[a-f0-9]{32}"/', $html);
        self::assertStringContainsString('does not send emails, collect money, or activate billing', $html);
        self::assertStringNotContainsString('Record paid monthly periods', $this->render());
        foreach (['cancelled','completed','void'] as $status) {
            self::assertStringNotContainsString('Record paid monthly periods', $this->render([], 'per_invoice', $monthly, $status));
        }
        self::assertStringNotContainsString('Record paid monthly periods', $this->render([], 'per_invoice', $monthly + ['status'=>'ended']));
        self::assertStringNotContainsString('Record paid monthly periods', $this->render([], 'per_invoice', $monthly + ['approval_status'=>'pending']));
        $change = ['id'=>3,'recurring_service_id'=>9,'state'=>'pending','effective_date'=>'2027-01-01','new_values'=>'{}'];
        self::assertStringNotContainsString('Record paid monthly periods', $this->render([$change], 'per_invoice', $monthly));
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

    public function testFinalPartialPeriodExplainsWhyBillingWillStop(): void
    {
        $html = $this->render([], 'per_invoice', ['effective_until'=>'2027-06-30']);
        self::assertStringContainsString('Service ends:', $html);
        self::assertStringContainsString('so it will not be charged', $html);
        self::assertStringContainsString('end this service and add the remaining period separately', $html);
    }

    public function testApprovedMonthlyChangeResolvesWarningForNextAnnualPeriod(): void
    {
        $change = ['id'=>3, 'recurring_service_id'=>9, 'state'=>'approved', 'effective_date'=>'2027-01-01', 'new_values'=>json_encode([
            'amount'=>'100.00', 'billing_interval_count'=>1, 'billing_interval_unit'=>'month',
            'rate_basis'=>'monthly', 'effective_until'=>'2027-06-30', 'billing_anchor_date'=>'2027-01-01',
        ])];
        $html = $this->render([$change], 'per_invoice', ['effective_until'=>'2027-06-30']);
        self::assertStringContainsString('Scheduled change', $html);
        self::assertStringNotContainsString('so it will not be charged', $html);
    }

    public function testPendingMonthlyProposalDoesNotHideWarningForNextAnnualPeriod(): void
    {
        $change = ['id'=>3, 'recurring_service_id'=>9, 'state'=>'pending', 'effective_date'=>'2027-01-01', 'new_values'=>json_encode([
            'amount'=>'100.00', 'billing_interval_count'=>1, 'billing_interval_unit'=>'month',
            'rate_basis'=>'monthly', 'effective_until'=>'2027-06-30', 'billing_anchor_date'=>'2027-01-01',
        ])];
        $html = $this->render([$change], 'per_invoice', ['effective_until'=>'2027-06-30']);
        self::assertStringContainsString('Awaiting client approval', $html);
        self::assertStringContainsString('so it will not be charged', $html);
    }

    public function testLaterApprovedProposalDoesNotHideWarningForNextAnnualPeriod(): void
    {
        $change = ['id'=>3, 'recurring_service_id'=>9, 'state'=>'approved', 'effective_date'=>'2028-01-01', 'new_values'=>json_encode([
            'amount'=>'100.00', 'billing_interval_count'=>1, 'billing_interval_unit'=>'month',
            'rate_basis'=>'monthly', 'effective_until'=>null,
        ])];
        $html = $this->render([$change], 'per_invoice', ['effective_until'=>'2027-06-30']);
        self::assertStringContainsString('so it will not be charged', $html);
    }
}
