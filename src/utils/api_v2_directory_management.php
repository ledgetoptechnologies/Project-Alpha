<?php
declare(strict_types=1);

require_once __DIR__ . '/api_scopes.php';
require_once __DIR__ . '/api_v2_capabilities.php';
require_once __DIR__ . '/api_v2_directory_release_safety.php';

const API_V2_DIRECTORY_MANAGEMENT_LABEL = 'Directory changes are managed by an authorized external application';
const API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY = 'api_v2_directory_management_ownership_active';

/**
 * This lives in the long-established application configuration store rather
 * than the managed-directory migration.  It is deliberately written only
 * when ownership is activated/explicitly relinquished, so a partial or
 * damaged managed-directory migration cannot silently reopen browser writers.
 */
/** @return bool|null null means the independent sentinel store could not be read. */
function api_v2_directory_management_sentinel_active(PDO $pdo): ?bool
{
    try {
        $statement = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key=? LIMIT 1');
        $statement->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        $value = $statement->fetchColumn();
        if ($value === false) return null;
        return match ((string)$value) { '0' => false, '1' => true, default => null };
    } catch (Throwable) { return null; }
}

function api_v2_directory_management_save_sentinel(PDO $pdo, bool $active): void
{
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO app_config(organization_id,config_key,config_value) VALUES(0,?,?) ON CONFLICT(organization_id,config_key) DO UPDATE SET config_value=excluded.config_value'
        : 'INSERT INTO app_config(organization_id,config_key,config_value) VALUES(0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';
    $pdo->prepare($sql)->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY, $active ? '1' : '0']);
}

/**
 * The sentinel is the transaction-scoped Directory cutover gate. Every
 * canonical source writer takes it shared before a source-row lock; ownership
 * transitions take it exclusively before the policy row. This fixed order
 * prevents a cutover from racing a committed source projection.
 */
function api_v2_directory_management_lock_sentinel(PDO $pdo, bool $exclusive): bool
{
    if (!$pdo->inTransaction()) throw new LogicException('Directory cutover gate requires an active transaction.');
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ($exclusive ? ' FOR UPDATE' : ' FOR SHARE')
        : '';
    try {
        $statement = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key=? LIMIT 1' . $lock);
        $statement->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        $value = $statement->fetchColumn();
    } catch (PDOException $error) {
        if (!api_v2_directory_management_sentinel_migration_applied($pdo)) return false;
        throw $error;
    }
    // Pre-sentinel test fixtures and installations have no cutover feature to
    // enforce. Once migration 0103 is recorded, a missing/corrupt row is a
    // fail-closed configuration fault rather than implicit local authority.
    if ($value === false && !api_v2_directory_management_sentinel_migration_applied($pdo)) return false;
    if ($value === false || !in_array((string)$value, ['0', '1'], true)) {
        throw new RuntimeException('Directory cutover sentinel is unavailable.');
    }
    return (string)$value === '1';
}

function api_v2_directory_management_sentinel_migration_applied(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare('SELECT filename FROM schema_migrations WHERE version=103');
        $statement->execute();
        return $statement->fetchColumn() === '0103_external_directory_management_sentinel.sql';
    } catch (Throwable) {
        return false;
    }
}

/** A local source mutation fails closed while external ownership is active. */
function api_v2_directory_management_acquire_shared_gate(PDO $pdo, bool $localAuthority = true): void
{
    $active = api_v2_directory_management_lock_sentinel($pdo, false);
    if (!$localAuthority) return;
    if ($active || api_v2_directory_management_local_policy_owns_directory($pdo)) {
        throw new DomainException(API_V2_DIRECTORY_MANAGEMENT_LABEL);
    }
}

/**
 * A local writer must not trust a stale local sentinel over an active policy.
 * This read deliberately follows the sentinel in the same transaction and
 * takes the corresponding shared lock on MySQL. Before migration 0103 there
 * is no cutover feature to enforce; after it, a missing or malformed policy
 * is fail-closed.
 */
