<?php
declare(strict_types=1);

use App\Services\ClientProfileMutationService;

require_once __DIR__ . '/address_book.php';
require_once __DIR__ . '/api_v2_directory_revision.php';

function api_v2_directory_client_profile_command_parse(string $json): ?array
{
    if (strlen($json) > 32 * 1024) return null;
    try { $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    if (!is_array($value) || count($value) !== 4 || array_diff(array_keys($value), ['commandId', 'expectedRevision', 'expectedAuthorizationGeneration', 'profile']) !== [] || array_diff(['commandId', 'expectedRevision', 'expectedAuthorizationGeneration', 'profile'], array_keys($value)) !== []) return null;
    if (!is_string($value['commandId']) || !is_string($value['expectedRevision']) || !is_string($value['expectedAuthorizationGeneration']) || !is_array($value['profile'])
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value['commandId']) !== 1
        || !api_v2_directory_client_profile_positive($value['expectedRevision']) || !preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value['expectedAuthorizationGeneration']) || (strlen($value['expectedAuthorizationGeneration']) === 19 && strcmp($value['expectedAuthorizationGeneration'], '9223372036854775807') > 0)) return null;
    // These limits intentionally mirror the live `clients` columns so a
    // malformed command is rejected before its transaction begins.
    $fields = ['name'=>150, 'email'=>255, 'phone'=>50, 'addressLine1'=>255, 'addressLine2'=>255, 'city'=>100, 'state'=>2, 'postalCode'=>20, 'country'=>100];
    if (count($value['profile']) !== count($fields) || array_diff(array_keys($value['profile']), array_keys($fields)) !== [] || array_diff(array_keys($fields), array_keys($value['profile'])) !== []) return null;
    foreach ($fields as $field => $limit) {
        $item = $value['profile'][$field] ?? null;
        if (!is_string($item) || preg_match('//u', $item) !== 1 || preg_match('/\p{C}/u', $item) === 1) return null;
        $value['profile'][$field] = trim($item);
        if (mb_strlen($value['profile'][$field]) > $limit) return null;
    }
    if ($value['profile']['name'] === '') return null;
    $email = strtolower($value['profile']['email']); $value['profile']['email'] = $email;
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    return ['commandId'=>$value['commandId'], 'expectedRevision'=>$value['expectedRevision'], 'expectedAuthorizationGeneration'=>$value['expectedAuthorizationGeneration'], 'profile'=>array_replace(array_fill_keys(array_keys($fields), ''), $value['profile'])];
}

function api_v2_directory_client_profile_positive(string $value): bool
{
    return preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1
        && !(strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0);
}

function api_v2_directory_client_profile_result(array $identity, string $publicId, string $revision, string $authorizationGeneration, string $requestId, bool $replayed): array
{
    return ['sourceInstanceId'=>(string)$identity['source_instance_id'], 'applicationId'=>(string)$identity['application_id'], 'historyEpoch'=>(string)$identity['history_epoch'], 'requestId'=>$requestId, 'replayed'=>$replayed,
        'result'=>['resource'=>['type'=>'client', 'publicId'=>$publicId, 'revision'=>$revision], 'authorizationGeneration'=>$authorizationGeneration]];
}

