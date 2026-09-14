<?php
declare(strict_types=1);

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
    $fields = $type === 'client'
        ? ['name', 'email', 'phone', 'client_type', 'organization_id', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']
        : ['name', 'general_email', 'general_phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country'];
    $profile = [];
    foreach ($fields as $field) $profile[$field] = $row[$field] ?? null;
    $hash = hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $state = $pdo->prepare('SELECT revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $state->execute([$type, $row['public_id']]);
    $current = $state->fetch(PDO::FETCH_ASSOC);
    if ($current && (int)$current['present'] === 1 && hash_equals((string)$current['projection_sha256'], $hash)) return false;
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