function api_v2_directory_management_local_policy_owns_directory(PDO $pdo): bool
{
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR SHARE' : '';
    try {
        $policy = $pdo->query('SELECT configured_enabled,ownership_active FROM api_v2_directory_management_policy WHERE singleton=1' . $lock)->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        if (!api_v2_directory_management_sentinel_migration_applied($pdo)) return false;
        throw new RuntimeException('Directory cutover policy is unavailable.', 0, $error);
    }
    if (!is_array($policy)) {
        if (!api_v2_directory_management_sentinel_migration_applied($pdo)) return false;
        throw new RuntimeException('Directory cutover policy is unavailable.');
    }
    $ownership = (string)($policy['ownership_active'] ?? '');
    $configured = (string)($policy['configured_enabled'] ?? '');
    if (!in_array($ownership, ['0', '1'], true) || !in_array($configured, ['0', '1'], true)) {
        throw new RuntimeException('Directory cutover policy is malformed.');
    }
    return $ownership === '1';
}

/** @return array<string,mixed> Locked after the sentinel, never before it. */
function api_v2_directory_management_lock_policy_after_sentinel(PDO $pdo): array
{
    api_v2_directory_management_lock_sentinel($pdo, true);
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $policy = $pdo->query('SELECT * FROM api_v2_directory_management_policy WHERE singleton=1' . $lock)->fetch(PDO::FETCH_ASSOC);
    if (!is_array($policy)) throw new RuntimeException('Directory policy row is unavailable.');
    return $policy;
}

/** These explicit scopes are never satisfied by the legacy `full` scope. */
function api_v2_directory_management_required_scopes(): array
{
    return [
        'api.capabilities.read',
        'directory.clients.read', 'directory.organizations.read', 'directory.units.read',
        'directory.clients.binding_status.read', 'directory.organizations.binding_status.read', 'directory.units.binding_status.read',
        'directory.clients.bind', 'directory.organizations.bind', 'directory.units.bind',
        'directory.clients.binding.revision.refresh', 'directory.organizations.binding.revision.refresh', 'directory.units.binding.revision.refresh',
        'directory.clients.write', 'directory.organizations.write', 'directory.units.write',
        'directory.clients.create', 'directory.organizations.create', 'directory.units.create', 'directory.units.organization.assign',
        'directory.clients.archive', 'directory.clients.restore',
        'directory.organizations.archive', 'directory.organizations.restore',
        'directory.units.archive', 'directory.units.restore',
        'directory.units.contacts.assign', 'directory.units.contacts.remove', 'directory.units.contacts.set_primary',
        'directory.clients.organization.assign', 'directory.clients.organization.remove', 'directory.clients.organization.move',
        'directory.clients.unbind', 'directory.organizations.unbind', 'directory.units.unbind', 'directory.inventory.read',
    ];
}

function api_v2_directory_management_required_flags(): array
{
    return [
        'APP_API_V2_DIRECTORY_READ_ENABLED',
        'APP_API_V2_BINDING_STATUS_ENABLED',
        'APP_API_V2_DIRECTORY_BINDING_ENABLED',
        'APP_API_V2_DIRECTORY_BINDING_REFRESH_ENABLED',
        'APP_API_V2_DIRECTORY_ORGANIZATIONS_WRITE_ENABLED',
        'APP_API_V2_DIRECTORY_CLIENTS_WRITE_ENABLED',
        'APP_API_V2_DIRECTORY_UNITS_WRITE_ENABLED',
        'APP_API_V2_DIRECTORY_ORGANIZATIONS_CREATE_ENABLED',
        'APP_API_V2_DIRECTORY_CLIENTS_CREATE_ENABLED',
        'APP_API_V2_DIRECTORY_UNITS_CREATE_ENABLED',
        'APP_API_V2_DIRECTORY_CLIENTS_ARCHIVE_ENABLED',
        'APP_API_V2_DIRECTORY_CLIENTS_RESTORE_ENABLED',
        'APP_API_V2_DIRECTORY_ORGANIZATIONS_ARCHIVE_ENABLED',
        'APP_API_V2_DIRECTORY_ORGANIZATIONS_RESTORE_ENABLED',
        'APP_API_V2_DIRECTORY_UNITS_ARCHIVE_ENABLED',
        'APP_API_V2_DIRECTORY_UNITS_RESTORE_ENABLED',
        'APP_API_V2_DIRECTORY_UNIT_CONTACTS_WRITE_ENABLED',
        'APP_API_V2_DIRECTORY_RELATIONSHIPS_WRITE_ENABLED',
        'APP_API_V2_DIRECTORY_BINDING_REVOKE_ENABLED',
        'APP_API_V2_DIRECTORY_INVENTORY_ENABLED',
    ];
}