/** @return array{status:int,payload?:array} */
function api_v2_directory_client_profile_command_write(PDO $pdo, string $publicId, array $command, int $apiKeyId, array $headers, string $requestId): array
{
    if (preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1 || $apiKeyId < 1 || $pdo->inTransaction()) throw new InvalidArgumentException('Invalid client profile command');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $identityStatement = $pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL' . $lock);
        $identityStatement->execute([$apiKeyId]); $identity = $identityStatement->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !api_v2_identity_is_valid($identity) || !hash_equals((string)$identity['source_instance_id'], (string)($headers['source'] ?? '')) || !hash_equals((string)$identity['application_id'], (string)($headers['application'] ?? '')) || !hash_equals((string)$identity['history_epoch'], (string)($headers['epoch'] ?? ''))) { $pdo->rollBack(); return ['status'=>409]; }
        $requestHash = hash('sha256', json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $receiptStatement = $pdo->prepare('SELECT request_sha256,public_id,CAST(expected_revision AS CHAR) expected_revision,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_revision AS CHAR) result_revision,result_projection_sha256 FROM api_v2_directory_client_profile_command_receipts WHERE application_pk=? AND command_id=?' . $lock);
        $receiptStatement->execute([(int)$identity['application_pk'], $command['commandId']]); $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC);
        if ($receipt && (!hash_equals((string)$receipt['request_sha256'], $requestHash) || !hash_equals((string)$receipt['public_id'], $publicId) || (string)$receipt['expected_revision'] !== $command['expectedRevision'] || (string)$receipt['expected_authorization_generation'] !== $command['expectedAuthorizationGeneration'])) { $pdo->rollBack(); return ['status'=>409]; }
        if ($receipt) { $pdo->commit(); return ['status'=>200, 'payload'=>api_v2_directory_client_profile_result($identity, $publicId, (string)$receipt['result_revision'], (string)$receipt['expected_authorization_generation'], $requestId, true)]; }
        $authorizationStatement = $pdo->prepare('SELECT CAST(authorization_generation AS CHAR) authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock); $authorizationStatement->execute([(int)$identity['application_pk']]); $authorizationGeneration = $authorizationStatement->fetchColumn();
        if ($authorizationGeneration === false || (string)$authorizationGeneration !== $command['expectedAuthorizationGeneration']) { $pdo->rollBack(); return ['status'=>409]; }
        $liveStatement = $pdo->prepare('SELECT * FROM clients WHERE public_id=?' . $lock); $liveStatement->execute([$publicId]); $live = $liveStatement->fetch(PDO::FETCH_ASSOC);
        $stateStatement = $pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=\'client\' AND public_id=?' . $lock); $stateStatement->execute([$publicId]); $state = $stateStatement->fetch(PDO::FETCH_ASSOC);
        if (!$live || !$state || (int)$state['present'] !== 1 || !hash_equals((string)$state['projection_sha256'], api_v2_directory_projection_hash('client', $live))) { $pdo->rollBack(); return ['status'=>409]; }
        if ((string)$state['revision'] !== $command['expectedRevision']) { $pdo->rollBack(); return ['status'=>409]; }
        $profile = $command['profile'];
        $profileFields = ['name'=>$profile['name'], 'email'=>$profile['email'], 'phone'=>$profile['phone'], 'address_line1'=>$profile['addressLine1'], 'address_line2'=>$profile['addressLine2'], 'city'=>$profile['city'], 'state'=>$profile['state'], 'postal_code'=>$profile['postalCode'], 'country'=>$profile['country']];
        $hasProfileChange = false;
        foreach ($profileFields as $field => $value) if ((string)($live[$field] ?? '') !== $value) { $hasProfileChange = true; break; }
        if ($hasProfileChange) {
            // Keep provider metadata and the selected reusable address private;
            // only address values mirrored by the client row are commandable.
            $currentAddress = address_book_default_for_entity($pdo, 'client', (int)$live['id'], 'billing', true) ?: [];
            (new ClientProfileMutationService())->mutate($pdo, (int)$live['id'], ['name'=>$profile['name'], 'email'=>$profile['email'], 'phone'=>$profile['phone'], 'organization_id'=>(int)($live['organization_id'] ?? 0), 'notes'=>(string)($live['notes'] ?? ''), 'address'=>['address_line1'=>$profile['addressLine1'], 'address_line2'=>$profile['addressLine2'], 'city'=>$profile['city'], 'state'=>$profile['state'], 'postal_code'=>$profile['postalCode'], 'country'=>$profile['country']], 'google_place_id'=>(string)($currentAddress['google_place_id'] ?? ''), 'address_label'=>(string)($currentAddress['label'] ?? 'Billing address'), 'address_id'=>(int)($currentAddress['id'] ?? 0), 'actor_id'=>0]);
        }
        $resultStateStatement = $pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=\'client\' AND public_id=?' . $lock); $resultStateStatement->execute([$publicId]); $result = $resultStateStatement->fetch(PDO::FETCH_ASSOC);
        if (!$result || (int)$result['present'] !== 1 || !api_v2_directory_client_profile_positive((string)$result['revision'])) throw new RuntimeException('Client directory result unavailable');
        $pdo->prepare('INSERT INTO api_v2_directory_client_profile_command_receipts(application_pk,command_id,request_sha256,public_id,expected_revision,expected_authorization_generation,result_revision,result_projection_sha256) VALUES(?,?,?,?,?,?,?,?)')->execute([(int)$identity['application_pk'], $command['commandId'], $requestHash, $publicId, $command['expectedRevision'], $command['expectedAuthorizationGeneration'], $result['revision'], $result['projection_sha256']]);
        $pdo->commit(); return ['status'=>200, 'payload'=>api_v2_directory_client_profile_result($identity, $publicId, (string)$result['revision'], (string)$authorizationGeneration, $requestId, false)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); if ($error instanceof PDOException && $error->getCode() === '23000') return ['status'=>409]; throw $error; }
}
