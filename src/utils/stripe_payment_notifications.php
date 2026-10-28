<?php

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/payment_receipts.php';

/**
 * Deliver ordinary invoice settlement emails after the accounting commit.
 * Duplicate callbacks retry missing deliveries using the same durable keys.
 * Both channels are attempted; delivery failures remain retryable by the caller.
 */
function stripe_invoice_deliver_payment_emails(
    PDO $pdo,
    array $appConfig,
    int $invoiceId,
    int $paymentId,
    float $paymentAmount,
    string $invoiceStatus,
    bool $newPayment,
    ?callable $sender = null
): void {
    payment_email_attempt_all(
        static fn() => notify_admin_invoice_paid(
            $pdo, $appConfig, $invoiceId, $paymentAmount, $invoiceStatus,
            $newPayment, true, $sender, 'payment:' . $paymentId
        ),
        static fn() => payment_receipt_issue(
            $pdo, $paymentId, $appConfig, true, $sender, true
        )
    );
}
