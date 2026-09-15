<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_backfill.php';

/**
 * Read-only, deterministic evidence that a local directory backfill covers the
 * current source rows. This is intentionally an attestation, not an activator:
 * callers must still keep the API capability disabled until their release
 * process accepts a complete result.
 *
 * @return array{attestationVersion:int,schemaReady:bool,complete:bool,resources:array<string,array<string,int>>,violations:array<string,int>}
 */
function api_v2_directory_backfill_attestation(PDO $pdo): array
{
    if ($pdo->inTransaction()) throw new LogicException('Directory backfill attestation requires no active transaction.');
    $resources = [
        'client' => ['source' => 0, 'covered' => 0, 'invalid' => 0, 'missing' => 0, 'drifted' => 0, 'history' => 0, 'orphaned' => 0],
        'organization' => ['source' => 0, 'covered' => 0, 'invalid' => 0, 'missing' => 0, 'drifted' => 0, 'history' => 0, 'orphaned' => 0],
    ];
    $violations = ['schema' => 0, 'identity' => 0, 'missing_state' => 0, 'projection_drift' => 0, 'history_gap' => 0, 'orphaned_state' => 0];
    if (!api_v2_directory_backfill_schema_ready($pdo)) {
        $violations['schema'] = 1;
        return ['attestationVersion' => 1, 'schemaReady' => false, 'complete' => false, 'resources' => $resources, 'violations' => $violations];
    }

    foreach (['client' => 'clients', 'organization' => 'organizations'] as $type => $table) {
        $source = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $live = [];
        foreach ($source as $row) {
            $resources[$type]['source']++;
            $publicId = (string)($row['public_id'] ?? '');
            if (preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1 || isset($live[$publicId])) {
                $resources[$type]['invalid']++; $violations['identity']++; continue;
            }
            $live[$publicId] = true;
            $state = $pdo->prepare('SELECT revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?');
            $state->execute([$type, $publicId]); $state = $state->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state) || (string)($state['present'] ?? '') !== '1') {
                $resources[$type]['missing']++; $violations['missing_state']++; continue;
            }
            $revision = (string)($state['revision'] ?? '');
            $hash = (string)($state['projection_sha256'] ?? '');
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $revision) !== 1 || (strlen($revision) === 19 && strcmp($revision, '9223372036854775807') > 0)
                || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1 || !hash_equals($hash, api_v2_directory_projection_hash($type, $row))) {
                $resources[$type]['drifted']++; $violations['projection_drift']++; continue;
            }
            $change = $pdo->prepare("SELECT action FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision=?");
            $change->execute([$type, $publicId, $revision]);
            $future = $pdo->prepare('SELECT 1 FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision>? LIMIT 1');
            $future->execute([$type, $publicId, $revision]);
            $historyCount = $pdo->prepare('SELECT COUNT(*) FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision<=?');
            $historyCount->execute([$type, $publicId, $revision]);
            if ($change->fetchColumn() !== 'upsert' || $future->fetchColumn() !== false || (int)$historyCount->fetchColumn() !== (int)$revision) {
                $resources[$type]['history']++; $violations['history_gap']++; continue;
            }
            $resources[$type]['covered']++;
        }
        $states = $pdo->prepare('SELECT public_id FROM api_v2_directory_resource_state WHERE resource_type=? AND present=1');
        $states->execute([$type]);
        foreach ($states->fetchAll(PDO::FETCH_COLUMN) as $publicId) {
            if (!isset($live[(string)$publicId])) { $resources[$type]['orphaned']++; $violations['orphaned_state']++; }
        }
    }
    $complete = array_sum($violations) === 0;
    return ['attestationVersion' => 1, 'schemaReady' => true, 'complete' => $complete, 'resources' => $resources, 'violations' => $violations];
}

