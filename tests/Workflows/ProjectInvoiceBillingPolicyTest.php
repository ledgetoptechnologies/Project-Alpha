<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectInvoiceBillingPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/src/utils/project_invoice_billing.php';
    }

    public function testAggregateDueDateStartsAtStatementPeriodEnd(): void
    {
        self::assertSame(
            '2026-04-30',
            project_invoice_due_date_for_period(
                ['invoice_net_terms_days' => 30],
                ['net_terms_days' => 15],
                '2026-03-31'
            )
        );
        self::assertSame(
            '2026-04-15',
            project_invoice_due_date_for_period(
                ['invoice_net_terms_days' => ''],
                ['net_terms_days' => 15],
                '2026-03-31'
            )
        );
    }

    public function testUndeliverableMonthlyStatementsStayDraftAndDeliveryDoesNoRuntimeDdl(): void
    {
        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $generator = (string)file_get_contents($this->root . '/src/controllers/project/project_invoice_generate.php');

        self::assertStringContainsString('$shouldFinalize = $finalize;', $billing);
        self::assertStringContainsString('$validRecipientCount === 0', $billing);
        self::assertStringContainsString('COALESCE(i.collection_mode, "direct") = "project_aggregate"', $billing);
        self::assertStringNotContainsString('ALTER TABLE public_links', $billing);
        self::assertStringContainsString('project_invoice_send_email_result', $generator);
        self::assertStringNotContainsString('saved as a draft because', $generator);
        self::assertStringContainsString('project_invoice_has_saved_deliverable_recipient', $generator);
        self::assertStringContainsString('null, false, null, true', $generator);
        self::assertStringContainsString('billing_missing_recipients=1', $generator);
    }

    public function testRecipientTargetsAreIndependentAndManualRetriesHonorQueuedAddress(): void
    {
        $migration = (string)file_get_contents($this->root . '/database/migrations/0070_project_invoice_recipients.sql');
        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $notifications = (string)file_get_contents($this->root . '/src/utils/project_invoice_notifications.php');
        $details = (string)file_get_contents($this->root . '/src/views/pages/project/project-invoice-details.php');

        self::assertStringContainsString('organization_id INT NULL', $migration);
        self::assertStringContainsString('manual_email VARCHAR(254) NULL', $migration);
        self::assertStringContainsString('client_id IS NOT NULL AND organization_id IS NULL', $migration);
        self::assertStringContainsString("'organization:' . \$organizationId", $billing);
        self::assertStringContainsString("\$row['notification_type'] === 'manual'", $notifications);
        self::assertStringContainsString("\$queuedEmail = trim", $notifications);
        self::assertStringContainsString("'document_revision'", $notifications);
        self::assertStringContainsString('function project_invoice_send_email_result', $billing);
        self::assertStringContainsString("'already_sent' => 0", $billing);
        self::assertStringContainsString('queued for retry', $billing);
        self::assertStringContainsString('name="recipient_keys[]"', $details);
        self::assertStringContainsString('saved project invoice recipients', $details);
    }

    public function testAutoEmailRecipientValidationAcceptsOnlyCurrentlyDeliverableTargets(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT, organization_id INTEGER, archived INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT, general_email TEXT)');
        $pdo->exec('CREATE TABLE project_invoice_recipients (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, client_id INTEGER, organization_id INTEGER, manual_email TEXT, manual_name TEXT, recipient_key TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO clients (id,name,email,organization_id,archived) VALUES (1,'Valid Contact','valid@example.test',10,0),(2,'Invalid Contact','invalid',10,0),(3,'Archived Contact','archived@example.test',10,1),(4,'Outside Contact','outside@example.test',11,0)");
        $pdo->exec("INSERT INTO organizations (id,name,general_email) VALUES (10,'Example Co','company@example.test'),(11,'Outside Co','')");
        $pdo->exec("INSERT INTO project_invoice_recipients (project_id,manual_email,recipient_key,sort_order) VALUES (99,'legacy@example.test','email:legacy@example.test',0)");

        self::assertFalse(project_invoice_has_deliverable_recipient_config($pdo, [], [], []));
        self::assertFalse(project_invoice_has_deliverable_recipient_config($pdo, [2, 3], [], [11]));
        self::assertTrue(project_invoice_has_deliverable_recipient_config($pdo, [1], [], []));
        self::assertTrue(project_invoice_has_deliverable_recipient_config($pdo, [], ['manual@example.test'], []));
        self::assertTrue(project_invoice_has_deliverable_recipient_config($pdo, [], [], [10]));
        self::assertTrue(project_invoice_recipient_client_ids_in_scope($pdo, [1, 2], 10));
        self::assertFalse(project_invoice_recipient_client_ids_in_scope($pdo, [1, 4], 10));
        self::assertFalse(project_invoice_recipient_client_ids_in_scope($pdo, [3], 10));

        project_invoice_sync_recipients($pdo, 99, [1], [], [10]);
        $saved = project_invoice_saved_recipients($pdo, 99);
        self::assertSame(['client:1', 'organization:10'], array_column($saved, 'recipient_key'));
        self::assertSame(['valid@example.test', 'company@example.test'], array_column($saved, 'email'));

        $pdo->exec("INSERT INTO project_invoice_recipients (project_id,manual_email,recipient_key,sort_order) VALUES (99,'hidden@example.test','email:hidden@example.test',2)");
        self::assertSame(
            ['client:1', 'organization:10'],
            array_column(project_invoice_saved_recipients($pdo, 99), 'recipient_key'),
            'Legacy manual addresses must remain inactive after the manual-recipient UI is removed.'
        );
    }

    public function testControllersAndCronSurfaceAutoEmailConfigurationAndDeliveryFailures(): void
    {
        $create = (string)file_get_contents($this->root . '/src/controllers/project/projects_create.php');
        $update = (string)file_get_contents($this->root . '/src/controllers/project/projects_update.php');
        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $cron = (string)file_get_contents($this->root . '/src/cron/generate_recurring_invoices.php');

        foreach ([$create, $update] as $controller) {
            self::assertStringContainsString('$projectInvoiceAutoEmail &&', $controller);
            self::assertStringContainsString('Automatic project invoice email requires at least one valid recipient.', $controller);
            self::assertStringContainsString('project_invoice_recipient_client_ids_in_scope', $controller);
            self::assertStringNotContainsString("\$_POST['project_invoice_manual_emails']", $controller);
        }
        self::assertStringContainsString('function project_invoice_generate_due_monthly_result', $billing);
        self::assertStringContainsString('?DateTimeInterface $runAt = null', $billing);
        self::assertStringContainsString('i.finalized_at IS NOT NULL', $billing);
        self::assertStringContainsString('"sent", "unpaid", "partial", "overdue"', $billing);
        self::assertStringNotContainsString('status IN ("active","not_started") AND invoice_billing_period', $billing);
        self::assertGreaterThanOrEqual(3, substr_count($billing, 'COALESCE(disputed_amount,0)'));
        self::assertStringContainsString('status IN ("unpaid","partial","sent","overdue","paid")', $billing);
        $receivables = (string)file_get_contents($this->root . '/src/services/ProjectReceivablesSummaryService.php');
        self::assertStringContainsString("status IN ('sent','unpaid','partial','overdue')", $receivables);
        $paymentView = (string)file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        self::assertStringContainsString("pi.status IN ('sent','unpaid','partial','overdue')", $paymentView);
        self::assertStringContainsString("'delivery_failed' => 0", $billing);
        self::assertStringContainsString('has no valid saved recipient', $billing);
        self::assertStringContainsString('project_invoice_generate_due_monthly_result($pdo, $appConfig)', $cron);
        self::assertStringContainsString("\$errors += \$projectBillingResult['delivery_pending'] + \$projectBillingResult['delivery_failed']", $cron);
        self::assertStringContainsString('project delivery {$projectBillingResult[\'delivered\']} sent/', $cron);
        self::assertStringContainsString('if ($errors > 0)', $cron);
        self::assertStringContainsString('throw new RuntimeException($runResult)', $cron);
    }

    public function testMonthlyChildInvoiceActionsDescribeProjectBillingInsteadOfDirectEmail(): void
    {
        $createView = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoices-create.php');
        $createScript = (string)file_get_contents($this->root . '/public/assets/js/invoices-create-logic.js');
        $projectSearch = (string)file_get_contents($this->root . '/src/controllers/project/projects_search.php');
        $details = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoice-details.php');
        $finalize = (string)file_get_contents($this->root . '/src/controllers/invoice/invoice_finalize.php');
        $createController = (string)file_get_contents($this->root . '/src/controllers/invoice/invoices_create.php');
        $projectDetails = (string)file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $onDemandList = (string)file_get_contents($this->root . '/src/views/pages/contract/on-demand-contracts-list.php');
        $onDemandInvoices = (string)file_get_contents($this->root . '/src/views/pages/contract/on-demand-invoices-list.php');

        self::assertStringContainsString('data-invoice-billing-period=', $createView);
        self::assertStringContainsString('invoice_billing_period', $projectSearch);
        self::assertStringContainsString("actionButton.value = isMonthly ? 'finalize' : 'finalize_send'", $createScript);
        self::assertStringContainsString("'Finalize for Project Billing'", $createScript);
        self::assertStringContainsString('will not be emailed separately', $createView);
        self::assertStringContainsString("\$invoiceCollectionMode === 'project_aggregate' ? 'Finalize for Project Billing'", $details);
        self::assertStringContainsString("\$isProjectAggregateInvoice =", $finalize);
        self::assertStringContainsString("'manual_project_billing_finalize'", $finalize);
        self::assertStringNotContainsString("invoice_send_finalized(\$pdo, \$id, \$appConfig, 'manual_project_billing_finalize'", $finalize);
        self::assertStringContainsString('if ($isMonthlyProjectBilling && $finalizeAndSend)', $createController);
        self::assertStringContainsString('$finalizeAndSend = false;', $createController);
        self::assertStringContainsString('$finalizeOnly = true;', $createController);
        self::assertStringContainsString("'&finalized=1&project_billing=1'", $createController);
        self::assertStringContainsString("&& \$invoiceCollectionMode === 'direct'", $details);
        self::assertStringContainsString("(\$invoice['collection_mode'] ?? 'direct') === 'direct'", $projectDetails);
        self::assertStringContainsString('Project statement billing', $projectDetails);
        self::assertStringContainsString('data-monthly-project-billing=', $onDemandList);
        self::assertStringContainsString("monthly ? 'Finalize for Project Billing'", $onDemandList);
        self::assertStringContainsString('Finalize for Project Billing', $onDemandInvoices);
        $notifications = (string)file_get_contents($this->root . '/src/utils/invoice_notifications.php');
        self::assertStringContainsString("(\$invoice['collection_mode'] ?? 'direct') !== 'direct'", $notifications);
    }

    public function testAggregatePaymentMethodsPreserveConfiguredKeysAndAllocationIsAtomic(): void
    {
        self::assertSame('ach', project_invoice_payment_method_key('ACH'));
        self::assertSame('check', project_invoice_payment_method_key('Cheque'));
        self::assertSame('wire_custom', project_invoice_payment_method_key('Wire Custom'));

        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/payments_create.php');
        $paymentView = (string)file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        self::assertStringContainsString('$ownsTransaction = !$pdo->inTransaction();', $billing);
        self::assertStringContainsString('status IN ("unpaid","partial","sent","overdue","paid") FOR UPDATE', $billing);
        self::assertStringContainsString('UPDATE project_invoice_payments SET status="succeeded"', $billing);
        self::assertStringContainsString('receipt failed after commit', $controller);
        self::assertStringContainsString('[$projectScopeWhere, $projectScopeParams] = scope_clause', $paymentView);
        self::assertStringContainsString('id="noStaffPaymentMethodNotice"', $paymentView);
    }

    public function testProjectStatementGenerationUsesLockedExplicitLifecycleResults(): void
    {
        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $baseline = (string)file_get_contents($this->root . '/database/baseline.sql');
        $generator = (string)file_get_contents($this->root . '/src/controllers/project/project_invoice_generate.php');
        $notifications = (string)file_get_contents($this->root . '/src/utils/project_invoice_notifications.php');

        self::assertStringContainsString('function project_invoice_create_for_period_result', $billing);
        self::assertStringContainsString("'status' => 'empty'", $billing);
        self::assertStringContainsString("'status' => 'existing'", $billing);
        self::assertStringContainsString("'status' => 'created'", $billing);
        self::assertStringContainsString('SELECT * FROM projects WHERE id = ? FOR UPDATE', $billing);
        self::assertStringContainsString('billing_period_end=?', $billing);
        self::assertStringContainsString('MAX(billing_period_end)', $billing);
        self::assertStringContainsString('pii.id IS NULL', $billing);
        self::assertStringContainsString('invoice_date ASC', $billing);
        self::assertStringNotContainsString('BETWEEN ? AND ?', $billing);
        self::assertStringContainsString('uq_project_invoice_period', $baseline);
        self::assertStringContainsString('uq_project_invoice_child_invoice', $baseline);
        self::assertStringContainsString('project_invoice_create_for_period_result', $generator);
        self::assertStringContainsString("'existing=1'", $generator);
        self::assertStringContainsString('no outstanding balance', strtolower($billing));
        self::assertStringContainsString('no longer eligible for email delivery', strtolower($notifications));
        self::assertStringContainsString('no longer eligible for reminders', strtolower($notifications));
    }
}
