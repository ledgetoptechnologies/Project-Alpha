<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

/**
 * Owns the authoritative Project Alpha service-assignment records.
 *
 * Callers supply internal database IDs. Public subject/service IDs and every
 * affected integration profile are resolved again under the same transaction;
 * a browser can therefore never select a projection profile or wire identity.
 * Assignments are factual business records and do not grant portal access.
 */
final class PortalServiceAssignmentManager
{
    /**
     * Reconcile assignment streams for the exact union of roots affected by
     * an authoritative ownership mutation. Callers must pass both the locked
     * pre-mutation roots and the post-mutation roots so the former profile
     * receives tombstones before the destination profile receives upserts.
     *
     * @param list<array{root_type:string,root_public_id:string}> $roots
     * @return array{ids:list<int>,summaries:list<array<string,mixed>>}
     */
    public function reconcileRoots(PDO $pdo, array $roots): array
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('service-assignment-reconciliation-requires-transaction');
        }
        $subjects = [];
        foreach ($roots as $root) {
            $rootType = (string)($root['root_type'] ?? '');
            $rootPublicId = (string)($root['root_public_id'] ?? '');
            if (!in_array($rootType, ['organization', 'standalone_client'], true)
                || !self::safeId($rootPublicId)) {
                throw new DomainException('service-assignment-root-invalid');
            }
            $subjects[$rootType . '|' . $rootPublicId] = [
                'type' => $rootType,
                'publicId' => $rootPublicId,
                'rootType' => $rootType,
                'rootPublicId' => $rootPublicId,
            ];
        }
        return $this->queueAffectedProfiles($pdo, array_values($subjects));
    }

    /** @return array{assignment:array<string,mixed>,projectionProfiles:list<int>,projectionSummaries:list<array<string,mixed>>} */
    public function create(PDO $pdo, string $subjectType, int $subjectId, int $itemLibraryId,
        ?string $effectiveFrom, ?string $effectiveUntil, int $actorId): array
    {
        return $this->transaction($pdo, function () use ($pdo, $subjectType, $subjectId, $itemLibraryId,
            $effectiveFrom, $effectiveUntil, $actorId): array {
            $this->requireActor($actorId);
            $subject = $this->resolveSubject($pdo, $subjectType, $subjectId, true);
            $service = $this->resolveService($pdo, $itemLibraryId, true);
            [$from, $until] = $this->window($effectiveFrom, $effectiveUntil);
            $this->requireUnique($pdo, $subject['type'], $subject['publicId'], $service['publicId']);
            $now = self::now();
            $publicId = self::publicId();
            $statement = $pdo->prepare(
                'INSERT INTO portal_service_assignments
                 (public_id,subject_type,subject_public_id,service_public_id,active,effective_from,effective_until,
                  deleted_at,created_by,updated_by,created_at,updated_at)
                 VALUES (?,?,?,?,1,?,?,NULL,?,?,?,?)'
            );
            $statement->execute([$publicId, $subject['type'], $subject['publicId'], $service['publicId'],
                $from, $until, $actorId, $actorId, $now, $now]);
            $assignmentId = (int)$pdo->lastInsertId();
            $projection = $this->queueAffectedProfiles($pdo, [$subject]);
            return $this->result($pdo, $assignmentId, $projection);
        });
    }

    /** @return array{assignment:array<string,mixed>,projectionProfiles:list<int>,projectionSummaries:list<array<string,mixed>>} */
    public function update(PDO $pdo, int $assignmentId, int $itemLibraryId,
        ?string $effectiveFrom, ?string $effectiveUntil, int $actorId): array
    {
        return $this->transaction($pdo, function () use ($pdo, $assignmentId,
            $itemLibraryId, $effectiveFrom, $effectiveUntil, $actorId): array {
            $this->requireActor($actorId);
            $existing = $this->assignment($pdo, $assignmentId, true, false);
            $subject = $this->resolvePublicSubject($pdo, (string)$existing['subject_type'],
                (string)$existing['subject_public_id'], true);
            $service = $this->resolveService($pdo, $itemLibraryId, true);
            [$from, $until] = $this->window($effectiveFrom, $effectiveUntil);
            $this->requireUnique($pdo, $subject['type'], $subject['publicId'], $service['publicId'], $assignmentId);
            $pdo->prepare(
                'UPDATE portal_service_assignments
                 SET service_public_id=?,effective_from=?,effective_until=?,updated_by=?,updated_at=?
                 WHERE id=? AND deleted_at IS NULL'
            )->execute([$service['publicId'], $from, $until, $actorId, self::now(), $assignmentId]);
            $projection = $this->queueAffectedProfiles($pdo, [$subject]);
            return $this->result($pdo, $assignmentId, $projection);
        });
    }

    /** @return array{assignment:array<string,mixed>,projectionProfiles:list<int>,projectionSummaries:list<array<string,mixed>>} */
    public function deactivate(PDO $pdo, int $assignmentId, int $actorId): array
    {
        return $this->setActive($pdo, $assignmentId, false, $actorId);
    }

    /** @return array{assignment:array<string,mixed>,projectionProfiles:list<int>,projectionSummaries:list<array<string,mixed>>} */
    public function reactivate(PDO $pdo, int $assignmentId, int $actorId): array
    {
        return $this->setActive($pdo, $assignmentId, true, $actorId);
    }

    /** @return array{assignment:array<string,mixed>,projectionProfiles:list<int>,projectionSummaries:list<array<string,mixed>>} */
    public function softDelete(PDO $pdo, int $assignmentId, int $actorId): array
    {
        return $this->transaction($pdo, function () use ($pdo, $assignmentId, $actorId): array {
            $this->requireActor($actorId);
            $existing = $this->assignment($pdo, $assignmentId, true, false);
            $subject = $this->resolvePublicSubject($pdo, (string)$existing['subject_type'],
                (string)$existing['subject_public_id'], true);
            $now = self::now();
            $pdo->prepare(
                'UPDATE portal_service_assignments SET active=0,deleted_at=?,updated_by=?,updated_at=?
                 WHERE id=? AND deleted_at IS NULL'
            )->execute([$now, $actorId, $now, $assignmentId]);
            $projection = $this->queueAffectedProfiles($pdo, [$subject]);
            return $this->result($pdo, $assignmentId, $projection, true);
        });
    }

    /** @return list<array<string,mixed>> */
    public function listForSubject(PDO $pdo, string $subjectType, int $subjectId): array
    {
        $subject = $this->resolveSubject($pdo, $subjectType, $subjectId, false);
        $statement = $pdo->prepare(
            "SELECT assignment.*,service.id item_library_id,service.item_name service_name,
                    CASE WHEN service.portal_requestable=1 AND service.is_active=1 AND service.entry_type='service' THEN 1 ELSE 0 END service_available
             FROM portal_service_assignments assignment
             LEFT JOIN item_library service ON service.portal_public_id=assignment.service_public_id
             WHERE assignment.subject_type=? AND assignment.subject_public_id=? AND assignment.deleted_at IS NULL
             ORDER BY assignment.active DESC,assignment.public_id"
        );
        $statement->execute([$subject['type'], $subject['publicId']]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{assignment:array<string,mixed>,projectionProfiles:list<int>,projectionSummaries:list<array<string,mixed>>} */
    private function setActive(PDO $pdo, int $assignmentId, bool $active, int $actorId): array
    {
        return $this->transaction($pdo, function () use ($pdo, $assignmentId, $active, $actorId): array {
            $this->requireActor($actorId);
            $existing = $this->assignment($pdo, $assignmentId, true, false);
            $subject = $this->resolvePublicSubject($pdo, (string)$existing['subject_type'],
                (string)$existing['subject_public_id'], true);
            if ($active) {
                // Revalidate the service before making an old assignment live.
                $this->resolvePublicService($pdo, (string)$existing['service_public_id'], true);
                $this->requireUnique($pdo, (string)$existing['subject_type'],
                    (string)$existing['subject_public_id'], (string)$existing['service_public_id'], $assignmentId);
            }
            $pdo->prepare(
                'UPDATE portal_service_assignments SET active=?,updated_by=?,updated_at=?
                 WHERE id=? AND deleted_at IS NULL'
            )->execute([$active ? 1 : 0, $actorId, self::now(), $assignmentId]);
            $projection = $this->queueAffectedProfiles($pdo, [$subject]);
            return $this->result($pdo, $assignmentId, $projection);
        });
    }

    /** @param list<array{type:string,publicId:string,rootType:string,rootPublicId:string}> $subjects
     *  @return array{ids:list<int>,summaries:list<array<string,mixed>>} */
    private function queueAffectedProfiles(PDO $pdo, array $subjects): array
    {
        $roots = [];
        foreach ($subjects as $subject) $roots[$subject['rootType'] . '|' . $subject['rootPublicId']] = $subject;
        $profiles = [];
        $statement = $pdo->prepare(
            'SELECT DISTINCT profile.id
             FROM portal_integration_profiles profile
             JOIN portal_integration_profile_workspaces profile_workspace
               ON profile_workspace.profile_id=profile.id AND profile_workspace.active=1
             JOIN portal_v2_workspaces workspace
               ON workspace.id=profile_workspace.workspace_id AND workspace.active=1
             WHERE profile.enabled=1 AND profile.service_assignment_projection_enabled=1 AND profile.delivery_enabled=1
               AND workspace.root_type=? AND workspace.root_public_id=?
             ORDER BY profile.id'
        );
        foreach ($roots as $root) {
            $statement->execute([$root['rootType'], $root['rootPublicId']]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $profileId) $profiles[(int)$profileId] = true;
        }
        // Preserve caller root order. Ownership mutations pass locked old roots
        // before current roots, so removals are queued before destination
        // upserts even when the destination profile has a lower database ID.
        $summaries = [];
        $projection = new PortalServiceAssignmentProjectionService();
        foreach (array_keys($profiles) as $profileId) {
            $summaries[] = $projection->queueChanges($pdo, ['id' => $profileId]);
        }
        return ['ids' => array_keys($profiles), 'summaries' => $summaries];
    }

    /** @return array{type:string,publicId:string,rootType:string,rootPublicId:string} */
    private function resolveSubject(PDO $pdo, string $subjectType, int $subjectId, bool $lock): array
    {
        if ($subjectId < 1) throw new DomainException('service-assignment-subject-invalid');
        $suffix = $lock ? $this->lockSuffix($pdo) : '';
        $projectVisibility = ProjectLifecycleSchema::visibility($pdo, 'project', true);
        $query = match ($subjectType) {
            'organization' => 'SELECT public_id,NULL organization_id,NULL client_id FROM organizations WHERE id=?',
            'department' => 'SELECT department.public_id,department.organization_id,NULL client_id
                FROM organization_departments department WHERE department.id=?',
            'client', 'standalone_client' => 'SELECT client.public_id,client.organization_id,NULL client_id
                FROM clients client WHERE client.id=? AND client.archived=0 AND client.deleted_at IS NULL',
            'project' => "SELECT project.public_id,project.organization_id,project.client_id
                FROM projects project WHERE project.id=? AND project.status<>'cancelled' AND {$projectVisibility}",
            default => throw new DomainException('service-assignment-subject-invalid'),
        };
        $statement = $pdo->prepare($query . $suffix);
        $statement->execute([$subjectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('service-assignment-subject-unavailable');
        if ($subjectType === 'standalone_client' && $row['organization_id'] !== null) {
            throw new DomainException('service-assignment-subject-invalid');
        }
        // A standalone client has one canonical wire identity. Normalizing the
        // generic client alias here prevents a forged form from creating the
        // same assignment once as client and again as standalone_client.
        if ($subjectType === 'client' && $row['organization_id'] === null) {
            $subjectType = 'standalone_client';
        }
        return $this->subjectRoot($pdo, $subjectType, (string)$row['public_id'],
            $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
            $row['client_id'] !== null ? (int)$row['client_id'] : null, $lock);
    }

    /** @return array{type:string,publicId:string,rootType:string,rootPublicId:string} */
    private function resolvePublicSubject(PDO $pdo, string $subjectType, string $publicId, bool $lock): array
    {
        if (!self::safeId($publicId)) throw new DomainException('service-assignment-subject-invalid');
        $table = match ($subjectType) {
            'organization' => 'organizations', 'department' => 'organization_departments',
            'client', 'standalone_client' => 'clients', 'project' => 'projects',
            default => throw new DomainException('service-assignment-subject-invalid'),
        };
        $statement = $pdo->prepare("SELECT id FROM {$table} WHERE public_id=?" . ($lock ? $this->lockSuffix($pdo) : ''));
        $statement->execute([$publicId]);
        $id = $statement->fetchColumn();
        if ($id === false) throw new DomainException('service-assignment-subject-unavailable');
        return $this->resolveSubject($pdo, $subjectType, (int)$id, $lock);
    }

    /** @return array{type:string,publicId:string,rootType:string,rootPublicId:string} */
    private function subjectRoot(PDO $pdo, string $type, string $publicId, ?int $organizationId,
        ?int $clientId, bool $lock): array
    {
        if (!self::safeId($publicId)) throw new DomainException('service-assignment-subject-invalid');
        if ($type === 'organization') return compact('type', 'publicId') + ['rootType' => 'organization', 'rootPublicId' => $publicId];
        if ($organizationId !== null && $organizationId > 0) {
            $root = $this->rootPublicId($pdo, 'organizations', $organizationId, $lock);
            return compact('type', 'publicId') + ['rootType' => 'organization', 'rootPublicId' => $root];
        }
        if ($type === 'department') throw new DomainException('service-assignment-subject-unavailable');
        $standaloneId = $type === 'project' ? $clientId : (int)$this->idByPublicId($pdo, 'clients', $publicId, $lock);
        if (!$standaloneId) throw new DomainException('service-assignment-subject-unavailable');
        $root = $this->rootPublicId($pdo, 'clients', $standaloneId, $lock, true);
        return compact('type', 'publicId') + ['rootType' => 'standalone_client', 'rootPublicId' => $root];
    }

    private function rootPublicId(PDO $pdo, string $table, int $id, bool $lock, bool $standalone = false): string
    {
        $where = $standalone ? ' AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL' : '';
        $statement = $pdo->prepare("SELECT public_id FROM {$table} WHERE id=?{$where}" . ($lock ? $this->lockSuffix($pdo) : ''));
        $statement->execute([$id]);
        $publicId = $statement->fetchColumn();
        if ($publicId === false || !self::safeId((string)$publicId)) throw new DomainException('service-assignment-subject-unavailable');
        return (string)$publicId;
    }

    private function idByPublicId(PDO $pdo, string $table, string $publicId, bool $lock): int|false
    {
        $statement = $pdo->prepare("SELECT id FROM {$table} WHERE public_id=?" . ($lock ? $this->lockSuffix($pdo) : ''));
        $statement->execute([$publicId]);
        $id = $statement->fetchColumn();
        return $id === false ? false : (int)$id;
    }

    /** @return array{id:int,publicId:string} */
    private function resolveService(PDO $pdo, int $itemLibraryId, bool $lock): array
    {
        if ($itemLibraryId < 1) throw new DomainException('service-assignment-service-unavailable');
        $statement = $pdo->prepare(
            "SELECT id,portal_public_id FROM item_library
             WHERE id=? AND portal_requestable=1 AND is_active=1 AND entry_type='service'" . ($lock ? $this->lockSuffix($pdo) : '')
        );
        $statement->execute([$itemLibraryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || !self::safeId((string)($row['portal_public_id'] ?? ''))) {
            throw new DomainException('service-assignment-service-unavailable');
        }
        return ['id' => (int)$row['id'], 'publicId' => (string)$row['portal_public_id']];
    }

    /** @return array{id:int,publicId:string} */
    private function resolvePublicService(PDO $pdo, string $publicId, bool $lock): array
    {
        $statement = $pdo->prepare('SELECT id FROM item_library WHERE portal_public_id=?' . ($lock ? $this->lockSuffix($pdo) : ''));
        $statement->execute([$publicId]);
        $id = $statement->fetchColumn();
        if ($id === false) throw new DomainException('service-assignment-service-unavailable');
        return $this->resolveService($pdo, (int)$id, $lock);
    }

    /** @return array<string,mixed> */
    private function assignment(PDO $pdo, int $assignmentId, bool $lock, bool $includeDeleted): array
    {
        if ($assignmentId < 1) throw new DomainException('service-assignment-unavailable');
        $statement = $pdo->prepare('SELECT * FROM portal_service_assignments WHERE id=?'
            . ($includeDeleted ? '' : ' AND deleted_at IS NULL') . ($lock ? $this->lockSuffix($pdo) : ''));
        $statement->execute([$assignmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('service-assignment-unavailable');
        return $row;
    }

    private function requireUnique(PDO $pdo, string $subjectType, string $subjectPublicId,
        string $servicePublicId, ?int $exceptAssignmentId = null): void
    {
        $sql = 'SELECT id FROM portal_service_assignments
                WHERE subject_type=? AND subject_public_id=? AND service_public_id=? AND deleted_at IS NULL';
        $parameters = [$subjectType, $subjectPublicId, $servicePublicId];
        if ($exceptAssignmentId !== null) {
            $sql .= ' AND id<>?';
            $parameters[] = $exceptAssignmentId;
        }
        $sql .= ' ORDER BY id LIMIT 1' . $this->lockSuffix($pdo);
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        if ($statement->fetchColumn() !== false) throw new DomainException('service-assignment-duplicate');
    }

    /** @param array{ids:list<int>,summaries:list<array<string,mixed>>} $projection
     *  @return array{assignment:array<string,mixed>,projectionProfiles:list<int>,projectionSummaries:list<array<string,mixed>>} */
    private function result(PDO $pdo, int $assignmentId, array $projection, bool $includeDeleted = false): array
    {
        return ['assignment' => $this->assignment($pdo, $assignmentId, false, $includeDeleted),
            'projectionProfiles' => $projection['ids'], 'projectionSummaries' => $projection['summaries']];
    }

    /** @return array{0:?string,1:?string} */
    private function window(?string $from, ?string $until): array
    {
        $from = $this->timestamp($from);
        $until = $this->timestamp($until);
        if ($from !== null && $until !== null && strcmp($until, $from) <= 0) {
            throw new DomainException('service-assignment-window-invalid');
        }
        return [$from, $until];
    }

    private function timestamp(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value) !== 1) {
            throw new DomainException('service-assignment-window-invalid');
        }
        try { $date = new DateTimeImmutable($value, new DateTimeZone('UTC')); }
        catch (Throwable) { throw new DomainException('service-assignment-window-invalid'); }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function requireActor(int $actorId): void
    {
        if ($actorId < 1) throw new DomainException('service-assignment-actor-invalid');
    }

    private function lockSuffix(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function transaction(PDO $pdo, callable $callback): mixed
    {
        $owns = !$pdo->inTransaction();
        try {
            if ($owns) $pdo->beginTransaction();
            $result = $callback();
            if ($owns) $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function safeId(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9](?=.*[A-Za-z])[A-Za-z0-9_-]{0,127}$/D', $value) === 1;
    }

    private static function publicId(): string
    {
        return 'a' . substr(bin2hex(random_bytes(16)), 1);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
