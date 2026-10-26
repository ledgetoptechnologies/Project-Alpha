<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/invoice_lifecycle.php';

final class InvoiceOverduePresentationTest extends TestCase
{
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new DateTimeImmutable('2026-08-12');
    }

    public function testOnlyIssuedCollectibleInvoicesWithPastExplicitDueDateAreOverdue(): void
    {
        self::assertFalse(invoice_is_past_due(['status' => 'draft', 'due_date' => '2026-01-01'], $this->today));
        self::assertFalse(invoice_is_past_due(['status' => 'draft', 'due_date' => null], $this->today));
        self::assertFalse(invoice_is_past_due(['status' => 'unpaid', 'due_date' => null], $this->today));
        self::assertFalse(invoice_is_past_due(['status' => 'unpaid', 'due_date' => '2026-08-12'], $this->today));
        self::assertFalse(invoice_is_past_due(['status' => 'unpaid', 'due_date' => '2026-08-11', 'collection_mode' => 'project_aggregate'], $this->today));
        self::assertTrue(invoice_is_past_due(['status' => 'sent', 'due_date' => '2026-08-11'], $this->today));
        self::assertTrue(invoice_is_past_due(['status' => 'sent', 'due_date' => '2026-08-11', 'collection_mode' => 'direct'], $this->today));
        self::assertTrue(invoice_is_past_due(['status' => 'partial', 'due_date' => '2026-08-11'], $this->today));
    }

    public function testInvoiceListsUseTheSharedPastDueGuard(): void
    {
        $root = dirname(__DIR__, 2);
        $regular = (string) file_get_contents($root . '/src/views/pages/invoice/invoices-list.php');
        $onDemand = (string) file_get_contents($root . '/src/views/pages/invoice/on-demand-invoices-list.php');
        $contractOnDemand = (string) file_get_contents($root . '/src/views/pages/contract/on-demand-invoices-list.php');

        self::assertStringContainsString('invoice_is_past_due($r)', $regular);
        self::assertStringNotContainsString("strtotime('+'.\$netDays.' days', strtotime(\$r['created_at']))", $regular);
        self::assertStringContainsString('invoice_is_past_due($r)', $onDemand);
        self::assertStringContainsString('invoice_is_past_due($r)', $contractOnDemand);

        $dashboard = (string) file_get_contents($root . '/src/views/pages/home.php');
        self::assertStringContainsString("COALESCE(collection_mode, 'direct') = 'direct'", $dashboard);
        self::assertStringContainsString("i.collection_mode='direct'", $regular);
        // The collection worker consumes explicit reservations, never overdue-list rows.
        $autoCharge = (string) file_get_contents($root . '/src/utils/autopay_collection.php');
        self::assertStringContainsString("(\$invoice['collection_mode']??'direct')!=='direct'", $autoCharge);
    }
}