/**
 * Route proof is deliberately tied to the checked-in stateless controllers.
 * Permanent deletion is not a replacement requirement: managed mode blocks
 * local purge/delete while API lifecycle commands preserve every linked row.
 */
function api_v2_directory_management_replacement_routes_implemented(): bool
{
    $root=dirname(__DIR__,2);
    foreach([
        'src/controllers/api/directory_lifecycle_command_v2.php',
        'src/controllers/api/directory_relationship_command_v2.php',
        'src/controllers/api/directory_binding_revoke_command_v2.php',
        'src/controllers/api/directory_inventory_v2.php',
        'src/controllers/api/directory_unit_profile_command_v2.php',
        'src/controllers/api/directory_unit_contact_command_v2.php',
    ]as$path)if(!is_file($root.'/'.$path))return false;
    return true;
}

function api_v2_directory_management_flags_ready(): bool
{
    foreach(api_v2_directory_management_required_flags() as $flag)if(!api_v2_enabled($flag))return false;
    return true;
}

function api_v2_directory_management_key_ready(PDO $pdo, int $applicationPk): bool
{
    $keys=$pdo->prepare('SELECT scopes FROM api_keys WHERE api_v2_application_id=? AND revoked_at IS NULL ORDER BY id');
    $keys->execute([$applicationPk]);$live=$keys->fetchAll(PDO::FETCH_COLUMN);
    if(count($live)!==1)return false;
    $scopes=api_normalize_scopes($live[0]);
    return !in_array('full',$scopes,true)&&array_diff(api_v2_directory_management_required_scopes(),$scopes)===[];
}

function api_v2_directory_management_attestation_ready(PDO $pdo, array $policy): bool
{
    $stored=$pdo->prepare('SELECT attestation_json FROM api_v2_directory_management_attestations WHERE attestation_sha256=?');
    $stored->execute([(string)($policy['release_attestation_sha256']??'')]);$json=$stored->fetchColumn();
    $proof=is_string($json)?json_decode($json,true):null;
    return is_array($proof)
        && hash_equals((string)($policy['release_attestation_sha256']??''),hash('sha256',(string)$json))
        && (int)($proof['schemaVersion']??0)===105
        && hash_equals((string)($proof['writerDigest']??''),api_v2_directory_management_code_digest());
}

/** Activation rechecks the mutable backfill proof, not just the code digest. */
function api_v2_directory_management_activation_attestation_ready(PDO $pdo, array $policy): bool
{
    if (!api_v2_directory_management_attestation_ready($pdo, $policy)) return false;
    $statement = $pdo->prepare('SELECT attestation_json FROM api_v2_directory_management_attestations WHERE attestation_sha256=?');
    $statement->execute([(string)($policy['release_attestation_sha256'] ?? '')]);
    $stored = $statement->fetchColumn();
    $proof = is_string($stored) ? json_decode($stored, true) : null;
    if (!is_array($proof) || preg_match('/^[0-9a-f]{64}$/D', (string)($proof['backfillDigest'] ?? '')) !== 1) return false;
    $backfill = api_v2_directory_backfill_attestation($pdo);
    if (!$backfill['complete']) return false;
    $digest = hash('sha256', api_v2_directory_backfill_attestation_json($backfill));
    return hash_equals((string)$proof['backfillDigest'], $digest)
        && api_v2_directory_backfill_attestation_receipt_is_current($pdo, $digest);
}

