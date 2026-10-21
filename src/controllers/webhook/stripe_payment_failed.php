<?php
// src/controllers/webhook/stripe_payment_failed.php
// Handler for payment_intent.payment_failed events

function handlePaymentIntentFailed($pdo, $paymentIntent) {
    $metadata = $paymentIntent['metadata'] ?? [];
    $invoiceId = $metadata['pa_invoice_id'] ?? $metadata['invoice_id'] ?? null;
    $piId = $paymentIntent['id'] ?? null;
    
    if (!$invoiceId || !$piId) {
        @error_log('[StripeWebhook] Payment failed - missing metadata: ' . ($piId ?? 'no-id'));
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $errorMessage = $paymentIntent['last_payment_error']['message'] ?? 'Unknown error';
    
    // Log the failed payment
    @error_log('[StripeWebhook] Payment failed for invoice ' . $invoiceId . ': ' . $errorMessage);
    
    // Record failed payment attempt (optional - for tracking)
    try {
        // Serialize with successful settlement of this same PaymentIntent.
        $pdo->beginTransaction();
        $invStmt = $pdo->prepare('SELECT client_id FROM invoices WHERE id = ? FOR UPDATE');
        $invStmt->execute([$invoiceId]);
        $clientId = (int)$invStmt->fetchColumn();
        if ($clientId <= 0) {
            $pdo->rollBack();
            @error_log('[StripeWebhook] Failed payment invoice not found: ' . $invoiceId);
            return;
        }

        $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
        $existsStmt->execute([$piId]);
        if ($existsStmt->fetchColumn()) {
            $pdo->commit();
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO payments (client_id, invoice_id, amount, payment_method, stripe_payment_intent_id, auto_pay_attempt, status, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())');
        $stmt->execute([
            $clientId,
            $invoiceId,
            0, // Amount is 0 for failed payments
            'stripe',
            $piId,
            0, // Failed retries do not re-enable the retired AutoPay workflow.
            'failed'
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record failed payment: ' . $e->getMessage());
        throw $e;
    }
    
    // Future: Send notification to admin about failed payment
    // Future: Implement retry logic for auto-pay
    // Future: Update subscription status if applicable
}
