<?php
declare(strict_types=1);

require_once __DIR__ . '/api_scopes.php';

const PA_API_V2_AUTHORIZATION_GENERATION_MAX = '9223372036854775807';

function api_v2_authorization_generation_column_exists(PDO $pdo, string $table, string $column): bool
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

function api_v2_authorization_generation_table_exists(PDO $pdo, string $table): bool
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

/** True only when a scope or IP restriction changes; a display-name change is deliberately excluded. */
function api_v2_key_authorization_changed($storedScopes, $storedAllowedIps, string $newScopes, ?string $newAllowedIps): bool
{
    $canonicalScopes = api_normalize_scopes($storedScopes);
    $requestedScopes = api_normalize_scopes($newScopes);
    sort($canonicalScopes, SORT_STRING);
    sort($requestedScopes, SORT_STRING);
    $canonicalAllowedIps = trim((string)$storedAllowedIps) ?: null;
    return $canonicalScopes !== $requestedScopes
        || $canonicalAllowedIps !== $newAllowedIps;
}

/**
 * Advance one application's authorization watermark while the caller holds the
 * corresponding api_keys row lock. A missing state row is an incomplete v2
 * binding and fails closed so an earlier watermark can never be silently reset.
 */
function api_v2_advance_authorization_generation(PDO $pdo, int $applicationPk): void
{
    if (!$pdo->inTransaction() || $applicationPk < 1) {
        throw new InvalidArgumentException('Authorization generation advancement requires an active application transaction.');
    }
    if (!api_v2_authorization_generation_table_exists($pdo, 'api_v2_directory_authorization_state')
        || !api_v2_authorization_generation_column_exists($pdo, 'api_v2_directory_authorization_state', 'authorization_generation')) {
        throw new RuntimeException('The API v2 authorization state is unavailable.');
    }

    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';

    $state = $pdo->prepare('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=?' . $lock);
    $state->execute([$applicationPk]);
    $generation = $state->fetchColumn();
    $value = is_scalar($generation) ? (string)$generation : '';
    if ($generation === false || preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) !== 1
        || (strlen($value) === 19 && strcmp($value, PA_API_V2_AUTHORIZATION_GENERATION_MAX) > 0)) {
        throw new RuntimeException('The API v2 authorization generation is invalid.');
    }
    if ($value === PA_API_V2_AUTHORIZATION_GENERATION_MAX) {
        throw new RuntimeException('The API v2 authorization generation cannot be advanced.');
    }
    $advance = $pdo->prepare('UPDATE api_v2_directory_authorization_state SET authorization_generation=authorization_generation+1 WHERE application_pk=?');
    $advance->execute([$applicationPk]);
    if ($advance->rowCount() !== 1) {
        throw new RuntimeException('The API v2 authorization generation was not advanced.');
    }
}
