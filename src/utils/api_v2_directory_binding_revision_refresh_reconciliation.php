<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_binding_revision_refresh.php';
require_once __DIR__ . '/api_scopes.php';

function api_v2_directory_binding_revision_refresh_reconciliation_result(
    array $identity,
    array $command,
    string $type,
    string $requestHash,
    string $requestId,
    string $status,
    array $binding,
    array $state,
    string $generation,
    ?array $acknowledgement
): array {
    return [
        'sourceInstanceId' => (string)$identity['source_instance_id'],
        'applicationId' => (string)$identity['application_id'],
        'historyEpoch' => (string)$identity['history_epoch'],
        'requestId' => $requestId,
        'status' => $status,
        'command' => ['commandId' => $command['commandId'], 'requestSha256' => $requestHash],
        'observation' => [
            'resource' => ['type' => $type, 'id' => $command['externalId'], 'revision' => (string)$state['revision'], 'projectionSha256' => (string)$state['projection_sha256']],
            'binding' => ['publicId' => (string)$binding['public_id'], 'revision' => (string)$binding['resource_revision'], 'projectionSha256' => (string)$binding['resource_projection_sha256']],
            'authorizationGeneration' => $generation,
        ],
        'acknowledgement' => $acknowledgement,
    ];
}

/** @return array{status:int,payload?:array} */
function api_v2_directory_binding_revision_refresh_reconcile(PDO $pdo, string $type, array $command, int $apiKeyId, array $headers, string $requestId): array
{
    if (!in_array($type, ['client', 'organization', 'unit'], true) || $apiKeyId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Invalid directory binding revision refresh reconciliation');
    }
    $pdo->beginTransaction();
    try {
        api_v2_directory_management_acquire_shared_gate($pdo, false);
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $identityStatement = $pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id AS application_pk,api_key.scopes FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL' . $lock);
        $identityStatement->execute([$apiKeyId]);
        $identity = $identityStatement->fetch(PDO::FETCH_ASSOC);
        $plural = ['client' => 'clients', 'organization' => 'organizations', 'unit' => 'units'][$type];
        $refreshScope = 'directory.' . $plural . '.binding.revision.refresh';
        if (!$identity || !api_v2_identity_is_valid($identity)
            || !hash_equals((string)$identity['source_instance_id'], (string)($headers['source'] ?? ''))
            || !hash_equals((string)$identity['application_id'], (string)($headers['application'] ?? ''))
            || !hash_equals((string)$identity['history_epoch'], (string)($headers['epoch'] ?? ''))
            || !api_key_has_scope($identity['scopes'] ?? '', 'api.capabilities.read', false)
            || !api_key_has_scope($identity['scopes'] ?? '', $refreshScope, false)
            || in_array('full', api_normalize_scopes($identity['scopes'] ?? ''), true)) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $appPk = (int)$identity['application_pk'];
        $requestHash = api_v2_directory_binding_revision_refresh_request_hash($command);
        $receiptStatement = $pdo->prepare('SELECT request_sha256,external_id,public_id,CAST(expected_prior_revision AS CHAR) expected_prior_revision,CAST(result_revision AS CHAR) result_revision,result_projection_sha256,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_authorization_generation AS CHAR) result_authorization_generation FROM api_v2_directory_binding_revision_refresh_receipts WHERE application_pk=? AND resource_type=? AND history_epoch=? AND command_id=?' . $lock);
        $receiptStatement->execute([$appPk, $type, $identity['history_epoch'], $command['commandId']]);
        $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC);
        if ($receipt && (!hash_equals((string)$receipt['request_sha256'], $requestHash)
            || !hash_equals((string)$receipt['external_id'], $command['externalId'])
            || (string)$receipt['expected_prior_revision'] !== $command['expectedPriorRevision']
            || (string)$receipt['result_revision'] !== $command['expectedLiveRevision']
            || (string)$receipt['expected_authorization_generation'] !== $command['expectedAuthorizationGeneration'])) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $bindingStatement = $pdo->prepare('SELECT public_id,CAST(resource_revision AS CHAR) resource_revision,resource_projection_sha256,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND external_id=? LIMIT 2' . $lock);
        $bindingStatement->execute([$appPk, $type, $command['externalId']]);
        $binding = $bindingStatement->fetch(PDO::FETCH_ASSOC);
        if (!$binding || (string)$binding['status'] !== 'active' || $bindingStatement->fetch(PDO::FETCH_ASSOC) !== false) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $table = match ($type) { 'client' => 'clients', 'organization' => 'organizations', 'unit' => 'organization_departments' };
        $liveStatement = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE public_id=? LIMIT 2' . $lock);
        $liveStatement->execute([$binding['public_id']]);
        $liveRows = $liveStatement->fetchAll(PDO::FETCH_ASSOC);
        if (count($liveRows) !== 1) { $pdo->rollBack(); return ['status' => 409]; }
        $stateStatement = $pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=? LIMIT 2' . $lock);
        $stateStatement->execute([$type, $binding['public_id']]);
        $stateRows = $stateStatement->fetchAll(PDO::FETCH_ASSOC);
        if (count($stateRows) !== 1 || (int)$stateRows[0]['present'] !== 1) { $pdo->rollBack(); return ['status' => 409]; }
        $state = $stateRows[0];
        if (!hash_equals((string)$state['projection_sha256'], api_v2_directory_canonical_hash($pdo, $type, $liveRows[0]))) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $authStatement = $pdo->prepare('SELECT CAST(authorization_generation AS CHAR) authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock);
        $authStatement->execute([$appPk]);
        $generationValue = $authStatement->fetchColumn();
        $generation = is_scalar($generationValue) ? (string)$generationValue : '';
        if (!api_v2_directory_binding_revision_refresh_stored_generation($generation)) {
            $pdo->rollBack(); return ['status' => 409];
        }
        if ($receipt) {
            if (!api_v2_directory_binding_revision_refresh_positive((string)$receipt['result_authorization_generation'])
                || (!api_v2_directory_binding_revision_refresh_greater_than($generation, (string)$receipt['result_authorization_generation']) && $generation !== (string)$receipt['result_authorization_generation'])
                || (string)$binding['public_id'] !== (string)$receipt['public_id']
                || (string)$binding['resource_revision'] !== (string)$receipt['result_revision']
                || !hash_equals((string)$binding['resource_projection_sha256'], (string)$receipt['result_projection_sha256'])
                || (string)$state['revision'] !== (string)$receipt['result_revision']
                || !hash_equals((string)$state['projection_sha256'], (string)$receipt['result_projection_sha256'])) {
                $pdo->rollBack(); return ['status' => 409];
            }
            $acknowledgement = api_v2_directory_binding_revision_refresh_result($identity, $command, $type, (string)$receipt['public_id'], (string)$receipt['result_authorization_generation'], $requestId, true);
            $payload = api_v2_directory_binding_revision_refresh_reconciliation_result($identity, $command, $type, $requestHash, $requestId, 'acknowledged', $binding, $state, $generation, $acknowledgement);
            $pdo->commit(); return ['status' => 200, 'payload' => $payload];
        }
        $terminal = api_v2_directory_binding_revision_refresh_greater_than($command['expectedLiveRevision'], $command['expectedPriorRevision'])
            && api_v2_directory_binding_revision_refresh_greater_than($generation, $command['expectedAuthorizationGeneration'])
            && (string)$binding['resource_revision'] === $command['expectedPriorRevision']
            && (string)$state['revision'] === $command['expectedLiveRevision'];
        $payload = api_v2_directory_binding_revision_refresh_reconciliation_result($identity, $command, $type, $requestHash, $requestId, $terminal ? 'terminal_conflict' : 'unresolved', $binding, $state, $generation, null);
        $pdo->commit(); return ['status' => 200, 'payload' => $payload];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
