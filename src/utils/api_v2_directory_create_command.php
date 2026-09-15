<?php
declare(strict_types=1);

require_once __DIR__ . '/address_book.php';
require_once __DIR__ . '/api_v2_directory_revision.php';
require_once __DIR__ . '/portal_projection_hooks.php';

function api_v2_directory_create_command_parse(string $type, string $json): ?array
{
    if (!in_array($type, ['client', 'organization'], true) || strlen($json) > 32 * 1024) return null;
    try { $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    $topFields = $type === 'client'
        ? ['commandId', 'externalId', 'expectedAuthorizationGeneration', 'profile', 'organization']
        : ['commandId', 'externalId', 'expectedAuthorizationGeneration', 'profile'];
    if (!is_array($value) || count($value) !== count($topFields)
        || array_diff(array_keys($value), $topFields) !== [] || array_diff($topFields, array_keys($value)) !== []) return null;
    if (!is_string($value['commandId']) || !is_string($value['externalId'])
        || !is_string($value['expectedAuthorizationGeneration']) || !is_array($value['profile'])
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value['commandId']) !== 1
        || !api_v2_directory_create_external_id_valid($value['externalId'])
        || !api_v2_directory_create_generation_valid($value['expectedAuthorizationGeneration'])) return null;

    $fields = $type === 'client'
        ? ['name'=>150, 'email'=>255, 'phone'=>50, 'clientType'=>8, 'addressLine1'=>255, 'addressLine2'=>255, 'city'=>100, 'state'=>2, 'postalCode'=>20, 'country'=>100]
        : ['name'=>150, 'generalEmail'=>255, 'generalPhone'=>50, 'addressLine1'=>255, 'addressLine2'=>255, 'city'=>100, 'state'=>100, 'postalCode'=>32, 'country'=>100];
    if (count($value['profile']) !== count($fields) || array_diff(array_keys($value['profile']), array_keys($fields)) !== []
        || array_diff(array_keys($fields), array_keys($value['profile'])) !== []) return null;
    $profile = [];
    foreach ($fields as $field => $limit) {
        $item = $value['profile'][$field] ?? null;
        if (!is_string($item) || preg_match('//u', $item) !== 1 || preg_match('/\p{C}/u', $item) === 1) return null;
        $profile[$field] = trim($item);
        if (mb_strlen($profile[$field]) > $limit) return null;
    }
    if ($profile['name'] === '') return null;
    $emailField = $type === 'client' ? 'email' : 'generalEmail';
    $profile[$emailField] = strtolower($profile[$emailField]);
    if ($profile[$emailField] !== '' && !filter_var($profile[$emailField], FILTER_VALIDATE_EMAIL)) return null;
    if ($type === 'client' && !in_array($profile['clientType'], ['unknown', 'business', 'consumer'], true)) return null;

    $organization = null;
    if ($type === 'client' && $value['organization'] !== null) {
        $requestedOrganization = $value['organization'];
        if (!is_array($requestedOrganization) || count($requestedOrganization) !== 2
            || array_diff(array_keys($requestedOrganization), ['externalId', 'expectedRevision']) !== []
            || array_diff(['externalId', 'expectedRevision'], array_keys($requestedOrganization)) !== []
            || !is_string($requestedOrganization['externalId']) || !is_string($requestedOrganization['expectedRevision'])
            || !api_v2_directory_create_external_id_valid($requestedOrganization['externalId'])
            || !api_v2_directory_create_positive($requestedOrganization['expectedRevision'])) return null;
        $organization = [
            'externalId' => $requestedOrganization['externalId'],
            'expectedRevision' => $requestedOrganization['expectedRevision'],
        ];
    }
    $command = [
        'commandId' => $value['commandId'],
        'externalId' => $value['externalId'],
        'expectedAuthorizationGeneration' => $value['expectedAuthorizationGeneration'],
        'profile' => $profile,
    ];
    if ($type === 'client') $command['organization'] = $organization;
    return $command;
}

