<?php
declare(strict_types=1);

require_once __DIR__ . '/acl.php';

/** @return array{0:string,1:list<mixed>} */
function pa_expense_schedule_scope(PDO $pdo, int $creatorId, int $organizationId): array
{
    if ($creatorId <= 0) {
        throw new DomainException('Expense schedule has no authorized creator.');
    }
    $stmt = $pdo->prepare('SELECT is_disabled, deleted_at FROM users WHERE id=?');
    $stmt->execute([$creatorId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !empty($user['is_disabled']) || !empty($user['deleted_at'])) {
        throw new DomainException('Expense schedule creator is inactive.');
    }
    if (!user_can($pdo, $creatorId, 'financial.manage', 0)) {
        throw new DomainException('Expense schedule creator no longer has permission.');
    }
    return finance_scope_clause($pdo, 'e', $creatorId, $organizationId, 'created_by');
}

/** @param list<string> $emails */
function pa_expense_schedule_recipients_allowed(PDO $pdo, int $creatorId, array $emails): bool
{
    if ($creatorId <= 0 || $emails === []) {
        return false;
    }
    if (in_array(acl_user_role($pdo, $creatorId), ['admin', 'owner', 'staff'], true)) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id=? AND is_disabled=0 AND deleted_at IS NULL');
    $stmt->execute([$creatorId]);
    $ownEmail = strtolower(trim((string)($stmt->fetchColumn() ?: '')));
    return $ownEmail !== '' && count($emails) === 1 && strtolower(trim($emails[0])) === $ownEmail;
}
