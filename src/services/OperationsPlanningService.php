<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final class OperationsPlanningService
{
    public const OPERATION_STATUSES = ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'];
    public const TASK_STATUSES = ['todo', 'in_progress', 'blocked', 'completed', 'cancelled'];

    /** @param list<int> $assignedUserIds */
    public function saveOperation(PDO $pdo, array $input, array $assignedUserIds, int $actorUserId): int
    {
        $id = max(0, (int)($input['id'] ?? 0));
        $projectId = (int)($input['project_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $status = (string)($input['status'] ?? 'draft');
        $startAt = $this->dateTimeOrNull((string)($input['scheduled_start_at'] ?? ''));
        $endAt = $this->dateTimeOrNull((string)($input['scheduled_end_at'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $assignedUserIds = array_values(array_unique(array_filter(array_map('intval', $assignedUserIds), static fn(int $value): bool => $value > 0)));

        if ($projectId < 1 || $title === '') {
            throw new DomainException('Project and operation title are required.');
        }
        if (!in_array($status, self::OPERATION_STATUSES, true)) {
            throw new DomainException('Invalid operation status.');
        }
        if ($startAt !== null && $endAt !== null && $endAt < $startAt) {
            throw new DomainException('Operation end time cannot be before its start time.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $businessUnitId = $this->projectBusinessUnitId($pdo, $projectId);
            $this->requireUsers($pdo, $assignedUserIds);
            $this->requireProjectTeamMembers($pdo, $projectId, $assignedUserIds);

            if ($id > 0) {
                $existingProject = $pdo->prepare('SELECT project_id FROM operations WHERE id=?');
                $existingProject->execute([$id]);
                if ((int)($existingProject->fetchColumn() ?: 0) !== $projectId) {
                    throw new DomainException('Operation does not belong to this Project.');
                }
                $statement = $pdo->prepare(
                    'UPDATE operations SET project_id=?,business_unit_id=?,title=?,status=?,scheduled_start_at=?,scheduled_end_at=?,location=?,notes=? WHERE id=?'
                );
                $statement->execute([$projectId, $businessUnitId ?: null, $title, $status, $startAt, $endAt, $location ?: null, $notes ?: null, $id]);
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM operations WHERE id=?');
                    $exists->execute([$id]);
                    if (!$exists->fetchColumn()) {
                        throw new DomainException('Operation not found.');
                    }
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO operations (project_id,business_unit_id,title,status,scheduled_start_at,scheduled_end_at,location,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$projectId, $businessUnitId ?: null, $title, $status, $startAt, $endAt, $location ?: null, $notes ?: null, $actorUserId ?: null]);
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM operation_assignments WHERE operation_id=?')->execute([$id]);
            $assignment = $pdo->prepare('INSERT INTO operation_assignments (operation_id,user_id,assigned_by) VALUES (?,?,?)');
            foreach ($assignedUserIds as $userId) {
                $assignment->execute([$id, $userId, $actorUserId ?: null]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int>|null $assignedUserIds */
    public function saveTask(PDO $pdo, array $input, int $actorUserId, ?array $assignedUserIds = null): int
    {
        $id = max(0, (int)($input['id'] ?? 0));
        $operationId = (int)($input['operation_id'] ?? 0);
        $projectId = (int)($input['project_id'] ?? 0);
        $legacyAssigneeUserId = (int)($input['assignee_user_id'] ?? 0);
        $assignedUserIds ??= (array)($input['assigned_user_ids'] ?? ($legacyAssigneeUserId > 0 ? [$legacyAssigneeUserId] : []));
        $assignedUserIds = array_values(array_unique(array_filter(array_map('intval', $assignedUserIds), static fn(int $value): bool => $value > 0)));
        $title = trim((string)($input['title'] ?? ''));
        $status = (string)($input['status'] ?? 'todo');
        $dueAt = $this->dateTimeOrNull((string)($input['due_at'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));

        if ($projectId < 1 || $title === '') {
            throw new DomainException('Project and task title are required.');
        }
        if (!in_array($status, self::TASK_STATUSES, true)) {
            throw new DomainException('Invalid task status.');
        }

        $businessUnitId = $this->projectBusinessUnitId($pdo, $projectId);
        $this->requireUsers($pdo, $assignedUserIds);
        $this->requireProjectTeamMembers($pdo, $projectId, $assignedUserIds);
        if ($operationId > 0) {
            $operation = $pdo->prepare('SELECT project_id FROM operations WHERE id=?');
            $operation->execute([$operationId]);
            $operationProjectId = (int)($operation->fetchColumn() ?: 0);
            if ($operationProjectId !== $projectId) {
                throw new DomainException('The selected operation does not belong to this project.');
            }
        }

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $primaryAssignee = $assignedUserIds[0] ?? null;
            if ($id > 0) {
                $existingProject = $pdo->prepare('SELECT project_id FROM tasks WHERE id=?');
                $existingProject->execute([$id]);
                if ((int)($existingProject->fetchColumn() ?: 0) !== $projectId) {
                    throw new DomainException('Task does not belong to this Project.');
                }
                $statement = $pdo->prepare(
                    'UPDATE tasks SET operation_id=?,project_id=?,business_unit_id=?,assignee_user_id=?,title=?,status=?,due_at=?,notes=? WHERE id=?'
                );
                $statement->execute([$operationId ?: null, $projectId, $businessUnitId ?: null, $primaryAssignee, $title, $status, $dueAt, $notes ?: null, $id]);
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM tasks WHERE id=?');
                    $exists->execute([$id]);
                    if (!$exists->fetchColumn()) {
                        throw new DomainException('Task not found.');
                    }
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO tasks (operation_id,project_id,business_unit_id,assignee_user_id,title,status,due_at,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$operationId ?: null, $projectId, $businessUnitId ?: null, $primaryAssignee, $title, $status, $dueAt, $notes ?: null, $actorUserId ?: null]);
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM task_assignments WHERE task_id=?')->execute([$id]);
            $assignment = $pdo->prepare('INSERT INTO task_assignments (task_id,user_id,assigned_by) VALUES (?,?,?)');
            foreach ($assignedUserIds as $userId) {
                $assignment->execute([$id, $userId, $actorUserId ?: null]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function projectBusinessUnitId(PDO $pdo, int $projectId): int
    {
        $visibility = ProjectLifecycleSchema::visibility($pdo, '');
        $project = $pdo->prepare("SELECT business_unit_id FROM projects WHERE id=? AND status NOT IN ('cancelled') AND {$visibility}");
        $project->execute([$projectId]);
        $value = $project->fetchColumn();
        if ($value === false) {
            throw new DomainException('Project is unavailable.');
        }
        return (int)($value ?: 0);
    }

    /** @param list<int> $userIds */
    private function requireUsers(PDO $pdo, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND is_disabled=0 AND deleted_at IS NULL");
        $statement->execute($userIds);
        $valid = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($valid);
        sort($userIds);
        if ($valid !== $userIds) {
            throw new DomainException('One or more assigned users are unavailable.');
        }
    }

    /** @param list<int> $userIds */
    private function requireProjectTeamMembers(PDO $pdo, int $projectId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $pdo->prepare(
            "SELECT user_id FROM project_assignments
             WHERE project_id=? AND user_id IN ($placeholders)
               AND (ends_at IS NULL OR ends_at>CURRENT_TIMESTAMP)"
        );
        $statement->execute(array_merge([$projectId], $userIds));
        $valid = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($valid);
        $expected = $userIds;
        sort($expected);
        if ($valid !== $expected) {
            throw new DomainException('Assign workers to the Project Team before assigning Operations or Tasks.');
        }
    }

    private function dateTimeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
