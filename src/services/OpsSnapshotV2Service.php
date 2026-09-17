<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Provider-neutral, cursor-paginated bootstrap projection for Sync Contract v2.
 *
 * The first foundation release intentionally covers only organization, client,
 * project, service-location, and job records. The resource catalog is explicit
 * so financial data, private links, credentials, and storage paths cannot enter
 * the contract accidentally.
 */
final class OpsSnapshotV2Service
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly SyncContractV2Service $contract = new SyncContractV2Service()
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(
        PDO $pdo,
        int $apiKeyId,
        int $limit = self::DEFAULT_LIMIT,
        ?string $snapshotId = null,
        ?string $cursor = null,
        ?DateTimeImmutable $now = null
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit must be between 1 and ' . self::MAX_LIMIT . '.');
        }
        if ($cursor !== null && trim((string)$snapshotId) === '') {
            throw new InvalidArgumentException('snapshot_id is required when cursor is provided.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $snapshot = trim((string)$snapshotId) === ''
                ? $this->contract->beginSnapshot($pdo, $apiKeyId, $now)
                : $this->contract->resumeSnapshot($pdo, (string)$snapshotId, $apiKeyId, $now);
            [$typeIndex, $afterId] = $cursor === null || trim($cursor) === ''
                ? [0, 0]
                : $this->decodeCursor($cursor, $snapshot['id']);

            $records = $this->readRecords($pdo, $typeIndex, $afterId, $limit + 1);
            $hasMore = count($records) > $limit;
            if ($hasMore) {
                $records = array_slice($records, 0, $limit);
            }

            $items = [];
            foreach ($records as $record) {
                $version = $this->contract->observeResource(
                    $pdo,
                    $record['type'],
                    $record['id'],
                    $record['data']
                );
                $items[] = [
                    'resource' => [
                        'type' => $record['type'],
                        'id' => $record['id'],
                        'version' => (string)$version,
                    ],
                    'data' => $record['data'],
                ];
            }

            $nextCursor = null;
            if ($hasMore && $records !== []) {
                $last = $records[array_key_last($records)];
                $nextCursor = $this->encodeCursor(
                    $snapshot['id'],
                    (int)$last['type_index'],
                    (int)$last['id']
                );
            }

            $result = [
                'contract_version' => SyncContractV2Service::CONTRACT_VERSION,
                'source_instance_id' => $snapshot['source_instance_id'],
                'snapshot' => [
                    'id' => $snapshot['id'],
                    'generated_at' => $snapshot['generated_at'],
                    'expires_at' => $snapshot['expires_at'],
                    'high_water_sequence' => $snapshot['high_water_sequence'],
                ],
                'items' => $items,
                'next_cursor' => $nextCursor,
            ];

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Return exactly the safe public projection used by both snapshots and
     * future transactional event adapters.
     *
     * @return array<string,mixed>|null
     */
    public function projectResource(PDO $pdo, string $resourceType, int $resourceId): ?array
    {
        $definitions = $this->definitions();
        foreach ($definitions as $definition) {
            if ($definition['type'] !== $resourceType) {
                continue;
            }
            $statement = $pdo->prepare(
                $definition['select'] . ' WHERE ' . $definition['where'] . ' AND id = :resource_id'
            );
            $statement->bindValue(':resource_id', $resourceId, PDO::PARAM_INT);
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->normalizeData($row, $definition) : null;
        }
        throw new InvalidArgumentException('Unsupported Sync Contract v2 resource type.');
    }

    /**
     * @return list<array{type:string,type_index:int,id:string,data:array<string,mixed>}>
     */
    private function readRecords(PDO $pdo, int $typeIndex, int $afterId, int $fetchLimit): array
    {
        $definitions = $this->definitions();
        if ($typeIndex < 0 || $typeIndex >= count($definitions)) {
            throw new InvalidArgumentException('The cursor resource type is invalid.');
        }

        $records = [];
        foreach ($definitions as $index => $definition) {
            if ($index < $typeIndex || count($records) >= $fetchLimit) {
                continue;
            }
            $definitionAfterId = $index === $typeIndex ? $afterId : 0;
            $remaining = $fetchLimit - count($records);
            $statement = $pdo->prepare(
                $definition['select']
                . ' WHERE ' . $definition['where']
                . ' AND id > :after_id ORDER BY id LIMIT :fetch_limit'
            );
            $statement->bindValue(':after_id', $definitionAfterId, PDO::PARAM_INT);
            $statement->bindValue(':fetch_limit', $remaining, PDO::PARAM_INT);
            $statement->execute();
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (string)$row['id'];
                $records[] = [
                    'type' => $definition['type'],
                    'type_index' => $index,
                    'id' => $id,
                    'data' => $this->normalizeData($row, $definition),
                ];
            }
        }
        return $records;
    }

    /**
     * @return list<array{
     *   type:string,
     *   select:string,
     *   where:string,
     *   ids:list<string>,
     *   public_ids:list<string>,
     *   booleans:list<string>,
     *   timestamps:list<string>
     * }>
     */
    private function definitions(): array
    {
        return [
            [
                'type' => 'organization',
                'select' => 'SELECT id,public_id,name,address_line1,address_line2,city,state,postal_code,country,created_at,updated_at FROM organizations',
                'where' => '1=1',
                'ids' => [],
                'public_ids' => ['public_id'],
                'booleans' => [],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            [
                'type' => 'client',
                'select' => 'SELECT id,public_id,name,email,phone,address_line1,address_line2,city,state,postal_code,country,
                                    organization_id,client_type,archived,created_at,updated_at
                             FROM clients',
                'where' => 'deleted_at IS NULL',
                'ids' => ['organization_id'],
                'public_ids' => ['public_id'],
                'booleans' => ['archived'],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            [
                'type' => 'project',
                'select' => 'SELECT id,public_id,client_id,parent_id,organization_id,business_unit_id,manager_user_id,
                                    name,description,status,start_date,end_date,estimated_start,estimated_end,
                                    created_at,updated_at
                             FROM projects',
                'where' => '1=1',
                'ids' => [
                    'client_id', 'parent_id', 'organization_id', 'business_unit_id', 'manager_user_id',
                ],
                'public_ids' => ['public_id'],
                'booleans' => [],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            [
                'type' => 'service_location',
                'select' => 'SELECT id,organization_id,client_id,project_id,name,address_line1,address_line2,
                                    city,state,postal_code,country,archived,created_at,updated_at
                             FROM service_locations',
                'where' => '1=1',
                'ids' => ['organization_id', 'client_id', 'project_id'],
                'public_ids' => [],
                'booleans' => ['archived'],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            [
                'type' => 'job',
                'select' => 'SELECT id,client_id,organization_id,project_id,job_code,job_origin,status,
                                    completed_at,default_service_location_id,archived,created_at,updated_at
                             FROM jobs',
                'where' => '1=1',
                'ids' => [
                    'client_id', 'organization_id', 'project_id', 'default_service_location_id',
                ],
                'public_ids' => [],
                'booleans' => ['archived'],
                'timestamps' => ['completed_at', 'created_at', 'updated_at'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array{ids:list<string>,public_ids:list<string>,booleans:list<string>,timestamps:list<string>} $definition
     * @return array<string,mixed>
     */
    private function normalizeData(array $row, array $definition): array
    {
        unset($row['id']);
        // Migration 0062 assigns these once. Export only the stored
        // source-issued identifier; snapshot reads must never manufacture or
        // repair cross-system identity.
        foreach ($definition['public_ids'] as $field) {
            if (!array_key_exists($field, $row)
                || !is_string($row[$field])
                || !preg_match('/^[0-9a-f]{32}$/D', $row[$field])
            ) {
                throw new \UnexpectedValueException('The operations source public ID is missing or invalid.');
            }
        }
        foreach ($definition['ids'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (string)$row[$field];
            }
        }
        foreach ($definition['booleans'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (bool)$row[$field];
            }
        }
        foreach ($definition['timestamps'] as $field) {
            if (isset($row[$field]) && trim((string)$row[$field]) !== '') {
                $row[$field] = $this->normalizeTimestamp((string)$row[$field]);
            }
        }
        return $row;
    }

    private function normalizeTimestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function encodeCursor(string $snapshotId, int $typeIndex, int $afterId): string
    {
        $json = json_encode([
            'v' => 2,
            'snapshot_id' => $snapshotId,
            'type_index' => $typeIndex,
            'after_id' => (string)$afterId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{0:int,1:int}
     */
    private function decodeCursor(string $cursor, string $snapshotId): array
    {
        $cursor = trim($cursor);
        if ($cursor === '' || strlen($cursor) > 1000 || !preg_match('/^[A-Za-z0-9_-]+$/', $cursor)) {
            throw new InvalidArgumentException('cursor is invalid.');
        }
        $padding = (4 - strlen($cursor) % 4) % 4;
        $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload)
            || ($payload['v'] ?? null) !== 2
            || !hash_equals($snapshotId, (string)($payload['snapshot_id'] ?? ''))
            || filter_var($payload['type_index'] ?? null, FILTER_VALIDATE_INT) === false
            || !preg_match('/^\d+$/', (string)($payload['after_id'] ?? ''))
        ) {
            throw new InvalidArgumentException('cursor is invalid for this snapshot.');
        }
        return [(int)$payload['type_index'], (int)$payload['after_id']];
    }
}
