<?php
// src/controllers/public_view/public_contract_action.php
// Accept a signed contract PDF via a public tokenized link and save it to uploads.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_contract_action', 30, 60)) {
  http_response_code(429);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><title>Rate limited</title></head><body><h1>Rate limited</h1></body></html>';
  exit;
}
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/contract_billing_start.php';
require_once __DIR__ . '/../../utils/upload_validator.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/recurring_services.php';

$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('public_contract_action', $submitted)) {
  header('Location: /?page=public-doc&error=' . urlencode('Invalid request'));
  exit;
}

$storedFile = null;
try {
  $token = isset($_POST['token']) ? (string)$_POST['token'] : '';
  if ($token === '' || preg_match('/^[a-f0-9]{32,64}$/', $token) !== 1) { throw new Exception('notoken'); }

  // Validate public link
  $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
  $st->execute([$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('notfound'); }
  if ((int)$row['revoked'] === 1 || (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time())) { throw new Exception('expired'); }
  if ($row['document_type'] !== 'contract') { throw new Exception('badtype'); }
  $cid = (int)$row['document_id'];

  // Validate file
  if (empty($_FILES['signed_pdf']) || !is_uploaded_file($_FILES['signed_pdf']['tmp_name'])) {
    header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&error=' . urlencode('Please upload a signed PDF'));
    exit;
  }
  $f = $_FILES['signed_pdf'];

  // Load contract and check allowed status
  $c = $pdo->prepare('SELECT * FROM contracts WHERE id=?');
  $c->execute([$cid]);
  $contract = $c->fetch(PDO::FETCH_ASSOC);
  if (!$contract) { throw new Exception('Not found'); }
  $status = (string)($contract['status'] ?? '');
  if ($status !== 'pending') {
    // if already active or cancelled, do not accept upload
    header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&error=' . urlencode('Contract cannot be uploaded for current status'));
    exit;
  }

  // Store signed PDF in src/uploads directory (same logic as internal contract_sign)
  $internal = __DIR__ . '/../../uploads/signed_contracts';
  $uploadError = null;
  $filename = validate_and_store_upload(
      $f,
      ['application/pdf' => 'pdf'],
      25 * 1024 * 1024,
      $internal,
      $uploadError,
      [
          'reject_archives' => true,
          'require_pdf_header' => true,
          'reject_pdf_active_content' => true,
          'clamav_required' => filter_var(getenv('PUBLIC_UPLOAD_CLAMAV_REQUIRED') ?: '', FILTER_VALIDATE_BOOLEAN),
      ]
  );
  if ($filename === null) {
      header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&error=' . urlencode($uploadError ?: 'Failed to store uploaded file'));
      exit;
  }
  $name = $filename;
  $storedFile = $internal . DIRECTORY_SEPARATOR . $name;
  $publicUrl = '/?page=serve-upload&file=' . rawurlencode('signed_contracts/' . $name);

  // Save path and activate contract
  $pdo->beginTransaction();
  try {
    $contract = pa_contract_lock_public_signing($pdo, $cid, $token);
    $manualStart = ($contract['contract_type'] ?? '') === 'long_term' && !pa_long_term_starts_billing_on_upload($contract);
    $nextInvoiceDate = pa_long_term_starts_billing_on_upload($contract) ? pa_long_term_billing_start_date($pdo, $contract) : null;
    $billingStartSql = '';
    $updateParams = [$publicUrl, $manualStart ? 'pending' : 'active', hash_file('sha256',$internal . DIRECTORY_SEPARATOR . $name)];
    if (pa_long_term_starts_billing_on_upload($contract)) {
      $billingStartSql = ', next_invoice_date=?';
      $updateParams[] = $nextInvoiceDate;
    }
    $updateParams[] = $cid;
    $update = $pdo->prepare("UPDATE contracts SET signed_pdf_path=?,status=?,signed_at=NOW(),signed_revision_number=revision_number,signed_pdf_sha256=?{$billingStartSql} WHERE id=? AND status='pending' AND (signed_pdf_path IS NULL OR signed_pdf_path='')");
    $update->execute($updateParams);
    if ($update->rowCount() !== 1) {
      $storedFile = $internal . DIRECTORY_SEPARATOR . $name;
      if (is_file($storedFile)) {
        @unlink($storedFile);
      }
      throw new RuntimeException('Contract cannot be uploaded for current status');
    }
    if (($contract['contract_type'] ?? '') === 'long_term' && pa_long_term_starts_billing_on_upload($contract)) {
      pa_recurring_services_activate($pdo, $cid, $nextInvoiceDate);
    }
    pa_public_link_terminalize($pdo, 'contract', $cid, 'signed');
    $pdo->commit();
    $storedFile = null;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  // Redirect directly to the terminal status page. The tokenized document link
  // is revoked after signing, so avoid routing the client back through it.
  header('Location: ' . pa_public_link_redirect_path('contract', 'signed'));
  exit;
} catch (Throwable $e) {
  if (is_string($storedFile) && is_file($storedFile)) @unlink($storedFile);
  $t = isset($token) ? (string)$token : '';
  header('Location: /?page=public-doc&token=' . rawurlencode($t) . '&error=' . urlencode('Unable to upload signed contract'));
  exit;
}
