<?php
declare(strict_types=1);

require_once __DIR__ . '/acl.php';

function pa_client_record_mutation_allowed(PDO $pdo, int $userId, $createdBy): bool
{
    if ($userId <= 0) {
        return false;
    }
    if (in_array(acl_user_role($pdo, $userId), ['admin', 'owner', 'staff'], true)) {
        return true;
    }
    return $createdBy !== null && (int)$createdBy === $userId;
}
