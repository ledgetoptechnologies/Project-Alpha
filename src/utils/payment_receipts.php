<?php

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/invoice_lifecycle.php';

/** Return a step-up protected management link only to the actual AutoPay payer. */
function payment_receipt_autopay_management(PDO $pdo,int $paymentId,array $config,string $type='invoice',bool $create=true):array {
    if(empty($config['app_host']))return $config;
    if(!invoice_table_has_column($pdo,'autopay_enrollments','management_token'))return $config;
    $table=$type==='project_invoice'?'project_invoice_payments':'payments';$field=$type==='project_invoice'?'project_invoice_id':'invoice_id';
    $s=$pdo->prepare("SELECT a.id,a.management_token,c.email FROM $table p JOIN autopay_collections ac ON ac.stripe_payment_intent_id=p.stripe_payment_intent_id AND ac.document_id=p.$field AND ac.document_type=? JOIN autopay_enrollments a ON a.id=ac.authorization_id AND a.client_id=ac.client_id JOIN clients c ON c.id=a.client_id WHERE p.id=? AND p.status=\"succeeded\"");$s->execute([$type,$paymentId]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)return $config;
    if(empty($row['management_token'])){
        if(!$create)return $config;
        $pdo->prepare('UPDATE autopay_enrollments SET management_token=? WHERE id=? AND management_token IS NULL')->execute([bin2hex(random_bytes(32)),$row['id']]);$s->execute([$type,$paymentId]);$row=$s->fetch(PDO::FETCH_ASSOC);
    }
    require_once __DIR__.'/invoice_notifications.php';
    $config['_autopay_manage_url']=invoice_notification_public_base($config).'/?page=public-autopay&manage='.rawurlencode($row['management_token']);
    $config['_autopay_manage_email']=$row['email'];return $config;
}

/**
 * Attempt every post-payment email side effect before surfacing failures.
 * Successful deliveries remain safe on retry through their stable message keys.
 */
function payment_email_attempt_all(callable ...$deliveries): void
{
    $failures = [];
    foreach ($deliveries as $delivery) {
        try {
            $delivery();
        } catch (Throwable $error) {
            $failures[] = $error->getMessage();
        }
    }
    if ($failures) {
        throw new RuntimeException('Payment email delivery failed: ' . implode('; ', $failures));
    }
}

/**
 * Create and email one durable Project Alpha receipt per payment.
 */
