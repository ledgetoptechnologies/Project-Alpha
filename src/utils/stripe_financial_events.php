<?php

require_once __DIR__ . '/invoice_lifecycle.php';

function stripe_refresh_invoice_from_payments(PDO $pdo, int $invoiceId): void
{
    if ($invoiceId <= 0) {
        return;
    }
    $invoice = $pdo->prepare('SELECT status FROM invoices WHERE id=?');
    $invoice->execute([$invoiceId]);
    $row = $invoice->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row || in_array((string)($row['status'] ?? ''), ['void','cancelled','draft'], true)) {
        return;
    }
    invoice_refresh_payment_totals($pdo, $invoiceId);
}

function stripe_refresh_payment_totals(PDO $pdo, ?int $paymentId): void
{
    if (!$paymentId) {
        return;
    }

    $pdo->prepare(
        'UPDATE payments p SET
           p.refunded_amount=COALESCE((SELECT SUM(r.amount) FROM stripe_refunds r WHERE r.payment_id=p.id AND r.status="succeeded"),0),
           p.disputed_amount=COALESCE((SELECT SUM(d.amount) FROM stripe_disputes d WHERE d.payment_id=p.id AND d.status IN ("needs_response","under_review","lost","funds_withdrawn")),0)
         WHERE p.id=?'
    )->execute([$paymentId]);

    $invoiceStmt = $pdo->prepare('SELECT invoice_id FROM payments WHERE id=?');
    $invoiceStmt->execute([$paymentId]);
    $invoiceId = (int)($invoiceStmt->fetchColumn() ?: 0);
    if ($invoiceId <= 0) {
        return;
    }

    stripe_refresh_invoice_from_payments($pdo, $invoiceId);
}

function stripe_refresh_project_payment_totals(PDO $pdo, ?int $projectPaymentId): void
{
    if (!$projectPaymentId) {
        return;
    }
    $pdo->prepare(
        'UPDATE project_invoice_payments pp SET
           pp.refunded_amount=COALESCE((SELECT SUM(r.amount) FROM stripe_refunds r WHERE r.project_invoice_payment_id=pp.id AND r.status="succeeded"),0),
           pp.disputed_amount=COALESCE((SELECT SUM(d.amount) FROM stripe_disputes d WHERE d.project_invoice_payment_id=pp.id AND d.status IN ("needs_response","under_review","lost","funds_withdrawn")),0)
         WHERE pp.id=?'
    )->execute([$projectPaymentId]);
    $parent = $pdo->prepare('SELECT project_invoice_id,refunded_amount,disputed_amount FROM project_invoice_payments WHERE id=?');
    $parent->execute([$projectPaymentId]);
    $projectPayment = $parent->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$projectPayment) {
        return;
    }

    $refundRemaining = (float)$projectPayment['refunded_amount'];
    $disputeRemaining = (float)$projectPayment['disputed_amount'];
    $children = $pdo->prepare('SELECT id,invoice_id,amount FROM payments WHERE project_invoice_payment_id=? ORDER BY id DESC');
    $children->execute([$projectPaymentId]);
    foreach ($children->fetchAll(PDO::FETCH_ASSOC) as $child) {
        $amount = (float)$child['amount'];
        $refunded = min($amount, max(0, $refundRemaining));
        $disputed = min(max(0, $amount - $refunded), max(0, $disputeRemaining));
        $pdo->prepare('UPDATE payments SET refunded_amount=?,disputed_amount=? WHERE id=?')
            ->execute([$refunded, $disputed, (int)$child['id']]);
        $refundRemaining -= $refunded;
        $disputeRemaining -= $disputed;
        stripe_refresh_invoice_from_payments($pdo, (int)$child['invoice_id']);
    }
    require_once __DIR__ . '/project_invoice_billing.php';
    project_invoice_refresh_status($pdo, (int)$projectPayment['project_invoice_id']);
}

function stripe_link_pending_financial_events(PDO $pdo, int $paymentId, ?string $paymentIntentId): void
{
    if ($paymentId <= 0 || !$paymentIntentId) {
        return;
    }
    $pdo->prepare('UPDATE stripe_refunds SET payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL')
        ->execute([$paymentId, $paymentIntentId]);
    $pdo->prepare('UPDATE stripe_disputes SET payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL')
        ->execute([$paymentId, $paymentIntentId]);
    stripe_refresh_payment_totals($pdo, $paymentId);
}

