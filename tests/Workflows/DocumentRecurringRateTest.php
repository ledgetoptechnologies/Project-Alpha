<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/document_recurring_rate.php';

final class DocumentRecurringRateTest extends TestCase
{
    public function testAnnualAmountUsesMonthlyRateRatherThanTamperedPreview(): void
    {
        $rate = pa_document_recurring_rate(['rate_basis'=>'monthly','monthly_rate'=>'19.99'], [], 1, 'year', 'per_invoice', '0.01');
        self::assertSame(['rate_basis'=>'monthly','monthly_rate'=>'19.99','amount'=>'239.88'], $rate);
    }

    public function testLegacyAnnualAmountRemainsPerPeriod(): void
    {
        self::assertSame(['rate_basis'=>'per_period','monthly_rate'=>null,'amount'=>'1200.00'], pa_document_recurring_rate([], [], 1, 'year', 'per_invoice', '1200.00'));
    }

    public function testOmittedNewFieldsOnDraftEditPreserveExistingMonthlyBasis(): void
    {
        $rate = pa_document_recurring_rate([], ['rate_basis'=>'monthly','monthly_rate'=>'50.00'], 1, 'year', 'per_invoice', '50.00');
        self::assertSame('600.00', $rate['amount']);
        self::assertSame('monthly', $rate['rate_basis']);
    }

    public function testReturningToPerPeriodRemovesMonthlyMetadata(): void
    {
        $rate = pa_document_recurring_rate(['rate_basis'=>'per_period'], ['rate_basis'=>'monthly','monthly_rate'=>'50.00'], 1, 'year', 'per_invoice', '500.00');
        self::assertNull($rate['monthly_rate']);
        self::assertSame('500.00', $rate['amount']);
    }

    public function testInstallmentsDoNotApplyMonthlyMultiplication(): void
    {
        $rate = pa_document_recurring_rate(['rate_basis'=>'monthly','monthly_rate'=>'50.00'], [], 1, 'year', 'fixed_total', null);
        self::assertSame(['rate_basis'=>'per_period','monthly_rate'=>null,'amount'=>null], $rate);
    }

    public function testMonthlyRateRejectsUnsupportedCadence(): void
    {
        $this->expectException(DomainException::class);
        pa_document_recurring_rate(['rate_basis'=>'monthly','monthly_rate'=>'50.00'], [], 2, 'month', 'per_invoice', '100.00');
    }

    public function testMonthlyRateRejectsFractionalCents(): void
    {
        $this->expectException(DomainException::class);
        pa_document_recurring_rate(['rate_basis'=>'monthly','monthly_rate'=>'50.001'], [], 1, 'month', 'per_invoice', '50.00');
    }

    public function testRateMetadataRollsBackWithDocumentTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, rate_basis TEXT DEFAULT 'per_period', monthly_rate TEXT)");
        $pdo->exec('INSERT INTO quotes (id) VALUES (1)');
        $pdo->beginTransaction();
        pa_document_recurring_rate_save($pdo, 'quote', 1, ['rate_basis'=>'monthly','monthly_rate'=>'25.00']);
        self::assertSame('monthly', $pdo->query('SELECT rate_basis FROM quotes')->fetchColumn());
        $pdo->rollBack();
        self::assertSame('per_period', $pdo->query('SELECT rate_basis FROM quotes')->fetchColumn());
    }
}
