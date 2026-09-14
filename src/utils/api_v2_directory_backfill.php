<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_revision.php';

/** Local-only operator backfill for the default-inert API v2 directory state. */
function api_v2_directory_backfill_table_exists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $statement->execute([$table]);
    return $statement->fetchColumn() !== false;
}

function api_v2_directory_backfill_column_exists(PDO $pdo, string $table, string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $quoted = str_replace("'", "''", $table);
        foreach ($pdo->query("PRAGMA table_info('{$quoted}')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) return true;
        }
        return false;
    }
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $statement->execute([$table, $column]);
    return $statement->fetchColumn() !== false;
}

/** Refuse a partially migrated database; this tool is not a migration repairer. */
function api_v2_directory_backfill_schema_ready(PDO $pdo): bool
{
    $columns = [
        'schema_migrations' => ['version', 'filename'],
        'clients' => ['id', 'public_id', 'name', 'email', 'phone', 'client_type', 'organization_id', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country'],
        'organizations' => ['id', 'public_id', 'name', 'general_email', 'general_phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country'],
        'api_v2_directory_resource_state' => ['resource_type', 'public_id', 'revision', 'projection_sha256', 'present'],
        'api_v2_directory_resource_changes' => ['resource_type', 'public_id', 'revision', 'action'],
    ];
    foreach ($columns as $table => $names) {
        if (!api_v2_directory_backfill_table_exists($pdo, $table)) return false;
        foreach ($names as $column) if (!api_v2_directory_backfill_column_exists($pdo, $table, $column)) return false;
    }
    $statement = $pdo->prepare('SELECT version,filename FROM schema_migrations WHERE version IN (88,89)');
    $statement->execute();
    $applied = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $applied[(int)$row['version']] = (string)$row['filename'];
    return ($applied[88] ?? null) === '0088_api_v2_application_identity.sql'
        && ($applied[89] ?? null) === '0089_api_v2_directory_revision_foundation.sql';
}

/** @return array{type:string,id:int}|null */
function api_v2_directory_backfill_parse_cursor(?string $cursor): ?array
{
    if ($cursor === null || $cursor === '') return null;
    if (preg_match('/^(client|organization):(0|[1-9][0-9]{0,18})$/D', $cursor, $matches) !== 1) {
        throw new InvalidArgumentException('Invalid resume cursor.');
    }
    if (strlen($matches[2]) === 19 && strcmp($matches[2], '9223372036854775807') > 0) throw new InvalidArgumentException('Invalid resume cursor.');
    return ['type' => $matches[1], 'id' => (int)$matches[2]];
}

function api_v2_directory_backfill_local_id(mixed $value): int
{
    $id = (string)$value; $maximum = (string)PHP_INT_MAX;
    if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1 || strlen($id) > strlen($maximum)
        || (strlen($id) === strlen($maximum) && strcmp($id, $maximum) > 0)) {
        throw new RuntimeException('Directory backfill refused: source identifier is malformed.');
    }
    return (int)$id;
}

/** @return array<string,mixed> */
function api_v2_directory_backfill_source(PDO $pdo, string $type, int $id, bool $lock): array
{
    $table = $type === 'client' ? 'clients' : 'organizations';
    $sql = 'SELECT * FROM ' . $table . ' WHERE id=?';
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql);
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int)($row['id'] ?? 0) !== $id
        || preg_match('/^[0-9a-f]{32}$/D', (string)($row['public_id'] ?? '')) !== 1) {
        throw new RuntimeException('Directory backfill refused: a source resource identity is malformed or changed.');
    }
    return $row;
}

/** Return a safe action without ever interpreting divergent state as repairable. */
function api_v2_directory_backfill_action(PDO $pdo, string $type, array $row, bool $lock): string
{
    $sql = 'SELECT revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?';
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql);
    $statement->execute([$type, (string)$row['public_id']]);
    $state = $statement->fetch(PDO::FETCH_ASSOC);
    if ($state === false) return 'insert';
    $revision = (string)($state['revision'] ?? '');
    $hash = (string)($state['projection_sha256'] ?? '');
    $present = (string)($state['present'] ?? '');
    if (preg_match('/^[1-9][0-9]{0,18}$/D', $revision) !== 1 || (strlen($revision) === 19 && strcmp($revision, '9223372036854775807') > 0)
        || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1 || !in_array($present, ['0', '1'], true)) {
        throw new RuntimeException('Directory backfill refused: existing directory state is malformed.');
    }
    if ($present === '0') throw new RuntimeException('Directory backfill refused: live source conflicts with a tombstone.');
    if (!hash_equals($hash, api_v2_directory_projection_hash($type, $row))) {
        throw new RuntimeException('Directory backfill refused: live source conflicts with existing directory state.');
    }
    $change = $pdo->prepare("SELECT action FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision=?");
    $change->execute([$type, (string)$row['public_id'], $revision]);
    if ($change->fetchColumn() !== 'upsert') throw new RuntimeException('Directory backfill refused: current directory state has no matching change record.');
    $future = $pdo->prepare('SELECT 1 FROM api_v2_directory_resource_changes WHERE resource_type=? AND public_id=? AND revision>? LIMIT 1');
    $future->execute([$type, (string)$row['public_id'], $revision]);
    if ($future->fetchColumn() !== false) throw new RuntimeException('Directory backfill refused: change history exceeds current directory state.');
    return 'skip_current';
}

