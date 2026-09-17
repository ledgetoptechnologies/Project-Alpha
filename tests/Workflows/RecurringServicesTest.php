<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/recurring_billing.php';

use PHPUnit\Framework\TestCase;

final class RecurringServicesTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $exists = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='contract_recurring_services'")->fetchColumn();
            if ((int)$exists !== 1) {
                $this->markTestSkipped('Recurring service migration is not applied.');
            }
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        foreach (array_reverse($this->ids['invoices'] ?? []) as $invoiceId) {
            $this->pdo->prepare('DELETE FROM invoice_notifications WHERE invoice_id=?')->execute([$invoiceId]);
            $this->pdo->prepare('DELETE FROM public_links WHERE document_type="invoice" AND document_id=?')->execute([$invoiceId]);
        }
        foreach (array_reverse($this->ids['invoice_notifications'] ?? []) as $id) $this->pdo->prepare('DELETE FROM invoice_notifications WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['public_links'] ?? []) as $id) $this->pdo->prepare('DELETE FROM public_links WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['invoice_items'] ?? []) as $id) $this->pdo->prepare('DELETE FROM invoice_items WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['invoices'] ?? []) as $id) $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['contract_amendments'] ?? []) as $id) $this->pdo->prepare('DELETE FROM contract_amendments WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['services'] ?? []) as $id) $this->pdo->prepare('DELETE FROM contract_recurring_services WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['contracts'] ?? []) as $id) $this->pdo->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['clients'] ?? []) as $id) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
        foreach (array_reverse($this->ids['organizations'] ?? []) as $id) $this->pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$id]);
    }

    public function testIndependentAddonScheduleGeneratesAndAutomaticallyEmailsInvoice(): void
    {
        $today = date('Y-m-d');
        $annualDate = date('Y-m-d', strtotime('+6 months'));
        $orgId = $this->remember('organizations', $this->insert('INSERT INTO organizations (name) VALUES (?)', ['Recurring Services ' . bin2hex(random_bytes(3))]));
        $clientId = $this->remember('clients', $this->insert('INSERT INTO clients (name,email,organization_id) VALUES (?,?,?)', ['Recurring Client', 'recurring@example.invalid', $orgId]));
        $contractId = $this->remember('contracts', $this->insert('
            INSERT INTO contracts
                (client_id,organization_id,status,contract_type,pricing_type,price_per_invoice,
                 billing_interval_count,billing_interval_unit,start_date,next_invoice_date,
                 signed_pdf_path,total_invoiced,invoices_generated,subtotal,total)
            VALUES (?,? ,"active","long_term","per_invoice",1200,1,"year",?,?,"signed.pdf",0,0,1200,1200)
        ', [$clientId, $orgId, $today, $today]));

        $hostingId = $this->remember('services', $this->insert('
            INSERT INTO contract_recurring_services
                (contract_id,name,amount,billing_interval_count,billing_interval_unit,effective_from,next_invoice_date,status,approval_status,is_base)
            VALUES (?,"Website Hosting",1200,1,"year",?, ?,"active","approved",1)
        ', [$contractId, $today, $annualDate]));
        $adsId = $this->remember('services', $this->insert('
            INSERT INTO contract_recurring_services
                (contract_id,name,description,amount,billing_interval_count,billing_interval_unit,effective_from,next_invoice_date,status,approval_status,is_base)
            VALUES (?,"Advertising Management","Campaign optimization",500,1,"month",?, ?,"active","approved",0)
        ', [$contractId, $today, $today]));

        $contractStmt = $this->pdo->prepare('SELECT * FROM contracts WHERE id=?');
        $contractStmt->execute([$contractId]);
        $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
        $deliveries = [];
        $config = [
            'net_terms_days' => 14,
            'invoice_auto_email_on_generate' => 1,
            'public_links_in_email' => 1,
            'app_host' => 'https://example.invalid',
            '_email_sender' => static function (string $to, string $subject, string $body, array $context) use (&$deliveries): array {
                $deliveries[] = compact('to', 'subject', 'body', 'context');
                return [true, ''];
            },
        ];

        $invoiceId = generate_recurring_invoice($this->pdo, $contract, $config);
        self::assertNotNull($invoiceId);
        $this->remember('invoices', (int)$invoiceId);
        $itemIds = $this->pdo->prepare('SELECT id FROM invoice_items WHERE invoice_id=?');
        $itemIds->execute([$invoiceId]);
        foreach ($itemIds->fetchAll(PDO::FETCH_COLUMN) as $itemId) $this->remember('invoice_items', (int)$itemId);

        $invoiceStmt = $this->pdo->prepare('SELECT status,subtotal,total,balance_due,finalized_at,sent_at FROM invoices WHERE id=?');
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('unpaid', $invoice['status']);
        self::assertEqualsWithDelta(500.0, (float)$invoice['subtotal'], 0.005);
        self::assertEqualsWithDelta((float)$invoice['total'], (float)$invoice['balance_due'], 0.005);
        self::assertNotEmpty($invoice['finalized_at']);

        $lineStmt = $this->pdo->prepare('SELECT item,line_total FROM invoice_items WHERE invoice_id=?');
        $lineStmt->execute([$invoiceId]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $lines);
        self::assertSame('Advertising Management', $lines[0]['item']);
        self::assertEqualsWithDelta(500.0, (float)$lines[0]['line_total'], 0.005);

        $hostingStmt = $this->pdo->prepare('SELECT next_invoice_date FROM contract_recurring_services WHERE id=?');
        $hostingStmt->execute([$hostingId]);
        self::assertSame($annualDate, (string)$hostingStmt->fetchColumn(), 'The annual hosting schedule must not move when the monthly ads invoice is generated.');
        $adsStmt = $this->pdo->prepare('SELECT next_invoice_date FROM contract_recurring_services WHERE id=?');
        $adsStmt->execute([$adsId]);
        self::assertSame(
            pa_recurring_service_next_date($today, 1, 'month', $today),
            (string)$adsStmt->fetchColumn(),
            'Month-end schedules stay anchored to the last valid day of the next month.'
        );

        self::assertTrue(recurring_invoice_send_on_generate_if_enabled($this->pdo, $invoiceId, $config));
        self::assertCount(1, $deliveries);
        self::assertSame('recurring@example.invalid', $deliveries[0]['to']);
        self::assertSame((int)$invoiceId, (int)$deliveries[0]['context']['invoice_id']);
        self::assertStringContainsString('Invoice LTI-', $deliveries[0]['subject']);

        $notificationStmt = $this->pdo->prepare('SELECT id,sent_at FROM invoice_notifications WHERE invoice_id=? AND notification_type="on_generate"');
        $notificationStmt->execute([$invoiceId]);
        $notification = $notificationStmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotEmpty($notification['sent_at']);
        $this->remember('invoice_notifications', (int)$notification['id']);
        $linkStmt = $this->pdo->prepare('SELECT id,expire_when_paid,revoked FROM public_links WHERE document_type="invoice" AND document_id=?');
        $linkStmt->execute([$invoiceId]);
        $link = $linkStmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int)$link['expire_when_paid']);
        self::assertSame(0, (int)$link['revoked']);
        $this->remember('public_links', (int)$link['id']);

        self::assertTrue(recurring_invoice_send_on_generate_if_enabled($this->pdo, $invoiceId, $config));
        self::assertCount(1, $deliveries, 'Automatic delivery must be idempotent.');
    }

    public function testMonthlyAndAnnualSchedulesKeepTheirCalendarAnchor(): void
    {
        self::assertSame('2026-02-28', pa_recurring_service_next_date('2026-01-31', 1, 'month', '2026-01-31'));
        self::assertSame('2026-03-31', pa_recurring_service_next_date('2026-02-28', 1, 'month', '2026-01-31'));
        self::assertSame('2025-02-28', pa_recurring_service_next_date('2024-02-29', 1, 'year', '2024-02-29'));
        self::assertSame('2028-02-29', pa_recurring_service_next_date('2027-02-28', 1, 'year', '2024-02-29'));
    }

    private function insert(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    private function remember(string $bucket, int $id): int
    {
        $this->ids[$bucket][] = $id;
        return $id;
    }
}
