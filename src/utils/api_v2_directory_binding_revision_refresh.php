<?php
declare(strict_types=1);

function api_v2_directory_binding_revision_refresh_parse(string $json): ?array
{
    if (strlen($json) > 32 * 1024) return null;
    try { $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    $fields = ['commandId', 'externalId', 'expectedPriorRevision', 'expectedLiveRevision', 'expectedAuthorizationGeneration'];
    if (!is_array($value) || count($value) !== count($fields) || array_diff(array_keys($value), $fields) !== [] || array_diff($fields, array_keys($value)) !== []) return null;
    foreach ($value as $item) if (!is_string($item)) return null;
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value['commandId']) !== 1
        || !api_v2_directory_binding_revision_refresh_positive($value['expectedPriorRevision'])
        || !api_v2_directory_binding_revision_refresh_positive($value['expectedLiveRevision'])
        || !api_v2_directory_binding_revision_refresh_generation($value['expectedAuthorizationGeneration'])
        || $value['externalId'] === '' || strlen($value['externalId']) > 764 || preg_match('//u', $value['externalId']) !== 1 || preg_match('/\p{C}/u', $value['externalId']) === 1) return null;
    $characters = preg_match_all('/./us', $value['externalId']);
    return $characters !== false && $characters >= 1 && $characters <= 191 ? $value : null;
}

function api_v2_directory_binding_revision_refresh_positive(string $value): bool
{
    return preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1 && (strlen($value) !== 19 || strcmp($value, '9223372036854775807') <= 0);
}

function api_v2_directory_binding_revision_refresh_generation(string $value): bool
{
    return preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1 && (strlen($value) !== 19 || strcmp($value, '9223372036854775807') < 0);
}

function api_v2_directory_binding_revision_refresh_stored_generation(string $value): bool
{
    return preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1 && (strlen($value) !== 19 || strcmp($value, '9223372036854775807') <= 0);
}

function api_v2_directory_binding_revision_refresh_greater_than(string $left, string $right): bool
{
    return strlen($left) !== strlen($right) ? strlen($left) > strlen($right) : strcmp($left, $right) > 0;
}

function api_v2_directory_binding_revision_refresh_result(array $identity, array $command, string $type, string $publicId, string $resultGeneration, string $requestId, bool $replayed): array
{
    return ['sourceInstanceId' => $identity['source_instance_id'], 'applicationId' => $identity['application_id'], 'historyEpoch' => $identity['history_epoch'], 'requestId' => $requestId, 'replayed' => $replayed,
        'result' => ['resource' => ['type' => $type, 'id' => $command['externalId'], 'revision' => $command['expectedLiveRevision']],
            'binding' => ['publicId' => $publicId, 'previousRevision' => $command['expectedPriorRevision'], 'authorizationGeneration' => $resultGeneration]]];
}

