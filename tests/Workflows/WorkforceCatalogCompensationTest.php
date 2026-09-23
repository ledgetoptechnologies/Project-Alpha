<?php

declare(strict_types=1);

use App\Services\CompensationRuleService;
use PHPUnit\Framework\TestCase;

final class WorkforceCatalogCompensationTest extends TestCase
{
    private CompensationRuleService $service;

    protected function setUp(): void
    {
        /** Pure calculation tests do not require a database connection. */
        $this->service = (new ReflectionClass(CompensationRuleService::class))->newInstanceWithoutConstructor();
    }

    public function testFixedCompensationUsesFulfilledQuantity(): void
    {
        $result = $this->service->calculate(['method'=>'fixed','amount'=>'150','currency'=>'USD'], ['quantity'=>2]);
        self::assertSame('300.00', $result['amount']);
    }

    public function testRecoveryBasePlusMinuteProratedOverage(): void
    {
        $rule = ['method'=>'base_overage','amount'=>'150','included_minutes'=>60,'overage_rate'=>'35','currency'=>'USD'];
        self::assertSame('150.00', $this->service->calculate($rule, ['duration_seconds'=>1800])['amount']);
        self::assertSame('167.50', $this->service->calculate($rule, ['duration_seconds'=>5400])['amount']);
    }

    public function testNetLinePercentageIsIndependentOfGrossClientPrice(): void
    {
        $rule = ['method'=>'percentage','percentage'=>'10','percentage_basis'=>'net_line','eligibility_trigger'=>'completed_approved'];
        $result = $this->service->calculate($rule, ['line_gross'=>200,'line_net'=>150]);
        self::assertSame('15.00', $result['amount']);
        self::assertSame('150.00', $result['basis_amount']);
    }

    public function testCashCollectedRequiresInvoicePaidTrigger(): void
    {
        $this->expectException(DomainException::class);
        $this->service->calculate(
            ['method'=>'percentage','percentage'=>10,'percentage_basis'=>'cash_collected','eligibility_trigger'=>'completed_approved'],
            ['cash_collected'=>100]
        );
    }

    public function testMigrationDefinesIndependentWorkerAndCatalogDomains(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0045_workforce_catalog_compensation.sql');
        foreach (['worker_profiles','business_units','worker_capability_scopes','work_types','catalog_bundle_items','catalog_work_components','job_work_components','work_assignments','pay_periods','worker_statements','compensation_adjustments'] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS '.$table, $source);
        }
        self::assertStringContainsString('user_id INT NULL', $source);
        self::assertStringContainsString('ON DELETE SET NULL', $source);
        self::assertStringContainsString("ENUM('own','assigned','business_unit','all')", $source);
    }

    public function testDocumentLinesRetainCatalogAndImmutableSnapshotReferences(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0045_workforce_catalog_compensation.sql');
        self::assertSame(3, substr_count($source, 'ADD COLUMN item_library_id INT NULL'));
        self::assertSame(3, substr_count($source, 'ADD COLUMN catalog_snapshot JSON NULL'));
        self::assertStringContainsString("entry_type ENUM(''product'',''service'',''fee'',''bundle'')", $source);
    }

