<?php

declare(strict_types=1);

function totp_enrollment_can_start(?array $factor): bool
{
    return $factor === null || empty($factor['enabled']);
}

function totp_enrollment_is_pending(mixed $pending, int $userId, ?array $factor, int $now): bool
{
    return is_array($pending)
        && (int)($pending['user_id'] ?? 0) === $userId
        && (int)($pending['expires_at'] ?? 0) >= $now
        && $factor !== null
        && totp_enrollment_can_start($factor)
        && !empty($factor['secret']);
}