/** @return array{status:int,payload?:array} */
function api_v2_directory_binding_revision_refresh_write(PDO $pdo, string $type, array $command, int $apiKeyId, array $headers, string $requestId): array
{
    if (!in_array($type, ['client', 'organization', 'unit'], true) || $apiKeyId < 1 || $pdo->inTransaction()) throw new InvalidArgumentException('Invalid directory binding revision refresh');
    $pdo->beginTransaction();
    try {
        api_v2_directory_management_acquire_shared_gate($pdo, false);
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $identityStatement = $pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id AS application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL' . $lock);
        $identityStatement->execute([$apiKeyId]); $identity = $identityStatement->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !api_v2_identity_is_valid($identity) || !hash_equals((string)$identity['source_instance_id'], (string)($headers['source'] ?? '')) || !hash_equals((string)$identity['application_id'], (string)($headers['application'] ?? '')) || !hash_equals((string)$identity['history_epoch'], (string)($headers['epoch'] ?? ''))) { $pdo->rollBack(); return ['status' => 409]; }
        $appPk = (int)$identity['application_pk'];
        $requestHash = hash('sha256', json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $receiptStatement = $pdo->prepare('SELECT request_sha256,external_id,public_id,CAST(expected_prior_revision AS CHAR) expected_prior_revision,CAST(result_revision AS CHAR) result_revision,result_projection_sha256,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_authorization_generation AS CHAR) result_authorization_generation FROM api_v2_directory_binding_revision_refresh_receipts WHERE application_pk=? AND resource_type=? AND history_epoch=? AND command_id=?' . $lock);
        $receiptStatement->execute([$appPk, $type, $identity['history_epoch'], $command['commandId']]); $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC);
        if ($receipt && (!hash_equals((string)$receipt['request_sha256'], $requestHash) || !hash_equals((string)$receipt['external_id'], $command['externalId']) || (string)$receipt['expected_prior_revision'] !== $command['expectedPriorRevision'] || (string)$receipt['result_revision'] !== $command['expectedLiveRevision'] || (string)$receipt['expected_authorization_generation'] !== $command['expectedAuthorizationGeneration'])) { $pdo->rollBack(); return ['status' => 409]; }
        $bindingStatement = $pdo->prepare('SELECT public_id,CAST(resource_revision AS CHAR) resource_revision,resource_projection_sha256,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND external_id=? LIMIT 2' . $lock);
        $bindingStatement->execute([$appPk, $type, $command['externalId']]); $binding = $bindingStatement->fetch(PDO::FETCH_ASSOC);
        if (!$binding || (string)$binding['status'] !== 'active') { $pdo->rollBack(); return ['status' => 409]; }
        if ($bindingStatement->fetch(PDO::FETCH_ASSOC) !== false) { $pdo->rollBack(); return ['status' => 409]; }
        $table = match ($type) { 'client'=>'clients', 'organization'=>'organizations', 'unit'=>'organization_departments' };
        // Directory writers lock their source row before revision state; retain
        // that order to avoid a source-row/state deadlock cycle.
        $liveStatement = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE public_id=? LIMIT 2' . $lock); $liveStatement->execute([$binding['public_id']]); $liveRows = $liveStatement->fetchAll(PDO::FETCH_ASSOC);
        if (count($liveRows) !== 1) { $pdo->rollBack(); return ['status' => 409]; }
        $stateStatement = $pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=? LIMIT 2' . $lock);
        $stateStatement->execute([$type, $binding['public_id']]); $stateRows = $stateStatement->fetchAll(PDO::FETCH_ASSOC);
        if (count($stateRows) !== 1 || (int)$stateRows[0]['present'] !== 1) { $pdo->rollBack(); return ['status' => 409]; }
        $state = $stateRows[0];
        if (!hash_equals((string)$state['projection_sha256'], api_v2_directory_canonical_hash($pdo, $type, $liveRows[0]))) { $pdo->rollBack(); return ['status' => 409]; }
        if ($receipt) {
            $authStatement = $pdo->prepare('SELECT CAST(authorization_generation AS CHAR) authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock);
            $authStatement->execute([$appPk]); $currentGeneration = $authStatement->fetchColumn(); $currentGeneration = is_scalar($currentGeneration) ? (string)$currentGeneration : '';
            if (!api_v2_directory_binding_revision_refresh_positive((string)$receipt['result_authorization_generation']) || !api_v2_directory_binding_revision_refresh_stored_generation($currentGeneration) || (!api_v2_directory_binding_revision_refresh_greater_than($currentGeneration, (string)$receipt['result_authorization_generation']) && $currentGeneration !== (string)$receipt['result_authorization_generation']) || (string)$binding['public_id'] !== (string)$receipt['public_id'] || (string)$binding['resource_revision'] !== (string)$receipt['result_revision'] || !hash_equals((string)$binding['resource_projection_sha256'], (string)$receipt['result_projection_sha256']) || (string)$state['revision'] !== (string)$receipt['result_revision'] || !hash_equals((string)$state['projection_sha256'], (string)$receipt['result_projection_sha256'])) { $pdo->rollBack(); return ['status' => 409]; }
            $pdo->commit(); return ['status' => 200, 'payload' => api_v2_directory_binding_revision_refresh_result($identity, $command, $type, (string)$receipt['public_id'], (string)$receipt['result_authorization_generation'], $requestId, true)];
        }
        if (!api_v2_directory_binding_revision_refresh_greater_than($command['expectedLiveRevision'], $command['expectedPriorRevision'])) { $pdo->rollBack(); return ['status' => 409]; }
        if ((string)$binding['resource_revision'] !== $command['expectedPriorRevision'] || (string)$state['revision'] !== $command['expectedLiveRevision']) { $pdo->rollBack(); return ['status' => 409]; }
        $authStatement = $pdo->prepare('SELECT CAST(authorization_generation AS CHAR) authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock);
        $authStatement->execute([$appPk]); $generation = $authStatement->fetchColumn();
        $generation = is_scalar($generation) ? (string)$generation : '';
        if (!api_v2_directory_binding_revision_refresh_generation($generation) || !hash_equals($generation, $command['expectedAuthorizationGeneration'])) { $pdo->rollBack(); return ['status' => 409]; }
        $resultGeneration = (string)((int)$generation + 1);
        $update = $pdo->prepare('UPDATE api_v2_directory_external_bindings SET resource_revision=?,resource_projection_sha256=? WHERE application_pk=? AND resource_type=? AND external_id=? AND public_id=? AND resource_revision=? AND status=\'active\'');
        $update->execute([$command['expectedLiveRevision'], $state['projection_sha256'], $appPk, $type, $command['externalId'], $binding['public_id'], $command['expectedPriorRevision']]);
        if ($update->rowCount() !== 1) { $pdo->rollBack(); return ['status' => 409]; }
        $pdo->prepare('INSERT INTO api_v2_directory_binding_revision_refresh_receipts(application_pk,resource_type,history_epoch,command_id,request_sha256,external_id,public_id,expected_prior_revision,result_revision,result_projection_sha256,expected_authorization_generation,result_authorization_generation) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$appPk, $type, $identity['history_epoch'], $command['commandId'], $requestHash, $command['externalId'], $binding['public_id'], $command['expectedPriorRevision'], $command['expectedLiveRevision'], $state['projection_sha256'], $command['expectedAuthorizationGeneration'], $resultGeneration]);
        $advance = $pdo->prepare('UPDATE api_v2_directory_authorization_state SET authorization_generation=authorization_generation+1 WHERE application_pk=? AND authorization_generation=?');
        $advance->execute([$appPk, $generation]); if ($advance->rowCount() !== 1) { $pdo->rollBack(); return ['status' => 409]; }
        $pdo->commit(); return ['status' => 200, 'payload' => api_v2_directory_binding_revision_refresh_result($identity, $command, $type, (string)$binding['public_id'], $resultGeneration, $requestId, false)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); if ($error instanceof PDOException && $error->getCode() === '23000') return ['status' => 409]; throw $error; }
}
