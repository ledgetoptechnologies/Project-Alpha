<?php
// Staff-only; caller supplies $autopayDocument, $autopayType, and optionally $autopayForm.
require_once __DIR__ . '/../../utils/autopay_staff.php';
$autopayType = $autopayType ?? 'invoice';
$autopayState = pa_autopay_staff_state($pdo, $autopayDocument, $appConfig, $autopayType);
$autopayEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<?php if ($autopayState['enabled']): ?>
<aside class="no-print" style="padding:14px;margin:12px 0;border:1px solid #93c5fd;background:#eff6ff;border-radius:8px" role="status">
  <strong>AutoPay</strong>
  <?php if ($autopayState['eligible']): ?>
    <p style="margin:6px 0"><?= $autopayEscape($autopayState['scope']) ?> · <?= $autopayEscape($autopayState['method']) ?></p>
    <p style="margin:6px 0">AutoPay covers <strong><?= $autopayEscape(strtoupper((string)($autopayDocument['currency'] ?? $appConfig['workforce_currency'] ?? 'USD'))) ?> <?= number_format((float)$autopayState['amount'], 2) ?></strong>. Finalizing reserves this invoice; it does not charge immediately. The client must receive the invoice notice and have at least seven calendar days to review, or until a later due date.</p>
    <?php if (!empty($autopayForm) && empty($autopayDocument['finalized_at'])): ?>
      <input form="<?= $autopayEscape($autopayForm) ?>" type="hidden" name="autopay_preview_hash" value="<?= $autopayEscape(pa_autopay_staff_preview_hash($autopayDocument, $autopayState, $autopayType, csrf_token())) ?>">
      <label style="display:block;margin-top:8px">AutoPay after invoice delivery and review
        <select data-autopay-choice form="<?= $autopayEscape($autopayForm) ?>" name="autopay_action" required style="display:block;padding:8px;max-width:100%">
          <option value="">Choose before finalizing</option>
          <option value="collect">Schedule AutoPay after the seven-day review</option>
          <option value="hold">Hold AutoPay for this invoice</option>
        </select>
      </label>
      <script src="/assets/js/autopay-finalize.js" defer></script>
    <?php endif; ?>
  <?php else: ?>
    <?php if($autopayState['state']==='pending' && ($autopayState['review_status']??'')==='scheduled' && !empty($autopayState['scheduled_at'])): ?>
    <p style="margin:6px 0"><strong>AutoPay scheduled on or after <?= $autopayEscape((new DateTimeImmutable($autopayState['scheduled_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($autopayState['timezone']??'UTC'))->format('F j, Y g:i a T')) ?></strong>. The client can skip or question this invoice before collection begins.</p>
    <?php elseif(($autopayState['review_status']??'')==='awaiting_delivery'): ?>
    <p style="margin:6px 0"><strong>Awaiting invoice email delivery.</strong> Collection cannot start until the notice is delivered and the seven-day review window ends.</p>
    <?php endif; ?>
    <?php if(!empty($autopayState['question_text'])): ?><p style="margin:6px 0"><strong>Client invoice question:</strong> <?= $autopayEscape($autopayState['question_text']) ?></p><?php endif; ?>
    <p style="margin:6px 0"><?= $autopayEscape(match ($autopayState['state']) {
        'pending' => 'AutoPay is reserved for this invoice. Use the cancellation control before recording another payment.',
        'processing' => 'Payment processing. The invoice will be marked paid only after Stripe confirms success.',
        'requires_action' => 'Client action is required. Review the collection before attempting another payment.',
        'succeeded' => 'Automatic payment succeeded. See the payment record for receipts and refunds.',
        'held' => 'Automatic collection is on hold. Review its payment or refund status before taking further action.',
        'failed' => 'Automatic payment failed. Review the failure before collecting payment another way.',
        'canceled', 'cancelled' => 'Automatic collection was canceled.',
        'separate_collection' => 'This document is collected separately or through its project statement.',
        'future_only' => 'AutoPay is authorized for future invoices. This existing invoice will not be charged automatically.',
        default => 'No active AutoPay authorization covers this invoice.',
    }) ?></p>
  <?php endif; ?>
  <?php
  $autopayCancel = false;
  $autopayUnsubmitted=false;$autopayCancelRow=[];
  if (!empty($autopayDocument['id'])) {
      $autopayCancelQuery = $pdo->prepare('SELECT state,attempt_count,stripe_payment_intent_id,financial_hold,lease_token,lease_until FROM autopay_collections WHERE document_type=? AND document_id=?');
      $autopayCancelQuery->execute([$autopayType, (int)$autopayDocument['id']]);
      $autopayCancelRow = $autopayCancelQuery->fetch(PDO::FETCH_ASSOC) ?: [];
      $autopayUnsubmitted=$autopayCancelRow && (int)$autopayCancelRow['attempt_count']===0 && !$autopayCancelRow['stripe_payment_intent_id'] && !$autopayCancelRow['financial_hold'] && !$autopayCancelRow['lease_token'] && !$autopayCancelRow['lease_until'];
  }
  if (pa_autopay_enabled($appConfig) && function_exists('user_can') && user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'invoices.send')) {
      $autopayCancel = (in_array($autopayCancelRow['state'] ?? '', ['pending','held'], true) && $autopayUnsubmitted)
          || (($autopayCancelRow['state'] ?? '') === 'requires_action' && !empty($autopayCancelRow['stripe_payment_intent_id']));
  }
  ?>
  <?php if ($autopayCancel): ?>
    <form method="post" action="/?page=invoice/autopay-cancel" style="margin-top:10px">
      <input type="hidden" name="csrf" value="<?= $autopayEscape(csrf_token()) ?>">
      <input type="hidden" name="document_type" value="<?= $autopayEscape($autopayType) ?>">
      <input type="hidden" name="document_id" value="<?= (int)$autopayDocument['id'] ?>">
      <button class="btn btn-sm" type="submit"><?= $autopayState['state']==='held'?'Cancel AutoPay and handle invoice manually':'Cancel automatic collection' ?></button>
      <small style="display:block;margin-top:5px">Another payment is allowed only after cancellation is confirmed.</small>
    </form>
  <?php elseif ($autopayState['state']==='processing' || ($autopayState['state']==='held' && !$autopayUnsubmitted)): ?>
    <p style="margin:6px 0">Reconcile this attempt with Stripe before accepting another payment. An unknown or processing debit cannot be released locally.</p>
  <?php endif; ?>
  <?php
  $autopayReviewResolvable=false;
  if($autopayState['state']==='held' && in_array($autopayState['review_status']??'', ['question','held'], true) && pa_autopay_enabled($appConfig)){
      require_once __DIR__.'/../../utils/check_report_staff.php';
      if(pa_check_report_staff_access($pdo,$autopayType,(int)$autopayDocument['id'],(int)($_SESSION['user']['id']??0),'invoices.send')){
          $autopayReviewResolvable=$autopayUnsubmitted;
      }
  }
  ?>
  <?php if($autopayReviewResolvable): ?>
    <form method="post" action="/?page=invoice/autopay-review-resolve" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= $autopayEscape(csrf_token()) ?>">
      <input type="hidden" name="document_type" value="<?= $autopayEscape($autopayType) ?>">
      <input type="hidden" name="document_id" value="<?= (int)$autopayDocument['id'] ?>">
      <label style="display:block">Resolution note <textarea name="resolution_note" maxlength="1000" required rows="3" style="display:block;width:100%;font:inherit"></textarea></label>
      <label style="display:block;margin:8px 0"><input type="checkbox" name="confirm_review_resolution" value="1" required> I have resolved this review. Require a fresh invoice notice and seven-day window.</label>
      <button type="submit" class="btn btn-sm">Resolve review and require a new notice</button>
      <small style="display:block">This does not charge or email the client. Send the updated invoice to start a fresh review window. Client opt-outs cannot be overridden here.</small>
    </form>
  <?php endif; ?>
  <?php if(!empty($_GET['autopay_review_resolved'])): ?><p role="status">Review resolved. Send the invoice again; collection remains held until the new notice is delivered and its review period ends.</p><?php endif; ?>
  <?php if (!empty($_GET['autopay_canceled'])): ?><p style="margin:6px 0">Automatic collection canceled. The invoice can be paid another way.</p><?php endif; ?>
</aside>
<?php endif; ?>
