<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/stripe_single_payment_reconciliation.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SinglePaymentStripeFixture extends StripeService
{
    public array $calls = [];
    public ?Closure $beforeFetch = null;
    public bool $failFetch = false;
    public array $session = [];
    public function getCheckoutSession(string $id): array { $this->calls[] = 'GET checkout/sessions/' . $id; return $this->session; }
    public function __construct(public array $intent) { parent::__construct(); }
    public function getReconciliationContext(): array { $this->calls[] = 'GET account'; return ['account_id' => 'acct_synthetic', 'livemode' => false]; }
    public function getPaymentIntentWithBalanceTransaction(string $id): array {
        $this->calls[] = 'GET payment_intents/' . $id;
        if ($this->failFetch) throw new RuntimeException('Synthetic provider unavailable');
        if ($this->beforeFetch) ($this->beforeFetch)();
        return $this->intent;
    }
    public function getCharge(string $chargeId): array { throw new LogicException('Unexpected provider call'); }
    public function getBalanceTransaction($id) { throw new LogicException('Unexpected provider call'); }
}

final class StripeSinglePaymentReconciliationTest extends TestCase
{
    public static function evidence(): array
    {
        $payment = ['id' => 1, 'invoice_id' => 2, 'client_id' => 3, 'stripe_payment_intent_id' => 'pi_synthetic', 'processor_payment_id' => null];
        $invoice = ['id' => 2, 'client_id' => 3, 'total' => '527.50', 'currency' => 'USD', 'stripe_customer_id' => null];
        $pi = ['id' => 'pi_synthetic', 'object' => 'payment_intent', 'status' => 'succeeded', 'livemode' => false,
            'currency' => 'usd', 'amount' => 52750, 'amount_received' => 52750,
            'metadata' => ['invoice_id' => '2', 'pa_invoice_amount' => '527.50'],
            'latest_charge' => ['id' => 'ch_synthetic', 'object' => 'charge', 'payment_intent' => 'pi_synthetic',
                'paid' => true, 'captured' => true, 'livemode' => false, 'amount' => 52750, 'currency' => 'usd',
                'amount_refunded' => 0, 'disputed' => false, 'created' => 1792440000,
                'balance_transaction' => ['id' => 'txn_synthetic', 'object' => 'balance_transaction', 'source' => 'ch_synthetic',
                    'currency' => 'usd', 'amount' => 52750, 'fee' => 1560, 'net' => 51190]]];
        return [$pi, ['account_id' => 'acct_synthetic', 'livemode' => false], $invoice, $payment];
    }

    public function testAuthoritativeGrossCreditsPrincipalWithoutSubtractingStripeFee(): void
    {
        self::assertSame(52750, stripe_single_payment_evidence(...self::evidence())['payment_cents']);
    }

    public static function invalidEvidence(): array
    {
        return array_map(static fn($case) => [$case], ['pi', 'invoice', 'client', 'currency', 'amount', 'mode', 'captured',
            'failed', 'refund', 'dispute', 'fee', 'charge', 'balance', 'aliases', 'customer', 'surcharge', 'account', 'connect']);
    }

    #[DataProvider('invalidEvidence')]
    public function testMismatchedSettlementIsRefusedBeforeLedgerMutation(string $case): void
    {
        [$pi, $context, $invoice, $payment] = self::evidence();
        switch ($case) {
            case 'pi': $pi['id'] = 'pi_different'; break;
            case 'invoice': $pi['metadata']['invoice_id'] = '4'; break;
            case 'client': $payment['client_id'] = 4; break;
            case 'currency': $pi['currency'] = 'cad'; break;
            case 'amount': $pi['amount_received'] = 52749; break;
            case 'mode': $pi['livemode'] = true; break;
            case 'captured': $pi['latest_charge']['captured'] = false; break;
            case 'failed': $pi['status'] = 'requires_payment_method'; break;
            case 'refund': $pi['latest_charge']['amount_refunded'] = 1; break;
            case 'dispute': $pi['latest_charge']['disputed'] = true; break;
            case 'fee': $pi['latest_charge']['balance_transaction']['fee'] = -1; break;
            case 'charge': $pi['latest_charge']['payment_intent'] = 'pi_other'; break;
            case 'balance': $pi['latest_charge']['balance_transaction']['currency'] = 'cad'; break;
            case 'aliases': $pi['metadata']['pa_invoice_id'] = '4'; break;
            case 'customer': $invoice['stripe_customer_id'] = 'cus_other'; break;
            case 'surcharge': $pi['metadata']['surcharge_amount'] = '1'; break;
            case 'account': $context['account_id'] = ''; break;
            case 'connect': $pi['on_behalf_of'] = 'acct_other'; break;
        }
        $this->expectException(Throwable::class);
        stripe_single_payment_evidence($pi, $context, $invoice, $payment);
    }

