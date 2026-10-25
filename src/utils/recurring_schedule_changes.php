<?php
declare(strict_types=1);

/** Exact decimal arithmetic bounded to the existing DECIMAL(12,2) columns. */
function pa_recurring_rate_minor(string $amount): int
{
    if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', trim($amount), $match)) {
        throw new DomainException('Enter a positive amount with at most two decimal places.');
    }
    $minor = (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
    if ($minor <= 0) throw new DomainException('The recurring rate must be positive.');
    return $minor;
}

function pa_recurring_rate_money(int $minor): string
{
    if ($minor <= 0 || $minor > 999999999999) throw new DomainException('The recurring charge exceeds the supported amount.');
    return intdiv($minor, 100) . '.' . str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
}

function pa_recurring_service_period_amount(string $monthlyRate, int $count, string $unit): string
{
    if ($count !== 1 || !in_array($unit, ['month', 'year'], true)) {
        throw new DomainException('A monthly rate supports monthly or annual billing.');
    }
    return pa_recurring_rate_money(pa_recurring_rate_minor($monthlyRate) * ($unit === 'year' ? 12 : 1));
}

function pa_recurring_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** No caller can shorten a period that has already been invoiced. */
function pa_recurring_change_boundary(array $service, string $today): string
{
    $boundary = (string)($service['next_invoice_date'] ?? '');
    if (!pa_recurring_valid_date($boundary) || $boundary < $today) {
        throw new DomainException('Generate outstanding scheduled invoices before changing this service, or set up a new service if it has no renewal date.');
    }
    if (($service['status'] ?? '') === 'ended') throw new DomainException('An ended service cannot be changed.');
    return $boundary;
}

/** Caller holds the contract and service row locks in a transaction. */
function pa_recurring_service_queue_change(PDO $pdo, array $service, array $values, bool $approved, ?string $signedDocument, ?int $actor, ?string $today = null): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Schedule changes require a transaction.');
    $boundary = pa_recurring_change_boundary($service, $today ?? date('Y-m-d'));
    $existing = $pdo->prepare("SELECT id FROM contract_recurring_service_changes WHERE recurring_service_id=? AND state IN ('pending','approved')");
    $existing->execute([$service['id']]);
    if ($existing->fetchColumn()) throw new DomainException('Cancel the existing scheduled change before replacing it.');
    // Renewal is the amendment's effective date. Its calendar anniversary is
    // independent: preserve it for rate-only changes, restart it for cadence changes.
    $sameCadence = (int)$values['billing_interval_count'] === (int)$service['billing_interval_count']
        && (string)$values['billing_interval_unit'] === (string)$service['billing_interval_unit']
        && (string)($values['rate_basis'] ?? 'per_period') === (string)($service['rate_basis'] ?? 'per_period');
    $values['billing_anchor_date'] = $sameCadence ? (string)($service['billing_anchor_date'] ?? $service['effective_from']) : $boundary;
    $values['effective_from'] = $boundary;
    $values['next_invoice_date'] = $boundary;
    if (!empty($values['effective_until']) && $values['effective_until'] < $boundary) {
        throw new DomainException('The service end date precedes the next renewal.');
    }
    pa_recurring_service_period_end($values, $boundary);
    $stmt = $pdo->prepare('INSERT INTO contract_recurring_service_changes (contract_id,recurring_service_id,effective_date,state,old_values,new_values,signed_document_path,created_by,approved_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([(int)$service['contract_id'], (int)$service['id'], $boundary, $approved ? 'approved' : 'pending',
        json_encode(pa_recurring_service_snapshot($service), JSON_THROW_ON_ERROR), json_encode($values, JSON_THROW_ON_ERROR),
        $signedDocument, $actor, $approved ? date('Y-m-d H:i:s') : null]);
    return (int)$pdo->lastInsertId();
}

