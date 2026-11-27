<?php
require_once __DIR__ . '/../../utils/check_reports.php';
require_once __DIR__ . '/../../utils/check_report_public.php';
require_once __DIR__ . '/../../utils/check_report_staff.php';
$checkActor = (int)($_SESSION['user']['id'] ?? 0);
$checkReport = null; $checkReportNeedsReview = false;
if (pa_check_report_staff_access($pdo, $checkDocumentType, $checkDocumentId, $checkActor)) {
    $checkReport = pa_check_report_lookup($pdo, $checkDocumentType, $checkDocumentId);
    if ($checkReport && !pa_check_report_same_client($checkReport, pa_check_report_current_client($pdo, $checkDocumentType, $checkDocumentId))) {
        $checkReport = null; $checkReportNeedsReview = true;
    }
}
$checkEsc = static fn($value)=>htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<?php if (!empty($_GET['check_action_saved'])): ?><p class="no-print" role="status">Check review saved. Review the current invoice and payment status below.</p><?php endif; ?>
<?php if (!empty($_GET['check_action_error'])): ?><p class="no-print" role="alert"><?= $checkEsc($_GET['check_action_error']) ?></p><?php endif; ?>
<?php if (!empty($_GET['check_receipt_warning'])): ?><p class="no-print" role="alert">The check payment was recorded, but receipt delivery needs review. Do not record another payment.</p><?php endif; ?>
<?php if ($checkReportNeedsReview): ?>
<aside class="no-print" role="alert" style="flex-basis:100%;margin:12px 0;padding:14px;border:1px solid #eab308;border-radius:8px;background:#fffbeb">
  <strong>Mailed-check hold — staff review needed</strong>
  <p>The invoice's client association has changed. This report's details cannot be shown for the current client. The collection hold remains in place. Review the original accounting records before closing the report.</p>
  <?php if (pa_check_report_staff_access($pdo, $checkDocumentType, $checkDocumentId, $checkActor, 'invoices.mark_paid')): ?>
  <form method="post" action="/?page=invoice/pending-check-action" style="display:grid;gap:9px;max-width:620px">
    <input name="csrf" type="hidden" value="<?= $checkEsc(csrf_token()) ?>"><input name="document_type" type="hidden" value="<?= $checkEsc($checkDocumentType) ?>"><input name="document_id" type="hidden" value="<?= (int)$checkDocumentId ?>">
    <label>Review reason <input name="note" maxlength="500" required style="display:block;width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px"></label>
    <label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="confirm_check_action" value="1" required><span>I reviewed the changed client association and want to reject this report for manual follow-up. This does not start an automatic debit.</span></label>
    <button name="action" value="rejected" type="submit" class="btn">Reject report after review</button>
  </form>
  <?php endif; ?>
</aside>
<?php elseif ($checkReport): ?>
<aside class="no-print" style="flex-basis:100%;margin:12px 0;padding:14px;border:1px solid #eab308;border-radius:8px;background:#fffbeb;overflow-wrap:anywhere">
  <strong><?= $checkEsc($checkReport['status'] === 'received' ? 'Mailed check — receipt confirmed' : ($checkReport['status'] === 'rejected' ? 'Mailed check report — rejected' : (!empty($checkReport['overdue']) ? 'Mailed check — overdue review' : 'Check reported — awaiting receipt'))) ?></strong>
  <p>Check number: <strong><?= $checkEsc($checkReport['check_number']) ?></strong> · Reported amount: <strong><?= $checkEsc(pa_check_report_money((int)$checkReport['amount_cents'], (string)$checkReport['currency'])) ?></strong><br>Reported: <?= $checkEsc(pa_check_report_display_date((string)$checkReport['reported_at'], $appConfig)) ?><br>Expected by: <?= $checkEsc(pa_check_report_display_date((string)$checkReport['expected_by'], $appConfig)) ?></p>
  <?php if ($checkReport['status'] === 'pending'): ?>
  <p>This is a payment report, not received income. The invoice remains unpaid until receipt is confirmed. The ten-calendar-day deadline does not trigger an automatic charge; automatic collection remains held for review.</p>
  <?php if (pa_check_report_staff_access($pdo, $checkDocumentType, $checkDocumentId, $checkActor, 'invoices.mark_paid')): ?>
  <form method="post" action="/?page=invoice/pending-check-action" style="display:grid;gap:9px;max-width:620px">
    <input name="csrf" type="hidden" value="<?= $checkEsc(csrf_token()) ?>"><input name="document_type" type="hidden" value="<?= $checkEsc($checkDocumentType) ?>"><input name="document_id" type="hidden" value="<?= (int)$checkDocumentId ?>">
    <label>Review note <input name="note" maxlength="500" style="display:block;width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px"></label>
    <label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="confirm_check_action" value="1" required><span>I have reviewed this report. “Confirm received” records the check payment now; “Reject report” closes it for manual follow-up and does not start an automatic debit.</span></label>
    <?php if (!array_key_exists('payment_receipts_enabled', $appConfig) || !empty($appConfig['payment_receipts_enabled'])): ?><label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="send_receipt" value="1" checked><span>Email a receipt after confirming the check was received</span></label><?php endif; ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><button name="action" value="received" type="submit" class="btn btn-primary">Confirm check received &amp; record payment</button><button name="action" value="rejected" type="submit" class="btn">Reject report — note required</button></div>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</aside>
<?php endif; ?>
