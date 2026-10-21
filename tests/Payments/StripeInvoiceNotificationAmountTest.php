<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/notifications.php';
require_once dirname(__DIR__, 2) . '/src/utils/stripe_invoice_payment_amounts.php';

use PHPUnit\Framework\TestCase;

final class StripeInvoiceNotificationAmountTest extends TestCase
{
    public function testSettledInvoicePrincipalAppearsInSubjectAndBody(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('SQLite PDO is unavailable.');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, client_id INTEGER, doc_number INTEGER,
            invoice_type TEXT, project_code TEXT, total REAL, amount_paid REAL, balance_due REAL, created_by INTEGER)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT, is_disabled INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE user_notification_preferences (user_id INTEGER, notify_processor_invoice_paid INTEGER)');
        $pdo->exec("INSERT INTO clients VALUES (1,'Synthetic Client')");
        $pdo->exec("INSERT INTO invoices VALUES (23,1,23,'regular','TEST-23',527.50,527.50,0,1)");
        $pdo->exec("INSERT INTO users VALUES (1,'owner@example.test','owner',0,NULL)");

        $amounts = stripe_invoice_payment_amounts([
            'amount' => 52750, 'amount_received' => 52750,
            'metadata' => ['original_amount' => '527.50', 'surcharge_amount' => '0'],
            'latest_charge' => ['balance_transaction' => ['fee' => 1560, 'net' => 51190]],
        ], 'payment_intent');
        $sent = [];
        self::assertTrue(notify_admin_invoice_paid(
            $pdo, ['brand_name' => 'Test Business'], 23, $amounts['payment_amount'], 'paid', false, true,
            static function (string $to, string $subject, string $body, array $options) use (&$sent): array {
                $sent[] = compact('to', 'subject', 'body', 'options');
                return [true, 'captured by test'];
            }, 'payment:100'
        ));
        self::assertCount(1, $sent);
        self::assertSame('[Test Business] Invoice I-23 paid in full ($527.50)', $sent[0]['subject']);
        self::assertStringContainsString('Payment amount: <strong>$527.50</strong>', $sent[0]['body']);
        self::assertStringContainsString('Invoice total: <strong>$527.50</strong>', $sent[0]['body']);
        self::assertStringNotContainsString('$511.90', $sent[0]['body']);
        self::assertStringNotContainsString('$0.00', $sent[0]['body']);
    }
}
