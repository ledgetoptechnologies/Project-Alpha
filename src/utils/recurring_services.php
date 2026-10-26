<?php
declare(strict_types=1);
require_once __DIR__ . '/recurring_schedule_changes.php';

function pa_recurring_service_snapshot(array $service): array
{
    return [
        'name' => (string)($service['name'] ?? ''),
        'description' => $service['description'] ?? null,
        'amount' => (string)($service['amount'] ?? '0.00'),
        'rate_basis' => (string)($service['rate_basis'] ?? 'per_period'),
        'monthly_rate' => $service['monthly_rate'] ?? null,
        'billing_anchor_date' => $service['billing_anchor_date'] ?? null,
        'billing_interval_count' => (int)($service['billing_interval_count'] ?? 1),
        'billing_interval_unit' => (string)($service['billing_interval_unit'] ?? 'month'),
        'effective_from' => $service['effective_from'] ?? null,
        'effective_until' => $service['effective_until'] ?? null,
        'next_invoice_date' => $service['next_invoice_date'] ?? null,
        'status' => (string)($service['status'] ?? 'pending'),
        'approval_status' => (string)($service['approval_status'] ?? 'pending'),
    ];
}

function pa_recurring_service_record_amendment(
    PDO $pdo,
    int $contractId,
    ?int $serviceId,
    string $type,
    string $approvalStatus,
    string $effectiveDate,
    string $summary,
    ?array $oldValues,
    ?array $newValues,
    ?string $signedDocumentPath,
    ?int $createdBy
): int {
    $allowedTypes = ['service_added', 'service_updated', 'service_approved', 'service_paused', 'service_resumed', 'service_ended', 'proration'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid amendment type.');
    }
    if (!in_array($approvalStatus, ['pending', 'approved', 'rejected'], true)) {
        $approvalStatus = 'pending';
    }

    $stmt = $pdo->prepare('
        INSERT INTO contract_amendments
            (contract_id, recurring_service_id, amendment_type, approval_status, effective_date,
             summary, old_values, new_values, signed_document_path, approved_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = "approved", NOW(), NULL), ?)
    ');
    $stmt->execute([
        $contractId,
        $serviceId,
        $type,
        $approvalStatus,
        $effectiveDate,
        substr($summary, 0, 500),
        $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_SLASHES),
        $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_SLASHES),
        $signedDocumentPath,
        $approvalStatus,
        $createdBy,
    ]);
    return (int)$pdo->lastInsertId();
}

function pa_recurring_service_contract_status(array $contract): string
{
    return match (strtolower((string)($contract['status'] ?? 'pending'))) {
        'active' => 'active',
        'paused' => 'paused',
        'completed', 'cancelled', 'denied', 'void' => 'ended',
        default => 'pending',
    };
}

