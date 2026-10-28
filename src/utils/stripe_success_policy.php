<?php

declare(strict_types=1);

function pa_stripe_success_link_valid(mixed $link, int $now): bool
{
    if (!is_array($link) || !in_array((string)($link['document_type'] ?? ''), ['invoice', 'project_invoice'], true)
        || !empty($link['revoked'])) {
        return false;
    }
    return empty($link['expires_at']) || (strtotime((string)$link['expires_at']) ?: 0) > $now;
}

function pa_stripe_money_cents(mixed $value): ?int
{
    if (!is_string($value) || !preg_match('/^([0-9]+)\.([0-9]{2})$/D', $value, $match)) {
        return null;
    }
    $whole = filter_var($match[1], FILTER_VALIDATE_INT);
    if ($whole === false || $whole > intdiv(PHP_INT_MAX - 99, 100)) {
        return null;
    }
    return $whole * 100 + (int)$match[2];
}

function pa_stripe_success_session_verified(array $session, array $invoice, string $documentType, int $invoiceId, string $token, string $sessionId): bool
{
    if ($sessionId === '' || $token === '' || $invoiceId <= 0
        || !in_array($documentType, ['invoice', 'project_invoice'], true)
        || (string)($session['id'] ?? '') !== $sessionId
        || (string)($invoice['stripe_session_id'] ?? '') !== $sessionId
        || (string)($session['status'] ?? '') !== 'complete'
        || (string)($session['payment_status'] ?? '') !== 'paid') {
        return false;
    }
    $metadata = $session['metadata'] ?? null;
    if (!is_array($metadata) || !hash_equals($token, (string)($metadata['token'] ?? ''))) {
        return false;
    }
    $idKey = $documentType === 'project_invoice' ? 'pa_project_invoice_id' : 'pa_invoice_id';
    if ((int)($metadata[$idKey] ?? 0) !== $invoiceId) {
        return false;
    }
    $invoiceCents = pa_stripe_money_cents($metadata['pa_invoice_amount'] ?? null);
    $surchargeCents = pa_stripe_money_cents($metadata['pa_surcharge_amount'] ?? null);
    return $invoiceCents !== null && $surchargeCents !== null
        && $invoiceCents > 0
        && $invoiceCents <= PHP_INT_MAX - $surchargeCents
        && (int)($session['amount_total'] ?? -1) === $invoiceCents + $surchargeCents;
}