/** Active ownership requires a healthy current projection, not a frozen snapshot. */
function api_v2_directory_management_backfill_health_ready(PDO $pdo): bool
{
    $backfill = api_v2_directory_backfill_attestation($pdo);
    return !empty($backfill['complete']);
}

/** Complete interactive writer inventory. API controllers are intentionally absent. */
function api_v2_directory_management_browser_writers(): array
{
    return [
        'client/clients-create' => ['client', 'create'], 'clients-create' => ['client', 'create'],
        'client/clients-update' => ['client', 'profile'], 'clients-update' => ['client', 'profile'],
        'client/clients-delete' => ['client', 'archive'], 'clients-delete' => ['client', 'archive'],
        'client/clients-restore' => ['client', 'restore'], 'clients-restore' => ['client', 'restore'],
        'client/clients-purge' => ['client', 'delete'], 'clients-purge' => ['client', 'delete'],
        'organization/org-create' => ['organization', 'create'],
        'organization/organizations-create' => ['organization', 'create'],
        'organization/organizations-update' => ['organization', 'profile'],
        'organization/organizations-delete' => ['directory', 'delete'],
        'organization/organization-add-client' => ['relationship', 'assign'],
        'organization/organization-remove-client' => ['relationship', 'remove'],
        'organization/organization-departments' => ['unit', 'mutate'],
    ];
}

function api_v2_directory_management_schema_ready(PDO $pdo): bool
{
    try {
        foreach (['api_v2_directory_management_policy', 'api_v2_directory_management_attestations', 'api_v2_directory_management_audit'] as $table) {
            $pdo->query('SELECT 1 FROM ' . $table . ' WHERE 1=0');
        }
        $pdo->query('SELECT application_pk,resource_type,history_epoch,command_id,request_sha256,action_name,public_id,expected_revision,expected_authorization_generation,result_revision,result_authorization_generation FROM api_v2_directory_lifecycle_command_receipts WHERE 1=0');
        $pdo->query('SELECT application_pk,history_epoch,command_id,request_sha256,action_name,client_public_id,expected_client_revision,expected_authorization_generation,result_client_revision,result_authorization_generation FROM api_v2_directory_relationship_command_receipts WHERE 1=0');
        $pdo->query('SELECT application_pk,resource_type,history_epoch,command_id,request_sha256,external_id,public_id,expected_resource_revision,expected_authorization_generation,result_authorization_generation FROM api_v2_directory_binding_revoke_command_receipts WHERE 1=0');
        $pdo->query('SELECT application_pk,resource_type,history_epoch,command_id,request_sha256,external_id,public_id,resource_revision FROM api_v2_directory_binding_command_receipts WHERE 1=0');
        $pdo->query('SELECT application_pk,resource_type,history_epoch,command_id,request_sha256,external_id,public_id,result_revision FROM api_v2_directory_binding_revision_refresh_receipts WHERE 1=0');
        $pdo->query('SELECT application_pk,resource_type,history_epoch,command_id,request_sha256,external_id,public_id,result_revision FROM api_v2_directory_create_command_receipts WHERE 1=0');
        $pdo->query('SELECT archived,deleted_at FROM organizations WHERE 1=0');
        $pdo->query('SELECT archived,deleted_at FROM organization_departments WHERE 1=0');
        $pdo->query('SELECT application_pk,history_epoch,command_id,request_sha256,public_id,expected_revision,result_revision FROM api_v2_directory_unit_profile_command_receipts WHERE 1=0');
        $pdo->query('SELECT application_pk,history_epoch,command_id,request_sha256,action_name,unit_public_id,client_public_id,result_unit_revision FROM api_v2_directory_unit_contact_command_receipts WHERE 1=0');
        $expected=[
            88=>'0088_api_v2_application_identity.sql',89=>'0089_api_v2_directory_revision_foundation.sql',
            90=>'0090_api_v2_directory_binding_status_foundation.sql',91=>'0091_api_v2_directory_binding_command_receipts.sql',
            92=>'0092_api_v2_directory_binding_revision_refresh_receipts.sql',93=>'0093_api_v2_directory_organization_profile_command_receipts.sql',
            94=>'0094_api_v2_directory_client_profile_command_receipts.sql',95=>'0095_api_v2_directory_binding_lifecycle.sql',
            96=>'0096_api_v2_directory_backfill_attestations.sql',97=>'0097_api_v2_directory_create_command_receipts.sql',
            98=>'0098_external_directory_management_policy.sql',99=>'0099_api_v2_directory_lifecycle_relationships.sql',
            103=>'0103_external_directory_management_sentinel.sql',
            104=>'0104_api_v2_directory_units.sql',
            105=>'0105_api_v2_directory_receipt_history_epochs.sql',
        ];
        $migration = $pdo->prepare('SELECT filename FROM schema_migrations WHERE version=?');
        foreach($expected as $version=>$filename){$migration->execute([$version]);if($migration->fetchColumn()!==$filename)return false;}
        return true;
    } catch (Throwable) { return false; }
}