function pa_recurring_service_ensure_base(PDO $pdo, int $contractId): ?int
{
    $existing = $pdo->prepare('SELECT id FROM contract_recurring_services WHERE contract_id=? AND is_base=1 ORDER BY id LIMIT 1');
    $existing->execute([$contractId]);
    $existingId = (int)($existing->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term" AND pricing_type="per_invoice" LIMIT 1');
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return null;
    }

    $name = trim((string)($contract['scope'] ?? '')) ?: 'Recurring service';
    $effectiveFrom = (string)($contract['start_date'] ?: date('Y-m-d', strtotime((string)($contract['created_at'] ?? 'now'))));
    $insert = $pdo->prepare('
        INSERT INTO contract_recurring_services
            (contract_id,name,description,amount,billing_interval_count,billing_interval_unit,
             effective_from,effective_until,next_invoice_date,last_invoice_date,status,
             approval_status,is_base,created_by,rate_basis,monthly_rate,billing_anchor_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)
    ');
    $insert->execute([
        $contractId,
        substr($name, 0, 190),
        $contract['scope'] ?? null,
        (float)($contract['price_per_invoice'] ?? 0),
        max(1, (int)($contract['billing_interval_count'] ?? 1)),
        (string)($contract['billing_interval_unit'] ?? 'month'),
        $effectiveFrom,
        $contract['end_date'] ?: null,
        $contract['next_invoice_date'] ?: null,
        $contract['last_invoice_date'] ?: null,
        pa_recurring_service_contract_status($contract),
        'approved',
        !empty($contract['created_by']) ? (int)$contract['created_by'] : null,
        (string)($contract['rate_basis'] ?? 'per_period'),
        $contract['monthly_rate'] ?? null,
        ($contract['rate_basis'] ?? 'per_period') === 'monthly' ? ($contract['next_invoice_date'] ?: $effectiveFrom) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function pa_recurring_service_sync_base(PDO $pdo, int $contractId): void
{
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term" AND pricing_type="per_invoice" LIMIT 1');
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return;
    }
    $baseId = pa_recurring_service_ensure_base($pdo, $contractId);
    if ($baseId === null) {
        return;
    }
    $changes = $pdo->prepare("SELECT 1 FROM contract_recurring_service_changes WHERE recurring_service_id=? AND state IN ('pending','approved') LIMIT 1");
    $changes->execute([$baseId]);
    if ($changes->fetchColumn()) throw new DomainException('Cancel the scheduled service change before editing the original billing setup.');
    $name = trim((string)($contract['scope'] ?? '')) ?: 'Recurring service';
    $pdo->prepare('
        UPDATE contract_recurring_services
        SET name=?, description=?, amount=?, billing_interval_count=?, billing_interval_unit=?,
            effective_from=?, effective_until=?, next_invoice_date=?,rate_basis=?,monthly_rate=?,billing_anchor_date=?
        WHERE id=? AND contract_id=? AND is_base=1
    ')->execute([
        substr($name, 0, 190),
        $contract['scope'] ?? null,
        (float)($contract['price_per_invoice'] ?? 0),
        max(1, (int)($contract['billing_interval_count'] ?? 1)),
        (string)($contract['billing_interval_unit'] ?? 'month'),
        (string)($contract['start_date'] ?: date('Y-m-d')),
        $contract['end_date'] ?: null,
        $contract['next_invoice_date'] ?: null,
        (string)($contract['rate_basis'] ?? 'per_period'),
        $contract['monthly_rate'] ?? null,
        ($contract['rate_basis'] ?? 'per_period') === 'monthly' ? ($contract['next_invoice_date'] ?: ($contract['start_date'] ?: date('Y-m-d'))) : null,
        $baseId,
        $contractId,
    ]);
}

function pa_recurring_service_sync_contract_next_date(PDO $pdo, int $contractId): ?string
{
    $contractStmt = $pdo->prepare('SELECT status FROM contracts WHERE id=? AND contract_type="long_term" LIMIT 1');
    $contractStmt->execute([$contractId]);
    $contractStatus = strtolower((string)($contractStmt->fetchColumn() ?: 'pending'));
    $scheduleStatus = $contractStatus === 'paused' ? 'paused' : 'active';
    $stmt = $pdo->prepare('
        SELECT MIN(next_invoice_date)
        FROM contract_recurring_services
        WHERE contract_id=?
          AND status=?
          AND approval_status="approved"
          AND next_invoice_date IS NOT NULL
          AND (effective_until IS NULL OR next_invoice_date<=effective_until)
    ');
    $stmt->execute([$contractId, $scheduleStatus]);
    $nextDate = $stmt->fetchColumn();
    $nextDate = $nextDate !== false && $nextDate !== null ? (string)$nextDate : null;
    $pdo->prepare('UPDATE contracts SET next_invoice_date=? WHERE id=? AND contract_type="long_term"')
        ->execute([$nextDate, $contractId]);
    return $nextDate;
}

function pa_recurring_services_activate(PDO $pdo, int $contractId, ?string $baseNextDate = null): void
{
    pa_recurring_service_ensure_base($pdo, $contractId);
    if ($baseNextDate !== null) {
        $pdo->prepare("UPDATE contract_recurring_services SET next_invoice_date=?,billing_anchor_date=CASE WHEN rate_basis='monthly' AND last_invoice_date IS NULL THEN ? ELSE billing_anchor_date END WHERE contract_id=? AND is_base=1")
            ->execute([$baseNextDate, $baseNextDate, $contractId]);
    }
    $pdo->prepare('
        UPDATE contract_recurring_services
        SET status="active"
        WHERE contract_id=? AND approval_status="approved" AND status IN ("pending","paused")
    ')->execute([$contractId]);
    pa_recurring_service_sync_contract_next_date($pdo, $contractId);
}

function pa_recurring_services_pause(PDO $pdo, int $contractId): void
{
    $pdo->prepare('UPDATE contract_recurring_services SET status="paused" WHERE contract_id=? AND status="active"')
        ->execute([$contractId]);
}

function pa_recurring_services_resume(PDO $pdo, int $contractId): void
{
    $pdo->prepare('UPDATE contract_recurring_services SET status="active" WHERE contract_id=? AND status="paused" AND approval_status="approved"')
        ->execute([$contractId]);
    pa_recurring_service_sync_contract_next_date($pdo, $contractId);
}

function pa_recurring_services_end(PDO $pdo, int $contractId, string $effectiveDate): void
{
    $pdo->prepare('
        UPDATE contract_recurring_services
        SET status="ended", effective_until=COALESCE(effective_until,?), next_invoice_date=NULL
        WHERE contract_id=? AND status<>"ended"
    ')->execute([$effectiveDate, $contractId]);
}

function pa_recurring_services_due(PDO $pdo, int $contractId, string $throughDate): array
{
    $stmt = $pdo->prepare('
        SELECT *
        FROM contract_recurring_services
        WHERE contract_id=?
          AND status="active"
          AND approval_status="approved"
          AND next_invoice_date IS NOT NULL
          AND next_invoice_date<=?
          AND effective_from<=?
          AND (effective_until IS NULL OR next_invoice_date<=effective_until)
          AND next_invoice_date = (
              SELECT MIN(due.next_invoice_date)
              FROM contract_recurring_services due
              WHERE due.contract_id=contract_recurring_services.contract_id
                AND due.status="active"
                AND due.approval_status="approved"
                AND due.next_invoice_date IS NOT NULL
                AND due.next_invoice_date<=?
                AND due.effective_from<=?
                AND (due.effective_until IS NULL OR due.next_invoice_date<=due.effective_until)
          )
        ORDER BY next_invoice_date,id
        FOR UPDATE
    ');
    $stmt->execute([$contractId, $throughDate, $throughDate, $throughDate, $throughDate]);
    return array_map(fn(array $service): array => pa_recurring_service_apply_change($pdo, $service), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function pa_recurring_services_exist(PDO $pdo, int $contractId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM contract_recurring_services WHERE contract_id=? LIMIT 1');
    $stmt->execute([$contractId]);
    return $stmt->fetchColumn() !== false;
}

function pa_recurring_service_next_date(string $currentDate, int $intervalCount, string $intervalUnit, ?string $anchorDate = null): string
{
    $intervalCount = max(1, $intervalCount);
    if (!in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
        $intervalUnit = 'month';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $currentDate);
    if (!$date) {
        throw new InvalidArgumentException('Invalid recurring service date.');
    }
    $anchor = $anchorDate ? DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate) : null;
    if (!$anchor) {
        $anchor = $date;
    }
    if ($intervalUnit === 'month') {
        $monthIndex = ((int)$date->format('Y') * 12) + ((int)$date->format('n') - 1) + $intervalCount;
        $targetYear = intdiv($monthIndex, 12);
        $targetMonth = ($monthIndex % 12) + 1;
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth));
        $targetDay = min((int)$anchor->format('j'), (int)$first->modify('last day of this month')->format('j'));
        return $first->setDate($targetYear, $targetMonth, $targetDay)->format('Y-m-d');
    }
    if ($intervalUnit === 'year') {
        $targetYear = (int)$date->format('Y') + $intervalCount;
        $targetMonth = (int)$anchor->format('n');
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth));
        $targetDay = min((int)$anchor->format('j'), (int)$first->modify('last day of this month')->format('j'));
        return $first->setDate($targetYear, $targetMonth, $targetDay)->format('Y-m-d');
    }
    return $date->modify('+' . $intervalCount . ' ' . $intervalUnit)->format('Y-m-d');
}
