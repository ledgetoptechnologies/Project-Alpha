<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/workforce_availability.php';
require_once dirname(__DIR__, 2) . '/src/utils/workforce_compensation.php';
require_once dirname(__DIR__, 2) . '/src/utils/job_work_materialization.php';
require_once dirname(__DIR__, 2) . '/src/views/pages/settings/registry.php';

final class WorkforceShelvedTest extends TestCase
{
    public function testSavedSettingsAndEnvironmentCannotResumeWorkforce(): void
    {
        $previous = getenv('APP_WORKFORCE_ENABLED');
        try {
            putenv('APP_WORKFORCE_ENABLED=true');
            self::assertFalse(pa_workforce_enabled());
            foreach (['workforce/time', 'workforce/action', 'workforce/contractor-invoice-download',
                'api/workforce-v1', 'settings/workforce-catalog-handler',
                'project/project-work-handler', 'time-tracking/start-timer', 'time-tracking/unbilled', 'financial/mileage-unbilled'] as $page) {
                self::assertTrue(pa_workforce_page_shelved($page), $page);
            }
            foreach (pa_workforce_settings_tabs() as $tab) {
                self::assertTrue(pa_workforce_page_shelved('settings', $tab), $tab);
            }
        } finally {
            putenv($previous === false ? 'APP_WORKFORCE_ENABLED' : 'APP_WORKFORCE_ENABLED=' . $previous);
        }
    }

    public function testDocumentClientAndHistoricalBillingRoutesRemainAvailable(): void
    {
        foreach (['invoice/invoices-create', 'invoice/invoices-update', 'public-doc',
            'quote/quotes-create', 'contract/contracts-create', 'project/projects-details',
            'api/financial-summary', 'client/clients-update', 'financial/mileage-tracking-api'] as $page) {
            self::assertFalse(pa_workforce_page_shelved($page), $page);
        }
        self::assertFalse(pa_workforce_page_shelved('settings', 'workflow'));
        self::assertFalse(pa_workforce_page_shelved('settings', 'documents'));
    }

    public function testRejectedRouteStopsBeforeWorkflowExecution(): void
    {
        $utility = dirname(__DIR__, 2) . '/src/utils/workforce_availability.php';
        $script = 'require ' . var_export($utility, true) . ';'
            . 'register_shutdown_function(static function (): void { echo " status=" . http_response_code(); });'
            . 'pa_workforce_require_available("workforce/action");'
            . 'echo "WORKFLOW_EXECUTED";';
        $process = proc_open([PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        self::assertStringContainsString('status=404', $output);
        self::assertStringNotContainsString('WORKFLOW_EXECUTED', $output);
    }

    public function testFinancialHooksPerformNoDatabaseWorkWhileShelved(): void
    {
        $pdo = new class extends PDO {
            public function __construct() {}
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                throw new LogicException('Shelved workflow touched the database');
            }
        };
        self::assertSame([], workforce_release_invoice_paid($pdo, 12, 1));
        self::assertSame([], catalog_plan_document_work($pdo, 'quote', 12, 1));
        self::assertSame([], catalog_plan_direct_contract($pdo, 12, 1));
        self::assertSame([], catalog_plan_direct_invoice($pdo, 12, 1));
    }

    public function testSettingsNavigationRetainsNormalWorkflowButHidesWorkforce(): void
    {
        $registry = pa_settings_registry();
        foreach (pa_workforce_settings_tabs() as $tab) {
            self::assertNull(pa_settings_find_tab($registry, $tab), $tab);
        }
        self::assertNotNull(pa_settings_find_tab($registry, 'workflow'));
        self::assertNotNull(pa_settings_find_tab($registry, 'item-library'));
        self::assertNotNull(pa_settings_find_tab($registry, 'system'));
    }
}