/**
 * Processes at most $limit local source rows. Apply is transactionally all-or-nothing
 * for that bounded batch. It makes no remote calls and returns only aggregate counts.
 *
 * @return array{dryRun:bool,scanned:int,inserted:int,skippedCurrent:int,nextCursor:?string}
 */
function api_v2_directory_backfill(PDO $pdo, string $type, ?string $cursor, int $limit, bool $dryRun): array
{
    if ($pdo->inTransaction() || !in_array($type, ['all', 'client', 'organization'], true) || $limit < 1 || $limit > 500) {
        throw new InvalidArgumentException('Invalid directory backfill request.');
    }
    if (!api_v2_directory_backfill_schema_ready($pdo)) throw new RuntimeException('Required API v2 migrations are missing or incomplete.');
    $parsed = api_v2_directory_backfill_parse_cursor($cursor);
    if ($parsed !== null && $type !== 'all' && $parsed['type'] !== $type) throw new InvalidArgumentException('Resume cursor does not match resource type.');
    $types = $type === 'all' ? ['client', 'organization'] : [$type];
    if ($parsed !== null && $type === 'all') $types = $parsed['type'] === 'client' ? ['client', 'organization'] : ['organization'];
    $rows = []; $nextCursor = null; $remaining = $limit;
    foreach ($types as $resourceType) {
        $after = ($parsed !== null && $parsed['type'] === $resourceType) ? $parsed['id'] : 0;
        $table = $resourceType === 'client' ? 'clients' : 'organizations';
        $statement = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id>? ORDER BY id ASC LIMIT ' . ($remaining + 1));
        $statement->execute([$after]);
        $found = $statement->fetchAll(PDO::FETCH_COLUMN);
        $hasMore = count($found) > $remaining;
        if ($hasMore) array_pop($found);
        foreach ($found as $localId) {
            $rows[] = ['type' => $resourceType, 'id' => api_v2_directory_backfill_local_id($localId)];
        }
        $remaining -= count($found);
        if ($hasMore) { $nextCursor = $resourceType . ':' . (int)end($found); break; }
        if ($remaining === 0) {
            // Preserve a resumable boundary even when the following type has rows.
            $following = array_slice($types, array_search($resourceType, $types, true) + 1);
            $nextCursor = $following ? $following[0] . ':0' : null;
            break;
        }
    }
    $counts = ['dryRun' => $dryRun, 'scanned' => count($rows), 'inserted' => 0, 'skippedCurrent' => 0, 'nextCursor' => $nextCursor];
    // Preflight every selected row before any write, so malformed local IDs fail closed.
    foreach ($rows as $candidate) {
        $row = api_v2_directory_backfill_source($pdo, $candidate['type'], $candidate['id'], false);
        $action = api_v2_directory_backfill_action($pdo, $candidate['type'], $row, false);
        if ($action === 'insert') $counts['inserted']++;
        elseif ($action === 'skip_current') $counts['skippedCurrent']++;
        else throw new LogicException('Unexpected directory backfill action.');
    }
    if ($dryRun || $rows === []) return $counts;
    $pdo->beginTransaction();
    try {
        // Re-check under locks: a concurrent writer wins; this tool never replaces it.
        $counts['inserted'] = $counts['skippedCurrent'] = 0;
        foreach ($rows as $candidate) {
            $row = api_v2_directory_backfill_source($pdo, $candidate['type'], $candidate['id'], true);
            $action = api_v2_directory_backfill_action($pdo, $candidate['type'], $row, true);
            if ($action === 'insert') { api_v2_directory_record($pdo, $candidate['type'], $candidate['id']); $counts['inserted']++; }
            elseif ($action === 'skip_current') $counts['skippedCurrent']++;
            else throw new LogicException('Unexpected directory backfill action.');
        }
        $pdo->commit();
        return $counts;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
