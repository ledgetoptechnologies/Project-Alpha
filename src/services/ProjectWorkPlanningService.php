<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

final class ProjectWorkPlanningService
{
    public function addTeamMember(PDO $pdo, int $projectId, int $userId, int $actorUserId, ?bool &$changed = null): int
    {
        $changed = false;
        $this->requireProject($pdo, $projectId);
        $this->requireActiveUser($pdo, $userId);
        $existing = $pdo->prepare('SELECT id,CASE WHEN ends_at IS NULL OR ends_at>CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS active FROM project_assignments WHERE project_id=? AND user_id=? LIMIT 1');
        $existing->execute([$projectId, $userId]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC) ?: [];
        $id = (int)($existingRow['id'] ?? 0);
        if ($id > 0) {
            if (!empty($existingRow['active'])) {
                return $id;
            }
            $pdo->prepare('UPDATE project_assignments SET ends_at=NULL,assigned_at=CURRENT_TIMESTAMP,created_by=? WHERE id=?')
                ->execute([$actorUserId ?: null, $id]);
            $changed = true;
            return $id;
        }
        $pdo->prepare('INSERT INTO project_assignments (project_id,user_id,created_by) VALUES (?,?,?)')
            ->execute([$projectId, $userId, $actorUserId ?: null]);
        $changed = true;
        return (int)$pdo->lastInsertId();
    }

    public function endTeamMember(PDO $pdo, int $projectId, int $userId): void
    {
        $manager = $pdo->prepare('SELECT 1 FROM projects WHERE id=? AND manager_user_id=? LIMIT 1');
        $manager->execute([$projectId, $userId]);
        if ($manager->fetchColumn()) {
            throw new DomainException('Choose a different Project Manager before ending this team membership.');
        }
        $openOperation = $pdo->prepare(
            "SELECT 1 FROM operation_assignments oa JOIN operations o ON o.id=oa.operation_id
             WHERE o.project_id=? AND oa.user_id=? AND o.status NOT IN ('completed','cancelled') LIMIT 1"
        );
        $openOperation->execute([$projectId, $userId]);
        $openTask = $pdo->prepare(
            "SELECT 1 FROM task_assignments ta JOIN tasks t ON t.id=ta.task_id
             WHERE t.project_id=? AND ta.user_id=? AND t.status NOT IN ('completed','cancelled') LIMIT 1"
        );
        $openTask->execute([$projectId, $userId]);
        if ($openOperation->fetchColumn() || $openTask->fetchColumn()) {
            throw new DomainException('Reassign or complete this worker\'s open Operations and Tasks before removing them from the Project Team.');
        }
        $pdo->prepare('UPDATE project_assignments SET ends_at=CURRENT_TIMESTAMP WHERE project_id=? AND user_id=? AND (ends_at IS NULL OR ends_at>CURRENT_TIMESTAMP)')
            ->execute([$projectId, $userId]);
    }

    public function setProjectBusinessUnit(PDO $pdo, int $projectId, int $businessUnitId): void
    {
        $this->requireProject($pdo, $projectId);
        if ($businessUnitId > 0) {
            $unit = $pdo->prepare('SELECT 1 FROM business_units WHERE id=? AND is_active=1');
            $unit->execute([$businessUnitId]);
            if (!$unit->fetchColumn()) {
                throw new DomainException('Business unit is unavailable.');
            }
        }
        $pdo->prepare('UPDATE projects SET business_unit_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$businessUnitId ?: null, $projectId]);
        $pdo->prepare('UPDATE operations SET business_unit_id=? WHERE project_id=?')->execute([$businessUnitId ?: null, $projectId]);
        $pdo->prepare('UPDATE tasks SET business_unit_id=? WHERE project_id=?')->execute([$businessUnitId ?: null, $projectId]);
    }

    public function setProjectManager(PDO $pdo, int $projectId, int $managerUserId, int $actorUserId): void
    {
        $this->requireProject($pdo, $projectId);
        if ($managerUserId > 0) {
            $this->requireActiveUser($pdo, $managerUserId);
        }
        $pdo->prepare('UPDATE projects SET manager_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$managerUserId ?: null, $projectId]);
        if ($managerUserId > 0) {
            $this->addTeamMember($pdo, $projectId, $managerUserId, $actorUserId);
        }
    }

    public function requireAvailableManager(PDO $pdo, int $managerUserId): void
    {
        if ($managerUserId > 0) {
            $this->requireActiveUser($pdo, $managerUserId);
        }
    }

    public function primaryBusinessUnitForUser(PDO $pdo, int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }
        $statement = $pdo->prepare(
            'SELECT bum.business_unit_id
             FROM business_unit_memberships bum
             JOIN business_units bu ON bu.id=bum.business_unit_id AND bu.is_active=1
             WHERE bum.user_id=? AND bum.is_primary=1
               AND (bum.ended_at IS NULL OR bum.ended_at>CURRENT_TIMESTAMP)
             ORDER BY bum.id DESC LIMIT 1'
        );
        $statement->execute([$userId]);
        return (int)($statement->fetchColumn() ?: 0);
    }

    private function requireProject(PDO $pdo, int $projectId): void
    {
        $visibility = ProjectLifecycleSchema::visibility($pdo, '');
        $statement = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND status<>'cancelled' AND {$visibility}");
        $statement->execute([$projectId]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('Project is unavailable.');
        }
    }

    private function requireActiveUser(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare('SELECT 1 FROM users WHERE id=? AND is_disabled=0 AND deleted_at IS NULL');
        $statement->execute([$userId]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('User is unavailable.');
        }
    }
}
