<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/views/components/document_list.php';

final class DocumentListRecipientPresentationTest extends TestCase
{
    /** @dataProvider coreDocumentLists */
    public function testCoreListsResolveTheEffectiveOrganization(string $relativePath, string $documentAlias): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../' . $relativePath);

        self::assertStringContainsString("pa_document_effective_organization_joins('{$documentAlias}', 'c')", $source);
        self::assertStringContainsString("doc_list_common_filters('{$documentAlias}'", $source);
        self::assertStringContainsString('doc_list_customer_cell($r)', $source);
    }

    public function testCustomerIsTheOrganizationWithTheContactUnderneath(): void
    {
        $html = doc_list_customer_cell(['organization_name' => 'Acme Co', 'client_name' => 'Pat Lee', 'client_id' => 7, 'project_code' => 'PA-1']);

        self::assertStringStartsWith('Acme Co', $html);
        self::assertStringContainsString('selected_client_id=7', $html);
        self::assertStringContainsString('Pat Lee', $html);
        self::assertStringContainsString('PA-1', $html);
    }

    public function testCustomerFallsBackToTheClientWithoutAContactLink(): void
    {
        $html = doc_list_customer_cell(['organization_name' => null, 'client_name' => 'Pat Lee', 'client_id' => 7]);

        self::assertSame('Pat Lee', $html);
    }

    public function testSearchMatchesClientAndOrganizationNames(): void
    {
        $_GET = ['q' => 'acme'];
        $where = [];
        $params = [];
        doc_list_common_filters('q', $where, $params);
        $_GET = [];

        self::assertStringContainsString('c.name LIKE ?', $where[0]);
        self::assertStringContainsString('o.name LIKE ?', $where[0]);
        self::assertSame(['%acme%', '%acme%', 'acme%'], $params);
    }

    public static function coreDocumentLists(): array
    {
        return [
            ['src/views/pages/quote/quotes-list.php', 'q'],
            ['src/views/pages/contract/contracts-list.php', 'co'],
            ['src/views/pages/invoice/invoices-list.php', 'i'],
        ];
    }
}
