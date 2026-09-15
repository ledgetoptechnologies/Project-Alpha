<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use RuntimeException;

/** Shared browser/API lifecycle authority. The caller owns the transaction. */
final class ProjectLifecycleService
{
    private readonly \Closure $authorize;
    private readonly ?\Closure $auditOverride;

    public function __construct(
        private readonly PDO $pdo,
        ?callable $authorizer = null,
        ?callable $auditor = null,
    ) {
        $this->authorize = $authorizer
            ? \Closure::fromCallable($authorizer)
            : static function (PDO $pdo, int $projectId): void {
                if (!function_exists('require_record_ownership')) {
                    throw new RuntimeException('Project ownership enforcement is unavailable.');
                }
                \require_record_ownership($pdo, 'projects', $projectId);
            };
        $this->auditOverride = $auditor ? \Closure::fromCallable($auditor) : null;
    }

    public function apply(
        int $projectId,
        string $action,
        int $actorUserId = 0,
        ?int $applicationPk = null,
        ?string $commandId = null,
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Project lifecycle writes require an active transaction.');
        }
        if (!in_array($action, ['not_started','active','complete','cancel','archive','restore'], true)) {
            throw new DomainException('Invalid Project lifecycle action.');
        }
        $revisionService = new ProjectRevisionService($this->pdo);
        $project = $revisionService->lockedProject($projectId);
        ($this->authorize)($this->pdo, $projectId);

        if ($action === 'archive' || $action === 'restore') {
            $isArchived = $project['archived_at'] !== null;
            if (($action === 'archive') === $isArchived) {
                return ['transitioned' => false, 'project' => $project, 'blockers' => []];
            }
            $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'UTC_TIMESTAMP(6)' : 'CURRENT_TIMESTAMP';
            $this->pdo->prepare('UPDATE projects SET archived_at=' . ($action === 'archive' ? $now : 'NULL') . ',updated_at=' . $now . ' WHERE id=?')
                ->execute([$projectId]);
            $project = $revisionService->advance($projectId, $action, $applicationPk, $commandId, $actorUserId ?: null);
            $this->audit($project, 'project.' . $action, ['revision' => (string)$project['revision']], $actorUserId);
            return ['transitioned' => true, 'project' => $project, 'blockers' => []];
        }

        if ($project['archived_at'] !== null) {
            throw new DomainException('Restore the Project before changing its status.');
        }
        $target = match ($action) {
            'complete' => 'completed',
            'cancel' => 'cancelled',
            default => $action,
        };
        $guard = new ProjectCloseGuardService(
            $this->pdo,
            static function (): void {},
            $this->auditOverride,
        );
        $result = $guard->transition($projectId, $target, $actorUserId);
        if (!$result['transitioned']) return $result;
        $historyAction = $action === 'complete' || $action === 'cancel' ? $action : 'status';
        $result['project'] = $revisionService->advance($projectId, $historyAction, $applicationPk, $commandId, $actorUserId ?: null);
        return $result;
    }

    private function audit(array $project, string $action, array $details, int $actorUserId): void
    {
        if ($this->auditOverride !== null) {
            ($this->auditOverride)($this->pdo, $project, $action, $details, $actorUserId);
            return;
        }
        // API callers are applications rather than PA users; project_changes is
        // their authoritative audit record and no synthetic user is invented.
        if ($actorUserId < 1) return;
        $this->pdo->prepare(
            'INSERT INTO system_audit
             (user_id,organization_id,action,entity_type,entity_id,details,ip_address,user_agent)
             VALUES(?,?,?,?,?,?,NULL,NULL)'
        )->execute([
            $actorUserId,
            !empty($project['organization_id']) ? (int)$project['organization_id'] : null,
            $action,
            'project',
            (int)$project['id'],
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}
