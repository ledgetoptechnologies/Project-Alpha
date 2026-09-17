<?php
// src/utils/project_billing.php

/**
 * Resolve the Project billing policy once at the invoice creation boundary.
 *
 * Creation paths should request a locked context from inside their existing
 * transaction and persist collection_mode from this result on the invoice.
 * That makes the invoice independent of later Project setting changes.
 *
 * @return array{project_id:?int,billing_period:string,collection_mode:string,net_terms_days:int,due_date:string}
 */
function project_invoice_billing_context(
    PDO $pdo,
    ?int $projectId,
    array $appConfig,
    ?string $baseDate = null,
    bool $lock = false
): array {
    $projectId = $projectId !== null && $projectId > 0 ? $projectId : null;
    $billingPeriod = 'per_invoice';
    $netTermsDays = max(0, (int)($appConfig['net_terms_days'] ?? 30));

    if ($projectId !== null) {
        if ($lock && !$pdo->inTransaction()) {
            throw new LogicException('A locked Project billing context requires an active transaction.');
        }
        $lockSuffix = $lock && (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            ? ' FOR UPDATE'
            : '';
        $stmt = $pdo->prepare(
            'SELECT invoice_billing_period, invoice_net_terms_days FROM projects WHERE id = ?' . $lockSuffix
        );
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Project billing settings are unavailable.');
        }
        $billingPeriod = (string)($row['invoice_billing_period'] ?? 'per_invoice') === 'monthly'
            ? 'monthly'
            : 'per_invoice';
        if ($row['invoice_net_terms_days'] !== null && $row['invoice_net_terms_days'] !== '') {
            $netTermsDays = max(0, (int)$row['invoice_net_terms_days']);
        }
    }

    $base = trim((string)$baseDate) !== '' ? (string)$baseDate : date('Y-m-d');
    $dueBase = $billingPeriod === 'monthly' ? date('Y-m-t', strtotime($base)) : $base;

    return [
        'project_id' => $projectId,
        'billing_period' => $billingPeriod,
        'collection_mode' => $billingPeriod === 'monthly' ? 'project_aggregate' : 'direct',
        'net_terms_days' => $netTermsDays,
        'due_date' => date('Y-m-d', strtotime($dueBase . ' +' . $netTermsDays . ' days')),
    ];
}

function project_invoice_terms_days(PDO $pdo, ?int $projectId, array $appConfig): int
{
    try {
        return project_invoice_billing_context($pdo, $projectId, $appConfig)['net_terms_days'];
    } catch (Throwable $e) {
        error_log('[project_billing] net terms lookup failed: ' . $e->getMessage());
    }
    return max(0, (int)($appConfig['net_terms_days'] ?? 30));
}

function project_invoice_due_date(PDO $pdo, ?int $projectId, array $appConfig, ?string $baseDate = null): string
{
    try {
        return project_invoice_billing_context($pdo, $projectId, $appConfig, $baseDate)['due_date'];
    } catch (Throwable $e) {
        error_log('[project_billing] due date lookup failed: ' . $e->getMessage());
    }
    $base = $baseDate ?: date('Y-m-d');
    return date('Y-m-d', strtotime($base . ' +' . max(0, (int)($appConfig['net_terms_days'] ?? 30)) . ' days'));
}

function project_uses_monthly_invoice_billing(PDO $pdo, ?int $projectId): bool
{
    if (!$projectId) {
        return false;
    }

    try {
        return project_invoice_billing_context($pdo, $projectId, [])['billing_period'] === 'monthly';
    } catch (Throwable $e) {
        error_log('[project_billing] monthly billing lookup failed: ' . $e->getMessage());
        return false;
    }
}
