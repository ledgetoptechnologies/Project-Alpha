<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_due_dates.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_notifications.php';
require_once dirname(__DIR__, 2) . '/src/utils/recurring_billing.php';
require_once dirname(__DIR__, 2) . '/src/utils/project_invoice_notifications.php';
require_once dirname(__DIR__, 2) . '/src/utils/project_invoice_billing.php';

use PHPUnit\Framework\TestCase;

final class InvoiceAutomationTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];
    private array $config;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $column = $this->pdo->query("SHOW COLUMNS FROM invoice_notifications LIKE 'delivery_status'")->fetchColumn();
            if ($column === false) {
                $this->markTestSkipped('Durable notification migration has not been applied.');
            }
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }
        $this->config = [
            'net_terms_days' => 30,
            'invoice_auto_email_on_generate' => 1,
            'invoice_auto_send_due_7days' => 1,
            'invoice_auto_send_overdue_weekly' => 1,
            'public_links_in_email' => 1,
            'app_host' => 'https://tenant-a.example.invalid',
            'invoice_content_links_enabled' => 0,
        ];
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach (array_reverse($this->ids['invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM public_links WHERE document_type="invoice" AND document_id=?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['project_invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM public_links WHERE document_type="project_invoice" AND document_id=?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM project_invoices WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['projects'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM project_clients WHERE project_id=?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['contracts'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['quotes'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM quotes WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['clients'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['organizations'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$id]);
        }
    }

    public function testDocumentDateUpdatesDerivedTermsAndPreservesManualDueDates(): void
    {
        $clientId = $this->client('dates@example.invalid');
        $net30 = $this->invoice($clientId, 'regular', '2026-01-01', '2026-01-31', 30, 'terms');
        $result = invoice_update_document_date($this->pdo, $net30, '2026-02-10');
        self::assertSame('2026-03-12', $result['due_date']);
        self::assertSame('Net 30 (due March 12, 2026)', invoice_payment_terms_text($result, $this->config));

        $net15 = $this->invoice($clientId, 'regular', '2026-01-01', '2026-01-16', 15, 'terms');
        self::assertSame('2026-03-05', invoice_update_document_date($this->pdo, $net15, '2026-02-18')['due_date']);

        $todayInvoice = $this->invoice($clientId, 'regular', '2026-01-01', '2026-01-31', 30, 'terms');
        self::assertSame(
            invoice_due_date_from_terms(date('Y-m-d'), 30),
            invoice_update_document_date($this->pdo, $todayInvoice, date('Y-m-d'))['due_date']
        );

        $manual = $this->invoice($clientId, 'regular', '2026-01-01', '2026-04-01', null, 'manual');
        self::assertSame('2026-04-01', invoice_update_document_date($this->pdo, $manual, '2026-02-10')['due_date']);
    }

    public function testRecurringGenerationAtomicallyEnqueuesOnceAndUsesDocumentDateTerms(): void
    {
        $clientId = $this->client('recurring@example.invalid');
        $contractId = $this->contract($clientId);
        $contract = $this->row('contracts', $contractId);

        $invoiceId = generate_recurring_invoice($this->pdo, $contract, $this->config);
        self::assertNotNull($invoiceId);
        $this->ids['invoices'][] = $invoiceId;
        $invoice = $this->row('invoices', $invoiceId);
        self::assertSame('recurring_schedule', $invoice['finalization_source']);
        self::assertSame('terms', $invoice['due_date_source']);
        self::assertSame(30, (int)$invoice['payment_terms_days']);
        self::assertEqualsWithDelta((float)$invoice['total'], (float)$invoice['balance_due'], 0.005);
        self::assertSame(invoice_due_date_from_terms(substr((string)$invoice['document_date'], 0, 10), 30), $invoice['due_date']);
        self::assertSame(1, $this->notificationCount($invoiceId, 'on_generate'));

        self::assertNull(generate_recurring_invoice($this->pdo, $contract, $this->config));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE contract_id=?');
        $count->execute([$contractId]);
        self::assertSame(1, (int)$count->fetchColumn());
    }

    public function testReminderSchedulerCoversEveryInvoiceTypeAndDuplicateRunsDoNotSpam(): void
    {
        $clientId = $this->client('all-types@example.invalid');
        $today = new DateTimeImmutable('2026-07-30');
        foreach (['regular', 'long_term', 'on_demand'] as $type) {
            $this->invoice($clientId, $type, '2026-07-01', '2026-08-06', 30, 'terms');
        }
        self::assertSame(3, invoice_notification_schedule_reminders($this->pdo, $this->config, $today)['queued']);
        self::assertSame(0, invoice_notification_schedule_reminders($this->pdo, $this->config, $today)['queued']);

        $sent = [];
        $sender = static function (string $to, string $subject, string $body, array $options) use (&$sent): array {
            $sent[] = compact('to', 'subject', 'body', 'options');
            return [true, ''];
        };
        $stats = invoice_notification_process($this->pdo, $this->config, $sender, $today->setTime(8, 0));
        self::assertSame(3, $stats['sent']);
        self::assertCount(3, $sent);
        $subjects = implode(' | ', array_column($sent, 'subject'));
        self::assertStringContainsString('I-', $subjects);
        self::assertStringContainsString('LTI-', $subjects);
        self::assertStringContainsString('ODI-', $subjects);
        self::assertStringContainsString('https://tenant-a.example.invalid/', $sent[0]['body']);
        self::assertSame('application/pdf', $sent[0]['options']['attachments'][0]['mime']);
        self::assertSame('invoice', $sent[0]['options']['document_type']);
        self::assertStringStartsWith('invoice-notification:', $sent[0]['options']['message_key']);
        self::assertStringStartsWith('%PDF-', $sent[0]['options']['attachments'][0]['content']);
        self::assertLessThanOrEqual(10 * 1024 * 1024, strlen($sent[0]['options']['attachments'][0]['content']));
        self::assertStringContainsString('Outstanding balance: <strong>$100.00</strong>', $sent[0]['body']);
        self::assertSame(0, invoice_notification_process($this->pdo, $this->config, $sender, $today->setTime(8, 1))['sent']);
    }

    public function testDeliveryFailureIsRetriedOnSameDurableOccurrence(): void
    {
        $clientId = $this->client('retry@example.invalid');
        $invoiceId = $this->invoice($clientId, 'regular', '2026-07-01', '2026-08-06', 30, 'terms');
        $today = new DateTimeImmutable('2026-07-30 08:00:00');
        invoice_notification_schedule_reminders($this->pdo, $this->config, $today->setTime(0, 0));

        $failed = invoice_notification_process(
            $this->pdo,
            $this->config,
            static fn(): array => [false, 'temporary transport failure'],
            $today
        );
        self::assertSame(1, $failed['retry']);
        $notice = $this->notification($invoiceId, 'due_7');
        self::assertSame('retry', $notice['delivery_status']);
        self::assertSame(1, (int)$notice['attempt_count']);
        self::assertStringContainsString('temporary transport failure', (string)$notice['last_error']);

        self::assertSame(0, invoice_notification_process(
            $this->pdo, $this->config, static fn(): array => [true, ''], $today->modify('+1 minute')
        )['sent']);
        self::assertSame(1, invoice_notification_process(
            $this->pdo, $this->config, static fn(): array => [true, ''], $today->modify('+6 minutes')
        )['sent']);
        self::assertSame(1, $this->notificationCount($invoiceId, 'due_7'));
    }

    public function testChangedDueDateAndBalanceAreRevalidatedBeforeSend(): void
    {
        $clientId = $this->client('changed@example.invalid');
        $invoiceId = $this->invoice($clientId, 'regular', '2026-07-01', '2026-08-06', 30, 'terms');
        $firstToday = new DateTimeImmutable('2026-07-30 08:00:00');
        invoice_notification_schedule_reminders($this->pdo, $this->config, $firstToday->setTime(0, 0));
        $this->pdo->prepare('UPDATE invoices SET due_date="2026-08-20",amount_paid=40,balance_due=60 WHERE id=?')->execute([$invoiceId]);
        self::assertSame(1, invoice_notification_process(
            $this->pdo, $this->config, static fn(): array => [true, ''], $firstToday
        )['suppressed']);

        $newToday = new DateTimeImmutable('2026-08-13 08:00:00');
        self::assertSame(1, invoice_notification_schedule_reminders($this->pdo, $this->config, $newToday->setTime(0, 0))['queued']);
        $bodies = [];
        $sender = static function (string $to, string $subject, string $body) use (&$bodies): array {
            $bodies[] = $body;
            return [true, ''];
        };
        self::assertSame(1, invoice_notification_process($this->pdo, $this->config, $sender, $newToday)['sent']);
        self::assertStringContainsString('$60.00', $bodies[0]);
        self::assertStringContainsString('August 20, 2026', $bodies[0]);
    }

    public function testOverdueWeeklyBucketsAndClosedInvoicesStopDelivery(): void
    {
        $clientId = $this->client('overdue@example.invalid');
        $invoiceId = $this->invoice($clientId, 'long_term', '2026-07-01', '2026-07-29', 30, 'terms');
        $sender = static fn(): array => [true, ''];

        $dayOne = new DateTimeImmutable('2026-07-30 08:00:00');
        self::assertSame(1, invoice_notification_schedule_reminders($this->pdo, $this->config, $dayOne->setTime(0, 0))['queued']);
        self::assertSame(1, invoice_notification_process($this->pdo, $this->config, $sender, $dayOne)['sent']);
        self::assertSame(0, invoice_notification_schedule_reminders($this->pdo, $this->config, $dayOne->modify('+6 days')->setTime(0, 0))['queued']);

        $dayEight = $dayOne->modify('+7 days');
        self::assertSame(1, invoice_notification_schedule_reminders($this->pdo, $this->config, $dayEight->setTime(0, 0))['queued']);
        $this->pdo->prepare('UPDATE invoices SET status="paid",amount_paid=total,balance_due=0 WHERE id=?')->execute([$invoiceId]);
        self::assertSame(1, invoice_notification_process($this->pdo, $this->config, $sender, $dayEight)['suppressed']);
        self::assertSame(0, invoice_notification_schedule_reminders($this->pdo, $this->config, $dayEight->modify('+7 days')->setTime(0, 0))['queued']);

        $closedIds = [];
        foreach (['void', 'cancelled'] as $status) {
            $closedIds[] = $this->invoice($clientId, 'regular', '2026-07-01', '2026-07-20', 30, 'terms', $status);
        }
        self::assertSame(0, invoice_notification_schedule_reminders($this->pdo, $this->config, $dayEight->setTime(0, 0))['queued']);
        foreach ($closedIds as $closed) {
            self::assertSame(0, $this->notificationCount($closed, 'overdue_weekly'));
        }
    }

    public function testMissingRecipientGlobalOptOutDraftAndProjectChildrenAreNotSendable(): void
    {
        $invalidClient = $this->client('not-an-email');
        $invalidInvoice = $this->invoice($invalidClient, 'regular', '2026-07-01', '2026-08-06', 30, 'terms');
        $today = new DateTimeImmutable('2026-07-30');
        self::assertSame(1, invoice_notification_schedule_reminders($this->pdo, $this->config, $today)['suppressed']);
        self::assertSame('suppressed', $this->notification($invalidInvoice, 'due_7')['delivery_status']);

        $clientId = $this->client('policy@example.invalid');
        $this->invoice($clientId, 'regular', '2026-07-01', '2026-08-06', 30, 'terms', 'draft');
        $aggregate = $this->invoice($clientId, 'regular', '2026-07-01', '2026-08-06', 30, 'terms');
        $this->pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$aggregate]);
        $aggregateRecurring = $this->invoice($clientId, 'long_term', '2026-07-01', '2026-08-06', 30, 'terms');
        $this->pdo->prepare(
            'UPDATE invoices SET collection_mode="project_aggregate",finalization_source="recurring_schedule" WHERE id=?'
        )->execute([$aggregateRecurring]);
        self::assertFalse(invoice_notification_enqueue_generated($this->pdo, $aggregateRecurring, $this->config));
        self::assertSame(0, $this->notificationCount($aggregateRecurring, 'on_generate'));
        $disabled = $this->config;
        $disabled['invoice_auto_send_due_7days'] = 0;
        $disabled['invoice_auto_send_overdue_weekly'] = 0;
        self::assertSame(0, invoice_notification_schedule_reminders($this->pdo, $disabled, $today)['queued']);
    }

    public function testPublicLinkOptOutStillAllowsReminderWithoutWrongTenantLink(): void
    {
        $clientId = $this->client('no-link@example.invalid');
        $this->invoice($clientId, 'regular', '2026-07-01', '2026-08-06', 30, 'terms');
        $config = $this->config;
        $config['public_links_in_email'] = 0;
        $today = new DateTimeImmutable('2026-07-30 08:00:00');
        invoice_notification_schedule_reminders($this->pdo, $config, $today->setTime(0, 0));
        $body = '';
        $attachment = [];
        $sender = static function (string $to, string $subject, string $html, array $options) use (&$body, &$attachment): array {
            $body = $html;
            $attachment = $options['attachments'][0] ?? [];
            return [true, ''];
        };
        self::assertSame(1, invoice_notification_process($this->pdo, $config, $sender, $today)['sent']);
        self::assertStringNotContainsString('public-doc', $body);
        self::assertStringContainsString('invoice <strong>I-', $body);
        self::assertSame('application/pdf', $attachment['mime']);
        self::assertStringStartsWith('%PDF-', $attachment['content']);
    }

    public function testInvoiceReminderReusesExistingActivePublicLink(): void
    {
        $clientId = $this->client('stable-link@example.invalid');
        $invoiceId = $this->invoice($clientId, 'regular', '2026-07-01', '2026-08-06', 30, 'terms');
        $token = 'stable-invoice-' . bin2hex(random_bytes(12));
        $this->pdo->prepare(
            'INSERT INTO public_links (document_type,document_id,token,expires_at,expire_when_paid,revoked)
             VALUES ("invoice",?,?,NULL,1,0)'
        )->execute([$invoiceId, $token]);
        $today = new DateTimeImmutable('2026-07-30 08:00:00');
        invoice_notification_schedule_reminders($this->pdo, $this->config, $today->setTime(0, 0));
        $body = '';
        $sender = static function (string $to, string $subject, string $html) use (&$body): array {
            $body = $html;
            return [true, ''];
        };

        self::assertSame(1, invoice_notification_process($this->pdo, $this->config, $sender, $today)['sent']);
        self::assertStringContainsString(rawurlencode($token), $body);
        $links = $this->pdo->prepare('SELECT token,revoked FROM public_links WHERE document_type="invoice" AND document_id=? ORDER BY id');
        $links->execute([$invoiceId]);
        self::assertSame([['token' => $token, 'revoked' => 0]], array_map(
            static fn(array $row): array => ['token' => (string)$row['token'], 'revoked' => (int)$row['revoked']],
            $links->fetchAll(PDO::FETCH_ASSOC)
        ));
    }

    public function testProjectInvoiceRemindersRespectRecipientSuppressionAndAttachPdf(): void
    {
        $recipientId = $this->client('project-billing@example.invalid');
        $suppressedId = $this->client('project-optout@example.invalid');
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare(
            'INSERT INTO projects (client_id,organization_id,name,status,invoice_net_terms_days) VALUES (?, ?, ?, "active", 15)'
        )->execute([$recipientId, $orgId, 'Reminder Project']);
        $projectId = (int)$this->pdo->lastInsertId();
        $this->ids['projects'][] = $projectId;
        $this->pdo->prepare(
            'INSERT INTO project_clients (project_id,client_id,is_primary_billing,send_project_invoices,sort_order)
             VALUES (?,?,1,1,0),(?,?,0,0,1)'
        )->execute([$projectId, $recipientId, $projectId, $suppressedId]);
        project_invoice_sync_recipients($this->pdo, $projectId, [$recipientId]);
        $this->pdo->prepare(
            'INSERT INTO project_invoices
             (project_id,organization_id,primary_client_id,doc_number,status,billing_period_start,billing_period_end,
              due_date,subtotal,total,amount_paid,balance_due,finalized_at,finalization_source)
             VALUES (?,?,?,? ,"unpaid","2026-07-01","2026-07-31","2026-08-06",250,250,0,250,"2026-07-31 09:00:00","project_billing")'
        )->execute([$projectId, $orgId, $recipientId, random_int(100000, 999999)]);
        $projectInvoiceId = (int)$this->pdo->lastInsertId();
        $this->ids['project_invoices'][] = $projectInvoiceId;
        $stableProjectToken = 'stable-project-' . bin2hex(random_bytes(12));
        $this->pdo->prepare(
            'INSERT INTO public_links (document_type,document_id,token,expires_at,expire_when_paid,revoked)
             VALUES ("project_invoice",?,?,NULL,1,0)'
        )->execute([$projectInvoiceId, $stableProjectToken]);

        $today = new DateTimeImmutable('2026-07-30 08:00:00');
        self::assertSame(1, project_invoice_notification_schedule_reminders($this->pdo, $this->config, $today->setTime(0, 0))['queued']);
        self::assertSame(0, project_invoice_notification_schedule_reminders($this->pdo, $this->config, $today->setTime(0, 0))['queued']);
        $delivered = [];
        $sender = static function (string $to, string $subject, string $body, array $options) use (&$delivered): array {
            $delivered[] = compact('to', 'subject', 'body', 'options');
            return [true, ''];
        };
        self::assertSame(1, project_invoice_notification_process($this->pdo, $this->config, $sender, $today)['sent']);
        self::assertCount(1, $delivered);
        self::assertSame('project-billing@example.invalid', $delivered[0]['to']);
        self::assertStringContainsString(rawurlencode($stableProjectToken), $delivered[0]['body']);
        $projectLinks = $this->pdo->prepare('SELECT token FROM public_links WHERE document_type="project_invoice" AND document_id=?');
        $projectLinks->execute([$projectInvoiceId]);
        self::assertSame([$stableProjectToken], $projectLinks->fetchAll(PDO::FETCH_COLUMN));
        self::assertStringContainsString('$250.00', $delivered[0]['body']);
        self::assertStringContainsString('Net 15 (due August 6, 2026)', $delivered[0]['body']);
        self::assertStringContainsString('https://tenant-a.example.invalid/', $delivered[0]['body']);
        self::assertStringStartsWith('%PDF-', $delivered[0]['options']['attachments'][0]['content']);
        self::assertSame('project_invoice', $delivered[0]['options']['document_type']);
        self::assertStringStartsWith('project-invoice-notification:', $delivered[0]['options']['message_key']);
    }

    public function testExplicitEarlySendUsesSavedContactWhenMonthlyAutoEmailIsOff(): void
    {
        $recipientId = $this->client('early-project-send@example.invalid');
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare(
            'INSERT INTO projects (client_id,organization_id,name,status,invoice_billing_period,project_invoice_auto_email)
             VALUES (?, ?, ?, "active", "monthly", 0)'
        )->execute([$recipientId, $orgId, 'Early Send Project']);
        $projectId = (int)$this->pdo->lastInsertId();
        $this->ids['projects'][] = $projectId;
        $this->pdo->prepare(
            'INSERT INTO project_clients (project_id,client_id,is_primary_billing,send_project_invoices,sort_order)
             VALUES (?,?,1,1,0)'
        )->execute([$projectId, $recipientId]);
        project_invoice_sync_recipients($this->pdo, $projectId, [$recipientId]);
        $this->pdo->prepare(
            'INSERT INTO project_invoices
             (project_id,organization_id,primary_client_id,doc_number,status,billing_period_start,billing_period_end,
              due_date,subtotal,total,amount_paid,balance_due,finalized_at,finalization_source)
             VALUES (?,?,?,? ,"unpaid","2026-08-01","2026-08-15","2026-09-14",125,125,0,125,NOW(),"manual_send")'
        )->execute([$projectId, $orgId, $recipientId, random_int(100000, 999999)]);
        $projectInvoiceId = (int)$this->pdo->lastInsertId();
        $this->ids['project_invoices'][] = $projectInvoiceId;

        $saved = project_invoice_client_recipients($this->pdo, $projectInvoiceId);
        self::assertCount(1, $saved);
        project_invoice_notification_enqueue(
            $this->pdo, $projectInvoiceId, 'manual', 'generated', (string)$saved[0]['email'], 'pending'
        );
        $sent = [];
        $sender = static function (string $to) use (&$sent): array {
            $sent[] = $to;
            return [true, ''];
        };
        self::assertSame(1, project_invoice_notification_process($this->pdo, $this->config, $sender)['sent']);
        self::assertSame(['early-project-send@example.invalid'], $sent);
    }

    public function testProjectStatementLifecycleRepairsLegacyEmailStateAndKeepsSnapshotsIdempotent(): void
    {
        $recipientId = $this->client('project-lifecycle@example.invalid');
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare(
            'INSERT INTO projects (client_id,organization_id,name,status,invoice_billing_period,project_invoice_auto_email)
             VALUES (?, ?, ?, "active", "monthly", 1)'
        )->execute([$recipientId, $orgId, 'Lifecycle Project']);
        $projectId = (int)$this->pdo->lastInsertId();
        $this->ids['projects'][] = $projectId;
        $this->pdo->prepare(
            'INSERT INTO project_clients (project_id,client_id,is_primary_billing,send_project_invoices,sort_order)
             VALUES (?,?,1,1,0)'
        )->execute([$projectId, $recipientId]);
        project_invoice_sync_recipients($this->pdo, $projectId, [$recipientId]);

        $firstChild = $this->invoice($recipientId, 'regular', '2026-08-10', '2026-09-09', 30, 'terms');
        $this->pdo->prepare('UPDATE invoices SET project_id=?,collection_mode="project_aggregate" WHERE id=?')
            ->execute([$projectId, $firstChild]);

        $first = project_invoice_create_for_period_result(
            $this->pdo, $projectId, '2026-08-01', '2026-08-15', $this->config
        );
        self::assertSame('created', $first['status']);
        self::assertSame(1, $first['included_count']);
        $firstStatementId = (int)$first['project_invoice_id'];
        $this->ids['project_invoices'][] = $firstStatementId;

        project_invoice_refresh_status($this->pdo, $firstStatementId);
        $statement = $this->projectInvoiceRow($firstStatementId);
        self::assertSame('draft', $statement['status']);
        self::assertNull($statement['finalized_at']);

        // Reproduce the production state created by the old refresh behavior.
        $this->pdo->prepare('UPDATE project_invoices SET status="unpaid",finalized_at=NULL WHERE id=?')
            ->execute([$firstStatementId]);
        $delivered = [];
        $delivery = project_invoice_send_email_result(
            $this->pdo,
            $firstStatementId,
            $this->config,
            null,
            false,
            null,
            true,
            static function (string $to) use (&$delivered): array {
                $delivered[] = $to;
                return [true, ''];
            }
        );
        self::assertSame(1, $delivery['sent']);
        self::assertSame(['project-lifecycle@example.invalid'], $delivered);
        $statement = $this->projectInvoiceRow($firstStatementId);
        self::assertNotEmpty($statement['finalized_at']);
        self::assertSame('unpaid', $statement['status']);

        $repeat = project_invoice_create_for_period_result(
            $this->pdo, $projectId, '2026-08-01', '2026-08-15', $this->config
        );
        self::assertSame('existing', $repeat['status']);
        self::assertSame($firstStatementId, $repeat['project_invoice_id']);

        $laterChild = $this->invoice($recipientId, 'regular', '2026-08-20', '2026-09-19', 30, 'terms');
        $backdatedChild = $this->invoice($recipientId, 'regular', '2026-08-05', '2026-09-04', 30, 'terms');
        $this->pdo->prepare('UPDATE invoices SET project_id=?,collection_mode="project_aggregate" WHERE id IN (?,?)')
            ->execute([$projectId, $laterChild, $backdatedChild]);

        $second = project_invoice_create_for_period_result(
            $this->pdo, $projectId, '2026-08-01', '2026-08-31', $this->config
        );
        self::assertSame('created', $second['status']);
        self::assertSame(2, $second['included_count']);
        $secondStatementId = (int)$second['project_invoice_id'];
        $this->ids['project_invoices'][] = $secondStatementId;
        $secondRow = $this->projectInvoiceRow($secondStatementId);
        self::assertSame('2026-08-16', $secondRow['billing_period_start']);
        self::assertSame('2026-08-31', $secondRow['billing_period_end']);

        $members = $this->pdo->prepare('SELECT invoice_id FROM project_invoice_items WHERE project_invoice_id=? ORDER BY invoice_id');
        $members->execute([$secondStatementId]);
        $expectedMembers = [$laterChild, $backdatedChild];
        sort($expectedMembers);
        self::assertSame($expectedMembers, array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN)));

        $secondRepeat = project_invoice_create_for_period_result(
            $this->pdo, $projectId, '2026-08-01', '2026-08-31', $this->config
        );
        self::assertSame('existing', $secondRepeat['status']);
        self::assertSame($secondStatementId, $secondRepeat['project_invoice_id']);

        $empty = project_invoice_create_for_period_result(
            $this->pdo, $projectId, '2026-09-01', '2026-09-30', $this->config
        );
        self::assertSame('empty', $empty['status']);
        self::assertNull($empty['project_invoice_id']);
        $statementCount = $this->pdo->prepare('SELECT COUNT(*) FROM project_invoices WHERE project_id=?');
        $statementCount->execute([$projectId]);
        self::assertSame(2, (int)$statementCount->fetchColumn());
    }

    public function testZeroBalanceProjectStatementNeverQueuesEmail(): void
    {
        $recipientId = $this->client('zero-project-statement@example.invalid');
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare(
            'INSERT INTO projects (client_id,organization_id,name,status,invoice_billing_period)
             VALUES (?, ?, ?, "active", "monthly")'
        )->execute([$recipientId, $orgId, 'Zero Balance Project']);
        $projectId = (int)$this->pdo->lastInsertId();
        $this->ids['projects'][] = $projectId;
        project_invoice_sync_recipients($this->pdo, $projectId, [$recipientId]);
        $this->pdo->prepare(
            'INSERT INTO project_invoices
             (project_id,organization_id,primary_client_id,doc_number,status,billing_period_start,billing_period_end,
              due_date,subtotal,total,amount_paid,balance_due)
             VALUES (?,?,?,? ,"draft","2026-08-01","2026-08-31","2026-09-30",0,0,0,0)'
        )->execute([$projectId, $orgId, $recipientId, random_int(100000, 999999)]);
        $statementId = (int)$this->pdo->lastInsertId();
        $this->ids['project_invoices'][] = $statementId;

        $delivery = project_invoice_send_email_result(
            $this->pdo,
            $statementId,
            $this->config,
            null,
            false,
            null,
            true,
            static fn(): array => [true, '']
        );
        self::assertSame(0, $delivery['sent']);
        self::assertStringContainsString('no outstanding balance', strtolower($delivery['message']));
        $queued = $this->pdo->prepare('SELECT COUNT(*) FROM project_invoice_notifications WHERE project_invoice_id=?');
        $queued->execute([$statementId]);
        self::assertSame(0, (int)$queued->fetchColumn());
    }

    public function testFirstOfMonthRunCollectsFinalizedOnDemandChargesAndLeavesDraftsForReview(): void
    {
        $recipientId = $this->client('monthly-on-demand@example.invalid');
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare(
            'INSERT INTO projects (client_id,organization_id,name,status,invoice_billing_period,project_invoice_auto_email)
             VALUES (?, ?, ?, "active", "monthly", 1)'
        )->execute([$recipientId, $orgId, 'Monthly On-Demand Project']);
        $projectId = (int)$this->pdo->lastInsertId();
        $this->ids['projects'][] = $projectId;
        project_invoice_sync_clients($this->pdo, $projectId, $recipientId, [$recipientId], [$recipientId]);
        project_invoice_sync_recipients($this->pdo, $projectId, [$recipientId]);

        $readyRegular = $this->invoice($recipientId, 'regular', '2026-08-08', '2026-09-30', 30, 'terms');
        $readyLongTerm = $this->invoice($recipientId, 'long_term', '2026-08-10', '2026-09-30', 30, 'terms');
        $readyOnDemand = $this->invoice($recipientId, 'on_demand', '2026-08-12', '2026-09-30', 30, 'terms');
        $draft = $this->invoice($recipientId, 'on_demand', '2026-08-20', '2026-09-30', 30, 'terms', 'draft');
        $this->pdo->prepare(
            'UPDATE invoices SET project_id=?,collection_mode="project_aggregate",subtotal=400,total=400,balance_due=400 WHERE id IN (?,?)'
        )->execute([$projectId, $readyRegular, $readyLongTerm]);
        $this->pdo->prepare(
            'UPDATE invoices SET project_id=?,collection_mode="project_aggregate",subtotal=400,total=400,balance_due=400 WHERE id IN (?,?)'
        )->execute([$projectId, $readyOnDemand, $draft]);

        $delivered = [];
        $sender = static function (string $to) use (&$delivered): array {
            $delivered[] = $to;
            return [true, ''];
        };
        $stats = project_invoice_generate_due_monthly_result(
            $this->pdo,
            $this->config,
            new DateTimeImmutable('2026-09-01 02:00:00'),
            $sender
        );
        self::assertGreaterThanOrEqual(1, $stats['generated']);
        self::assertSame(['monthly-on-demand@example.invalid'], $delivered);

        $statement = $this->pdo->prepare(
            'SELECT id,billing_period_start,billing_period_end,total,balance_due,sent_at
             FROM project_invoices WHERE project_id=? AND billing_period_end="2026-08-31"'
        );
        $statement->execute([$projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertNotEmpty($row);
        $statementId = (int)$row['id'];
        $this->ids['project_invoices'][] = $statementId;
        self::assertSame('2026-08-01', $row['billing_period_start']);
        self::assertEqualsWithDelta(1200.0, (float)$row['total'], 0.005);
        self::assertNotEmpty($row['sent_at']);

        $members = $this->pdo->prepare('SELECT invoice_id FROM project_invoice_items WHERE project_invoice_id=?');
        $members->execute([$statementId]);
        $expectedMembers = [$readyRegular, $readyLongTerm, $readyOnDemand];
        sort($expectedMembers);
        $actualMembers = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
        sort($actualMembers);
        self::assertSame($expectedMembers, $actualMembers);
        self::assertSame('draft', (string)$this->row('invoices', $draft)['status']);

        $repeat = project_invoice_generate_due_monthly_result(
            $this->pdo,
            $this->config,
            new DateTimeImmutable('2026-09-01 03:00:00'),
            $sender
        );
        self::assertGreaterThanOrEqual(1, $repeat['existing']);
        self::assertSame(1, $repeat['already_delivered']);
        self::assertSame(['monthly-on-demand@example.invalid'], $delivered, 'The same monthly statement must not be emailed twice.');
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM project_invoices WHERE project_id=? AND billing_period_end="2026-08-31"');
        $count->execute([$projectId]);
        self::assertSame(1, (int)$count->fetchColumn());
    }

    public function testFirstOfMonthRunDoesNotStrandFinalizedChargesAfterProjectCompletion(): void
    {
        $recipientId = $this->client('completed-project-billing@example.invalid');
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare(
            'INSERT INTO projects (client_id,organization_id,name,status,invoice_billing_period,project_invoice_auto_email)
             VALUES (?, ?, ?, "completed", "monthly", 0)'
        )->execute([$recipientId, $orgId, 'Completed Monthly Project']);
        $projectId = (int)$this->pdo->lastInsertId();
        $this->ids['projects'][] = $projectId;
        project_invoice_sync_clients($this->pdo, $projectId, $recipientId, [$recipientId], [$recipientId]);

        $invoiceId = $this->invoice($recipientId, 'on_demand', '2026-08-28', '2026-09-30', 30, 'terms');
        $this->pdo->prepare(
            'UPDATE invoices SET project_id=?,collection_mode="project_aggregate" WHERE id=?'
        )->execute([$projectId, $invoiceId]);

        $stats = project_invoice_generate_due_monthly_result(
            $this->pdo,
            $this->config,
            new DateTimeImmutable('2026-09-01 02:00:00')
        );
        self::assertGreaterThanOrEqual(1, $stats['generated']);
        $statement = $this->pdo->prepare(
            'SELECT id,status,total FROM project_invoices WHERE project_id=? AND billing_period_end="2026-08-31"'
        );
        $statement->execute([$projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertNotEmpty($row);
        $this->ids['project_invoices'][] = (int)$row['id'];
        self::assertSame('draft', $row['status'], 'Auto-email-off statements remain reviewable drafts.');
        self::assertEqualsWithDelta(100.0, (float)$row['total'], 0.005);
    }

    public function testCanonicalTenantUrlAndDocumentPdfFailureBoundaries(): void
    {
        self::assertSame('https://billing.example.invalid', invoice_notification_public_base([
            'app_host' => 'billing.example.invalid/',
        ]));
        try {
            invoice_notification_public_base(['app_host' => 'https://user:secret@wrong.example']);
            self::fail('Credential-bearing public URL must be rejected.');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('invalid', strtolower($expected->getMessage()));
        }

        $clientId = $this->client('pdf-limit@example.invalid');
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare(
            'INSERT INTO quotes (client_id,organization_id,doc_number,status,subtotal,total,scope) VALUES (?,?,?,"pending",100,100,"Quoted work")'
        )->execute([$clientId, $orgId, random_int(100000, 999999)]);
        $quoteId = (int)$this->pdo->lastInsertId();
        $this->ids['quotes'][] = $quoteId;
        $quotePdf = document_pdf_attachment($this->pdo, $this->config, 'quote', $quoteId, (string)$quoteId);
        self::assertStringStartsWith('%PDF-', $quotePdf['content']);

        $contractId = $this->contract($clientId);
        $contractPdf = document_pdf_attachment($this->pdo, $this->config, 'contract', $contractId, (string)$contractId);
        self::assertStringStartsWith('%PDF-', $contractPdf['content']);

        $invoiceId = $this->invoice($clientId, 'regular', '2026-07-01', '2026-08-06', 30, 'terms');
        try {
            document_pdf_attachment($this->pdo, $this->config, 'invoice', $invoiceId, (string)$invoiceId, 1);
            self::fail('Oversize PDF must fail closed.');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('size limit', $expected->getMessage());
        }

        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/email_send.php');
        self::assertStringContainsString("['quote','contract','invoice']", $controller);
        self::assertStringContainsString('require_record_ownership($pdo, $ownershipTable, $id)', $controller);
        self::assertStringContainsString('public_links_in_email', $controller);
        self::assertStringContainsString('document_pdf_attachment($pdo, $appConfig, $type, $id, $docnum)', $controller);
        self::assertStringNotContainsString("HTTP_HOST", $controller);

        $sharedView = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/invoice/invoice-details.php');
        self::assertStringContainsString('Payment terms:', $sharedView);
        self::assertStringContainsString('$paymentTermsSummary', $sharedView);
    }
    private function client(string $email): int
    {
        if (empty($this->ids['organizations'])) {
            $this->pdo->prepare('INSERT INTO organizations (name) VALUES (?)')->execute(['Automation ' . bin2hex(random_bytes(4))]);
            $this->ids['organizations'][] = (int)$this->pdo->lastInsertId();
        }
        $orgId = $this->ids['organizations'][0];
        $this->pdo->prepare('INSERT INTO clients (name,email,organization_id) VALUES (?,?,?)')
            ->execute(['Automation Client', $email, $orgId]);
        $id = (int)$this->pdo->lastInsertId();
        $this->ids['clients'][] = $id;
        return $id;
    }

    private function invoice(
        int $clientId,
        string $type,
        string $documentDate,
        string $dueDate,
        ?int $termDays,
        string $source,
        string $status = 'unpaid'
    ): int {
        $finalized = $status === 'draft' ? null : $documentDate . ' 09:00:00';
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices
             (client_id,organization_id,doc_number,invoice_type,status,subtotal,total,amount_paid,balance_due,
              document_date,due_date,payment_terms_days,due_date_source,finalized_at,collection_mode)
             VALUES (?,?,?, ?,?,100,100,0,100, ?,?,?,?, ?,"direct")'
        );
        $stmt->execute([
            $clientId, $this->ids['organizations'][0], random_int(100000, 999999), $type, $status,
            $documentDate . ' 09:00:00', $dueDate, $termDays, $source, $finalized,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->ids['invoices'][] = $id;
        return $id;
    }

    private function contract(int $clientId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO contracts
             (client_id,organization_id,doc_number,status,contract_type,pricing_type,price_per_invoice,total,
              next_invoice_date,billing_interval_count,billing_interval_unit,signed_pdf_path,scope)
             VALUES (?,?,?,"active","long_term","per_invoice",100,100,CURDATE(),1,"month","signed/test.pdf","Service")'
        );
        $stmt->execute([$clientId, $this->ids['organizations'][0], random_int(100000, 999999)]);
        $id = (int)$this->pdo->lastInsertId();
        $this->ids['contracts'][] = $id;
        return $id;
    }

    private function row(string $table, int $id): array
    {
        self::assertContains($table, ['contracts', 'invoices']);
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id=?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function projectInvoiceRow(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_invoices WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function notification(int $invoiceId, string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoice_notifications WHERE invoice_id=? AND notification_type=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$invoiceId, $type]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function notificationCount(int $invoiceId, string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM invoice_notifications WHERE invoice_id=? AND notification_type=?');
        $stmt->execute([$invoiceId, $type]);
        return (int)$stmt->fetchColumn();
    }
}
