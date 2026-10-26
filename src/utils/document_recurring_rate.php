<?php
declare(strict_types=1);

require_once __DIR__ . '/recurring_services.php';

/** Resolve posted draft pricing without interpreting existing per-period amounts as monthly rates. */
function pa_document_recurring_rate(array $post, array $existing, int $count, string $unit, string $pricingType, ?string $periodAmount): array
{
    if ($pricingType !== 'per_invoice') {
        return ['rate_basis' => 'per_period', 'monthly_rate' => null, 'amount' => $periodAmount];
    }
    $basis = (string)($post['rate_basis'] ?? $existing['rate_basis'] ?? 'per_period');
    if (!in_array($basis, ['per_period', 'monthly'], true)) throw new DomainException('Choose a valid rate basis.');
    if ($basis === 'per_period') return ['rate_basis' => $basis, 'monthly_rate' => null, 'amount' => $periodAmount];
    $monthly = trim((string)($post['monthly_rate'] ?? $existing['monthly_rate'] ?? ''));
    $amount = pa_recurring_service_period_amount($monthly, $count, $unit);
    return ['rate_basis' => $basis, 'monthly_rate' => pa_recurring_rate_money(pa_recurring_rate_minor($monthly)), 'amount' => $amount];
}

function pa_document_recurring_rate_save(PDO $pdo, string $type, int $id, array $rate): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Recurring rate changes require a transaction.');
    $table = match ($type) { 'quote' => 'quotes', 'contract' => 'contracts', default => throw new InvalidArgumentException('Invalid recurring document type.') };
    $pdo->prepare("UPDATE {$table} SET rate_basis=?,monthly_rate=? WHERE id=?")
        ->execute([$rate['rate_basis'], $rate['monthly_rate'], $id]);
}

/** Draft previews must fit a complete billing period within the selected service term. */
function pa_document_recurring_rate_validate_period(array $rate, int $count, string $unit, ?string $firstInvoiceDate, ?string $endDate): void
{
    if (($rate['rate_basis'] ?? 'per_period') !== 'monthly' || $firstInvoiceDate === null || $firstInvoiceDate === '') return;
    if (!pa_recurring_valid_date($firstInvoiceDate) || ($endDate !== null && $endDate !== '' && !pa_recurring_valid_date($endDate))) {
        throw new DomainException('Choose valid service start and end dates.');
    }
    pa_recurring_service_period_end([
        'rate_basis' => 'monthly',
        'effective_from' => $firstInvoiceDate,
        'billing_anchor_date' => $firstInvoiceDate,
        'effective_until' => $endDate ?: null,
        'billing_interval_count' => $count,
        'billing_interval_unit' => $unit,
    ], $firstInvoiceDate);
}
