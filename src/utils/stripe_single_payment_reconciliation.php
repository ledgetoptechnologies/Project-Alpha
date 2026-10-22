<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/stripe_invoice_payment_amounts.php';
require_once __DIR__ . '/stripe_invoice_payment_state.php';
require_once __DIR__ . '/invoice_lifecycle.php';

/** Strictly scoped repair, not a payment capture or a historical import. */
function stripe_single_payment_evidence(array $pi, array $context, array $invoice, array $payment): array
{
    $reject = static function (): never { throw new RuntimeException('Stripe settlement evidence does not match this payment; manual review is required.'); };
    $piId = (string)($payment['stripe_payment_intent_id'] ?? '');
    if (!preg_match('/\Api_[A-Za-z0-9_]+\z/D', $piId)
        || ($pi['object'] ?? '') !== 'payment_intent' || ($pi['id'] ?? '') !== $piId
        || ($pi['status'] ?? '') !== 'succeeded' || !is_bool($pi['livemode'] ?? null)
        || !is_bool($context['livemode'] ?? null) || $pi['livemode'] !== $context['livemode']
        || !preg_match('/\Aacct_[A-Za-z0-9]+\z/D', (string)($context['account_id'] ?? ''))
        || !empty($pi['on_behalf_of']) || !empty($pi['transfer_data'])
        || !empty($pi['application']) || !empty($pi['application_fee_amount'])) $reject();
    $metadata = $pi['metadata'] ?? null;
    if (!is_array($metadata) || !empty($metadata['pa_project_invoice_id']) || !empty($metadata['project_invoice_id'])) $reject();
    $identityFound = false;
    foreach (['pa_invoice_id', 'invoice_id'] as $key) {
        if (!array_key_exists($key, $metadata)) continue;
        if (!is_string($metadata[$key]) || $metadata[$key] !== (string)$invoice['id']) $reject();
        $identityFound = true;
    }
    if (!$identityFound) $reject();
    foreach (['pa_client_id', 'client_id'] as $key) {
        if (array_key_exists($key, $metadata) && $metadata[$key] !== (string)$invoice['client_id']) $reject();
    }
    if ((int)$payment['invoice_id'] !== (int)$invoice['id'] || (int)$payment['client_id'] !== (int)$invoice['client_id']) $reject();
    if (!empty($invoice['stripe_customer_id']) && ($pi['customer'] ?? null) !== $invoice['stripe_customer_id']) $reject();
    $currency = strtoupper((string)($invoice['currency'] ?? ''));
    // This repair intentionally supports only currencies with two decimal minor units.
    if (!in_array($currency, ['USD', 'CAD', 'EUR', 'GBP', 'AUD', 'NZD', 'CHF'], true)
        || ($pi['currency'] ?? '') !== strtolower($currency)) $reject();
    $amounts = stripe_invoice_payment_amounts($pi, 'payment_intent');
    $invoiceCents = stripe_invoice_payment_metadata_cents(['total' => (string)$invoice['total']], ['total']);
    // Partial captures, overpayments and client surcharges require separate review.
    if ($amounts['payment_cents'] !== $invoiceCents || $amounts['surcharge_cents'] !== 0
        || ($pi['amount'] ?? null) !== $amounts['gross_cents']) $reject();
    $charge = $pi['latest_charge'] ?? null;
    if (!is_array($charge) || ($charge['object'] ?? '') !== 'charge'
        || !preg_match('/\Ach_[A-Za-z0-9_]+\z/D', (string)($charge['id'] ?? ''))
        || ($charge['payment_intent'] ?? '') !== $piId || ($charge['paid'] ?? null) !== true
        || ($charge['captured'] ?? null) !== true || ($charge['livemode'] ?? null) !== $context['livemode']
        || ($charge['amount'] ?? null) !== $amounts['gross_cents'] || ($charge['currency'] ?? '') !== strtolower($currency)
        || ($charge['amount_refunded'] ?? null) !== 0 || ($charge['disputed'] ?? null) !== false
        || !is_int($charge['created'] ?? null) || $charge['created'] <= 0 || $charge['created'] > time() + 300
        || !empty($charge['failure_code']) || !empty($charge['on_behalf_of']) || !empty($charge['transfer_data'])
        || !empty($charge['application']) || !empty($charge['application_fee'])
        || !empty($charge['refunds']['data']) || !empty($charge['refunds']['has_more'])) $reject();
    $balance = $charge['balance_transaction'] ?? null;
    if (!is_array($balance) || ($balance['object'] ?? '') !== 'balance_transaction'
        || !preg_match('/\Atxn_[A-Za-z0-9_]+\z/D', (string)($balance['id'] ?? ''))
        || ($balance['source'] ?? '') !== $charge['id'] || ($balance['currency'] ?? '') !== strtolower($currency)
        || ($balance['amount'] ?? null) !== $amounts['gross_cents']
        || !is_int($balance['fee'] ?? null) || $balance['fee'] < 0 || $balance['fee'] > $balance['amount']
        || !is_int($balance['net'] ?? null) || $balance['net'] !== $balance['amount'] - $balance['fee']) $reject();
    if (!empty($payment['processor_payment_id']) && $payment['processor_payment_id'] !== $piId) $reject();
    return $amounts;
}

