<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/invoice_notification_queue.php';

use PHPUnit\Framework\TestCase;

final class InvoiceNotificationQueueTest extends TestCase
{
    public function testBothQueuesPreserveClaimRetryReclaimAndTerminalBehavior(): void
    {
        foreach (['invoice', 'project_invoice'] as $type) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            [$table, $column] = invoice_notification_queue_definition($type);
            $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, $column INTEGER, delivery_status TEXT,
                next_attempt_at TEXT, claimed_at TEXT, last_attempt_at TEXT, attempt_count INTEGER DEFAULT 0,
                last_error TEXT, sent_at TEXT, email_to TEXT, email_subject TEXT, email_body TEXT)");
            $insert = $pdo->prepare("INSERT INTO $table (id,$column,delivery_status,next_attempt_at,claimed_at) VALUES (?,?,?,?,?)");
            foreach ([
                [1, 10, 'pending', null, null],
                [2, 10, 'retry', '2026-01-01 10:01:00', null],
                [3, 10, 'processing', null, '2026-01-01 09:50:00'],
                [4, 10, 'processing', null, '2026-01-01 09:49:59'],
                [5, 20, 'pending', null, null],
                [6, 10, 'sent', null, null],
                [7, 10, 'suppressed', null, null],
            ] as $row) {
                $insert->execute($row);
            }
            $now = new DateTimeImmutable('2026-01-01 10:00:00');
            self::assertSame([1, 4], array_map('intval', invoice_notification_queue_candidates($pdo, $type, $now, 100, 10)));
            self::assertSame([1], array_map('intval', invoice_notification_queue_candidates($pdo, $type, $now, 0, null)));
            self::assertTrue(invoice_notification_queue_claim($pdo, $type, 1, $now));
            // A competing worker that already selected this ID cannot claim it again.
            self::assertFalse(invoice_notification_queue_claim($pdo, $type, 1, $now));
            self::assertFalse(invoice_notification_queue_claim($pdo, $type, 3, $now));
            self::assertTrue(invoice_notification_queue_claim($pdo, $type, 4, $now));

            invoice_notification_queue_retry($pdo, $type, 1, $now, 1, str_repeat('x', 1100));
            $row = $pdo->query("SELECT * FROM $table WHERE id=1")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('retry', $row['delivery_status']);
            self::assertSame('2026-01-01 10:05:00', $row['next_attempt_at']);
            self::assertNull($row['claimed_at']);
            self::assertSame(1000, strlen($row['last_error']));
            self::assertFalse(invoice_notification_queue_claim($pdo, $type, 1, $now->modify('+4 minutes')));
            self::assertTrue(invoice_notification_queue_claim($pdo, $type, 1, $now->modify('+5 minutes')));
            self::assertSame(2, (int)$pdo->query("SELECT attempt_count FROM $table WHERE id=1")->fetchColumn());
            invoice_notification_queue_retry($pdo, $type, 1, $now, 2, 'Retry');
            self::assertSame('2026-01-01 10:10:00', $pdo->query("SELECT next_attempt_at FROM $table WHERE id=1")->fetchColumn());
            invoice_notification_queue_retry($pdo, $type, 1, $now, 50, 'Retry');
            self::assertSame($now->modify('+1280 minutes')->format('Y-m-d H:i:s'), $pdo->query("SELECT next_attempt_at FROM $table WHERE id=1")->fetchColumn());

            invoice_notification_queue_suppress($pdo, $type, 4, 'Recipient changed.');
            invoice_notification_queue_sent($pdo, $type, 1, $now, 'fixture@example.invalid', 'Invoice ready', '<p>Ready</p>');
            foreach ([1 => 'sent', 4 => 'suppressed'] as $id => $status) {
                $row = $pdo->query("SELECT * FROM $table WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
                self::assertSame($status, $row['delivery_status']);
                self::assertNull($row['next_attempt_at']);
                self::assertNull($row['claimed_at']);
                self::assertFalse(invoice_notification_queue_claim($pdo, $type, $id, $now->modify('+1 day')));
            }
            self::assertSame('fixture@example.invalid', $pdo->query("SELECT email_to FROM $table WHERE id=1")->fetchColumn());
            self::assertSame('Recipient changed.', $pdo->query("SELECT last_error FROM $table WHERE id=4")->fetchColumn());
        }
    }

    public function testReminderBoundaryKeysAndPreferencesRemainStable(): void
    {
        $today = new DateTimeImmutable('2026-01-15');
        $config = ['invoice_auto_send_due_7days' => true, 'invoice_auto_send_overdue_weekly' => true];
        foreach ([8 => null, 7 => 'due_7', 1 => 'due_7', 0 => null, -1 => 'overdue_weekly', -7 => 'overdue_weekly', -8 => 'overdue_weekly'] as $offset => $type) {
            $due = $today->modify(sprintf('%+d days', $offset));
            $stage = invoice_notification_reminder_stage($today, $due, $config);
            self::assertSame($type, $stage['type'] ?? null);
            if ($type !== null) {
                $key = 'due:' . $due->format('Y-m-d');
                if ($offset < 0) {
                    $key .= ':week:' . ($offset === -8 ? 1 : 0);
                }
                self::assertSame($key, $stage['key']);
            }
            self::assertNull(invoice_notification_reminder_stage($today, $due, []));
        }
    }

    public function testUnknownQueueIsRejectedBeforeAnySql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        invoice_notification_queue_candidates(new PDO('sqlite::memory:'), 'autopay_notifications', new DateTimeImmutable(), 10, null);
    }

    public function testDeliveryRetainsRawLegacyDateKeyWhileSchedulingNormalizesIt(): void
    {
        $raw = '2026-02-30';
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        self::assertInstanceOf(DateTimeImmutable::class, $due);
        $config = ['invoice_auto_send_due_7days' => true, 'invoice_auto_send_overdue_weekly' => true];
        foreach (['2026-03-01' => '', '2026-03-03' => ':week:0'] as $today => $suffix) {
            $scheduled = invoice_notification_reminder_stage(new DateTimeImmutable($today), $due, $config);
            $delivery = invoice_notification_reminder_stage(new DateTimeImmutable($today), $due, $config, $raw);
            self::assertSame('due:2026-03-02' . $suffix, $scheduled['key']);
            self::assertSame('due:2026-02-30' . $suffix, $delivery['key']);
            self::assertNotSame($scheduled['key'], $delivery['key']);
        }
    }
}
