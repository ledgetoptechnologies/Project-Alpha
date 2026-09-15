<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use RuntimeException;

/** Records the permanent, per-Project revision stream inside the caller's transaction. */
final class ProjectRevisionService
{
    public const MAX_REVISION = '9223372036854775807';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function initialize(
        int $projectId,
        string $action = 'create',
        ?int $applicationPk = null,
        ?string $commandId = null,
        ?int $actorUserId = null,
    ): array {
        $project = $this->lockedProject($projectId);
        if ((string)($project['revision'] ?? '') !== '1') {
            throw new RuntimeException('New Project revision is invalid.');
        }
        $insert = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT IGNORE INTO project_retention_guards(project_public_id) VALUES(?)'
            : 'INSERT OR IGNORE INTO project_retention_guards(project_public_id) VALUES(?)';
        $this->pdo->prepare($insert)->execute([$project['public_id']]);
        $this->insertChange($project, $action, $applicationPk, $commandId, $actorUserId);
        return $project;
    }

    public function advance(
        int $projectId,
        string $action,
        ?int $applicationPk = null,
        ?string $commandId = null,
        ?int $actorUserId = null,
    ): array {
        $project = $this->lockedProject($projectId);
        $revision = (string)($project['revision'] ?? '');
        if (!self::positiveInteger($revision) || $revision === self::MAX_REVISION) {
            throw new RuntimeException('Project revision is exhausted.');
        }
        $next = (string)((int)$revision + 1);
        $this->pdo->prepare('UPDATE projects SET revision=?,updated_at=' . $this->nowSql() . ' WHERE id=?')
            ->execute([$next, $projectId]);
        $project['revision'] = $next;
        $this->insertChange($project, $action, $applicationPk, $commandId, $actorUserId);
        return $project;
    }

    public function lockedProject(int $projectId): array
    {
        if (!$this->pdo->inTransaction() || $projectId < 1) {
            throw new RuntimeException('Project revision writes require an active transaction.');
        }
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare('SELECT * FROM projects WHERE id=?' . $lock);
        $statement->execute([$projectId]);
        $project = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            throw new DomainException('Project not found.');
        }
        if (!empty($project['client_id'])) {
            $related = $this->pdo->prepare('SELECT public_id FROM clients WHERE id=?');
            $related->execute([$project['client_id']]);
            $project['client_public_id'] = $related->fetchColumn() ?: null;
        } else {
            $project['client_public_id'] = null;
        }
        if (!empty($project['organization_id'])) {
            $related = $this->pdo->prepare('SELECT public_id FROM organizations WHERE id=?');
            $related->execute([$project['organization_id']]);
            $project['organization_public_id'] = $related->fetchColumn() ?: null;
        } else {
            $project['organization_public_id'] = null;
        }
        return $project;
    }

    public static function projection(array $project): array
    {
        $status = (string)($project['status'] ?? '');
        if (!in_array($status, ['not_started', 'active', 'completed', 'cancelled'], true)) {
            throw new DomainException('Project lifecycle state is invalid.');
        }
        return [
            'publicId' => (string)$project['public_id'],
            'name' => (string)$project['name'],
            'description' => self::nullableString($project['description'] ?? null),
            'status' => $status,
            'completedAt' => self::nullableString($project['completed_at'] ?? null),
            'archivedAt' => self::nullableString($project['archived_at'] ?? null),
            'estimatedStart' => self::nullableString($project['estimated_start'] ?? null),
            'estimatedEnd' => self::nullableString($project['estimated_end'] ?? null),
            'clientPublicId' => self::nullableString($project['client_public_id'] ?? null),
            'organizationPublicId' => self::nullableString($project['organization_public_id'] ?? null),
        ];
    }

    public static function projectionHash(array $project): string
    {
        return hash('sha256', json_encode(self::projection($project), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function derivedOverdue(array $project, ?string $today = null): bool
    {
        $status = (string)($project['status'] ?? '');
        $end = self::nullableString($project['estimated_end'] ?? null);
        return in_array($status, ['not_started', 'active'], true)
            && ($project['archived_at'] ?? null) === null
            && $end !== null
            && $end < ($today ?? gmdate('Y-m-d'));
    }

    public static function positiveInteger(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, self::MAX_REVISION) <= 0);
    }

    private function insertChange(array $project, string $action, ?int $applicationPk, ?string $commandId, ?int $actorUserId): void
    {
        if (!in_array($action, ['baseline','create','update','status','complete','cancel','archive','restore'], true)) {
            throw new DomainException('Project change action is invalid.');
        }
        $this->pdo->prepare(
            'INSERT INTO project_changes
             (project_public_id,revision,action_name,projection_sha256,application_pk,command_id,actor_user_id)
             VALUES(?,?,?,?,?,?,?)'
        )->execute([
            $project['public_id'], $project['revision'], $action, self::projectionHash($project),
            $applicationPk, $commandId, $actorUserId && $actorUserId > 0 ? $actorUserId : null,
        ]);
    }

    private function nowSql(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'UTC_TIMESTAMP(6)' : 'CURRENT_TIMESTAMP';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
