<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WorkerCompensationPolicyDecouplingTest extends TestCase
{
    private function source(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testRulesPolicyCanPayAnOwner(): void
    {
        $time = $this->source('src/Modules/Timekeeping/TimekeepingService.php');
        $approval = $this->source('src/Modules/Timekeeping/ApprovalService.php');
        $earnings = $this->source('src/services/WorkerEarningService.php');
        self::assertStringContainsString("\$payable = (string)\$profile['compensation_policy'] === 'rules'", $time);
        self::assertStringNotContainsString("&& (\$entry['relationship_type'] ?? '') !== 'owner'", $approval);
        self::assertStringNotContainsString("\$worker['relationship_type'] === 'owner'", $earnings);
        self::assertStringContainsString("'duration',?,?,?,?,?,?,?,0,?", $time);
    }

    public function testExplicitNonpayablePolicySuppressesPayRegardlessOfRole(): void
    {
        $time = $this->source('src/Modules/Timekeeping/TimekeepingService.php');
        $earnings = $this->source('src/services/WorkerEarningService.php');
        $planning = $this->source('src/services/JobWorkPlanningService.php');
        self::assertStringContainsString("\$profile['compensation_policy'] === 'nonpayable'", $time);
        self::assertStringContainsString("\$worker['compensation_policy'] === 'nonpayable'", $earnings);
        self::assertStringContainsString("['nonpayable','owner_no_pay']", $planning);
    }

    public function testAccountRoleChangePreservesExistingWorkerPolicy(): void
    {
        $update = $this->source('src/controllers/accounts/accounts_update.php');
        self::assertStringContainsString('Keep an existing worker relationship independent from the account ACL', $update);
        self::assertStringNotContainsString('UPDATE worker_profiles SET compensation_policy=', $update);
        self::assertStringContainsString("\$workerCompensationPolicy = 'needs_setup'", $update);
    }

    public function testMigrationChangesOnlyFutureDefaultAndDoesNotRewriteRows(): void
    {
        $migration = $this->source('database/migrations/0107_decouple_worker_compensation_policy.sql');
        self::assertStringContainsString("DEFAULT 'needs_setup'", $migration);
        self::assertStringNotContainsString('UPDATE WORKER_PROFILES', strtoupper($migration));
        self::assertStringNotContainsString('worker_earnings', strtolower($migration));
    }

    public function testPolicyControlsAreSeparateFromReviewControls(): void
    {
        $handler = $this->source('src/controllers/settings/workforce_catalog_handler.php');
        $view = $this->source('src/views/pages/settings/business-units.php');
        self::assertStringNotContainsString("name=\"time_review_policy\"", $view);
        self::assertStringContainsString('aria-label="Time review policy"', $view);
        self::assertStringContainsString("name=\"compensation_policy\"", $view);
        self::assertStringContainsString("['rules','nonpayable','owner_no_pay','needs_setup']", $handler);
        self::assertStringNotContainsString("\$compensationPolicy=\$relationship==='owner'", $handler);
        self::assertStringContainsString("\$previousProfile['compensation_policy']??'needs_setup'", $handler);
    }

    public function testSelfConfirmationMakesEarningEligibleButDoesNotApproveIt(): void
    {
        $approval = $this->source('src/Modules/Timekeeping/ApprovalService.php');
        self::assertStringContainsString("\$payAmount === null ? 'needs_setup' : 'eligible'", $approval);
        self::assertStringNotContainsString("\$payAmount === null ? 'needs_setup' : 'approved'", $approval);
        self::assertStringContainsString("if(!empty(\$entry['work_assignment_id']))", $approval);
    }

    public function testPayPeriodAndAssignmentSurfacesUsePolicyInsteadOfOwnerRelationship(): void
    {
        $period = $this->source('src/services/PayPeriodService.php');
        $deadline = $this->source('src/services/PayPeriodDeadlineService.php');
        $assignments = $this->source('src/views/pages/settings/assignments.php');
        $pay = $this->source('src/views/pages/workforce/pay.php');
        foreach ([$period, $deadline, $assignments] as $source) {
            self::assertStringNotContainsString("relationship_type<>'owner'", $source);
        }
        self::assertStringContainsString("compensation_policy='rules'", $period);
        self::assertStringContainsString("compensation_policy='rules'", $deadline);
        self::assertStringContainsString("compensation_policy IN ('rules','nonpayable','owner_no_pay')", $assignments);
        self::assertStringContainsString("['nonpayable', 'owner_no_pay']", $pay);
        self::assertStringNotContainsString("\$currentWorker['relationship_type'] === 'owner'", $pay);
    }

    public function testTimeFormAndStatementSelectionPreservePolicySemantics(): void
    {
        $time = $this->source('src/views/pages/workforce/time.php');
        $period = $this->source('src/services/PayPeriodService.php');
        self::assertStringNotContainsString('!$selectedIsOwner &&', $time);
        self::assertStringNotContainsString('Owner &mdash; no payroll compensation', $time);
        self::assertStringContainsString("['nonpayable', 'owner_no_pay']", $time);
        self::assertStringContainsString(') payable ON payable.worker_profile_id=wp.id"', $period);
        self::assertStringNotContainsString("payable.worker_profile_id=wp.id WHERE wp.compensation_policy='rules'", $period);
    }
}
