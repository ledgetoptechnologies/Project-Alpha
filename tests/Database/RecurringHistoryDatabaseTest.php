<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/recurring_history.php';

final class RecurringHistoryFailingStatement extends PDOStatement
{
    protected function __construct(private object $state) {}
    public function execute(?array $params = null): bool
    {
        if (str_contains($this->queryString,'INSERT INTO payments') && ++$this->state->payments === 2) throw new RuntimeException('Synthetic second payment failure');
        return parent::execute($params);
    }
}

final class RecurringHistoryDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $client=0;
    private int $contract=0;
    private int $service=0;
    private array $config=['from_company'=>'Synthetic history','brand_name'=>'Synthetic history','_history_preview_secret'=>'synthetic-history-preview-secret-for-tests'];

    protected function setUp(): void
    {
        try { $this->pdo=migration_connection();$this->pdo->query('SELECT billing_anchor_date FROM contract_recurring_services LIMIT 0'); }
        catch (Throwable) { self::markTestSkipped('Migrated MySQL test database required.'); }
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic history test','never-send@example.invalid')");$this->client=(int)$this->pdo->lastInsertId();
        $future=(new DateTimeImmutable('first day of next month'))->format('Y-m-d');
        $this->pdo->prepare('INSERT INTO contracts(client_id,status,contract_type,pricing_type,price_per_invoice,billing_interval_count,billing_interval_unit,start_date,next_invoice_date,scope,subtotal,total,rate_basis,monthly_rate) VALUES (?,"draft","long_term","per_invoice",350,1,"month",?,?,"Synthetic unchanged agreement",350,350,"monthly",350)')->execute([$this->client,$future,$future]);
        $this->contract=(int)$this->pdo->lastInsertId();$this->service=(int)pa_recurring_service_ensure_base($this->pdo,$this->contract);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);
        if ($this->contract) {
            $this->pdo->prepare("DELETE FROM document_revisions WHERE document_type='invoice' AND document_id IN (SELECT id FROM invoices WHERE contract_id=?)")->execute([$this->contract]);
            $this->pdo->prepare('DELETE FROM payments WHERE contract_id=?')->execute([$this->contract]);
            $this->pdo->prepare('DELETE FROM invoices WHERE contract_id=?')->execute([$this->contract]);
            $this->pdo->prepare('DELETE FROM contracts WHERE id=?')->execute([$this->contract]);
            $this->pdo->prepare("DELETE FROM system_audit WHERE action='contract.historical_payment' AND entity_id=?")->execute([$this->contract]);
        }
        if ($this->client) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
    }

    private function input(): array
    {
        return ['first_period_start'=>(new DateTimeImmutable('first day of last month'))->format('Y-m-d'),'months'=>2,'payment_date'=>date('Y-m-d'),'reference_number'=>'SYNTHETIC-CHECK','paid_amount'=>'700.00','generation_key'=>bin2hex(random_bytes(16))];
    }
    private function row(string $table,int $id): array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM $table WHERE id=?");$stmt->execute([$id]);return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    private function preview(array $input): array { return pa_recurring_history_preview($this->pdo,$this->contract,$this->service,$input,$this->config); }
    private function record(array $input): array
    {
        return pa_recurring_history_record($this->pdo,$this->contract,$this->service,$input+['preview_hash'=>$this->preview($input)['preview_hash'],'history_confirmed'=>1],$this->config,null);
    }
    private function invoices(): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM invoices WHERE contract_id=? ORDER BY id');$stmt->execute([$this->contract]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testOneCheckCreatesTwoPaidInvoicesAndPreservesFutureDraftTerms(): void
    {
        $input=$this->input();$before=$this->row('contracts',$this->contract);$serviceBefore=$this->row('contract_recurring_services',$this->service);
        $preview=$this->preview($input);
        self::assertSame('700.00',$preview['total']);self::assertCount(2,$preview['periods']);self::assertGreaterThan(date('Y-m-d'),$preview['next_invoice_date']);
        self::assertSame($before,$this->row('contracts',$this->contract));self::assertSame($serviceBefore,$this->row('contract_recurring_services',$this->service));self::assertSame([],$this->invoices());
        $result=$this->record($input);self::assertCount(2,$result['invoice_ids']);self::assertCount(2,$result['payment_ids']);
        foreach($this->invoices() as $invoice){self::assertSame('paid',$invoice['status']);self::assertSame('350.00',$invoice['total']);self::assertSame('350.00',$invoice['amount_paid']);self::assertSame('0.00',$invoice['balance_due']);self::assertSame('historical_recurring_payment',$invoice['finalization_source']);}
        $after=$this->row('contracts',$this->contract);foreach(['status','start_date','scope','subtotal','total','price_per_invoice'] as $key)self::assertSame($before[$key],$after[$key],$key);
        self::assertSame('700.00',$after['total_invoiced']);self::assertSame(2,(int)$after['invoices_generated']);
        self::assertSame($preview['periods'][1]['start'],$after['last_invoice_date']);
        self::assertSame($serviceBefore['effective_from'],$this->row('contract_recurring_services',$this->service)['effective_from']);
        $payments=$this->pdo->prepare('SELECT SUM(amount),COUNT(DISTINCT reference_number) FROM payments WHERE contract_id=?');$payments->execute([$this->contract]);$totals=$payments->fetch(PDO::FETCH_NUM);self::assertSame('700.00',$totals[0]);self::assertSame(1,(int)$totals[1]);
        $outbox=$this->pdo->prepare('SELECT COUNT(*) FROM invoice_notifications WHERE invoice_id IN (SELECT id FROM invoices WHERE contract_id=?)');$outbox->execute([$this->contract]);self::assertSame(0,(int)$outbox->fetchColumn());
    }

    public function testIdenticalRetryReturnsOriginalIdsAndNewRequestCannotOverlap(): void
    {
        $input=$this->input();$input+=['history_confirmed'=>1,'preview_hash'=>$this->preview($input)['preview_hash']];
        $first=pa_recurring_history_record($this->pdo,$this->contract,$this->service,$input,$this->config,null);
        $retry=pa_recurring_history_record($this->pdo,$this->contract,$this->service,$input,$this->config,null);
        self::assertSame($first['invoice_ids'],$retry['invoice_ids']);self::assertTrue($retry['idempotent']);self::assertCount(2,$this->invoices());
        $input['generation_key']=bin2hex(random_bytes(16));$this->expectException(DomainException::class);$this->preview($input);
    }

    public function testStalePreviewAndCheckMismatchDoNotWrite(): void
    {
        $input=$this->input();$hash=$this->preview($input)['preview_hash'];
        $this->pdo->prepare('UPDATE contracts SET scope=? WHERE id=?')->execute(['Changed after preview',$this->contract]);
        try {pa_recurring_history_record($this->pdo,$this->contract,$this->service,$input+['preview_hash'=>$hash,'history_confirmed'=>1],$this->config,null);self::fail('Expected stale preview rejection');}catch(DomainException $e){self::assertStringContainsString('changed',$e->getMessage());}
        self::assertSame([],$this->invoices());$input['paid_amount']='699.99';
        try{$this->preview($input);self::fail('Expected mismatch rejection');}catch(DomainException $e){self::assertStringContainsString('700.00',$e->getMessage());}
        self::assertSame([],$this->invoices());
    }

    public function testSecondPaymentFailureRollsBackEntireBatch(): void
    {
        $input=$this->input();$input+=['history_confirmed'=>1,'preview_hash'=>$this->preview($input)['preview_hash']];$before=$this->row('contracts',$this->contract);
        $state=(object)['payments'=>0];$this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[RecurringHistoryFailingStatement::class,[$state]]);
        try{pa_recurring_history_record($this->pdo,$this->contract,$this->service,$input,$this->config,null);self::fail('Expected injected failure');}catch(RuntimeException $e){self::assertSame('Synthetic second payment failure',$e->getMessage());}
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);
        self::assertSame([],$this->invoices());self::assertSame($before,$this->row('contracts',$this->contract));
        $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM recurring_invoice_coverage WHERE recurring_service_id=?');$stmt->execute([$this->service]);self::assertSame(0,(int)$stmt->fetchColumn());
    }

    public function testTaxAndDiscountAreRoundedPerInvoiceThenSummed(): void
    {
        $this->pdo->prepare('UPDATE contracts SET discount_type="percent",discount_value=10,tax_percent=5 WHERE id=?')->execute([$this->contract]);
        $input=$this->input();$input['paid_amount']='661.50';$preview=$this->preview($input);
        self::assertSame('35.00',$preview['periods'][0]['discount']);self::assertSame('15.75',$preview['periods'][0]['tax']);
        $this->record($input);foreach($this->invoices() as $row)self::assertSame('330.75',$row['total']);
    }

    public function testAmbiguousLegacyInvoiceBlocksImport(): void
    {
        $this->pdo->prepare('INSERT INTO invoices(client_id,contract_id,status,total) VALUES (?,?,"paid",350)')->execute([$this->client,$this->contract]);
        $this->expectException(DomainException::class);$this->expectExceptionMessage('untracked coverage');$this->preview($this->input());
    }

    public function testNegativeFixedDiscountIsRejected(): void
    {
        $this->pdo->prepare('UPDATE contracts SET discount_type="fixed",discount_value=-5 WHERE id=?')->execute([$this->contract]);
        $this->expectException(DomainException::class);$this->preview($this->input());
    }

    public function testNewerContractLastInvoiceDateIsPreserved(): void
    {
        $newer=date('Y-m-d');
        $this->pdo->prepare('UPDATE contracts SET last_invoice_date=? WHERE id=?')->execute([$newer,$this->contract]);
        $this->record($this->input());
        self::assertSame($newer,$this->row('contracts',$this->contract)['last_invoice_date']);
        self::assertSame('draft',$this->row('contracts',$this->contract)['status']);
    }

    public function testMonthEndPeriodsRecoverOriginalAnniversary(): void
    {
        $this->pdo->prepare("UPDATE contract_recurring_services SET next_invoice_date='2026-03-31',billing_anchor_date='2026-01-31' WHERE id=?")->execute([$this->service]);
        $input=$this->input();$input['first_period_start']='2026-01-31';$preview=$this->preview($input);
        self::assertSame('2026-02-28',$preview['periods'][0]['end']);self::assertSame('2026-03-31',$preview['periods'][1]['end']);
        $this->record($input);
        $service=$this->row('contract_recurring_services',$this->service);
        self::assertSame('2026-04-30',pa_recurring_service_period_end($service,$service['next_invoice_date']));
    }

    public function testRenewalInsidePeriodIsRejectedWithoutChangingCursor(): void
    {
        $input=$this->input();$inside=(new DateTimeImmutable('first day of this month'))->modify('+14 days')->format('Y-m-d');
        $this->pdo->prepare('UPDATE contract_recurring_services SET next_invoice_date=? WHERE id=?')->execute([$inside,$this->service]);
        $this->expectException(DomainException::class);$this->expectExceptionMessage('period boundary');$this->preview($input);
    }

    public function testClippedBoundaryWithDifferentFutureAnniversaryIsRejected(): void
    {
        $this->pdo->prepare("UPDATE contract_recurring_services SET next_invoice_date='2026-02-28',billing_anchor_date='2026-01-28' WHERE id=?")->execute([$this->service]);
        $input=$this->input();$input['first_period_start']='2026-01-31';$input['months']=1;$input['paid_amount']='350.00';
        $this->expectException(DomainException::class);$this->expectExceptionMessage('anniversary disagree');$this->preview($input);
    }

    public function testConcurrentIdenticalRequestsRecordOneBatch(): void
    {
        if (!function_exists('proc_open')) self::markTestSkipped('Subprocess execution required.');
        $input=$this->input();$input+=['history_confirmed'=>1,'preview_hash'=>$this->preview($input)['preview_hash']];
        $data=base64_encode(json_encode([$this->contract,$this->service,$input,$this->config],JSON_THROW_ON_ERROR));
        $root=dirname(__DIR__,2);
        $code='require '.var_export($root.'/vendor/autoload.php',true).'; require '.var_export($root.'/src/migrations/migration_lib.php',true).'; require '.var_export($root.'/src/utils/recurring_history.php',true).'; [$c,$s,$i,$config]=json_decode(base64_decode('.var_export($data,true).'),true); echo json_encode(pa_recurring_history_record(migration_connection(),$c,$s,$i,$config,null),JSON_THROW_ON_ERROR);';
        $children=[];
        $this->pdo->beginTransaction();
        pa_recurring_history_lock($this->pdo,$this->contract,$this->service);
        try {
            for($n=0;$n<2;$n++) {
                $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
                self::assertIsResource($process);fclose($pipes[0]);$children[]=[$process,$pipes];
            }
            $this->pdo->commit();
            $results=[];
            foreach($children as [$process,$pipes]) {
                $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                self::assertSame(0,proc_close($process),$stderr);$results[]=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
            }
            self::assertSame($results[0]['invoice_ids'],$results[1]['invoice_ids']);
            self::assertNotSame($results[0]['idempotent'],$results[1]['idempotent']);
            self::assertCount(2,$this->invoices());
        } finally {if($this->pdo->inTransaction())$this->pdo->rollBack();}
    }

    public function testNextLiveInvoiceStartsAfterPaidCoverageAndContainsFullMonth(): void
    {
        $result=$this->record($this->input());$next=$result['next_invoice_date'];
        $this->pdo->prepare('UPDATE contracts SET status="active" WHERE id=?')->execute([$this->contract]);
        $this->pdo->prepare('UPDATE contract_recurring_services SET status="active" WHERE id=?')->execute([$this->service]);
        $contract=$this->row('contracts',$this->contract);
        self::assertNull(generate_recurring_invoice($this->pdo,$contract,$this->config,(new DateTimeImmutable($next))->modify('-1 day')));
        $invoiceId=generate_recurring_invoice($this->pdo,$contract,$this->config,new DateTimeImmutable($next));
        self::assertNotNull($invoiceId);
        $stmt=$this->pdo->prepare('SELECT coverage_start,coverage_end FROM recurring_invoice_coverage WHERE invoice_id=?');$stmt->execute([$invoiceId]);$coverage=$stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame($next,$coverage['coverage_start']);
        self::assertSame((new DateTimeImmutable($next))->modify('+1 month')->format('Y-m-d'),$coverage['coverage_end']);
        self::assertSame('350.00',$this->row('invoices',(int)$invoiceId)['total']);
        self::assertCount(3,$this->invoices());
    }
}