function api_v2_directory_management_code_digest(): string
{
    $root = dirname(__DIR__, 2);
    $paths = array_values(array_unique(array_merge(
        array_column(api_v2_directory_writer_inventory(), 'path'),
        ['public/index.php', 'src/utils/api_scopes.php', 'src/utils/api_v2_capabilities.php',
         'src/utils/api_v2_directory_management.php','src/controllers/api/directory_read_v2.php',
         'src/controllers/api/directory_binding_command_v2.php','src/controllers/api/directory_binding_revision_refresh_v2.php',
         'src/controllers/api/directory_organization_profile_command_v2.php','src/controllers/api/directory_client_profile_command_v2.php',
         'src/controllers/api/directory_create_command_v2.php','src/controllers/api/directory_lifecycle_command_v2.php',
         'src/controllers/api/directory_relationship_command_v2.php','src/controllers/api/directory_binding_revoke_command_v2.php',
         'src/controllers/api/directory_inventory_v2.php','src/controllers/api/directory_unit_profile_command_v2.php',
         'src/controllers/api/directory_unit_contact_command_v2.php','src/utils/api_v2_directory_lifecycle_command.php',
         'src/utils/api_v2_directory_relationship_command.php','src/utils/api_v2_directory_binding_revoke_command.php',
         'src/utils/api_v2_directory_inventory.php','src/utils/api_v2_directory_unit_profile_command.php',
         'src/utils/api_v2_directory_unit_contact_command.php','src/utils/api_v2_directory_backfill.php',
         'src/utils/api_v2_directory_release_safety.php']
    )));
    foreach(range(88,105) as $version){$match=glob($root.'/database/migrations/'.str_pad((string)$version,4,'0',STR_PAD_LEFT).'_*.sql');if(count($match)!==1)return '';$paths[]=str_replace('\\','/',substr($match[0],strlen($root)+1));}
    $paths=array_values(array_unique($paths));
    sort($paths, SORT_STRING);
    $evidence = [];
    foreach ($paths as $path) {
        $contents = @file_get_contents($root . '/' . $path);
        if (!is_string($contents)) return '';
        $evidence[$path] = hash('sha256', $contents);
    }
    return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/** @return array{complete:bool,reason:string,json:string,digest:string} */
function api_v2_directory_management_release_attestation(PDO $pdo): array
{
    if (!api_v2_directory_management_schema_ready($pdo)) return ['complete'=>false,'reason'=>'schema_unavailable','json'=>'','digest'=>''];
    $codeDigest = api_v2_directory_management_code_digest();
    if ($codeDigest === '') return ['complete'=>false,'reason'=>'writer_inventory_incomplete','json'=>'','digest'=>''];
    $backfill = api_v2_directory_backfill_attestation($pdo);
    if (!$backfill['complete']) return ['complete'=>false,'reason'=>'backfill_incomplete','json'=>'','digest'=>''];
    $backfillJson = api_v2_directory_backfill_attestation_json($backfill);
    $backfillDigest = hash('sha256', $backfillJson);
    if (!api_v2_directory_backfill_attestation_receipt_is_current($pdo, $backfillDigest)) {
        return ['complete'=>false,'reason'=>'backfill_receipt_stale','json'=>'','digest'=>''];
    }
    $payload = ['version'=>1,'schemaVersion'=>105,'writerDigest'=>$codeDigest,'backfillDigest'=>$backfillDigest];
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return ['complete'=>true,'reason'=>'ready','json'=>$json,'digest'=>hash('sha256', $json)];
}

function api_v2_directory_management_persist_attestation(PDO $pdo, int $actorUserId): string
{
    $proof = api_v2_directory_management_release_attestation($pdo);
    if (!$proof['complete']) throw new RuntimeException('Release-safety evidence is incomplete: ' . $proof['reason']);
    $statement = $pdo->prepare('INSERT INTO api_v2_directory_management_attestations(attestation_sha256,attestation_json,created_by) VALUES(?,?,?)');
    try { $statement->execute([$proof['digest'], $proof['json'], $actorUserId ?: null]); }
    catch (PDOException $error) {
        $existing = $pdo->prepare('SELECT attestation_json FROM api_v2_directory_management_attestations WHERE attestation_sha256=?');
        $existing->execute([$proof['digest']]);
        if ($existing->fetchColumn() !== $proof['json']) throw $error;
    }
    return $proof['digest'];
}

function api_v2_directory_management_audit(PDO $pdo, string $event, string $outcome, string $reason, ?int $applicationPk, int $actor, ?string $target = null, ?string $action = null, array $metadata = []): void
{
    $pdo->prepare('INSERT INTO api_v2_directory_management_audit(event_type,outcome,reason,application_pk,actor_user_id,target_type,action_name,metadata_json) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$event,$outcome,$reason,$applicationPk,$actor ?: null,$target,$action,$metadata ? json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null]);
}

/** @return array{configured:bool,effective:bool,reason:string,eligibility_reason:string,application_pk:?int,label:string} */
function api_v2_directory_management_status(PDO $pdo, bool $recordTransition = true): array
{
    $base = ['configured'=>false,'effective'=>false,'reason'=>'not_configured','eligibility_reason'=>'not_configured','application_pk'=>null,'label'=>API_V2_DIRECTORY_MANAGEMENT_LABEL];
    $sentinel = api_v2_directory_management_sentinel_active($pdo);
    $sentinelUnavailable = $sentinel === null;
    $wasActive = $sentinel === true;
    if (!api_v2_directory_management_schema_ready($pdo)) {
        if ($wasActive || $sentinelUnavailable) return ['configured'=>true,'effective'=>true,'reason'=>'managed_degraded','eligibility_reason'=>'schema_unavailable','application_pk'=>null,'label'=>API_V2_DIRECTORY_MANAGEMENT_LABEL];
        $base['reason'] = 'schema_unavailable'; return $base;
    }
    try {
        $policy = $pdo->query('SELECT * FROM api_v2_directory_management_policy WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
        if (!$policy || (int)$policy['configured_enabled'] !== 1) {
            if ($wasActive || $sentinelUnavailable) return ['configured'=>true,'effective'=>true,'reason'=>'managed_degraded','eligibility_reason'=>'policy_disagrees_with_sentinel','application_pk'=>null,'label'=>API_V2_DIRECTORY_MANAGEMENT_LABEL];
            return $base;
        }
        $wasActive = $wasActive || (int)($policy['ownership_active'] ?? 0) === 1;
        $base['configured'] = true; $base['application_pk'] = (int)$policy['application_pk'];
        $reason = 'ready';
        $identity = $pdo->prepare('SELECT app.application_id,history.source_instance_id,history.history_epoch,auth.authorization_generation
            FROM api_v2_applications app CROSS JOIN api_v2_history_identity history
            LEFT JOIN api_v2_directory_authorization_state auth ON auth.application_pk=app.id
            WHERE app.id=? AND history.singleton=1');
        $identity->execute([$base['application_pk']]); $identity = $identity->fetch(PDO::FETCH_ASSOC);
        $generation=(string)($identity['authorization_generation']??'');
        if (!$identity || preg_match('/^(0|[1-9][0-9]{0,18})$/D',$generation)!==1 || (strlen($generation)===19&&strcmp($generation,'9223372036854775807')>=0) || !api_v2_identity_is_valid($identity)
            || count(array_unique([(string)$identity['application_id'],(string)$identity['source_instance_id'],(string)$identity['history_epoch']]))!==3
            || !hash_equals((string)$policy['application_id'], (string)($identity['application_id'] ?? ''))
            || !hash_equals((string)$policy['source_instance_id'], (string)($identity['source_instance_id'] ?? ''))
            || !hash_equals((string)$policy['history_epoch'], (string)($identity['history_epoch'] ?? ''))) $reason = 'identity_changed';
        if ($reason === 'ready' && !api_v2_directory_management_replacement_routes_implemented()) $reason = 'replacement_routes_unavailable';
        if($reason==='ready'&&!api_v2_directory_management_flags_ready())$reason='route_disabled';
        if($reason==='ready'&&!api_v2_directory_management_key_ready($pdo,$base['application_pk']))$reason='authorized_key_unavailable';
        if($reason==='ready'&&!api_v2_directory_management_attestation_ready($pdo,$policy))$reason='attestation_stale';
        if($reason==='ready'&&!api_v2_directory_management_backfill_health_ready($pdo))$reason='backfill_unhealthy';
        $base['eligibility_reason'] = $reason;
        $base['effective'] = $wasActive;
        $base['reason'] = $base['effective'] && $reason !== 'ready' ? 'managed_degraded' : $reason;
        if ($recordTransition && ((int)$policy['last_effective'] !== (int)$base['effective'] || (string)$policy['last_reason'] !== $base['reason'])) {
            $update = $pdo->prepare('UPDATE api_v2_directory_management_policy SET last_effective=?,last_reason=? WHERE singleton=1 AND (last_effective<>? OR last_reason<>?)');
            $update->execute([(int)$base['effective'],$base['reason'],(int)$base['effective'],$base['reason']]);
            if ($update->rowCount() === 1) api_v2_directory_management_audit($pdo,'effective_state_changed',$base['effective']?'effective':'inactive',$base['reason'],$base['application_pk'],0,null,null,['eligibility_reason'=>$reason]);
        }
        return $base;
    } catch (Throwable $error) {
        error_log('[DirectoryManagement] health unavailable ' . get_class($error));
        $mustBlock = $wasActive || $sentinelUnavailable;
        return ['configured'=>true,'effective'=>$mustBlock,'reason'=>$mustBlock?'managed_degraded':'health_unavailable','eligibility_reason'=>'health_unavailable','application_pk'=>$base['application_pk'],'label'=>API_V2_DIRECTORY_MANAGEMENT_LABEL];
    }
}

function api_v2_directory_management_save(PDO $pdo, bool $enabled, int $applicationPk, int $actorUserId, bool $confirmed): array
{
    if (!$confirmed) throw new DomainException('Explicit confirmation is required.');
    if (!api_v2_directory_management_schema_ready($pdo)) throw new RuntimeException('Directory policy storage is unavailable.');
    $pdo->beginTransaction();
    try {
        $current=api_v2_directory_management_lock_policy_after_sentinel($pdo);
        if($enabled&&(int)$current['ownership_active']===1&&(int)$current['application_pk']!==$applicationPk)throw new DomainException('Take local control before changing the owning application.');
        $ownershipActive=$enabled?(int)$current['ownership_active']:0;
        $lastEffective=$enabled?(int)$current['last_effective']:0;
        $identity = null; $digest = null;
        if ($enabled) {
            $statement = $pdo->prepare('SELECT app.application_id,history.source_instance_id,history.history_epoch FROM api_v2_applications app CROSS JOIN api_v2_history_identity history WHERE app.id=? AND history.singleton=1');
            $statement->execute([$applicationPk]); $identity = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$identity || !api_v2_identity_is_valid($identity)) throw new DomainException('Choose a valid application identity.');
            $pdo->commit(); // Release attestations intentionally run outside a transaction.
            $digest = api_v2_directory_management_persist_attestation($pdo, $actorUserId);
            $pdo->beginTransaction();
            $fresh=api_v2_directory_management_lock_policy_after_sentinel($pdo);
            if(!$fresh||(int)$fresh['ownership_active']!==$ownershipActive||(int)$fresh['application_pk']!==(int)$current['application_pk'])throw new RuntimeException('Directory policy changed while release evidence was prepared.');
        }
        $pdo->prepare('UPDATE api_v2_directory_management_policy SET configured_enabled=?,ownership_active=?,application_pk=?,source_instance_id=?,application_id=?,history_epoch=?,release_attestation_sha256=?,last_effective=?,last_reason=?,configured_by=?,configured_at=CURRENT_TIMESTAMP WHERE singleton=1')
            ->execute([(int)$enabled,$ownershipActive,$enabled?$applicationPk:null,$identity['source_instance_id']??null,$identity['application_id']??null,$identity['history_epoch']??null,$digest,$lastEffective,$enabled?'pending_evaluation':'not_configured',$actorUserId ?: null]);
        if (!$enabled) api_v2_directory_management_save_sentinel($pdo, false);
        api_v2_directory_management_audit($pdo,'policy_saved',$enabled?'configured':'disabled',$enabled?'pending_evaluation':'not_configured',$enabled?$applicationPk:null,$actorUserId);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    return api_v2_directory_management_status($pdo);
}

function api_v2_directory_management_activate(PDO $pdo, int $actorUserId, bool $confirmed): array
{
    if (!$confirmed) throw new DomainException('Explicit activation confirmation is required.');
    $pdo->beginTransaction();
    try{
        // Retain the exclusive sentinel gate while proving the current policy,
        // identity, code digest, and mutable backfill attestation.  No source
        // writer can commit between this proof and ownership activation.
        $policy = api_v2_directory_management_lock_policy_after_sentinel($pdo);
        $preStatus = api_v2_directory_management_status($pdo, false);
        if (!$preStatus['configured'] || $preStatus['eligibility_reason'] !== 'ready') throw new DomainException('Directory ownership cannot be activated: ' . $preStatus['eligibility_reason']);
        if (!api_v2_directory_management_activation_attestation_ready($pdo, $policy)) throw new DomainException('Directory ownership cannot be activated: backfill_attestation_stale');
        $statement=$pdo->prepare('UPDATE api_v2_directory_management_policy SET ownership_active=1,last_effective=1,last_reason=\'ready\' WHERE singleton=1 AND configured_enabled=1 AND ownership_active=0');
        $statement->execute();
        if($statement->rowCount()!==1) throw new RuntimeException('Directory ownership activation changed concurrently.');
        api_v2_directory_management_save_sentinel($pdo, true);
        api_v2_directory_management_audit($pdo,'ownership_activated','effective','ready',$preStatus['application_pk'],$actorUserId);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
    return api_v2_directory_management_status($pdo, false);
}

function api_v2_directory_management_guard(PDO $pdo, string $target, string $action): bool
{
    $status = api_v2_directory_management_status($pdo);
    if (!$status['effective']) return false;
    try { api_v2_directory_management_audit($pdo,'browser_write_denied','denied','externally_managed',$status['application_pk'],(int)($_SESSION['user']['id']??0),$target,$action); }
    catch (Throwable $error) { error_log('[DirectoryManagement] denial audit unavailable ' . get_class($error)); }
    return true;
}

function api_v2_directory_management_warning(array $status): string
{
    if (!$status['configured']) return '';
    if ($status['effective']) return $status['reason']==='managed_degraded'
        ? API_V2_DIRECTORY_MANAGEMENT_LABEL . '; a safety check is degraded and local directory writes remain blocked until an explicit administrator takeover.'
        : API_V2_DIRECTORY_MANAGEMENT_LABEL;
    return 'External directory management is configured but inactive; local administrator changes remain available.';
}
