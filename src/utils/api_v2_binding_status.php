<?php
declare(strict_types=1);

function api_v2_binding_status_scope(string $kind): ?string
{
    return match ($kind) {
        'client' => 'directory.clients.binding_status.read',
        'organization' => 'directory.organizations.binding_status.read',
        'unit' => 'directory.units.binding_status.read',
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
    if (preg_match('#^/api/v2/bindings/(client|organization|unit)/status/([A-Za-z0-9_-]{1,1024})$#D', $path, $match) !== 1) return null;
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
        || !in_array($binding['resource_type'] ?? null, ['client', 'organization', 'unit'], true) || !is_string($binding['external_id'] ?? null)
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

/** Returns only an exact managed own-client association, never an email match.
 * Older schemas remain usable for ordinary binding-status clients. */
function api_v2_binding_status_client_portal_identity(PDO $pdo, array $client): array
{
    $unavailable = ['state'=>'unavailable','principalPublicId'=>null,'associationVersion'=>null];
    try {
        if (!isset($client['id'], $client['public_id']) || !array_key_exists('archived', $client)
            || !array_key_exists('deleted_at', $client)) return $unavailable;
        if ((int)$client['archived'] !== 0 || $client['deleted_at'] !== null)
            return ['state'=>'revoked','principalPublicId'=>null,'associationVersion'=>null];
        $read = $pdo->prepare("SELECT eligibility.portal_principal_id,eligibility.manual_state,eligibility.eligibility_status,
            eligibility.source_version eligibility_version,principal.public_id principal_public_id,
            principal.source_version principal_version,principal.authorization_version,principal.enabled,principal.revoked_at,
            root.access_state,root.root_public_id,organization.public_id organization_public_id,
            organization.archived organization_archived,organization.deleted_at organization_deleted_at,
            (SELECT COUNT(*) FROM portal_client_login_eligibility other WHERE other.portal_principal_id=eligibility.portal_principal_id) managed_count,
            (SELECT COUNT(*) FROM portal_principal_clients association WHERE association.portal_principal_id=eligibility.portal_principal_id) association_count,
            (SELECT COUNT(*) FROM portal_principal_clients association WHERE association.portal_principal_id=eligibility.portal_principal_id AND association.client_id=eligibility.client_id) own_association
            FROM portal_client_login_eligibility eligibility
            LEFT JOIN portal_principals principal ON principal.id=eligibility.portal_principal_id
            LEFT JOIN organizations organization ON organization.id=?
            LEFT JOIN portal_client_access_roots root
              ON root.root_type=CASE WHEN ? IS NULL THEN 'standalone_client' ELSE 'organization' END
              AND root.root_public_id=CASE WHEN ? IS NULL THEN ? ELSE organization.public_id END
            WHERE eligibility.client_id=? LIMIT 2");
        $organizationId = $client['organization_id'] ?? null;
        $read->execute([$organizationId,$organizationId,$organizationId,$client['public_id'],$client['id']]);
        $rows = $read->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) return ['state'=>'review_required','principalPublicId'=>null,'associationVersion'=>null];
        $row = $rows[0];
        if ($organizationId !== null && ($row['organization_public_id'] === null
            || (int)$row['organization_archived'] !== 0 || $row['organization_deleted_at'] !== null))
            return ['state'=>'revoked','principalPublicId'=>null,'associationVersion'=>null];
        if ($row['manual_state']==='revoked' || $row['eligibility_status']==='revoked' || $row['access_state']==='revoked'
            || ($row['principal_public_id']!==null && ((int)$row['enabled']!==1 || $row['revoked_at']!==null)))
            return ['state'=>'revoked','principalPublicId'=>null,'associationVersion'=>null];
        if ($row['manual_state']!=='automatic' || $row['eligibility_status']!=='eligible' || $row['access_state']!=='active'
            || (int)$row['managed_count']!==1 || (int)$row['association_count']!==1 || (int)$row['own_association']!==1
            || !is_string($row['principal_public_id']) || preg_match('/^[0-9a-f]{32}$/D',$row['principal_public_id'])!==1)
            return ['state'=>'review_required','principalPublicId'=>null,'associationVersion'=>null];
        $version = hash('sha256',json_encode(['client-portal-own-identity-v1',(string)$client['public_id'],
            (string)$row['principal_public_id'],(string)$row['eligibility_version'],(string)$row['principal_version'],
            (string)$row['authorization_version'],(string)$row['root_public_id'],
            $row['organization_public_id'],$row['organization_archived'],$row['organization_deleted_at']],JSON_THROW_ON_ERROR));
        return ['state'=>'eligible','principalPublicId'=>$row['principal_public_id'],'associationVersion'=>$version];
    } catch (PDOException) { return $unavailable; }
}

/** Reads one binding and rejects drift against the live canonical profile. */
function api_v2_binding_status_read(PDO $pdo, string $type, string $externalId, int $apiKeyId, array $headers, string $requestId, bool $includeClientPortalIdentity = false): array
{
    if (!in_array($type, ['client', 'organization', 'unit'], true) || $apiKeyId < 1) return ['status' => 404];
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        // Do not hold a binding lock while acquiring the canonical source and
        // revision-state locks. Delete writers use source -> state -> binding;
        // the initial lookup is intentionally unlocked and the binding is
        // re-read with a lock after the canonical rows are held.
        $identityStatement = $pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id AS application_pk
          FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id
          JOIN api_v2_history_identity history ON history.singleton=1
          WHERE api_key.id=? AND api_key.revoked_at IS NULL LIMIT 2' . $lock);
        $identityStatement->execute([$apiKeyId]); $identityRows = $identityStatement->fetchAll(PDO::FETCH_ASSOC);
        if (count($identityRows) === 0) { $pdo->commit(); return ['status' => 404]; }
        if (count($identityRows) !== 1) throw new RuntimeException('ambiguous binding identity');
        $identity = $identityRows[0];
        if (!hash_equals((string)$identity['source_instance_id'], (string)($headers['source'] ?? '')) || !hash_equals((string)$identity['application_id'], (string)($headers['application'] ?? '')) || !hash_equals((string)$identity['history_epoch'], (string)($headers['epoch'] ?? ''))) { $pdo->commit(); return ['status' => 409]; }
        $lookup = $pdo->prepare('SELECT public_id,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND external_id=? LIMIT 2');
        $lookup->execute([(int)$identity['application_pk'], $type, $externalId]); $bindingRows = $lookup->fetchAll(PDO::FETCH_ASSOC);
        if (count($bindingRows) === 0) { $pdo->commit(); return ['status' => 404]; }
        if (count($bindingRows) !== 1) throw new RuntimeException('ambiguous binding');
        if ((string)$bindingRows[0]['status'] === 'tombstoned') { $pdo->commit(); return ['status' => 410]; }
        $table = match ($type) { 'client'=>'clients', 'organization'=>'organizations', 'unit'=>'organization_departments' };
        $live = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE public_id=? LIMIT 2' . $lock); $live->execute([(string)$bindingRows[0]['public_id']]); $liveRows = $live->fetchAll(PDO::FETCH_ASSOC);
        // Directory writers lock the source row before its revision state.
        // A status read must follow that order to avoid a reader/writer cycle.
        $state = $pdo->prepare('SELECT present,CAST(revision AS CHAR) state_revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?' . $lock);
        $state->execute([$type, (string)$bindingRows[0]['public_id']]); $stateRow = $state->fetch(PDO::FETCH_ASSOC);
        if ($stateRow && (int)$stateRow['present'] === 0) { $pdo->commit(); return ['status' => 410]; }
        $binding = $pdo->prepare('SELECT binding.resource_type,binding.external_id,binding.public_id,CAST(binding.resource_revision AS CHAR) resource_revision,binding.resource_projection_sha256,binding.status,binding.created_at,CAST(auth.authorization_generation AS CHAR) authorization_generation
            FROM api_v2_directory_external_bindings binding LEFT JOIN api_v2_directory_authorization_state auth ON auth.application_pk=binding.application_pk
            WHERE binding.application_pk=? AND binding.resource_type=? AND binding.external_id=? LIMIT 2' . $lock);
        $binding->execute([(int)$identity['application_pk'], $type, $externalId]); $rows = $binding->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) { $pdo->commit(); return ['status' => 404]; }
        if (count($rows) !== 1) throw new RuntimeException('ambiguous binding');
        $row = $rows[0];
        if ((string)$row['status'] === 'tombstoned') { $pdo->commit(); return ['status' => 410]; }
        if ((string)$row['public_id'] !== (string)$bindingRows[0]['public_id'] || count($liveRows) !== 1 || !$stateRow || $row['authorization_generation'] === null
            || (string)$stateRow['state_revision'] !== (string)$row['resource_revision']) { $pdo->commit(); return ['status' => 409]; }
        $liveHash = api_v2_directory_canonical_hash($pdo, $type, $liveRows[0]);
        if (!hash_equals((string)$stateRow['projection_sha256'], $liveHash) || !hash_equals((string)$row['resource_projection_sha256'], $liveHash)) { $pdo->commit(); return ['status' => 409]; }
        $payload = api_v2_binding_status_payload($identity, $requestId, $row);
        if ($payload === null) throw new RuntimeException('invalid binding');
        if ($includeClientPortalIdentity && $type === 'client')
            $payload['clientPortalIdentity'] = api_v2_binding_status_client_portal_identity($pdo,$liveRows[0]);
        $pdo->commit(); return ['status' => 200, 'payload' => $payload];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
