<?php

declare(strict_types=1);

use App\Domain\Workforce\TimeEntryWorkflow;
use App\Services\TimeBillingAllocationService;
use App\Services\TimeSubmissionService;
use App\Services\WorkerEarningService;
use PHPUnit\Framework\TestCase;

final class WorkforceWorkflowFoundationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testCanonicalWorkflowMapsLegacyStatusesWithoutRemovingCompatibility(): void
    {
        self::assertSame('submitted', TimeEntryWorkflow::fromLegacyStatus('review'));
        self::assertSame('confirmed', TimeEntryWorkflow::fromLegacyStatus('approved'));
        self::assertSame('review', TimeEntryWorkflow::legacyStatus('draft'));
        self::assertSame('approved', TimeEntryWorkflow::legacyStatus('confirmed'));
        TimeEntryWorkflow::assertTransition('returned', 'submitted');
        $this->expectException(DomainException::class);
        TimeEntryWorkflow::assertTransition('confirmed', 'draft');
    }

    public function testBillingAndCompensationLifecyclesAreIndependent(): void
    {
        self::assertSame('pending', TimeBillingAllocationService::initialStatus('undecided', null));
        self::assertSame('ready', TimeBillingAllocationService::initialStatus('internal', null));
        self::assertSame('rate_needed', TimeBillingAllocationService::initialStatus('hourly', null));
        self::assertSame('ready', TimeBillingAllocationService::initialStatus('hourly', '125.0000'));

        WorkerEarningService::assertTransition('provisional', 'eligible');
        WorkerEarningService::assertTransition('approved', 'included');
        WorkerEarningService::assertTransition('included', 'settled');
        self::assertSame(
            'time_entry:0aa00000-0000-4000-8000-000000000000:3',
            WorkerEarningService::sourceKey('time_entry', '0aa00000-0000-4000-8000-000000000000', 3)
        );
    }

    public function testInvalidEarningTransitionIsRejected(): void
    {
        $this->expectException(DomainException::class);
        WorkerEarningService::assertTransition('settled', 'approved');
    }

    public function testNewServicesAreAutoloadable(): void
    {
        self::assertTrue(class_exists(TimeSubmissionService::class));
        self::assertTrue(class_exists(TimeBillingAllocationService::class));
        self::assertTrue(class_exists(WorkerEarningService::class));
    }

    public function testMigrationAddsRevisionSnapshotsAllocationsAndUnifiedEarnings(): void
    {
        $migration = (string)file_get_contents(
            $this->root . '/database/migrations/0047_workforce_workflow_redesign.sql'
        );
        foreach ([
            'time_submissions',
            'time_submission_entries',
            'work_type_billing_defaults',
            'work_time_billing_allocations',
            'worker_earnings',
            'worker_earning_events',
        ] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $migration);
        }
        self::assertStringContainsString('entry_snapshot JSON NOT NULL', $migration);
        self::assertStringContainsString('allocation_snapshot JSON NOT NULL', $migration);
        self::assertStringContainsString('calculation_snapshot JSON NOT NULL', $migration);
        self::assertStringContainsString('source_key VARCHAR(190) NOT NULL', $migration);
        self::assertStringContainsString('current_submission_id CHAR(36) NULL', $migration);
    }

    public function testAdminAclDoesNotSilentlyRemainAnOwnerInference(): void
    {
        $migration = (string)file_get_contents(
            $this->root . '/database/migrations/0047_workforce_workflow_redesign.sql'
        );
        self::assertStringContainsString("relationship_review_reason='legacy_admin_owner_inference'", $migration);
        self::assertStringContainsString("WHERE u.role='admin' AND wp.relationship_type='owner'", $migration);
        self::assertStringContainsString("wp.compensation_policy='needs_review'", $migration);

        $service = (string)file_get_contents($this->root . '/src/services/WorkerEarningService.php');
        self::assertStringContainsString("worker['compensation_policy'] === 'owner_no_pay'", $service);
        self::assertStringNotContainsString("worker['relationship_type']", $service);
        self::assertStringNotContainsString("worker['role']", $service);
    }

    public function testWorkTypeBillingDefaultsAreSeparateFromCompensationDefaults(): void
    {
        $migration = (string)file_get_contents(
            $this->root . '/database/migrations/0047_workforce_workflow_redesign.sql'
        );
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS work_type_billing_defaults', $migration);
        self::assertStringContainsString("default_treatment ENUM('undecided','internal','fixed_price_included','hourly')", $migration);
        self::assertStringContainsString('default_billing_rate DECIMAL(12,4) NULL', $migration);
        self::assertStringNotContainsString('ALTER TABLE work_types ADD COLUMN default_billing', $migration);
    }

    public function testMigrationPreservesLegacyReadModels(): void
    {
        $migration = (string)file_get_contents(
            $this->root . '/database/migrations/0047_workforce_workflow_redesign.sql'
        );
        foreach (['work_pay_accruals', 'work_billing_consumptions', 'worker_period_submissions'] as $legacyTable) {
            self::assertStringNotContainsString('DROP TABLE ' . $legacyTable, $migration);
            self::assertStringNotContainsString('RENAME TABLE ' . $legacyTable, $migration);
        }
        self::assertStringContainsString("SET workflow_status='submitted',status='review'", (string)file_get_contents(
            $this->root . '/src/services/TimeSubmissionService.php'
        ));
    }

    public function testReadinessHealthRequiresEveryNewDomainTable(): void
    {
        $health = (string)file_get_contents($this->root . '/src/migrations/migration_lib.php');
        foreach ([
            'time_submissions',
            'time_submission_entries',
            'work_type_billing_defaults',
            'work_time_billing_allocations',
            'worker_earnings',
            'worker_earning_events',
        ] as $table) {
            self::assertStringContainsString("'{$table}'", $health);
        }
    }

    public function testBaselinePrefoldsTheForwardWorkflowSchema(): void
    {
        $baseline = (string)file_get_contents($this->root . '/database/baseline.sql');
        foreach ([
            'time_submissions',
            'time_submission_entries',
            'work_type_billing_defaults',
            'work_time_billing_allocations',
            'worker_earnings',
            'worker_earning_events',
        ] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $baseline);
        }
        self::assertStringContainsString('ADD COLUMN workflow_status', $baseline);
        self::assertStringContainsString('ADD COLUMN worker_earning_id', $baseline);
        self::assertStringContainsString('relationship_review_required TINYINT(1)', $baseline);
    }
}
