<?php
// src/controllers/contract_complete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/autopay_staff.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contract/contracts-list&error=Invalid%20contract'); exit; }
require_record_ownership($pdo, 'contracts', $id);

$pdo->beginTransaction();
try {
  $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $st->execute([$id]);
  $co = $st->fetch(PDO::FETCH_ASSOC);
  if (!$co) throw new Exception('Contract not found');
  if (($co['contract_type'] ?? 'regular') !== 'regular') {
    throw new Exception('Recurring contracts must be ended with their dedicated Terminate action.');
  }

  // Mark completed
  $pdo->prepare('UPDATE contracts SET status=?, completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['completed', $id]);

  // Set invoice due date if not set
  $netDays = (int)($appConfig['net_terms_days'] ?? 30); if ($netDays < 0) { $netDays = 0; }
  $due = date('Y-m-d', strtotime('+' . $netDays . ' days'));
  $inv = $pdo->prepare('SELECT * FROM invoices WHERE contract_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
  $inv->execute([$id]);
  $invoice = $inv->fetch(PDO::FETCH_ASSOC);
  if ($invoice && empty($invoice['due_date'])) {
    $pdo->prepare('UPDATE invoices SET due_date=? WHERE id=?')->execute([$due, (int)$invoice['id']]);
  }
  $autoPayReview = $invoice && pa_autopay_staff_state($pdo, $invoice, $appConfig)['eligible'];
  if ($invoice && !$autoPayReview) {
    invoice_finalize(
      $pdo,
      (int)$invoice['id'],
      $appConfig,
      'contract_complete',
      (int)($_SESSION['user']['id'] ?? 0) ?: null
    );
  }

  $pdo->commit();

  if ($autoPayReview) {
    header('Location: /?page=invoice/invoice-details&id=' . (int)$invoice['id'] . '&autopay_review=1'); exit;
  }

  if ($invoice && !empty($appConfig['invoice_auto_email_on_contract_complete'])) {
    invoice_send_finalized($pdo, (int)$invoice['id'], $appConfig, 'contract_complete');
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contract/contracts-list&error=' . urlencode($e->getMessage()));
  exit;
}

header('Location: /?page=contract/contracts-list&completed=1');
exit;
