<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/controllers/webhook/stripe_payment_succeeded.php';
require_once dirname(__DIR__, 2) . '/src/controllers/webhook/stripe_checkout_completed.php';
require_once dirname(__DIR__, 2) . '/src/utils/stripe_reconciliation_import.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No provider calls: list results are fixtures and normalization is production code. */
final class StripeInvoiceFixtureService extends StripeService
{
    public function __construct(private array $intents) { parent::__construct(); }
    public function listPaymentIntentsBetween(int $since, ?int $until = null, ?int $maxItems = 1000): array { return $this->intents; }
    public function listChargesBetween(int $since, ?int $until = null, ?int $maxItems = null): array { return []; }
    public function getCharge(string $chargeId): array { throw new LogicException('Network access forbidden in fixture.'); }
    public function getBalanceTransaction($btId) { throw new LogicException('Network access forbidden in fixture.'); }
}

/** Reject even a SELECT, proving malformed inputs are held before ledger work. */
final class StripeInvoiceNoSqlConnection extends PDO
{
    public int $calls = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->calls++; throw new LogicException('Unexpected SQL.'); }
    public function beginTransaction(): bool { $this->calls++; throw new LogicException('Unexpected transaction.'); }
    public function inTransaction(): bool { return false; }
}

final class StripeInvoiceHandlersTest extends TestCase
{
    private ?PDO $pdo = null;
    private array $previousConfig = [];
    private int $organizationId = 0;
    private int $clientId = 0;
    private int $invoiceId = 0;
    private array $intent = [];
    private array $session = [];

    protected function setUp(): void
    {
        $this->previousConfig = $GLOBALS['appConfig'] ?? [];
        // No secret keys or transports are ever loaded from a real config.
        $GLOBALS['appConfig'] = ['notify_invoice_paid' => false, 'payment_receipts_enabled' => false];
        self::assertNull(StripeService::fromAppConfig($GLOBALS['appConfig']));
    }

