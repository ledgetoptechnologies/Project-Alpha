<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/check_reports.php';

/** Two real connections exercise the snapshot opened before the invoice lock. */
final class MailedCheckPaymentRaceDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $client = 0;
    private int $invoice = 0;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $this->pdo->query('SELECT client_public_id,currency FROM mailed_check_reports LIMIT 0');
        } catch (Throwable) {
            self::markTestSkipped('Disposable MySQL database with mailed-check migration required.');
        }
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->prepare('INSERT INTO clients(name,email) VALUES (?,?)')
            ->execute(['Synthetic payment race', 'race-' . bin2hex(random_bytes(8)) . '@example.invalid']);
        $this->client = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,balance_due,finalized_at,finalization_source) VALUES (?,"unpaid",350,350,NOW(),"manual_finalize")')
            ->execute([$this->client]);
        $this->invoice = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->invoice) {
            $this->pdo->prepare('DELETE FROM mailed_check_reports WHERE document_type="invoice" AND document_id=?')->execute([$this->invoice]);
            $this->pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$this->invoice]);
            $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$this->invoice]);
        }
        if ($this->client) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
    }

    public function testWaitingManualPaymentReadsCommittedPartialPaymentAndRejectsOverpayment(): void
    {
        $result = $this->race('manual');
        self::assertSame('rejected', $result['status']);
        self::assertStringContainsString('outstanding balance', $result['message']);
        self::assertSame('100.00', $this->pdo->query('SELECT SUM(amount) FROM payments WHERE invoice_id=' . $this->invoice)->fetchColumn());
        $invoice = $this->pdo->query('SELECT amount_paid,balance_due,status FROM invoices WHERE id=' . $this->invoice)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['amount_paid'=>'100.00','balance_due'=>'250.00','status'=>'partial'], $invoice);
    }

    public function testWaitingCheckReportSnapshotsBalanceAfterConcurrentPartialPayment(): void
    {
        $result = $this->race('check');
        self::assertSame('pending', $result['status']);
        self::assertSame(25000, $result['amount_cents']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id=' . $this->invoice)->fetchColumn());
        self::assertSame('partial', $this->pdo->query('SELECT status FROM invoices WHERE id=' . $this->invoice)->fetchColumn());
    }

    private function race(string $mode): array
    {
        $root = dirname(__DIR__, 2);
        $script = tempnam(sys_get_temp_dir(), 'pa-check-ledger-race-');
        self::assertNotFalse($script);
        $marker = $script . '.ready';
        $process = null;
        $pipes = [];
        $barrier = <<<'PHP'
class CheckLedgerRaceStatement extends PDOStatement {
    protected function __construct(private string $marker) {}
    public function execute(?array $params = null): bool {
        // The preceding nonlocking project-ID read has opened the RR snapshot.
        // This current read must wait until the parent's payment is committed.
        if (str_contains($this->queryString, 'SELECT project_id FROM invoices WHERE id=? FOR UPDATE')) {
            file_put_contents($this->marker, 'waiting-for-invoice-lock');
        }
        return parent::execute($params);
    }
}
PHP;
        $operation = $mode === 'manual'
            ? 'try { invoice_record_locked_payment($pdo,$invoice,300,"cash","second",null); echo json_encode(["status"=>"recorded"]); } catch (RuntimeException $e) { echo json_encode(["status"=>"rejected","message"=>$e->getMessage()]); }'
            : '$report=pa_check_report_submit($pdo,"invoice",$invoice,$client,"RACE-5321",["payment_methods"=>["check"]]); echo json_encode(["status"=>$report["status"],"amount_cents"=>(int)$report["amount_cents"]]);';
        $code = '<?php require ' . var_export($root . '/vendor/autoload.php', true)
            . ';require ' . var_export($root . '/src/migrations/migration_lib.php', true)
            . ';require ' . var_export($root . '/src/utils/check_reports.php', true) . ';' . $barrier
            . '$pdo=migration_connection();$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");'
            . '$pdo->exec("SET SESSION innodb_lock_wait_timeout=10");'
            . '$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[CheckLedgerRaceStatement::class,[' . var_export($marker, true) . ']]);'
            . '$invoice=' . $this->invoice . ';$client=' . $this->client . ';' . $operation;
        try {
            $this->pdo->beginTransaction();
            $this->pdo->query('SELECT id FROM invoices WHERE id=' . $this->invoice . ' FOR UPDATE')->fetchColumn();
            file_put_contents($script, $code);
            $process = proc_open([PHP_BINARY, $script], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root);
            self::assertIsResource($process);
            $waiting = false;
            for ($i=0; $i<150; $i++) {
                if (is_file($marker)) { $waiting=true; break; }
                usleep(20000);
            }
            self::assertTrue($waiting, 'Second connection reached the invoice lock after opening its snapshot.');
            self::assertTrue(proc_get_status($process)['running'], 'The second operation must wait for the financial row lock.');
            invoice_record_locked_payment($this->pdo, $this->invoice, 100, 'cash', 'first', null);
            $this->pdo->commit();
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) fclose($pipe);
            $pipes=[];
            $exit = proc_close($process);
            $process=null;
            self::assertSame(0, $exit, $error);
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                proc_close($process);
            }
            if (is_file($script)) unlink($script);
            if (is_file($marker)) unlink($marker);
        }
    }
}