/** No email, Stripe writes, new ledger rows, or changes to global settings. */
function stripe_reconcile_existing_payment(PDO $pdo, StripeService $stripe, array $appConfig, int $paymentId, int $actorId): array
{
    if ($paymentId <= 0 || $actorId <= 0 || $pdo->inTransaction()) throw new RuntimeException('Invalid reconciliation context.');
    $stmt = $pdo->prepare('SELECT p.*,i.stripe_session_id AS invoice_checkout_session FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.id=?');
    $stmt->execute([$paymentId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before || empty($before['invoice_id']) || ($before['payment_method'] ?? '') !== 'stripe'
        || !in_array($before['status'] ?? '', ['failed', 'succeeded'], true)
        || !preg_match('/\Api_[A-Za-z0-9_]+\z/D', (string)($before['stripe_payment_intent_id'] ?? ''))) {
        throw new RuntimeException('This row is not an existing invoice Stripe attempt.');
    }
    $invoiceId = (int)$before['invoice_id'];
    // Provider evidence is fetched before taking locks; both records are rechecked below.
    $session = !empty($before['invoice_checkout_session']) ? $stripe->getCheckoutSession($before['invoice_checkout_session']) : null;
    $context = $stripe->getReconciliationContext();
    $pi = $stripe->getPaymentIntentWithBalanceTransaction($before['stripe_payment_intent_id']);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT i.*,c.stripe_customer_id FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || !$payment || (int)$payment['invoice_id'] !== $invoiceId
            || $payment['stripe_payment_intent_id'] !== $before['stripe_payment_intent_id']
            || (int)$payment['client_id'] !== (int)$before['client_id']
            || !in_array($invoice['status'], ['sent', 'unpaid', 'partial', 'overdue', 'paid'], true)
            || !in_array($invoice['collection_mode'] ?? '', ['', 'direct'], true)
            || pa_invoice_is_general_recipient($invoice) || empty($invoice['finalized_at']) || !empty($payment['job_id']) || !empty($payment['project_invoice_payment_id'])
            || ($payment['payment_method'] ?? '') !== 'stripe'
            || !in_array((string)($payment['processor_provider'] ?? ''), ['', 'stripe'], true)
            || !empty($payment['reversed_at']) || (float)$payment['refunded_amount'] !== 0.0
            || (float)$payment['disputed_amount'] !== 0.0 || (float)$payment['surcharge_paid'] !== 0.0
            || !empty($payment['surcharge_refunded']) || (float)($payment['surcharge_refund_amount'] ?? 0) !== 0.0) {
            throw new RuntimeException('Payment or invoice changed, or needs a different reconciliation path.');
        }
        $amounts = stripe_single_payment_evidence($pi, $context, $invoice, $payment);
        if (!empty($invoice['stripe_session_id']) && ($invoice['stripe_session_id'] !== ($before['invoice_checkout_session'] ?? null)
            || !is_array($session) || ($session['id'] ?? '') !== $invoice['stripe_session_id']
            || ($session['payment_intent'] ?? '') !== $pi['id'] || ($session['status'] ?? '') !== 'complete'
            || ($session['payment_status'] ?? '') !== 'paid')) {
            throw new RuntimeException('Another or unverified Checkout session requires manual review.');
        }
        foreach (['stripe_refunds', 'stripe_disputes'] as $table) {
            $events = $pdo->prepare("SELECT id FROM {$table} WHERE stripe_payment_intent_id=? OR payment_id=? FOR UPDATE");
            $events->execute([$pi['id'], $paymentId]);
            if ($events->fetchColumn() !== false) throw new RuntimeException('Refund or dispute history requires manual review.');
        }
        $assigned = $pdo->prepare('SELECT id FROM project_invoice_items WHERE invoice_id=? FOR UPDATE');
        $assigned->execute([$invoiceId]);
        if ($assigned->fetchColumn() !== false) throw new RuntimeException('Assigned project invoice requires its own reconciliation.');
        // Refuse ambiguity with any other settled principal instead of creating credit.
        $others = $pdo->prepare('SELECT id FROM payments WHERE invoice_id=? AND id<>? AND status="succeeded" AND amount>0 FOR UPDATE');
        $others->execute([$invoiceId, $paymentId]);
        if ($others->fetchColumn() !== false) throw new RuntimeException('Other settled payments require manual review.');
        $existing = stripe_invoice_existing_payment($pdo, $paymentId, $invoiceId, $pi['id']);
        if (!$existing['transition_required']) {
            if (stripe_invoice_payment_metadata_cents(['amount' => (string)$payment['amount']], ['amount']) !== $amounts['payment_cents']
                || $invoice['status'] !== 'paid' || (float)$invoice['amount_paid'] !== $amounts['payment_amount']
                || (float)$invoice['balance_due'] !== 0.0 || empty($invoice['paid_at'])) {
                throw new RuntimeException('Existing settlement differs; manual review is required.');
            }
            $pdo->commit();
            return ['payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'status' => 'unchanged'];
        }
        if ($invoice['status'] === 'paid') throw new RuntimeException('Invoice is already paid without this settlement; manual review is required.');
        $transaction = $stripe->normalizePaymentIntentForImport($pi);
        stripe_invoice_promote_failed_payment($pdo, $paymentId, $invoiceId, $pi['id'], null,
            $amounts['payment_amount'], 0, $transaction, $appConfig);
        $settledAt = gmdate('Y-m-d H:i:s', $pi['latest_charge']['created']);
        $pdo->prepare('UPDATE payments SET payment_date=? WHERE id=?')->execute([substr($settledAt, 0, 10), $paymentId]);
        $pdo->prepare('UPDATE invoices SET paid_at=COALESCE(paid_at,?) WHERE id=?')->execute([$settledAt, $invoiceId]);
        $state = invoice_refresh_payment_totals($pdo, $invoiceId, false);
        if ($state['status'] !== 'paid') throw new RuntimeException('Reconciled settlement did not fully settle this invoice.');
        $pdo->prepare('UPDATE invoices SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')->execute([$invoiceId]);
        pa_public_link_terminalize($pdo, 'invoice', $invoiceId, 'paid');
        $links = $pdo->prepare('SELECT id FROM public_links WHERE document_type="invoice" AND document_id=? AND revoked=0 FOR UPDATE');
        $links->execute([$invoiceId]);
        if ($links->fetchColumn() !== false) throw new RuntimeException('Invoice public-link terminalization failed.');
        invoice_complete_linked_contract_if_eligible($pdo, $invoiceId);
        // Unlike best-effort audit_log(), this evidence must commit with the ledger.
        $pdo->prepare('INSERT INTO system_audit (user_id,action,entity_type,entity_id,details) VALUES (?,"payment.stripe_reconciled","payment",?,?)')
            ->execute([$actorId, $paymentId, json_encode([
                'invoice_id' => $invoiceId, 'payment_intent_id' => $pi['id'], 'stripe_account_id' => $context['account_id'],
                'livemode' => $context['livemode'], 'principal_cents' => $amounts['payment_cents'],
                'currency' => $invoice['currency'], 'charge_id' => $pi['latest_charge']['id'],
                'balance_transaction_id' => $pi['latest_charge']['balance_transaction']['id'],
                'processor_action' => 'read_only', 'emails_sent' => false,
            ], JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return ['payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'status' => 'reconciled'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
