<?php
declare(strict_types=1);

require_once __DIR__ . '/acl.php';

function pa_onboarding_org_wide(PDO $pdo, int $userId): bool
{
    return $userId > 0 && in_array(acl_user_role($pdo, $userId), ['admin', 'owner', 'staff'], true);
}

/** @return array{0:string,1:list<int>} */
function pa_onboarding_invitation_scope(PDO $pdo, int $userId, int $organizationId, string $alias = 'i'): array
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid invitation alias.');
    }
    if ($userId <= 0) {
        return ['1=0', []];
    }
    if (!pa_onboarding_org_wide($pdo, $userId) || $organizationId <= 0) {
        return ["{$alias}.created_by=?", [$userId]];
    }
    return ["({$alias}.organization_id=? OR ({$alias}.organization_id IS NULL AND {$alias}.created_by=?))", [$organizationId, $userId]];
}