function payment_receipt_issue(
    PDO $pdo,
    int $paymentId,
    array $appConfig,
    bool $sendEmail = true,
    ?callable $sender = null,
    bool $throwOnEmailFailure = false
): ?array
{
    if (array_key_exists('payment_receipts_enabled', $appConfig) && empty($appConfig['payment_receipts_enabled'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT p.id,p.invoice_id,p.job_id,p.processor_transaction_id,p.amount,p.payment_date,p.payment_method,p.reference_number,
                i.doc_number,i.invoice_type,i.recipient_presentation_mode,j.job_code,
                COALESCE(NULLIF(c.name,""),NULLIF(ppt.payer_name,"")) AS client_name,
                COALESCE(NULLIF(c.email,""),NULLIF(ppt.payer_email,"")) AS email
         FROM payments p
         LEFT JOIN invoices i ON i.id=p.invoice_id
         LEFT JOIN jobs j ON j.id=p.job_id
         LEFT JOIN clients c ON c.id=p.client_id
         LEFT JOIN processor_payment_transactions ppt ON ppt.payment_id=p.id
         WHERE p.id=? AND p.status="succeeded"'
    );
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        return null;
    }
    if (empty($payment['invoice_id']) && !empty($payment['processor_transaction_id'])) {
        return null;
    }

    // The invoice's paid public link is the only external receipt for a
    // general-recipient invoice. Creating this separate receipt would copy the
    // private accounting client's identity and could email it automatically.
    if (pa_invoice_is_general_recipient($payment)) {
        return null;
    }

    $existing = $pdo->prepare('SELECT * FROM payment_receipts WHERE payment_id=?');
    $existing->execute([$paymentId]);
    $receipt = $existing->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        $token = bin2hex(random_bytes(32));
        $number = 'R-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
        $pdo->prepare(
            'INSERT INTO payment_receipts (payment_id,invoice_id,receipt_number,public_token,amount,email_to)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $paymentId,
            $payment['invoice_id'] ?: null,
            $number,
            $token,
            (float)$payment['amount'],
            filter_var((string)$payment['email'], FILTER_VALIDATE_EMAIL) ? $payment['email'] : null,
        ]);
        $existing->execute([$paymentId]);
        $receipt = $existing->fetch(PDO::FETCH_ASSOC);
    }

    if (!$sendEmail || !$receipt || !empty($receipt['emailed_at']) || empty($receipt['email_to'])) {
        return $receipt ?: null;
    }

    $url = '';
    if (!empty($appConfig['public_links_in_email'])) {
        $url = invoice_public_base_url($appConfig) . '/?page=payment-receipt&token=' . rawurlencode((string)$receipt['public_token']);
    }
    $invoiceLabel = !empty($payment['invoice_id']) ? ' for invoice ' . pa_invoice_label_from_row($payment) : '';
    $serviceLabel = empty($payment['invoice_id']) && !empty($payment['job_code'])
        ? ' for service job ' . (string)$payment['job_code']
        : '';
    $subject = 'Payment receipt ' . $receipt['receipt_number'];
    $body = '<p>Hello ' . htmlspecialchars((string)($payment['client_name'] ?: 'there')) . ',</p>'
        . '<p>We received your payment of <strong>$' . number_format((float)$payment['amount'], 2) . '</strong>'
        . htmlspecialchars($invoiceLabel . $serviceLabel) . '.</p>'
        . '<p>Receipt number: <strong>' . htmlspecialchars((string)$receipt['receipt_number']) . '</strong></p>'
        . ($url !== '' ? '<p><a href="' . htmlspecialchars($url) . '">View and print your Project Alpha receipt</a></p>' : '');

    $sender ??= [EmailService::class, 'sendEmail'];
    $appConfig=payment_receipt_autopay_management($pdo,$paymentId,$appConfig);
    if(!empty($appConfig['_autopay_manage_url']) && strcasecmp((string)$receipt['email_to'],(string)($appConfig['_autopay_manage_email']??$receipt['email_to']))===0)$body.='<p><a href="'.htmlspecialchars($appConfig['_autopay_manage_url'],ENT_QUOTES,'UTF-8').'">Manage or revoke AutoPay</a> (email verification required).</p>';
    [$ok, $error] = $sender((string)$receipt['email_to'], $subject, $body, [
        'document_type' => 'notification',
        'document_id' => $paymentId,
        'message_key' => 'payment-receipt:' . $paymentId . ':'
            . hash('sha256', strtolower((string)$receipt['email_to'])),
    ]);
    if ($ok) {
        $pdo->prepare('UPDATE payment_receipts SET emailed_at=NOW() WHERE id=?')->execute([(int)$receipt['id']]);
        $receipt['emailed_at'] = date('Y-m-d H:i:s');
    } else {
        @error_log('[payment_receipts] Receipt email failed for payment ' . $paymentId . ': ' . $error);
        if ($throwOnEmailFailure) {
            throw new RuntimeException('Payment receipt email delivery failed: ' . $error);
        }
    }

    return $receipt;
}

/**
 * Email an idempotent receipt for an aggregate project-invoice payment.
 *
 * Project payments are allocated across child invoices, so issuing a normal
 * payment_receipts row for any one child would show the wrong aggregate amount.
 * The project invoice's configured invoice recipients instead receive one
 * email receipt for the parent payment.
 */