/** Apply only at a service's next unbilled boundary, never on the edit date. */
function pa_recurring_service_apply_change(PDO $pdo, array $service): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Schedule changes require a transaction.');
    $stmt = $pdo->prepare("SELECT * FROM contract_recurring_service_changes WHERE recurring_service_id=? AND state='approved' AND effective_date<=? ORDER BY effective_date,id LIMIT 1");
    $stmt->execute([$service['id'], $service['next_invoice_date']]);
    $change = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$change) return $service;
    if ($change['effective_date'] !== $service['next_invoice_date']) {
        throw new DomainException('The approved schedule change no longer matches the renewal date. Review the service before billing.');
    }
    $values = json_decode($change['new_values'], true, 32, JSON_THROW_ON_ERROR);
    $sameCadence = (int)$values['billing_interval_count'] === (int)$service['billing_interval_count']
        && (string)$values['billing_interval_unit'] === (string)$service['billing_interval_unit']
        && (string)($values['rate_basis'] ?? 'per_period') === (string)($service['rate_basis'] ?? 'per_period');
    $anchor = (string)($values['billing_anchor_date'] ?? ($sameCadence ? ($service['billing_anchor_date'] ?? $service['effective_from']) : $change['effective_date']));
    if (!pa_recurring_valid_date($anchor) || $anchor > $change['effective_date']) throw new DomainException('The approved schedule has an invalid calendar anchor.');
    $update = $pdo->prepare('UPDATE contract_recurring_services SET name=?,description=?,amount=?,billing_interval_count=?,billing_interval_unit=?,effective_from=?,effective_until=?,rate_basis=?,monthly_rate=?,billing_anchor_date=? WHERE id=? AND contract_id=?');
    $update->execute([$values['name'], $values['description'], $values['amount'], $values['billing_interval_count'], $values['billing_interval_unit'],
        $change['effective_date'], $values['effective_until'], $values['rate_basis'], $values['monthly_rate'], $anchor, $service['id'], $service['contract_id']]);
    $pdo->prepare("UPDATE contract_recurring_service_changes SET state='applied',applied_at=CURRENT_TIMESTAMP WHERE id=? AND state='approved'")->execute([$change['id']]);
    // Lifecycle/approval are current service state, not stale values from the proposal.
    $values['effective_from'] = $change['effective_date'];
    $values['billing_anchor_date'] = $anchor;
    return array_replace($service, array_intersect_key($values, array_flip(['name','description','amount','billing_interval_count','billing_interval_unit','effective_from','effective_until','rate_basis','monthly_rate','billing_anchor_date'])));
}

function pa_recurring_service_change_action(PDO $pdo, array $service, int $changeId, string $action, ?string $today = null): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Schedule changes require a transaction.');
    $stmt = $pdo->prepare("SELECT * FROM contract_recurring_service_changes WHERE id=? AND recurring_service_id=? AND contract_id=? AND state IN ('pending','approved')");
    $stmt->execute([$changeId, $service['id'], $service['contract_id']]);
    $change = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$change) throw new DomainException('Scheduled change not found or already applied.');
    if ($action === 'approve_change') {
        if (pa_recurring_change_boundary($service, $today ?? date('Y-m-d')) !== $change['effective_date']) throw new DomainException('The renewal date has changed. Cancel this change and submit a new one.');
        $pdo->prepare("UPDATE contract_recurring_service_changes SET state='approved',approved_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$changeId]);
    } elseif ($action === 'cancel_change') {
        $pdo->prepare("UPDATE contract_recurring_service_changes SET state='cancelled' WHERE id=?")->execute([$changeId]);
    } else {
        throw new DomainException('Invalid schedule change action.');
    }
    return $change;
}

/** Calendar coverage for one complete charge; no implicit proration or overrun. */
function pa_recurring_service_period_end(array $service, string $start): string
{
    $anchor = (string)($service['billing_anchor_date'] ?? $service['effective_from'] ?? $start);
    $end = pa_recurring_service_next_date($start, (int)$service['billing_interval_count'], (string)$service['billing_interval_unit'], $anchor);
    if (($service['rate_basis'] ?? 'per_period') === 'monthly' && !empty($service['effective_until'])) {
        $lastCoveredDay = (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d');
        if ((string)$service['effective_until'] < $lastCoveredDay) {
            throw new DomainException('This billing period extends beyond the service end date. Extend the end date or choose a shorter billing frequency; no automatic proration is applied.');
        }
    }
    return $end;
}
