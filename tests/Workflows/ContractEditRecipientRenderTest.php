<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/DocumentPolicy.php';

final class ContractEditRecipientRenderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE contracts (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, created_by INTEGER, issuer_mode TEXT, signed_at TEXT, signed_pdf_path TEXT)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, organization_id INTEGER)');
        $this->pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->prepare('INSERT INTO organizations VALUES (3,?)')->execute(['Company "A" & B']);
        $this->pdo->prepare('INSERT INTO clients VALUES (7,?,3)')->execute(['Jordan & "Casey"']);
        $this->pdo->exec("INSERT INTO clients VALUES (8,'Other recipient',NULL)");
        $this->pdo->exec("INSERT INTO contracts VALUES (1,7,'draft',23,'creator',NULL,NULL)");
        http_response_code(200);
    }

    public function testActualEditInputsKeepCurrentClientAndOrganizationDisplay(): void
    {
        [$contract, $html] = $this->renderRecipient();
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        self::assertSame('7', $dom->getElementById('contractEditClientId')->getAttribute('value'));
        self::assertSame('3', $dom->getElementById('contractEditClientId')->getAttribute('data-organization-id'));
        self::assertSame('Company "A" & B', $dom->getElementById('contractEditClientId')->getAttribute('data-organization-name'));
        self::assertSame('Jordan & "Casey"', $dom->getElementById('contractEditClientSearch')->getAttribute('value'));
        self::assertStringContainsString('&quot;Casey&quot;', $html);
        self::assertSame(23, (int)$contract['created_by']);
        self::assertSame('creator', $contract['issuer_mode']);
    }

    public function testRecipientDisplayFollowsPolicyCheckedRowInsteadOfEarlierJoin(): void
    {
        [$contract, $html] = $this->renderRecipient(['client_id' => 8, 'client_name' => 'Stale joined name', 'client_organization_id' => 99]);
        self::assertSame(7, (int)$contract['client_id']);
        self::assertSame('Jordan & "Casey"', $contract['client_name']);
        self::assertSame(3, (int)$contract['client_organization_id']);
        self::assertStringNotContainsString('Stale joined name', $html);
    }

    public function testCommercialLockStillStopsRecipientEditorRendering(): void
    {
        $this->pdo->exec("UPDATE contracts SET status='active',signed_at='2026-10-19 00:00:00' WHERE id=1");
        [, $html] = $this->renderRecipient();
        self::assertSame(409, http_response_code());
        self::assertStringContainsString('Return to contract', $html);
        self::assertStringNotContainsString('contractEditClientSearch', $html);
    }

    /** Render the actual policy/display preparation and input markup, without a live DB/bootstrap. */
    private function renderRecipient(array $earlierRow = []): array
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/contract/contracts-edit.php');
        $start = strpos($source, 'try{$contract=DocumentPolicy::assertMutable');
        $end = strpos($source, '$isLongTermContract =', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $pdo = $this->pdo;
        $id = 1;
        $contract = $earlierRow;
        ob_start();
        eval(substr($source, $start, $end - $start));
        $policyOutput = (string)ob_get_clean();
        if ($policyOutput !== '') return [$contract, $policyOutput];
        $start = strpos($source, '<input type="hidden" data-document-client-id');
        $end = strpos($source, '<div id="contractEditClientSuggest"', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        ob_start();
        eval('?>' . substr($source, $start, $end - $start));
        return [$contract, (string)ob_get_clean()];
    }
}
