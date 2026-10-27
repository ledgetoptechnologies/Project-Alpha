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

    private function configuredBillingFixture(): void
    {
        $this->pdo->exec("ALTER TABLE clients ADD COLUMN organization_id INTEGER");
        $this->pdo->exec("ALTER TABLE invoices ADD COLUMN organization_id INTEGER");
        $this->pdo->exec('CREATE TABLE organization_billing_preferences (organization_id INTEGER PRIMARY KEY,copy_working_contact INTEGER)');
        $this->pdo->exec('CREATE TABLE organization_billing_recipients (organization_id INTEGER,client_id INTEGER,PRIMARY KEY(organization_id,client_id))');
        $this->pdo->exec('CREATE TABLE payment_receipt_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT,receipt_id INTEGER,recipient_key TEXT,email_to TEXT,recipient_name TEXT,emailed_at TEXT,suppressed_at TEXT,UNIQUE(receipt_id,recipient_key))');
        $this->pdo->exec("INSERT INTO organizations VALUES (9,'Company','general@example.test')");
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Working Contact','working@example.test',0,9),(2,'Accounting One','one@example.test',0,9),(3,'Accounting Two','two@example.test',0,9)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1,9)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,NULL,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");
        pa_billing_organization_save($this->pdo, 9, [2,3], false);
    }

    public function testConfiguredReceiptRetriesOnlyFailedRecipientAndKeepsOneFinancialReceipt(): void
    {
        $this->configuredBillingFixture();
        $attempts = [];
        try {
            payment_receipt_issue($this->pdo,100,[],true,static function(string $to,string $subject,string $body,array $options) use (&$attempts):array {
                $attempts[] = compact('to','body','options');
                return [$to !== 'one@example.test','synthetic failure'];
            },true);
            self::fail('The failed accounting recipient must remain retryable.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('synthetic failure',$error->getMessage());
        }
        self::assertSame(['one@example.test','two@example.test'],array_column($attempts,'to'));
        self::assertStringContainsString('Hello Accounting Two',$attempts[1]['body']);
        self::assertNull($this->pdo->query('SELECT emailed_at FROM payment_receipts')->fetchColumn());
        $retry = [];
        $receipt = payment_receipt_issue($this->pdo,100,[],true,static function(string $to,string $subject,string $body,array $options) use (&$retry):array {
            $retry[] = ['to'=>$to,'key'=>$options['message_key']];return [true,''];
        },true);
        self::assertSame([['to'=>'one@example.test','key'=>$attempts[0]['options']['message_key']]],$retry);
        self::assertNotEmpty($receipt['emailed_at']);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipts')->fetchColumn());
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipt_deliveries WHERE emailed_at IS NOT NULL')->fetchColumn());
    }

    public function testRemovedBillingRecipientsNeverFallBackToWorkingContact(): void
    {
        $this->configuredBillingFixture();
        $this->pdo->exec('UPDATE clients SET archived=1 WHERE id IN (2,3)');
        $attempts = [];
        try {
            payment_receipt_issue($this->pdo,100,[],true,static function(string $to) use (&$attempts):array {$attempts[]=$to;return [true,''];},true);
            self::fail('Missing configured accounting recipients must surface an actionable error.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('billing recipients',$error->getMessage());
        }
        self::assertSame([],$attempts);
    }

    public function testPreviouslyEmailedLegacyReceiptIsNotResentAfterPolicyOptIn(): void
    {
        $this->configuredBillingFixture();
        $this->pdo->exec("INSERT INTO payment_receipts(payment_id,invoice_id,receipt_number,public_token,amount,email_to,emailed_at) VALUES(100,10,'R-100','historical',125.50,'working@example.test','2026-08-18 10:00:00')");
        $attempts=[];
        payment_receipt_issue($this->pdo,100,[],true,static function(string $to) use (&$attempts):array {$attempts[]=$to;return [true,''];},true);
        self::assertSame([],$attempts);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipt_deliveries')->fetchColumn());
    }

    public function testAutoPayPayerGetsReceiptButAccountingNeverGetsManagementLink(): void
    {
        $this->configuredBillingFixture();
        $sent=[];
        payment_receipt_issue($this->pdo,100,['_autopay_manage_url'=>'https://example.test/payer-management','_autopay_manage_email'=>'working@example.test'],true,
            static function(string $to,string $subject,string $body) use (&$sent):array {$sent[$to]=$body;return [true,''];},true);
        self::assertSame(['one@example.test','two@example.test','working@example.test'],array_keys($sent));
        self::assertStringNotContainsString('payer-management',$sent['one@example.test']);
        self::assertStringNotContainsString('payer-management',$sent['two@example.test']);
        self::assertStringContainsString('payer-management',$sent['working@example.test']);
    }

    public function testInvoiceQueueFansOutOnceAndDoesNotRetargetRemovedRecipients(): void
    {
        $this->configuredBillingFixture();
        $this->pdo->exec('CREATE TABLE invoice_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,invoice_id INTEGER,notification_type TEXT,delivery_key TEXT,recipient_key TEXT,delivery_status TEXT,attempt_count INTEGER,next_attempt_at TEXT,last_error TEXT,email_to TEXT,claimed_at TEXT,last_attempt_at TEXT,UNIQUE(invoice_id,notification_type,delivery_key,recipient_key))');
        $first=invoice_notification_enqueue_resolved($this->pdo,10,'on_finalize','finalized');
        self::assertSame(['queued'=>2,'suppressed'=>0],$first);
        self::assertSame(['queued'=>0,'suppressed'=>0],invoice_notification_enqueue_resolved($this->pdo,10,'on_finalize','finalized'));
        self::assertSame(['one@example.test','two@example.test'],$this->pdo->query('SELECT email_to FROM invoice_notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $this->pdo->exec('UPDATE clients SET archived=1 WHERE id IN (2,3)');
        self::assertSame(['queued'=>0,'suppressed'=>1],invoice_notification_enqueue_resolved($this->pdo,10,'due_7','due:2026-08-19'));
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM invoice_notifications WHERE email_to='working@example.test'")->fetchColumn());
        self::assertSame(['one@example.test','two@example.test'],$this->pdo->query('SELECT email_to FROM invoice_notifications WHERE delivery_status="pending" ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        foreach (['status TEXT','finalized_at TEXT','collection_mode TEXT','total REAL','amount_paid REAL','due_date TEXT','payment_terms_days INTEGER','due_date_source TEXT'] as $column) $this->pdo->exec('ALTER TABLE invoices ADD COLUMN ' . $column);
        $this->pdo->exec("UPDATE invoices SET status='unpaid',finalized_at='2026-08-18',collection_mode='direct',total=125.50,amount_paid=0");
        $sent=[];
        $result=invoice_notification_process($this->pdo,[],static function(string $to) use (&$sent):array {$sent[]=$to;return [true,''];},new DateTimeImmutable('+1 minute'));
        self::assertSame(2,$result['suppressed']);
        self::assertSame([],$sent);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_notifications WHERE delivery_status="pending"')->fetchColumn());
    }

    public function testConfiguredProjectRecipientsAreRevalidatedAgainstOrganizationMembership(): void
    {
        $this->configuredBillingFixture();
        $this->pdo->exec('ALTER TABLE projects ADD COLUMN organization_id INTEGER');
        $this->pdo->exec("INSERT INTO projects VALUES (5,'Services',1,9)");
        $this->pdo->exec("INSERT INTO project_invoices VALUES (20,5,1,77,'unpaid',125.50)");
        $this->pdo->exec("INSERT INTO project_invoice_recipients VALUES (1,5,2,NULL,NULL,NULL,'client:2',0),(2,5,3,NULL,NULL,NULL,'client:3',1)");
        self::assertSame(['one@example.test','two@example.test'],array_column(project_invoice_client_recipients($this->pdo,20),'email'));
        $this->pdo->exec('UPDATE clients SET organization_id=99 WHERE id=2');
        self::assertSame(['two@example.test'],array_column(project_invoice_client_recipients($this->pdo,20),'email'));
        $this->pdo->exec('UPDATE clients SET archived=1 WHERE id=3');
        self::assertSame([],project_invoice_client_recipients($this->pdo,20));
    }

    public function testCompletedConfiguredReceiptNeverGainsRecipientsAfterPolicyChange(): void
    {
        $this->configuredBillingFixture();
        payment_receipt_issue($this->pdo,100,[],true,static fn():array=>[true,''],true);
        $this->pdo->exec("INSERT INTO clients VALUES(4,'New Accounting','new@example.test',0,9)");
        pa_billing_organization_save($this->pdo,9,[2,3,4],false);
        $sent=[];
        payment_receipt_issue($this->pdo,100,[],true,static function(string $to) use (&$sent):array {$sent[]=$to;return [true,''];},true);
        self::assertSame([],$sent);
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipt_deliveries')->fetchColumn());
    }

    public function testPartialReceiptFreezesOriginalRecipientsAndSuppressesRemovedContact(): void
    {
        $this->configuredBillingFixture();
        $snapshotCount=0;
        try {
            payment_receipt_issue($this->pdo,100,[],true,function(string $to) use (&$snapshotCount):array {
                $snapshotCount=(int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipt_deliveries')->fetchColumn();
                return [$to==='two@example.test','synthetic first-recipient failure'];
            },true);
            self::fail('First recipient must be retried.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('first-recipient failure',$error->getMessage());
        }
        self::assertSame(2,$snapshotCount,'All recipients must be saved before any email is sent.');
        $this->pdo->exec("INSERT INTO clients VALUES(4,'New Accounting','new@example.test',0,9)");
        pa_billing_organization_save($this->pdo,9,[3,4],false);
        $sent=[];
        $receipt=payment_receipt_issue($this->pdo,100,[],true,static function(string $to) use (&$sent):array {$sent[]=$to;return [true,''];},true);
        self::assertSame([],$sent,'Neither removed contact nor new contact may receive the historical receipt.');
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipt_deliveries')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM payment_receipt_deliveries WHERE suppressed_at IS NOT NULL')->fetchColumn());
        self::assertNotEmpty($receipt['emailed_at']);
    }
}
