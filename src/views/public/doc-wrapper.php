<?php
// src/views/public/doc-wrapper.php
// Expects variables set by controller: $type, $rid, $token, $notice, $err, $pdo, $appConfig, $row (link data)

$type = isset($type) ? (string)$type : '';
$rid = isset($rid) ? (int)$rid : 0;
$token = isset($token) ? (string)$token : '';
$notice = isset($notice) ? $notice : false;
$err = isset($err) ? $err : '';
$row = (isset($row) && is_array($row)) ? $row : [];
$appConfig = (isset($appConfig) && is_array($appConfig)) ? $appConfig : [];

// Get link expiration info
$linkExpiresAt = isset($row['expires_at']) && $row['expires_at'] !== null && $row['expires_at'] !== '' ? $row['expires_at'] : null;
$linkExpireWhenPaid = isset($row['expire_when_paid']) && (int)$row['expire_when_paid'] === 1;
$expirationText = '';

if ($linkExpireWhenPaid) {
    $expirationText = !empty($isGeneralRecipientPublicInvoice)
        ? 'After payment, this link remains available as a receipt for seven days.'
        : 'This link is valid until the invoice is paid in full.';
} elseif ($linkExpiresAt !== null) {
    // Date-based expiration
    $expTime = strtotime($linkExpiresAt);
    if ($expTime !== false) {
        $daysLeft = max(0, (int)ceil(($expTime - time()) / 86400));
        if ($daysLeft <= 0) {
            $expirationText = 'This link expires today.';
        } elseif ($daysLeft === 1) {
            $expirationText = 'This link expires tomorrow.';
        } else {
            $expirationText = 'This link expires in ' . $daysLeft . ' days (' . date('M j, Y', $expTime) . ').';
        }
    }
}
// If neither expire_when_paid nor expires_at is set, no expiration text is shown

