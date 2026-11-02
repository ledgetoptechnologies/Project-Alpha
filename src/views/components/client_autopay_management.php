<?php
require_once __DIR__ . '/../../utils/autopay_staff_management.php';
$autopayActorId = (int)($_SESSION['user']['id'] ?? 0);
$clientAutoPayRows = [];
$showClientAutoPay = false;
try {
    $showClientAutoPay = pa_autopay_test_context_enabled($appConfig)
        && pa_autopay_staff_can_manage_client($pdo, (int)$client['id'], $autopayActorId);
    if ($showClientAutoPay) $clientAutoPayRows = pa_autopay_staff_client_authorizations($pdo, (int)$client['id'], $appConfig);
} catch (Throwable $error) {
    $showClientAutoPay = false;
}
?>
<?php if ($showClientAutoPay): ?>
<section class="client-card" id="client-autopay">
  <h3>AutoPay — test mode</h3>
  <?php if (!empty($_GET['autopay_disabled'])): ?><p role="status">AutoPay is off for this client. Payments already processing may still complete.</p><?php endif; ?>
  <?php if (!$clientAutoPayRows): ?>
    <p>No active or pending AutoPay authorizations for this client.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($clientAutoPayRows as $clientAutoPayRow): ?>
      <li><?= e($clientAutoPayRow['scope'] === 'agreement' ? 'Agreement #' . (int)$clientAutoPayRow['contract_id'] : 'All future invoices for this client') ?> — <?= e(ucfirst((string)$clientAutoPayRow['status'])) ?> · <?= e($clientAutoPayRow['method_type'] === 'us_bank_account' ? 'Bank account (ACH)' : 'Card') ?></li>
      <?php endforeach; ?>
    </ul>
    <p>This stops future automatic payments for every agreement and invoice scope on this client account. Payments already processing may still complete. Turning off AutoPay does not cancel invoices or refund payments.</p>
    <form method="post" action="/?page=client/autopay-disable">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
      <label style="display:block;margin:12px 0"><input type="checkbox" name="confirm_disable_autopay" value="1" required> I confirm turning off all future automatic payments for this client.</label>
      <button type="submit" class="client-view__button">Turn off AutoPay for this client</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