/** @param array{attestationVersion:int,schemaReady:bool,complete:bool,resources:array<string,array<string,int>>,violations:array<string,int>} $attestation */
function api_v2_directory_backfill_attestation_json(array $attestation): string
{
    return json_encode($attestation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function api_v2_directory_backfill_attestation_receipt_schema_ready(PDO $pdo): bool
{
    return api_v2_directory_backfill_table_exists($pdo, 'api_v2_directory_backfill_attestations')
        && api_v2_directory_backfill_column_exists($pdo, 'api_v2_directory_backfill_attestations', 'attestation_sha256')
        && api_v2_directory_backfill_column_exists($pdo, 'api_v2_directory_backfill_attestations', 'attestation_json');
}

/**
 * Persist one immutable receipt only for a complete fresh attestation. Repeating
 * the same proof is idempotent; a different current state produces a new digest.
 */
function api_v2_directory_backfill_attestation_persist(PDO $pdo): string
{
    if ($pdo->inTransaction() || !api_v2_directory_backfill_attestation_receipt_schema_ready($pdo)) {
        throw new RuntimeException('Directory backfill receipt storage is unavailable.');
    }
    $attestation = api_v2_directory_backfill_attestation($pdo);
    if (!$attestation['complete']) throw new RuntimeException('Directory backfill is not complete.');
    $json = api_v2_directory_backfill_attestation_json($attestation); $digest = hash('sha256', $json);
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT attestation_json FROM api_v2_directory_backfill_attestations WHERE attestation_sha256=?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $existing->execute([$digest]); $stored = $existing->fetchColumn();
        if ($stored !== false && !hash_equals($json, (string)$stored)) throw new RuntimeException('Directory backfill receipt digest conflict.');
        if ($stored === false) $pdo->prepare('INSERT INTO api_v2_directory_backfill_attestations(attestation_sha256,attestation_json) VALUES (?,?)')->execute([$digest, $json]);
        $pdo->commit(); return $digest;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack(); throw $error;
    }
}

/** A release gate must read this back after retaining the returned digest. */
function api_v2_directory_backfill_attestation_receipt_is_current(PDO $pdo, string $digest): bool
{
    if ($pdo->inTransaction() || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1 || !api_v2_directory_backfill_attestation_receipt_schema_ready($pdo)) return false;
    $attestation = api_v2_directory_backfill_attestation($pdo);
    if (!$attestation['complete']) return false;
    $json = api_v2_directory_backfill_attestation_json($attestation);
    if (!hash_equals($digest, hash('sha256', $json))) return false;
    $statement = $pdo->prepare('SELECT attestation_json FROM api_v2_directory_backfill_attestations WHERE attestation_sha256=?');
    $statement->execute([$digest]); $stored = $statement->fetchColumn();
    return $stored !== false && hash_equals($json, (string)$stored);
}

/**
 * Complete source inventory for SQL writers touching directory source tables.
 * `revision` means the file calls the shared transaction-boundary helper;
 * `caller` is the one restoration service, whose only runtime caller does so;
 * `non_projection` entries are deliberately excluded after source review.
 *
 * @return list<array{path:string,target:string,governance:string,evidence:string}>
 */
function api_v2_directory_writer_inventory(): array
{
    return [
        ['path' => 'src/controllers/client/client_onboarding_review.php', 'target' => 'both', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/controllers/client/clients_create.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/controllers/client/clients_delete.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record_delete'],
        ['path' => 'src/controllers/client/clients_purge.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record_delete'],
        ['path' => 'src/controllers/organization/organization_add_client.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/controllers/organization/organization_remove_client.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/controllers/organization/org_create.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/controllers/organization/organizations_create.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/controllers/organization/organizations_delete.php', 'target' => 'both', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record_delete'],
        ['path' => 'src/controllers/organization/organizations_update.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/services/ClientProfileMutationService.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/services/OrganizationProfileMutationService.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/services/PaymentProcessorImportService.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record'],
        ['path' => 'src/services/ClientArchivePortalStateService.php', 'target' => 'client', 'governance' => 'caller', 'evidence' => 'consumeAndRestore'],
        ['path' => 'src/controllers/organization/organization-update-notes.php', 'target' => 'organization', 'governance' => 'non_projection', 'evidence' => 'UPDATE organizations SET notes'],
        ['path' => 'src/controllers/organization/organizations_upload.php', 'target' => 'organization', 'governance' => 'non_projection', 'evidence' => 'UPDATE organizations SET tax_exempt_file'],
        ['path' => 'src/controllers/organization/organization_document_upload.php', 'target' => 'organization', 'governance' => 'non_projection', 'evidence' => 'UPDATE organizations SET {$dbFileColumn}'],
        ['path' => 'src/controllers/organization/organization_departments.php', 'target' => 'organization', 'governance' => 'non_projection', 'evidence' => 'UPDATE organizations SET link_strategy'],
        ['path' => 'src/services/StripeService.php', 'target' => 'client', 'governance' => 'non_projection', 'evidence' => 'UPDATE clients SET stripe_customer_id'],
    ];
}