function api_v2_directory_create_external_id_valid(string $value): bool
{
    if ($value === '' || strlen($value) > 764 || preg_match('//u', $value) !== 1 || preg_match('/\p{C}/u', $value) === 1) return false;
    $characters = preg_match_all('/./us', $value);
    return $characters !== false && $characters >= 1 && $characters <= 191;
}

function api_v2_directory_create_generation_valid(string $value): bool
{
    return preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1
        && !(strlen($value) === 19 && strcmp($value, PA_API_V2_AUTHORIZATION_GENERATION_MAX) > 0);
}

function api_v2_directory_create_positive(string $value): bool
{
    return preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1
        && !(strlen($value) === 19 && strcmp($value, PA_API_V2_AUTHORIZATION_GENERATION_MAX) > 0);
}

function api_v2_directory_create_result(array $identity, string $type, string $externalId, string $publicId, string $revision, string $generation, string $requestId, bool $replayed): array
{
    return [
        'sourceInstanceId'=>(string)$identity['source_instance_id'],
        'applicationId'=>(string)$identity['application_id'],
        'historyEpoch'=>(string)$identity['history_epoch'],
        'requestId'=>$requestId,
        'replayed'=>$replayed,
        'result'=>[
            'resource'=>['type'=>$type, 'id'=>$externalId, 'publicId'=>$publicId, 'revision'=>$revision],
            'authorizationGeneration'=>$generation,
        ],
    ];
}

