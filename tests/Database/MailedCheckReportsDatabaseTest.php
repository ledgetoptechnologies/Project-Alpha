<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/check_reports.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_status.php';

/** Real accounting/locking tests; run only against the disposable QA database. */
final class MailedCheckReportsDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $client = 0;
    private int $actor = 0;
    private array $invoices = [];
    private array $projects = [];
    private array $statements = [];
    private array $otherClients = [];
    private mixed $oldConfig = null;
    private array $config = ['payment_methods' => ['check']];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $this->pdo->query('SELECT expected_by,document_public_id,client_public_id,currency FROM mailed_check_reports LIMIT 0');
        } catch (Throwable) {
            self::markTestSkipped('Disposable MySQL database with mailed-check migration required.');
        }
        $this->oldConfig = $GLOBALS['appConfig'] ?? null;
        $GLOBALS['appConfig'] = ['notify_invoice_paid' => false, 'payment_receipts_enabled' => false];
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic check reporter','check@example.invalid')");
        $this->client = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users(username,email,password_hash,role,is_disabled) VALUES (?, ?, "synthetic", "member", 1)')
            ->execute(['Synthetic check reviewer', bin2hex(random_bytes(8)) . '@example.invalid']);
        $this->actor = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($this->statements as $id) {
                $this->pdo->prepare('DELETE FROM mailed_check_reports WHERE document_type="project_invoice" AND document_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM project_invoices WHERE id=?')->execute([$id]);
            }
            foreach ($this->invoices as $id) {
                $this->pdo->prepare('DELETE FROM mailed_check_reports WHERE document_type="invoice" AND document_id=?')->execute([$id]);
                if(invoice_table_has_column($this->pdo,'autopay_invoice_reviews','generation'))$this->pdo->prepare('DELETE r FROM autopay_invoice_reviews r JOIN autopay_collections c ON c.id=r.collection_id WHERE c.document_type="invoice" AND c.document_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM autopay_collections WHERE document_type="invoice" AND document_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
            }
            foreach ($this->projects as $id) $this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
            foreach ($this->otherClients as $id) {
                $this->pdo->prepare('DELETE FROM activity_log WHERE client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
            }
            if ($this->client) {
                $this->pdo->prepare('DELETE FROM activity_log WHERE client_id=?')->execute([$this->client]);
                $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
            }
            if ($this->actor) {
                $this->pdo->prepare('DELETE FROM system_audit WHERE user_id=?')->execute([$this->actor]);
                $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->actor]);
            }
        }
        $GLOBALS['appConfig'] = $this->oldConfig;
    }

    private function invoice(): int
    {
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,balance_due,finalized_at,finalization_source) VALUES (?,"unpaid",350,350,NOW(),"manual_finalize")')->execute([$this->client]);
        return $this->invoices[] = (int)$this->pdo->lastInsertId();
    }

    private function report(int $id, string $number = '5866'): array
    {
        return pa_check_report_submit($this->pdo, 'invoice', $id, $this->client, $number, $this->config);
    }

    private function rejects(callable $operation): void
    {
        try { $operation(); self::fail('The unsafe payment operation was accepted.'); }
        catch (DomainException $error) { self::assertNotSame('', $error->getMessage()); }
    }

    private function paymentCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id=?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    public function testReportFreezesCalendarDeadlineWithoutPostingIncomeOrRenewing(): void
    {
        $id = $this->invoice();
        $first = $this->report($id);
        self::assertSame('pending', $first['status']);
        self::assertSame(35000, (int)$first['amount_cents']);
        self::assertNull($first['payment_id']);
        self::assertStringContainsString('mailed check',pa_autopay_pending_notice($this->pdo,$id));
        self::assertSame(0, $this->paymentCount($id));
        self::assertSame(864000, (int)$this->pdo->query('SELECT TIMESTAMPDIFF(SECOND,reported_at,expected_by) FROM mailed_check_reports WHERE id=' . (int)$first['id'])->fetchColumn());
        $this->pdo->prepare('UPDATE mailed_check_reports SET reported_at=DATE_SUB(reported_at,INTERVAL 11 DAY),expected_by=DATE_SUB(expected_by,INTERVAL 11 DAY) WHERE id=?')->execute([$first['id']]);
        $aged = pa_check_report_lookup($this->pdo, 'invoice', $id);
        self::assertTrue($aged['overdue']);
        self::assertStringContainsString('staff review',pa_autopay_pending_notice($this->pdo,$id));
        $again = $this->report($id);
        self::assertSame($aged, $again);
        $this->rejects(fn() => $this->report($id, 'different-check'));
        $this->rejects(fn() => pa_autopay_assert_payment_available($this->pdo, $id));
        $this->rejects(fn() => invoice_record_locked_payment($this->pdo, $id, 350, 'cash', null, null));
        self::assertSame(0, $this->paymentCount($id));
    }

    public function testReceivedUsesCanonicalLedgerExactlyOnceAndSharedCheckNumberIsAllowed(): void
    {
        $one = $this->invoice(); $two = $this->invoice();
        $this->report($one); $this->report($two);
        foreach ([$one, $two] as $id) {
            $received = pa_check_report_resolve($this->pdo, 'invoice', $id, 'received', $this->actor, 'Received physical check');
            self::assertSame('received', $received['status']);
            self::assertGreaterThan(0, (int)$received['payment_id']);
            $again = pa_check_report_resolve($this->pdo, 'invoice', $id, 'received', $this->actor);
            self::assertTrue($again['already_resolved']);
            self::assertSame($received['payment_id'], $again['payment_id']);
            self::assertSame($received['resolved_at'], $again['resolved_at']);
            self::assertSame(1, $this->paymentCount($id));
            $payment = $this->pdo->query('SELECT * FROM payments WHERE id=' . (int)$received['payment_id'])->fetch(PDO::FETCH_ASSOC);
            self::assertSame('5866', $payment['reference_number']);
            self::assertSame('check', $payment['payment_method']);
            self::assertSame('succeeded', $payment['status']);
            self::assertSame('350.00', $payment['amount']);
            self::assertSame('paid', $this->pdo->query('SELECT status FROM invoices WHERE id=' . $id)->fetchColumn());
            self::assertSame('',pa_autopay_pending_notice($this->pdo,$id));
        }
    }

    public function testInvalidRecipientDocumentStateCheckNumberAndDisabledMerchantNeverCreateClaim(): void
    {
        $id = $this->invoice();
        $this->rejects(fn() => pa_check_report_submit($this->pdo, 'invoice', $id, $this->client + 100000, '5866', $this->config));
        $this->rejects(fn() => pa_check_report_submit($this->pdo, 'invoice', $id, $this->client, '5866', ['payment_methods' => ['cash']]));
        foreach (['', "5866\nInjected", str_repeat('x', 101)] as $number) $this->rejects(fn() => $this->report($id, $number));
        foreach (['draft','paid','void','cancelled'] as $status) {
            $this->pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$status, $id]);
            $this->rejects(fn() => $this->report($id));
        }
        $this->pdo->prepare('UPDATE invoices SET status="unpaid",finalized_at=NULL WHERE id=?')->execute([$id]);
        $this->rejects(fn() => $this->report($id));
        $this->pdo->prepare('UPDATE invoices SET finalized_at=NOW(),recipient_presentation_mode="general" WHERE id=?')->execute([$id]);
        $this->rejects(fn() => $this->report($id));
        self::assertNull(pa_check_report_lookup($this->pdo, 'invoice', $id));
        self::assertSame(0, $this->paymentCount($id));
    }

    public function testExistingCheckoutAndChangedBalanceRequireStaffReview(): void
    {
        $id = $this->invoice();
        foreach (['cs_SYNTHETIC', 'pa_checkout_pending_SYNTHETIC'] as $session) {
            $this->pdo->prepare('UPDATE invoices SET stripe_session_id=? WHERE id=?')->execute([$session, $id]);
            $this->rejects(fn() => $this->report($id));
        }
        $this->pdo->prepare('UPDATE invoices SET stripe_session_id=NULL WHERE id=?')->execute([$id]);
        $this->pdo->prepare('INSERT INTO payments(client_id,invoice_id,amount,payment_method,status,payment_date) VALUES (?,?,350,"stripe","pending",CURDATE())')->execute([$this->client,$id]);
        $this->rejects(fn() => $this->report($id));
        $this->pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$id]);
        $this->report($id);
        $this->pdo->prepare('UPDATE invoices SET total=351 WHERE id=?')->execute([$id]);
        $this->rejects(fn() => pa_check_report_resolve($this->pdo, 'invoice', $id, 'received', $this->actor));
        self::assertSame('pending', pa_check_report_lookup($this->pdo, 'invoice', $id)['status']);
        self::assertSame(0, $this->paymentCount($id));
        $this->pdo->prepare('UPDATE invoices SET total=350,currency="CAD" WHERE id=?')->execute([$id]);
        self::assertSame('usd',pa_check_report_lookup($this->pdo,'invoice',$id)['currency']);
        $this->rejects(fn()=>pa_check_report_resolve($this->pdo,'invoice',$id,'received',$this->actor,null,['workforce_currency'=>'cad']));
        self::assertSame('pending',pa_check_report_lookup($this->pdo,'invoice',$id)['status']);
        self::assertSame(0,$this->paymentCount($id));
    }

    public function testRejectionDoesNotReopenClientClaimAndAllowsReviewedManualPayment(): void
    {
        $id = $this->invoice(); $this->report($id);
        $rejected = pa_check_report_resolve($this->pdo, 'invoice', $id, 'rejected', $this->actor, 'Check never arrived');
        self::assertSame('rejected', $rejected['status']);
        self::assertSame('',pa_autopay_pending_notice($this->pdo,$id));
        self::assertSame(0, $this->paymentCount($id));
        $this->rejects(fn() => $this->report($id));
        $this->rejects(fn() => pa_check_report_resolve($this->pdo, 'invoice', $id, 'received', $this->actor));
        $payment = invoice_record_locked_payment($this->pdo, $id, 350, 'cash', null, 'Reviewed replacement payment');
        self::assertGreaterThan(0, $payment['payment_id']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE document_type="invoice" AND document_id=' . $id)->fetchColumn());
    }

    public function testAccountingFailureRollsBackReleasedHoldPaymentAndDocumentTogether(): void
    {
        $id = $this->invoice(); $this->report($id);
        $trigger = 'qa_check_rollback_' . bin2hex(random_bytes(6));
        $this->pdo->exec("CREATE TRIGGER {$trigger} BEFORE UPDATE ON invoices FOR EACH ROW BEGIN IF NEW.id={$id} AND NEW.status='paid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic accounting failure'; END IF; END");
        try {
            try { pa_check_report_resolve($this->pdo, 'invoice', $id, 'received', $this->actor); self::fail('Synthetic accounting failure was ignored.'); }
            catch (PDOException $error) { self::assertStringContainsString('Synthetic accounting failure', $error->getMessage()); }
            $report = pa_check_report_lookup($this->pdo, 'invoice', $id);
            self::assertSame('pending', $report['status']);
            self::assertNull($report['payment_id']);
            self::assertNull($report['resolved_at']);
            self::assertSame(0, $this->paymentCount($id));
            self::assertSame('unpaid', $this->pdo->query('SELECT status FROM invoices WHERE id=' . $id)->fetchColumn());
            // A caller that catches the error and commits its outer transaction
            // must not commit the temporary received status or partial ledger.
            $this->pdo->beginTransaction();
            try { pa_check_report_resolve($this->pdo, 'invoice', $id, 'received', $this->actor); self::fail('Nested accounting failure was ignored.'); }
            catch (PDOException $error) { self::assertStringContainsString('Synthetic accounting failure', $error->getMessage()); }
            self::assertTrue($this->pdo->inTransaction());
            $this->pdo->commit();
            self::assertSame('pending', pa_check_report_lookup($this->pdo, 'invoice', $id)['status']);
            self::assertSame(0, $this->paymentCount($id));
        } finally { $this->pdo->exec('DROP TRIGGER IF EXISTS ' . $trigger); }
    }

    public function testPendingReportBlocksCheckoutReservationEvenWithAutoPayDisabled(): void
    {
        $id = $this->invoice(); $this->report($id);
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->query('SELECT * FROM invoices WHERE id=' . $id . ' FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            $this->rejects(fn() => pa_autopay_reserve_checkout($this->pdo, $row, []));
            self::assertEmpty($this->pdo->query('SELECT stripe_session_id FROM invoices WHERE id=' . $id)->fetchColumn());
        } finally { $this->pdo->rollBack(); }
    }

    public function testActiveProcessorReservationsRejectClaimsAndRejectedClaimNeverEnqueuesAutoPay(): void
    {
        $environment = [];
        foreach (['APP_ENV'=>'test','AUTOPAY_BETA_ENABLED'=>'true','AUTOPAY_INSTALLATION_ID'=>'synthetic-check-tests','AUTOPAY_STRIPE_ACCOUNT_ID'=>'acct_SYNTHETIC'] as $key=>$value) {
            $environment[$key] = getenv($key); putenv($key . '=' . $value);
        }
        $authorization = 0;
        $config = ['_stripe_secret_key'=>'sk_test_SYNTHETIC','workforce_currency'=>'USD'];
        try {
            $this->pdo->prepare('INSERT INTO autopay_enrollments(client_id,scope,status,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,currency,consent_version,consent_text,verified_email,consent_at,consent_proof,verified_at,effective_from) VALUES (?,"business","active","acct_SYNTHETIC","synthetic-check-tests","cus_SYNTHETIC","pm_SYNTHETIC","us_bank_account","usd","test","Synthetic test consent","check@example.invalid",DATE_SUB(NOW(),INTERVAL 1 DAY),REPEAT("a",64),NOW(),DATE_SUB(NOW(),INTERVAL 1 DAY))')->execute([$this->client]);
            $authorization = (int)$this->pdo->lastInsertId();
            $id = $this->invoice();
            $this->pdo->beginTransaction();
            $collection = pa_autopay_enqueue_invoice($this->pdo, $id, $config, null, 'manual_finalize');
            $this->pdo->commit();
            self::assertNotNull($collection);
            foreach (['pending','processing','requires_action','held'] as $state) {
                $this->pdo->prepare('UPDATE autopay_collections SET state=? WHERE id=?')->execute([$state, $collection]);
                $this->rejects(fn() => $this->report($id));
                self::assertNull(pa_check_report_lookup($this->pdo, 'invoice', $id));
            }
            $other = $this->invoice(); $this->report($other);
            pa_check_report_resolve($this->pdo, 'invoice', $other, 'rejected', $this->actor);
            $this->pdo->beginTransaction();
            self::assertNull(pa_autopay_enqueue_invoice($this->pdo, $other, $config, null, 'manual_finalize'));
            $this->pdo->commit();
            self::assertSame(0, $this->paymentCount($other));
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($authorization) {
                if(invoice_table_has_column($this->pdo,'autopay_invoice_reviews','generation'))$this->pdo->prepare('DELETE r FROM autopay_invoice_reviews r JOIN autopay_collections c ON c.id=r.collection_id WHERE c.authorization_id=?')->execute([$authorization]);
                $this->pdo->prepare('DELETE FROM autopay_collections WHERE authorization_id=?')->execute([$authorization]);
                $this->pdo->prepare('DELETE FROM autopay_enrollments WHERE id=?')->execute([$authorization]);
            }
            foreach ($environment as $key=>$value) putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    private function statement(int $child): int
    {
        require_once dirname(__DIR__, 2) . '/src/utils/project_invoice_billing.php';
        $this->pdo->prepare('INSERT INTO projects(name,client_id) VALUES ("Synthetic check statement",?)')->execute([$this->client]);
        $project = $this->projects[] = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE invoices SET project_id=? WHERE id=?')->execute([$project, $child]);
        $this->pdo->prepare('INSERT INTO project_invoices(project_id,primary_client_id,status,billing_period_start,billing_period_end,total,balance_due,finalized_at,finalization_source) VALUES (?,?,"unpaid",CURDATE(),CURDATE(),350,350,NOW(),"project_finalize")')->execute([$project,$this->client]);
        $id = $this->statements[] = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO project_invoice_items(project_invoice_id,invoice_id,invoice_total,amount_due_at_generation) VALUES (?,?,350,350)')->execute([$id,$child]);
        return $id;
    }

    public function testChildPendingReportBlocksParentAndParentReportBlocksDirectChildPayment(): void
    {
        $child = $this->invoice(); $parent = $this->statement($child);
        $this->report($child);
        $this->rejects(fn() => pa_check_report_submit($this->pdo, 'project_invoice', $parent, $this->client, '5866', $this->config));
        self::assertNull(project_invoice_record_manual_payment($this->pdo, $parent, 350, 'cash'));
        pa_check_report_resolve($this->pdo, 'invoice', $child, 'rejected', $this->actor);
        $report = pa_check_report_submit($this->pdo, 'project_invoice', $parent, $this->client, '5866', $this->config);
        self::assertSame(35000, (int)$report['amount_cents']);
        $this->rejects(fn() => invoice_record_locked_payment($this->pdo, $child, 350, 'cash', null, null));
        self::assertSame(0, $this->paymentCount($child));
        $received = pa_check_report_resolve($this->pdo, 'project_invoice', $parent, 'received', $this->actor);
        self::assertGreaterThan(0, (int)$received['payment_id']);
        $again = pa_check_report_resolve($this->pdo, 'project_invoice', $parent, 'received', $this->actor);
        self::assertTrue($again['already_resolved']);
        self::assertSame($received['payment_id'], $again['payment_id']);
        self::assertSame(1, $this->paymentCount($child));
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM project_invoice_payments WHERE project_invoice_id=' . $parent)->fetchColumn());
    }

    public function testConcurrentStaffConfirmationWaitsForDocumentLockAndReturnsOriginalPayment(): void
    {
        $id = $this->invoice(); $this->report($id);
        $root = dirname(__DIR__, 2);
        $script = tempnam(sys_get_temp_dir(), 'pa-check-race-');
        $marker = $script . '.ready'; $process = null; $pipes = [];
        $barrier = <<<'PHP'
class CheckRaceStatement extends PDOStatement {
 protected function __construct(private string $marker) {}
 public function execute(?array $params = null): bool {
  if (str_contains($this->queryString, 'FROM invoices WHERE id=? FOR UPDATE')) file_put_contents($this->marker, 'ready');
  return parent::execute($params);
 }
}
PHP;
        $code = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($root . '/src/migrations/migration_lib.php', true) . ';require ' . var_export($root . '/src/utils/check_reports.php', true) . ';' . $barrier
            . '$GLOBALS["appConfig"]=["notify_invoice_paid"=>false,"payment_receipts_enabled"=>false];$pdo=migration_connection();$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[CheckRaceStatement::class,[' . var_export($marker, true) . ']]);$pdo->exec("SET SESSION innodb_lock_wait_timeout=10");$r=pa_check_report_resolve($pdo,"invoice",' . $id . ',"received",' . $this->actor . ');echo $r["payment_id"];';
        try {
            $this->pdo->beginTransaction();
            $this->pdo->query('SELECT id FROM invoices WHERE id=' . $id . ' FOR UPDATE')->fetchColumn();
            file_put_contents($script, $code);
            $process = proc_open([PHP_BINARY, $script], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root);
            self::assertIsResource($process);
            $waiting = false;
            for ($i=0; $i<150; $i++) { if (is_file($marker)) { $waiting=true; break; } usleep(20000); }
            self::assertTrue($waiting, 'Second connection reached the actual document locking statement.');
            self::assertTrue(proc_get_status($process)['running'], 'Second confirmer must wait for the document lock.');
            $first = pa_check_report_resolve($this->pdo, 'invoice', $id, 'received', $this->actor);
            $this->pdo->commit();
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) fclose($pipe); $pipes=[];
            $exit = proc_close($process); $process=null;
            self::assertSame(0, $exit, $err);
            self::assertSame((string)$first['payment_id'], $out, $err);
            self::assertSame(1, $this->paymentCount($id));
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
            if (is_file($script)) unlink($script); if (is_file($marker)) unlink($marker);
        }
    }

    public function testReusedInvoiceNumberCannotInheritHistoricalReportButRestoredPublicIdentityRetainsHold(): void
    {
        $old = $this->invoice();
        $original = $this->pdo->query('SELECT public_id FROM invoices WHERE id='.$old)->fetchColumn();
        $report = $this->report($old);
        // Simulate the physical archive boundary, then another invoice occupying
        // the old numeric ID. The report is historical and intentionally remains.
        $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$old]);
        $this->pdo->prepare('INSERT INTO invoices(id,public_id,client_id,status,total,balance_due,finalized_at,finalization_source) VALUES (?,?,?,"unpaid",350,350,NOW(),"manual_finalize")')->execute([$old,bin2hex(random_bytes(16)),$this->client]);
        self::assertNull(pa_check_report_lookup($this->pdo,'invoice',$old));
        pa_autopay_assert_payment_available($this->pdo,$old);
        $unrelated = $this->report($old,'new-check');
        self::assertNotSame((int)$report['id'],(int)$unrelated['id']);
        $this->pdo->prepare('INSERT INTO invoices(public_id,client_id,status,total,balance_due,finalized_at,finalization_source) VALUES (?, ?,"unpaid",350,350,NOW(),"manual_finalize")')->execute([$original,$this->client]);
        $restored = $this->invoices[] = (int)$this->pdo->lastInsertId();
        $retained = pa_check_report_lookup($this->pdo,'invoice',$restored);
        self::assertSame((int)$report['id'],(int)$retained['id']);
        self::assertSame($restored,(int)$retained['document_id']);
        $this->rejects(fn()=>pa_autopay_assert_payment_available($this->pdo,$restored));
        $received = pa_check_report_resolve($this->pdo,'invoice',$restored,'received',$this->actor);
        self::assertSame(1,$this->paymentCount($restored));
        self::assertSame(0,$this->paymentCount($old));
        self::assertGreaterThan(0,(int)$received['payment_id']);
        self::assertSame('pending',pa_check_report_lookup($this->pdo,'invoice',$old)['status']);
    }

    public function testRestoredClientIdentityRemapsPaymentWithoutTrustingReusedNumericClientId(): void
    {
        $id = $this->invoice();
        $identity = bin2hex(random_bytes(16));
        $this->pdo->prepare('UPDATE clients SET public_id=? WHERE id=?')->execute([$identity,$this->client]);
        $report = $this->report($id);
        // The original numeric client slot now belongs to a distinct person.
        $this->pdo->prepare('UPDATE clients SET public_id=? WHERE id=?')->execute([bin2hex(random_bytes(16)),$this->client]);
        $this->rejects(fn()=>pa_check_report_resolve($this->pdo,'invoice',$id,'received',$this->actor));
        self::assertSame(0,$this->paymentCount($id));
        $this->pdo->prepare('INSERT INTO clients(public_id,name,email) VALUES (?,"Restored synthetic client","restored@example.invalid")')->execute([$identity]);
        $restoredClient = $this->otherClients[] = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE invoices SET client_id=? WHERE id=?')->execute([$restoredClient,$id]);
        $received = pa_check_report_resolve($this->pdo,'invoice',$id,'received',$this->actor);
        self::assertSame((int)$report['id'],(int)$received['id']);
        self::assertSame($restoredClient,(int)$this->pdo->query('SELECT client_id FROM payments WHERE id='.(int)$received['payment_id'])->fetchColumn());
        self::assertSame(1,$this->paymentCount($id));
    }
}
