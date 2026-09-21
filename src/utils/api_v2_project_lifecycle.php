<?php

declare(strict_types=1);

use App\Services\PortalProjectionMutationService;
use App\Services\ProjectLifecycleService;
use App\Services\ProjectRevisionService;

require_once __DIR__ . '/api_v2_capabilities.php';
require_once __DIR__ . '/../services/ProjectRevisionService.php';
require_once __DIR__ . '/../services/ProjectLifecycleService.php';
require_once __DIR__ . '/../services/ProjectPresentationService.php';
require_once __DIR__ . '/../services/ManagedDeliveryService.php';
require_once __DIR__ . '/../services/ProjectCloseGuardService.php';
require_once __DIR__ . '/../services/ProjectContractEligibilityGuardService.php';
require_once __DIR__ . '/../services/ProjectReceivablesSummaryService.php';
require_once __DIR__ . '/../services/ScheduleService.php';
require_once __DIR__ . '/../services/PortalProjectionMutationService.php';

function api_v2_project_lifecycle_command_parse(string $json): ?array
{
    if (strlen($json) > 8 * 1024) return null;
    try { $value = json_decode($json, true, 5, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    if (!is_array($value) || array_keys($value) !== ['commandId', 'expectedRevision']
        || !is_string($value['commandId']) || !is_string($value['expectedRevision'])
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value['commandId']) !== 1
        || !ProjectRevisionService::positiveInteger($value['expectedRevision'])) return null;
    return $value;
}

function api_v2_project_identity(PDO $pdo, int $apiKeyId, array $headers, bool $lock): ?array
{
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $statement = $pdo->prepare(
        'SELECT app.id application_pk,app.application_id,history.source_instance_id,history.history_epoch
         FROM api_keys api_key
         JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id
         JOIN api_v2_history_identity history ON history.singleton=1
         WHERE api_key.id=? AND api_key.revoked_at IS NULL' . $suffix
    );
    $statement->execute([$apiKeyId]);
    $identity = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$identity || !api_v2_identity_is_valid($identity)
        || !hash_equals((string)$identity['source_instance_id'], (string)($headers['source'] ?? ''))
        || !hash_equals((string)$identity['application_id'], (string)($headers['application'] ?? ''))
        || !hash_equals((string)$identity['history_epoch'], (string)($headers['epoch'] ?? ''))) return null;
    return $identity;
}

