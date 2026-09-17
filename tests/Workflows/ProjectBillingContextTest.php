<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectBillingContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/src/utils/project_billing.php';
    }

    public function testLockedContextFreezesMonthlyCollectionModeAndDueDate(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, invoice_billing_period TEXT, invoice_net_terms_days INTEGER)');
        $pdo->exec("INSERT INTO projects VALUES (7,'monthly',15)");

        $pdo->beginTransaction();
        $context = project_invoice_billing_context($pdo, 7, ['net_terms_days' => 30], '2026-08-12', true);
        $pdo->commit();

        self::assertSame('monthly', $context['billing_period']);
        self::assertSame('project_aggregate', $context['collection_mode']);
        self::assertSame(15, $context['net_terms_days']);
        self::assertSame('2026-09-15', $context['due_date']);
    }

    public function testPerInvoiceAndStandaloneContextsUseDocumentDate(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, invoice_billing_period TEXT, invoice_net_terms_days INTEGER)');
        $pdo->exec("INSERT INTO projects VALUES (8,'per_invoice',10)");

        $project = project_invoice_billing_context($pdo, 8, ['net_terms_days' => 30], '2026-08-12');
        $standalone = project_invoice_billing_context($pdo, null, ['net_terms_days' => 30], '2026-08-12');

        self::assertSame('direct', $project['collection_mode']);
        self::assertSame('2026-08-22', $project['due_date']);
        self::assertSame('direct', $standalone['collection_mode']);
        self::assertSame('2026-09-11', $standalone['due_date']);
    }

    public function testLockedContextRequiresAnOwningTransaction(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, invoice_billing_period TEXT, invoice_net_terms_days INTEGER)');
        $pdo->exec("INSERT INTO projects VALUES (9,'monthly',30)");

        $this->expectException(LogicException::class);
        project_invoice_billing_context($pdo, 9, [], '2026-08-12', true);
    }

    public function testEveryInvoiceCreationPathStoresTheResolvedModeAtInsert(): void
    {
        $paths = [
            'src/controllers/invoice/invoices_create.php',
            'src/controllers/quote/quote_approve.php',
            'src/controllers/public_view/public_quote_action.php',
            'src/controllers/contract/contracts_create.php',
            'src/controllers/contract/on_demand_invoice_generate.php',
            'src/utils/recurring_billing.php',
        ];
        foreach ($paths as $path) {
            $source = (string)file_get_contents($this->root . '/' . $path);
            self::assertStringContainsString('project_invoice_billing_context(', $source, $path);
            self::assertStringContainsString('collection_mode', $source, $path);
            self::assertStringContainsString('payment_terms_days', $source, $path);
            self::assertStringContainsString('due_date_source', $source, $path);
        }

        $allSources = implode("\n", array_map(
            fn(string $path): string => (string)file_get_contents($this->root . '/' . $path),
            $paths
        ));
        self::assertStringNotContainsString('UPDATE invoices SET collection_mode="project_aggregate"', $allSources);
    }

    public function testOnDemandReplayUsesStoredCollectionModeWithoutReplacingLinks(): void
    {
        $source = (string)file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');
        $replayStart = strpos($source, 'if($existingInvoiceId>0)');
        $replayEnd = strpos($source, '// Check if contract is active', $replayStart ?: 0);
        self::assertIsInt($replayStart);
        self::assertIsInt($replayEnd);
        $replay = substr($source, (int)$replayStart, (int)$replayEnd - (int)$replayStart);

        self::assertStringContainsString("\$existingCollectionMode === 'direct'", $replay);
        self::assertStringNotContainsString('project_uses_monthly_invoice_billing', $replay);
        self::assertStringNotContainsString('DELETE FROM public_links', $replay);
        self::assertStringNotContainsString('UPDATE public_links', $replay);
    }
}
