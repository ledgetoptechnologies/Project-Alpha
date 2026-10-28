<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProcessorImportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProcessorImportMigrationAddsGenericSchema(): void
    {
        $sql = $this->read('database/migrations/0016_processor_payment_imports.sql');
        $netSql = $this->read('database/migrations/0017_processor_net_income.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS processor_payment_transactions', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS processor_webhook_events', $sql);
        self::assertStringContainsString('ALTER TABLE payments MODIFY COLUMN client_id INT NULL', $sql);
        self::assertStringContainsString('ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50)', $sql);
        self::assertStringContainsString('processor_provider VARCHAR(50)', $sql);
        self::assertStringContainsString('processor_payment_id VARCHAR(255)', $sql);
        self::assertStringContainsString('processor_gross_amount DECIMAL(12,2)', $sql);
        self::assertStringContainsString('processor_fee_amount DECIMAL(12,2)', $sql);
        self::assertStringContainsString('processor_net_amount DECIMAL(12,2)', $netSql);
        self::assertStringContainsString('processor_fee_policy VARCHAR(32)', $netSql);
        self::assertStringContainsString('processor_fee_source VARCHAR(32)', $netSql);
        self::assertStringContainsString('ALTER TABLE project_invoice_payments', $netSql);
    }

    public function testBillingSettingsExposeIndependentProcessorImportToggles(): void
    {
        $view = $this->read('src/views/pages/settings/billing.php');
        $handler = $this->read('src/controllers/settings_handler.php');
        $config = $this->read('src/config/app.php');

        foreach (['processor_import_standalone_income', 'processor_import_auto_create_clients'] as $key) {
            self::assertStringContainsString($key, $view);
            self::assertStringContainsString($key, $handler);
            self::assertStringContainsString($key, $config);
        }
    }

    public function testGenericImportServiceEncodesToggleAndClientRules(): void
    {
        $service = $this->read('src/services/PaymentProcessorImportService.php');

        self::assertStringContainsString('class PaymentProcessorImportService', $service);
        self::assertStringContainsString('standaloneImportEnabled', $service);
        self::assertStringContainsString('autoCreateClientsEnabled', $service);
        self::assertStringContainsString('Net payout and processor fee are required', $service);
        self::assertStringContainsString('matchOrCreateClient', $service);
        self::assertStringContainsString('payer_name', $service);
        self::assertStringContainsString('payer_email', $service);
        self::assertStringContainsString('processor_provider, processor_payment_id, processor_transaction_id', $service);
        self::assertStringContainsString('processor_net_amount, processor_fee_policy, processor_fee_source', $service);
        self::assertStringNotContainsString('payment_receipt_issue', $service);
    }

    public function testStripeReconciliationUsesGenericStandaloneImportFallback(): void
    {
        $cron = $this->read('src/cron/stripe_reconciliation.php');
        $importHelper = $this->read('src/utils/stripe_reconciliation_import.php');
        $stripe = $this->read('src/services/StripeService.php');
        $webhook = $this->read('src/utils/stripe_payment_settlement.php');

        self::assertStringContainsString('stripe_reconcile_payment_intents', $cron);
        self::assertStringContainsString('PaymentProcessorImportService::importStandalone', $importHelper);
        self::assertStringContainsString('forceStandaloneImport', $importHelper);
        self::assertStringContainsString('normalizePaymentIntentForImport', $stripe);
        self::assertStringContainsString('listPaymentIntentsBetween', $stripe);
        self::assertStringContainsString('listChargesBetween', $stripe);
        self::assertStringContainsString('normalizeChargeForImport', $stripe);
        self::assertStringContainsString('data.latest_charge.balance_transaction', $stripe);
        self::assertStringContainsString('latest_charge.balance_transaction', $stripe);
        self::assertStringNotContainsString("'charges.data'", $stripe);
        self::assertStringContainsString('getPaymentIntentWithBalanceTransaction', $stripe);
        self::assertStringContainsString('PaymentProcessorImportService::importStandalone', $webhook);
    }

    public function testStripeNetIncomeCaptureAndBackfillAreWired(): void
    {
        $checkoutWebhook = $this->read('src/controllers/webhook/stripe_checkout_completed.php');
        $intentWebhook = $this->read('src/utils/stripe_payment_settlement.php');
        $cron = $this->read('src/cron/stripe_reconciliation.php');
        $helper = $this->read('src/utils/stripe_payment_accounting.php');
        $checkout = $this->read('src/controllers/stripe/stripe_checkout.php');
        $settings = $this->read('src/views/pages/settings/billing.php');
        $controller = $this->read('src/controllers/settings/stripe_net_backfill.php');
        $importController = $this->read('src/controllers/settings/stripe_import_payments.php');
        $front = $this->read('public/index.php');
        $acl = $this->read('src/utils/acl_middleware.php');

        self::assertStringContainsString('stripe_processor_fields_from_normalized', $checkoutWebhook);
        self::assertStringContainsString('processor_net_amount', $checkoutWebhook);
        self::assertStringContainsString('stripe_processor_fields_from_normalized', $intentWebhook);
        self::assertStringContainsString('stripe_backfill_net_income', $cron);
        self::assertStringContainsString('stripe_update_payment_processor_fields', $helper);
        self::assertStringContainsString('stripe_update_project_payment_processor_fields', $helper);
        self::assertStringContainsString('pa_fee_policy', $checkout);
        self::assertStringContainsString('Backfill Stripe Net Income', $settings);
        self::assertStringContainsString('Import old Stripe payments', $settings);
        self::assertStringContainsString('stripe_backfill_net_income', $controller);
        self::assertStringContainsString('stripe_reconcile_payment_intents', $importController);
        self::assertStringContainsString('stripe_import_start_date', $importController);
        self::assertStringNotContainsString('stripe_import_end_date', $settings);
        self::assertStringNotContainsString('stripe_import_max_intents', $settings);
        self::assertStringContainsString('settings/stripe-net-backfill', $front);
        self::assertStringContainsString('settings/stripe-import-payments', $front);
        self::assertStringContainsString("'settings/stripe-net-backfill'", $acl);
        self::assertStringContainsString("'settings/stripe-import-payments'", $acl);
    }

    public function testDashboardsAndPaymentsListUseNetIncomeAccounting(): void
    {
        $helper = $this->read('src/utils/payment_accounting.php');
        $home = $this->read('src/views/pages/home.php');
        $dashboard = $this->read('src/views/pages/financial/financial-dashboard.php');
        $api = $this->read('src/controllers/api/financial_summary.php');
        $dashboardApi = $this->read('src/controllers/api/dashboard_summary.php');
        $paymentsList = $this->read('src/views/pages/payments/payments-list.php');

        self::assertStringContainsString('function payment_accounting_net_income_expr', $helper);
        self::assertStringContainsString('processor_net_amount', $helper);
        self::assertStringContainsString('payment_accounting_net_income_expr', $home);
        self::assertStringContainsString('payment_accounting_net_income_expr', $dashboard);
        self::assertStringContainsString('payment_accounting_net_income_expr', $api);
        self::assertStringContainsString('payment_accounting_net_income_expr', $dashboardApi);
        self::assertStringContainsString('Net Received', $paymentsList);
        self::assertStringContainsString('processor_fee_source', $paymentsList);
    }

    public function testStandaloneProcessorIncomeIsVisibleWithoutClient(): void
    {
        $paymentsList = $this->read('src/views/pages/payments/payments-list.php');
        $receipts = $this->read('src/utils/payment_receipts.php');
        $publicReceipt = $this->read('src/controllers/public_view/payment_receipt.php');

        self::assertStringContainsString('LEFT JOIN clients c ON c.id=p.client_id', $paymentsList);
        self::assertStringContainsString('Processor income', $paymentsList);
        self::assertStringContainsString('LEFT JOIN clients c ON c.id=p.client_id', $receipts);
        self::assertStringContainsString('COALESCE(NULLIF(c.email,""),NULLIF(ppt.payer_email,""))', $receipts);
        self::assertStringContainsString('empty($payment[\'invoice_id\']) && !empty($payment[\'processor_transaction_id\'])', $receipts);
        self::assertStringContainsString('LEFT JOIN clients c ON c.id=p.client_id', $publicReceipt);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }
}
