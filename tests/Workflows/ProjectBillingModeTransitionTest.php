<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectBillingModeTransitionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }

        require_once dirname(__DIR__, 2) . '/src/utils/project_billing_transition.php';
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->pdo->exec("INSERT INTO clients (id,name,email,organization_id) VALUES (1,'Client One','client@example.test',10)");
        $this->pdo->exec("INSERT INTO organizations (id,name,general_email) VALUES (10,'Example Co','billing@example.test')");
    }

    public function testConvertToDirectResetsCollectionTermsAndPreservesExternalState(): void
    {
        $this->insertProject('monthly', 10);
        $this->insertInvoice(11, 'draft', 'project_aggregate', 125.00, '2026-08-15', true);
        $this->insertInvoice(12, 'unpaid', 'project_aggregate', 80.00, '2026-08-15');
        $this->pdo->exec("INSERT INTO project_invoices (id,project_id,status,billing_period_start,billing_period_end,total,balance_due) VALUES (4,1,'unpaid','2026-08-01','2026-08-15',80,80)");
        $this->pdo->exec("INSERT INTO project_invoice_items (project_invoice_id,invoice_id,amount_due_at_generation) VALUES (4,12,80)");
        $this->pdo->exec("INSERT INTO public_links (token,document_type,document_id,revoked) VALUES ('keep-token','invoice',11,0)");
        $this->pdo->exec("INSERT INTO public_links (token,document_type,document_id,revoked) VALUES ('keep-statement-token','project_invoice',4,0)");
        $debug = $this->pdo->query('SELECT i.id,i.total,COALESCE(p.paid,0) paid,pii.id item_id FROM invoices i LEFT JOIN (SELECT invoice_id,SUM(CASE WHEN amount-refunded_amount-disputed_amount>0 THEN amount-refunded_amount-disputed_amount ELSE 0 END) paid FROM payments WHERE status="succeeded" GROUP BY invoice_id) p ON p.invoice_id=i.id LEFT JOIN project_invoice_items pii ON pii.invoice_id=i.id WHERE i.project_id=1 AND i.collection_mode="project_aggregate" AND pii.id IS NULL AND i.status NOT IN ("void","cancelled","paid")')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, project_billing_transition_candidates($this->pdo, 1), json_encode($debug));

        $result = project_billing_mode_transition(
            $this->pdo,
            1,
            'monthly',
            'per_invoice',
            'convert_to_direct',
            'review',
            ['net_terms_days' => 30],
            7,
            '2026-09-01'
        );

        self::assertTrue($result['changed']);
        self::assertSame('converted_to_direct', $result['notice_code']);
        self::assertSame(1, $result['converted_count']);
        self::assertSame([11], $result['affected_invoice_ids']);
        self::assertSame(2, $result['preserved_link_count']);
        self::assertSame('per_invoice', $this->pdo->query('SELECT invoice_billing_period FROM projects WHERE id=1')->fetchColumn());

        $converted = $this->pdo->query('SELECT * FROM invoices WHERE id=11')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('direct', $converted['collection_mode']);
        self::assertSame('unpaid', $converted['status']);
        self::assertSame('2026-09-11', $converted['due_date']);
        self::assertSame(10, (int)$converted['payment_terms_days']);
        self::assertSame('terms', $converted['due_date_source']);
        self::assertNotEmpty($converted['finalized_at']);
        self::assertSame('billing_mode_transition', $converted['finalization_source']);
        self::assertSame('stripe-session-11', $converted['stripe_session_id']);
        self::assertSame('2026-09-30 12:00:00', $converted['stripe_checkout_expires_at']);
        self::assertSame('stripe-intent-11', $converted['stripe_payment_intent_id']);
        self::assertSame('keep-token', $this->pdo->query('SELECT token FROM public_links WHERE document_id=11')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT revoked FROM public_links WHERE document_id=11')->fetchColumn());
        self::assertSame('keep-statement-token', $this->pdo->query("SELECT token FROM public_links WHERE document_type='project_invoice' AND document_id=4")->fetchColumn());

        $assigned = $this->pdo->query('SELECT collection_mode,status,due_date FROM invoices WHERE id=12')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['collection_mode' => 'project_aggregate', 'status' => 'unpaid', 'due_date' => '2026-08-15'], $assigned);
        self::assertSame('project.billing_mode.changed', $this->pdo->query('SELECT action FROM system_audit')->fetchColumn());
    }

    public function testFinalStatementCreatesOnePayableClosingStatementAndFinalizesChildrenWithoutRevokingLinks(): void
    {
        $this->insertProject('monthly', null);
        $this->insertInvoice(21, 'draft', 'project_aggregate', 150.00, null);
        $this->insertInvoice(22, 'unpaid', 'project_aggregate', 80.00, '2026-08-20');
        $this->pdo->exec("INSERT INTO project_clients (project_id,client_id,is_primary_billing,sort_order) VALUES (1,1,1,0)");
        $this->pdo->exec("INSERT INTO public_links (token,document_type,document_id,revoked) VALUES ('child-link','invoice',21,0)");

        $result = project_billing_mode_transition(
            $this->pdo,
            1,
            'monthly',
            'per_invoice',
            'final_project_statement',
            'review',
            ['net_terms_days' => 30],
            7,
            '2026-09-01'
        );

        self::assertSame('final_statement_created', $result['notice_code']);
        self::assertSame(2, $result['included_count']);
        self::assertSame(1, $result['preserved_link_count']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM project_invoices')->fetchColumn());
        $statement = $this->pdo->query('SELECT * FROM project_invoices')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('unpaid', $statement['status']);
        self::assertSame('2026-09-01', $statement['billing_period_start']);
        self::assertSame('2026-09-01', $statement['billing_period_end']);
        self::assertSame('2026-10-01', $statement['due_date']);
        self::assertNotEmpty($statement['finalized_at']);
        self::assertSame('billing_mode_transition', $statement['finalization_source']);
        self::assertSame('Closing statement through Sep 1, 2026', project_invoice_period_label($statement));

        $child = $this->pdo->query('SELECT * FROM invoices WHERE id=21')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('project_aggregate', $child['collection_mode']);
        self::assertSame('unpaid', $child['status']);
        self::assertNull($child['due_date']);
        self::assertSame(30, (int)$child['payment_terms_days']);
        self::assertNotEmpty($child['finalized_at']);
        self::assertSame(0, (int)$this->pdo->query('SELECT revoked FROM public_links WHERE document_id=21')->fetchColumn());
        self::assertSame(
            [null, '2026-08-20'],
            $this->pdo->query('SELECT invoice_due_date FROM project_invoice_items ORDER BY invoice_id')->fetchAll(PDO::FETCH_COLUMN)
        );
        $legacyUnfinalized = $this->pdo->query('SELECT status,due_date,finalized_at FROM invoices WHERE id=22')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('unpaid', $legacyUnfinalized['status']);
        self::assertSame('2026-08-20', $legacyUnfinalized['due_date']);
        self::assertNotEmpty($legacyUnfinalized['finalized_at']);
    }

    public function testFinalStatementRejectsAnyUnresolvedChildCheckoutEvenPastLocalExpiry(): void
    {
        $this->insertProject('monthly', null);
        $this->insertInvoice(24, 'unpaid', 'project_aggregate', 60.00, '2026-09-10', true);
        $this->pdo->exec("UPDATE invoices SET stripe_checkout_expires_at='2020-01-01 00:00:00' WHERE id=24");

        try {
            project_billing_mode_transition(
                $this->pdo, 1, 'monthly', 'per_invoice', 'final_project_statement', 'review',
                ['net_terms_days' => 30], 7, '2026-09-01'
            );
            self::fail('Expected the active child checkout to block statement creation.');
        } catch (DomainException $error) {
            self::assertStringContainsString('unresolved card checkout', $error->getMessage());
        }

        self::assertSame('monthly', $this->pdo->query('SELECT invoice_billing_period FROM projects')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM project_invoices')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT finalized_at FROM invoices WHERE id=24')->fetchColumn());
    }

    public function testEmptyTransitionDoesNotCreateStatement(): void
    {
        $this->insertProject('monthly', null);
        $this->insertInvoice(31, 'paid', 'project_aggregate', 0.00, null);

        $result = project_billing_mode_transition(
            $this->pdo,
            1,
            'monthly',
            'per_invoice',
            'final_project_statement',
            'review',
            ['net_terms_days' => 30],
            null,
            '2026-09-01'
        );

        self::assertSame('no_pending_monthly', $result['notice_code']);
        self::assertSame(0, $result['candidate_count']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM project_invoices')->fetchColumn());
        self::assertSame('per_invoice', $this->pdo->query('SELECT invoice_billing_period FROM projects')->fetchColumn());
    }

    public function testSendAllPreflightFailureRollsBackModeAndInvoices(): void
    {
        $this->insertProject('monthly', null);
        $this->insertInvoice(41, 'draft', 'project_aggregate', 50.00, null);
        $this->pdo->exec("UPDATE clients SET email='not-an-email' WHERE id=1");

        try {
            project_billing_mode_transition(
                $this->pdo,
                1,
                'monthly',
                'per_invoice',
                'convert_to_direct',
                'send_all',
                ['net_terms_days' => 30],
                null,
                '2026-09-01'
            );
            self::fail('Expected recipient preflight to reject the transition.');
        } catch (DomainException $error) {
            self::assertStringContainsString('saved client email', $error->getMessage());
        }

        self::assertSame('monthly', $this->pdo->query('SELECT invoice_billing_period FROM projects')->fetchColumn());
        $invoice = $this->pdo->query('SELECT status,collection_mode,finalized_at FROM invoices WHERE id=41')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['status' => 'draft', 'collection_mode' => 'project_aggregate', 'finalized_at' => null], $invoice);
    }

    public function testSameModePerInvoiceCanRecoverStrandedAggregateInvoices(): void
    {
        $this->insertProject('per_invoice', 5);
        $this->insertInvoice(51, 'unpaid', 'project_aggregate', 70.00, '2026-08-01');

        $result = project_billing_mode_transition(
            $this->pdo,
            1,
            'per_invoice',
            'per_invoice',
            'convert_to_direct',
            'review',
            ['net_terms_days' => 30],
            9,
            '2026-09-01'
        );

        self::assertFalse($result['changed']);
        self::assertSame(1, $result['converted_count']);
        self::assertSame('direct', $this->pdo->query('SELECT collection_mode FROM invoices WHERE id=51')->fetchColumn());
        self::assertSame('2026-09-06', $this->pdo->query('SELECT due_date FROM invoices WHERE id=51')->fetchColumn());
        self::assertSame('project.billing_mode.recovered', $this->pdo->query('SELECT action FROM system_audit')->fetchColumn());

        $second = project_billing_mode_transition(
            $this->pdo,
            1,
            'per_invoice',
            'per_invoice',
            'convert_to_direct',
            'review',
            ['net_terms_days' => 30],
            9,
            '2026-09-01'
        );
        self::assertSame('no_stranded_monthly', $second['notice_code']);
        self::assertSame(0, $second['candidate_count']);
    }

    public function testSwitchingToMonthlyLeavesHistoricalDirectInvoicesUntouched(): void
    {
        $this->insertProject('per_invoice', null);
        $this->insertInvoice(61, 'unpaid', 'direct', 90.00, '2026-09-10');

        $result = project_billing_mode_transition(
            $this->pdo,
            1,
            'per_invoice',
            'monthly',
            null,
            'review',
            ['net_terms_days' => 30],
            null,
            '2026-09-01'
        );

        self::assertSame('monthly_enabled', $result['notice_code']);
        self::assertSame('monthly', $this->pdo->query('SELECT invoice_billing_period FROM projects')->fetchColumn());
        self::assertSame('direct', $this->pdo->query('SELECT collection_mode FROM invoices WHERE id=61')->fetchColumn());
        self::assertSame('2026-09-10', $this->pdo->query('SELECT due_date FROM invoices WHERE id=61')->fetchColumn());
    }

    public function testProjectInvoiceNumbersUseTheSerializedGlobalSequence(): void
    {
        $this->pdo->beginTransaction();
        self::assertSame(1, project_invoice_next_doc_number($this->pdo));
        self::assertSame(2, project_invoice_next_doc_number($this->pdo));
        $this->pdo->commit();
        self::assertSame(
            3,
            (int)$this->pdo->query(
                "SELECT next_number FROM document_number_sequences
                 WHERE document_type='project_invoice' AND document_subtype='standard'"
            )->fetchColumn()
        );
    }

    public function testProjectUpdateAppliesTransitionBeforeCommitAndDeliversAfterCommit(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/project/projects_update.php');
        $transitionCall = strpos($controller, 'project_billing_mode_transition(');
        $commit = strrpos($controller, '$pdo->commit();');
        $deliveryCall = strpos($controller, 'project_billing_transition_deliver(');

        self::assertNotFalse($transitionCall);
        self::assertNotFalse($commit);
        self::assertNotFalse($deliveryCall);
        self::assertLessThan($commit, $transitionCall, 'The accounting transition must run inside the project update transaction.');
        self::assertLessThan($deliveryCall, $commit, 'Email transport must run only after the accounting transaction commits.');
        self::assertStringContainsString('$lockedInvoiceBillingPeriod', $controller);
        self::assertStringContainsString('$postedOriginalBillingPeriod !== $lockedInvoiceBillingPeriod', $controller);
        self::assertStringContainsString("user_can(\$pdo, \$actorId, 'invoices.send', 0)", $controller);
        self::assertStringContainsString('$monthlyAutoEmailConfirmed', $controller);
        $normalizedController = str_replace("\r\n", "\n", $controller);
        self::assertStringContainsString(
            "'per_invoice'\n\t\t&& \$invoiceBillingPeriod === 'per_invoice'",
            $normalizedController
        );
    }

    public function testLateChildStripeCheckoutRoutesToItsAssignedProjectInvoice(): void
    {
        $webhook = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/webhook/stripe_checkout_completed.php');
        self::assertStringContainsString('pii.project_invoice_id AS assigned_project_invoice_id', $webhook);
        self::assertStringContainsString("'pa_project_invoice_id' => (string)\$assignedProjectInvoiceId", $webhook);
        self::assertStringContainsString('project_invoice_record_stripe_payment($pdo, $projectSession)', $webhook);

        $billing = (string)file_get_contents(dirname(__DIR__, 2) . '/src/utils/project_invoice_billing.php');
        self::assertStringNotContainsString(
            'UPDATE public_links SET revoked=1 WHERE document_type="invoice" AND document_id=? AND revoked=0',
            $billing
        );
        self::assertStringContainsString('project_invoice_next_doc_number($pdo)', $billing);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, client_id INTEGER, organization_id INTEGER, invoice_billing_period TEXT, invoice_net_terms_days INTEGER)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT, organization_id INTEGER, archived INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT, general_email TEXT)');
        $this->pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY, client_id INTEGER, project_id INTEGER, quote_id INTEGER, contract_id INTEGER,
            job_id INTEGER, doc_number INTEGER, status TEXT,
            total REAL, amount_paid REAL DEFAULT 0, balance_due REAL DEFAULT 0, due_date TEXT,
            payment_terms_days INTEGER, due_date_source TEXT, document_date TEXT, fulfillment_date TEXT, created_at TEXT,
            recipient_presentation_mode TEXT DEFAULT "named", collection_mode TEXT, finalized_at TEXT,
            finalized_by INTEGER, finalization_source TEXT, stripe_session_id TEXT,
            stripe_checkout_expires_at TEXT, stripe_payment_intent_id TEXT
        )');
        $this->pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, amount REAL, refunded_amount REAL DEFAULT 0, disputed_amount REAL DEFAULT 0, status TEXT)');
        $this->pdo->exec('CREATE TABLE project_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, organization_id INTEGER, primary_client_id INTEGER,
            doc_number INTEGER, status TEXT, billing_period_start TEXT, billing_period_end TEXT, due_date TEXT,
            subtotal REAL DEFAULT 0, total REAL DEFAULT 0, amount_paid REAL DEFAULT 0, balance_due REAL DEFAULT 0,
            sent_at TEXT, finalized_at TEXT, finalization_source TEXT, stripe_session_id TEXT,
            stripe_checkout_expires_at TEXT, paid_at TEXT, created_by INTEGER,
            UNIQUE(project_id,billing_period_start,billing_period_end)
        )');
        $this->pdo->exec('CREATE TABLE project_invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_invoice_id INTEGER, invoice_id INTEGER UNIQUE,
            invoice_doc_number INTEGER, invoice_date TEXT, invoice_due_date TEXT, invoice_status TEXT,
            invoice_total REAL DEFAULT 0, amount_paid_at_generation REAL DEFAULT 0,
            amount_due_at_generation REAL DEFAULT 0
        )');
        $this->pdo->exec('CREATE TABLE project_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, client_id INTEGER, is_primary_billing INTEGER, sort_order INTEGER)');
        $this->pdo->exec('CREATE TABLE project_invoice_recipients (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, client_id INTEGER, organization_id INTEGER, manual_email TEXT, manual_name TEXT, recipient_key TEXT, sort_order INTEGER)');
        $this->pdo->exec('CREATE TABLE public_links (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT, document_type TEXT, document_id INTEGER, expires_at TEXT, expire_when_paid INTEGER DEFAULT 0, revoked INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE system_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT, user_agent TEXT)');
        $this->pdo->exec('CREATE TABLE document_number_sequences (document_type TEXT, document_subtype TEXT, next_number INTEGER, PRIMARY KEY(document_type,document_subtype))');
    }

    private function insertProject(string $mode, ?int $netDays): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO projects (id,client_id,organization_id,invoice_billing_period,invoice_net_terms_days) VALUES (1,1,10,?,?)');
        $stmt->execute([$mode, $netDays]);
    }

    private function insertInvoice(int $id, string $status, string $collectionMode, float $total, ?string $dueDate, bool $withStripeSession = false): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices
                (id,client_id,project_id,doc_number,status,total,amount_paid,balance_due,due_date,payment_terms_days,
                 due_date_source,document_date,created_at,recipient_presentation_mode,collection_mode,
                 stripe_session_id,stripe_checkout_expires_at,stripe_payment_intent_id)
             VALUES (?,?,?,?,?,?,?,?,?,30,"manual","2026-08-15","2026-08-15 12:00:00","named",?,
                     ?,"2026-09-30 12:00:00",?)'
        );
        $stmt->execute([
            $id,
            1,
            1,
            $id,
            $status,
            $total,
            $status === 'paid' ? $total : 0,
            $status === 'paid' ? 0 : $total,
            $dueDate,
            $collectionMode,
            $withStripeSession ? 'stripe-session-' . $id : null,
            $withStripeSession ? 'stripe-intent-' . $id : null,
        ]);
    }
}
