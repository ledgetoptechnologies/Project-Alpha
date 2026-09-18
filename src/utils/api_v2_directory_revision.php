<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_authorization_generation.php';

/** The one canonical profile projection used by writers and live readers. */
function api_v2_directory_projection_hash(string $type, array $row): string
{
    if (!in_array($type, ['client', 'organization'], true)) throw new LogicException('Unsupported directory resource');
    $fields = $type === 'client'
        ? ['name', 'email', 'phone', 'client_type', 'organization_id', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']
        : ['name', 'general_email', 'general_phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country'];
    $profile = [];
    foreach ($fields as $field) $profile[$field] = $row[$field] ?? null;
    return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Record a committed-profile candidate inside the caller's transaction. */
function api_v2_directory_record(PDO $pdo, string $type, int $localId): bool
{
    if (!$pdo->inTransaction() || !in_array($type, ['client', 'organization'], true) || $localId < 1) {
        throw new LogicException('Directory revision requires an active transaction and supported resource');
    }
    $table = $type === 'client' ? 'clients' : 'organizations';
    $query = 'SELECT * FROM ' . $table . ' WHERE id=?';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $query .= ' FOR UPDATE';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$localId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || preg_match('/^[0-9a-f]{32}$/D', (string)($row['public_id'] ?? '')) !== 1) {
        throw new RuntimeException('Directory resource identity unavailable');
    }
    $hash = api_v2_directory_projection_hash($type, $row);
    $state = $pdo->prepare('SELECT revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $state->execute([$type, $row['public_id']]);
    $current = $state->fetch(PDO::FETCH_ASSOC);
    if ($current && (int)$current['present'] === 1 && hash_equals((string)$current['projection_sha256'], $hash)) return false;
    // A restore must not revive authority left behind by an older writer or
    // partial pre-0095 deployment. Treat the first post-tombstone upsert as a
    // lifecycle boundary too; the explicit binding command remains the only
    // way to establish new external authority.
    if ($current && (int)$current['present'] === 0) {
        api_v2_directory_tombstone_bindings($pdo, $type, (string)$row['public_id']);
    }
    $revision = $current ? (int)$current['revision'] + 1 : 1;
    if ($revision < 1 || $revision > PHP_INT_MAX) throw new OverflowException('Directory revision exhausted');
    if ($current) {
        $pdo->prepare('UPDATE api_v2_directory_resource_state SET revision=?,projection_sha256=?,present=1 WHERE resource_type=? AND public_id=?')
            ->execute([$revision, $hash, $type, $row['public_id']]);
    } else {
        $pdo->prepare('INSERT INTO api_v2_directory_resource_state(resource_type,public_id,revision,projection_sha256,present) VALUES (?,?,?, ?,1)')
            ->execute([$type, $row['public_id'], $revision, $hash]);
    }
    $pdo->prepare("INSERT INTO api_v2_directory_resource_changes(resource_type,public_id,revision,action) VALUES (?,?,?,'upsert')")
        ->execute([$type, $row['public_id'], $revision]);
    return true;
}

/** Record a committed deletion tombstone inside the caller's transaction. */
function api_v2_directory_record_delete(PDO $pdo, string $type, string $publicId): bool
{
    if (!$pdo->inTransaction() || !in_array($type, ['client', 'organization'], true)
        || preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1) {
        throw new LogicException('Directory deletion revision requires an active transaction and stable supported identity');
    }
    $state = $pdo->prepare('SELECT revision,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?'
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $state->execute([$type, $publicId]);
    $current = $state->fetch(PDO::FETCH_ASSOC);
    // A prior interrupted/legacy delete can leave active external authority
    // beside an already-published tombstone.  Repair it even though no second
    // resource revision should be emitted.
    if ($current && (int)$current['present'] === 0) {
        api_v2_directory_tombstone_bindings($pdo, $type, $publicId);
        return false;
    }

    // Check all downstream authorization watermarks while only locks have
    // been taken. A generation ceiling must not publish a resource tombstone
    // (or its change event) that cannot also revoke its external authority.
    api_v2_directory_preflight_tombstone_bindings($pdo, $type, $publicId);

    $revision = $current ? (int)$current['revision'] + 1 : 1;
    if ($revision < 1 || $revision > PHP_INT_MAX) throw new OverflowException('Directory revision exhausted');
    // A tombstone has no profile projection; a stable empty projection hash keeps
    // the state row valid without pretending that a removed row is still present.
    $hash = hash('sha256', '');
    if ($current) {
        $pdo->prepare('UPDATE api_v2_directory_resource_state SET revision=?,projection_sha256=?,present=0 WHERE resource_type=? AND public_id=?')
            ->execute([$revision, $hash, $type, $publicId]);
    } else {
        $pdo->prepare('INSERT INTO api_v2_directory_resource_state(resource_type,public_id,revision,projection_sha256,present) VALUES (?,?,?, ?,0)')
            ->execute([$type, $publicId, $revision, $hash]);
    }
    $pdo->prepare("INSERT INTO api_v2_directory_resource_changes(resource_type,public_id,revision,action) VALUES (?,?,?,'delete')")
        ->execute([$type, $publicId, $revision]);

    // A resource tombstone is also an authorization boundary.  Existing
    // external identities must be tombstoned in this same transaction so a
    // concurrent status read cannot observe a deleted resource as active, and
    // every affected application must move its authorization watermark.  The
    // binding table was introduced after the revision foundation; tolerate an
    // older installation that has not applied that optional migration yet.
    api_v2_directory_tombstone_bindings($pdo, $type, $publicId);
    return true;
}

/** Tombstone all active authority for a resource and advance each app once. */
function api_v2_directory_tombstone_bindings(PDO $pdo, string $type, string $publicId): void
{
    if (!api_v2_directory_lifecycle_binding_table_exists($pdo)) return;
    $applicationPks = api_v2_directory_preflight_tombstone_bindings($pdo, $type, $publicId);
    if ($applicationPks === []) return;
    $tombstone = $pdo->prepare("UPDATE api_v2_directory_external_bindings
        SET status='tombstoned', tombstoned_at=CURRENT_TIMESTAMP
        WHERE resource_type=? AND public_id=? AND status='active'");
    $tombstone->execute([$type, $publicId]);
    if ($tombstone->rowCount() !== count($applicationPks)) {
        throw new RuntimeException('The API v2 external binding tombstone was incomplete.');
    }
    foreach ($applicationPks as $applicationPk) {
        api_v2_advance_authorization_generation($pdo, (int)$applicationPk);
    }
}

/** @return list<int> Locked active-binding applications whose watermarks can advance. */
function api_v2_directory_preflight_tombstone_bindings(PDO $pdo, string $type, string $publicId): array
{
    if (!api_v2_directory_lifecycle_binding_table_exists($pdo)) return [];
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $bindings = $pdo->prepare('SELECT application_pk FROM api_v2_directory_external_bindings
        WHERE resource_type=? AND public_id=? AND status=\'active\' ORDER BY application_pk' . $lock);
    $bindings->execute([$type, $publicId]);
    $applications = [];
    $bindingCount = 0;
    foreach ($bindings->fetchAll(PDO::FETCH_COLUMN) as $applicationPk) {
        $bindingCount++;
        $applications[(int)$applicationPk] = true;
    }
    if ($applications === []) return [];
    $applicationPks = array_keys($applications);
    sort($applicationPks, SORT_NUMERIC);
    // Preserve the writer lock order: source -> revision state -> binding ->
    // authorization state.  Validate every affected watermark before the
    // binding UPDATE so a missing/exhausted state fails closed without any
    // lifecycle mutation.
    foreach ($applicationPks as $applicationPk) {
        api_v2_authorization_generation_require_advanceable($pdo, (int)$applicationPk);
    }
    if ($bindingCount !== count($applicationPks)) {
        throw new RuntimeException('Duplicate API v2 external bindings are not permitted.');
    }
    return $applicationPks;
}

/** The binding table is optional until migration 0090 has been applied. */
function api_v2_directory_lifecycle_binding_table_exists(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $statement->execute(['api_v2_directory_external_bindings']);
        return $statement->fetchColumn() !== false;
    }
    $statement = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $statement->execute(['api_v2_directory_external_bindings']);
    return $statement->fetchColumn() !== false;
}
