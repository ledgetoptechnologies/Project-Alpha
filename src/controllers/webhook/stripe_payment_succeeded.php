<?php
// src/controllers/webhook/stripe_payment_succeeded.php
// Handler for payment_intent.succeeded events

// The signed webhook router owns the database connection.
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../services/PaymentProcessorImportService.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../../utils/stripe_financial_events.php';
require_once __DIR__ . '/../../utils/stripe_payment_accounting.php';
require_once __DIR__ . '/../../utils/stripe_invoice_payment_amounts.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';

function handlePaymentIntentSucceeded($pdo, $paymentIntent) {
    $metadata = $paymentIntent['metadata'] ?? [];
    if (!empty($metadata['pa_project_invoice_id']) || !empty($metadata['project_invoice_id'])) {
        require_once __DIR__ . '/../../utils/project_invoice_billing.php';
        if (!project_invoice_record_stripe_payment($pdo, $paymentIntent)) {
            throw new RuntimeException('Project invoice payment could not be allocated.');
        }
        return;
    }
    $invoiceId = $metadata['pa_invoice_id'] ?? $metadata['invoice_id'] ?? null;
    $piId = $paymentIntent['id'] ?? null;
    
    // If no metadata, try to find invoice from charges data
    if (!$invoiceId) {
        $charges = $paymentIntent['charges']['data'] ?? [];
        if (!empty($charges)) {
            $charge = $charges[0];
            $chargeMetadata = $charge['metadata'] ?? [];
            $invoiceId = $chargeMetadata['pa_invoice_id'] ?? $chargeMetadata['invoice_id'] ?? null;
            
            // Also check description for invoice reference
            if (!$invoiceId) {
                $description = $charge['description'] ?? '';
                if (preg_match('/Invoice\s+(?:I|LTI|ODI)-(\d+)/i', $description, $matches)) {
                    $invoiceId = $matches[1];
                }
            }
        }
    }
    
    if (!$invoiceId || !$piId) {
        if ($piId) {
            $stripe = StripeService::fromAppConfig($GLOBALS['appConfig'] ?? []);
            if ($stripe) {
                $result = PaymentProcessorImportService::importStandalone(
                    $pdo,
                    $GLOBALS['appConfig'] ?? [],
                    $stripe->normalizePaymentIntentForImport($paymentIntent)
                );
                @error_log('[StripeWebhook] Standalone processor import for ' . $piId . ': ' . ($result['status'] ?? 'unknown'));
                return;
            }
        }
        @error_log('[StripeWebhook] PaymentIntent missing invoice_id or id. metadata=' . json_encode($metadata) . ' piId=' . $piId);
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $amounts = stripe_invoice_payment_amounts($paymentIntent, 'payment_intent');
    $amountTotal = $amounts['gross_amount'];
    $paymentAmount = $amounts['payment_amount'];
    $surchargeAmount = $amounts['surcharge_amount'];
    $processorTx = [
        'provider' => 'stripe',
        'provider_payment_id' => (string)$piId,
        'status' => 'succeeded',
        'gross_amount' => $amountTotal,
        'fee_amount' => null,
        'net_amount' => null,
        'metadata' => $metadata,
    ];
    $stripe = StripeService::fromAppConfig($GLOBALS['appConfig'] ?? []);
    if ($stripe) {
        try {
            $processorTx = $stripe->normalizePaymentIntentForImport($paymentIntent);
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Could not normalize fee/net for PaymentIntent ' . $piId . ': ' . $e->getMessage());
        }
    }
    
    try {
    // Serialize payment recording with manual payments and Checkout webhooks.
    $pdo->beginTransaction();
    $invCheck = $pdo->prepare('SELECT id,total,client_id FROM invoices WHERE id=? FOR UPDATE');
    $invCheck->execute([$invoiceId]);
    $invoice = $invCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found - skipping PaymentIntent recording');
        return;
    }
    
    $clientId = (int)($invoice['client_id'] ?? 0);
    
    // Check if already recorded (idempotency)
    $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
    $existsStmt->execute([$piId]);
    $existingPaymentId = (int)($existsStmt->fetchColumn() ?: 0);
    if ($existingPaymentId > 0) {
        $deliveryState = $pdo->prepare(
            'SELECT p.amount,i.status FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.id=?'
        );
        $deliveryState->execute([$existingPaymentId]);
        $existing = $deliveryState->fetch(PDO::FETCH_ASSOC);
        if (!$existing || !is_numeric($existing['amount'] ?? null)
            || !is_finite((float)$existing['amount']) || (float)$existing['amount'] <= 0) {
            throw new RuntimeException('Existing Stripe payment has no valid positive principal; manual review is required.');
        }
        stripe_update_payment_processor_fields($pdo, $existingPaymentId, $processorTx, $GLOBALS['appConfig'] ?? []);
        $pdo->commit();
        if ($existing) {
            require_once __DIR__ . '/../../utils/payment_receipts.php';
            payment_email_attempt_all(
                static fn() => notify_admin_invoice_paid(
                    $pdo, $GLOBALS['appConfig'] ?? [], $invoiceId, (float)$existing['amount'],
                    (string)$existing['status'], false, true, null, 'payment:' . $existingPaymentId
                ),
                static fn() => payment_receipt_issue(
                    $pdo, $existingPaymentId, $GLOBALS['appConfig'] ?? [], true, null, true
                )
            );
        }
        @error_log('[StripeWebhook] PaymentIntent already recorded: ' . $piId);
        return;
    }
    
        // Record the payment
        // Legacy metadata is retained only as Stripe-side history. It never
        // grants collection authority or re-enables PA's retired AutoPay path.
        $isAutoPay = 0;
        $stmt = $pdo->prepare('
            INSERT INTO payments
                (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_payment_intent_id, auto_pay_attempt,
                 processor_provider, processor_payment_id, processor_gross_amount, processor_fee_amount, processor_net_amount,
                 processor_fee_policy, processor_fee_source, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ');
        $processorFields = stripe_processor_fields_from_normalized($processorTx, $GLOBALS['appConfig'] ?? [], $paymentAmount, $surchargeAmount);
        $stmt->execute([
            $clientId, $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $piId, $isAutoPay,
            $processorFields['processor_provider'], $processorFields['processor_payment_id'] ?: null,
            $processorFields['processor_gross_amount'], $processorFields['processor_fee_amount'], $processorFields['processor_net_amount'],
            $processorFields['processor_fee_policy'], $processorFields['processor_fee_source'], 'succeeded'
        ]);
        $paymentId = (int)$pdo->lastInsertId();
        stripe_link_pending_financial_events($pdo, $paymentId, $piId);
        
        // COMPLIANCE: If the card was debit/prepaid, refund the surcharge (Durbin Amendment)
        $surchargePaid = (float)$surchargeAmount;
        if ($surchargePaid > 0) {
            require_once __DIR__ . '/../../services/PaymentProcessorInterface.php';
            require_once __DIR__ . '/../../services/StripeProcessor.php';
            $processor = StripeProcessor::fromAppConfig($GLOBALS['appConfig'] ?? []);
            if ($processor && !$processor->isCreditCardPayment($paymentIntent)) {
                $chargeId = $paymentIntent['charges']['data'][0]['id'] ?? null;
                if ($chargeId && $processor->refundSurcharge($chargeId, $surchargePaid)) {
                    $pdo->prepare('UPDATE payments SET surcharge_refunded = 1, surcharge_refund_amount = ? WHERE stripe_payment_intent_id = ?')
                        ->execute([$surchargePaid, $piId]);
                    @error_log('[StripeWebhook] Refunded surcharge $' . $surchargePaid . ' for debit card on invoice ' . $invoiceId);
                } else {
                    @error_log('[StripeWebhook] WARNING: failed to refund surcharge for debit card on invoice ' . $invoiceId);
                }
            }
        }
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $total = (float)$invoice['total'];
        $status = ($paid >= $total) ? 'paid' : 'partial';
        
        $pdo->prepare('UPDATE invoices SET status=?,amount_paid=?,balance_due=GREATEST(total-?,0),paid_at=CASE WHEN ?="paid" THEN COALESCE(paid_at,NOW()) ELSE NULL END,stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$status, $paid, $paid, $status, $invoiceId]);
        
        // If paid, revoke public links and complete only a one-time contract.
        if ($status === 'paid') {
            pa_public_link_terminalize($pdo, 'invoice', $invoiceId, 'paid');
            invoice_complete_linked_contract_if_eligible($pdo, $invoiceId);
        }
        
        $pdo->commit();
        @error_log('[StripeWebhook] PaymentIntent recorded for invoice ' . $invoiceId . ': $' . $paymentAmount . ' - status: ' . $status);
        
        require_once __DIR__ . '/../../utils/payment_receipts.php';
        payment_email_attempt_all(
            static fn() => notify_admin_invoice_paid(
                $pdo, $GLOBALS['appConfig'] ?? [], $invoiceId, $paymentAmount, $status,
                true, true, null, 'payment:' . $paymentId
            ),
            static fn() => payment_receipt_issue(
                $pdo, $paymentId, $GLOBALS['appConfig'] ?? [], true, null, true
            )
        );
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record PaymentIntent: ' . $e->getMessage());
        throw $e;
    }
}
