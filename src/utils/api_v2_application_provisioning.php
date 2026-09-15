<?php
declare(strict_types=1);

require_once __DIR__ . '/api_scopes.php';

/**
 * This is deliberately an operator-only provisioning primitive. It never
 * receives, reads, logs, or returns API-key material; the numeric key id is
 * the sole key selector.
 */
function api_v2_application_provision_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function api_v2_application_provision_table_exists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function api_v2_application_provision_column_exists(PDO $pdo, string $table, string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $quoted = str_replace("'", "''", $table);
        foreach ($pdo->query("PRAGMA table_info('{$quoted}')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) return true;
        }
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() !== false;
}

/** Refuse a partially migrated database instead of trying to repair it. */
function api_v2_application_provision_schema_ready(PDO $pdo): bool
{
    foreach (['schema_migrations', 'api_keys', 'api_v2_history_identity', 'api_v2_applications', 'api_v2_directory_authorization_state'] as $table) {
        if (!api_v2_application_provision_table_exists($pdo, $table)) return false;
    }
    foreach ([['api_keys', 'api_v2_application_id'], ['api_v2_history_identity', 'source_instance_id'], ['api_v2_history_identity', 'history_epoch'], ['api_v2_applications', 'application_id'], ['api_v2_applications', 'name'], ['api_v2_directory_authorization_state', 'application_pk'], ['api_v2_directory_authorization_state', 'authorization_generation']] as [$table, $column]) {
        if (!api_v2_application_provision_column_exists($pdo, $table, $column)) return false;
    }
    if(api_v2_application_provision_table_exists($pdo,'api_v2_project_authorization_state')
        &&(!api_v2_application_provision_column_exists($pdo,'api_v2_project_authorization_state','application_pk')
            ||!api_v2_application_provision_column_exists($pdo,'api_v2_project_authorization_state','authorization_generation')))return false;
    $stmt = $pdo->prepare('SELECT version,filename FROM schema_migrations WHERE version IN (88,89)');
    $stmt->execute();
    $applied = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $applied[(int)$row['version']] = (string)$row['filename'];
    $projectMigration=$pdo->prepare('SELECT filename FROM schema_migrations WHERE version=102');$projectMigration->execute();$projectFilename=$projectMigration->fetchColumn();
    return ($applied[88] ?? null) === '0088_api_v2_application_identity.sql'
        && ($applied[89] ?? null) === '0089_api_v2_directory_revision_foundation.sql'
        && ($projectFilename===false||($projectFilename==='0102_api_v2_project_synchronization.sql'&&api_v2_application_project_authorization_available($pdo)));
}

function api_v2_application_project_authorization_available(PDO $pdo): bool
{
    return api_v2_application_provision_table_exists($pdo,'api_v2_project_authorization_state')
        && api_v2_application_provision_column_exists($pdo,'api_v2_project_authorization_state','application_pk')
        && api_v2_application_provision_column_exists($pdo,'api_v2_project_authorization_state','authorization_generation');
}

function api_v2_application_provision_name_valid(string $name): bool
{
    return $name !== '' && preg_match('/^\s*$/u', $name) !== 1 && strlen($name) <= 764 && preg_match('//u', $name) === 1
        && preg_match('/\p{C}/u', $name) !== 1 && (preg_match_all('/./us', $name) ?: 0) <= 191;
}

/**
 * @return array{dryRun:bool,apiKeyId:int,applicationId?:string,alreadyProvisioned?:bool}
 */
function api_v2_application_provision(PDO $pdo, int $apiKeyId, string $name, bool $dryRun): array
{
    if ($apiKeyId < 1 || !api_v2_application_provision_name_valid($name) || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Invalid provisioning request.');
    }
    if (!api_v2_application_provision_schema_ready($pdo)) {
        throw new RuntimeException('Required API v2 migrations are missing or incomplete.');
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $key = $pdo->prepare('SELECT id,scopes,revoked_at,api_v2_application_id FROM api_keys WHERE id=?' . $lock);
        $key->execute([$apiKeyId]);
        $row = $key->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['revoked_at'] !== null) {
            throw new RuntimeException('The selected API key is not an active, unbound dedicated key.');
        }
        $scopes = api_normalize_scopes($row['scopes'] ?? '');
        if (in_array('full', $scopes, true) || !in_array('api.capabilities.read', $scopes, true)) {
            throw new RuntimeException('The selected API key does not have an acceptable explicit API v2 scope set.');
        }
        $identity = $pdo->query('SELECT source_instance_id,history_epoch FROM api_v2_history_identity WHERE singleton=1' . $lock)->fetch(PDO::FETCH_ASSOC);
        foreach (['source_instance_id', 'history_epoch'] as $field) {
            if (!is_array($identity) || !is_string($identity[$field] ?? null)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $identity[$field]) !== 1) {
                throw new RuntimeException('The API v2 source identity is missing or invalid.');
            }
        }
        if ($row['api_v2_application_id'] !== null) {
            $application = $pdo->prepare('SELECT application_id,name FROM api_v2_applications WHERE id=?' . $lock);
            $application->execute([(int)$row['api_v2_application_id']]);
            $existing = $application->fetch(PDO::FETCH_ASSOC);
            $authorization = $pdo->prepare('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock);
            $authorization->execute([(int)$row['api_v2_application_id']]);
            $generation = $authorization->fetchColumn();
            $validId = is_array($existing) && is_string($existing['application_id'] ?? null)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $existing['application_id']) === 1;
            $validGeneration = is_scalar($generation) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string)$generation) === 1
                && (strlen((string)$generation) < 19 || strcmp((string)$generation, '9223372036854775807') <= 0);
            $validProjectGeneration=true;
            if(api_v2_application_project_authorization_available($pdo)){$projectAuthorization=$pdo->prepare('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=?'.$lock);$projectAuthorization->execute([(int)$row['api_v2_application_id']]);$projectGeneration=$projectAuthorization->fetchColumn();$validProjectGeneration=is_scalar($projectGeneration)&&preg_match('/^(0|[1-9][0-9]{0,18})$/D',(string)$projectGeneration)===1&&(strlen((string)$projectGeneration)<19||strcmp((string)$projectGeneration,'9223372036854775807')<=0);}
            if (!$validId || !hash_equals((string)$existing['name'], $name) || !$validGeneration || !$validProjectGeneration) {
                throw new RuntimeException('The selected API key has an incompatible or incomplete existing API v2 binding.');
            }
            $pdo->rollBack();
            return ['dryRun' => $dryRun, 'apiKeyId' => $apiKeyId, 'applicationId' => $existing['application_id'], 'alreadyProvisioned' => true];
        }
        if ($dryRun) {
            $pdo->rollBack();
            return ['dryRun' => true, 'apiKeyId' => $apiKeyId];
        }

        $applicationId = api_v2_application_provision_uuid_v4();
        $insertApplication = $pdo->prepare('INSERT INTO api_v2_applications(application_id,name) VALUES(?,?)');
        $insertApplication->execute([$applicationId, $name]);
        $applicationPk = (int)$pdo->lastInsertId();
        if ($applicationPk < 1) throw new RuntimeException('Application identity creation failed.');
        $pdo->prepare('INSERT INTO api_v2_directory_authorization_state(application_pk,authorization_generation) VALUES(?,0)')
            ->execute([$applicationPk]);
        if(api_v2_application_project_authorization_available($pdo))$pdo->prepare('INSERT INTO api_v2_project_authorization_state(application_pk,authorization_generation) VALUES(?,0)')->execute([$applicationPk]);
        $bind = $pdo->prepare('UPDATE api_keys SET api_v2_application_id=? WHERE id=? AND revoked_at IS NULL AND api_v2_application_id IS NULL');
        $bind->execute([$applicationPk, $apiKeyId]);
        if ($bind->rowCount() !== 1) throw new RuntimeException('The selected API key changed during provisioning.');
        $pdo->commit();
        return ['dryRun' => false, 'apiKeyId' => $apiKeyId, 'applicationId' => $applicationId, 'alreadyProvisioned' => false];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
