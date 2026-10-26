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
    <p style="margin:6px 0">Finalizing can initiate a payment of <strong><?= $autopayEscape(strtoupper((string)($autopayDocument['currency'] ?? $appConfig['workforce_currency'] ?? 'USD'))) ?> <?= number_format((float)$autopayState['amount'], 2) ?></strong>. Saving a draft does not charge. Bank debits remain processing until confirmed.</p>
    <?php if (!empty($autopayForm) && empty($autopayDocument['finalized_at'])): ?>
      <input form="<?= $autopayEscape($autopayForm) ?>" type="hidden" name="autopay_preview_hash" value="<?= $autopayEscape(pa_autopay_staff_preview_hash($autopayDocument, $autopayState, $autopayType, csrf_token())) ?>">
      <label style="display:block;margin-top:8px">Collection when finalized
        <select data-autopay-choice form="<?= $autopayEscape($autopayForm) ?>" name="autopay_action" required style="display:block;padding:8px;max-width:100%">
          <option value="">Choose before finalizing</option>
          <option value="collect">Initiate authorized AutoPay</option>
          <option value="hold">Hold AutoPay for this invoice</option>
        </select>
      </label>
      <script src="/assets/js/autopay-finalize.js" defer></script>
    <?php endif; ?>
  <?php else: ?>
    <p style="margin:6px 0"><?= $autopayEscape(match ($autopayState['state']) {
        'pending' => 'Payment queued. Another payment cannot be recorded while collection is reserved.',
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
  if (pa_autopay_enabled($appConfig) && !empty($autopayDocument['id']) && in_array($autopayState['state'], ['pending','requires_action'], true)
      && function_exists('user_can') && user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'invoices.send')) {
      $autopayCancelQuery = $pdo->prepare('SELECT state,attempt_count,stripe_payment_intent_id FROM autopay_collections WHERE document_type=? AND document_id=?');
      $autopayCancelQuery->execute([$autopayType, (int)$autopayDocument['id']]);
      $autopayCancelRow = $autopayCancelQuery->fetch(PDO::FETCH_ASSOC) ?: [];
      $autopayCancel = (($autopayCancelRow['state'] ?? '') === 'pending' && (int)($autopayCancelRow['attempt_count'] ?? 1) === 0)
          || (($autopayCancelRow['state'] ?? '') === 'requires_action' && !empty($autopayCancelRow['stripe_payment_intent_id']));
  }
  ?>
  <?php if ($autopayCancel): ?>
    <form method="post" action="/?page=invoice/autopay-cancel" style="margin-top:10px">
      <input type="hidden" name="csrf" value="<?= $autopayEscape(csrf_token()) ?>">
      <input type="hidden" name="document_type" value="<?= $autopayEscape($autopayType) ?>">
      <input type="hidden" name="document_id" value="<?= (int)$autopayDocument['id'] ?>">
      <button class="btn btn-sm" type="submit">Cancel automatic collection</button>
      <small style="display:block;margin-top:5px">Another payment is allowed only after cancellation is confirmed.</small>
    </form>
  <?php elseif (in_array($autopayState['state'], ['processing','held'], true)): ?>
    <p style="margin:6px 0">Reconcile this attempt with Stripe before accepting another payment. An unknown or processing debit cannot be released locally.</p>
  <?php endif; ?>
  <?php if (!empty($_GET['autopay_canceled'])): ?><p style="margin:6px 0">Automatic collection canceled. The invoice can be paid another way.</p><?php endif; ?>
</aside>
<?php endif; ?>
