<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

/**
 * Publishes Project Alpha-owned service assignments through the existing
 * signed portal projection outbox. Assignments are facts, never access grants.
 */
final class PortalServiceAssignmentProjectionService
{
    public const PUBLISH_SCOPE = 'portal.service-assignments.publish';
    private const STREAM_KEY = 'service-assignments';
    private const ROUTE_TYPE = 'service_assignments';
    private const MAX_ITEMS = 5000;
    private const PAGE_SIZE = 100;
    private const MAX_BODY_BYTES = 262144;

    /** Queue a complete deterministic v1 snapshot. Caller owns the transaction. */
    public function queueSnapshot(PDO $pdo, array $profile, string $idempotencyKey): array
    {
        $this->requireTransaction($pdo);
        $profile = $this->requireProfile($pdo, $profile);
        $items = $this->items($pdo, (int)$profile['id']);
        return $this->queueSnapshotItems($pdo, $profile, $items, $idempotencyKey, false);
    }

    /** Queue an authoritative empty snapshot that remains deliverable while
     * the profile or this capability is being disabled. */
    public function queueRevocationSnapshot(PDO $pdo, array $profile, string $idempotencyKey): array
    {
        $this->requireTransaction($pdo);
        $profile = $this->requireProfile($pdo, $profile);
        return $this->queueSnapshotItems($pdo, $profile, [], $idempotencyKey, true);
    }

    /** @param list<array<string,mixed>> $items */
    private function queueSnapshotItems(PDO $pdo, array $profile, array $items, string $idempotencyKey, bool $revocation): array
    {
        $profileId = (int)$profile['id'];
        $intent = [
            'action' => $revocation ? 'snapshot.revocation' : 'snapshot',
            'applicationKey' => (string)$profile['application_key'],
            'destinationUrl' => $this->serviceAssignmentRoute((string)$profile['portal_route']),
            'items' => $items,
        ];
        if (($replay = $this->replay($pdo, $profileId, $idempotencyKey, $intent)) !== null) {
            return $replay;
        }

        $state = $this->stateForUpdate($pdo, $profileId);
        $sequence = $state ? (int)$state['source_sequence'] + 1 : 1;
        $pages = array_chunk($items, self::PAGE_SIZE);
        if ($pages === []) $pages = [[]];
        $pageMeta = [];
        foreach ($pages as $index => $pageItems) {
            $pageMeta[] = [
                'pageNumber' => $index + 1,
                'itemCount' => count($pageItems),
                'pageHash' => hash('sha256', self::json(['schemaVersion' => 1, 'items' => $pageItems])),
            ];
        }
        $snapshotHash = hash('sha256', self::json([
            'schemaVersion' => 1,
            'pageCount' => count($pages),
            'itemCount' => count($items),
            'pages' => $pageMeta,
        ]));
        $generation = 'assignments-' . $sequence . '-' . substr($snapshotHash, 0, 24);
        $occurredAt = self::now();
        $deliveryIds = [];
        foreach ($pages as $index => $pageItems) {
            $payload = [
                'schemaVersion' => 1,
                'applicationKey' => (string)$profile['application_key'],
                'deliveryId' => self::uuid(),
                'occurredAt' => $occurredAt,
                'sourceGeneration' => $generation,
                'sourceSequence' => $sequence,
                'kind' => 'snapshot.page',
                'snapshotHash' => $snapshotHash,
                'pageNumber' => $index + 1,
                'pageCount' => count($pages),
                'itemCount' => count($items),
                'items' => $pageItems,
            ];
            $this->enqueue($pdo, $profile, $sequence, 'snapshot.page', $payload, $revocation);
            $deliveryIds[] = $payload['deliveryId'];
        }
        $activation = [
            'schemaVersion' => 1,
            'applicationKey' => (string)$profile['application_key'],
            'deliveryId' => self::uuid(),
            'occurredAt' => $occurredAt,
            'sourceGeneration' => $generation,
            'sourceSequence' => $sequence,
            'kind' => 'snapshot.activate',
            'snapshotHash' => $snapshotHash,
            'pageCount' => count($pages),
            'itemCount' => count($items),
        ];
        $this->enqueue($pdo, $profile, $sequence, 'snapshot.activate', $activation, $revocation);
        $deliveryIds[] = $activation['deliveryId'];

        $this->saveState($pdo, $profileId, $generation, $sequence, $snapshotHash);
        $this->replaceRecords($pdo, $profileId, $items);
        $result = [
            'status' => 'queued',
            'sourceGeneration' => $generation,
            'sourceSequence' => $sequence,
            'snapshotHash' => $snapshotHash,
            'pageCount' => count($pages),
            'itemCount' => count($items),
            'deliveryIds' => $deliveryIds,
        ];
        $this->saveReceipt($pdo, $profileId, $idempotencyKey, $intent, $result);
        return $result;
    }