function api_v2_project_read(PDO $pdo, string $publicId, int $apiKeyId, array $headers, string $requestId): ?array
{
    if (preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1 || $apiKeyId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Invalid Project read.');
    }
    $pdo->beginTransaction();
    try {
        $identity = api_v2_project_identity($pdo, $apiKeyId, $headers, true);
        if (!$identity) { $pdo->rollBack(); return null; }
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $pdo->prepare('SELECT * FROM projects WHERE public_id=?' . $lock);
        $statement->execute([$publicId]); $project = $statement->fetch(PDO::FETCH_ASSOC);
        if ($project) $project = api_v2_project_hydrate_relations($pdo, $project);
        if (!$project || !api_v2_project_revision_matches($pdo, $project, $lock)) { $pdo->rollBack(); return null; }
        $payload = api_v2_project_payload($identity, $project, $requestId, false, true);
        $pdo->commit();
        return $payload;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function api_v2_project_lifecycle_write(PDO $pdo, string $publicId, string $action, array $command, int $apiKeyId, array $headers, string $requestId): array
{
    if (preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1
        || !in_array($action, ['complete','cancel','archive','restore'], true)
        || $apiKeyId < 1 || $pdo->inTransaction()) throw new InvalidArgumentException('Invalid Project lifecycle command.');
    $pdo->beginTransaction();
    try {
        $identity = api_v2_project_identity($pdo, $apiKeyId, $headers, true);
        if (!$identity) { $pdo->rollBack(); return ['status' => 409]; }
        $hash = hash('sha256', json_encode(['publicId'=>$publicId,'action'=>$action,'command'=>$command], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $receiptStatement = $pdo->prepare(
            'SELECT request_sha256,project_public_id,action_name,CAST(expected_revision AS CHAR) expected_revision,
                    CAST(result_revision AS CHAR) result_revision,result_status,result_completed_at,result_archived_at,
                    result_portal_publish_enabled,result_public_project_enabled,outcome
             FROM api_v2_project_lifecycle_command_receipts
             WHERE application_pk=? AND history_epoch=? AND command_id=?' . $lock
        );
        $receiptStatement->execute([$identity['application_pk'],$identity['history_epoch'],$command['commandId']]);
        $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            if (!hash_equals((string)$receipt['request_sha256'], $hash)
                || !hash_equals((string)$receipt['project_public_id'], $publicId)
                || (string)$receipt['action_name'] !== $action
                || (string)$receipt['expected_revision'] !== $command['expectedRevision']) {
                $pdo->rollBack(); return ['status'=>409];
            }
            $payload = api_v2_project_lifecycle_payload($identity, $publicId, (string)$receipt['result_revision'],
                (string)$receipt['result_status'], $receipt['result_completed_at'], $receipt['result_archived_at'],
                (bool)$receipt['result_portal_publish_enabled'], (bool)$receipt['result_public_project_enabled'],
                $requestId, true, $receipt['outcome'] !== 'blocked');
            $pdo->commit();
            return ['status'=>$receipt['outcome'] === 'blocked' ? 409 : 200,'payload'=>$payload];
        }
        $projectStatement = $pdo->prepare('SELECT * FROM projects WHERE public_id=?' . $lock);
        $projectStatement->execute([$publicId]); $project = $projectStatement->fetch(PDO::FETCH_ASSOC);
        if ($project) $project = api_v2_project_hydrate_relations($pdo, $project);
        if (!$project || (string)$project['revision'] !== $command['expectedRevision']
            || !api_v2_project_revision_matches($pdo, $project, $lock)) { $pdo->rollBack(); return ['status'=>409]; }
        $audit = static function (PDO $pdo, array $row, string $auditAction, array $details, int $actorId) use ($identity, $command, $requestId): void {
            $details['api_v2'] = ['applicationId'=>$identity['application_id'],'commandId'=>$command['commandId'],'requestId'=>$requestId];
            $pdo->prepare('INSERT INTO system_audit (user_id,organization_id,action,entity_type,entity_id,details,ip_address,user_agent)
                VALUES(NULL,?,?,?,?,?,NULL,NULL)')->execute([
                    !empty($row['organization_id']) ? (int)$row['organization_id'] : null,$auditAction,'project',(int)$row['id'],
                    json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
        };
        $service = new ProjectLifecycleService($pdo, static function (): void {}, $audit);
        $result = $service->apply((int)$project['id'], $action, 0, (int)$identity['application_pk'], $command['commandId']);
        $outcome = !$result['transitioned'] ? ($result['blockers'] === [] ? 'noop' : 'blocked') : 'applied';
        $project = $result['project'];
        if ($outcome !== 'blocked') {
            if ($outcome === 'applied') {
                \ScheduleService::syncProject($pdo, (int)$project['id'], getenv('APP_TIMEZONE') ?: 'UTC', null);
                $projection = new PortalProjectionMutationService();
                $projection->afterMutation($pdo, $projection->projectScopes($pdo, (int)$project['id']));
            }
        }
        $pdo->prepare('INSERT INTO api_v2_project_lifecycle_command_receipts
            (application_pk,history_epoch,command_id,request_sha256,action_name,project_public_id,expected_revision,result_revision,result_status,result_completed_at,result_archived_at,result_portal_publish_enabled,result_public_project_enabled,outcome)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $identity['application_pk'],$identity['history_epoch'],$command['commandId'],$hash,$action,$publicId,$command['expectedRevision'],
                $project['revision'],$project['status'],$project['completed_at'] ?? null,$project['archived_at'] ?? null,
                !empty($project['portal_publish_enabled']) ? 1 : 0,!empty($project['public_project_enabled']) ? 1 : 0,$outcome,
            ]);
        $payload = api_v2_project_lifecycle_payload($identity, $publicId, (string)$project['revision'], (string)$project['status'],
            $project['completed_at'] ?? null, $project['archived_at'] ?? null,
            !empty($project['portal_publish_enabled']), !empty($project['public_project_enabled']),
            $requestId, false, $outcome !== 'blocked');
        $pdo->commit();
        return ['status'=>$outcome === 'blocked' ? 409 : 200,'payload'=>$payload];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode() === '23000') return ['status'=>409];
        throw $error;
    }
}

function api_v2_project_revision_matches(PDO $pdo, array $project, string $lock = ''): bool
{
    if (!ProjectRevisionService::positiveInteger((string)($project['revision'] ?? ''))) return false;
    $change = $pdo->prepare('SELECT projection_sha256 FROM project_changes WHERE project_public_id=? AND revision=?' . $lock);
    $change->execute([$project['public_id'],$project['revision']]);
    $hash = $change->fetchColumn();
    return is_string($hash) && hash_equals($hash, ProjectRevisionService::projectionHash($project));
}

function api_v2_project_payload(array $identity, array $project, string $requestId, bool $replayed, bool $accepted): array
{
    return [
        'apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],
        'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'replayed'=>$replayed,'accepted'=>$accepted,
        'resource'=>['type'=>'project','id'=>$project['public_id'],'revision'=>(string)$project['revision'],'projectionSha256'=>ProjectRevisionService::projectionHash($project)],
        'data'=>[
            'name'=>(string)($project['name'] ?? ''),'description'=>$project['description'] ?? null,'status'=>$project['status'],'archived'=>($project['archived_at'] ?? null) !== null,
            'overdueWarning'=>ProjectRevisionService::derivedOverdue($project),
            'completedAt'=>$project['completed_at'] ?? null,'archivedAt'=>$project['archived_at'] ?? null,'estimatedStart'=>$project['estimated_start'] ?? null,
            'estimatedEnd'=>$project['estimated_end'] ?? null,'clientPublicId'=>$project['client_public_id'] ?? null,
            'organizationPublicId'=>$project['organization_public_id'] ?? null,
        ],
    ];
}

function api_v2_project_lifecycle_payload(array $identity, string $publicId, string $revision, string $status, mixed $completedAt,
    mixed $archivedAt, bool $portalPublished, bool $publicLinkEnabled, string $requestId, bool $replayed, bool $accepted): array
{
    return [
        'apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],
        'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'replayed'=>$replayed,'accepted'=>$accepted,
        'resource'=>['type'=>'project','id'=>$publicId,'revision'=>$revision],
        'result'=>[
            'status'=>$status,'completedAt'=>$completedAt,'archived'=>$archivedAt !== null,'archivedAt'=>$archivedAt,
            'presentation'=>['portalPublished'=>$portalPublished,'publicLinkEnabled'=>$publicLinkEnabled],
        ],
    ];
}

function api_v2_project_hydrate_relations(PDO $pdo, array $project): array
{
    foreach ([['client_id','clients','client_public_id'],['organization_id','organizations','organization_public_id']] as [$idField,$table,$publicField]) {
        $project[$publicField] = null;
        if (!empty($project[$idField])) {
            $statement = $pdo->prepare('SELECT public_id FROM ' . $table . ' WHERE id=?');
            $statement->execute([$project[$idField]]);
            $value = $statement->fetchColumn();
            $project[$publicField] = is_string($value) ? $value : null;
        }
    }
    return $project;
}