// Get invoice payment info if invoice type
$invoiceData = null;
$showPayButton = false;
$calculatedAmountDue = 0;
$surchargeInfo = null;
$autoPayNotice = '';
if (in_array($type, ['invoice', 'project_invoice'], true)) {
    require_once __DIR__ . '/../../services/StripeService.php';
    require_once __DIR__ . '/../../utils/StripeFeeCalculator.php';
    require_once __DIR__ . '/../../utils/document_sender.php';
    require_once __DIR__ . '/../../utils/payment_methods.php';
    require_once __DIR__ . '/../../utils/autopay_status.php';
    $autoPayNotice = pa_autopay_pending_notice($pdo, $rid, $type);
    $stripeConfigured = StripeService::isConfigured($appConfig);
    try {
        $invSt = $type === 'project_invoice'
            ? $pdo->prepare('SELECT status,total,amount_paid,balance_due,NULL AS original_amount,NULL AS surcharge_amount,NULL AS surcharge_type,NULL AS created_by,finalized_at,"direct" AS collection_mode FROM project_invoices WHERE id=?')
            : $pdo->prepare('SELECT status,total,amount_paid,NULL AS original_amount,NULL AS surcharge_amount,NULL AS surcharge_type,created_by,finalized_at,collection_mode FROM invoices WHERE id = ?');
        $invSt->execute([$rid]);
        $invoiceData = $invSt->fetch(PDO::FETCH_ASSOC);
        if ($invoiceData) {
            // Calculate amount paid from payments table for accuracy
            if ($type === 'project_invoice') {
                $amountPaid = (float)($invoiceData['amount_paid'] ?? 0);
            } else {
                $paidSt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount-disputed_amount,0)), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
                $paidSt->execute([$rid]);
                $amountPaid = (float)$paidSt->fetchColumn();
            }
            $invoiceData['amount_paid'] = $amountPaid;
            
            $invStatus = strtolower($invoiceData['status'] ?? '');
            $calculatedAmountDue = max(0, (float)($invoiceData['total'] ?? 0) - $amountPaid);
            $collectionMode = trim((string)($invoiceData['collection_mode'] ?? ''));
            if ($collectionMode === '') {
                $collectionMode = 'direct';
            }
            $showPayButton = $stripeConfigured
                && $autoPayNotice === ''
                && !empty($invoiceData['finalized_at'])
                && $collectionMode === 'direct'
                && in_array($invStatus, ['sent', 'unpaid', 'partial', 'overdue'], true)
                && $calculatedAmountDue > 0;
            if (!$showPayButton) {
                $hideReasons = [];
                if (!$stripeConfigured) { $hideReasons[] = 'stripe_not_configured'; }
                if (empty($invoiceData['finalized_at'])) { $hideReasons[] = 'not_finalized'; }
                if ($collectionMode !== 'direct') { $hideReasons[] = 'collection_mode_' . $collectionMode; }
                if (!in_array($invStatus, ['sent', 'unpaid', 'partial', 'overdue'], true)) { $hideReasons[] = 'status_' . ($invStatus ?: 'blank'); }
                if ($calculatedAmountDue <= 0) { $hideReasons[] = 'no_amount_due'; }
                @error_log('[PublicInvoicePayment] Hiding Stripe payment option: ' . json_encode([
                    'document_type' => $type,
                    'document_id' => $rid,
                    'reasons' => $hideReasons,
                    'status' => $invStatus,
                    'collection_mode' => $collectionMode,
                    'finalized' => !empty($invoiceData['finalized_at']),
                    'amount_due' => $calculatedAmountDue,
                    'stripe_secret_available' => method_exists('StripeService', 'hasSecretKey') ? StripeService::hasSecretKey($appConfig) : false,
                ]));
            }
            
            // Calculate surcharge info
            if ($showPayButton) {
                $surchargeInfo = StripeFeeCalculator::calculateSurcharge($calculatedAmountDue, $appConfig);
            }
        }
    } catch (Throwable $e) { 
        @error_log('[DocWrapper] Error checking invoice: ' . $e->getMessage());
    }
}
?>
<style>
  body { background: #e5e7eb !important; }
  .public-doc-wrap {
    max-width: 850px;
    margin: 32px auto;
    padding: 0 24px 96px;
  }
  .public-header {
    text-align: center;
    margin-bottom: 24px;
  }
  .public-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px;
  }
  .link-expiry {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #fef3c7;
    color: #92400e;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
  }
  .paper-document {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.12), 0 1px 3px rgba(0,0,0,0.08);
    padding: 48px 56px;
    margin-bottom: 24px;
    position: relative;
  }
  .paper-document::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #4f46e5, #7c3aed);
    border-radius: 8px 8px 0 0;
  }
  .payment-banner {
    margin-bottom: 24px;
    padding: 24px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border: 1px solid #93c5fd;
    border-radius: 12px;
    text-align: center;
  }
  .payment-banner h2 {
    font-size: 20px;
    font-weight: 600;
    color: #1e40af;
    margin: 0 0 8px;
  }
  .payment-banner .amount-due {
    font-size: 32px;
    font-weight: 700;
    color: #1e3a8a;
    margin: 12px 0;
  }
  .payment-banner .pay-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 32px;
    background: #4f46e5;
    color: #fff;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 16px;
    box-shadow: 0 4px 12px rgba(79,70,229,0.3);
    transition: all 0.2s;
  }
  .payment-banner .pay-btn:hover {
    background: #4338ca;
    transform: translateY(-1px);
  }
  .notice { margin: 10px 0; padding: 12px 16px; border-radius: 8px; }
  .n-ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .n-err { background: #fff1f2; color: #881337; border: 1px solid #fca5a5; }
  @media (max-width: 640px) {
    body { background: #fff !important; }
    .public-doc-wrap {
      margin: 0 auto;
      padding: 0 12px 56px;
      width: 100%;
      max-width: 100%;
    }
    .public-header {
      margin: 12px 0 14px;
    }
    .public-header h1 {
      font-size: 22px;
    }
    .link-expiry {
      border-radius: 10px;
      padding: 8px 10px;
      text-align: left;
      align-items: flex-start;
    }
    .paper-document {
      padding: 18px 12px;
      margin: 0 -12px 18px;
      border-radius: 0;
      box-shadow: none;
      overflow-x: auto;
    }
    .paper-document::before {
      border-radius: 0;
    }
    .action-banner {
      padding: 16px 12px !important;
      border-radius: 10px !important;
    }
    .action-banner > div {
      flex-wrap: wrap !important;
    }
    .action-banner form {
      width: 100%;
    }
    .action-banner button,
    .payment-banner .pay-btn {
      width: 100%;
      justify-content: center;
    }
    .action-banner input[type="file"] {
      width: 100%;
      max-width: 100%;
    }
  }
</style>

<div class="public-doc-wrap">
  <?php if (!empty($notice)): ?><div class="notice n-ok">Thank you! Your response has been recorded.</div><?php endif; ?>
  <?php if (!empty($err)): ?><div class="notice n-err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

  <!-- Header with expiration info -->
  <div class="public-header">
    <div style="display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
      <a href="/?page=public-doc-pdf&token=<?php echo htmlspecialchars(rawurlencode($token)); ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:8px;background:#111827;color:#fff;text-decoration:none;font-weight:600;font-size:14px">
        Print / PDF
      </a>
    </div>
    <?php if ($expirationText): ?>
      <div class="link-expiry">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        <?php echo htmlspecialchars($expirationText); ?>
      </div>
    <?php endif; ?>
  </div>

  <?php
    // Quote approve/deny actions - show at top
    if ($type === 'quote'):
      $showActions = $showActions ?? false;
      if ($showActions):
        require_once __DIR__ . '/../../utils/csrf_sf.php';
        $csrf = csrf_sf_token('public_quote_action');
  ?>
  <div class="action-banner" style="margin-bottom:24px;padding:20px;background:linear-gradient(135deg, #f0fdf4, #dcfce7);border:1px solid #86efac;border-radius:12px;text-align:center">
    <h2 style="font-size:18px;font-weight:600;color:#166534;margin:0 0 12px">📋 Review This Quote</h2>
    <p style="color:#15803d;margin:0 0 16px">Please review the quote below and approve or deny it.</p>
    <div style="display:flex;gap:12px;justify-content:center">
      <form method="post" action="/?page=public-quote-action" onsubmit="return confirmAction('approve')">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" style="padding:12px 28px;border-radius:8px;border:0;background:#16a34a;color:#fff;font-weight:600;font-size:15px;cursor:pointer;box-shadow:0 2px 8px rgba(22,163,74,0.3)">✓ Approve Quote</button>
      </form>
      <form method="post" action="/?page=public-quote-action" onsubmit="return confirmAction('deny')">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="action" value="deny">
        <button type="submit" style="padding:12px 28px;border-radius:8px;border:0;background:#dc2626;color:#fff;font-weight:600;font-size:15px;cursor:pointer;box-shadow:0 2px 8px rgba(220,38,38,0.3)">✗ Deny Quote</button>
      </form>
    </div>
  </div>
  <script>
  function confirmAction(action) {
    if (action === 'approve') {
      return confirm('Are you sure you want to APPROVE this quote?');
    } else {
      return confirm('Are you sure you want to DENY this quote? This action cannot be undone.');
    }
  }
  </script>
  <?php
      endif;
    endif;
  ?>

  <?php
    // Contract upload form - show at top if pending
    if ($type === 'contract' && !empty($showUpload)):
      require_once __DIR__ . '/../../utils/csrf_sf.php';
      $csrf = csrf_sf_token('public_contract_sign');
  ?>
  <div class="action-banner" style="margin-bottom:24px;padding:20px;background:linear-gradient(135deg, #eff6ff, #dbeafe);border:1px solid #93c5fd;border-radius:12px;text-align:center">
    <h2 style="font-size:18px;font-weight:600;color:#1e40af;margin:0 0 12px">📝 Sign This Contract</h2>
    <p style="color:#3b82f6;margin:0 0 16px">Please review the contract below, then upload a signed copy.</p>
    <form method="post" action="/?page=public-contract-sign" enctype="multipart/form-data" style="display:flex;flex-direction:column;align-items:center;gap:12px">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      <input type="file" name="signed_pdf" accept="application/pdf,.pdf,image/jpeg,.jpg,.jpeg,image/png,.png,image/gif,.gif,image/webp,.webp" required style="padding:8px;border:1px solid #93c5fd;border-radius:8px;background:#fff">
      <button type="submit" style="padding:12px 28px;border-radius:8px;border:0;background:#2563eb;color:#fff;font-weight:600;font-size:15px;cursor:pointer;box-shadow:0 2px 8px rgba(37,99,235,0.3)">📤 Upload Signed Contract</button>
    </form>
    <p style="margin-top:12px;font-size:13px;color:#6b7280">Accepts PDF or image files. Maximum 25 MB. If the file is stored in a cloud drive, download it to your device first, then upload it here.</p>
  </div>
  <?php
    endif;
    
    // Show "signed contract received" message if contract has signed_pdf_path
    if ($type === 'contract' && empty($showUpload)):
      $contractSigned = false;
      try {
        $signedCheck = $pdo->prepare('SELECT signed_pdf_path, status FROM contracts WHERE id = ?');
        $signedCheck->execute([$rid]);
        $contractData = $signedCheck->fetch(PDO::FETCH_ASSOC);
        if ($contractData && !empty($contractData['signed_pdf_path'])) {
          $contractSigned = true;
        }
      } catch (Throwable $e) { /* ignore */ }
      
      if ($contractSigned):
  ?>
  <div style="margin-bottom:24px;padding:16px 20px;background:linear-gradient(135deg, #ecfdf5, #d1fae5);border:1px solid #86efac;border-radius:12px;text-align:center">
    <div style="font-size:16px;font-weight:600;color:#166534">✓ Signed Contract Received</div>
    <p style="color:#15803d;margin:8px 0 0;font-size:14px">Thank you! Your signed contract has been received and is being processed.</p>
  </div>
  <?php
      endif;
    endif;
  ?>

  <?php
    // Show payment banner at TOP for invoices if Stripe is configured and payment is due
    if (in_array($type, ['invoice', 'project_invoice'], true) && $invoiceData):
      $amountDue = $calculatedAmountDue;
      $configuredPaymentMethods = pa_payment_methods_from_config($appConfig);
      $stripePaymentMethod = null;
      $offlinePaymentMethods = [];
      foreach ($configuredPaymentMethods as $method) {
        $methodKey = (string)$method['key'];
        if ($methodKey === 'stripe') {
          if ($showPayButton) {
            $stripePaymentMethod = $method;
          }
          continue;
        }
        $offlinePaymentMethods[] = $method;
      }
      if ($autoPayNotice !== '') echo '<p role="status"><strong>AutoPay:</strong> ' . htmlspecialchars($autoPayNotice) . '</p>';
      if ($autoPayNotice === '' && $amountDue > 0 && ($stripePaymentMethod !== null || !empty($offlinePaymentMethods))):
  ?>
  <div class="payment-section">
    <div class="payment-header">
      <div class="payment-title">Payment Options</div>
      <div class="payment-amount-due">
        <?php if ($stripePaymentMethod !== null && $surchargeInfo && $surchargeInfo['client_pays'] > 0): ?>
          <span class="original-amount">$<?php echo number_format($calculatedAmountDue, 2); ?></span>
          <span class="total-due">$<?php echo number_format($surchargeInfo['new_total'], 2); ?></span>
          <span class="due-label">total due</span>
        <?php else: ?>
          <span class="total-due">$<?php echo number_format($amountDue, 2); ?></span>
          <span class="due-label">due</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($stripePaymentMethod !== null && StripeFeeCalculator::isSurchargeEnabled($appConfig) && $surchargeInfo && $surchargeInfo['client_pays'] > 0): ?>
    <div class="surcharge-notice">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="12" y1="8" x2="12" y2="12"></line>
        <line x1="12" y1="16" x2="12.01" y2="16"></line>
      </svg>
      <div class="surcharge-text">
        <div style="font-weight:600;margin-bottom:4px">
          <strong>Online Card Processing Fee:</strong>
          A surcharge of $<?php echo number_format($surchargeInfo['client_pays'], 2); ?> will be added to your payment.
          <?php echo htmlspecialchars($surchargeInfo['display_text']); ?>
        </div>
        <div style="margin-top:4px;font-size:12px;">
          <small>This fee applies only when paying online by card through Stripe. Other listed payment methods use the invoice amount.</small>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="payment-columns">
      <?php if (!empty($offlinePaymentMethods)): ?>
      <div class="payment-column">
        <div class="payment-column-title">Other ways to pay</div>
        <div class="payment-column-total">$<?php echo number_format($amountDue, 2); ?></div>
        <div class="payment-column-note">Use the invoice amount shown here.</div>
        <?php foreach ($offlinePaymentMethods as $method): ?>
          <?php
            $methodKey = (string)$method['key'];
            $methodLabel = (string)$method['label'];
            $methodDescription = match ($methodKey) {
              'cash' => 'Contact us to arrange an in-person payment.',
              'check', 'cheque' => 'Make checks payable to the business listed on this invoice.',
              'bank', 'bank_transfer', 'ach' => 'Contact us for bank transfer instructions.',
              'card', 'credit_card' => 'Contact us for card payment instructions.',
              default => 'Contact us to use this payment method.',
            };
          ?>
          <div class="payment-mini-option">
            <div class="payment-option-name"><?php echo htmlspecialchars($methodLabel); ?></div>
            <div class="payment-option-desc"><?php echo htmlspecialchars($methodDescription); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($stripePaymentMethod !== null): ?>
      <div class="payment-column payment-column-card">
        <div class="payment-column-title">Pay online by card</div>
        <div class="payment-column-total">
          $<?php echo number_format(($surchargeInfo && $surchargeInfo['client_pays'] > 0) ? (float)$surchargeInfo['new_total'] : $amountDue, 2); ?>
        </div>
        <div class="payment-column-note">Secure checkout through Stripe.</div>
        <a href="/?page=stripe-checkout&token=<?php echo htmlspecialchars(rawurlencode($token)); ?>" class="payment-option primary payment-option-cta">
          <div class="payment-option-details">
            <div class="payment-option-name">Pay by Card</div>
            <div class="payment-option-desc">Credit and debit cards are processed online.</div>
          </div>
          <div class="payment-option-arrow">&rarr;</div>
        </a>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($stripePaymentMethod !== null): ?>
    <div class="payment-footer">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
      </svg>
      Online card payments are secure and encrypted
    </div>
    <?php endif; ?>
  </div>

  <style>
    .payment-section {
      margin-bottom: 24px;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    .payment-header {
      background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
      color: #fff;
      padding: 24px;
      text-align: center;
    }
    .payment-title {
      font-size: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      opacity: 0.9;
      margin-bottom: 8px;
    }
    .payment-amount-due {
      display: flex;
      align-items: baseline;
      justify-content: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .total-due {
      font-size: 36px;
      font-weight: 700;
    }
    .due-label {
      font-size: 16px;
      opacity: 0.8;
    }
    .original-amount {
      font-size: 18px;
      text-decoration: line-through;
      opacity: 0.7;
    }
    .surcharge-notice {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 12px 16px;
      background: #fffbeb;
      border-bottom: 1px solid #fcd34d;
      color: #92400e;
      font-size: 13px;
    }
    .surcharge-notice svg {
      flex-shrink: 0;
      margin-top: 1px;
    }
    .payment-options {
      padding: 16px;
    }
    .payment-columns {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      padding: 16px;
    }
    .payment-column {
      border: 1.5px solid #e5e7eb;
      border-radius: 10px;
      padding: 16px;
      background: #ffffff;
    }
    .payment-column-card {
      border-color: #3b82f6;
      background: #eff6ff;
    }
    .payment-column-title {
      color: #374151;
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .payment-column-total {
      color: #111827;
      font-size: 28px;
      font-weight: 800;
      line-height: 1.1;
    }
    .payment-column-note {
      color: #6b7280;
      font-size: 13px;
      margin: 6px 0 14px;
    }
    .payment-mini-option {
      border-top: 1px solid #e5e7eb;
      padding: 11px 0 0;
      margin-top: 11px;
    }
    .payment-option-cta {
      margin-bottom: 0;
    }
    .payment-option {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      border: 1.5px solid #e5e7eb;
      border-radius: 10px;
      margin-bottom: 10px;
      transition: all 0.2s;
    }
    .payment-option:last-child {
      margin-bottom: 0;
    }
    .payment-option.primary {
      background: #eff6ff;
      border-color: #3b82f6;
      text-decoration: none;
      color: inherit;
      cursor: pointer;
    }
    .payment-option.primary:hover {
      background: #dbeafe;
      border-color: #2563eb;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59,130,246,0.15);
    }
    .payment-option-icon {
      font-size: 24px;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f3f4f6;
      border-radius: 10px;
      flex-shrink: 0;
    }
    .payment-option.primary .payment-option-icon {
      background: #dbeafe;
    }
    .payment-option-details {
      flex: 1;
      text-align: left;
    }
    .payment-option-name {
      font-weight: 600;
      color: #111827;
      font-size: 15px;
    }
    .payment-option-desc {
      font-size: 13px;
      color: #6b7280;
      margin-top: 2px;
    }
    .payment-option-arrow {
      font-size: 20px;
      color: #3b82f6;
      font-weight: 600;
    }
    .payment-footer {
      padding: 12px 16px;
      background: #f9fafb;
      border-top: 1px solid #e5e7eb;
      text-align: center;
      font-size: 12px;
      color: #6b7280;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    @media (max-width: 700px) {
      .payment-columns {
        grid-template-columns: 1fr;
      }
      .total-due {
        font-size: 30px;
      }
    }
  </style>

  <?php endif; // amountDue/paymentMethods ?>
  <?php endif; // type === invoice ?>

  <?php
    $reviewLink = '';
    if (in_array($type, ['invoice', 'project_invoice'], true)) {
      $reviewLink = trim((string)($appConfig['review_link'] ?? ''));
    }
    if ($reviewLink !== '' && filter_var($reviewLink, FILTER_VALIDATE_URL)):
  ?>
  <div style="margin-bottom:24px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center">
    <div style="font-weight:700;color:#111827;margin-bottom:6px">Happy with the work?</div>
    <div style="color:#6b7280;font-size:14px;margin-bottom:12px">A quick review helps future clients know what to expect.</div>
    <a href="<?php echo htmlspecialchars($reviewLink); ?>" target="_blank" rel="noopener" style="display:inline-block;background:#111827;color:#fff;text-decoration:none;border-radius:8px;padding:10px 16px;font-weight:700">Leave a Review</a>
  </div>
  <?php endif; ?>

  <!-- Paper-like document container -->
  <div class="paper-document">
    <?php
      // Use the detail views - they check for PUBLIC_VIEW constant to hide admin controls
      if ($type === 'quote') {
        $quoteType = '';
        try {
          $typeSt = $pdo->prepare('SELECT quote_type FROM quotes WHERE id=? LIMIT 1');
          $typeSt->execute([$rid]);
          $quoteType = (string)($typeSt->fetchColumn() ?: '');
        } catch (Throwable $e) { /* default to regular detail */ }
        require $quoteType === 'long_term'
          ? __DIR__ . '/../pages/quote/long-term-quote-details.php'
          : __DIR__ . '/../pages/quote/quote-details.php';
      } elseif ($type === 'contract') {
        $contractType = '';
        try {
          $typeSt = $pdo->prepare('SELECT contract_type FROM contracts WHERE id=? LIMIT 1');
          $typeSt->execute([$rid]);
          $contractType = (string)($typeSt->fetchColumn() ?: '');
        } catch (Throwable $e) { /* default to regular detail */ }
        require $contractType === 'long_term'
          ? __DIR__ . '/../pages/contract/long-term-contract-details.php'
          : __DIR__ . '/../pages/contract/contract-details.php';
      } elseif ($type === 'invoice') {
        require __DIR__ . '/../pages/invoice/invoice-details.php';
      } elseif ($type === 'project_invoice') {
        require __DIR__ . '/../pages/project/project-invoice-details.php';
      }
    ?>
  </div>

  <!-- Powered by footer -->
  <div style="text-align:center;margin-top:24px;color:#9ca3af;font-size:12px">
    <a href="https://project-alpha.tech" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">Powered by Project Alpha</a>
  </div>
</div>
