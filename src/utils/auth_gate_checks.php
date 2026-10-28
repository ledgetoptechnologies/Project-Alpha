<?php

declare(strict_types=1);

/** A missing 2FA enrollment row means disabled; a failed lookup must abort login. */
function pa_auth_two_factor_enabled(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) throw new InvalidArgumentException('Invalid user');
    $statement = $pdo->prepare('SELECT enabled FROM user_2fa WHERE user_id = ? AND enabled = 1');
    $statement->execute([$userId]);
    return (bool)$statement->fetchColumn();
}

/** Recovery flags must be readable before a protected route is dispatched. */
function pa_auth_recovery_flag(PDO $pdo, int $userId, string $column): bool
{
    if ($userId <= 0 || !in_array($column, ['force_password_reset', 'totp_reenroll_required'], true)) {
        throw new InvalidArgumentException('Invalid recovery flag');
    }
    $statement = $pdo->prepare("SELECT {$column} FROM users WHERE id = ?");
    $statement->execute([$userId]);
    $value = $statement->fetchColumn();
    if ($value === false) throw new RuntimeException('Recovery state unavailable');
    return (int)$value === 1;
}