function project_payment_receipt_email_issue(
    PDO $pdo,
    int $projectPaymentId,
    array $appConfig,
    ?callable $sender = null,
    bool $throwOnEmailFailure = false
): int {
    if (array_key_exists('payment_receipts_enabled', $appConfig) && empty($appConfig['payment_receipts_enabled'])) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT pp.id,pp.project_invoice_id,pp.amount,pp.payment_date,
                pi.doc_number,pi.status,pi.total,p.name AS project_name
         FROM project_invoice_payments pp
         JOIN project_invoices pi ON pi.id=pp.project_invoice_id
         JOIN projects p ON p.id=pi.project_id
         WHERE pp.id=? AND pp.status="succeeded"'
    );
    $stmt->execute([$projectPaymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        return 0;
    }

    require_once __DIR__ . '/project_invoice_billing.php';
    $recipients = project_invoice_client_recipients($pdo, (int)$payment['project_invoice_id']);
    $appConfig=payment_receipt_autopay_management($pdo,$projectPaymentId,$appConfig,'project_invoice');
    // Statement delivery preferences cannot opt the actual AutoPay payer out
    // of the receipt or the separate, email-verified management capability.
    $autoPayPayer=trim((string)($appConfig['_autopay_manage_email']??''));
    if(!empty($appConfig['_autopay_manage_url']) && filter_var($autoPayPayer,FILTER_VALIDATE_EMAIL)){
        $recipients[]=['name'=>'there','email'=>$autoPayPayer];
        $unique=[];
        foreach($recipients as $recipient){
            $address=strtolower(trim((string)($recipient['email']??'')));
            if($address!==''&&!isset($unique[$address]))$unique[$address]=$recipient;
        }
        $recipients=array_values($unique);
    }
    if (!$recipients) {
        return 0;
    }

    $sender ??= [EmailService::class, 'sendEmail'];
    $receiptNumber = 'PR-' . str_pad((string)$projectPaymentId, 6, '0', STR_PAD_LEFT);
    $invoiceNumber = (string)($payment['doc_number'] ?: $payment['project_invoice_id']);
    $statusText = (string)$payment['status'] === 'paid' ? 'paid in full' : 'partially paid';
    $subject = 'Payment receipt ' . $receiptNumber . ' for project invoice PI-' . $invoiceNumber;
    $sent = 0;
    $failures = [];

    foreach ($recipients as $recipient) {
        $email = trim((string)($recipient['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $name = trim((string)($recipient['name'] ?? '')) ?: 'there';
        $body = '<p>Hello ' . htmlspecialchars($name) . ',</p>'
            . '<p>We received your payment of <strong>$' . number_format((float)$payment['amount'], 2) . '</strong>'
            . ' for project invoice <strong>PI-' . htmlspecialchars($invoiceNumber) . '</strong>.</p>'
            . '<p>Project: <strong>' . htmlspecialchars((string)$payment['project_name']) . '</strong><br>'
            . 'Payment status: <strong>' . htmlspecialchars($statusText) . '</strong><br>'
            . 'Receipt number: <strong>' . htmlspecialchars($receiptNumber) . '</strong></p>';
        if(!empty($appConfig['_autopay_manage_url']) && strcasecmp($email,(string)($appConfig['_autopay_manage_email']??''))===0)$body.='<p><a href="'.htmlspecialchars($appConfig['_autopay_manage_url'],ENT_QUOTES,'UTF-8').'">Manage or revoke AutoPay</a> (email verification required).</p>';
        [$ok, $error] = $sender($email, $subject, $body, [
            'document_type' => 'notification',
            'document_id' => (int)$payment['project_invoice_id'],
            'message_key' => 'project-payment-receipt:' . $projectPaymentId . ':'
                . hash('sha256', strtolower($email)),
        ]);
        if ($ok) {
            $sent++;
            continue;
        }
        $failures[] = $email . ': ' . $error;
        @error_log('[payment_receipts] Project receipt email failed for payment ' . $projectPaymentId . ': ' . $error);
    }

    if ($failures && $throwOnEmailFailure) {
        throw new RuntimeException('Project payment receipt email delivery failed: ' . implode('; ', $failures));
    }
    return $sent;
}
