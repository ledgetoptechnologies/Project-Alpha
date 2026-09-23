<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TimekeepingDeferredWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testOwnerTimeUsesApprovalSnapshotPipelineWithIndependentPayPolicy(): void
    {
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');
        $time = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/workforce/action.php');

        self::assertStringContainsString('public function selfConfirmOwner', $approval);
        self::assertStringContainsString('public function ensureOwnerProjection', $approval);
        self::assertStringContainsString("['draft','returned','submitted']", $approval);
        self::assertStringNotContainsString('$effectivePayable = !$ownerSelfConfirmation', $approval);
        self::assertStringContainsString("(string)(\$entry['compensation_policy'] ?? '') === 'rules'", $approval);
        self::assertStringContainsString('if(!empty($entry[\'work_assignment_id\']))', $approval);
        self::assertStringContainsString("'time_entry.owner_self_confirmed'", $approval);
        self::assertStringContainsString("'duration'", $time);
        self::assertStringContainsString("\$worker['compensation_state']", $time);
        self::assertStringContainsString('workforce_self_confirm_completed($approval, $userId, $entryToSelfConfirm)', $controller);
        self::assertStringContainsString('$entryToSelfConfirm = $time->saveManual', $controller);
        self::assertStringContainsString('$entryToSelfConfirm = $time->saveDuration', $controller);
    }

    public function testSelfApprovalIsRejectedAndMissingBillingRateIsDeferred(): void
    {
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');

        self::assertStringContainsString('You cannot approve your own time entry.', $approval);
        self::assertStringContainsString('$billingRateOverride ?? $this->billingRate($entry)', $approval);
        self::assertStringNotContainsString('A project or business billing rate is required for billable time.', $approval);
    }

    public function testEditableUnbilledTimeKeepsAnAuditedRevisionAndCompatibilityAlias(): void
    {
        $time = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');

        self::assertStringContainsString('public function reviseEntry(', $time);
        self::assertStringContainsString("workflow_status IN ('draft','returned')", $time);
        self::assertStringContainsString("workflow_status='confirmed'", $time);
        self::assertStringContainsString('Billed or invoiced time cannot be edited.', $time);
        self::assertStringContainsString('INSERT INTO work_time_revisions', $time);
        self::assertStringContainsString("'time_entry.revised'", $time);
        self::assertStringContainsString('public function reviseRejected(', $time);
        self::assertStringContainsString('$this->reviseEntry($userId, $userId, $entryId, $input, $manageAll);', $time);
    }

    public function testInvoiceContextInfersJobAndReviewQueueExcludesReviewer(): void
    {
        $time = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');
        $approvals = (string)file_get_contents($this->root . '/src/views/pages/workforce/approvals.php');
        $queue = (string)file_get_contents($this->root . '/src/services/TimeReviewQueueService.php');
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');

        self::assertStringContainsString('SELECT client_id,project_id,job_id FROM invoices', $time);
        self::assertStringContainsString('$jobId ??= $invoiceJobId;', $time);
        self::assertStringContainsString('$reviewQueue->pendingFor($userId)', $approvals);
        self::assertStringContainsString("WHERE t.status='review' AND t.workflow_status='submitted'", $queue);
        self::assertStringContainsString("canReviewRecord(\$reviewerId, \$row, 'approve')", $queue);
        self::assertStringContainsString('s2.entry_revision<=t.revision', $queue);
        self::assertStringContainsString('s.entry_revision<=?', $approval);
        self::assertStringContainsString('Select the assignment Job before saving time.', $time);
    }
}