/** @return array{status:int,payload?:array} */
function api_v2_directory_create_command_write(PDO $pdo, string $type, array $command, int $apiKeyId, array $headers, string $requestId): array
{
    if (!in_array($type, ['client', 'organization'], true) || $apiKeyId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Invalid directory create command');
    }
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        // The application row is shared by every key for this application. Its
        // lock serializes receipt and authorization decisions across those keys.
        $identityStatement = $pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk
            FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id
            JOIN api_v2_history_identity history ON history.singleton=1
            WHERE api_key.id=? AND api_key.revoked_at IS NULL' . $lock);
        $identityStatement->execute([$apiKeyId]); $identity = $identityStatement->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !api_v2_identity_is_valid($identity)
            || !hash_equals((string)$identity['source_instance_id'], (string)($headers['source'] ?? ''))
            || !hash_equals((string)$identity['application_id'], (string)($headers['application'] ?? ''))
            || !hash_equals((string)$identity['history_epoch'], (string)($headers['epoch'] ?? ''))) {
            $pdo->rollBack(); return ['status'=>409];
        }
        $appPk = (int)$identity['application_pk'];
        $requestHash = hash('sha256', json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $receiptStatement = $pdo->prepare('SELECT request_sha256,external_id,public_id,CAST(result_revision AS CHAR) result_revision,
            CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,
            CAST(result_authorization_generation AS CHAR) result_authorization_generation
            FROM api_v2_directory_create_command_receipts WHERE application_pk=? AND resource_type=? AND command_id=?' . $lock);
        $receiptStatement->execute([$appPk, $type, $command['commandId']]); $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC);
        if ($receipt && (!hash_equals((string)$receipt['request_sha256'], $requestHash)
            || !hash_equals((string)$receipt['external_id'], $command['externalId'])
            || (string)$receipt['expected_authorization_generation'] !== $command['expectedAuthorizationGeneration'])) {
            $pdo->rollBack(); return ['status'=>409];
        }
        if ($receipt) {
            $pdo->commit();
            return ['status'=>200, 'payload'=>api_v2_directory_create_result($identity, $type, (string)$receipt['external_id'], (string)$receipt['public_id'], (string)$receipt['result_revision'], (string)$receipt['result_authorization_generation'], $requestId, true)];
        }

        $authorizationStatement = $pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock);
        $authorizationStatement->execute([$appPk]); $generation = $authorizationStatement->fetchColumn();
        if ($generation === false || (string)$generation !== $command['expectedAuthorizationGeneration']
            || !api_v2_directory_create_generation_valid((string)$generation)
            || (string)$generation === PA_API_V2_AUTHORIZATION_GENERATION_MAX) {
            $pdo->rollBack(); return ['status'=>409];
        }

        $organizationId = null;
        if ($type === 'client' && $command['organization'] !== null) {
            $organization = $command['organization'];
            // Resolve the public ID without taking the binding lock, then use
            // the browser-compatible source -> address -> state -> binding
            // order. The final locked binding comparison closes the lookup
            // window and prevents a retarget/tombstone TOCTOU.
            $relationshipLookup = $pdo->prepare("SELECT public_id FROM api_v2_directory_external_bindings
                WHERE application_pk=? AND resource_type='organization' AND external_id=?");
            $relationshipLookup->execute([$appPk, $organization['externalId']]);
            $organizationPublicId = $relationshipLookup->fetchColumn();
            if ($organizationPublicId === false || preg_match('/^[0-9a-f]{32}$/D', (string)$organizationPublicId) !== 1) {
                $pdo->rollBack(); return ['status'=>409];
            }
            $organizationStatement = $pdo->prepare('SELECT id,public_id,name,general_email,general_phone,address_line1,address_line2,city,state,postal_code,country
                FROM organizations WHERE public_id=?' . $lock);
            $organizationStatement->execute([(string)$organizationPublicId]);
            $relatedOrganization = $organizationStatement->fetch(PDO::FETCH_ASSOC);
            if (!$relatedOrganization) { $pdo->rollBack(); return ['status'=>409]; }
            address_book_default_for_entity($pdo, 'organization', (int)$relatedOrganization['id'], 'billing', true);
            $relationshipState = $pdo->prepare("SELECT CAST(revision AS CHAR) revision,projection_sha256,present
                FROM api_v2_directory_resource_state WHERE resource_type='organization' AND public_id=?" . $lock);
            $relationshipState->execute([(string)$organizationPublicId]); $relatedState = $relationshipState->fetch(PDO::FETCH_ASSOC);
            $relationshipBinding = $pdo->prepare("SELECT public_id,CAST(resource_revision AS CHAR) resource_revision,resource_projection_sha256,status
                FROM api_v2_directory_external_bindings
                WHERE application_pk=? AND resource_type='organization' AND external_id=?" . $lock);
            $relationshipBinding->execute([$appPk, $organization['externalId']]); $relatedBinding = $relationshipBinding->fetch(PDO::FETCH_ASSOC);
            if (!$relatedState || !$relatedBinding || (string)$relatedBinding['status'] !== 'active' || (int)$relatedState['present'] !== 1
                || !hash_equals((string)$relatedBinding['public_id'], (string)$organizationPublicId)
                || (string)$relatedBinding['resource_revision'] !== $organization['expectedRevision']
                || (string)$relatedState['revision'] !== $organization['expectedRevision']
                || !hash_equals((string)$relatedBinding['resource_projection_sha256'], (string)$relatedState['projection_sha256'])
                || !hash_equals((string)$relatedState['projection_sha256'], api_v2_directory_projection_hash('organization', $relatedOrganization))) {
                $pdo->rollBack(); return ['status'=>409];
            }
            $organizationId = (int)$relatedOrganization['id'];
        }

        // A tombstoned or active external ID is intentionally not reusable.
        // Lifecycle recovery requires a separately authorized binding command.
        $bindingStatement = $pdo->prepare('SELECT status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND external_id=?' . $lock);
        $bindingStatement->execute([$appPk, $type, $command['externalId']]);
        if ($bindingStatement->fetchColumn() !== false) { $pdo->rollBack(); return ['status'=>409]; }

        if ($type === 'organization') {
            // Organization names are unique in the live schema. Reject a match
            // explicitly; never reinterpret creation as selection or reuse.
            $duplicate = $pdo->prepare('SELECT id FROM organizations WHERE LOWER(name)=LOWER(?) LIMIT 1' . $lock);
            $duplicate->execute([$command['profile']['name']]);
            if ($duplicate->fetchColumn() !== false) { $pdo->rollBack(); return ['status'=>409]; }
        }

        // Keep neutral relationship and existing-workspace projection state on
        // the authoritative transaction boundary without enrolling the new
        // resource into portal authority. Enrollment remains a separate,
        // explicitly governed operation.
        $publicId = bin2hex(random_bytes(16));
        $profile = $command['profile'];
        if ($type === 'client') {
            $insert = $pdo->prepare('INSERT INTO clients(public_id,name,email,phone,organization_id,client_type,address_line1,address_line2,city,state,postal_code,country,source_version)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$publicId,$profile['name'],$profile['email'] ?: null,$profile['phone'] ?: null,$organizationId,$profile['clientType'],
                $profile['addressLine1'] ?: null,$profile['addressLine2'] ?: null,$profile['city'] ?: null,$profile['state'] ?: null,
                $profile['postalCode'] ?: null,$profile['country'] ?: null,portal_projection_source_version()]);
        } else {
            $insert = $pdo->prepare('INSERT INTO organizations(public_id,name,general_email,general_phone,address_line1,address_line2,city,state,postal_code,country,source_version)
                VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$publicId,$profile['name'],$profile['generalEmail'] ?: null,$profile['generalPhone'] ?: null,
                $profile['addressLine1'] ?: null,$profile['addressLine2'] ?: null,$profile['city'] ?: null,$profile['state'] ?: null,
                $profile['postalCode'] ?: null,$profile['country'] ?: null,portal_projection_source_version()]);
        }
        $localId = (int)$pdo->lastInsertId();
        if ($localId < 1) throw new RuntimeException('Directory create identity unavailable');
        address_book_save($pdo, [
            'label'=>'Billing address','address_line1'=>$profile['addressLine1'],'address_line2'=>$profile['addressLine2'],
            'city'=>$profile['city'],'state'=>$profile['state'],'postal_code'=>$profile['postalCode'],'country'=>$profile['country'],
        ], $type, $localId, 'billing', true, null);
        $projection = new App\Services\PortalProjectionMutationService();
        $projectionScopes = $type === 'client'
            ? $projection->clientScopes($pdo, $localId)
            : $projection->organizationScopes($pdo, $localId);
        $projection->afterMutationProjectionOnly($pdo, $projectionScopes);
        if (!api_v2_directory_record($pdo, $type, $localId)) throw new RuntimeException('Initial directory revision was not created');
        $stateStatement = $pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?' . $lock);
        $stateStatement->execute([$type, $publicId]); $state = $stateStatement->fetch(PDO::FETCH_ASSOC);
        if (!$state || (string)$state['revision'] !== '1' || (int)$state['present'] !== 1) throw new RuntimeException('Initial directory state unavailable');
        $pdo->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status)
            VALUES(?,?,?,?,1,?,'active')")->execute([$appPk,$type,$command['externalId'],$publicId,$state['projection_sha256']]);
        api_v2_advance_authorization_generation($pdo, $appPk);
        $resultGenerationStatement = $pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?');
        $resultGenerationStatement->execute([$appPk]); $resultGeneration = $resultGenerationStatement->fetchColumn();
        if ($resultGeneration === false) throw new RuntimeException('Result authorization generation unavailable');
        $pdo->prepare('INSERT INTO api_v2_directory_create_command_receipts(application_pk,resource_type,command_id,request_sha256,external_id,public_id,
            expected_authorization_generation,result_revision,result_projection_sha256,result_authorization_generation) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$appPk,$type,$command['commandId'],$requestHash,$command['externalId'],$publicId,$command['expectedAuthorizationGeneration'],1,$state['projection_sha256'],$resultGeneration]);
        $pdo->commit();
        return ['status'=>201, 'payload'=>api_v2_directory_create_result($identity, $type, $command['externalId'], $publicId, '1', (string)$resultGeneration, $requestId, false)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode() === '23000') return ['status'=>409];
        throw $error;
    }
}
