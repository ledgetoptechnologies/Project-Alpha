<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/payment_receipts.php';
require_once dirname(__DIR__, 2) . '/src/utils/stripe_payment_notifications.php';

use PHPUnit\Framework\TestCase;

final class PaymentEmailDeliveryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The SQLite PDO driver is unavailable.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-08-18 12:00:00');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT, archived INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY, doc_number INTEGER, invoice_type TEXT,
            recipient_presentation_mode TEXT, client_id INTEGER
        )');
        $this->pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, job_code TEXT)');
        $this->pdo->exec('CREATE TABLE processor_payment_transactions (
            id INTEGER PRIMARY KEY, payment_id INTEGER, payer_name TEXT, payer_email TEXT
        )');
        $this->pdo->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY, invoice_id INTEGER, job_id INTEGER, processor_transaction_id INTEGER,
            client_id INTEGER, amount REAL, payment_date TEXT, payment_method TEXT,
            reference_number TEXT, status TEXT
        )');
        $this->pdo->exec('CREATE TABLE payment_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER UNIQUE, invoice_id INTEGER,
            receipt_number TEXT UNIQUE, public_token TEXT UNIQUE, amount REAL, email_to TEXT,
            expires_at TEXT, revoked INTEGER NOT NULL DEFAULT 0,
            emailed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, client_id INTEGER)');
        $this->pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT, general_email TEXT)');
        $this->pdo->exec('CREATE TABLE project_invoices (
            id INTEGER PRIMARY KEY, project_id INTEGER, primary_client_id INTEGER,
            doc_number INTEGER, status TEXT, total REAL
        )');
        $this->pdo->exec('CREATE TABLE project_invoice_payments (
            id INTEGER PRIMARY KEY, project_invoice_id INTEGER, amount REAL,
            payment_date TEXT, status TEXT
        )');
        $this->pdo->exec('CREATE TABLE project_clients (
            project_id INTEGER, client_id INTEGER, is_primary_billing INTEGER, sort_order INTEGER
        )');
        $this->pdo->exec('CREATE TABLE project_invoice_recipients (
            id INTEGER PRIMARY KEY, project_id INTEGER, client_id INTEGER, organization_id INTEGER, manual_name TEXT,
            manual_email TEXT, recipient_key TEXT, sort_order INTEGER
        )');
    }

    public function testDirectReceiptUsesStableKeyAndStrictFailureCanRetry(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Client Contact','client@example.test',0)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,NULL,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");

        $failedOptions = [];
        try {
            payment_receipt_issue(
                $this->pdo,
                100,
                ['payment_receipts_enabled' => 1],
                true,
                static function (string $to, string $subject, string $body, array $options) use (&$failedOptions): array {
                    $failedOptions = $options;
                    return [false, 'temporary transport failure'];
                },
                true
            );
            self::fail('Strict webhook delivery should surface a failed receipt email.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('temporary transport failure', $error->getMessage());
        }

        self::assertStringStartsWith('payment-receipt:100:', $failedOptions['message_key']);
        self::assertNull($this->pdo->query('SELECT emailed_at FROM payment_receipts WHERE payment_id=100')->fetchColumn());
        $access = $this->pdo->prepare('SELECT id FROM payment_receipts WHERE public_token=? AND revoked=0 AND expires_at>?');
        $token = (string)$this->pdo->query('SELECT public_token FROM payment_receipts WHERE payment_id=100')->fetchColumn();
        $access->execute([$token, date('Y-m-d H:i:s')]);
        self::assertNotFalse($access->fetchColumn());
        $access->execute([$token, (new DateTimeImmutable('now'))->modify('+2 years')->format('Y-m-d H:i:s')]);
        self::assertFalse($access->fetchColumn());
        $this->pdo->exec('UPDATE payment_receipts SET revoked=1 WHERE payment_id=100');
        $access->execute([$token, date('Y-m-d H:i:s')]);
        self::assertFalse($access->fetchColumn());
        $this->pdo->exec('UPDATE payment_receipts SET revoked=0 WHERE payment_id=100');
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/public_view/payment_receipt.php');
        self::assertStringContainsString('r.revoked=0 AND r.expires_at>?', $controller);

        $retryOptions = [];
        $receipt = payment_receipt_issue(
            $this->pdo,
            100,
            ['payment_receipts_enabled' => 1],
            true,
            static function (string $to, string $subject, string $body, array $options) use (&$retryOptions): array {
                $retryOptions = $options;
                return [true, 'sent'];
            },
            true
        );
        self::assertNotNull($receipt);
        self::assertSame($failedOptions['message_key'], $retryOptions['message_key']);
        self::assertSame('2026-08-18 12:00:00', $this->pdo->query('SELECT emailed_at FROM payment_receipts WHERE payment_id=100')->fetchColumn());
    }

    public function testAggregateProjectPaymentEmailsOneReceiptToEachInvoiceRecipient(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES
            (1,'Primary Contact','primary@example.test',0),
            (2,'Billing Contact','billing@example.test',0)");
        $this->pdo->exec("INSERT INTO organizations VALUES (9,'Client Company','accounts@example.test')");
        $this->pdo->exec("INSERT INTO projects VALUES (5,'Monthly Services',1)");
        $this->pdo->exec("INSERT INTO project_invoices VALUES (20,5,1,77,'paid',500.00)");
        $this->pdo->exec("INSERT INTO project_invoice_payments VALUES (200,20,500.00,'2026-08-18','succeeded')");
        $this->pdo->exec('INSERT INTO project_clients VALUES (5,1,1,0),(5,2,0,1)');
        $this->pdo->exec("INSERT INTO project_invoice_recipients VALUES
            (1,5,1,NULL,NULL,NULL,'client:1',0),
            (2,5,2,NULL,NULL,NULL,'client:2',1),
            (3,5,NULL,9,NULL,NULL,'organization:9',2)");

        $sent = [];
        $count = project_payment_receipt_email_issue(
            $this->pdo,
            200,
            ['payment_receipts_enabled' => 1],
            static function (string $to, string $subject, string $body, array $options) use (&$sent): array {
                $sent[] = compact('to', 'subject', 'body', 'options');
                return [true, 'sent'];
            },
            true
        );

        self::assertSame(3, $count);
        self::assertSame(
            ['primary@example.test', 'billing@example.test', 'accounts@example.test'],
            array_column($sent, 'to')
        );
        self::assertStringContainsString('Client Company (Company email)', $sent[2]['body']);
        self::assertStringContainsString('project invoice PI-77', $sent[0]['subject']);
        self::assertStringContainsString('$500.00', $sent[0]['body']);
        self::assertStringStartsWith('project-payment-receipt:200:', $sent[0]['options']['message_key']);
        self::assertNotSame($sent[0]['options']['message_key'], $sent[1]['options']['message_key']);
    }

    public function testBlankClientEmailFallsBackToProcessorPayerEmail(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES (1,'','',0)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,9,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");
        $this->pdo->exec("INSERT INTO processor_payment_transactions
            (id,payment_id,payer_name,payer_email) VALUES (9,100,'Card Payer','payer@example.test')");

        $sent = [];
        payment_receipt_issue(
            $this->pdo,
            100,
            ['payment_receipts_enabled' => 1],
            true,
            static function (string $to, string $subject, string $body, array $options) use (&$sent): array {
                $sent[] = compact('to', 'subject', 'body', 'options');
                return [true, 'sent'];
            },
            true
        );

        self::assertCount(1, $sent);
        self::assertSame('payer@example.test', $sent[0]['to']);
        self::assertStringContainsString('Hello Card Payer', $sent[0]['body']);
        self::assertSame('payer@example.test', $this->pdo->query(
            'SELECT email_to FROM payment_receipts WHERE payment_id=100'
        )->fetchColumn());
    }

    public function testSettlementDeliveryRetriesAdminWithoutResendingSuccessfulReceipt(): void
    {
        $this->pdo->exec("ALTER TABLE invoices ADD COLUMN created_by INTEGER");
        $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT, is_disabled INTEGER, deleted_at TEXT)");
        $this->pdo->exec("CREATE TABLE user_notification_preferences (user_id INTEGER, notify_processor_invoice_paid INTEGER)");
        $this->pdo->exec("INSERT INTO users VALUES (1,'owner@example.test','owner',0,NULL)");
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Client Contact','client@example.test',0)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1,1)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,NULL,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");
        $attempts = [];
        $sender = static function (string $to, string $subject, string $body, array $options) use (&$attempts): array {
            $attempts[] = ['to' => $to, 'key' => $options['message_key']];
            return [$to !== 'owner@example.test', 'synthetic admin failure'];
        };
        try {
            stripe_invoice_deliver_payment_emails($this->pdo, ['payment_receipts_enabled' => 1], 10, 100, 125.50, 'paid', false, $sender);
            self::fail('Failed admin delivery must remain retryable.');
        } catch (RuntimeException $error) {
            self::assertStringStartsWith('Payment email delivery failed:', $error->getMessage());
        }
        self::assertSame(['owner@example.test', 'client@example.test'], array_column($attempts, 'to'));
        self::assertSame('invoice-paid-processor:10:' . hash('sha256', 'owner@example.test:payment:100'), $attempts[0]['key']);
        self::assertSame('2026-08-18 12:00:00', $this->pdo->query('SELECT emailed_at FROM payment_receipts WHERE payment_id=100')->fetchColumn());
        $retry = [];
        stripe_invoice_deliver_payment_emails(
            $this->pdo, ['payment_receipts_enabled' => 1], 10, 100, 125.50, 'paid', false,
            static function (string $to, string $subject, string $body, array $options) use (&$retry): array {
                $retry[] = ['to' => $to, 'key' => $options['message_key']];
                return [true, 'sent'];
            }
        );
        self::assertSame([$attempts[0]], $retry);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipts')->fetchColumn());
    }

    public function testCustomerReceiptAttemptIsNotBlockedByAdminDeliveryFailure(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Client Contact','client@example.test',0)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,NULL,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");
        $attempts = [];
        try {
            payment_email_attempt_all(
                static function () use (&$attempts): void {
                    $attempts[] = 'admin';
                    throw new RuntimeException('admin transport unavailable');
                },
                function () use (&$attempts): void {
                    $attempts[] = 'customer_receipt';
                    payment_receipt_issue(
                        $this->pdo,
                        100,
                        ['payment_receipts_enabled' => 1],
                        true,
                        static fn(): array => [true, 'sent'],
                        true
                    );
                }
            );
            self::fail('The combined delivery should report the admin failure after attempting the receipt.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('admin transport unavailable', $error->getMessage());
        }
        self::assertSame(['admin', 'customer_receipt'], $attempts);
        self::assertSame(
            '2026-08-18 12:00:00',
            $this->pdo->query('SELECT emailed_at FROM payment_receipts WHERE payment_id=100')->fetchColumn()
        );

    }
}
