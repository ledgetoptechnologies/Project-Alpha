<?php
declare(strict_types=1);

require_once __DIR__ . '/stripe_payment_accounting.php';

/**
 * The caller holds the invoice row lock. A failed attempt is not a settled
 * payment: Stripe can successfully retry the same PaymentIntent later.
 */
function stripe_invoice_existing_payment(PDO $pdo, int $paymentId, int $invoiceId, ?string $paymentIntentId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.*,i.status AS invoice_status,i.collection_mode AS invoice_collection_mode,
            EXISTS(SELECT 1 FROM project_invoice_items pii WHERE pii.invoice_id=i.id) AS assigned_project_invoice
         FROM payments p
         JOIN invoices i ON i.id=p.invoice_id WHERE p.id=? FOR UPDATE'
    );
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || (int)$payment['invoice_id'] !== $invoiceId
        || ($paymentIntentId !== null && (string)$payment['stripe_payment_intent_id'] !== $paymentIntentId)) {
        throw new RuntimeException('Existing Stripe payment identity does not match this invoice; manual review is required.');
    }
    $amount = $payment['amount'] ?? null;
    if (!is_numeric($amount) || !is_finite((float)$amount)) {
        throw new RuntimeException('Existing Stripe payment has no valid principal; manual review is required.');
    }
    if (($payment['status'] ?? '') === 'failed' && (float)$amount === 0.0
        && $paymentIntentId !== null && $paymentIntentId !== ''
        && ($payment['payment_method'] ?? '') === 'stripe'
        && empty($payment['project_invoice_payment_id'])
        && (float)($payment['surcharge_paid'] ?? 0) === 0.0
        && (float)($payment['refunded_amount'] ?? 0) === 0.0
        && (float)($payment['disputed_amount'] ?? 0) === 0.0) {
        if (!empty($payment['assigned_project_invoice'])
            || !in_array((string)($payment['invoice_collection_mode'] ?? ''), ['', 'direct'], true)) {
            throw new RuntimeException('Failed Stripe attempt belongs to an assigned Project Invoice; manual review is required.');
        }
        $payment['transition_required'] = true;
        return $payment;
    }
    if (($payment['status'] ?? '') !== 'succeeded' || (float)$amount <= 0) {
        throw new RuntimeException('Existing Stripe payment has no valid positive settled principal; manual review is required.');
    }
    $payment['transition_required'] = false;
    return $payment;
}

/** Promote only the locked, explicitly recognized failed attempt in place. */
function stripe_invoice_promote_failed_payment(
    PDO $pdo, int $paymentId, int $invoiceId, string $paymentIntentId,
    ?string $sessionId, float $principal, float $surcharge, array $processorTx, array $appConfig
): void {
    $fields = stripe_processor_fields_from_normalized($processorTx, $appConfig, $principal, $surcharge);
    $stmt = $pdo->prepare(
        'UPDATE payments SET amount=?,surcharge_paid=?,status="succeeded",payment_date=CURDATE(),auto_pay_attempt=0,
            stripe_session_id=COALESCE(NULLIF(?,""),stripe_session_id),processor_provider=?,processor_payment_id=?,
            processor_gross_amount=?,processor_fee_amount=?,processor_net_amount=?,processor_fee_policy=?,processor_fee_source=?
         WHERE id=? AND invoice_id=? AND stripe_payment_intent_id=? AND status="failed" AND amount=0'
    );
    $stmt->execute([
        $principal, $surcharge, $sessionId,
        $fields['processor_provider'], $fields['processor_payment_id'] ?: null,
        $fields['processor_gross_amount'], $fields['processor_fee_amount'], $fields['processor_net_amount'],
        $fields['processor_fee_policy'], $fields['processor_fee_source'],
        $paymentId, $invoiceId, $paymentIntentId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Failed Stripe attempt changed before settlement; retry the event.');
    }
}
