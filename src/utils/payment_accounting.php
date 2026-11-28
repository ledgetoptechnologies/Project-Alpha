<?php
declare(strict_types=1);

function payment_accounting_surcharge_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    return "GREATEST(COALESCE({$a}.surcharge_paid,0) - CASE WHEN COALESCE({$a}.surcharge_refunded,0)=1 THEN COALESCE({$a}.surcharge_refund_amount,0) ELSE 0 END, 0)";
}

function payment_accounting_invoice_applied_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    return "GREATEST(COALESCE({$a}.amount,0)-COALESCE({$a}.refunded_amount,0)-COALESCE({$a}.disputed_amount,0),0)";
}

function payment_accounting_gross_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    return "GREATEST(COALESCE({$a}.amount,0)+" . payment_accounting_surcharge_expr($a) . ",0)";
}

/** Money received after processor fees, before refunds and disputes. */
function payment_accounting_after_fee_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    $gross = payment_accounting_gross_expr($a);

    return "(CASE
        WHEN {$a}.processor_net_amount IS NOT NULL THEN {$a}.processor_net_amount
        WHEN {$a}.processor_gross_amount IS NOT NULL AND {$a}.processor_fee_amount IS NOT NULL THEN GREATEST({$a}.processor_gross_amount-{$a}.processor_fee_amount,0)
        WHEN {$a}.processor_fee_amount IS NOT NULL THEN GREATEST({$gross}-{$a}.processor_fee_amount,0)
        ELSE {$gross}
    END)";
}

/** Income = net collected: payment amount - processor fees - refunds - disputes. */
function payment_accounting_net_income_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    return "GREATEST(" . payment_accounting_after_fee_expr($a) . "-COALESCE({$a}.refunded_amount,0)-COALESCE({$a}.disputed_amount,0),0)";
}

/**
 * Every succeeded payment in the period, with or without an invoice, carrying gross, fee,
 * refunds and net. The one income source for the Financial Overview and the income audit.
 */
function payment_accounting_collected_payments(PDO $pdo, int $orgId, string $start, string $end): array
{
    $gross = payment_accounting_gross_expr('p');
    $afterFee = payment_accounting_after_fee_expr('p');
    $net = payment_accounting_net_income_expr('p');
    $stmt = $pdo->prepare("
        SELECT p.id AS payment_id,p.payment_date,p.payment_method,p.invoice_id,
               i.doc_number,i.invoice_type,i.status,i.total,i.tax_percent,i.tax_county,i.discount_value,
               COALESCE(ic.name,pc.name) AS client_name,
               {$gross} AS gross,GREATEST({$gross}-{$afterFee},0) AS fee,
               COALESCE(p.refunded_amount,0)+COALESCE(p.disputed_amount,0) AS refunds,{$net} AS net
        FROM payments p
        LEFT JOIN invoices i ON i.id=p.invoice_id
        LEFT JOIN clients ic ON ic.id=i.client_id
        LEFT JOIN clients pc ON pc.id=p.client_id
        WHERE p.status='succeeded' AND (?=0 OR COALESCE(p.organization_id,i.organization_id,0)=?) AND p.payment_date BETWEEN ? AND ?
        ORDER BY p.payment_date,p.id
    ");
    $stmt->bindValue(1, $orgId, PDO::PARAM_INT);
    $stmt->bindValue(2, $orgId, PDO::PARAM_INT);
    $stmt->bindValue(3, $start);
    $stmt->bindValue(4, $end);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Income audit rows: one per invoice, and one per payment that has no invoice. */
function payment_accounting_income_audit_rows(array $payments): array
{
    $rows = [];
    foreach ($payments as $p) {
        $key = !empty($p['invoice_id']) ? 'i' . $p['invoice_id'] : 'p' . $p['payment_id'];
        if (!isset($rows[$key])) {
            $rows[$key] = $p + ['id' => $p['invoice_id'], 'report_date' => $p['payment_date'], 'methods' => []];
            foreach (['gross', 'fee', 'refunds', 'net'] as $col) $rows[$key][$col] = 0.0;
        }
        foreach (['gross', 'fee', 'refunds', 'net'] as $col) $rows[$key][$col] += (float)$p[$col];
        $rows[$key]['methods'][$p['payment_method']] = true;
    }
    foreach ($rows as &$row) {
        $row['payment_methods'] = implode(', ', array_keys($row['methods']));
        unset($row['methods']);
    }
    unset($row);
    return array_values($rows);
}

function payment_accounting_net_income(array $payment): float
{
    $amount = (float)($payment['amount'] ?? 0);
    $surcharge = max(0.0, (float)($payment['surcharge_paid'] ?? 0));
    if (!empty($payment['surcharge_refunded'])) {
        $surcharge = max(0.0, $surcharge - (float)($payment['surcharge_refund_amount'] ?? 0));
    }

    $base = $amount + $surcharge;
    if (array_key_exists('processor_net_amount', $payment) && $payment['processor_net_amount'] !== null && $payment['processor_net_amount'] !== '') {
        $base = (float)$payment['processor_net_amount'];
    } elseif (($payment['processor_gross_amount'] ?? null) !== null && ($payment['processor_fee_amount'] ?? null) !== null) {
        $base = max(0.0, (float)$payment['processor_gross_amount'] - (float)$payment['processor_fee_amount']);
    } elseif (($payment['processor_fee_amount'] ?? null) !== null) {
        $base = max(0.0, $base - (float)$payment['processor_fee_amount']);
    }

    return round(max(0.0, $base - (float)($payment['refunded_amount'] ?? 0) - (float)($payment['disputed_amount'] ?? 0)), 2);
}

function payment_accounting_alias(string $alias): string
{
    $alias = trim($alias);
    return preg_match('/^[A-Za-z0-9_]+$/', $alias) ? $alias : 'p';
}
