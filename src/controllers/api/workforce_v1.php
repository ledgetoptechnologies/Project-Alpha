<?php

declare(strict_types=1);

use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;
use App\Modules\Timekeeping\WorkforceSettings;
use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;
use App\Services\PayPeriodService;
use App\Services\TimeApprovalPolicy;
use App\Services\TimeBillingAllocationService;
use App\Services\TimeReviewQueueService;
use App\Services\TimeSubmissionService;
use App\Services\WorkerEarningService;
use App\Services\WorkforceAccessService;
use App\Services\WorkforceCommandRegistry;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/api_response.php';
require_once __DIR__ . '/../../utils/project_id.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) api_json_failure(401, 'authentication_required', 'Authentication is required.');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $_POST;
if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) $input = $decoded;
}
if ($method !== 'GET') {
    $headerToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    $csrfOk = csrf_validate() || ($headerToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $headerToken));
    if (!$csrfOk) api_json_failure(403, 'csrf_failed', 'The request could not be verified.');
}

$resource = trim((string)($_GET['resource'] ?? $input['resource'] ?? 'actor'));
$action = trim((string)($_GET['action'] ?? $input['action'] ?? 'list'));
$access = new WorkforceAccessService($pdo);
$planning = new JobWorkPlanningService($pdo, new CompensationRuleService($pdo));
$periods = new PayPeriodService($pdo);
$approvalPolicy = new TimeApprovalPolicy($pdo);
$reviewQueue = new TimeReviewQueueService($pdo, $approvalPolicy);
$submissions = new TimeSubmissionService($pdo);
$billingAllocations = new TimeBillingAllocationService($pdo);
$earnings = new WorkerEarningService($pdo);
$approval = new ApprovalService($pdo, new AuditRecorder($pdo), new BillingTimeConsumer($pdo), $approvalPolicy);
$actor = $access->actor($userId);
$activeWorkerStatement = $pdo->prepare(
    "SELECT id FROM worker_profiles WHERE user_id=? AND status='active' ORDER BY id DESC LIMIT 1"
);
$activeWorkerStatement->execute([$userId]);
$activeWorkerProfileId = (int)($activeWorkerStatement->fetchColumn() ?: 0);

$canManage = static function (string $permission) use ($pdo, $userId, $actor): bool {
    return in_array((string)($actor['role'] ?? ''), ['admin', 'owner'], true) || user_can($pdo, $userId, $permission, 0);
};

$activeWorkerId = static fn(): int => $activeWorkerProfileId;
$requireWorker = static function () use ($activeWorkerId): int {
    $workerId = $activeWorkerId();
    if ($workerId <= 0) {
        api_json_failure(403, 'worker_profile_required', 'This account is not linked to an active worker profile.');
    }
    return $workerId;
};
$requireTimeManager = static function () use ($pdo, $userId): void {
    if (!WorkforceSettings::canManageAllTime($pdo, $userId)) {
        api_json_failure(403, 'permission_denied', 'Time-management permission is required.');
    }
};
$requireBillingManager = static function () use ($requireTimeManager, $canManage): void {
    $requireTimeManager();
    if (!$canManage('invoices.edit')) {
        api_json_failure(403, 'permission_denied', 'Invoice-edit permission is required to manage client billing allocations.');
    }
};
$entryIds = static function (mixed $value): array {
    if (is_string($value)) {
        $value = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_unique(array_filter(
        array_map(static fn(mixed $id): string => trim((string)$id), $value),
        static fn(string $id): bool => $id !== ''
    )));
};

