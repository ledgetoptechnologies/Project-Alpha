<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_backfill.php';

/**
 * Read-only, deterministic evidence that a local directory backfill covers the
 * current source rows. This is intentionally an attestation, not an activator:
 * callers must still keep the API capability disabled until their release
 * process accepts a complete result.
 *
 * @return array{attestationVersion:int,schemaReady:bool,complete:bool,resources:array<string,array<string,int|string>>,violations:array<string,int>}
 */
function api_v2_directory_backfill_attestation(PDO $pdo): array
{
    // Activation invokes this while holding the exclusive sentinel gate. In
    // that context every canonical source writer is blocked, so the current
    // transaction provides the authoritative cutover snapshot.
    $resources = [
        'client' => ['source' => 0, 'covered' => 0, 'invalid' => 0, 'missing' => 0, 'drifted' => 0, 'history' => 0, 'orphaned' => 0, 'coverageDigest' => hash('sha256', '[]')],
        'organization' => ['source' => 0, 'covered' => 0, 'invalid' => 0, 'missing' => 0, 'drifted' => 0, 'history' => 0, 'orphaned' => 0, 'coverageDigest' => hash('sha256', '[]')],
        'unit' => ['source' => 0, 'covered' => 0, 'invalid' => 0, 'missing' => 0, 'drifted' => 0, 'history' => 0, 'orphaned' => 0, 'coverageDigest' => hash('sha256', '[]')],
    ];
    $violations = ['schema' => 0, 'identity' => 0, 'missing_state' => 0, 'projection_drift' => 0, 'history_gap' => 0, 'orphaned_state' => 0];
    if (!api_v2_directory_backfill_schema_ready($pdo)) {
        $violations['schema'] = 1;
        return ['attestationVersion' => 2, 'schemaReady' => false, 'complete' => false, 'resources' => $resources, 'violations' => $violations];
    }

    $sourceTables=['organization'=>'organizations','client'=>'clients'];
    if(api_v2_directory_unit_schema_ready($pdo))$sourceTables['unit']='organization_departments';
    foreach ($sourceTables as $type => $table) {
        $source = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $live = []; $seen = [];
        $coverage = [];
        foreach ($source as $row) {
            $resources[$type]['source']++;
            $publicId = (string)($row['public_id'] ?? '');
            if (preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1 || isset($seen[$publicId])) {
                $resources[$type]['invalid']++; $violations['identity']++; continue;
            }
            $seen[$publicId] = true;
            $archived = array_key_exists('archived',$row) && (int)$row['archived'] === 1
                && array_key_exists('deleted_at',$row) && $row['deleted_at'] !== null;
            if (!$archived) $live[$publicId] = true;
            $state = $pdo->prepare('SELECT revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?');
            $state->execute([$type, $publicId]); $state = $state->fetch(PDO::FETCH_ASSOC);
            $expectedPresent = $archived ? '0' : '1';
            if (!is_array($state) || (string)($state['present'] ?? '') !== $expectedPresent) {
                $resources[$type]['missing']++; $violations['missing_state']++; continue;
            }
            $revision = (string)($state['revision'] ?? '');
            $hash = (string)($state['projection_sha256'] ?? '');
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $revision) !== 1 || (strlen($revision) === 19 && strcmp($revision, '9223372036854775807') > 0)
                || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1 || !hash_equals($hash, $archived ? hash('sha256','') : api_v2_directory_canonical_hash($pdo, $type, $row))) {
                $resources[$type]['drifted']++; $violations['projection_drift']++; continue;
            }
            $change = $pdo->prepare("SELECT action FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision=?");
            $change->execute([$type, $publicId, $revision]);
            $future = $pdo->prepare('SELECT 1 FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision>? LIMIT 1');
            $future->execute([$type, $publicId, $revision]);
            $historyCount = $pdo->prepare('SELECT COUNT(*) FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision<=?');
            $historyCount->execute([$type, $publicId, $revision]);
            if ($change->fetchColumn() !== ($archived ? 'delete' : 'upsert') || $future->fetchColumn() !== false || (int)$historyCount->fetchColumn() !== (int)$revision) {
                $resources[$type]['history']++; $violations['history_gap']++; continue;
            }
            $resources[$type]['covered']++;
            $coverage[] = ['publicId' => $publicId, 'revision' => (int)$revision, 'present' => !$archived, 'projectionSha256' => $hash];
        }
        $states = $pdo->prepare('SELECT public_id FROM api_v2_directory_resource_state WHERE resource_type=? AND present=1');
        $states->execute([$type]);
        foreach ($states->fetchAll(PDO::FETCH_COLUMN) as $publicId) {
            if (!isset($live[(string)$publicId])) { $resources[$type]['orphaned']++; $violations['orphaned_state']++; }
        }
        usort($coverage, static fn(array $left, array $right): int => strcmp($left['publicId'], $right['publicId']));
        $resources[$type]['coverageDigest'] = hash('sha256', json_encode($coverage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $complete = array_sum($violations) === 0;
    return ['attestationVersion' => 2, 'schemaReady' => true, 'complete' => $complete, 'resources' => $resources, 'violations' => $violations];
}

/** @param array{attestationVersion:int,schemaReady:bool,complete:bool,resources:array<string,array<string,int|string>>,violations:array<string,int>} $attestation */
function api_v2_directory_backfill_attestation_json(array $attestation): string
{
    return json_encode($attestation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function api_v2_directory_backfill_attestation_receipt_schema_ready(PDO $pdo, int $throughVersion = 96): bool
{
    if ($throughVersion < 89 || $throughVersion > 96) throw new InvalidArgumentException('Unsupported directory release-safety migration version.');
    if ($throughVersion < 96) return true;
    $migration = $pdo->prepare('SELECT filename FROM schema_migrations WHERE version=96');
    $migration->execute();
    if ($migration->fetchColumn() !== '0096_api_v2_directory_backfill_attestations.sql') return false;
    return api_v2_directory_backfill_table_exists($pdo, 'api_v2_directory_backfill_attestations')
        && api_v2_directory_backfill_column_exists($pdo, 'api_v2_directory_backfill_attestations', 'attestation_sha256')
        && api_v2_directory_backfill_column_exists($pdo, 'api_v2_directory_backfill_attestations', 'attestation_json')
        && api_v2_directory_backfill_column_exists($pdo, 'api_v2_directory_backfill_attestations', 'created_at');
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
    if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1 || !api_v2_directory_backfill_attestation_receipt_schema_ready($pdo)) return false;
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
 * @return list<array{path:string,target:string,governance:string,evidence:string,mutationCount:int}>
 */
function api_v2_directory_writer_inventory(): array
{
    return [
        ['path' => 'src/controllers/client/client_onboarding_review.php', 'target' => 'both', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 6],
        ['path' => 'src/controllers/client/clients_create.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 1],
        ['path' => 'src/controllers/client/clients_delete.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record_delete', 'mutationCount' => 1],
        ['path' => 'src/controllers/client/clients_purge.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record_delete', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organization_add_client.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organization_remove_client.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/org_create.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organizations_create.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organizations_delete.php', 'target' => 'both', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record_delete', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organizations_update.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 2],
        ['path' => 'src/services/ClientProfileMutationService.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 1],
        ['path' => 'src/services/OrganizationProfileMutationService.php', 'target' => 'organization', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 1],
        ['path' => 'src/services/PaymentProcessorImportService.php', 'target' => 'client', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 2],
        ['path' => 'src/services/ClientArchivePortalStateService.php', 'target' => 'client', 'governance' => 'caller', 'evidence' => 'consumeAndRestore', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organization-update-notes.php', 'target' => 'organization', 'governance' => 'non_projection', 'evidence' => 'UPDATE organizations SET notes', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organizations_upload.php', 'target' => 'organization', 'governance' => 'non_projection', 'evidence' => 'UPDATE organizations SET tax_exempt_file', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organization_document_upload.php', 'target' => 'organization', 'governance' => 'non_projection', 'evidence' => 'UPDATE organizations SET {$dbFileColumn}', 'mutationCount' => 1],
        ['path' => 'src/controllers/organization/organization_departments.php', 'target' => 'unit', 'governance' => 'revision', 'evidence' => 'api_v2_directory_record', 'mutationCount' => 2],
        ['path' => 'src/services/StripeService.php', 'target' => 'client', 'governance' => 'non_projection', 'evidence' => 'UPDATE clients SET stripe_customer_id', 'mutationCount' => 1],
    ];
}
