<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_review.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_collection.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_enrollment.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_invoice_email.php';

/** Monetary and delivery evidence tests; synthetic Stripe and loopback SMTP only. */
final class AutoPayInvoiceReviewDatabaseTest extends TestCase
{
    private PDO $pdo;
    private array $client;
    private array $clients = [];
    private array $documents = [];
    private array $projects = [];
    private array $contracts = [];
    private array $deliveryLogs = [];
    private array $users = [];
    private array $env = [];
    private int $authorization;
    private array $config = ['_stripe_secret_key' => 'sk_test_synthetic', 'app_host' => 'https://example.invalid', 'timezone' => 'America/Chicago', 'workforce_currency' => 'usd', '_autopay_period_start' => '2026-01-01'];

    protected function setUp(): void
    {
        if (getenv('AUTOPAY_REVIEW_ALLOW') !== 'isolated-disposable-only') self::markTestSkipped('Explicit disposable AutoPay invoice review database required.');
        if (!preg_match('/^pa_autopay_review_[a-f0-9]{16}$/D', (string)getenv('MYSQL_DATABASE')) || getenv('DB_HOST') !== '127.0.0.1') self::fail('Isolated loopback AutoPay review database required.');
        $this->pdo = migration_connection();
        $this->pdo->query('SELECT generation FROM autopay_invoice_reviews LIMIT 0');
        foreach (['APP_ENV' => 'test', 'AUTOPAY_BETA_ENABLED' => 'true', 'AUTOPAY_INSTALLATION_ID' => 'synthetic-review-tests', 'AUTOPAY_STRIPE_ACCOUNT_ID' => 'acct_reviewTEST'] as $key => $value) {
            $this->env[$key] = getenv($key); putenv($key . '=' . $value);
        }
        $this->client = $this->client();
        $this->pdo->prepare('INSERT INTO autopay_enrollments(client_id,scope,status,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,currency,consent_version,consent_text,verified_email,consent_at,consent_proof,verified_at,effective_from) VALUES (?,"business","active","acct_reviewTEST","synthetic-review-tests","cus_review","pm_review","card","usd","synthetic","Synthetic account-wide consent",?,"2025-01-01",REPEAT("a",64),"2025-01-01","2025-01-01")')->execute([$this->client['id'], $this->client['email']]);
        $this->authorization = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($this->clients as $id) {
                $this->pdo->prepare('DELETE r FROM autopay_invoice_reviews r JOIN autopay_collections c ON c.id=r.collection_id WHERE c.client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE n FROM autopay_notifications n JOIN autopay_collections c ON c.id=n.collection_id WHERE c.client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM autopay_collections WHERE client_id=?')->execute([$id]);
            }
            foreach (array_reverse($this->documents) as $document) {
                $type = $document['type']; $id = $document['id'];
                $this->pdo->prepare('DELETE FROM public_links WHERE document_type=? AND document_id=?')->execute([$type, $id]);
                $this->pdo->prepare('DELETE FROM mailed_check_reports WHERE document_type=? AND document_id=?')->execute([$type, $id]);
                if ($type === 'project_invoice') {
                    $this->pdo->prepare('DELETE FROM project_invoice_payments WHERE project_invoice_id=?')->execute([$id]);
                    $this->pdo->prepare('DELETE FROM project_invoices WHERE id=?')->execute([$id]);
                } else {
                    $this->pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$id]);
                    $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
                }
            }
            foreach ($this->projects as $id) $this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
            foreach ($this->contracts as $id) $this->pdo->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);
            foreach ($this->clients as $id) {
                $this->pdo->prepare('DELETE FROM autopay_enrollments WHERE client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM autopay_email_challenges WHERE client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
            }
            foreach ($this->deliveryLogs as $id) $this->pdo->prepare('DELETE FROM email_delivery_log WHERE id=?')->execute([$id]);
            foreach ($this->users as $id) {
                $this->pdo->prepare('DELETE FROM system_audit WHERE user_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            }
            $this->pdo->exec('DELETE FROM rate_limits WHERE identifier="127.0.0.37:autopay_manage"');
        }
        foreach ($this->env as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    private function client(): array
    {
        $email = 'invoice-review-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $this->pdo->prepare('INSERT INTO clients(name,email) VALUES ("Synthetic review client",?)')->execute([$email]);
        $id = (int)$this->pdo->lastInsertId(); $this->clients[] = $id;
        return ['id' => $id, 'name' => 'Synthetic review client', 'email' => $email];
    }

    private function clock(string $when): array
    {
        return $this->config + ['_autopay_review_now' => new DateTimeImmutable($when, new DateTimeZone('UTC'))];
    }

    private function invoice(string $kind = 'regular', array $override = [], bool $reserve = true): array
    {
        $row = array_replace(['client_id' => $this->client['id'], 'invoice_type' => $kind, 'status' => 'unpaid', 'total' => '100.00', 'balance_due' => '100.00', 'finalized_at' => '2026-01-01 12:00:00', 'finalization_source' => $kind === 'long_term' ? 'recurring_schedule' : 'manual_finalize', 'due_date' => null], $override);
        $this->insert('invoices', $row);
        $document = ['type' => 'invoice', 'id' => (int)$this->pdo->lastInsertId()]; $this->documents[] = $document;
        $document['collection'] = $reserve ? $this->reserve($document, $kind === 'long_term' ? 'recurring_auto' : ($kind === 'on_demand' ? 'on_demand_send' : 'manual_finalize')) : null;
        return $document;
    }

    private function projectInvoice(): array
    {
        $child = $this->invoice('regular', ['collection_mode' => 'project_aggregate'], false);
        $this->insert('projects', ['name' => 'Synthetic review project', 'client_id' => $this->client['id']]);
        $project = (int)$this->pdo->lastInsertId(); $this->projects[] = $project;
        $this->insert('project_invoices', ['project_id' => $project, 'primary_client_id' => $this->client['id'], 'status' => 'unpaid', 'total' => '100.00', 'balance_due' => '100.00', 'finalized_at' => '2026-01-01 12:00:00', 'billing_period_start' => '2026-01-01', 'billing_period_end' => '2026-12-31']);
        $document = ['type' => 'project_invoice', 'id' => (int)$this->pdo->lastInsertId()]; $this->documents[] = $document;
        $this->insert('project_invoice_items', ['project_invoice_id' => $document['id'], 'invoice_id' => $child['id'], 'invoice_total' => '100.00', 'amount_due_at_generation' => '100.00']);
        $document['collection'] = $this->reserve($document, 'project_finalize');
        return $document;
    }

    private function insert(string $table, array $row): void
    {
        $this->pdo->prepare('INSERT INTO ' . $table . '(' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }

    private function reserve(array $document, string $source = 'manual_finalize'): ?int
    {
        $this->pdo->beginTransaction();
        try { $id = pa_autopay_enqueue_document($this->pdo, $document['type'], $document['id'], $this->config, null, $source); $this->pdo->commit(); return $id; }
        catch (Throwable $error) { $this->pdo->rollBack(); throw $error; }
    }

    private function collection(array $document): array
    {
        $s = $this->pdo->prepare('SELECT * FROM autopay_collections WHERE id=?'); $s->execute([$document['collection']]); return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function review(array $document): array
    {
        return pa_autopay_review_latest($this->pdo, (int)$document['collection']);
    }

    private function notice(array $document, ?array $config = null): array
    {
        $notice = pa_autopay_prepare_invoice_notice($this->pdo, $document['type'], $document['id'], $config ?? $this->config, $this->client['email']);
        self::assertIsArray($notice);
        return $notice;
    }

    private function ready(array $document, string $when): bool
    {
        $this->pdo->beginTransaction();
        try {
            [$current, $collection] = pa_autopay_review_lock($this->pdo, $document['type'], $document['id']);
            $ready = pa_autopay_review_worker_ready($this->pdo, $collection, $current, $this->clock($when));
            $this->pdo->commit(); return $ready;
        } catch (Throwable $error) { $this->pdo->rollBack(); throw $error; }
    }

    public function testEveryInvoiceKindRequiresSuccessfulNoticeBeforeSevenDayReviewStarts(): void
    {
        foreach ([$this->invoice(), $this->invoice('on_demand'), $this->invoice('long_term'), $this->projectInvoice()] as $document) {
            self::assertNotNull($document['collection']);
            self::assertSame('awaiting_delivery', $this->review($document)['status']);
            self::assertNull($this->review($document)['delivered_at']);
            self::assertNull($this->collection($document)['next_attempt_at']);
            $notice = $this->notice($document);
            self::assertStringContainsString('seven calendar days', $notice['text']);
            self::assertStringContainsString('100.00 USD', $notice['text']);
            self::assertStringContainsString('https://example.invalid/', $notice['action_url']);
            // Merely preparing HTML or a failed transport does not acknowledge delivery.
            self::assertNull($this->review($document)['delivered_at']);
            self::assertTrue(pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00')));
            self::assertSame('2026-02-08 12:00:00', $this->review($document)['scheduled_at']);
        }
    }

    public function testWorkerBeforeDeliveryDoesNotChargeOrPreventLaterSuccessfulNotice(): void
    {
        $document = $this->invoice(); $calls = 0;
        pa_autopay_run_one($this->pdo, $this->config, static function () use (&$calls) { $calls++; throw new RuntimeException('Processor must not be called.'); });
        self::assertSame(0, $calls);
        $notice = $this->notice($document);
        self::assertTrue(pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00')));
        self::assertSame('scheduled', $this->review($document)['status']);
    }

    public function testExactSevenDayBoundaryAndResendsDoNotExtendWindow(): void
    {
        $document = $this->invoice(); $notice = $this->notice($document);
        pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00'));
        $before = $this->review($document);
        $resend = $this->notice($document);
        self::assertTrue(pa_autopay_mark_invoice_notice_sent($this->pdo, $resend, $this->client['email'], null, $this->clock('2026-02-03 14:00:00')));
        self::assertSame($before['generation'], $this->review($document)['generation']);
        self::assertSame($before['scheduled_at'], $this->review($document)['scheduled_at']);
        self::assertFalse($this->ready($document, '2026-02-08 11:59:59'));
        self::assertTrue($this->ready($document, '2026-02-08 12:00:00'));
    }

    public function testCalendarDaysAcrossDaylightSavingAndLaterInvoiceDueDate(): void
    {
        $document = $this->invoice(); $notice = $this->notice($document);
        pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-10-28 17:00:00'));
        self::assertSame('2026-11-04 18:00:00', $this->review($document)['scheduled_at'], 'Fall-back week is 169 hours but seven local calendar days.');
        $later = $this->invoice('regular', ['due_date' => '2026-11-12']);
        pa_autopay_mark_invoice_notice_sent($this->pdo, $this->notice($later), $this->client['email'], null, $this->clock('2026-10-28 17:00:00'));
        self::assertSame('2026-11-12 06:00:00', $this->review($later)['scheduled_at']);
    }

    public function testWrongRecipientAndStaleGenerationCannotAcknowledgeInvoiceDelivery(): void
    {
        $document = $this->invoice(); $notice = $this->notice($document);
        self::assertFalse(pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, 'another@example.invalid', null, $this->clock('2026-02-01 12:00:00')));
        $this->pdo->prepare('UPDATE invoices SET total=125,balance_due=125,revision_number=revision_number+1 WHERE id=?')->execute([$document['id']]);
        $new = $this->notice($document);
        self::assertNotSame($notice['generation'], $new['generation']);
        self::assertFalse(pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00')));
        self::assertNull($this->review($document)['delivered_at']);
        self::assertTrue(pa_autopay_mark_invoice_notice_sent($this->pdo, $new, $this->client['email'], null, $this->clock('2026-02-03 12:00:00')));
        self::assertSame('2026-02-10 12:00:00', $this->review($document)['scheduled_at']);
        self::assertSame(12500, (int)$this->collection($document)['amount_cents']);
    }

    public function testEditDuringDeliveryRequiresStaffResolutionAndAnotherFullWindow(): void
    {
        $document = $this->invoice(); $notice = $this->notice($document);
        $this->pdo->prepare('UPDATE invoices SET total=125,balance_due=125,revision_number=revision_number+1 WHERE id=?')->execute([$document['id']]);
        self::assertFalse(pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00')));
        self::assertSame('held', $this->review($document)['status']);
        self::assertSame('held', $this->collection($document)['state']);
        self::assertNull(pa_autopay_prepare_invoice_notice($this->pdo, 'invoice', $document['id'], $this->config, $this->client['email']));
        pa_autopay_invoice_review_staff_restart($this->pdo, 'invoice', $document['id'], 77, $this->config, 'Confirmed the corrected amount with the client.');
        $next = $this->notice($document);
        self::assertNotSame($notice['generation'], $next['generation']);
        self::assertTrue(pa_autopay_mark_invoice_notice_sent($this->pdo, $next, $this->client['email'], null, $this->clock('2026-02-03 12:00:00')));
        self::assertSame('2026-02-10 12:00:00', $this->review($document)['scheduled_at']);
    }

    public function testChangedAmountRevisionCurrencyOrClientCannotUseOldDeliveryEvidence(): void
    {
        foreach ([['total=110,balance_due=110', 'held'], ['revision_number=revision_number+1', 'held'], ['currency="EUR"', 'failed'], ['client_id=' . $this->client()['id'], 'failed']] as [$mutation, $expectedState]) {
            $document = $this->invoice(); $notice = $this->notice($document);
            pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00'));
            $this->pdo->exec('UPDATE invoices SET ' . $mutation . ' WHERE id=' . $document['id']);
            self::assertFalse($this->ready($document, '2026-02-08 12:00:00'));
            self::assertSame($expectedState, $this->collection($document)['state']);
            if ($expectedState === 'failed') self::assertStringStartsWith('Canceled:', $this->collection($document)['last_error']);
        }
    }

    public function testSkippingAndBillingQuestionsHoldOnlyTheReviewedInvoice(): void
    {
        foreach (['skip', 'question'] as $action) {
            $document = $this->invoice();
            $notice = $this->notice($document);
            pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00'));
            $review = pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client['id'], $action, $this->config, 'Please explain this invoice.');
            self::assertSame($action === 'skip' ? 'skipped' : 'question', $review['status']);
            self::assertSame($action === 'skip' ? 'failed' : 'held', $this->collection($document)['state']);
            self::assertNull($this->collection($document)['next_attempt_at']);
            if ($action === 'question') self::assertFalse($this->ready($document, '2026-03-01 12:00:00'));
            self::assertSame('active', $this->pdo->query('SELECT status FROM autopay_enrollments WHERE id=' . $this->authorization)->fetchColumn());
        }
    }

    public function testWrongClientAndAlreadySubmittedPaymentCannotPretendToCancel(): void
    {
        $document = $this->invoice();
        try { pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client()['id'], 'skip', $this->config); self::fail('Another client canceled invoice collection.'); }
        catch (DomainException $expected) { self::assertStringContainsString('unavailable', $expected->getMessage()); }
        $this->pdo->prepare('UPDATE autopay_collections SET state="processing",attempt_count=1,stripe_payment_intent_id="pi_submitted_review" WHERE id=?')->execute([$document['collection']]);
        foreach (['skip', 'question'] as $action) {
            try { pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client['id'], $action, $this->config); self::fail('Already submitted payment pretended canceled.'); }
            catch (DomainException $expected) { self::assertStringContainsString('submission', $expected->getMessage()); }
            self::assertSame('processing', $this->collection($document)['state']);
        }
    }

    public function testStaffCanReleaseOnlyAGenuinelyUnsubmittedQuestionForManualBilling(): void
    {
        $document=$this->invoice();
        pa_autopay_invoice_review_action($this->pdo,'invoice',$document['id'],$this->client['id'],'question',$this->config,'Please correct the billed work.');
        pa_autopay_cancel_collection($this->pdo,$document['collection'],$this->config,static fn()=>self::fail('An unsent question does not need a processor cancellation.'));
        self::assertSame('failed',$this->collection($document)['state']);
        self::assertSame('Canceled before submission.',$this->collection($document)['last_error']);
        self::assertSame('Please correct the billed work.',$this->review($document)['question_text']);
        pa_autopay_assert_payment_available($this->pdo,$document['id']);
        foreach ([['attempt_count'=>1],['stripe_payment_intent_id'=>'pi_unknown_review'],['lease_token'=>str_repeat('a',32)],['lease_until'=>'2099-01-01 00:00:00'],['financial_hold'=>1]] as $guard) {
            $held=$this->invoice();
            pa_autopay_invoice_review_action($this->pdo,'invoice',$held['id'],$this->client['id'],'question',$this->config,'Staff review required.');
            $column=array_key_first($guard);
            $this->pdo->prepare('UPDATE autopay_collections SET '.$column.'=? WHERE id=?')->execute([$guard[$column],$held['collection']]);
            try {
                pa_autopay_cancel_collection($this->pdo,$held['collection'],$this->config,static fn()=>self::fail('An uncertain payment must not be released.'));
                self::fail('Submitted, leased or financially held collection was released.');
            } catch (DomainException $expected) {
                self::assertSame('held',$this->collection($held)['state']);
            }
        }
    }

    public function testExistingPaymentRequiresReviewAndNeverCreatesAnotherCharge(): void
    {
        $document = $this->invoice(); $notice = $this->notice($document);
        pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00'));
        $this->insert('payments', ['client_id' => $this->client['id'], 'invoice_id' => $document['id'], 'amount' => '25.00', 'payment_method' => 'check', 'status' => 'succeeded', 'payment_date' => '2026-02-02']);
        self::assertFalse($this->ready($document, '2026-02-08 12:00:00'));
        self::assertSame('held', $this->collection($document)['state']);
    }

    public function testDisablingAccountOrLegacyAgreementConsentCannotBroadenCollection(): void
    {
        pa_autopay_revoke_authorization($this->pdo, $this->client, 0);
        $unreserved = $this->invoice('regular', [], false);
        self::assertNull($this->reserve($unreserved));
        $this->insert('contracts', ['client_id' => $this->client['id'], 'contract_type' => 'long_term', 'status' => 'active']);
        $contract = (int)$this->pdo->lastInsertId(); $this->contracts[] = $contract;
        $this->pdo->prepare('UPDATE autopay_enrollments SET status="active",revoked_at=NULL,scope="agreement",contract_id=? WHERE id=?')->execute([$contract, $this->authorization]);
        self::assertNull($this->reserve($unreserved));
        self::assertSame('agreement', $this->pdo->query('SELECT scope FROM autopay_enrollments WHERE id=' . $this->authorization)->fetchColumn());
    }

    public function testFailureAndReceiptEmailNeverStartCollectionWindow(): void
    {
        $document = $this->invoice();
        $options = ['document_type' => 'invoice', 'document_id' => $document['id'], 'message_key' => 'synthetic-review-notice', 'invoice_notice' => true];
        $sentOptions = [];
        $failed = pa_autopay_deliver_invoice_email($this->pdo, $this->clock('2026-02-01 12:00:00'), $this->client['email'], 'Invoice', '<p>Synthetic invoice</p>', $options,
            static function ($to, $subject, $body, $options) use (&$sentOptions) { $sentOptions = $options; self::assertStringContainsString('seven calendar days', $body); return [false, 'Synthetic delivery failure']; });
        self::assertFalse($failed[0]);
        self::assertNull($this->review($document)['delivered_at']);
        self::assertStringContainsString(':autopay-review:', $sentOptions['message_key']);
        $receipt = $options; unset($receipt['invoice_notice']);
        pa_autopay_deliver_invoice_email($this->pdo, $this->clock('2026-02-02 12:00:00'), $this->client['email'], 'Receipt', '<p>Receipt only</p>', $receipt,
            static function ($to, $subject, $body) { self::assertSame('<p>Receipt only</p>', $body); return [true, 'Synthetic receipt']; });
        self::assertNull($this->review($document)['delivered_at']);
        pa_autopay_deliver_invoice_email($this->pdo, $this->clock('2026-02-03 12:00:00'), $this->client['email'], 'Invoice', '<p>Synthetic invoice</p>', $options, static fn() => [true, 'Synthetic acceptance']);
        self::assertSame('2026-02-03 12:00:00', $this->review($document)['delivered_at']);
        self::assertSame('2026-02-10 12:00:00', $this->review($document)['scheduled_at']);
    }

    public function testStaffResolutionRequiresNewNoticeAndCannotOverrideClientSkip(): void
    {
        $document = $this->invoice(); $notice = $this->notice($document);
        pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], null, $this->clock('2026-02-01 12:00:00'));
        pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client['id'], 'question', $this->config, str_repeat('界', 1000));
        self::assertTrue(mb_check_encoding($this->review($document)['question_text'], 'UTF-8'));
        $next = pa_autopay_invoice_review_staff_restart($this->pdo, 'invoice', $document['id'], 77, $this->config, 'Synthetic staff resolution');
        self::assertNotSame($notice['generation'], $next['generation']);
        $resolved = $this->pdo->query('SELECT question_text,resolution_text,resolved_by FROM autopay_invoice_reviews WHERE id=' . (int)$notice['review_id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame(str_repeat('界', 1000), $resolved['question_text']);
        self::assertSame('Synthetic staff resolution', $resolved['resolution_text']);
        self::assertSame(77, (int)$resolved['resolved_by']);
        self::assertSame('awaiting_delivery', $next['status']);
        self::assertNull($next['delivered_at']);
        self::assertNull($this->collection($document)['next_attempt_at']);
        pa_autopay_mark_invoice_notice_sent($this->pdo, $this->notice($document), $this->client['email'], null, $this->clock('2026-02-05 12:00:00'));
        self::assertSame('2026-02-12 12:00:00', $this->review($document)['scheduled_at']);
        pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client['id'], 'skip', $this->config);
        try { pa_autopay_invoice_review_staff_restart($this->pdo, 'invoice', $document['id'], 77, $this->config, 'Cannot override consent'); self::fail('Staff overrode client skip.'); }
        catch (DomainException $expected) { self::assertStringContainsString('cannot be restarted', $expected->getMessage()); }
        self::assertSame('skipped', $this->review($document)['status']);
    }

    public function testClientSkipAllowsMailedCheckWithoutReenqueuingAutomaticPayment(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/check_reports.php';
        $document = $this->invoice();
        pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client['id'], 'skip', $this->config);
        pa_autopay_assert_payment_available($this->pdo, $document['id']);
        $check = pa_check_report_submit($this->pdo, 'invoice', $document['id'], $this->client['id'], 'SYNTHETIC-687', $this->config + ['payment_methods' => ['check']]);
        self::assertSame('pending', $check['status']);
        self::assertNull($this->reserve($document));
        $calls = 0;
        pa_autopay_run_one($this->pdo, $this->config, static function () use (&$calls) { $calls++; throw new RuntimeException('Unexpected collection.'); });
        self::assertSame(0, $calls);
        self::assertSame('skipped', $this->review($document)['status']);
    }

    public function testDeliveryRecoveryUsesOriginalAcceptanceTimeAndRejectsForeignEvidence(): void
    {
        $document = $this->invoice(); $notice = $this->notice($document);
        $original = new DateTimeImmutable('2026-02-01 12:00:00', new DateTimeZone('UTC'));
        // EmailProviderManager stores PHP/app-local wall time, not MySQL NOW().
        $s = $this->pdo->prepare('INSERT INTO email_delivery_log(message_key,document_type,document_id,recipient,subject,status,sent_at) VALUES (?,"invoice",?,?,"Synthetic invoice","sent",?)');
        $s->execute(['synthetic-original-delivery:autopay-review:' . $notice['review_id'], $document['id'], $this->client['email'], $original->setTimezone(new DateTimeZone($this->config['timezone']))->format('Y-m-d H:i:s')]);
        $logId = (int)$this->pdo->lastInsertId(); $this->deliveryLogs[] = $logId;
        self::assertTrue(pa_autopay_mark_invoice_notice_sent($this->pdo, $notice, $this->client['email'], $logId, $this->clock('2026-02-03 15:00:00')));
        self::assertSame('2026-02-01 12:00:00', $this->review($document)['delivered_at']);
        self::assertSame('2026-02-08 12:00:00', $this->review($document)['scheduled_at']);
        $other = $this->invoice(); $otherNotice = $this->notice($other);
        try { pa_autopay_mark_invoice_notice_sent($this->pdo, $otherNotice, $this->client['email'], $logId, $this->clock('2026-02-03 15:00:00')); self::fail('Another invoice delivery log acknowledged this invoice.'); }
        catch (DomainException $expected) { self::assertStringContainsString('evidence', $expected->getMessage()); }
        self::assertNull($this->review($other)['delivered_at']);
    }

    private function proof(?array $client = null): array
    {
        $client ??= $this->client;
        return ['autopay_verified' => ['client_id' => $client['id'], 'email' => $client['email'], 'until' => time() + 600, 'proof' => 'synthetic']];
    }

    public function testPublicReviewRequiresCurrentTokenCsrfClientProofAndConfirmation(): void
    {
        $document = $this->invoice(); $unrelated = $this->invoice();
        $token = $this->review($document)['management_token'];
        $expired = $this->proof(); $expired['autopay_verified']['until'] = time() - 1;
        foreach ([
            ['method' => 'GET', 'proof' => $this->proof(), 'expected' => 'AutoPay'],
            ['post' => ['csrf' => 'wrong'], 'proof' => $this->proof(), 'expected' => 'Invalid request'],
            ['proof' => [], 'expected' => 'Verify your email'],
            ['proof' => $expired, 'expected' => 'Verify your email'],
            ['proof' => $this->proof($this->client()), 'expected' => 'Verify your email'],
            ['post' => ['confirm_invoice_action' => ''], 'proof' => $this->proof(), 'expected' => 'Confirm the change'],
            ['review' => str_repeat('f', 64), 'proof' => $this->proof(), 'expected' => 'unavailable'],
        ] as $case) {
            $output = $this->publicReviewController($case['review'] ?? $token, $case);
            self::assertStringContainsString($case['expected'], $output);
            self::assertSame('awaiting_delivery', $this->review($document)['status']);
            self::assertSame('pending', $this->collection($document)['state']);
        }
        $output = $this->publicReviewController($token, ['proof' => $this->proof(), 'post' => ['client_id' => 999999, 'document_id' => $unrelated['id']]]);
        self::assertStringContainsString('Automatic payment is off for this invoice only', $output);
        self::assertSame('skipped', $this->review($document)['status']);
        self::assertSame('failed', $this->collection($document)['state']);
        self::assertSame('awaiting_delivery', $this->review($unrelated)['status']);
        self::assertSame('active', $this->pdo->query('SELECT status FROM autopay_enrollments WHERE id=' . $this->authorization)->fetchColumn());
    }

    public function testPublicQuestionHoldsCollectionAndSupersededTokenCannotChangeInvoice(): void
    {
        $document = $this->invoice(); $token = $this->review($document)['management_token'];
        $this->pdo->prepare('UPDATE invoices SET revision_number=revision_number+1 WHERE id=?')->execute([$document['id']]);
        $this->notice($document);
        $output = $this->publicReviewController($token, ['proof' => $this->proof()]);
        self::assertStringContainsString('replaced', $output);
        self::assertSame('awaiting_delivery', $this->review($document)['status']);
        $token = $this->review($document)['management_token'];
        $output = $this->publicReviewController($token, ['proof' => $this->proof(), 'post' => ['action' => 'question_invoice', 'question' => 'Please explain the additional service.']]);
        self::assertStringContainsString('on hold', $output);
        self::assertSame('question', $this->review($document)['status']);
        self::assertSame('held', $this->collection($document)['state']);
    }

    public function testPublicReviewActuallyVerifiesSessionBoundOneTimeEmailCode(): void
    {
        $document = $this->invoice(); $token = $this->review($document)['management_token'];
        $sessionId = 'synthetic-review-otp-' . bin2hex(random_bytes(8));
        $code = '';
        $challenge = pa_autopay_send_code($this->pdo, $this->client, $sessionId, static function ($email, $value) use (&$code) { $code = $value; });
        $case = ['session_id' => $sessionId, 'session' => ['autopay_challenge' => $challenge], 'post' => ['action' => 'verify', 'code' => '00000000']];
        self::assertStringContainsString('Invalid or expired verification code', $this->publicReviewController($token, $case));
        $case['post']['code'] = $code;
        self::assertStringContainsString('Email verified', $this->publicReviewController($token, $case));
        self::assertStringContainsString('Invalid or expired verification code', $this->publicReviewController($token, $case));
        self::assertSame('awaiting_delivery', $this->review($document)['status']);
        self::assertSame('pending', $this->collection($document)['state']);
    }

    public function testStaffResolutionControllerRequiresPermissionCsrfConfirmationAndFreshNotice(): void
    {
        $document = $this->invoice();
        pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client['id'], 'question', $this->config, 'Synthetic billing question');
        $before = $this->review($document);
        $suffix = bin2hex(random_bytes(5));
        $this->insert('users', ['username' => 'synthetic-review-staff-' . $suffix, 'email' => 'review-staff-' . $suffix . '@example.invalid', 'password_hash' => 'synthetic-disabled-password', 'role' => 'admin']);
        $actor = (int)$this->pdo->lastInsertId(); $this->users[] = $actor;
        foreach ([
            ['method' => 'GET', 'actor' => $actor, 'status' => 405],
            ['actor' => $actor, 'post' => ['csrf' => 'wrong'], 'status' => 403],
            ['actor' => 0, 'status' => 403],
            ['actor' => $actor, 'post' => ['confirm_review_resolution' => ''], 'status' => 303],
            ['actor' => $actor, 'post' => ['resolution_note' => ''], 'status' => 303],
        ] as $case) {
            self::assertSame($case['status'], $this->staffResolutionController($document, $case));
            self::assertSame($before['generation'], $this->review($document)['generation']);
            self::assertSame('held', $this->collection($document)['state']);
        }
        self::assertSame(303, $this->staffResolutionController($document, ['actor' => $actor]));
        self::assertNotSame($before['generation'], $this->review($document)['generation']);
        self::assertSame('awaiting_delivery', $this->review($document)['status']);
        self::assertNull($this->review($document)['delivered_at']);
        self::assertNull($this->collection($document)['next_attempt_at']);
        pa_autopay_invoice_review_action($this->pdo, 'invoice', $document['id'], $this->client['id'], 'skip', $this->config);
        $skipped = $this->review($document);
        self::assertSame(303, $this->staffResolutionController($document, ['actor' => $actor]));
        self::assertSame($skipped['generation'], $this->review($document)['generation']);
        self::assertSame('skipped', $this->review($document)['status']);
    }

    private function staffResolutionController(array $document, array $case): int
    {
        $root = dirname(__DIR__, 2);
        $payload = base64_encode(json_encode([$document, $case, $this->config], JSON_THROW_ON_ERROR));
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($root . '/src/config/app.php', true) . ';[$document,$case,$config]=json_decode(base64_decode(' . var_export($payload, true) . '),true);$appConfig=array_replace($appConfig,$config);ini_set("display_errors","stderr");error_reporting(E_ALL);session_id("synthetic-staff-review-".bin2hex(random_bytes(8)));session_start();register_shutdown_function(static function(){echo "\\nSYNTHETIC_STATUS=".http_response_code();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});$_SESSION=["csrf"=>"synthetic-csrf","user"=>["id"=>$case["actor"],"role"=>"admin"]];$_SERVER["REQUEST_METHOD"]=$case["method"]??"POST";$_POST=($case["post"]??[])+["document_type"=>$document["type"],"document_id"=>$document["id"],"csrf"=>"synthetic-csrf","confirm_review_resolution"=>"1","resolution_note"=>"Synthetic staff resolution"];require ' . var_export($root . '/src/controllers/invoice/autopay_review_resolve.php', true) . ';';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        preg_match('/SYNTHETIC_STATUS=([0-9]+)/', $output, $match);
        return (int)($match[1] ?? 0);
    }

    public function testActualSmtpAcceptanceArmsReviewOnceAndReceiptDoesNotArmAnotherInvoice(): void
    {
        require_once dirname(__DIR__, 2) . '/src/services/EmailService.php';
        require_once dirname(__DIR__, 2) . '/src/services/EmailProviderManager.php';
        global $appConfig, $pdo;
        $oldConfig = $appConfig ?? null; $oldPdo = $pdo ?? null;
        $oldKey = getenv('APP_ENCRYPTION_KEY'); $oldTimezone = date_default_timezone_get();
        $oldErrorLog = ini_get('error_log');
        $oldFixtureConfig = $this->config;
        $directory = sys_get_temp_dir() . '/pa-invoice-review-smtp-' . bin2hex(random_bytes(10));
        mkdir($directory, 0700); $process = null; $providerId = null;
        $oldState = null;
        try {
            ini_set('error_log', $directory . '/php-error.log');
            $oldState = $this->pdo->query('SELECT active_connection_id,updated_by FROM email_provider_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM email_provider_connections')->fetchColumn(), 'Loopback fixture must not replace an existing provider.');
            date_default_timezone_set('UTC'); putenv('APP_ENCRYPTION_KEY=synthetic-invoice-review-smtp-only');
            $this->config['timezone'] = 'UTC';
            $document = $this->invoice(); $other = $this->invoice();
            $this->pdo->prepare('UPDATE autopay_enrollments SET last4="4242" WHERE id=?')->execute([$this->authorization]);
            $server = <<<'PHP'
<?php
$directory=$argv[1];$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$server)exit(1);
file_put_contents($directory.'/port',explode(':',stream_socket_get_name($server,false))[1]);$count=0;
while($client=@stream_socket_accept($server,20)){
 stream_set_timeout($client,10);fwrite($client,"220 synthetic SMTP capture\r\n");
 while(($line=fgets($client))!==false){$command=strtoupper(strtok(trim($line),' '));
  if($command==='EHLO'||$command==='HELO')fwrite($client,"250 synthetic.local\r\n");
  elseif($command==='DATA'){fwrite($client,"354 End with dot\r\n");$data='';while(($body=fgets($client))!==false&&$body!==".\r\n")$data.=str_starts_with($body,'..')?substr($body,1):$body;file_put_contents($directory.'/message-'.(++$count).'.eml',$data);fwrite($client,"250 captured\r\n");}
  elseif($command==='QUIT'){fwrite($client,"221 bye\r\n");break;}else fwrite($client,"250 accepted\r\n");
 }fclose($client);
}fclose($server);
PHP;
            file_put_contents($directory . '/smtp.php', $server);
            $process = proc_open([PHP_BINARY, $directory . '/smtp.php', $directory], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes);
            self::assertIsResource($process);
            for ($i = 0; $i < 100 && !is_file($directory . '/port'); $i++) usleep(20000);
            self::assertFileExists($directory . '/port');
            $port = (int)file_get_contents($directory . '/port'); self::assertGreaterThan(0, $port);
            $pdo = $this->pdo;
            $appConfig = $this->config + ['brand_name' => 'Synthetic Review Company', 'from_company' => 'Synthetic Review Company', 'from_email' => 'no-reply@example.invalid', 'smtp_from_email' => 'no-reply@example.invalid', 'email_no_reply_notice_enabled' => 1];
            $issuer = json_encode(\App\Services\DocumentIssuerPolicy::resolve($appConfig, null, 'company'), JSON_THROW_ON_ERROR);
            foreach ([$document, $other] as $issued) $this->pdo->prepare('UPDATE invoices SET issuer_mode="company",issuer_snapshot=? WHERE id=?')->execute([$issuer, $issued['id']]);
            $manager = new EmailProviderManager($this->pdo, $appConfig);
            $providerId = $manager->upsertSmtp(['host' => '127.0.0.1', 'port' => $port, 'secure' => 'none', 'username' => '', 'password' => ''], 'no-reply@example.invalid', 'Synthetic Review Company');
            $manager->activate($providerId);
            $pdf = "%PDF-1.4\n%synthetic review attachment\n%%EOF\n";
            $options = ['invoice_notice' => true, 'document_type' => 'invoice', 'document_id' => $document['id'], 'message_key' => 'synthetic-review-smtp-' . $document['id'], 'attachments' => [['filename' => 'synthetic-invoice.pdf', 'content' => $pdf, 'mime' => 'application/pdf']]];
            self::assertNull($this->review($document)['delivered_at']);
            $invoiceBody = '<!doctype html><html><head><meta charset="utf-8"><title>Synthetic invoice</title></head><body><p>Outstanding balance: 100.00 USD</p></body></html>';
            $first = EmailService::sendEmail($this->client['email'], 'Synthetic review invoice', $invoiceBody, $options);
            self::assertTrue($first[0], (string)$first[1] . ' ' . (is_file($directory . '/php-error.log') ? file_get_contents($directory . '/php-error.log') : ''));
            $sent = $this->review($document);
            self::assertSame('scheduled', $sent['status']);
            self::assertNotNull($sent['delivered_at']); self::assertNotNull($sent['scheduled_at']);
            self::assertGreaterThanOrEqual(7 * 86400, strtotime($sent['scheduled_at']) - strtotime($sent['delivered_at']));
            self::assertCount(1, glob($directory . '/message-*.eml'));
            $parts = $this->mimeParts((string)file_get_contents($directory . '/message-1.eml'));
            $html = $parts['text/html'][0];
            self::assertStringContainsString('100.00 USD', $html);
            self::assertStringContainsString('4242', $html);
            self::assertStringContainsString('scheduled on or after', $html);
            self::assertStringContainsString('seven calendar days', $html);
            self::assertStringContainsString('public-autopay', $html);
            self::assertStringContainsString($sent['management_token'], $html);
            self::assertSame($pdf, $parts['application/pdf'][0]);
            $repeat = EmailService::sendEmail($this->client['email'], 'Synthetic review invoice', '<p>Resend must not reset review.</p>', $options);
            self::assertTrue($repeat[0]); self::assertSame('Already sent', $repeat[1]);
            self::assertCount(1, glob($directory . '/message-*.eml'));
            self::assertSame($sent['delivered_at'], $this->review($document)['delivered_at']);
            self::assertSame($sent['scheduled_at'], $this->review($document)['scheduled_at']);
            $receipt = EmailService::sendEmail($this->client['email'], 'Synthetic receipt only', '<p>Receipt test</p>', ['document_type' => 'invoice', 'document_id' => $other['id'], 'message_key' => 'synthetic-review-receipt-' . $other['id']]);
            self::assertTrue($receipt[0], (string)$receipt[1]);
            self::assertCount(2, glob($directory . '/message-*.eml'));
            self::assertNull($this->review($other)['delivered_at']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                for ($i = 0; $i < 20 && proc_get_status($process)['running']; $i++) usleep(50000);
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
                proc_close($process);
            }
            if ($providerId !== null) {
                $this->pdo->prepare('UPDATE email_provider_state SET active_connection_id=?,updated_by=? WHERE id=1')->execute([$oldState['active_connection_id'] ?? null, $oldState['updated_by'] ?? null]);
                $this->pdo->prepare('DELETE FROM email_delivery_log WHERE provider_connection_id=?')->execute([$providerId]);
                $this->pdo->prepare('DELETE FROM email_provider_connections WHERE id=?')->execute([$providerId]);
            }
            $appConfig = $oldConfig; $pdo = $oldPdo; $this->config = $oldFixtureConfig;
            date_default_timezone_set($oldTimezone);
            ini_set('error_log', (string)$oldErrorLog);
            $oldKey === false ? putenv('APP_ENCRYPTION_KEY') : putenv('APP_ENCRYPTION_KEY=' . $oldKey);
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    private function mimeParts(string $mime): array
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $mime, 2), 2, '');
        $head = (string)preg_replace('/\r\n[ \t]+/', ' ', $head);
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2); $headers[strtolower($name)] = trim($value);
        }
        $type = $headers['content-type'] ?? 'text/plain';
        if (preg_match('/boundary="([^"]+)"/', $type, $match)) {
            $result = [];
            foreach (array_slice(explode('--' . $match[1], $body), 1) as $part) {
                if (str_starts_with($part, '--')) continue;
                foreach ($this->mimeParts(ltrim($part, "\r\n")) as $kind => $values) $result[$kind] = array_merge($result[$kind] ?? [], $values);
            }
            return $result;
        }
        $body = (string)preg_replace('/\r\n$/', '', $body);
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        if ($encoding === 'base64') $body = (string)base64_decode($body, true);
        elseif ($encoding === 'quoted-printable') $body = quoted_printable_decode($body);
        return [strtolower(trim(explode(';', $type, 2)[0])) => [$body]];
    }

    private function publicReviewController(string $token, array $case): string
    {
        $root = dirname(__DIR__, 2);
        $payload = base64_encode(json_encode([$token, $case, $this->config], JSON_THROW_ON_ERROR));
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($root . '/src/config/app.php', true) . ';[$token,$case,$config]=json_decode(base64_decode(' . var_export($payload, true) . '),true);$appConfig=array_replace($appConfig,$config);ini_set("display_errors","stderr");error_reporting(E_ALL);session_id($case["session_id"]??("synthetic-invoice-review-".bin2hex(random_bytes(8))));session_start();register_shutdown_function(static function(){if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});$_SESSION=["csrf"=>"synthetic-csrf"]+($case["proof"]??[])+($case["session"]??[]);$_SERVER["REQUEST_METHOD"]=$case["method"]??"POST";$_SERVER["REMOTE_ADDR"]="127.0.0.37";$_GET=["review"=>$token];$_POST=($case["post"]??[])+["review"=>$token,"csrf"=>"synthetic-csrf","action"=>"skip_invoice","confirm_invoice_action"=>"1"];require ' . var_export($root . '/src/controllers/public_view/public_autopay.php', true) . ';';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        return $output;
    }
}
