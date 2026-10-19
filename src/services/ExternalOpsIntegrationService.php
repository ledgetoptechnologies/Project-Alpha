<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../utils/workforce_availability.php';

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalOpsIntegrationService
{
    public const SCHEMA_VERSION = 1;
    public const ROLES = [
        'role-admin',
        'role-operator',
    ];

    /**
     * Incremental events use the same least-privilege boundary as the full
     * snapshot. Keep this allowlist here so a future caller cannot expose a
     * pay-rate override, private sharing token, password hash, or unrelated
     * accounting field by passing a raw database row.
     *
     * @var array<string,list<string>>
     */
    private const PROJECTION_FIELDS = [
        'business_unit' => [
            'id', 'name', 'code', 'description', 'is_active', 'created_by',
            'created_at', 'updated_at',
        ],
        'project' => [
            'id', 'client_id', 'parent_id', 'organization_id', 'department_id',
            'business_unit_id', 'manager_user_id', 'created_by', 'name', 'description', 'status',
            'start_date', 'end_date', 'estimated_start', 'estimated_end',
            'created_at', 'updated_at',
        ],
        'project_assignment' => [
            'id', 'project_id', 'user_id', 'assigned_at', 'ends_at',
            'created_by', 'created_at', 'updated_at', 'active',
        ],
        'operation' => [
            'id', 'project_id', 'business_unit_id', 'title', 'status',
            'scheduled_start_at', 'scheduled_end_at', 'location', 'notes',
            'created_by', 'created_at', 'updated_at',
        ],
        'operation_assignment' => [
            'operation_id', 'user_id', 'assignment_role', 'assigned_by',
            'assigned_at', 'updated_at',
        ],
        'task' => [
            'id', 'operation_id', 'project_id', 'business_unit_id',
            'assignee_user_id', 'title', 'status', 'due_at', 'notes',
            'created_by', 'created_at', 'updated_at',
        ],
        'task_assignment' => [
            'task_id', 'user_id', 'assigned_by', 'assigned_at', 'updated_at',
        ],
    ];

    /** @return array{event_id:?string,entitlement_id:int,effective_enabled:bool,changed:bool} */
    public function grantAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        int $actorUserId,
        string $displayLabel = 'External operations'
    ): array {
        if ($userId < 1 || $actorUserId < 1) {
            throw new DomainException('A valid user and administrator are required.');
        }
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $account = $this->accountForUpdate($pdo, $userId);
            if (!empty($account['deleted_at'])) {
                throw new DomainException('Deleted accounts must be restored before external operations access can be granted.');
            }
            if (($account['worker_status'] ?? '') === 'terminated'
                || ($account['employment_status'] ?? '') === 'terminated') {
                throw new DomainException('Terminated workers must be restored separately before external operations access can be granted.');
            }

            if (!pa_workforce_enabled() && (
                ($account['worker_status'] ?? '') === 'inactive'
                || ($account['employment_status'] ?? '') === 'inactive'
            )) {
                throw new DomainException('An inactive Workforce profile cannot be reactivated while Workforce is paused.');
            }

            $accountReactivated = !empty($account['is_disabled'])
                || ($account['worker_status'] ?? '') === 'inactive'
                || ($account['employment_status'] ?? '') === 'inactive';
            if (!empty($account['is_disabled'])) {
                $pdo->prepare('UPDATE users SET is_disabled=0,auth_version=auth_version+1 WHERE id=?')->execute([$userId]);
            }
            if (pa_workforce_enabled()) {
                $pdo->prepare("UPDATE worker_profiles SET status='active',ended_at=NULL WHERE user_id=? AND status='inactive'")->execute([$userId]);
                $pdo->prepare("UPDATE employee_profiles SET employment_status='active',terminated_at=NULL WHERE user_id=? AND employment_status='inactive'")->execute([$userId]);
            }
            $pdo->prepare("UPDATE team_members SET is_active=1 WHERE user_id=? AND profile_source='pa'")->execute([$userId]);

            $roleKey = (string)$account['role'] === 'admin' ? 'role-admin' : 'role-operator';
            $suffix = $this->forUpdateSuffix($pdo);
            $existing = $pdo->prepare('SELECT * FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1' . $suffix);
            $existing->execute([$userId, $applicationKey]);
            $entitlement = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
            $entitlementId = (int)($entitlement['id'] ?? 0);
            $scopeCount = 0;
            if ($entitlementId > 0) {
                $scope = $pdo->prepare(
                    'SELECT (SELECT COUNT(*) FROM application_entitlement_business_units WHERE entitlement_id=?)
                          + (SELECT COUNT(*) FROM application_entitlement_oversight_units WHERE entitlement_id=?)'
                );
                $scope->execute([$entitlementId, $entitlementId]);
                $scopeCount = (int)$scope->fetchColumn();
            }
            $changed = $accountReactivated || $entitlement === null || $scopeCount > 0
                || (int)($entitlement['enabled'] ?? 0) !== 1
                || (int)($entitlement['manual_enabled'] ?? 0) !== 1
                || (int)($entitlement['automatic_enabled'] ?? 0) !== 0
                || (int)($entitlement['oversight_enabled'] ?? 0) !== 0
                || (string)($entitlement['role_key'] ?? '') !== $roleKey;

            if ($entitlementId > 0) {
                $pdo->prepare('UPDATE application_entitlements SET enabled=1,manual_enabled=1,automatic_enabled=0,oversight_enabled=0,role_key=?,updated_by=? WHERE id=?')
                    ->execute([$roleKey, $actorUserId, $entitlementId]);
            } else {
                $pdo->prepare('INSERT INTO application_entitlements (user_id,application_key,enabled,manual_enabled,automatic_enabled,oversight_enabled,role_key,created_by,updated_by) VALUES (?,?,1,1,0,0,?,?,?)')
                    ->execute([$userId, $applicationKey, $roleKey, $actorUserId, $actorUserId]);
                $entitlementId = (int)$pdo->lastInsertId();
            }
            $this->clearScopes($pdo, $entitlementId);

            $eventId = $changed
                ? $this->enqueueCurrentState($pdo, $userId, $applicationKey, 'application_entitlement.changed')
                : null;
            if ($changed && $eventId === null) {
                throw new RuntimeException('Failed to queue the external access grant.');
            }
            if ($changed) {
                $this->recordAccessAudit($pdo, $actorUserId, 'external_application.access_granted', $userId, [
                    'application_key' => $applicationKey,
                    'display_label' => $this->normalizeDisplayLabel($displayLabel),
                    'role_key' => $roleKey,
                    'account_reactivated' => $accountReactivated,
                ]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['event_id' => $eventId, 'entitlement_id' => $entitlementId, 'effective_enabled' => true, 'changed' => $changed];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{event_id:?string,entitlement_id:int,effective_enabled:bool,changed:bool} */
    public function revokeAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        int $actorUserId,
        string $displayLabel = 'External operations'
    ): array {
        if ($userId < 1 || $actorUserId < 1) {
            throw new DomainException('A valid user and administrator are required.');
        }
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $account = $this->accountForUpdate($pdo, $userId);
            $statement = $pdo->prepare('SELECT * FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1' . $this->forUpdateSuffix($pdo));
            $statement->execute([$userId, $applicationKey]);
            $entitlement = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($entitlement === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return ['event_id' => null, 'entitlement_id' => 0, 'effective_enabled' => false, 'changed' => false];
            }
            $entitlementId = (int)$entitlement['id'];
            $changed = !empty($entitlement['enabled'])
                || !empty($entitlement['manual_enabled'])
                || !empty($entitlement['automatic_enabled'])
                || !empty($entitlement['oversight_enabled']);
            $roleKey = (string)$account['role'] === 'admin' ? 'role-admin' : 'role-operator';
            $changed = $changed || (string)$entitlement['role_key'] !== $roleKey;
            $pdo->prepare('UPDATE application_entitlements SET enabled=0,manual_enabled=0,automatic_enabled=0,oversight_enabled=0,role_key=?,updated_by=? WHERE id=?')
                ->execute([$roleKey, $actorUserId, $entitlementId]);
            $this->clearScopes($pdo, $entitlementId);
            $eventId = $changed
                ? $this->enqueueCurrentState($pdo, $userId, $applicationKey, 'application_entitlement.revoked')
                : null;
            if ($changed && $eventId === null) {
                throw new RuntimeException('Failed to queue the external access revocation.');
            }
            if ($changed) {
                $this->recordAccessAudit($pdo, $actorUserId, 'external_application.access_revoked', $userId, [
                    'application_key' => $applicationKey,
                    'display_label' => $this->normalizeDisplayLabel($displayLabel),
                    'role_key' => $roleKey,
                ]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['event_id' => $eventId, 'entitlement_id' => $entitlementId, 'effective_enabled' => false, 'changed' => $changed];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{event_id:?string,entitlement_id:int,effective_enabled:bool,changed:bool}|null */
    public function refreshGrantedAccount(PDO $pdo, int $userId, string $applicationKey, int $actorUserId): ?array
    {
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $statement = $pdo->prepare('SELECT id,enabled,manual_enabled FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1');
        $statement->execute([$userId, $applicationKey]);
        $entitlement = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$entitlement || empty($entitlement['enabled']) || empty($entitlement['manual_enabled'])) {
            return null;
        }
        $account = $this->accountForUpdate($pdo, $userId);
        $workerStatus = (string)($account['worker_status'] ?? '');
        $employmentStatus = (string)($account['employment_status'] ?? '');
        if (!empty($account['is_disabled']) || !empty($account['deleted_at'])
            || in_array($workerStatus, ['inactive','terminated'], true)
            || in_array($employmentStatus, ['inactive','terminated'], true)) {
            return $this->revokeAccountAccess($pdo, $userId, $applicationKey, $actorUserId);
        }
        $roleKey = (string)$account['role'] === 'admin' ? 'role-admin' : 'role-operator';
        $pdo->prepare('UPDATE application_entitlements SET role_key=?,manual_enabled=1,automatic_enabled=0,oversight_enabled=0,updated_by=? WHERE id=?')
            ->execute([$roleKey, $actorUserId, (int)$entitlement['id']]);
        $this->clearScopes($pdo, (int)$entitlement['id']);
        $eventId = $this->enqueueCurrentState($pdo, $userId, $applicationKey, 'application_entitlement.changed');
        return ['event_id' => $eventId, 'entitlement_id' => (int)$entitlement['id'], 'effective_enabled' => true, 'changed' => true];
    }

    /**
     * Backward-compatible adapter. New controllers use the explicit grant and
     * revoke methods so a checkbox can never represent partial access.
     *
     * @param list<int>|null $businessUnitIds Deprecated compatibility argument.
     * @return array{event_id:?string,entitlement_id:int,effective_enabled:bool,changed:bool}
     */
    public function saveAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        bool $requestedAccess,
        int $actorUserId,
        ?array $businessUnitIds = null
    ): array {
        return $requestedAccess
            ? $this->grantAccountAccess($pdo, $userId, $applicationKey, $actorUserId)
            : $this->revokeAccountAccess($pdo, $userId, $applicationKey, $actorUserId);
    }

    /**
     * Refresh an existing explicit selection after account or worker changes.
     *
     * @return array{event_id:string,entitlement_id:int}|null
     */
    public function resyncAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        int $actorUserId
    ): ?array {
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $statement = $pdo->prepare('SELECT id FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1');
        $statement->execute([$userId, $applicationKey]);
        $existing = $statement->fetchColumn();
        if ($existing === false) {
            return null;
        }
        return $this->refreshGrantedAccount($pdo, $userId, $applicationKey, $actorUserId);
    }

    /**
     * Requeue an active explicit entitlement without changing its stored state.
     *
     * @return array{event_id:string,entitlement_id:int}|null
     */
    public function resendAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        int $actorUserId
    ): ?array {
        if ($userId < 1 || $actorUserId < 1) {
            throw new DomainException('A valid user and administrator are required.');
        }
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $state = $this->entitlementState($pdo, $userId, $applicationKey);
        if ($state === null
            || empty($state['user']['active'])
            || empty($state['entitlement']['enabled'])
            || empty($state['entitlement']['manual_access'])) {
            return null;
        }

        return [
            'event_id' => $this->enqueueState($pdo, $state, 'application_entitlement.changed'),
            'entitlement_id' => (int)$state['entitlement_id'],
        ];
    }

    public function enqueueCurrentState(PDO $pdo, int $userId, string $applicationKey, string $eventType = 'user.changed'): ?string
    {
        $state = $this->entitlementState($pdo, $userId, self::normalizeApplicationKey($applicationKey));
        if ($state === null) {
            return null;
        }
        return $this->enqueueState($pdo, $state, $eventType);
    }

    public function enqueueDeprovisionBeforeDelete(PDO $pdo, int $userId, string $applicationKey): ?string
    {
        $state = $this->entitlementState($pdo, $userId, self::normalizeApplicationKey($applicationKey));
        if ($state === null) {
            return null;
        }
        $state['entitlement']['enabled'] = false;
        $state['user']['active'] = false;
        return $this->enqueueState($pdo, $state, 'application_entitlement.revoked');
    }

    /** @return array<string,mixed>|null */
    private function entitlementState(PDO $pdo, int $userId, string $applicationKey): ?array
    {
        $statement = $pdo->prepare(
            'SELECT ae.id AS entitlement_id,ae.application_key,ae.enabled,ae.manual_enabled,ae.automatic_enabled,ae.oversight_enabled,
                    u.id AS user_id,u.email,u.username,u.role,u.is_disabled,u.deleted_at,wp.display_name,wp.status AS worker_status,
                    ep.employment_status
             FROM application_entitlements ae
             JOIN users u ON u.id = ae.user_id
             LEFT JOIN worker_profiles wp ON wp.user_id = u.id
             LEFT JOIN employee_profiles ep ON ep.user_id = u.id
             WHERE ae.user_id = ? AND ae.application_key = ?
             LIMIT 1'
        );
        $statement->execute([$userId, $applicationKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $isAdmin = (string)$row['role'] === 'admin';
        $workerStatus = trim((string)($row['worker_status'] ?? ''));
        $employmentStatus = trim((string)($row['employment_status'] ?? ''));
        $userActive = empty($row['is_disabled'])
            && empty($row['deleted_at'])
            && ($workerStatus === '' || $workerStatus === 'active')
            && ($employmentStatus === '' || $employmentStatus === 'active');

        return [
            'entitlement_id' => (int)$row['entitlement_id'],
            'user' => [
                'id' => (int)$row['user_id'],
                'email' => strtolower(trim((string)$row['email'])),
                'display_name' => trim((string)($row['display_name'] ?: $row['username'] ?: $row['email'])),
                'active' => $userActive,
            ],
            'entitlement' => [
                'application_key' => (string)$row['application_key'],
                'enabled' => !empty($row['enabled']) && !empty($row['manual_enabled']) && $userActive,
                'role_key' => $isAdmin ? 'role-admin' : 'role-operator',
                'business_unit_ids' => [],
                'oversight_business_unit_ids' => [],
                'manual_access' => !empty($row['manual_enabled']),
                'automatic_access' => false,
                'unit_oversight' => false,
            ],
        ];
    }

    /**
     * Queue a least-privilege incremental projection change. The daily
     * snapshot remains the reconciliation authority.
     *
     * @param array<string,mixed> $data
     */
    public function enqueueProjectionChange(PDO $pdo, string $applicationKey, string $entityType, string|int $entityId, string $action, array $data): string
    {
        if (!in_array($action, ['upsert', 'revoke'], true)) {
            throw new DomainException('Invalid projection action.');
        }
        if (!isset(self::PROJECTION_FIELDS[$entityType])) {
            throw new DomainException('Unsupported projection entity type.');
        }
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $data = array_intersect_key($data, array_fill_keys(self::PROJECTION_FIELDS[$entityType], true));
        $eventId = $this->uuidV4();
        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $sourceUpdatedAt = $occurredAt;
        if (!empty($data['updated_at'])) {
            try {
                $sourceUpdatedAt = (new DateTimeImmutable((string)$data['updated_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            } catch (Throwable $ignored) {
                $sourceUpdatedAt = $occurredAt;
            }
        }
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'projection.changed',
            'occurred_at' => $occurredAt,
            'schema_version' => self::SCHEMA_VERSION,
            'application_key' => $applicationKey,
            'projection' => [
                'entity_type' => $entityType,
                'entity_id' => (string)$entityId,
                'action' => $action,
                'source_updated_at' => $sourceUpdatedAt,
                'data' => $data,
            ],
        ];
        $databaseTime = (new DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s.u');
        $pdo->prepare('INSERT INTO integration_outbox (event_id,integration_key,event_type,schema_version,payload_json,occurred_at,next_attempt_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$eventId, $applicationKey, 'projection.changed', self::SCHEMA_VERSION, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $databaseTime, $databaseTime]);
        return $eventId;
    }

    /** @param array<string,mixed> $state */
    private function enqueueState(PDO $pdo, array $state, string $eventType): string
    {
        $eventId = $this->uuidV4();
        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $payload = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'schema_version' => self::SCHEMA_VERSION,
            'user' => $state['user'],
            'entitlement' => $state['entitlement'],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $databaseTime = (new DateTimeImmutable($occurredAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $pdo->prepare(
            'INSERT INTO integration_outbox
                (event_id,integration_key,event_type,schema_version,payload_json,occurred_at,next_attempt_at)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $eventId,
            (string)$state['entitlement']['application_key'],
            $eventType,
            self::SCHEMA_VERSION,
            $json,
            $databaseTime,
            $databaseTime,
        ]);
        return $eventId;
    }

    public static function normalizeApplicationKey(string $applicationKey): string
    {
        $applicationKey = strtolower(trim($applicationKey));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $applicationKey)) {
            throw new DomainException('The application key must be 2 to 64 characters using letters, numbers, underscores, or hyphens.');
        }
        return $applicationKey;
    }

    /** @return array<string,mixed> */
    private function accountForUpdate(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT u.role,u.is_disabled,u.deleted_at,wp.status AS worker_status,ep.employment_status
             FROM users u
             LEFT JOIN worker_profiles wp ON wp.user_id=u.id
             LEFT JOIN employee_profiles ep ON ep.user_id=u.id
             WHERE u.id=? LIMIT 1' . $this->forUpdateSuffix($pdo)
        );
        $statement->execute([$userId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new DomainException('User not found.');
        }
        return $account;
    }

    private function clearScopes(PDO $pdo, int $entitlementId): void
    {
        $pdo->prepare('DELETE FROM application_entitlement_business_units WHERE entitlement_id=?')->execute([$entitlementId]);
        $pdo->prepare('DELETE FROM application_entitlement_oversight_units WHERE entitlement_id=?')->execute([$entitlementId]);
    }

    /** @param array<string,mixed> $details */
    private function recordAccessAudit(PDO $pdo, int $actorId, string $action, int $userId, array $details): void
    {
        $pdo->prepare(
            'INSERT INTO system_audit (user_id,action,entity_type,entity_id,details)
             VALUES (?,?,?,?,?)'
        )->execute([
            $actorId,
            $action,
            'user',
            $userId,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $GLOBALS['__audit_logged'] = true;
    }

    private function forUpdateSuffix(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function normalizeDisplayLabel(string $displayLabel): string
    {
        $displayLabel = trim($displayLabel);
        return $displayLabel !== '' ? mb_substr($displayLabel, 0, 100) : 'External operations';
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