try {
    if ($method === 'GET' && $resource === 'actor') {
        api_json_success(['data' => $actor]);
    }
    if ($method === 'GET' && $resource === 'clients') {
        api_json_success(['data' => $access->clientDirectory($userId, (string)($_GET['q'] ?? ''), (int)($_GET['limit'] ?? 25))]);
    }
    if ($method === 'GET' && $resource === 'jobs') {
        $stmt=$pdo->query("SELECT j.id,j.job_code,j.client_id,j.project_id,j.status,j.created_by,c.name client_name FROM jobs j JOIN clients c ON c.id=j.client_id WHERE j.archived=0 ORDER BY j.created_at DESC,j.id DESC LIMIT 250");
        $canViewOwn = user_can($pdo, $userId, 'jobs.view', 0);
        $canViewAll = $canViewOwn && acl_user_has_org_wide_scope($pdo, $userId, 0);
        $rows=array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),static function(array $job)use($access,$userId,$canViewOwn,$canViewAll):bool{
            return $canViewAll
                || ($canViewOwn && (int)$job['created_by'] === $userId)
                || $access->can($userId,'jobs.view','job',(int)$job['id']);
        }));
        api_json_success(['data'=>$rows]);
    }
    if ($method === 'GET' && $resource === 'assignments') {
        $workerId = (int)($actor['worker_profile_id'] ?? 0);
        $manage = $canManage('workforce.assignments.manage');
        $sql = 'SELECT wa.id,wa.status,wa.estimated_pay,wa.currency,wa.offered_at,wa.decline_reason,
                       jwc.name,jwc.description,jwc.expected_duration_minutes,jwc.planned_quantity,j.id job_id,j.job_code
                FROM work_assignments wa JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
                JOIN jobs j ON j.id=jwc.job_id';
        $params = [];
        if (!$manage) {
            $sql .= ' WHERE wa.worker_profile_id=?';
            $params[] = $workerId;
        }
        $sql .= ' ORDER BY COALESCE(wa.offered_at,wa.created_at) DESC,wa.id DESC LIMIT 250';
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        api_json_success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'GET' && $resource === 'pay_periods') {
        $period = $periods->periodFor(new DateTimeImmutable((string)($_GET['date'] ?? 'now')));
        api_json_success(['data' => $period]);
    }
    if ($method === 'GET' && $resource === 'statements') {
        $workerId = (int)($actor['worker_profile_id'] ?? 0);
        $manage = $canManage('workforce.statements.manage');
        $stmt = $pdo->prepare(
            'SELECT ws.*,pp.period_start,pp.period_end,wp.display_name FROM worker_statements ws
             JOIN pay_periods pp ON pp.id=ws.pay_period_id JOIN worker_profiles wp ON wp.id=ws.worker_profile_id'
            . ($manage ? '' : ' WHERE ws.worker_profile_id=?') . ' ORDER BY pp.period_end DESC,ws.id DESC'
        );
        $stmt->execute($manage ? [] : [$workerId]);
        api_json_success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'GET' && $resource === 'review_queue') {
        if (!$approvalPolicy->canAccessQueue($userId)) {
            api_json_failure(403, 'permission_denied', 'Time-review permission and an applicable worker scope are required.');
        }
        $pending = $reviewQueue->pendingFor($userId);
        api_json_success([
            'data' => $pending,
            'meta' => [
                'pending_count' => count($pending),
                'pending_by_user' => $reviewQueue->pendingCountsByUser($userId),
                'recently_confirmed' => $reviewQueue->recentlyApprovedFor(
                    $userId,
                    max(1, min(250, (int)($_GET['recent_limit'] ?? 50)))
                ),
            ],
        ]);
    }
    if ($method === 'GET' && $resource === 'time_submissions') {
        $submissionId = trim((string)($_GET['submission_id'] ?? ''));
        $workerId = $activeWorkerId();
        $manage = $approvalPolicy->canAccessQueue($userId);
        if ($workerId <= 0 && !$manage) {
            api_json_failure(403, 'worker_profile_required', 'This account is not linked to an active worker profile.');
        }
        $sql = 'SELECT ts.*,wp.user_id,wp.display_name,pp.period_start,pp.period_end
                FROM time_submissions ts
                JOIN worker_profiles wp ON wp.id=ts.worker_profile_id
                JOIN pay_periods pp ON pp.id=ts.pay_period_id';
        $parameters = [];
        if ($submissionId !== '') {
            $sql .= ' WHERE ts.id=?';
            $parameters[] = $submissionId;
        } elseif (!$manage) {
            $sql .= ' WHERE ts.worker_profile_id=?';
            $parameters[] = $workerId;
        }
        $sql .= ' ORDER BY ts.submitted_at DESC,ts.submission_sequence DESC LIMIT 250';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $row): bool => (int)$row['worker_profile_id'] === $workerId
                || ($manage && $approvalPolicy->canReviewWorker($userId, (int)$row['user_id']))
        ));
        if ($submissionId !== '' && $rows === []) {
            api_json_failure(404, 'time_submission_not_found', 'The time submission was not found or is outside your review scope.');
        }
        if ($submissionId !== '') {
            $rows[0]['entries'] = $submissions->entriesForReview($submissionId);
        }
        api_json_success(['data' => $rows]);
    }
    if ($method === 'GET' && $resource === 'billing_allocations') {
        $requireBillingManager();
        $timeEntryId = trim((string)($_GET['time_entry_id'] ?? ''));
        $statement = $pdo->prepare(
            'SELECT a.* FROM work_time_billing_allocations a
             WHERE (?=\'\' OR a.time_entry_id=?)
             ORDER BY a.created_at DESC,a.id DESC LIMIT 250'
        );
        $statement->execute([$timeEntryId, $timeEntryId]);
        api_json_success(['data' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'GET' && $resource === 'earnings') {
        $workerId = $activeWorkerId();
        $manage = $canManage('workforce.statements.manage') || $canManage('employee_pay.manage');
        if ($workerId <= 0 && !$manage) {
            api_json_failure(403, 'worker_profile_required', 'This account is not linked to an active worker profile.');
        }
        $requestedWorkerId = (int)($_GET['worker_profile_id'] ?? 0);
        if (!$manage && $requestedWorkerId > 0 && $requestedWorkerId !== $workerId) {
            api_json_failure(403, 'permission_denied', 'You may view only your own earnings.');
        }
        $filterWorkerId = $manage ? $requestedWorkerId : $workerId;
        $sql = 'SELECT we.id,we.source_key,we.source_type,we.source_id,we.source_revision,we.worker_profile_id,
                       we.pay_period_id,we.status,we.method,we.quantity,we.rate,we.amount,we.currency,
                       we.eligible_at,we.approved_at,we.settled_at,we.created_at,wp.display_name
                FROM worker_earnings we JOIN worker_profiles wp ON wp.id=we.worker_profile_id';
        $parameters = [];
        if ($filterWorkerId > 0) {
            $sql .= ' WHERE we.worker_profile_id=?';
            $parameters[] = $filterWorkerId;
        }
        $sql .= ' ORDER BY we.created_at DESC,we.id DESC LIMIT 250';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        api_json_success(['data' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method !== 'POST') api_json_failure(405, 'method_not_allowed', 'Use POST for this operation.');

    if ($resource === 'time_submission' && $action === 'submit') {
        WorkforceCommandRegistry::require('submit-period', $method);
        $workerId = (int)($input['worker_profile_id'] ?? 0) ?: $requireWorker();
        $canManageWorker = $workerId !== $activeWorkerId();
        if ($canManageWorker) {
            $worker = $pdo->prepare('SELECT user_id FROM worker_profiles WHERE id=? AND status=\'active\' LIMIT 1');
            $worker->execute([$workerId]);
            $workerUserId = (int)($worker->fetchColumn() ?: 0);
            if ($workerUserId <= 0 || !$approvalPolicy->canReviewWorker($userId, $workerUserId)) {
                api_json_failure(403, 'permission_denied', 'You may not submit time for that worker.');
            }
        }
        $result = $submissions->submit(
            (int)($input['pay_period_id'] ?? 0),
            $workerId,
            $userId,
            $entryIds($input['time_entry_ids'] ?? $input['time_entry_id'] ?? []),
            isset($input['notes']) ? (string)$input['notes'] : null,
            $canManageWorker
        );
        api_json_success(['data' => $result, 'message' => 'Time submitted for review.'], 201);
    }

    if ($resource === 'time_review') {
        $entryId = trim((string)($input['time_entry_id'] ?? $input['entry_id'] ?? ''));
        $reason = (string)($input['reason'] ?? '');
        $decision = match ($action) {
            'approve', 'confirm' => 'confirmed',
            'reject', 'return' => 'returned',
            'void' => 'voided',
            default => null,
        };
        if ($decision === null) {
            api_json_failure(404, 'operation_not_found', 'The time-review operation was not found.');
        }
        $command = match ($decision) {
            'confirmed' => 'approve',
            'returned' => 'reject',
            'voided' => 'void',
        };
        WorkforceCommandRegistry::require($command, $method);
        $ownerSelfConfirmation = $decision === 'confirmed'
            && $approval->canSelfConfirmOwner($userId)
            && $approvalPolicy->isOwnerSelfAction($userId, $entryId);
        $administrativeSelfConfirmation = $decision === 'confirmed'
            && !$ownerSelfConfirmation
            && $approvalPolicy->canAdministrativelySelfConfirmEntry($userId, $entryId);
        if (!$ownerSelfConfirmation && !$administrativeSelfConfirmation) {
            $approvalPolicy->assertCanReviewEntry($userId, $entryId, $command);
        }

        $submissionStatement = $pdo->prepare(
            'SELECT current_submission_id,revision FROM work_time_entries WHERE id=? LIMIT 1'
        );
        $submissionStatement->execute([$entryId]);
        $submittedEntry = $submissionStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($decision === 'confirmed') {
            if ($ownerSelfConfirmation) {
                $approval->selfConfirmOwner($userId, $entryId);
            } elseif ($administrativeSelfConfirmation) {
                $approval->selfConfirmAdministrator($userId, $entryId);
            } else {
                $approval->approve($userId, $entryId);
            }
        } elseif ($decision === 'returned') {
            $approval->reject($userId, $entryId, $reason);
        } else {
            $approval->void($userId, $entryId, $reason);
        }
        if (!empty($submittedEntry['current_submission_id'])) {
            // ApprovalService owns the canonical lifecycle and normally records
            // this decision in its transaction. The fallback keeps API parity
            // during a rolling upgrade without writing lifecycle state here.
            $decisionStatement = $pdo->prepare(
                'SELECT decision FROM time_submission_entries
                 WHERE submission_id=? AND time_entry_id=? AND entry_revision=? LIMIT 1'
            );
            $decisionStatement->execute([
                (string)$submittedEntry['current_submission_id'],
                $entryId,
                (int)$submittedEntry['revision'],
            ]);
            if ((string)($decisionStatement->fetchColumn() ?: '') === 'pending') {
                $submissions->recordDecision(
                    (string)$submittedEntry['current_submission_id'],
                    $entryId,
                    (int)$submittedEntry['revision'],
                    $decision,
                    $userId,
                    $reason
                );
            }
        }
        api_json_success(['message' => 'Time-review decision recorded.']);
    }

    if ($resource === 'billing_allocation') {
        $requireBillingManager();
        if ($action === 'create') {
            $context = is_array($input['context'] ?? null) ? $input['context'] : [];
            foreach (['client_id','project_id','job_id','invoice_id','invoice_item_id'] as $contextKey) {
                if (array_key_exists($contextKey, $input) && !array_key_exists($contextKey, $context)) {
                    $context[$contextKey] = $input[$contextKey];
                }
            }
            $result = $billingAllocations->allocate(
                trim((string)($input['time_entry_id'] ?? '')),
                (int)($input['entry_revision'] ?? 0),
                trim((string)($input['treatment'] ?? 'undecided')),
                (int)($input['duration_seconds'] ?? 0),
                isset($input['rate']) ? (string)$input['rate'] : null,
                (string)($input['currency'] ?? 'USD'),
                $userId,
                $context,
                isset($input['idempotency_key']) ? (string)$input['idempotency_key'] : null
            );
            api_json_success(['data' => $result, 'message' => 'Client-billing allocation saved.'], 201);
        }
        if ($action === 'reverse') {
            $billingAllocations->reverse(
                (int)($input['allocation_id'] ?? 0),
                $userId,
                (string)($input['reason'] ?? '')
            );
            api_json_success(['message' => 'Client-billing allocation reversed.']);
        }
    }

    if ($resource === 'earning' && $action === 'approve') {
        WorkforceCommandRegistry::require('earning-approve', $method);
        if (!$canManage('workforce.statements.manage') && !$canManage('employee_pay.manage')) {
            api_json_failure(403, 'permission_denied', 'Earnings-management permission is required.');
        }
        $earnings->transition(
            trim((string)($input['earning_id'] ?? '')),
            'approved',
            $userId,
            isset($input['reason']) ? (string)$input['reason'] : null
        );
        api_json_success(['message' => 'Worker earning approved.']);
    }

    if ($resource === 'assignment') {
        $assignmentId = (int)($input['assignment_id'] ?? 0);
        $workerId = (int)($actor['worker_profile_id'] ?? 0);
        if ($assignmentId <= 0) throw new DomainException('Choose an assignment.');
        if (in_array($action, ['accept','decline','start','complete'], true)) {
            if ($workerId <= 0) api_json_failure(403, 'worker_profile_required', 'This account is not linked to a worker profile.');
            if ($action === 'accept') $planning->accept($assignmentId, $workerId);
            if ($action === 'decline') $planning->decline($assignmentId, $workerId, (string)($input['reason'] ?? ''));
            if ($action === 'start') $planning->start($assignmentId, $workerId);
            if ($action === 'complete') $planning->complete($assignmentId, $workerId);
            api_json_success(['message' => 'Assignment updated.']);
        }
        if (!$canManage('workforce.assignments.manage')) api_json_failure(403, 'permission_denied', 'Assignment management permission is required.');
        if ($action === 'offer') {
            $preview = $planning->offer($assignmentId, (int)($input['worker_profile_id'] ?? 0), $userId, is_array($input['compensation_override'] ?? null) ? $input['compensation_override'] : null);
            api_json_success(['data' => $preview]);
        }
        if ($action === 'eligible') {
            $preview = $planning->markEligible($assignmentId, is_array($input['context'] ?? null) ? $input['context'] : [], $userId);
            api_json_success(['data' => $preview]);
        }
        if ($action === 'approve') { $planning->approvePayable($assignmentId, $userId); api_json_success(['message' => 'Compensation approved.']); }
        if ($action === 'settle') { $planning->settle($assignmentId); api_json_success(['message' => 'Assignment settled.']); }
    }

    if ($resource === 'job' && $action === 'create') {
        if (!$canManage('jobs.edit') && !$canManage('jobs.create')) api_json_failure(403,'permission_denied','Job creation permission is required.');
        $clientId=(int)($input['client_id']??0);$projectId=(int)($input['project_id']??0)?:null;
        $client=$pdo->prepare('SELECT id,organization_id FROM clients WHERE id=? AND archived=0 AND deleted_at IS NULL');$client->execute([$clientId]);$client=$client->fetch(PDO::FETCH_ASSOC);
        if(!$client)throw new DomainException('Choose an active client.');
        if(!can_access_record($pdo,'clients',$clientId,$userId)
            && !$access->can($userId,'clients.search','client',$clientId)) api_json_failure(403,'client_scope_denied','Client is outside your scope.');
        if($projectId){$project=$pdo->prepare('SELECT 1 FROM projects WHERE id=? AND client_id=?');$project->execute([$projectId,$clientId]);if(!$project->fetchColumn())throw new DomainException('The Project does not belong to that client.');
            if(!can_access_record($pdo,'projects',$projectId,$userId)) api_json_failure(403,'project_scope_denied','Project is outside your scope.');}
        $code=project_next_code($pdo,$clientId);
        $pdo->prepare('INSERT INTO jobs (client_id,organization_id,project_id,job_code,status,notes,created_by) VALUES (?,?,?,?,"not_started",?,?)')->execute([$clientId,$client['organization_id']?:null,$projectId,$code,trim((string)($input['notes']??''))?:null,$userId]);
        api_json_success(['data'=>['id'=>(int)$pdo->lastInsertId(),'job_code'=>$code]],201);
    }

    if ($resource === 'pay_period') {
        $periodId = (int)($input['pay_period_id'] ?? 0);
        if ($action === 'submit') {
            $workerId = (int)($actor['worker_profile_id'] ?? 0);
            if ($workerId <= 0) api_json_failure(403, 'worker_profile_required', 'This account is not linked to a worker profile.');
            $periods->submit($periodId, $workerId, (string)($input['notes'] ?? ''));
            api_json_success(['message' => 'Period submitted.']);
        }
        if (!$canManage('workforce.pay_periods.manage')) api_json_failure(403, 'permission_denied', 'Pay-period management permission is required.');
        if ($action === 'close') {
            $result = $periods->close($periodId, $userId, !empty($input['force']));
            api_json_success(['data' => $result], $result['closed'] ? 200 : 409);
        }
        if ($action === 'settle_statement') {
            $periods->settleStatement((int)($input['statement_id'] ?? 0));
            api_json_success(['message' => 'Statement settled.']);
        }
    }

    if($resource==='adjustment'){
        if(!$canManage('workforce.statements.manage'))api_json_failure(403,'permission_denied','Statement management permission is required.');
        if($action==='create'){$id=$periods->recordAdjustment((int)($input['worker_profile_id']??0),(int)($input['pay_period_id']??0),(string)($input['adjustment_type']??''),(float)($input['amount']??0),(string)($input['reason']??''),!empty($input['source_assignment_id'])?(int)$input['source_assignment_id']:null,$userId);api_json_success(['data'=>['id'=>$id]],201);}
        if($action==='review'){$periods->reviewAdjustment((int)($input['adjustment_id']??0),$userId);api_json_success(['message'=>'Adjustment reviewed.']);}
    }

    api_json_failure(404, 'operation_not_found', 'The workforce operation was not found.');
} catch (DomainException $error) {
    api_json_failure(422, 'invalid_workforce_operation', $error->getMessage());
} catch (PDOException $error) {
    error_log('[WorkforceV1][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(503, 'schema_out_of_date', 'The workforce foundation is unavailable until the latest migration is applied.');
} catch (Throwable $error) {
    error_log('[WorkforceV1][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(500, 'internal_error', 'The workforce request could not be completed.');
}
