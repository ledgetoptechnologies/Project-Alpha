<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/audit.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../utils/payment_corrections.php';
require_once __DIR__ . '/../utils/stripe_financial_events.php';
require_once __DIR__ . '/../services/StripeService.php';

csrf_verify_post_or_redirect('payments-list');

$paymentId = (int)($_POST['payment_id'] ?? 0);
$amount = round((float)($_POST['amount'] ?? 0), 2);
if ($paymentId <= 0 || $amount <= 0) {
    header('Location: /?page=payments-list&error=' . urlencode('Invalid refund amount.'));
    exit;
}

if (!can_access_record($pdo, 'payments', $paymentId, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=payments-list&error=' . urlencode('Permission denied'));
    exit;
}

try {
    invoice_ensure_payments_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT id, invoice_id, amount, refunded_amount, disputed_amount, surcharge_paid,
               surcharge_refunded, surcharge_refund_amount, status, payment_method,
               processor_provider, processor_payment_id, processor_gross_amount,
               stripe_payment_intent_id, stripe_session_id
        FROM payments
        WHERE id=?
    ');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$payment || strtolower((string)$payment['status']) !== 'succeeded') {
        throw new RuntimeException('Only successful payments can be refunded.');
    }

    if (payment_is_processor_backed($payment)) {
        if (empty($_POST['processor_refund'])) {
            throw new RuntimeException('Confirm that this refund should send money through Stripe.');
        }
        $provider = strtolower(trim((string)($payment['processor_provider'] ?? '')));
        if ($provider !== '' && $provider !== 'stripe') {
            throw new RuntimeException('This connected payment processor is not supported by the Stripe refund action.');
        }

        $requestToken = strtolower(trim((string)($_POST['refund_request_token'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $requestToken)) {
            throw new RuntimeException('The refund request expired. Reload Payments and try again.');
        }

        $processorGross = $payment['processor_gross_amount'] !== null
            ? (float)$payment['processor_gross_amount']
            : (float)$payment['amount'] + max(0.0, (float)$payment['surcharge_paid']);
        $remaining = max(
            0.0,
            $processorGross - (float)$payment['refunded_amount'] - (float)$payment['disputed_amount']
        );
        if ($amount > $remaining + 0.005) {
            throw new RuntimeException('Stripe refund cannot exceed the remaining processor charge amount.');
        }

        $stripe = StripeService::fromAppConfig($appConfig);
        if (!$stripe) {
            throw new RuntimeException('Stripe is not configured.');
        }

        $chargeId = null;
        $processorId = trim((string)($payment['processor_payment_id'] ?? ''));
        $paymentIntentId = trim((string)($payment['stripe_payment_intent_id'] ?? ''));
        if ($paymentIntentId === '' && str_starts_with($processorId, 'pi_')) {
            $paymentIntentId = $processorId;
        } elseif (str_starts_with($processorId, 'ch_')) {
            $chargeId = $processorId;
        }

        if ($paymentIntentId !== '') {
            $intent = $stripe->getPaymentIntentWithBalanceTransaction($paymentIntentId);
            if (is_string($intent['latest_charge'] ?? null)) {
                $intent['latest_charge'] = $stripe->getCharge($intent['latest_charge']);
            } elseif (is_array($intent['charges']['data'][0] ?? null)) {
                $intent['latest_charge'] ??= $intent['charges']['data'][0];
            }
            $chargeId = stripe_refund_charge_id($intent,$paymentIntentId);
        }
        if (!$chargeId || !preg_match('/^(?:ch|py)_[A-Za-z0-9_]+$/D',$chargeId)) {
            throw new RuntimeException('Stripe charge ID could not be resolved for this payment. No refund was created.');
        }

        $reason = (string)($_POST['refund_reason'] ?? 'requested_by_customer');
        if (!in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
            $reason = 'requested_by_customer';
        }
        $refund = $stripe->refundCharge(
            $chargeId,
            (int)round($amount * 100),
            'pa-refund-v1-' . $paymentId . '-' . $requestToken,
            $reason
        );
        stripe_validate_created_refund($refund,$chargeId,(int)round($amount*100),$paymentIntentId!==''?$intent:null);

        // The controller already knows the PA payment. Pass it explicitly so
        // legacy records that only stored a Charge ID reconcile immediately.
        stripe_record_refund($pdo, $refund, $paymentId);
        audit_log($pdo, 'payment.stripe_refund_created', 'payment', $paymentId, [
            'amount' => $amount,
            'invoice_id' => !empty($payment['invoice_id']) ? (int)$payment['invoice_id'] : null,
            'stripe_refund_id' => (string)$refund['id'],
            'stripe_refund_status' => (string)($refund['status'] ?? 'unknown'),
            'reason' => $reason,
        ]);
        header('Location: /?page=payments-list&stripe_refunded=1');
        exit;
    }

    $pdo->beginTransaction();
    $lock = $pdo->prepare('
        SELECT id, invoice_id, amount, refunded_amount, disputed_amount, status
        FROM payments
        WHERE id=?
        FOR UPDATE
    ');
    $lock->execute([$paymentId]);
    $payment = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$payment || strtolower((string)$payment['status']) !== 'succeeded') {
        throw new RuntimeException('Only successful payments can be refunded.');
    }

    $remaining = max(
        0.0,
        (float)$payment['amount'] - (float)$payment['refunded_amount'] - (float)$payment['disputed_amount']
    );
    if ($amount > $remaining + 0.005) {
        throw new RuntimeException('Refund cannot exceed the remaining payment amount.');
    }

    $pdo->prepare('UPDATE payments SET refunded_amount = refunded_amount + ? WHERE id=?')
        ->execute([$amount, $paymentId]);

    $totals = null;
    if (!empty($payment['invoice_id'])) {
        $totals = invoice_refresh_payment_totals($pdo, (int)$payment['invoice_id']);
    }
    $pdo->commit();

    audit_log($pdo, 'payment.manual_refund_recorded', 'payment', $paymentId, [
        'amount' => $amount,
        'invoice_id' => !empty($payment['invoice_id']) ? (int)$payment['invoice_id'] : null,
        'invoice_status' => $totals['status'] ?? null,
    ]);
    header('Location: /?page=payments-list&refunded=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: /?page=payments-list&error=' . urlencode($e->getMessage()));
}
exit;
