<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/recurring_billing.php';

final class RecurringScheduleBillingDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $clientId = 0;
    private int $contractId = 0;
    private int $serviceId = 0;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $this->pdo->query('SELECT 1 FROM recurring_invoice_coverage LIMIT 1');
        } catch (Throwable $error) {
            $this->markTestSkipped('Migrated MySQL test database unavailable.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->contractId) {
            $this->pdo->prepare("DELETE FROM public_links WHERE document_type='invoice' AND document_id IN (SELECT id FROM invoices WHERE contract_id=?)")->execute([$this->contractId]);
            $this->pdo->prepare('DELETE FROM payments WHERE contract_id=?')->execute([$this->contractId]);
            $this->pdo->prepare('DELETE FROM invoices WHERE contract_id=?')->execute([$this->contractId]);
            $this->pdo->prepare('DELETE FROM contracts WHERE id=?')->execute([$this->contractId]);
        }
        if ($this->clientId) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->clientId]);
    }

    private function setupService(string $unit = 'year', string $rateBasis = 'monthly'): void
    {
        $this->pdo->exec("INSERT INTO clients (name,email) VALUES ('Synthetic billing schedule test','schedule@example.invalid')");
        $this->clientId = (int)$this->pdo->lastInsertId();
        $amount = $unit === 'year' ? '1200.12' : '100.01';
        $this->pdo->prepare('INSERT INTO contracts (client_id,status,contract_type,pricing_type,price_per_invoice,billing_interval_count,billing_interval_unit,start_date,next_invoice_date,signed_pdf_path,scope,subtotal,total,rate_basis,monthly_rate) VALUES (?,"active","long_term","per_invoice",?,1,?,"2026-01-01","2026-01-01","synthetic-signed.pdf","Immutable signed service agreement",?,?,?,?)')
            ->execute([$this->clientId,$amount,$unit,$amount,$amount,$rateBasis,$rateBasis === 'monthly' ? '100.01' : null]);
        $this->contractId = (int)$this->pdo->lastInsertId();
        $this->serviceId = (int)pa_recurring_service_ensure_base($this->pdo, $this->contractId);
    }

    private function row(string $table, int $id): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM $table WHERE id=?");
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function generate(string $date, ?PDO $pdo = null): ?int
    {
        return generate_recurring_invoice($pdo ?? $this->pdo, ['id'=>$this->contractId], ['invoice_auto_email_on_generate'=>0,'from_company'=>'Synthetic billing tests','brand_name'=>'Synthetic billing tests'], new DateTimeImmutable($date));
    }

    private function queue(string $unit, bool $approved = true, string $today = '2026-07-01', string $monthlyRate = '100.01'): int
    {
        $this->pdo->beginTransaction();
        $statement = $this->pdo->prepare('SELECT id FROM contracts WHERE id=? FOR UPDATE');
        $statement->execute([$this->contractId]);
        $statement = $this->pdo->prepare('SELECT * FROM contract_recurring_services WHERE id=? FOR UPDATE');
        $statement->execute([$this->serviceId]);
        $service = $statement->fetch(PDO::FETCH_ASSOC);
        $proposal = array_replace(pa_recurring_service_snapshot($service), [
            'rate_basis'=>'monthly','monthly_rate'=>$monthlyRate,'billing_interval_count'=>1,'billing_interval_unit'=>$unit,
            'amount'=>pa_recurring_service_period_amount($monthlyRate, 1, $unit),
        ]);
        $id = pa_recurring_service_queue_change($this->pdo, $service, $proposal, $approved, null, null, $today);
        $this->pdo->commit();
        return $id;
    }

    public function testPaidAnnualCoverageDefersMonthlyChangeAndPreservesSignedAgreement(): void
    {
        $this->setupService();
        $signed = $this->row('contracts', $this->contractId);
        $annualId = $this->generate('2026-01-01');
        self::assertNotNull($annualId);
        invoice_record_locked_payment($this->pdo, $annualId, 1200.12, 'bank_transfer', 'synthetic', 'Synthetic annual prepayment', ['payment_date'=>'2026-01-01']);
        $paid = $this->row('invoices', $annualId);
        self::assertSame('paid', $paid['status']);
        $changeId = $this->queue('month');
        self::assertSame('2027-01-01', $this->row('contract_recurring_service_changes', $changeId)['effective_date']);
        self::assertNull($this->generate('2026-07-01'));
        self::assertNull($this->generate('2026-12-31'));
        self::assertSame($paid, $this->row('invoices', $annualId), 'Paid invoice is immutable during schedule changes.');
        $monthlyId = $this->generate('2027-01-01');
        self::assertNotNull($monthlyId);
        self::assertSame('100.01', $this->row('invoices', $monthlyId)['total']);
        self::assertNull($this->generate('2027-01-01'), 'Retry cannot duplicate the renewal.');
        $contract = $this->row('contracts', $this->contractId);
        foreach (['scope','price_per_invoice','billing_interval_unit','start_date','signed_pdf_path','subtotal','total'] as $field) self::assertSame($signed[$field], $contract[$field], $field);
        self::assertSame('2027-02-01', $contract['next_invoice_date']);
        $coverage = $this->pdo->prepare('SELECT * FROM recurring_invoice_coverage WHERE invoice_id=?');
        $coverage->execute([$monthlyId]);$period = $coverage->fetch(PDO::FETCH_ASSOC);
        self::assertSame('2027-01-01', $period['coverage_start']);
        self::assertSame('2027-02-01', $period['coverage_end']);
        self::assertSame('100.01', $period['monthly_rate']);
        $line = $this->pdo->prepare('SELECT description FROM invoice_items WHERE invoice_id=?');$line->execute([$monthlyId]);
        self::assertStringContainsString('Monthly rate 100.01 x 1 month', $line->fetchColumn());
    }

    public function testMonthlyToAnnualUsesTwelveMonthsAndSnapshotsCoverage(): void
    {
        $this->setupService('month');
        self::assertNotNull($this->generate('2026-01-01'));
        $this->queue('year', true, '2026-01-15');
        self::assertNull($this->generate('2026-01-31'));
        $annualId = $this->generate('2026-02-01');
        self::assertNotNull($annualId);
        self::assertSame('1200.12', $this->row('invoices', $annualId)['total']);
        self::assertSame('2027-02-01', $this->row('contract_recurring_services', $this->serviceId)['next_invoice_date']);
        $line = $this->pdo->prepare('SELECT description FROM invoice_items WHERE invoice_id=?');$line->execute([$annualId]);
        self::assertStringContainsString('Monthly rate 100.01 x 12 months', $line->fetchColumn());
    }

    public function testPendingChangeDoesNotInterruptBillingAndCannotBeApprovedAfterBoundaryWasBilled(): void
    {
        $this->setupService('month');
        self::assertNotNull($this->generate('2026-01-01'));
        $id = $this->queue('year', false, '2026-01-15');
        $invoice = $this->generate('2026-02-01');
        self::assertNotNull($invoice);
        self::assertSame('100.01', $this->row('invoices', $invoice)['total']);
        self::assertSame('pending', $this->row('contract_recurring_service_changes', $id)['state']);
        $this->pdo->beginTransaction();
        $this->expectException(DomainException::class);
        pa_recurring_service_change_action($this->pdo, $this->row('contract_recurring_services', $this->serviceId), $id, 'approve_change', '2026-02-02');
    }

    public function testLegacyPerPeriodAmountRemainsAnnualAmountWithoutMultiplication(): void
    {
        $this->setupService('year', 'per_period');
        $before = $this->row('contract_recurring_services', $this->serviceId);
        $invoice = $this->generate('2026-01-01');
        self::assertNotNull($invoice);
        self::assertSame('1200.12', $this->row('invoices', $invoice)['total']);
        $service = $this->row('contract_recurring_services', $this->serviceId);
        foreach (['amount','billing_interval_count','billing_interval_unit','effective_from','rate_basis','monthly_rate'] as $field) self::assertSame($before[$field], $service[$field]);
        self::assertSame('per_period', $service['rate_basis']);
        self::assertNull($service['monthly_rate']);
    }

    private function moveInitialAnchor(string $date): void
    {
        $this->pdo->prepare('UPDATE contracts SET start_date=?,next_invoice_date=? WHERE id=?')->execute([$date,$date,$this->contractId]);
        $this->pdo->prepare('UPDATE contract_recurring_services SET effective_from=?,next_invoice_date=?,billing_anchor_date=? WHERE id=?')->execute([$date,$date,$date,$this->serviceId]);
    }

    public function testRateOnlyAmendmentPreservesJanuary31AnchorAcrossFebruaryRenewal(): void
    {
        $this->setupService('month');
        $this->moveInitialAnchor('2026-01-31');
        self::assertNotNull($this->generate('2026-01-31'));
        self::assertSame('2026-02-28',$this->row('contract_recurring_services',$this->serviceId)['next_invoice_date']);
        $changeId = $this->queue('month',true,'2026-02-15','125.03');
        $change = $this->row('contract_recurring_service_changes',$changeId);
        $proposal = json_decode($change['new_values'],true);
        self::assertSame('2026-02-28',$change['effective_date']);
        self::assertSame('2026-02-28',$proposal['effective_from'],'Amendment metadata still records its actual effective date.');
        self::assertSame('2026-01-31',$proposal['billing_anchor_date']);
        $invoice = $this->generate('2026-02-28');
        self::assertNotNull($invoice);
        self::assertSame('125.03',$this->row('invoices',$invoice)['total']);
        self::assertSame('2026-01-31',$this->row('contract_recurring_services',$this->serviceId)['billing_anchor_date']);
        self::assertSame('2026-02-28',$this->row('contract_recurring_services',$this->serviceId)['effective_from']);
        self::assertSame('2026-03-31',$this->row('contract_recurring_services',$this->serviceId)['next_invoice_date']);
        self::assertNull($this->generate('2026-03-28'));
        self::assertNotNull($this->generate('2026-03-31'));
        self::assertSame('2026-04-30',$this->row('contract_recurring_services',$this->serviceId)['next_invoice_date']);
    }

    public function testAnnualRateOnlyAmendmentPreservesLeapDayAnniversary(): void
    {
        $this->setupService('year');
        $this->moveInitialAnchor('2024-02-29');
        self::assertNotNull($this->generate('2024-02-29'));
        $this->queue('year',true,'2025-02-01','125.03');
        $invoice = $this->generate('2025-02-28');
        self::assertNotNull($invoice);
        self::assertSame('1500.36',$this->row('invoices',$invoice)['total']);
        self::assertSame('2024-02-29',$this->row('contract_recurring_services',$this->serviceId)['billing_anchor_date']);
        self::assertNotNull($this->generate('2026-02-28'));
        self::assertNotNull($this->generate('2027-02-28'));
        self::assertSame('2028-02-29',$this->row('contract_recurring_services',$this->serviceId)['next_invoice_date']);
        self::assertNull($this->generate('2028-02-28'));
        self::assertNotNull($this->generate('2028-02-29'));
    }

    public function testCadenceChangeStartsNewAnniversaryAtFebruaryRenewal(): void
    {
        $this->setupService('month');
        $this->moveInitialAnchor('2026-01-31');
        self::assertNotNull($this->generate('2026-01-31'));
        $changeId = $this->queue('year',true,'2026-02-15');
        $change = $this->row('contract_recurring_service_changes',$changeId);
        self::assertSame('2026-02-28',json_decode($change['new_values'],true)['billing_anchor_date']);
        self::assertNotNull($this->generate('2026-02-28'));
        $service = $this->row('contract_recurring_services',$this->serviceId);
        self::assertSame('2026-02-28',$service['effective_from']);
        self::assertSame('2027-02-28',$service['next_invoice_date']);
    }

    public function testLegacyToMonthlyRateStartsFullAnnualPeriodAtConversionBoundary(): void
    {
        $this->setupService('year','per_period');
        $this->pdo->prepare("UPDATE contracts SET next_invoice_date='2026-02-01' WHERE id=?")->execute([$this->contractId]);
        $this->pdo->prepare("UPDATE contract_recurring_services SET next_invoice_date='2026-02-01' WHERE id=?")->execute([$this->serviceId]);
        $changeId=$this->queue('year',true,'2026-01-15');
        $proposal=json_decode($this->row('contract_recurring_service_changes',$changeId)['new_values'],true);
        self::assertSame('2026-02-01',$proposal['billing_anchor_date']);
        self::assertNotNull($this->generate('2026-02-01'));
        self::assertSame('2027-02-01',$this->row('contract_recurring_services',$this->serviceId)['next_invoice_date']);
    }

    public function testMonthlyRateAnnualGenerationRejectsPartialFinalYearWithoutMutatingSchedule(): void
    {
        $this->setupService('year');
        $this->pdo->prepare("UPDATE contract_recurring_services SET effective_until='2026-06-30' WHERE id=?")->execute([$this->serviceId]);
        $before = $this->row('contract_recurring_services',$this->serviceId);
        self::assertNull($this->generate('2026-01-01'));
        self::assertSame($before,$this->row('contract_recurring_services',$this->serviceId));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE contract_id=?');$count->execute([$this->contractId]);
        self::assertSame(0,(int)$count->fetchColumn());
    }

    public function testAnnualChangeCannotExceedInclusiveServiceEnd(): void
    {
        $this->setupService('month');
        $this->pdo->prepare("UPDATE contract_recurring_services SET effective_until='2026-06-30' WHERE id=?")->execute([$this->serviceId]);
        self::assertNotNull($this->generate('2026-01-01'));
        $before = $this->row('contract_recurring_services',$this->serviceId);
        try {
            $this->queue('year',true,'2026-01-15');
            self::fail('Annual change extending past the service end must be rejected.');
        } catch (DomainException $error) {
            self::assertStringContainsString('extends beyond',$error->getMessage());
            $this->pdo->rollBack();
        }
        self::assertSame($before,$this->row('contract_recurring_services',$this->serviceId));
        self::assertNotNull($this->generate('2026-02-01'));
    }

    public function testFullYearEndingOnInclusiveLastCoveredDayIsAccepted(): void
    {
        $this->setupService('year');
        $this->pdo->prepare("UPDATE contract_recurring_services SET effective_until='2026-12-31' WHERE id=?")->execute([$this->serviceId]);
        self::assertNotNull($this->generate('2026-01-01'));
        $service = $this->row('contract_recurring_services',$this->serviceId);
        self::assertSame('ended',$service['status']);self::assertNull($service['next_invoice_date']);
    }

    public function testShiftedRenewalCannotOverlapAnAlreadyInvoicedAnnualPeriod(): void
    {
        $this->setupService();
        $annualId = $this->generate('2026-01-01');
        self::assertNotNull($annualId);
        $this->pdo->prepare("UPDATE contract_recurring_services SET next_invoice_date='2026-07-01' WHERE id=?")->execute([$this->serviceId]);
        $this->pdo->prepare("UPDATE contracts SET next_invoice_date='2026-07-01' WHERE id=?")->execute([$this->contractId]);
        self::assertNull($this->generate('2026-07-01'));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE contract_id=?');$count->execute([$this->contractId]);
        self::assertSame(1, (int)$count->fetchColumn(), 'The attempted overlapping invoice is rolled back.');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testContractLockPreventsConcurrentGenerationDuringScheduleChange(): void
    {
        $this->setupService('month');
        $other = migration_connection();
        $other->exec('SET SESSION innodb_lock_wait_timeout=1');
        $this->pdo->beginTransaction();
        $this->pdo->prepare('SELECT id FROM contracts WHERE id=? FOR UPDATE')->execute([$this->contractId]);
        $service = $this->row('contract_recurring_services', $this->serviceId);
        $proposal = array_replace(pa_recurring_service_snapshot($service), ['billing_interval_unit'=>'year','amount'=>'1200.12']);
        pa_recurring_service_queue_change($this->pdo, $service, $proposal, true, null, null, '2026-01-01');
        self::assertNull($this->generate('2026-01-01', $other), 'Another generator cannot bypass the schedule transaction lock.');
        self::assertFalse($other->inTransaction());
        $this->pdo->commit();
        $invoice = $this->generate('2026-01-01', $other);
        self::assertNotNull($invoice);
        self::assertSame('1200.12', $this->row('invoices', $invoice)['total']);
        self::assertNull($this->generate('2026-01-01'));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM recurring_invoice_coverage WHERE recurring_service_id=?');$count->execute([$this->serviceId]);
        self::assertSame(1, (int)$count->fetchColumn());
    }
}