function stripe_link_pending_project_financial_events(PDO $pdo, int $projectPaymentId, ?string $paymentIntentId): void
{
    if ($projectPaymentId <= 0 || !$paymentIntentId) {
        return;
    }
    $pdo->prepare('UPDATE stripe_refunds SET project_invoice_payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL AND project_invoice_payment_id IS NULL')
        ->execute([$projectPaymentId, $paymentIntentId]);
    $pdo->prepare('UPDATE stripe_disputes SET project_invoice_payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL AND project_invoice_payment_id IS NULL')
        ->execute([$projectPaymentId, $paymentIntentId]);
    stripe_refresh_project_payment_totals($pdo, $projectPaymentId);
}

/** Resolve the authenticated expanded charge, including bank-payment IDs. */
function stripe_refund_charge_id(array $intent,string $expectedIntentId): string
{
    $charge=$intent['latest_charge']??null;
    if(($intent['object']??'')!=='payment_intent' || ($intent['id']??'')!==$expectedIntentId || ($intent['status']??'')!=='succeeded'
        || !is_bool($intent['livemode']??null) || !is_array($charge) || ($charge['object']??'')!=='charge'
        || !preg_match('/^(?:ch|py)_[A-Za-z0-9_]+$/D',(string)($charge['id']??''))
        || ($charge['payment_intent']??'')!==$expectedIntentId || ($charge['status']??'')!=='succeeded'
        || ($charge['livemode']??null)!==$intent['livemode'] || ($charge['customer']??null)!==($intent['customer']??null)
        || ($charge['amount_captured']??$charge['amount']??null)!==($intent['amount_received']??null) || ($charge['currency']??null)!==($intent['currency']??null))throw new DomainException('Stripe charge identity could not be verified. No refund was created.');
    return $charge['id'];
}

function stripe_validate_created_refund(array $refund,string $chargeId,int $amountCents,?array $intent=null):void
{
    if(($refund['object']??'')!=='refund' || !preg_match('/^(?:re|pyr)_[A-Za-z0-9_]+$/D',(string)($refund['id']??''))
        || ($refund['charge']??'')!==$chargeId || ($refund['amount']??null)!==$amountCents
        || ($intent!==null && (($refund['payment_intent']??'')!==$intent['id'] || (array_key_exists('livemode',$refund) && $refund['livemode']!==$intent['livemode']) || ($refund['currency']??'')!==$intent['currency'])))throw new DomainException('Stripe did not confirm the refund. No local refund was recorded.');
}

