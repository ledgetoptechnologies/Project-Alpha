<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/recurring_services.php';

final class RecurringScheduleChangesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE contract_recurring_services (id INTEGER PRIMARY KEY,contract_id INTEGER,name TEXT,description TEXT,amount TEXT,billing_interval_count INTEGER,billing_interval_unit TEXT,effective_from TEXT,effective_until TEXT,next_invoice_date TEXT,status TEXT,approval_status TEXT,rate_basis TEXT,monthly_rate TEXT)');
        $this->pdo->exec('CREATE TABLE contract_recurring_service_changes (id INTEGER PRIMARY KEY,contract_id INTEGER,recurring_service_id INTEGER,effective_date TEXT,state TEXT,old_values TEXT,new_values TEXT,signed_document_path TEXT,created_by INTEGER,approved_at TEXT,applied_at TEXT)');
        $this->pdo->exec("INSERT INTO contract_recurring_services VALUES (1,10,'Hosting',NULL,'1200.00',1,'year','2026-01-01',NULL,'2027-01-01','active','approved','monthly','100.00')");
    }

    private function service(): array
    {
        return $this->pdo->query('SELECT * FROM contract_recurring_services WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    }

    private function monthlyProposal(): array
    {
        return array_replace(pa_recurring_service_snapshot($this->service()), ['amount'=>'100.00','billing_interval_unit'=>'month']);
    }

    public function testAnnualAmountIsTwelveMonthlyRatesWithExactCents(): void
    {
        self::assertSame('1200.12', pa_recurring_service_period_amount('100.01', 1, 'year'));
        self::assertSame('100.01', pa_recurring_service_period_amount('100.01', 1, 'month'));
        self::assertSame('0.12', pa_recurring_service_period_amount('0.01', 1, 'year'));
    }

    public function testMonthlyRateRejectsUnsupportedCadence(): void
    {
        $this->expectException(DomainException::class);
        pa_recurring_service_period_amount('100.00', 1, 'week');
    }

    public function testAnnualCalculationRejectsDatabaseOverflow(): void
    {
        $this->expectException(DomainException::class);
        pa_recurring_service_period_amount('9999999999.99', 1, 'year');
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        self::assertFalse(pa_recurring_valid_date('2026-02-30'));
        self::assertFalse(pa_recurring_valid_date('2026-1-01'));
        self::assertTrue(pa_recurring_valid_date('2028-02-29'));
    }

    public function testAnnualToMonthlyRequestedMidyearWaitsForExistingCoverage(): void
    {
        $old = $this->service();
        $this->pdo->beginTransaction();
        pa_recurring_service_queue_change($this->pdo, $old, $this->monthlyProposal(), true, null, null, '2026-07-01');
        $this->pdo->commit();
        self::assertSame($old, $this->service(), 'Editing must not rewrite the active annual service.');
        $change = $this->pdo->query('SELECT * FROM contract_recurring_service_changes')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('2027-01-01', $change['effective_date']);
        self::assertSame('1200.00', json_decode($change['old_values'], true)['amount']);
        $this->pdo->beginTransaction();
        $next = pa_recurring_service_apply_change($this->pdo, $this->service());
        $this->pdo->commit();
        self::assertSame('month', $next['billing_interval_unit']);
        self::assertSame('100.00', $next['amount']);
        self::assertSame('2027-01-01', $next['effective_from']);
        self::assertSame('2027-02-01', pa_recurring_service_next_date($next['next_invoice_date'], 1, 'month', $next['effective_from']));
        self::assertSame('applied', $this->pdo->query('SELECT state FROM contract_recurring_service_changes')->fetchColumn());
    }

    public function testPendingProposalDoesNotDisableOrRepriceCurrentService(): void
    {
        $old = $this->service();
        $this->pdo->beginTransaction();
        pa_recurring_service_queue_change($this->pdo, $old, $this->monthlyProposal(), false, null, null, '2026-07-01');
        self::assertSame($old, pa_recurring_service_apply_change($this->pdo, $old));
        $this->pdo->commit();
        self::assertSame($old, $this->service());
    }

    public function testDuplicatePendingChangeIsRejected(): void
    {
        $this->pdo->beginTransaction();
        pa_recurring_service_queue_change($this->pdo, $this->service(), $this->monthlyProposal(), true, null, null, '2026-07-01');
        $this->expectException(DomainException::class);
        pa_recurring_service_queue_change($this->pdo, $this->service(), $this->monthlyProposal(), true, null, null, '2026-07-01');
    }

    public function testCancelledChangeLeavesOriginalScheduleAndAllowsReplacement(): void
    {
        $old = $this->service();
        $this->pdo->beginTransaction();
        $id = pa_recurring_service_queue_change($this->pdo, $old, $this->monthlyProposal(), true, null, null, '2026-07-01');
        pa_recurring_service_change_action($this->pdo, $old, $id, 'cancel_change');
        self::assertSame($old, pa_recurring_service_apply_change($this->pdo, $old));
        $replacement = pa_recurring_service_queue_change($this->pdo, $old, $this->monthlyProposal(), true, null, null, '2026-07-01');
        self::assertGreaterThan($id, $replacement);
        $this->pdo->commit();
    }

    public function testApprovalCannotMovePastAlreadyBilledBoundary(): void
    {
        $this->pdo->beginTransaction();
        $id = pa_recurring_service_queue_change($this->pdo, $this->service(), $this->monthlyProposal(), false, null, null, '2026-07-01');
        $this->pdo->exec("UPDATE contract_recurring_services SET next_invoice_date='2028-01-01'");
        $this->expectException(DomainException::class);
        pa_recurring_service_change_action($this->pdo, $this->service(), $id, 'approve_change', '2027-01-02');
    }

    public function testAnotherContractCannotApproveChange(): void
    {
        $this->pdo->beginTransaction();
        $id = pa_recurring_service_queue_change($this->pdo, $this->service(), $this->monthlyProposal(), false, null, null, '2026-07-01');
        $other = array_replace($this->service(), ['contract_id'=>11]);
        $this->expectException(DomainException::class);
        pa_recurring_service_change_action($this->pdo, $other, $id, 'approve_change', '2026-07-01');
    }

    public function testRollbackRestoresScheduleAndPendingChange(): void
    {
        $old = $this->service();
        $this->pdo->beginTransaction();
        pa_recurring_service_queue_change($this->pdo, $old, $this->monthlyProposal(), true, null, null, '2026-07-01');
        $this->pdo->commit();
        $this->pdo->beginTransaction();
        pa_recurring_service_apply_change($this->pdo, $old);
        $this->pdo->rollBack();
        self::assertSame($old, $this->service());
        self::assertSame('approved', $this->pdo->query('SELECT state FROM contract_recurring_service_changes')->fetchColumn());
    }

    public function testOverdueServiceMustCatchUpBeforeChangingCadence(): void
    {
        $this->expectException(DomainException::class);
        pa_recurring_change_boundary($this->service(), '2027-01-02');
    }
}
