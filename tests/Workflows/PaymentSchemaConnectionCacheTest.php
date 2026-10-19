<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2) . '/src/utils/invoice_lifecycle.php';

final class PaymentSchemaConnectionCacheTest extends TestCase
{
    public function testColumnPresenceDoesNotLeakBetweenConnections(): void
    {
        $first=new PDO('sqlite::memory:');$second=new PDO('sqlite::memory:');
        $first->exec('CREATE TABLE payments(id INTEGER,processor_payment_id TEXT)');
        $second->exec('CREATE TABLE payments(id INTEGER)');
        self::assertTrue(invoice_table_has_column($first,'payments','processor_payment_id'));
        self::assertFalse(invoice_table_has_column($second,'payments','processor_payment_id'));
        $second->exec('ALTER TABLE payments ADD COLUMN processor_payment_id TEXT');
        self::assertTrue(invoice_table_has_column($second,'payments','processor_payment_id'));
    }

    public function testNonMySqlCompatibilityCheckDoesNotRunDdlOrEndTransaction(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->exec('CREATE TABLE payments(id INTEGER)');
        $pdo->beginTransaction();$pdo->exec('INSERT INTO payments VALUES(1)');
        invoice_ensure_payments_schema($pdo);
        self::assertTrue($pdo->inTransaction());$pdo->rollBack();
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }
}
