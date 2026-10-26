<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DocumentIssuerChoiceRenderTest extends TestCase
{
    private function render(array $row = [], array $config = []): DOMXPath
    {
        $issuerChoiceRow = $row;
        $appConfig = $config + ['from_company' => 'Example Company'];
        ob_start();
        require dirname(__DIR__, 2) . '/src/views/components/document_issuer_choice.php';
        $html = (string)ob_get_clean();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        return new DOMXPath($document);
    }

    public function testNewDocumentHasCollapsedKeyboardDisclosureAndSubmittableDefault(): void
    {
        $xpath = $this->render();
        self::assertSame(1, $xpath->query('//details[not(@open)]/summary')->length);
        self::assertStringContainsString('Issued by: Example Company (default)', preg_replace('/\s+/', ' ', $xpath->query('//summary')->item(0)->textContent));
        self::assertStringContainsString('Change', $xpath->query('//summary')->item(0)->textContent);
        self::assertSame(1, $xpath->query('//details//select[@name="document_issuer_mode" and not(@disabled)]/option[@value="default" and @selected]')->length);
        self::assertSame(1, $xpath->query('//details//label/select')->length);
    }

    public function testDraftRetainsEveryExistingModeWithoutChangingSubmittedValue(): void
    {
        foreach (['legacy', 'default', 'company', 'creator'] as $mode) {
            $xpath = $this->render(['status' => 'draft', 'issuer_mode' => $mode]);
            self::assertSame(1, $xpath->query('//select[not(@disabled)]/option[@selected]')->length);
            self::assertSame($mode, $xpath->query('//option[@selected]')->item(0)->getAttribute('value'));
        }
    }

    public function testIssuedAndSignedDocumentsKeepTheirControlDisabled(): void
    {
        foreach ([['status' => 'sent'], ['status' => 'paid'], ['status' => 'draft', 'signed_at' => '2026-01-01'], ['status' => 'draft', 'finalized_at' => '2026-01-01'], ['status' => 'draft', 'last_sent_revision' => 1]] as $row) {
            $xpath = $this->render($row + ['issuer_mode' => 'creator']);
            self::assertSame(1, $xpath->query('//select[@disabled]')->length);
            self::assertStringContainsString('Details', $xpath->query('//summary')->item(0)->textContent);
            self::assertStringNotContainsString('Change', $xpath->query('//summary')->item(0)->textContent);
        }
    }

    public function testHistoricalSnapshotWinsOverCurrentCompanyAndIsEscaped(): void
    {
        $name = '<script>alert("issuer")</script>';
        $xpath = $this->render([
            'status' => 'paid',
            'issuer_mode' => 'creator',
            'issuer_snapshot' => json_encode(['sender' => ['name' => $name, 'company' => 'Historical Company']]),
        ]);
        $summary = $xpath->query('//span[@data-issuer-summary]')->item(0)->textContent;
        self::assertSame($name . ' · Historical Company', $summary);
        self::assertSame(0, $xpath->query('//script')->length);
        self::assertStringNotContainsString('Example Company', $summary);
    }

    public function testCreatorCompanyDefaultIsNotMisrepresentedAsCompanyOnly(): void
    {
        $xpath = $this->render([], ['document_issuer_mode' => 'creator']);
        self::assertSame('Original creator · Example Company (default)', $xpath->query('//span[@data-issuer-summary]')->item(0)->textContent);
    }
}
