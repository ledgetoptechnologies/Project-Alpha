<?php
declare(strict_types=1);

function api_v2_directory_binding_command_parse(string $json): ?array
{
    if (strlen($json) > 32 * 1024) return null;
    try { $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    if (!is_array($value) || count($value) !== 4
        || array_diff(array_keys($value), ['commandId', 'externalId', 'expectedPublicId', 'expectedRevision']) !== []
        || array_diff(['commandId', 'externalId', 'expectedPublicId', 'expectedRevision'], array_keys($value)) !== []) return null;
    foreach ($value as $item) if (!is_string($item)) return null;
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value['commandId']) !== 1
        || preg_match('/^[0-9a-f]{32}$/D', $value['expectedPublicId']) !== 1
        || preg_match('/^[1-9][0-9]{0,18}$/D', $value['expectedRevision']) !== 1
        || (strlen($value['expectedRevision']) === 19 && strcmp($value['expectedRevision'], '9223372036854775807') > 0)
        || $value['externalId'] === '' || strlen($value['externalId']) > 764
        || preg_match('//u', $value['externalId']) !== 1 || preg_match('/\p{C}/u', $value['externalId']) === 1) return null;
    $characters = preg_match_all('/./us', $value['externalId']);
    return $characters !== false && $characters >= 1 && $characters <= 191
        ? ['commandId' => $value['commandId'], 'externalId' => $value['externalId'], 'expectedPublicId' => $value['expectedPublicId'], 'expectedRevision' => $value['expectedRevision']]
        : null;
}

function api_v2_directory_binding_command_result(array $identity, array $command, string $type, string $requestId, bool $replayed): array
{
    return [
        'sourceInstanceId' => $identity['source_instance_id'],
        'applicationId' => $identity['application_id'],
        'historyEpoch' => $identity['history_epoch'],
        'requestId' => $requestId,
        'replayed' => $replayed,
        'result' => [
            'resource' => ['type' => $type, 'id' => $command['externalId'], 'revision' => $command['expectedRevision']],
            'binding' => ['publicId' => $command['expectedPublicId']],
        ],
    ];
}

/** @return array{status:int,payload?:array} */
function api_v2_directory_binding_command_write(PDO $pdo, string $type, array $command, int $apiKeyId, array $headers, string $requestId): array
{
    if (!in_array($type, ['client', 'organization'], true) || $apiKeyId < 1 || $pdo->inTransaction()) throw new InvalidArgumentException('Invalid directory binding command');
    $pdo->beginTransaction();
    try {
        // Serialize commands for this application before checking receipts or
        // uniqueness, making concurrent retries deterministic on MySQL.
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id AS application_pk
            FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id
            JOIN api_v2_history_identity history ON history.singleton=1
            WHERE api_key.id=? AND api_key.revoked_at IS NULL' . $lock);
        $stmt->execute([$apiKeyId]); $identity = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !api_v2_identity_is_valid($identity)
            || !hash_equals((string)$identity['source_instance_id'], (string)($headers['source'] ?? ''))
            || !hash_equals((string)$identity['application_id'], (string)($headers['application'] ?? ''))
            || !hash_equals((string)$identity['history_epoch'], (string)($headers['epoch'] ?? ''))) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $appPk = (int)$identity['application_pk'];
        $requestHash = hash('sha256', json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $receiptStmt = $pdo->prepare('SELECT request_sha256,external_id,public_id,CAST(resource_revision AS CHAR) resource_revision FROM api_v2_directory_binding_command_receipts WHERE application_pk=? AND resource_type=? AND command_id=?');
        $receiptStmt->execute([$appPk, $type, $command['commandId']]); $receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);
        if ($receipt && (!hash_equals((string)$receipt['request_sha256'], $requestHash)
            || !hash_equals((string)$receipt['external_id'], $command['externalId'])
            || (string)$receipt['public_id'] !== $command['expectedPublicId']
            || (string)$receipt['resource_revision'] !== $command['expectedRevision'])) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $stateStmt = $pdo->prepare('SELECT revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?' . $lock);
        $stateStmt->execute([$type, $command['expectedPublicId']]); $state = $stateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$state || (int)$state['present'] !== 1 || (string)$state['revision'] !== $command['expectedRevision']) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $table = $type === 'client' ? 'clients' : 'organizations';
        $liveStmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE public_id=?' . $lock);
        $liveStmt->execute([$command['expectedPublicId']]); $live = $liveStmt->fetch(PDO::FETCH_ASSOC);
        if (!$live || !hash_equals((string)$state['projection_sha256'], api_v2_directory_projection_hash($type, $live))) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $bindingStmt = $pdo->prepare('SELECT external_id,public_id,resource_revision,resource_projection_sha256,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND (external_id=? OR public_id=?)' . $lock);
        $bindingStmt->execute([$appPk, $type, $command['externalId'], $command['expectedPublicId']]);
        $bindings = $bindingStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($receipt) {
            if (count($bindings) !== 1 || (string)$bindings[0]['status'] !== 'active'
                || !hash_equals((string)$bindings[0]['external_id'], $command['externalId'])
                || (string)$bindings[0]['public_id'] !== $command['expectedPublicId']
                || (string)$bindings[0]['resource_revision'] !== $command['expectedRevision']
                || !hash_equals((string)$bindings[0]['resource_projection_sha256'], (string)$state['projection_sha256'])) {
                $pdo->rollBack(); return ['status' => 409];
            }
            $pdo->commit(); return ['status' => 200, 'payload' => api_v2_directory_binding_command_result($identity, $command, $type, $requestId, true)];
        }
        if ($bindings !== []) { $pdo->rollBack(); return ['status' => 409]; }
        $auth = $pdo->prepare('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock);
        $auth->execute([$appPk]); $generation = $auth->fetchColumn();
        if ($generation === false || !preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string)$generation)
            || (strlen((string)$generation) === 19 && strcmp((string)$generation, '9223372036854775807') >= 0)) {
            $pdo->rollBack(); return ['status' => 409];
        }
        $pdo->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status) VALUES(?,?,?,?,?,?,'active')")
            ->execute([$appPk, $type, $command['externalId'], $command['expectedPublicId'], $command['expectedRevision'], $state['projection_sha256']]);
        $pdo->prepare('INSERT INTO api_v2_directory_binding_command_receipts(application_pk,resource_type,command_id,request_sha256,external_id,public_id,resource_revision) VALUES(?,?,?,?,?,?,?)')
            ->execute([$appPk, $type, $command['commandId'], $requestHash, $command['externalId'], $command['expectedPublicId'], $command['expectedRevision']]);
        $pdo->prepare('UPDATE api_v2_directory_authorization_state SET authorization_generation=authorization_generation+1 WHERE application_pk=?')->execute([$appPk]);
        $pdo->commit();
        return ['status' => 200, 'payload' => api_v2_directory_binding_command_result($identity, $command, $type, $requestId, false)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode() === '23000') return ['status' => 409];
        throw $error;
    }
}