/** Signed delivery snapshots can be stale; serialize and retrieve current state. */
function stripe_record_refund_event(PDO $pdo, array $object, callable $retrieve): void
{
    if (str_starts_with((string)($object['id'] ?? ''), 'ch_') || (($object['object']??'')==='charge' && preg_match('/^py_[A-Za-z0-9_]+$/D',(string)($object['id']??'')))) {
        foreach (($object['refunds']['data'] ?? []) as $refund) {
            if (is_array($refund)) stripe_record_refund_event($pdo, $refund, $retrieve);
        }
        return;
    }
    $id = (string)($object['id'] ?? '');
    if (!preg_match('/^(?:re|pyr)_[A-Za-z0-9_]+$/D', $id)) throw new DomainException('Refund identity is invalid.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT stripe_refund_id FROM stripe_refunds WHERE stripe_refund_id=? FOR UPDATE');
        $lock->execute([$id]);
        // Lock before fetching: concurrent deliveries cannot apply an older
        // retrieval after a newer one. Provider failures leave delivery retryable.
        $current = $retrieve($id);
        if (($current['id'] ?? '') !== $id || ($current['object'] ?? '') !== 'refund') {
            throw new DomainException('Refund response identity does not match.');
        }
        stripe_record_refund($pdo, $current);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function stripe_record_refund(
    PDO $pdo,
    array $refund,
    ?int $knownPaymentId = null,
    ?int $knownProjectPaymentId = null
): void
{
    if(str_starts_with((string)($refund['id']??''),'py_') && ($refund['object']??'')!=='charge')throw new DomainException('Charge wrapper identity is invalid.');
    if (str_starts_with((string)($refund['id'] ?? ''), 'ch_') || (($refund['object']??'')==='charge' && preg_match('/^py_[A-Za-z0-9_]+$/D',(string)($refund['id']??'')))) {
        foreach (($refund['refunds']['data'] ?? []) as $item) {
            if (is_array($item)) {
                stripe_record_refund($pdo, $item, $knownPaymentId, $knownProjectPaymentId);
            }
        }
        return;
    }

    $refundId = (string)($refund['id'] ?? '');
    if ($refundId === '') {
        return;
    }
    $paymentIntentId = is_string($refund['payment_intent'] ?? null) ? $refund['payment_intent'] : null;
    $paymentId = $knownPaymentId && $knownPaymentId > 0 ? $knownPaymentId : null;
    $projectPaymentId = $knownProjectPaymentId && $knownProjectPaymentId > 0
        ? $knownProjectPaymentId
        : null;
    if (!$paymentId && !$projectPaymentId && $paymentIntentId) {
        $payment = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id=? LIMIT 1');
        $payment->execute([$paymentIntentId]);
        $paymentId = (int)($payment->fetchColumn() ?: 0) ?: null;
        if (!$paymentId) {
            $projectPayment = $pdo->prepare('SELECT id FROM project_invoice_payments WHERE stripe_payment_intent_id=? LIMIT 1');
            $projectPayment->execute([$paymentIntentId]);
            $projectPaymentId = (int)($projectPayment->fetchColumn() ?: 0) ?: null;
        }
    }
    $pdo->prepare(
        'INSERT INTO stripe_refunds (stripe_refund_id,stripe_payment_intent_id,payment_id,project_invoice_payment_id,amount,status,reason)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE payment_id=COALESCE(payment_id,VALUES(payment_id)),project_invoice_payment_id=COALESCE(project_invoice_payment_id,VALUES(project_invoice_payment_id)),amount=VALUES(amount),status=VALUES(status),reason=VALUES(reason)'
    )->execute([
        $refundId,
        $paymentIntentId,
        $paymentId,
        $projectPaymentId,
        ((float)($refund['amount'] ?? 0)) / 100,
        (string)($refund['status'] ?? 'unknown'),
        isset($refund['reason']) ? (string)$refund['reason'] : null,
    ]);
    stripe_refresh_payment_totals($pdo, $paymentId);
    stripe_refresh_project_payment_totals($pdo, $projectPaymentId);
}

function stripe_record_dispute(PDO $pdo, array $dispute, ?string $eventType = null): void
{
    $disputeId = (string)($dispute['id'] ?? '');
    if ($disputeId === '') {
        return;
    }
    $paymentIntentId = is_string($dispute['payment_intent'] ?? null) ? $dispute['payment_intent'] : null;
    $paymentId = null;
    $projectPaymentId = null;
    if ($paymentIntentId) {
        $payment = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id=? LIMIT 1');
        $payment->execute([$paymentIntentId]);
        $paymentId = (int)($payment->fetchColumn() ?: 0) ?: null;
        if (!$paymentId) {
            $projectPayment = $pdo->prepare('SELECT id FROM project_invoice_payments WHERE stripe_payment_intent_id=? LIMIT 1');
            $projectPayment->execute([$paymentIntentId]);
            $projectPaymentId = (int)($projectPayment->fetchColumn() ?: 0) ?: null;
        }
    }
    $evidenceDue = !empty($dispute['evidence_details']['due_by'])
        ? date('Y-m-d H:i:s', (int)$dispute['evidence_details']['due_by'])
        : null;
    $storedStatus = (string)($dispute['status'] ?? 'unknown');
    if ($eventType === 'charge.dispute.funds_withdrawn') {
        $storedStatus = 'funds_withdrawn';
    } elseif ($eventType === 'charge.dispute.funds_reinstated') {
        $storedStatus = 'funds_reinstated';
    }
    $pdo->prepare(
        'INSERT INTO stripe_disputes (stripe_dispute_id,stripe_payment_intent_id,payment_id,project_invoice_payment_id,amount,status,reason,evidence_due_at)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE payment_id=VALUES(payment_id),project_invoice_payment_id=VALUES(project_invoice_payment_id),amount=VALUES(amount),status=VALUES(status),reason=VALUES(reason),evidence_due_at=VALUES(evidence_due_at)'
    )->execute([
        $disputeId,
        $paymentIntentId,
        $paymentId,
        $projectPaymentId,
        ((float)($dispute['amount'] ?? 0)) / 100,
        $storedStatus,
        isset($dispute['reason']) ? (string)$dispute['reason'] : null,
        $evidenceDue,
    ]);
    stripe_refresh_payment_totals($pdo, $paymentId);
    stripe_refresh_project_payment_totals($pdo, $projectPaymentId);
}
