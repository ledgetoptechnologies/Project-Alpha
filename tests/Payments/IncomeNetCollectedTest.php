<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/payment_accounting.php';

/** Income = net collected (payment - processor fees - refunds - disputes), the same in the Overview and the income audit. */
final class IncomeNetCollectedTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite required.');
        }
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('GREATEST', static fn(...$v) => max($v));
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, client_id INT, organization_id INT, doc_number INT, invoice_type TEXT, status TEXT, total REAL, tax_percent REAL, tax_county TEXT, discount_value REAL)');
        $this->pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, client_id INT, invoice_id INT, organization_id INT, amount REAL, refunded_amount REAL DEFAULT 0, disputed_amount REAL DEFAULT 0,
            surcharge_paid REAL DEFAULT 0, surcharge_refunded INT DEFAULT 0, surcharge_refund_amount REAL, processor_gross_amount REAL, processor_fee_amount REAL, processor_net_amount REAL,
            payment_method TEXT, payment_date TEXT, status TEXT)');
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Acme'),(2,'Walk-in')");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,1,0,9001,'regular','paid',300,5,'Dane',0)");
        $cols = '(client_id,invoice_id,amount,refunded_amount,disputed_amount,processor_fee_amount,payment_method,payment_date,status)';
        // Card payment on the invoice: 300 gross, 9 fee, 50 refunded -> 241.
        $this->pdo->exec("INSERT INTO payments {$cols} VALUES (1,10,300,50,0,9,'card','2026-03-02','succeeded')");
        // Second check on the same invoice with a 20 dispute -> 80.
        $this->pdo->exec("INSERT INTO payments {$cols} VALUES (1,10,100,0,20,NULL,'check','2026-03-05','succeeded')");
        // Payment with no invoice is still collected income -> 40 - 1.5 = 38.5.
        $this->pdo->exec("INSERT INTO payments {$cols} VALUES (2,NULL,40,0,0,1.5,'cash','2026-03-09','succeeded')");
        // Failed and out-of-period payments are not income.
        $this->pdo->exec("INSERT INTO payments {$cols} VALUES (1,10,999,0,0,NULL,'card','2026-03-10','failed')");
        $this->pdo->exec("INSERT INTO payments {$cols} VALUES (1,10,999,0,0,NULL,'card','2027-01-01','succeeded')");
    }

    public function testNetIsGrossLessFeesRefundsAndDisputes(): void
    {
        self::assertEqualsWithDelta(241.0, payment_accounting_net_income(['amount' => 300, 'processor_fee_amount' => 9, 'refunded_amount' => 50]), 0.001);
        $rows = payment_accounting_collected_payments($this->pdo, 0, '2026-01-01', '2026-12-31');
        self::assertSame([241.0, 80.0, 38.5], array_map(static fn($r) => round((float)$r['net'], 2), $rows));
        foreach ($rows as $r) {
            self::assertEqualsWithDelta((float)$r['gross'] - (float)$r['fee'] - (float)$r['refunds'], (float)$r['net'], 0.001);
        }
    }

    public function testOverviewAndIncomeAuditAgree(): void
    {
        $payments = payment_accounting_collected_payments($this->pdo, 0, '2026-01-01', '2026-12-31');
        $overview = round(array_sum(array_column($payments, 'net')), 2);
        $audit = payment_accounting_income_audit_rows($payments);

        self::assertSame(359.5, $overview);
        self::assertEqualsWithDelta($overview, array_sum(array_column($audit, 'net')), 0.001);
        self::assertCount(2, $audit, 'One row for the invoice, one for the unlinked payment.');
        self::assertSame(10, (int)$audit[0]['id']);
        self::assertEqualsWithDelta(400.0, $audit[0]['gross'], 0.001);
        self::assertEqualsWithDelta(9.0, $audit[0]['fee'], 0.001);
        self::assertEqualsWithDelta(70.0, $audit[0]['refunds'], 0.001);
        self::assertSame('card, check', $audit[0]['payment_methods']);
        self::assertNull($audit[1]['id']);
        self::assertSame('Walk-in', $audit[1]['client_name']);
    }

    public function testOverviewAndAuditExportsShareTheHelper(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringContainsString('payment_accounting_collected_payments', (string)file_get_contents($root . '/src/views/pages/financial/financial-dashboard.php'));
        foreach (['src/controllers/financial/audit_export.php', 'src/cron/process_audit_schedules.php'] as $path) {
            $src = (string)file_get_contents($root . '/' . $path);
            self::assertStringContainsString('payment_accounting_income_audit_rows(payment_accounting_collected_payments(', $src, $path);
            self::assertStringContainsString("'Gross', 'Processor Fees', 'Refunds/Disputes', 'Net Collected'", $src, $path);
        }
    }
}
