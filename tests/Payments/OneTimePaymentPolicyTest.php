<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OneTimePaymentPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAutoChargeIsNotScheduled(): void
    {
        $crontab = file_get_contents($this->root . '/cron/crontab');
        self::assertIsString($crontab);
        self::assertStringNotContainsString('auto_charge_recurring.php', $crontab);
    }

    public function testLegacyAutoChargeFailsClosedInProduction(): void
    {
        putenv('APP_ENV=production');
        putenv('AUTOPAY_BETA_ENABLED=true');
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/src/cron/auto_charge_recurring.php') . ' 2>&1', $output, $code);
        putenv('APP_ENV');
        putenv('AUTOPAY_BETA_ENABLED');
        self::assertSame(78, $code, implode("\n", $output));
    }

    public function testCheckoutDoesNotRequestReusablePaymentMethod(): void
    {
        $service = file_get_contents($this->root . '/src/services/StripeService.php');
        self::assertIsString($service);
        self::assertStringNotContainsString('setup_future_usage', $service);
    }

    public function testQuoteApprovalCreatesDraftInvoice(): void
    {
        $approval = file_get_contents($this->root . '/src/controllers/quote/quote_approve.php');
        $publicApproval = file_get_contents($this->root . '/src/controllers/public_view/public_quote_action.php');
        self::assertStringContainsString("'draft'", (string)$approval);
        self::assertStringContainsString("'draft'", (string)$publicApproval);
    }

    public function testPublicCheckoutRequiresFinalizationAndDirectCollection(): void
    {
        $checkout = file_get_contents($this->root . '/src/controllers/stripe/stripe_checkout.php');
        self::assertStringContainsString("empty(\$invoice['finalized_at'])", (string)$checkout);
        self::assertStringContainsString("collection_mode", (string)$checkout);
        self::assertStringContainsString('Idempotency-Key', (string)file_get_contents($this->root . '/src/services/StripeService.php'));
    }

    public function testProjectPaymentsAllocateOnlyToChildInvoices(): void
    {
        $billing = file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        self::assertStringContainsString('project_invoice_payment_id', (string)$billing);
        self::assertStringContainsString('INSERT INTO payments', (string)$billing);
        self::assertStringNotContainsString('INSERT INTO payments (client_id, project_invoice_id', (string)$billing);
    }

    public function testStaffProjectStatementPaymentUsesAggregateAllocationAndPublicLinkForStripe(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/payments_create.php');
        $detailsController = (string)file_get_contents($this->root . '/src/controllers/project/project_invoice_payment.php');

        self::assertStringContainsString("COALESCE(i.collection_mode,'direct')='direct'", $view);
        self::assertStringContainsString('name="project_invoice_id"', $view);
        self::assertStringContainsString("\$prefProjectInvoice <= 0 ? 'checked' : ''", $view);
        self::assertStringContainsString('Use the project statement payment link to collect a Stripe payment.', $controller);
        self::assertStringContainsString('project_invoice_record_manual_payment', $controller);
        self::assertStringContainsString('project_payment_receipt_email_issue', $detailsController);
        self::assertStringContainsString("audit_log(\$pdo, 'payment.recorded', 'project_invoice'", $detailsController);
        self::assertStringContainsString("if (scope === 'manual') return;", (string)file_get_contents($this->root . '/public/assets/js/payments-create-logic.js'));
    }

    public function testProductionDoesNotRenderNewAutoPayControls(): void
    {
        $before = [];
        foreach (['APP_ENV'=>'production','AUTOPAY_BETA_ENABLED'=>'true','AUTOPAY_INSTALLATION_ID'=>'synthetic-production-test','AUTOPAY_STRIPE_ACCOUNT_ID'=>'acct_TEST'] as $name=>$value) {
            $before[$name]=getenv($name);putenv($name.'='.$value);
        }
        try {
            $pdo = new PDO('sqlite::memory:');
            $appConfig = ['_stripe_secret_key'=>'sk_test_SYNTHETIC'];
            $autopayDocument = ['id'=>1,'client_id'=>1,'status'=>'draft','total'=>'100.00','balance_due'=>'100.00'];
            ob_start();
            require $this->root . '/src/views/components/autopay_invoice_status.php';
            $rendered = (string)ob_get_clean();
            self::assertSame('', trim($rendered));
            self::assertFalse(pa_autopay_enabled($appConfig));
        } finally {
            foreach($before as $name=>$value)putenv($value===false?$name:$name.'='.$value);
        }
    }

    public function testDocumentDeliveryHandlersEnforceRecordOwnership(): void
    {
        $email = file_get_contents($this->root . '/src/controllers/email_send.php');
        $onDemand = file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');
        $publicLink = file_get_contents($this->root . '/src/controllers/public_link_create.php');

        self::assertStringContainsString('require_record_ownership', (string)$email);
        self::assertStringContainsString('require_record_ownership', (string)$onDemand);
        self::assertStringContainsString('can_access_record', (string)$publicLink);
        self::assertStringContainsString("empty(\$doc['finalized_at'])", (string)$publicLink);
    }
}
