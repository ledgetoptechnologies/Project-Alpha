<?php

declare(strict_types=1);

use App\Services\WorkforceCommandRegistry;
use PHPUnit\Framework\TestCase;

final class WorkforceApiParityTest extends TestCase
{
    private string $source;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->source = (string)file_get_contents(
            $this->root . '/src/controllers/api/workforce_v1.php'
        );
    }

    public function testEveryDesktopWorkforceButtonUsesARegisteredControllerAction(): void
    {
        $controller = (string)file_get_contents($this->root . '/src/controllers/workforce/action.php');
        $router = (string)file_get_contents($this->root . '/public/index.php');
        $views = '';
        foreach (glob($this->root . '/src/views/pages/workforce/*.php') ?: [] as $view) {
            $views .= (string)file_get_contents($view);
        }

        preg_match_all('/name="action"\s+value="([a-z-]+)"/', $views, $matches);
        $actions = array_values(array_unique(array_merge($matches[1] ?? [], [
            // These controls select their action dynamically in the unified form/table.
            'clock-in',
            'manual-create',
            'assignment-start',
            'assignment-complete',
        ])));
        sort($actions);

        self::assertNotEmpty($actions);
        foreach ($actions as $action) {
            self::assertContains($action, WorkforceCommandRegistry::actions(), "{$action} is not registered.");
            self::assertStringContainsString("'{$action}'", $controller, "{$action} is not handled by the desktop controller.");
        }
        self::assertStringContainsString("if (\$page === 'workforce/action')", $router);
        self::assertStringContainsString('csrf_verify_post_or_redirect($page)', $router);
    }

    public function testReviewQueueUsesTheSharedPolicyAndQueueReadModel(): void
    {
        self::assertStringContainsString('new TimeApprovalPolicy($pdo)', $this->source);
        self::assertStringContainsString('new TimeReviewQueueService($pdo, $approvalPolicy)', $this->source);
        self::assertStringContainsString("\$resource === 'review_queue'", $this->source);
        self::assertStringContainsString('$approvalPolicy->canAccessQueue($userId)', $this->source);
        self::assertStringContainsString('$reviewQueue->pendingFor($userId)', $this->source);
        self::assertStringContainsString('$reviewQueue->recentlyApprovedFor(', $this->source);
    }

    public function testJobDirectoryAndCreationEnforceRecordScope(): void
    {
        self::assertStringNotContainsString("\$canManage('jobs.view')||", $this->source);
        self::assertStringContainsString("(int)\$job['created_by'] === \$userId", $this->source);
        self::assertStringContainsString("\$access->can(\$userId,'jobs.view','job'", $this->source);
        self::assertStringContainsString("can_access_record(\$pdo,'clients',\$clientId,\$userId)", $this->source);
        self::assertStringContainsString("can_access_record(\$pdo,'projects',\$projectId,\$userId)", $this->source);
    }

    public function testRevisionBoundSubmissionUsesTheSharedSubmissionService(): void
    {
        self::assertSame('time.submit', WorkforceCommandRegistry::require('submit-period')['policy']);
        self::assertStringContainsString("\$resource === 'time_submission'", $this->source);
        self::assertStringContainsString("WorkforceCommandRegistry::require('submit-period', \$method)", $this->source);
        self::assertStringContainsString('$submissions->submit(', $this->source);
        self::assertStringContainsString('$approvalPolicy->canReviewWorker(', $this->source);
    }

    public function testReviewDecisionsDoNotDuplicateApprovalPolicyOrCalculations(): void
    {
        self::assertStringContainsString("\$resource === 'time_review'", $this->source);
        self::assertStringContainsString('$approvalPolicy->assertCanReviewEntry(', $this->source);
        self::assertStringContainsString('$approval->approve(', $this->source);
        self::assertStringContainsString('$approval->reject(', $this->source);
        self::assertStringContainsString('$approval->void(', $this->source);
        self::assertStringContainsString('$submissions->recordDecision(', $this->source);
        self::assertStringNotContainsString('UPDATE work_time_entries SET workflow_status=', $this->source);
    }

    public function testBillingAllocationEndpointsUseTheDomainServiceAndInvoicePermission(): void
    {
        self::assertStringContainsString("\$resource === 'billing_allocations'", $this->source);
        self::assertStringContainsString("\$resource === 'billing_allocation'", $this->source);
        self::assertStringContainsString("\$canManage('invoices.edit')", $this->source);
        self::assertStringContainsString('$billingAllocations->allocate(', $this->source);
        self::assertStringContainsString('$billingAllocations->reverse(', $this->source);
        self::assertStringNotContainsString('INSERT INTO work_time_billing_allocations', $this->source);
    }

    public function testWorkersSeeOnlyTheirEarningsAndManagersUseTheEarningService(): void
    {
        self::assertStringContainsString("\$resource === 'earnings'", $this->source);
        self::assertStringContainsString('You may view only your own earnings.', $this->source);
        self::assertStringContainsString("WorkforceCommandRegistry::require('earning-approve', \$method)", $this->source);
        self::assertStringContainsString('$earnings->transition(', $this->source);
        self::assertStringNotContainsString('UPDATE worker_earnings SET status=', $this->source);
    }

    public function testEveryNewApiPathUsesStandardJsonResponses(): void
    {
        self::assertStringContainsString('api_json_success', $this->source);
        self::assertStringContainsString('api_json_failure', $this->source);
        self::assertStringNotContainsString('echo json_encode', $this->source);
        foreach ([
            'permission_denied',
            'worker_profile_required',
            'time_submission_not_found',
            'operation_not_found',
            'schema_out_of_date',
            'internal_error',
        ] as $code) {
            self::assertStringContainsString("'{$code}'", $this->source);
        }
    }
}
