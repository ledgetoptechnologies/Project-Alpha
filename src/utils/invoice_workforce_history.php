<?php

declare(strict_types=1);
require_once __DIR__ . '/workforce_availability.php';

/** Existing persisted source links only; never derive preserved IDs from POST. */
function invoice_workforce_history(PDO $pdo, int $invoiceId): array
{
    if (pa_workforce_enabled()) return ['items' => [], 'linked' => false];
    $stmt = $pdo->prepare('SELECT ii.* FROM invoice_items ii WHERE ii.invoice_id=? AND (
        ii.time_entry_id IS NOT NULL
        OR EXISTS (SELECT 1 FROM time_entries te WHERE te.invoice_item_id=ii.id)
        OR EXISTS (SELECT 1 FROM mileage_charge_allocations ma WHERE ma.invoice_item_id=ii.id)
        OR EXISTS (SELECT 1 FROM mileage_logs ml WHERE ml.invoice_item_id=ii.id)
    ) ORDER BY ii.id');
    $stmt->execute([$invoiceId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $items[(int)$row['id']] = $row;
    $linked = $pdo->prepare('SELECT id FROM time_entries WHERE invoice_id=? UNION ALL SELECT id FROM mileage_charge_allocations WHERE invoice_id=? UNION ALL SELECT id FROM mileage_logs WHERE invoice_id=? LIMIT 1');
    $linked->execute([$invoiceId, $invoiceId, $invoiceId]);
    return ['items' => $items, 'linked' => $items !== [] || (bool)$linked->fetchColumn()];
}
