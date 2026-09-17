<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecurringInvoiceListRecipientTest extends TestCase
{
    public function testScheduleAndHistoryUseOrganizationFirstRecipientPresentation(): void
    {
        $view = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/views/pages/invoice/recurring-invoices-list.php'
        );

        self::assertStringContainsString(
            "pa_document_effective_organization_joins('ltc', 'c')",
            $view
        );
        self::assertStringContainsString(
            'COALESCE(document_project.organization_id,i.organization_id,ltc.organization_id,c.organization_id)',
            $view
        );
        self::assertSame(2, substr_count($view, '(c.name LIKE ? OR o.name LIKE ?)'));
        self::assertGreaterThanOrEqual(2, substr_count($view, 'o.name AS organization_name'));
        self::assertSame(2, substr_count($view, '<th style="padding:10px">Customer</th>'));
        self::assertSame(2, substr_count($view, '<th style="padding:10px">Contact</th>'));
        self::assertStringContainsString(
            "\$ltc['organization_name'] ?: \$ltc['client_name']",
            $view
        );
        self::assertStringContainsString(
            "\$historyInvoice['organization_name'] ?: \$historyInvoice['client_name']",
            $view
        );
        self::assertStringContainsString("if (!empty(\$ltc['organization_name']))", $view);
        self::assertStringContainsString("if (!empty(\$historyInvoice['organization_name']))", $view);
        self::assertStringNotContainsString('<th style="padding:10px">Client</th>', $view);
    }
}
