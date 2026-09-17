<?php
// src/controllers/webhook/stripe_checkout_completed.php
// Handler for checkout.session.completed events

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../../utils/stripe_financial_events.php';
require_once __DIR__ . '/../../utils/stripe_payment_accounting.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';

function handleCheckoutSessionCompleted($pdo, $session) {
    $metadata = $session['metadata'] ?? [];
    if (!empty($metadata['pa_project_invoice_id']) || !empty($metadata['project_invoice_id'])) {
        require_once __DIR__ . '/../../utils/project_invoice_billing.php';
        if (!project_invoice_record_stripe_payment($pdo, $session)) {
            throw new RuntimeException('Project invoice payment could not be allocated.');
        }
        return;
    }
    $invoiceId = $metadata['invoice_id'] ?? $metadata['pa_invoice_id'] ?? null;
    
    if (!$invoiceId) {
        @error_log('[StripeWebhook] No invoice_id in session metadata');
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $amountTotal = ($session['amount_total'] ?? 0) / 100; // Convert from cents
    $paymentIntentId = !empty($session['payment_intent']) ? (string)$session['payment_intent'] : null;
    $paymentAmount = isset($metadata['original_amount']) ? (float)$metadata['original_amount'] : $amountTotal;
    $surchargeAmount = isset($metadata['surcharge_amount']) ? (float)$metadata['surcharge_amount'] : max(0, $amountTotal - $paymentAmount);
    $paymentStatus = $session['payment_status'] ?? '';
    
    if ($paymentStatus !== 'paid') {
        @error_log('[StripeWebhook] Session not paid: ' . $paymentStatus);
        return;
    }

    $processorTx = [
        'provider' => 'stripe',
        'provider_payment_id' => $paymentIntentId ?: '',
        'status' => 'succeeded',
        'gross_amount' => $amountTotal,
        'fee_amount' => null,
        'net_amount' => null,
        'metadata' => $metadata,
    ];
    if ($paymentIntentId) {
        $stripe = StripeService::fromAppConfig($GLOBALS['appConfig'] ?? []);
        if ($stripe) {
            try {
                $processorTx = $stripe->normalizePaymentIntentForImport(
                    $stripe->getPaymentIntentWithBalanceTransaction($paymentIntentId)
                );
            } catch (Throwable $e) {
                @error_log('[StripeWebhook] Could not fetch fee/net for session ' . ($session['id'] ?? '') . ': ' . $e->getMessage());
            }
        }
    }
    
    try {
    // Serialize payment recording with manual payments and other Stripe events.
    $pdo->beginTransaction();
    $invCheck = $pdo->prepare(
        'SELECT i.id,i.total,i.client_id,i.collection_mode,pii.project_invoice_id AS assigned_project_invoice_id
         FROM invoices i
         LEFT JOIN project_invoice_items pii ON pii.invoice_id=i.id
         WHERE i.id=? FOR UPDATE'
    );
    $invCheck->execute([$invoiceId]);
    $invoice = $invCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found - skipping payment recording');
        return;
    }
    
    $clientId = (int)($invoice['client_id'] ?? 0);
    
    // Idempotency check - prevent duplicate processing
    $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_session_id = ? OR (stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id = ?)');
    $existsStmt->execute([$session['id'], $paymentIntentId]);
    $existingPaymentId = (int)($existsStmt->fetchColumn() ?: 0);
    if ($existingPaymentId > 0) {
        stripe_update_payment_processor_fields($pdo, $existingPaymentId, $processorTx, $GLOBALS['appConfig'] ?? []);
        $pdo->commit();
        $deliveryState = $pdo->prepare(
            'SELECT p.amount,i.status FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.id=?'
        );
        $deliveryState->execute([$existingPaymentId]);
        $existing = $deliveryState->fetch(PDO::FETCH_ASSOC);
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
        @error_log('[StripeWebhook] Session ' . $session['id'] . ' already processed - skipping');
        return;
    }

    // A checkout can complete while staff is closing monthly billing. Once the
    // child belongs to a statement, route the already-authorized charge through
    // that statement so the same balance cannot be collected twice.
    $assignedProjectInvoiceId = (int)($invoice['assigned_project_invoice_id'] ?? 0);
    if ($assignedProjectInvoiceId > 0) {
        require_once __DIR__ . '/../../utils/project_invoice_billing.php';
        $projectSession = $session;
        $projectSession['metadata'] = array_merge($metadata, [
            'pa_project_invoice_id' => (string)$assignedProjectInvoiceId,
            'project_invoice_id' => (string)$assignedProjectInvoiceId,
            'original_amount' => (string)$paymentAmount,
        ]);
        if (!project_invoice_record_stripe_payment($pdo, $projectSession)) {
            throw new RuntimeException('Assigned Project Invoice payment could not be allocated.');
        }
        $pdo->commit();
        return;
    }
    
        // Record the payment
        $stmt = $pdo->prepare('
            INSERT INTO payments
                (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_session_id, stripe_payment_intent_id,
                 processor_provider, processor_payment_id, processor_gross_amount, processor_fee_amount, processor_net_amount,
                 processor_fee_policy, processor_fee_source, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ');
        $processorFields = stripe_processor_fields_from_normalized($processorTx, $GLOBALS['appConfig'] ?? [], $paymentAmount, $surchargeAmount);
        $stmt->execute([
            $clientId, $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $session['id'], $paymentIntentId,
            $processorFields['processor_provider'], $processorFields['processor_payment_id'] ?: null,
            $processorFields['processor_gross_amount'], $processorFields['processor_fee_amount'], $processorFields['processor_net_amount'],
            $processorFields['processor_fee_policy'], $processorFields['processor_fee_source'], 'succeeded'
        ]);
        $paymentId = (int)$pdo->lastInsertId();
        stripe_link_pending_financial_events($pdo, $paymentId, $paymentIntentId);
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $total = (float)$invoice['total'];
        $status = ($paid >= $total) ? 'paid' : 'partial';
        
        $pdo->prepare('UPDATE invoices SET status=?,amount_paid=?,balance_due=GREATEST(total-?,0),paid_at=CASE WHEN ?="paid" THEN COALESCE(paid_at,NOW()) ELSE NULL END,stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$status, $paid, $paid, $status, $invoiceId]);
        
        // Revoke public links if fully paid
        if ($status === 'paid') {
            pa_public_link_terminalize($pdo, 'invoice', $invoiceId, 'paid');
            
            invoice_complete_linked_contract_if_eligible($pdo, $invoiceId);
        }
        
        $pdo->commit();
        @error_log('[StripeWebhook] Payment recorded for invoice ' . $invoiceId . ': $' . $paymentAmount . ' - status: ' . $status);
        
        // Delivery failures deliberately fail the webhook after the payment is
        // committed. Stripe's retry then enters the idempotent branch above
        // and retries only the missing email side effects.
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
        @error_log('[StripeWebhook] Failed to record payment: ' . $e->getMessage());
        throw $e;
    }
}