    public function testPlanningIsIdempotentAndSellingNeverCreatesPay(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__,2).'/src/services/JobWorkPlanningService.php');
        self::assertStringContainsString("hash('sha256'", $source);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', $source);
        self::assertStringContainsString("\$snapshot['work_components']", $source);
        self::assertStringContainsString("\$snapshot['bundle_items']", $source);
        self::assertStringContainsString("VALUES (?,'planned',?)", $source);
        self::assertStringNotContainsString("VALUES (?,'approved_payable'", $source);
    }

    public function testAssignmentLifecycleAndDeclineReplacementAreExplicit(): void
    {
        $migration = (string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0045_workforce_catalog_compensation.sql');
        self::assertStringContainsString("'planned','offered','accepted','declined','in_progress','completed','eligible','approved_payable','settled','cancelled'", $migration);
        $service = (string)file_get_contents(dirname(__DIR__,2).'/src/services/JobWorkPlanningService.php');
        self::assertStringContainsString("status='declined'", $service);
        self::assertStringContainsString("status='eligible'", $service);
        self::assertStringContainsString("status='approved_payable'", $service);
    }

    public function testMileageTravelerUsesWorkerIdentityAndFinancialTreatment(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0045_workforce_catalog_compensation.sql');
        self::assertStringContainsString('traveler_worker_id INT NULL', $source);
        self::assertStringContainsString("'organization_mileage'',''worker_reimbursement'',''contractor_record_only'',''nonreimbursable'", $source);
        self::assertStringContainsString('JOIN worker_profiles wp ON wp.user_id=m.user_id', $source);
    }

    public function testOwnerQuickDurationUsesExplicitCompensationPolicy(): void
    {
        $service = (string)file_get_contents(dirname(__DIR__,2).'/src/Modules/Timekeeping/TimekeepingService.php');
        self::assertStringContainsString('public function saveDuration', $service);
        self::assertStringContainsString("'duration'", $service);
        self::assertStringContainsString("\$worker['is_payable']", $service);
        self::assertStringContainsString("\$worker['compensation_state']", $service);
        self::assertStringNotContainsString("'review','draft',?,'owner_no_pay'", $service);
        self::assertStringContainsString('ApprovalService::selfConfirmOwner', $service);
        self::assertStringContainsString('owner_internal_cost_rate', $service);
    }

    public function testWorkerCanUseJobsAndAssignmentsWithoutDocuments(): void
    {
        $time=(string)file_get_contents(dirname(__DIR__,2).'/src/Modules/Timekeeping/TimekeepingService.php');
        self::assertStringContainsString('public function jobsFor', $time);
        self::assertStringContainsString('public function assignmentsFor', $time);
        self::assertStringContainsString("'timer'", $time);
        self::assertStringContainsString("'exact'", $time);
        $view=(string)file_get_contents(dirname(__DIR__,2).'/src/views/pages/workforce/time.php');
        self::assertStringContainsString('Assignment offers', $view);
        self::assertStringContainsString('Accepted assignment', $view);
    }

    public function testStatementsSupportContractorInvoiceAttachment(): void
    {
        $migration=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0045_workforce_catalog_compensation.sql');
        self::assertStringContainsString('contractor_invoice_sha256 CHAR(64)', $migration);
        $upload=(string)file_get_contents(dirname(__DIR__,2).'/src/controllers/workforce/contractor_invoice.php');
        self::assertStringContainsString('validate_and_store_upload', $upload);
        self::assertStringContainsString("'reject_pdf_active_content'=>true", $upload);
        self::assertStringContainsString('already has a contractor invoice attached', $upload);
        $download=(string)file_get_contents(dirname(__DIR__,2).'/src/controllers/workforce/contractor_invoice_download.php');
        self::assertStringContainsString('Cache-Control: private, no-store', $download);
    }

    public function testCatalogDeactivationPreservesHistoryAndPermanentDeletionIsGuarded(): void
    {
        $handler = (string)file_get_contents(dirname(__DIR__,2).'/src/controllers/settings/item_library_handler.php');
        self::assertStringContainsString('UPDATE item_library SET is_active=0', $handler);
        self::assertStringContainsString("['quote_items', 'item_library_id']", $handler);
        self::assertStringContainsString("['contract_items', 'item_library_id']", $handler);
        self::assertStringContainsString("['invoice_items', 'item_library_id']", $handler);
        self::assertStringContainsString('job_work_components', $handler);
        self::assertStringContainsString('DELETE FROM item_library WHERE id=?', $handler);
        self::assertLessThan(
            strpos($handler, 'DELETE FROM item_library WHERE id=?'),
            strpos($handler, "['quote_items', 'item_library_id']")
        );
    }

    public function testApisUseStandardResponsesAndSchemaError(): void
    {
        foreach (['workforce_v1.php','catalog_v1.php'] as $file) {
            $source = (string)file_get_contents(dirname(__DIR__,2).'/src/controllers/api/'.$file);
            self::assertStringContainsString('api_json_success', $source);
            self::assertStringContainsString("'schema_out_of_date'", $source);
            self::assertStringContainsString("'csrf_failed'", $source);
        }
    }
}
