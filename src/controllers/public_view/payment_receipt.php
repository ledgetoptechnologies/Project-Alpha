<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/general_recipient_invoices.php';

if (!rate_limit_check($pdo, 'payment_receipt', 20, 60)) {
    http_response_code(429);
    echo 'Rate limited';
    exit;
}

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT r.*,p.payment_date,p.payment_method,p.reference_number,p.status AS payment_status,
            p.refunded_amount,p.disputed_amount,p.reversed_at,p.reversal_reason,p.job_id,
            i.doc_number,i.invoice_type,i.recipient_presentation_mode,j.job_code,
            COALESCE(c.name,ppt.payer_name) AS client_name,COALESCE(c.email,ppt.payer_email) AS client_email
     FROM payment_receipts r
     JOIN payments p ON p.id=r.payment_id
     LEFT JOIN clients c ON c.id=p.client_id
     LEFT JOIN jobs j ON j.id=p.job_id
     LEFT JOIN processor_payment_transactions ppt ON ppt.payment_id=p.id
     LEFT JOIN invoices i ON i.id=r.invoice_id
     WHERE r.public_token=? AND r.revoked=0 AND r.expires_at>?'
);
$stmt->execute([$token, date('Y-m-d H:i:s')]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$receipt) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}
if (pa_invoice_is_general_recipient($receipt)) {
    // Defense in depth for any receipt row created before the presentation
    // mode was enforced. The invoice's own paid link is the safe receipt.
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

$brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
require_once __DIR__.'/../../utils/payment_receipts.php';
$receiptAutoPayConfig=payment_receipt_autopay_management($pdo,(int)$receipt['payment_id'],$appConfig,'invoice',false);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($receipt['receipt_number']); ?> - <?php echo htmlspecialchars($brand); ?></title>
  <style>
    body{font-family:Arial,sans-serif;background:#f3f4f6;color:#111827;margin:0;padding:24px}.receipt{max-width:720px;margin:0 auto;background:#fff;border:1px solid #d1d5db;padding:32px}.top{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #111827;padding-bottom:18px}.amount{font-size:32px;font-weight:700}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:24px}.label{font-size:12px;color:#6b7280;text-transform:uppercase}.value{font-weight:600;margin-top:4px}.actions{max-width:720px;margin:16px auto;text-align:right}button{padding:10px 16px;border:0;background:#111827;color:#fff;cursor:pointer}@media(max-width:600px){.grid{grid-template-columns:1fr}.top{display:block}.amount{margin-top:16px}}@media print{body{background:#fff;padding:0}.actions{display:none}.receipt{border:0;max-width:none}}
  </style>
</head>
<body>
  <div class="actions"><button type="button" onclick="window.print()">Print or Save PDF</button></div>
  <?php if(!empty($receiptAutoPayConfig['_autopay_manage_url'])): ?><p class="actions"><a href="<?php echo htmlspecialchars($receiptAutoPayConfig['_autopay_manage_url'],ENT_QUOTES,'UTF-8'); ?>">Manage or revoke AutoPay</a> (email verification required)</p><?php endif; ?>
  <main class="receipt">
    <?php if (strtolower((string)$receipt['payment_status']) === 'reversed'): ?>
      <div style="margin:0 0 20px;padding:12px 14px;border:1px solid #fca5a5;background:#fff1f2;color:#881337">
        <strong>Accounting entry reversed.</strong> This payment record was corrected in Project Alpha and is no longer applied to the invoice. No client refund is implied.<?php if (!empty($receipt['reversal_reason'])): ?><div style="margin-top:5px"><?php echo htmlspecialchars((string)$receipt['reversal_reason']); ?></div><?php endif; ?>
      </div>
    <?php elseif ((float)$receipt['refunded_amount'] > 0.005): ?>
      <div style="margin:0 0 20px;padding:12px 14px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e">
        <strong><?php echo (float)$receipt['refunded_amount'] + 0.005 >= (float)$receipt['amount'] ? 'Payment refunded in full.' : 'Payment partially refunded.'; ?></strong>
        A refund of $<?php echo number_format((float)$receipt['refunded_amount'], 2); ?> is recorded for this payment.
      </div>
    <?php elseif ((float)$receipt['disputed_amount'] > 0.005): ?>
      <div style="margin:0 0 20px;padding:12px 14px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e"><strong>Payment disputed.</strong> $<?php echo number_format((float)$receipt['disputed_amount'], 2); ?> is currently excluded from the invoice payment total.</div>
    <?php endif; ?>
    <div class="top"><div><div class="label">Receipt</div><h1><?php echo htmlspecialchars($receipt['receipt_number']); ?></h1><div><?php echo htmlspecialchars($brand); ?></div></div><div><div class="label">Amount received</div><div class="amount">$<?php echo number_format((float)$receipt['amount'], 2); ?></div></div></div>
    <div class="grid">
      <div><div class="label">Received from</div><div class="value"><?php echo htmlspecialchars($receipt['client_name'] ?: 'Processor payment'); ?></div><div><?php echo htmlspecialchars((string)$receipt['client_email']); ?></div></div>
      <div><div class="label">Payment date</div><div class="value"><?php echo htmlspecialchars(date('F j, Y', strtotime((string)$receipt['payment_date']))); ?></div></div>
      <div><div class="label">Invoice</div><div class="value"><?php echo !empty($receipt['invoice_id']) ? htmlspecialchars(pa_invoice_label_from_row($receipt)) : 'Not linked'; ?></div></div>
      <?php if (!empty($receipt['job_id'])): ?><div><div class="label">Service job</div><div class="value"><?php echo htmlspecialchars((string)($receipt['job_code'] ?: ('Job #' . (int)$receipt['job_id']))); ?></div></div><?php endif; ?>
      <div><div class="label">Payment method</div><div class="value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$receipt['payment_method']))); ?></div></div>
      <?php if (!empty($receipt['reference_number'])): ?><div><div class="label">Reference</div><div class="value"><?php echo htmlspecialchars($receipt['reference_number']); ?></div></div><?php endif; ?>
    </div>
  </main>
</body>
</html>