    protected function tearDown(): void
    {
        $GLOBALS['appConfig'] = $this->previousConfig;
        if (!$this->pdo) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->invoiceId) {
            $this->pdo->prepare('DELETE FROM activity_log WHERE document_type="invoice" AND document_id=?')->execute([$this->invoiceId]);
            $this->pdo->prepare('DELETE FROM public_links WHERE document_type="invoice" AND document_id=?')->execute([$this->invoiceId]);
            $this->pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$this->invoiceId]);
            $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$this->invoiceId]);
        }
        if ($this->clientId) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->clientId]);
        if ($this->organizationId) $this->pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$this->organizationId]);
    }

    public static function paths(): array { return [['intent'], ['checkout'], ['reconcile']]; }

    #[DataProvider('paths')]
    public function testSettledPrincipalPaysInvoiceWithoutDeductingProcessorFees(string $path): void
    {
        $this->fixture();
        $this->deliver($path);
        $payment = $this->payment();
        self::assertSame(527.50, (float)$payment['amount']);
        self::assertSame(0.0, (float)$payment['surcharge_paid']);
        self::assertSame(527.50, (float)$payment['processor_gross_amount']);
        self::assertGreaterThan(0, (float)$payment['processor_fee_amount']);
        self::assertLessThan(527.50, (float)$payment['processor_net_amount']);
        if ($path === 'reconcile') {
            // The real normalizer reads this expanded Stripe balance transaction.
            self::assertSame(15.60, (float)$payment['processor_fee_amount']);
            self::assertSame(511.90, (float)$payment['processor_net_amount']);
            self::assertSame('actual', $payment['processor_fee_source']);
        }
        $invoice = $this->invoice();
        self::assertSame('paid', $invoice['status']);
        self::assertSame(527.50, (float)$invoice['amount_paid']);
        self::assertSame(0.0, (float)$invoice['balance_due']);
        $activity = $this->pdo->prepare('SELECT metadata FROM activity_log WHERE event_type="invoice_paid" AND document_id=?');
        $activity->execute([$this->invoiceId]);
        $metadata = json_decode((string)$activity->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(527.50, (float)$metadata['amount'], 'The actual handler passes principal to the notification.');
    }

    public static function eventOrders(): array
    {
        return [
            [['intent', 'checkout', 'reconcile', 'intent']],
            [['checkout', 'intent', 'reconcile', 'checkout']],
            [['reconcile', 'checkout', 'intent', 'reconcile']],
        ];
    }

    #[DataProvider('eventOrders')]
    public function testCrossEventOrderAndRetriesDoNotCreditTwice(array $paths): void
    {
        $this->fixture();
        foreach ($paths as $path) $this->deliver($path);
        self::assertSame(1, $this->paymentCount());
        self::assertSame(527.50, (float)$this->payment()['amount']);
        self::assertSame(527.50, (float)$this->invoice()['amount_paid']);
        self::assertSame('paid', $this->invoice()['status']);
        $activity = $this->pdo->prepare('SELECT COUNT(*) FROM activity_log WHERE event_type="invoice_paid" AND document_id=?');
        $activity->execute([$this->invoiceId]);
        self::assertSame(1, (int)$activity->fetchColumn(), 'Duplicate paths only retry delivery, not payment side effects.');
    }

    #[DataProvider('paths')]
    public function testExistingZeroLedgerRowIsHeldWithoutMutationOrNotification(string $path): void
    {
        $this->fixture();
        $this->pdo->prepare('INSERT INTO payments (client_id,invoice_id,amount,surcharge_paid,payment_method,stripe_payment_intent_id,stripe_session_id,status,payment_date) VALUES (?,?,0,0,"stripe",?,?,"succeeded",CURDATE())')
            ->execute([$this->clientId, $this->invoiceId, $this->intent['id'], $this->session['id']]);
        $before = $this->payment();
        $beforeInvoice = $this->invoice();
        if ($path === 'reconcile') {
            $result = $this->deliver($path, false);
            self::assertSame(1, $result['errors']);
            self::assertSame(0, $result['reconciled']);
        } else {
            try { $this->deliver($path); self::fail('Zero existing payment must fail for manual review.'); }
            catch (RuntimeException $e) { self::assertStringContainsString('manual review', $e->getMessage()); }
        }
        self::assertSame($before, $this->payment(), 'No silent principal repair or fee-field update.');
        self::assertSame($beforeInvoice, $this->invoice());
        self::assertSame(1, $this->paymentCount());
        $activity = $this->pdo->prepare('SELECT COUNT(*) FROM activity_log WHERE event_type="invoice_paid" AND document_id=?');
        $activity->execute([$this->invoiceId]);
        self::assertSame(0, (int)$activity->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public static function invalidMetadata(): iterable
    {
        foreach (['intent', 'checkout', 'reconcile'] as $path) {
            foreach (['0', '', 'not-money', '-527.50', '527.501', '527.51'] as $value) {
                yield "$path $value" => [$path, $value];
            }
        }
    }

    #[DataProvider('invalidMetadata')]
    public function testMalformedMetadataFailsBeforeAnySql(string $path, string $value): void
    {
        $connection = new StripeInvoiceNoSqlConnection();
        $intent = ['id' => 'pi_synthetic_invalid', 'status' => 'succeeded', 'amount_received' => 52750,
            'metadata' => ['invoice_id' => '23', 'original_amount' => $value]];
        if ($path === 'reconcile') {
            $result = stripe_reconcile_payment_intents($connection, new StripeInvoiceFixtureService([$intent]), $GLOBALS['appConfig'], 0);
            self::assertSame(1, $result['errors']);
        } else {
            try {
                if ($path === 'intent') handlePaymentIntentSucceeded($connection, $intent);
                else handleCheckoutSessionCompleted($connection, ['id' => 'cs_synthetic_invalid', 'payment_status' => 'paid', 'amount_total' => 52750, 'metadata' => $intent['metadata']]);
                self::fail('Invalid metadata must hold the event.');
            } catch (InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertSame(0, $connection->calls);
    }

    public function testUnpaidCheckoutStillWaitsWithoutParsingOrWriting(): void
    {
        $connection = new StripeInvoiceNoSqlConnection();
        handleCheckoutSessionCompleted($connection, ['payment_status' => 'unpaid', 'metadata' => ['invoice_id' => '23', 'original_amount' => '']]);
        self::assertSame(0, $connection->calls);
    }

    public function testPaymentIntentPartialCaptureCreditsOnlyReceivedAmount(): void
    {
        $this->fixture();
        $this->intent['amount_received'] = 10000;
        unset($this->intent['metadata']['original_amount'], $this->intent['metadata']['pa_invoice_amount']);
        $this->deliver('intent');
        self::assertSame(100.0, (float)$this->payment()['amount']);
        self::assertSame('partial', $this->invoice()['status']);
        self::assertSame(427.50, (float)$this->invoice()['balance_due']);
    }

    #[DataProvider('paths')]
    public function testExplicitClientSurchargeIsNotCreditedToInvoice(string $path): void
    {
        $this->fixture();
        $metadata = ['invoice_id' => (string)$this->invoiceId, 'original_amount' => '527.50', 'surcharge_amount' => '15.60'];
        $this->intent['metadata'] = $this->session['metadata'] = $metadata;
        $this->intent['amount'] = $this->intent['amount_received'] = $this->session['amount_total'] = 54310;
        $this->intent['latest_charge']['balance_transaction']['amount'] = 54310;
        $this->intent['latest_charge']['balance_transaction']['net'] = 52750;
        $this->deliver($path);
        self::assertSame(527.50, (float)$this->payment()['amount']);
        self::assertSame(15.60, (float)$this->payment()['surcharge_paid']);
        self::assertSame(543.10, (float)$this->payment()['processor_gross_amount']);
        self::assertSame(527.50, (float)$this->invoice()['amount_paid']);
    }

    private function fixture(): void
    {
        // Explicit opt-in only, never the application's normal database config.
        $dsn = getenv('PA_STRIPE_HANDLER_TEST_DSN') ?: '';
        if ($dsn === '') self::markTestSkipped('Set PA_STRIPE_HANDLER_TEST_DSN to a migrated disposable local MySQL database.');
        if (!preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=pa_stripe_[a-z0-9_]+(?:;charset=utf8mb4)?\z/', $dsn)) {
            throw new RuntimeException('Handler tests require a disposable local pa_stripe_* database.');
        }
        $this->pdo = new PDO($dsn, getenv('PA_STRIPE_HANDLER_TEST_USER') ?: 'root', getenv('PA_STRIPE_HANDLER_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec("INSERT INTO organizations (name) VALUES ('Synthetic Stripe Handler Test')");
        $this->organizationId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO clients (name,email,organization_id) VALUES ("Synthetic Stripe Client",?,?)')->execute(['stripe-' . bin2hex(random_bytes(8)) . '@example.invalid', $this->organizationId]);
        $this->clientId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO invoices (client_id,organization_id,invoice_type,status,subtotal,total,amount_paid,balance_due,finalized_at,collection_mode) VALUES (?, ?, "regular", "unpaid",527.50,527.50,0,527.50,NOW(),"direct")')->execute([$this->clientId, $this->organizationId]);
        $this->invoiceId = (int)$this->pdo->lastInsertId();
        invoice_ensure_payments_schema($this->pdo);
        ensure_activity_log_table($this->pdo);
        $suffix = bin2hex(random_bytes(8));
        $metadata = ['invoice_id' => (string)$this->invoiceId, 'pa_invoice_amount' => '527.50', 'original_amount' => '527.50', 'surcharge_amount' => '0'];
        $this->intent = ['object' => 'payment_intent', 'id' => 'pi_synthetic_' . $suffix, 'status' => 'succeeded', 'amount' => 52750, 'amount_received' => 52750, 'metadata' => $metadata,
            'latest_charge' => ['id' => 'ch_synthetic_' . $suffix, 'balance_transaction' => ['id' => 'txn_synthetic_' . $suffix, 'amount' => 52750, 'fee' => 1560, 'net' => 51190]]];
        $this->session = ['object' => 'checkout.session', 'id' => 'cs_synthetic_' . $suffix, 'payment_intent' => $this->intent['id'], 'payment_status' => 'paid', 'amount_total' => 52750, 'metadata' => $metadata];
    }

    private function deliver(string $path, bool $expectSuccess = true): ?array
    {
        if ($path === 'intent') { handlePaymentIntentSucceeded($this->pdo, $this->intent); return null; }
        if ($path === 'checkout') { handleCheckoutSessionCompleted($this->pdo, $this->session); return null; }
        $result = stripe_reconcile_payment_intents($this->pdo, new StripeInvoiceFixtureService([$this->intent]), $GLOBALS['appConfig'], 0);
        if ($expectSuccess) { self::assertSame(0, $result['errors']); self::assertSame(1, $result['reconciled']); }
        return $result;
    }

    private function payment(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE invoice_id=?');
        $stmt->execute([$this->invoiceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    private function invoice(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE id=?');
        $stmt->execute([$this->invoiceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    private function paymentCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id=?');
        $stmt->execute([$this->invoiceId]);
        return (int)$stmt->fetchColumn();
    }
}
