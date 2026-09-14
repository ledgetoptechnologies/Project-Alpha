<?php
declare(strict_types=1);

function api_v2_binding_status_scope(string $kind): ?string
{
    return match ($kind) {
        'client' => 'directory.clients.binding_status.read',
        'organization' => 'directory.organizations.binding_status.read',
        default => null,
    };
}

function api_v2_binding_status_decode_external_id(string $encoded): ?string
{
    if ($encoded === '' || strlen($encoded) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) return null;
    $base64 = strtr($encoded, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $value = base64_decode($base64, true);
    if (!is_string($value) || $value === '' || strlen($value) > 764 || preg_match('//u', $value) !== 1 || preg_match('/\p{C}/u', $value) === 1) return null;
    $characterCount = preg_match_all('/./us', $value, $characters);
    if ($characterCount === false || $characterCount < 1 || $characterCount > 191) return null;
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=') === $encoded ? $value : null;
}

function api_v2_binding_status_route(string $path): ?array
{
    if (preg_match('#^/api/v2/bindings/(client|organization)/status/([A-Za-z0-9_-]{1,1024})$#D', $path, $match) !== 1) return null;
    $externalId = api_v2_binding_status_decode_external_id($match[2]);
    return $externalId === null ? null : ['kind' => $match[1], 'externalId' => $externalId];
}

function api_v2_binding_status_timestamp(string $value): ?string
{
    try {
        $timestamp = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
    } catch (Throwable) { return null; }
}

function api_v2_binding_status_payload(array $identity, string $requestId, array $binding): ?array
{
    if (!api_v2_identity_is_valid($identity) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId) !== 1
        || !in_array($binding['resource_type'] ?? null, ['client', 'organization'], true) || !is_string($binding['external_id'] ?? null)
        || !is_string($binding['public_id'] ?? null) || preg_match('/^[0-9a-f]{32}$/D', $binding['public_id']) !== 1
        || !is_string($binding['resource_revision'] ?? null) || preg_match('/^[1-9][0-9]{0,18}$/D', $binding['resource_revision']) !== 1
        || !is_string($binding['authorization_generation'] ?? null) || preg_match('/^(0|[1-9][0-9]{0,18})$/D', $binding['authorization_generation']) !== 1
        || (strlen($binding['authorization_generation']) === 19 && strcmp($binding['authorization_generation'], '9223372036854775807') > 0)) return null;
    $createdAt = api_v2_binding_status_timestamp((string)($binding['created_at'] ?? ''));
    if ($createdAt === null) return null;
    return ['apiVersion' => '2', 'sourceInstanceId' => $identity['source_instance_id'], 'historyEpoch' => $identity['history_epoch'],
        'authorizationGeneration' => $binding['authorization_generation'], 'binding' => ['type' => $binding['resource_type'], 'externalId' => $binding['external_id'], 'publicId' => $binding['public_id'], 'createdAt' => $createdAt],
        'resource' => ['revision' => $binding['resource_revision'], 'present' => true], 'applicationId' => $identity['application_id'], 'requestId' => $requestId];
}

/** Reads one binding and rejects drift against the live canonical profile. */
function api_v2_binding_status_read(PDO $pdo, string $type, string $externalId, int $apiKeyId, array $headers, string $requestId): array
{
    if (!in_array($type, ['client', 'organization'], true) || $apiKeyId < 1) return ['status' => 404];
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,binding.resource_type,binding.external_id,binding.public_id,CAST(binding.resource_revision AS CHAR) resource_revision,binding.resource_projection_sha256,binding.status,binding.created_at,state.present,CAST(state.revision AS CHAR) state_revision,state.projection_sha256,CAST(auth.authorization_generation AS CHAR) authorization_generation
          FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1
          JOIN api_v2_directory_external_bindings binding ON binding.application_pk=app.id AND binding.resource_type=? AND binding.external_id=?
          JOIN api_v2_directory_resource_state state ON state.resource_type=binding.resource_type AND state.public_id=binding.public_id
          LEFT JOIN api_v2_directory_authorization_state auth ON auth.application_pk=app.id WHERE api_key.id=? AND api_key.revoked_at IS NULL LIMIT 2' . $lock);
        $statement->execute([$type, $externalId, $apiKeyId]); $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) { $pdo->commit(); return ['status' => 404]; }
        if (count($rows) !== 1) throw new RuntimeException('ambiguous binding');
        $row = $rows[0];
        if (!hash_equals((string)$row['source_instance_id'], (string)($headers['source'] ?? '')) || !hash_equals((string)$row['application_id'], (string)($headers['application'] ?? '')) || !hash_equals((string)$row['history_epoch'], (string)($headers['epoch'] ?? ''))) { $pdo->commit(); return ['status' => 409]; }
        if ((string)$row['status'] === 'tombstoned' || (int)$row['present'] !== 1) { $pdo->commit(); return ['status' => 410]; }
        $table = $type === 'client' ? 'clients' : 'organizations';
        $live = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE public_id=? LIMIT 2' . $lock); $live->execute([(string)$row['public_id']]); $liveRows = $live->fetchAll(PDO::FETCH_ASSOC);
        if (count($liveRows) !== 1 || $row['authorization_generation'] === null || (string)$row['state_revision'] !== (string)$row['resource_revision']) { $pdo->commit(); return ['status' => 409]; }
        $liveHash = api_v2_directory_projection_hash($type, $liveRows[0]);
        if (!hash_equals((string)$row['projection_sha256'], $liveHash) || !hash_equals((string)$row['resource_projection_sha256'], $liveHash)) { $pdo->commit(); return ['status' => 409]; }
        $payload = api_v2_binding_status_payload($row, $requestId, $row);
        if ($payload === null) throw new RuntimeException('invalid binding');
        $pdo->commit(); return ['status' => 200, 'payload' => $payload];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