    private function database(): PDO
    {
        $dsn = getenv('PA_STRIPE_HANDLER_TEST_DSN') ?: '';
        if ($dsn === '') self::markTestSkipped('Requires explicit migrated disposable Stripe test database.');
        $local = preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=pa_stripe_[a-z0-9_]+(?:;charset=utf8mb4)?\z/', $dsn);
        $ci = $dsn === 'mysql:host=db;port=3306;dbname=project_alpha;charset=utf8mb4'
            && getenv('PA_DISPOSABLE_SCHEMA_PROOF') === '1' && getenv('PA_STRIPE_HANDLER_TEST_ALLOW') === 'isolated-disposable-only';
        if (!$local && !$ci) throw new RuntimeException('Disposable test database guard refused DSN.');
        return new PDO($dsn, getenv('PA_STRIPE_HANDLER_TEST_USER') ?: 'root', getenv('PA_STRIPE_HANDLER_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function fixture(PDO $pdo): array
    {
        invoice_ensure_payments_schema($pdo);
        $pdo->exec('INSERT INTO clients(name,email) VALUES ("Synthetic single-payment repair","repair@example.invalid")');
        $clientId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO invoices(client_id,invoice_type,status,subtotal,total,amount_paid,balance_due,finalized_at,collection_mode) VALUES (?,"regular","unpaid",527.50,527.50,0,527.50,NOW(),"direct")')->execute([$clientId]);
        $invoiceId = (int)$pdo->lastInsertId();
        [$pi] = self::evidence();
        $pi['id'] = 'pi_synthetic_' . bin2hex(random_bytes(8));
        $pi['metadata']['invoice_id'] = (string)$invoiceId;
        $pi['latest_charge']['payment_intent'] = $pi['id'];
        $pdo->prepare('INSERT INTO payments(client_id,invoice_id,payment_method,stripe_payment_intent_id,status,amount,payment_date) VALUES (?, ?,"stripe",?,"failed",0,CURDATE())')->execute([$clientId, $invoiceId, $pi['id']]);
        $paymentId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO users(email,password_hash,role) VALUES (?,"synthetic-unusable-password","admin")')
            ->execute(['repair-' . bin2hex(random_bytes(8)) . '@example.invalid']);
        $actorId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO public_links(token,document_type,document_id,expire_when_paid) VALUES (?,"invoice",?,1)')
            ->execute([bin2hex(random_bytes(32)), $invoiceId]);
        return [$clientId, $invoiceId, $paymentId, $actorId, new SinglePaymentStripeFixture($pi)];
    }

    private function clean(PDO $pdo, array $fixture): void
    {
        if ($pdo->inTransaction()) $pdo->rollBack();
        [$clientId, $invoiceId, $paymentId, $actorId, $stripe] = $fixture;
        $pdo->prepare('DELETE FROM system_audit WHERE entity_type="payment" AND entity_id=?')->execute([$paymentId]);
        $pdo->prepare('DELETE FROM stripe_refunds WHERE stripe_payment_intent_id=?')->execute([$stripe->intent['id']]);
        $pdo->prepare('DELETE FROM stripe_disputes WHERE stripe_payment_intent_id=?')->execute([$stripe->intent['id']]);
        $pdo->prepare('DELETE FROM public_links WHERE document_type="invoice" AND document_id=?')->execute([$invoiceId]);
        $pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$invoiceId]);
        $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$invoiceId]);
        $pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$clientId]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$actorId]);
    }

    public function testSingleFailedAttemptSettlesInPlaceWithActualFeesAndRepeatIsNoOp(): void
    {
        $pdo = $this->database(); $fixture = $this->fixture($pdo);
        [, $invoiceId, $paymentId, $actorId, $stripe] = $fixture;
        try {
            $emailsBefore = (int)$pdo->query('SELECT COUNT(*) FROM email_delivery_log')->fetchColumn();
            self::assertSame('reconciled', stripe_reconcile_existing_payment($pdo, $stripe, [], $paymentId, $actorId)['status']);
            $payment = $pdo->query('SELECT * FROM payments WHERE id=' . $paymentId)->fetch(PDO::FETCH_ASSOC);
            self::assertSame('succeeded', $payment['status']);
            self::assertSame(527.50, (float)$payment['amount']);
            self::assertSame(15.60, (float)$payment['processor_fee_amount']);
            self::assertSame(511.90, (float)$payment['processor_net_amount']);
            self::assertSame('actual', $payment['processor_fee_source']);
            self::assertSame(gmdate('Y-m-d', $stripe->intent['latest_charge']['created']), $payment['payment_date']);
            $invoice = $pdo->query('SELECT * FROM invoices WHERE id=' . $invoiceId)->fetch(PDO::FETCH_ASSOC);
            self::assertSame(1, (int)$pdo->query('SELECT revoked FROM public_links WHERE document_type="invoice" AND document_id=' . $invoiceId)->fetchColumn());
            self::assertSame('paid', $invoice['status']); self::assertSame(0.0, (float)$invoice['balance_due']);
            self::assertSame(gmdate('Y-m-d H:i:s', $stripe->intent['latest_charge']['created']), $invoice['paid_at']);
            self::assertSame('unchanged', stripe_reconcile_existing_payment($pdo, $stripe, [], $paymentId, $actorId)['status']);
            self::assertSame($payment, $pdo->query('SELECT * FROM payments WHERE id=' . $paymentId)->fetch(PDO::FETCH_ASSOC));
            self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id=' . $invoiceId)->fetchColumn());
            self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM system_audit WHERE action="payment.stripe_reconciled" AND entity_id=' . $paymentId)->fetchColumn());
            self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM payment_receipts WHERE payment_id=' . $paymentId)->fetchColumn());
            self::assertSame($emailsBefore, (int)$pdo->query('SELECT COUNT(*) FROM email_delivery_log')->fetchColumn());
            self::assertSame(['GET account','GET payment_intents/' . $stripe->intent['id'],'GET account','GET payment_intents/' . $stripe->intent['id']], $stripe->calls);
        } finally { $this->clean($pdo, $fixture); }
    }

    public static function heldRepairs(): array { return [['get-failed'], ['concurrent-change'], ['refund'], ['dispute'], ['audit-failed'], ['checkout-open'], ['checkout-other']]; }

    #[DataProvider('heldRepairs')]
    public function testHeldRepairPreservesFailedLedgerAndInvoice(string $case): void
    {
        $pdo = $this->database(); $fixture = $this->fixture($pdo);
        [, $invoiceId, $paymentId, $actorId, $stripe] = $fixture;
        $triggerName = 'synthetic_reconcile_' . bin2hex(random_bytes(4));
        try {
            if (str_starts_with($case, 'checkout-')) {
                $pdo->prepare('UPDATE payments SET stripe_session_id="cs_synthetic" WHERE id=?')->execute([$paymentId]);
                $pdo->prepare('UPDATE invoices SET stripe_session_id="cs_synthetic",stripe_checkout_expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=?')->execute([$invoiceId]);
                $stripe->session = ['id' => 'cs_synthetic', 'status' => $case === 'checkout-open' ? 'open' : 'complete',
                    'payment_status' => 'paid', 'payment_intent' => $case === 'checkout-other' ? 'pi_other' : $stripe->intent['id']];
            }
            if ($case === 'get-failed') $stripe->failFetch = true;
            if ($case === 'concurrent-change') $stripe->beforeFetch = static function () use ($pdo, $paymentId): void {
                $pdo->exec('UPDATE payments SET client_id=NULL WHERE id=' . $paymentId);
            };
            if ($case === 'refund') $pdo->prepare('INSERT INTO stripe_refunds(stripe_refund_id,stripe_payment_intent_id,amount,status) VALUES (?,?,1,"pending")')->execute(['re_synthetic_' . bin2hex(random_bytes(4)), $stripe->intent['id']]);
            if ($case === 'dispute') $pdo->prepare('INSERT INTO stripe_disputes(stripe_dispute_id,stripe_payment_intent_id,amount,status) VALUES (?,?,1,"won")')->execute(['dp_synthetic_' . bin2hex(random_bytes(4)), $stripe->intent['id']]);
            if ($case === 'audit-failed') $pdo->exec("CREATE TRIGGER {$triggerName} BEFORE INSERT ON system_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit refusal'");
            try { stripe_reconcile_existing_payment($pdo, $stripe, [], $paymentId, $actorId); self::fail('Must refuse repair'); }
            catch (RuntimeException $error) { self::assertNotEmpty($error->getMessage()); }
            self::assertSame('failed', $pdo->query('SELECT status FROM payments WHERE id=' . $paymentId)->fetchColumn());
            self::assertSame(0.0, (float)$pdo->query('SELECT amount FROM payments WHERE id=' . $paymentId)->fetchColumn());
            self::assertSame(0, (int)$pdo->query('SELECT revoked FROM public_links WHERE document_type="invoice" AND document_id=' . $invoiceId)->fetchColumn());
            self::assertSame('unpaid', $pdo->query('SELECT status FROM invoices WHERE id=' . $invoiceId)->fetchColumn());
            self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM system_audit WHERE action="payment.stripe_reconciled" AND entity_id=' . $paymentId)->fetchColumn());
            self::assertFalse($pdo->inTransaction());
        } finally {
            if ($case === 'audit-failed') $pdo->exec('DROP TRIGGER IF EXISTS ' . $triggerName);
            $this->clean($pdo, $fixture);
        }
    }
    public function testCompletedMatchingCheckoutIsClearedAfterSettlement(): void
    {
        $pdo = $this->database(); $fixture = $this->fixture($pdo);
        [, $invoiceId, $paymentId, $actorId, $stripe] = $fixture;
        try {
            $pdo->prepare('UPDATE invoices SET stripe_session_id="cs_synthetic_complete",stripe_checkout_expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=?')->execute([$invoiceId]);
            $stripe->session = ['id' => 'cs_synthetic_complete', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => $stripe->intent['id']];
            self::assertSame('reconciled', stripe_reconcile_existing_payment($pdo, $stripe, [], $paymentId, $actorId)['status']);
            $invoice = $pdo->query('SELECT stripe_session_id,stripe_checkout_expires_at FROM invoices WHERE id=' . $invoiceId)->fetch(PDO::FETCH_ASSOC);
            self::assertNull($invoice['stripe_session_id']); self::assertNull($invoice['stripe_checkout_expires_at']);
            self::assertSame(['GET checkout/sessions/cs_synthetic_complete', 'GET account', 'GET payment_intents/' . $stripe->intent['id']], $stripe->calls);
        } finally { $this->clean($pdo, $fixture); }
    }

    public function testSecondConnectionCanSettleDuringProviderReadWithoutDoubleCredit(): void
    {
        $pdo = $this->database(); $second = $this->database(); $fixture = $this->fixture($pdo);
        [, $invoiceId, $paymentId, $actorId, $stripe] = $fixture;
        $otherStripe = new SinglePaymentStripeFixture($stripe->intent);
        try {
            $stripe->beforeFetch = static function () use ($second, $otherStripe, $paymentId, $actorId): void {
                self::assertSame('reconciled', stripe_reconcile_existing_payment($second, $otherStripe, [], $paymentId, $actorId)['status']);
            };
            self::assertSame('unchanged', stripe_reconcile_existing_payment($pdo, $stripe, [], $paymentId, $actorId)['status']);
            self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id=' . $invoiceId)->fetchColumn());
            self::assertSame(527.50, (float)$pdo->query('SELECT amount_paid FROM invoices WHERE id=' . $invoiceId)->fetchColumn());
            self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM system_audit WHERE action="payment.stripe_reconciled" AND entity_id=' . $paymentId)->fetchColumn());
        } finally { $this->clean($pdo, $fixture); }
    }

}
