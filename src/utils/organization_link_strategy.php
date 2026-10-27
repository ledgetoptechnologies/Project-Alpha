<?php

declare(strict_types=1);

/** Read the global switch instead of relying on a possibly stale page config. */
function pa_link_resolver_enabled(PDO $pdo, bool $lock = false): bool
{
    $locking = $lock && $pdo->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' FOR SHARE' : '';
    $stmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = ? LIMIT 1' . $locking);
    $stmt->execute(['link_resolver_enabled']);
    return (string)$stmt->fetchColumn() === '1';
}

/** Strategy changes share the department handler's ownership-guarded transaction. */
function pa_organization_link_strategy_save(PDO $pdo, int $organizationId, string $strategy, string $sourceVersion): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Organization link strategy changes require a transaction.');
    }
    if (!pa_link_resolver_enabled($pdo, true)) {
        throw new DomainException('The link resolver is disabled. Enable it in Links & Storage before changing automatic link settings.');
    }
    if (!in_array($strategy, ['department_links_only', 'overall_folder', 'shared_folder'], true)) {
        throw new InvalidArgumentException('Invalid organization link strategy.');
    }
    $pdo->prepare('UPDATE organizations SET link_strategy = ?, source_version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$strategy, $sourceVersion, $organizationId]);
}

/** A disabled resolver must preserve an organization's saved strategy and links. */
function pa_organization_first_department_link_strategy(PDO $pdo, int $organizationId, string $sourceVersion): bool
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Organization link strategy changes require a transaction.');
    }
    if (!pa_link_resolver_enabled($pdo, true)) {
        return false;
    }
    pa_organization_link_strategy_save($pdo, $organizationId, 'department_links_only', $sourceVersion);
    return true;
}
