<?php

require_once __DIR__ . '/logger.php';

function password_reset_canonical_origin(array $appConfig): ?string
{
    $configured = trim((string)($appConfig['app_host'] ?? ''));
    if ($configured === '') {
        $configured = trim((string)(getenv('APP_HOST') ?: ''));
    }
    $parts = parse_url($configured);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array((string)($parts['path'] ?? ''), ['', '/'], true)) {
        return null;
    }
    $host = (string)$parts['host'];
    if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        && !filter_var($host, FILTER_VALIDATE_IP)) {
        return null;
    }
    return 'https://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
}

function password_reset_normalize_token(string $token): string
{
    $token = trim($token);
    $numeric = preg_replace('/\D+/', '', $token);
    if (is_string($numeric) && $numeric !== '') {
        return str_pad(substr($numeric, -6), 6, '0', STR_PAD_LEFT);
    }
    return $token;
}

function password_reset_token_matches(string $submitted, string $stored): bool
{
    if (strlen($stored) === 64 && ctype_xdigit($stored)) {
        return hash_equals(strtolower($stored), hash('sha256', $submitted));
    }
    if (hash_equals($submitted, $stored)) {
        return true;
    }
    $storedDigits = preg_replace('/\D+/', '', $stored);
    if (is_string($storedDigits) && $storedDigits !== '' && hash_equals($submitted, $storedDigits)) {
        return true;
    }
    $storedCompact = preg_replace('/\s+|-/', '', $stored);
    return is_string($storedCompact) && $storedCompact !== '' && hash_equals($submitted, $storedCompact);
}

function password_reset_revoke_for_user(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        $statement = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0');
        $statement->execute([$userId]);
    } catch (Throwable $error) {
        // Authentication must not fail merely because reset cleanup is unavailable.
    }
}

function password_reset_email_is_configured(array $appConfig): bool
{
    if (trim((string)($appConfig['smtp_host'] ?? '')) !== '') return true;
    $pdo=$GLOBALS['pdo']??null;
    if(!$pdo instanceof PDO)return false;
    try{return (bool)$pdo->query('SELECT c.id FROM email_provider_state s JOIN email_provider_connections c ON c.id=s.active_connection_id WHERE s.id=1 AND c.status IN ("configured","connected") LIMIT 1')->fetchColumn();}catch(Throwable $error){return false;}
}

function password_reset_mask_token(string $token): string
{
    if ($token === '') {
        return '';
    }
    if (strlen($token) <= 4) {
        return str_repeat('*', strlen($token));
    }
    return substr($token, 0, 2) . '****' . substr($token, -2);
}

function password_reset_has_attempts(PDO $pdo): bool
{
    try {
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'password_resets'
               AND COLUMN_NAME = 'attempts'"
        );
        $check->execute();
        return (int)$check->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function password_reset_increment_attempts(PDO $pdo, string $email): void
{
    if (!password_reset_has_attempts($pdo)) {
        return;
    }

    try {
        $increment = $pdo->prepare(
            'UPDATE password_resets
             SET attempts = attempts + 1
             WHERE user_id = (SELECT id FROM users WHERE email = ? LIMIT 1)
               AND used = 0
             ORDER BY id DESC
             LIMIT 1'
        );
        $increment->execute([$email]);

        $lock = $pdo->prepare(
            'UPDATE password_resets
             SET used = 1
             WHERE user_id = (SELECT id FROM users WHERE email = ? LIMIT 1)
               AND used = 0
               AND attempts >= 3'
        );
        $lock->execute([$email]);
    } catch (Throwable $e) {
        // Password reset failure handling must not leak backend details.
    }
}

/**
 * Verify a password reset token and consume it exactly once.
 *
 * @throws RuntimeException when the user/token is missing, expired, locked, used,
 *                          or does not match. Callers should present a generic
 *                          public error.
 */
function password_reset_verify_and_consume(PDO $pdo, string $email, string $token): int
{
    $email = trim($email);
    $token = password_reset_normalize_token($token);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
        throw new RuntimeException('invalid_input');
    }

    $user = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $user->execute([$email]);
    $userId = (int)($user->fetchColumn() ?: 0);
    if ($userId <= 0) {
        throw new RuntimeException('badtoken');
    }

    $row = null;
    $hasAttempts = password_reset_has_attempts($pdo);
    try {
        $lookupToken = hash('sha256', $token);
        $exact = $pdo->prepare(
            'SELECT id, token, expires_at, used, ' . ($hasAttempts ? 'attempts' : '0 AS attempts') . '
             FROM password_resets
             WHERE user_id = ? AND used = 0 AND token IN (?, ?)
             ORDER BY id DESC
             LIMIT 1'
        );
        $exact->execute([$userId, $lookupToken, $token]);
        $row = $exact->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row && ctype_digit($token)) {
            $compact = $pdo->prepare(
                'SELECT id, token, expires_at, used, ' . ($hasAttempts ? 'attempts' : '0 AS attempts') . "
                 FROM password_resets
                 WHERE user_id = ? AND used = 0
                   AND REPLACE(REPLACE(token, '-', ''), ' ', '') = ?
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $compact->execute([$userId, $token]);
            $row = $compact->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $row = null;
    }

    if (!$row) {
        $latest = $pdo->prepare(
            'SELECT id, token, expires_at, used, ' . ($hasAttempts ? 'attempts' : '0 AS attempts') . '
             FROM password_resets
             WHERE user_id = ? AND used = 0
             ORDER BY id DESC
             LIMIT 1'
        );
        $latest->execute([$userId]);
        $row = $latest->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$row) {
        throw new RuntimeException('badtoken');
    }
    if ((int)($row['used'] ?? 0) === 1) {
        throw new RuntimeException('used');
    }
    if (strtotime((string)($row['expires_at'] ?? '')) < time()) {
        throw new RuntimeException('expired');
    }
    if ($hasAttempts && (int)($row['attempts'] ?? 0) >= 3) {
        throw new RuntimeException('locked');
    }

    $stored = (string)($row['token'] ?? '');
    if (!password_reset_token_matches($token, $stored)) {
        app_log('auth', 'reset token mismatch', [
            'email' => $email,
            'submitted_mask' => password_reset_mask_token($token),
            'stored_mask' => password_reset_mask_token($stored),
        ]);
        password_reset_increment_attempts($pdo, $email);
        throw new RuntimeException('badtoken');
    }

    $consume = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0');
    $consume->execute([(int)$row['id']]);
    if ($consume->rowCount() !== 1) {
        throw new RuntimeException('used');
    }

    return $userId;
}
