<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecurringInvoiceListRecipientTest extends TestCase
{
    // Recurring invoice history is the unified invoice list (kind=recurring); the
    // billing-schedule table moved to the Contracts list's Recurring tab.
    public function testRecurringHistoryUsesOrganizationFirstRecipientPresentation(): void
    {
        $view = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/views/pages/invoice/invoices-list.php'
        );

        self::assertStringContainsString("pa_document_effective_organization_joins('i', 'c')", $view);
        self::assertStringContainsString("doc_list_common_filters('i'", $view);
        self::assertStringContainsString('o.name AS organization_name', $view);
        self::assertStringContainsString('c.name AS client_name', $view);
        self::assertStringContainsString('doc_list_customer_cell($r)', $view);
        self::assertStringContainsString('<th>Client</th>', $view);
        self::assertStringNotContainsString('<th>Contact</th>', $view);
    }
}