    /**
     * Queue one exact ordered event. A repeated key with the same intent returns
     * the immutable receipt; a changed intent is rejected.
     */
    public function queueEvent(PDO $pdo, array $profile, array $event, string $idempotencyKey): array
    {
        $this->requireTransaction($pdo);
        $profile = $this->requireProfile($pdo, $profile);
        $event = $this->normalizeEvent($event);
        return $this->queueValidatedEvent($pdo, $profile, $event, $idempotencyKey, true);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $event */
    private function queueValidatedEvent(PDO $pdo, array $profile, array $event, string $idempotencyKey, bool $requireSubjectScope): array
    {
        $profileId = (int)$profile['id'];
        $intent = [
            'action' => 'event',
            'applicationKey' => (string)$profile['application_key'],
            'destinationUrl' => $this->serviceAssignmentRoute((string)$profile['portal_route']),
            'event' => $event,
        ];
        if (($replay = $this->replay($pdo, $profileId, $idempotencyKey, $intent)) !== null) {
            return $replay;
        }
        if ($requireSubjectScope) $this->requireEventSubjectAllowed($pdo, $profileId, $event);
        $state = $this->stateForUpdate($pdo, $profileId);
        if (!$state || trim((string)$state['snapshot_hash']) === '') {
            throw new DomainException('service-assignment-snapshot-required');
        }
        $sequence = (int)$state['source_sequence'] + 1;
        $payload = [
            'schemaVersion' => 1,
            'applicationKey' => (string)$profile['application_key'],
            'deliveryId' => self::uuid(),
            'occurredAt' => self::now(),
            'sourceGeneration' => (string)$state['source_generation'],
            'sourceSequence' => $sequence,
            'kind' => 'event',
            'event' => $event,
        ];
        $this->enqueue($pdo, $profile, $sequence, 'event', $payload);
        $pdo->prepare('UPDATE portal_service_assignment_projection_state SET source_sequence=? WHERE integration_profile_id=?')
            ->execute([$sequence, $profileId]);
        if ($event['action'] === 'upsert') {
            $this->saveRecord($pdo, $profileId, $event['item']);
        } else {
            $pdo->prepare('DELETE FROM portal_service_assignment_projection_records WHERE integration_profile_id=? AND assignment_public_id=?')
                ->execute([$profileId, $event['assignmentPublicId']]);
        }
        $result = [
            'status' => 'queued',
            'sourceGeneration' => (string)$state['source_generation'],
            'sourceSequence' => $sequence,
            'deliveryId' => $payload['deliveryId'],
            'event' => $event,
        ];
        $this->saveReceipt($pdo, $profileId, $idempotencyKey, $intent, $result);
        return $result;
    }

    /** Diff explicit assignments and emit tombstones before upserts. */
    public function queueChanges(PDO $pdo, array $profile): array
    {
        $this->requireTransaction($pdo);
        $profile = $this->requireProfile($pdo, $profile);
        $profileId = (int)$profile['id'];
        if (!$this->stateForUpdate($pdo, $profileId)) {
            return ['snapshot' => $this->queueSnapshot($pdo, $profile, 'initial-' . self::uuid()), 'events' => []];
        }
        $current = [];
        foreach ($this->items($pdo, $profileId) as $item) $current[$item['assignmentPublicId']] = $item;
        $statement = $pdo->prepare('SELECT assignment_public_id,source_version,payload_hash,record_json FROM portal_service_assignment_projection_records WHERE integration_profile_id=? ORDER BY assignment_public_id');
        $statement->execute([$profileId]);
        $existing = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $existing[(string)$row['assignment_public_id']] = $row;
        $changes = [];
        foreach ($existing as $publicId => $prior) {
            if (!isset($current[$publicId])) {
                $changes[] = ['action' => 'tombstone', 'assignmentPublicId' => $publicId, 'sourceVersion' => (string)$prior['source_version']];
            }
        }
        foreach ($current as $publicId => $item) {
            $hash = hash('sha256', self::json($item));
            $prior = $existing[$publicId] ?? null;
            if ($prior && hash_equals((string)$prior['payload_hash'], $hash)) continue;
            if ($prior && hash_equals((string)$prior['source_version'], (string)$item['sourceVersion'])) {
                throw new DomainException('service-assignment-source-version-reuse');
            }
            $changes[] = ['action' => 'upsert', 'item' => $item];
        }
        $results = [];
        foreach ($changes as $change) {
            $state = $this->stateForUpdate($pdo, $profileId);
            if (!$state) throw new DomainException('service-assignment-snapshot-required');
            $nextSequence = (int)$state['source_sequence'] + 1;
            $fingerprint = hash('sha256', self::json($change));
            // Sequence qualification is required here: an assignment can move
            // A -> B -> A inside one generation, and the second A must be a new
            // event rather than a replay of the first A receipt.
            // Both branches are already profile-scoped: upserts came from
            // items($profileId), while tombstones came from this profile's
            // previously published records. Avoid rebuilding the allowlist for
            // every item in a potentially large reconciliation batch.
            $results[] = $this->queueValidatedEvent($pdo, $profile, $change, 'change-' . $nextSequence . '-' . $fingerprint, false);
        }
        return ['snapshot' => null, 'events' => $results];
    }

    /** @return list<array<string,mixed>> */
    private function items(PDO $pdo, int $profileId): array
    {
        $allowedSubjects = $this->allowedSubjects($pdo, $profileId);
        $assignments = $pdo->query("SELECT public_id,subject_type,subject_public_id,service_public_id,active,effective_from,effective_until FROM portal_service_assignments WHERE deleted_at IS NULL ORDER BY public_id")
            ->fetchAll(PDO::FETCH_ASSOC);
        $catalog = (new PortalIntegrationService())->catalog($pdo);
        $services = [];
        foreach ($catalog['items'] as $item) $services[(string)$item['publicId']] = (string)$item['sourceVersion'];
        $items = [];
        foreach ($assignments as $row) {
            $subjectType = (string)($row['subject_type'] ?? '');
            $subjectPublicId = (string)($row['subject_public_id'] ?? '');
            // Ignore rows outside this profile before validating their payload.
            // A corrupt record owned by another workspace must not be able to
            // block this profile's otherwise valid snapshot.
            if (!isset($allowedSubjects[$subjectType . '|' . $subjectPublicId])) continue;
            $publicId = self::publicId($row['public_id'] ?? null);
            $subjectPublicId = self::publicId($subjectPublicId);
            $servicePublicId = self::publicId($row['service_public_id'] ?? null);
            if (!in_array($subjectType, ['organization', 'standalone_client', 'department', 'client', 'project'], true)) {
                throw new DomainException('service-assignment-subject-invalid');
            }
            // Assignments remain authoritative history when their catalog
            // service is unpublished. They simply stop projecting. Existing
            // projection records are then diffed into tombstones, and one
            // stale assignment cannot block unrelated assignment mutations.
            if (!isset($services[$servicePublicId])) continue;
            $visible = [
                'assignmentPublicId' => $publicId,
                'subjectType' => $subjectType,
                'subjectPublicId' => $subjectPublicId,
                'servicePublicId' => $servicePublicId,
                'serviceSourceVersion' => $services[$servicePublicId],
                'active' => (bool)$row['active'],
                'effectiveFrom' => self::utc($row['effective_from'] ?? null),
                'effectiveUntil' => self::utc($row['effective_until'] ?? null),
            ];
            if ($visible['effectiveFrom'] !== null && $visible['effectiveUntil'] !== null
                && strcmp($visible['effectiveUntil'], $visible['effectiveFrom']) <= 0) {
                throw new DomainException('service-assignment-window-invalid');
            }
            $items[] = [
                'assignmentPublicId' => $publicId,
                'sourceVersion' => PortalSourceVersion::from($visible),
                'subjectType' => $subjectType,
                'subjectPublicId' => $subjectPublicId,
                'servicePublicId' => $servicePublicId,
                'serviceSourceVersion' => $services[$servicePublicId],
                'active' => $visible['active'],
                'effectiveFrom' => $visible['effectiveFrom'],
                'effectiveUntil' => $visible['effectiveUntil'],
            ];
            if (count($items) > self::MAX_ITEMS) throw new DomainException('service-assignment-item-limit');
        }
        return $items;
    }

    /** @return array<string,true> */
    private function allowedSubjects(PDO $pdo, int $profileId): array
    {
        $workspaces = $pdo->prepare(
            'SELECT w.root_type,w.root_public_id
             FROM portal_integration_profile_workspaces pw
             JOIN portal_v2_workspaces w ON w.id=pw.workspace_id
             WHERE pw.profile_id=? AND pw.active=1 AND w.active=1
             ORDER BY w.root_type,w.root_public_id'
        );
        $workspaces->execute([$profileId]);
        $allowed = [];
        foreach ($workspaces->fetchAll(PDO::FETCH_ASSOC) as $workspace) {
            $rootType = (string)$workspace['root_type'];
            $rootPublicId = self::publicId($workspace['root_public_id'] ?? null);
            if ($rootType === 'organization') {
                $organization = $pdo->prepare('SELECT id FROM organizations WHERE public_id=?');
                $organization->execute([$rootPublicId]);
                $organizationId = $organization->fetchColumn();
                if ($organizationId === false) throw new DomainException('portal-workspace-root-missing');
                $allowed['organization|' . $rootPublicId] = true;
                $this->addAllowedRows($allowed, 'department', $pdo,
                    'SELECT public_id FROM organization_departments WHERE organization_id=? ORDER BY public_id', [(int)$organizationId]);
                $this->addAllowedRows($allowed, 'client', $pdo,
                    'SELECT public_id FROM clients WHERE organization_id=? AND archived=0 AND deleted_at IS NULL ORDER BY public_id', [(int)$organizationId]);
                $projectVisibility = ProjectLifecycleSchema::visibility($pdo, '', true);
                $this->addAllowedRows($allowed, 'project', $pdo,
                    "SELECT public_id FROM projects WHERE organization_id=? AND status<>'cancelled' AND {$projectVisibility} ORDER BY public_id", [(int)$organizationId]);
                continue;
            }
            if ($rootType !== 'standalone_client') throw new DomainException('portal-workspace-root-invalid');
            $client = $pdo->prepare('SELECT id FROM clients WHERE public_id=? AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL');
            $client->execute([$rootPublicId]);
            $clientId = $client->fetchColumn();
            if ($clientId === false) throw new DomainException('portal-workspace-root-missing');
            $allowed['standalone_client|' . $rootPublicId] = true;
            $allowed['client|' . $rootPublicId] = true;
            $projectVisibility = ProjectLifecycleSchema::visibility($pdo, '', true);
            $this->addAllowedRows($allowed, 'project', $pdo,
                "SELECT public_id FROM projects WHERE client_id=? AND status<>'cancelled' AND {$projectVisibility} ORDER BY public_id", [(int)$clientId]);
        }
        return $allowed;
    }

    /** @param array<string,true> $allowed @param list<mixed> $parameters */
    private function addAllowedRows(array &$allowed, string $type, PDO $pdo, string $sql, array $parameters): void
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $publicId) {
            $allowed[$type . '|' . self::publicId($publicId)] = true;
        }
    }

    /** @param array<string,mixed> $event */
    private function requireEventSubjectAllowed(PDO $pdo, int $profileId, array $event): void
    {
        if ($event['action'] === 'upsert') {
            $item = $event['item'];
            $key = (string)$item['subjectType'] . '|' . (string)$item['subjectPublicId'];
            if (!isset($this->allowedSubjects($pdo, $profileId)[$key])) {
                throw new DomainException('service-assignment-subject-outside-profile');
            }
            return;
        }
        $statement = $pdo->prepare(
            'SELECT 1 FROM portal_service_assignment_projection_records WHERE integration_profile_id=? AND assignment_public_id=? LIMIT 1'
        );
        $statement->execute([$profileId, (string)$event['assignmentPublicId']]);
        if (!$statement->fetchColumn()) throw new DomainException('service-assignment-subject-outside-profile');
    }

    private function requireProfile(PDO $pdo, array $profile): array
    {
        $profileId = (int)($profile['id'] ?? 0);
        if ($profileId < 1) throw new DomainException('service-assignment-profile-disabled');
        $profile = PortalProjectionService::lockProfileContract($pdo, $profileId);
        if (empty($profile['enabled']) || empty($profile['service_assignment_projection_enabled'])) {
            throw new DomainException('service-assignment-profile-disabled');
        }
        if (empty($profile['delivery_enabled'])) throw new DomainException('service-assignment-delivery-disabled');
        $this->serviceAssignmentRoute((string)($profile['portal_route'] ?? ''));
        return $profile;
    }

    private function requireTransaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new DomainException('service-assignment-transaction-required');
        }
    }

    private function serviceAssignmentRoute(string $portalRoute): string
    {
        try {
            PortalProjectionDeliveryConfigService::validateDestination($portalRoute);
        } catch (DomainException) {
            throw new DomainException('service-assignment-route-invalid');
        }
        return $portalRoute;
    }

    private function enqueue(PDO $pdo, array $profile, int $sequence, string $kind, array $payload, bool $revocation=false): void
    {
        $body = self::json($payload);
        if (strlen($body) > self::MAX_BODY_BYTES) throw new DomainException('service-assignment-body-limit');
        $pdo->prepare('INSERT INTO portal_projection_outbox (integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int)$profile['id'], $payload['deliveryId'], self::STREAM_KEY, 1, $sequence, $kind,
                self::ROUTE_TYPE, $revocation ? 1 : 0,
                $this->serviceAssignmentRoute((string)$profile['portal_route']), $profile['delivery_key_id'] ?? null, $body]);
    }

    private function normalizeEvent(array $event): array
    {
        if (($event['action'] ?? null) === 'upsert' && is_array($event['item'] ?? null)) {
            $item = $event['item'];
            $expected = ['assignmentPublicId','sourceVersion','subjectType','subjectPublicId','servicePublicId','serviceSourceVersion','active','effectiveFrom','effectiveUntil'];
            if (array_keys($item) !== $expected) throw new DomainException('service-assignment-event-invalid');
            self::publicId($item['assignmentPublicId'] ?? null);
            self::safeId($item['sourceVersion'] ?? null);
            self::publicId($item['subjectPublicId'] ?? null);
            self::publicId($item['servicePublicId'] ?? null);
            self::safeId($item['serviceSourceVersion'] ?? null);
            if (!in_array($item['subjectType'] ?? null, ['organization','standalone_client','department','client','project'], true)
                || !is_bool($item['active'] ?? null)) throw new DomainException('service-assignment-event-invalid');
            $effectiveFrom = self::wireTimestamp($item['effectiveFrom'] ?? null);
            $effectiveUntil = self::wireTimestamp($item['effectiveUntil'] ?? null);
            if ($effectiveFrom !== null && $effectiveUntil !== null
                && strcmp($effectiveUntil, $effectiveFrom) <= 0) {
                throw new DomainException('service-assignment-window-invalid');
            }
            $item['effectiveFrom'] = $effectiveFrom;
            $item['effectiveUntil'] = $effectiveUntil;
            return ['action' => 'upsert', 'item' => $item];
        }
        if (($event['action'] ?? null) === 'tombstone' && array_keys($event) === ['action','assignmentPublicId','sourceVersion']) {
            return ['action' => 'tombstone', 'assignmentPublicId' => self::publicId($event['assignmentPublicId']),
                'sourceVersion' => self::safeId($event['sourceVersion'])];
        }
        throw new DomainException('service-assignment-event-invalid');
    }

    private function replay(PDO $pdo, int $profileId, string $key, array $intent): ?array
    {
        $key = self::idempotencyKey($key);
        $statement = $pdo->prepare('SELECT payload_hash,result_json FROM portal_service_assignment_projection_receipts WHERE integration_profile_id=? AND idempotency_hash=?');
        $statement->execute([$profileId, hash('sha256', $key)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $payloadHash = hash('sha256', self::canonicalJson($intent));
        if (!hash_equals((string)$row['payload_hash'], $payloadHash)) {
            throw new DomainException('service-assignment-idempotency-conflict');
        }
        $result = json_decode((string)$row['result_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new DomainException('service-assignment-receipt-invalid');
        $result['status'] = 'replayed';
        return $result;
    }

    private function saveReceipt(PDO $pdo, int $profileId, string $key, array $intent, array $result): void
    {
        $key = self::idempotencyKey($key);
        $pdo->prepare('INSERT INTO portal_service_assignment_projection_receipts (integration_profile_id,idempotency_hash,payload_hash,result_json) VALUES (?,?,?,?)')
            ->execute([$profileId, hash('sha256', $key), hash('sha256', self::canonicalJson($intent)), self::json($result)]);
    }

    private function stateForUpdate(PDO $pdo, int $profileId): array|false
    {
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $pdo->prepare('SELECT * FROM portal_service_assignment_projection_state WHERE integration_profile_id=?' . $suffix);
        $statement->execute([$profileId]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function saveState(PDO $pdo, int $profileId, string $generation, int $sequence, string $snapshotHash): void
    {
        $update = $pdo->prepare('UPDATE portal_service_assignment_projection_state SET source_generation=?,source_sequence=?,snapshot_hash=? WHERE integration_profile_id=?');
        $update->execute([$generation, $sequence, $snapshotHash, $profileId]);
        if ($update->rowCount() === 0) {
            $pdo->prepare('INSERT INTO portal_service_assignment_projection_state (integration_profile_id,source_generation,source_sequence,snapshot_hash) VALUES (?,?,?,?)')
                ->execute([$profileId, $generation, $sequence, $snapshotHash]);
        }
    }

    private function replaceRecords(PDO $pdo, int $profileId, array $items): void
    {
        $pdo->prepare('DELETE FROM portal_service_assignment_projection_records WHERE integration_profile_id=?')->execute([$profileId]);
        foreach ($items as $item) $this->saveRecord($pdo, $profileId, $item);
    }

    private function saveRecord(PDO $pdo, int $profileId, array $item): void
    {
        $json = self::json($item);
        $hash = hash('sha256', $json);
        $update = $pdo->prepare('UPDATE portal_service_assignment_projection_records SET source_version=?,payload_hash=?,record_json=? WHERE integration_profile_id=? AND assignment_public_id=?');
        $update->execute([$item['sourceVersion'], $hash, $json, $profileId, $item['assignmentPublicId']]);
        if ($update->rowCount() === 0) {
            $pdo->prepare('INSERT INTO portal_service_assignment_projection_records (integration_profile_id,assignment_public_id,source_version,payload_hash,record_json) VALUES (?,?,?,?,?)')
                ->execute([$profileId, $item['assignmentPublicId'], $item['sourceVersion'], $hash, $json]);
        }
    }

    private static function publicId(mixed $value): string
    {
        if (!is_string($value) || $value !== trim($value)
            || preg_match('/^(?=.{1,128}$)(?=.*[A-Za-z])[A-Za-z0-9][A-Za-z0-9_-]*$/D', $value) !== 1) {
            throw new DomainException('service-assignment-public-id-invalid');
        }
        return $value;
    }

    private static function safeId(mixed $value): string
    {
        if (!is_string($value) || $value !== trim($value)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $value) !== 1) {
            throw new DomainException('service-assignment-id-invalid');
        }
        return $value;
    }

    private static function idempotencyKey(string $value): string
    {
        if ($value === '' || strlen($value) > 255 || preg_match('/^[\x21-\x7E]+$/D', $value) !== 1) {
            throw new DomainException('service-assignment-idempotency-key-invalid');
        }
        return $value;
    }

    private static function utc(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function wireTimestamp(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/D', $value) !== 1) {
            throw new DomainException('service-assignment-timestamp-invalid');
        }
        try {
            $timestamp = new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new DomainException('service-assignment-timestamp-invalid');
        }
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function canonicalJson(array $value): string
    {
        $sort = function (&$item) use (&$sort): void {
            if (!is_array($item)) return;
            if (array_is_list($item)) {
                foreach ($item as &$child) $sort($child);
                unset($child);
                return;
            }
            ksort($item);
            foreach ($item as &$child) $sort($child);
            unset($child);
        };
        $sort($value);
        return self::json($value);
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-' . dechex((hexdec($hex[16]) & 3) | 8) . substr($hex, 17, 3) . '-' . substr($hex, 20);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
