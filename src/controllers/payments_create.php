<?php
// src/controllers/payments_create.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/audit.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../utils/project_invoice_billing.php';
require_once __DIR__ . '/../services/ManualPaymentJobService.php';

use App\Services\ManualPaymentJobService;

csrf_verify_post_or_redirect('payments/payments-create');

$payment_scope = (string)($_POST['payment_scope'] ?? 'invoice');
if (!in_array($payment_scope, ['invoice', 'project_invoice', 'manual'], true)) { $payment_scope = 'invoice'; }
$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$project_invoice_id = (int)($_POST['project_invoice_id'] ?? 0);
$client_id_input = (int)($_POST['client_id'] ?? 0);
$job_id_input = (int)($_POST['job_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = trim((string)($_POST['method'] ?? 'card'));
$check_number = trim((string)($_POST['reference_number'] ?? $_POST['check_number'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$payment_date = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
$paid_in_advance = !empty($_POST['paid_in_advance']);
$send_receipt = !empty($_POST['send_receipt']);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Invalid payment date'));
  exit;
}

// If Stripe is selected, redirect to Stripe checkout
if (strtolower($method) === 'stripe') {
  if ($payment_scope === 'manual') {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Stripe payments must be tied to an invoice.'));
    exit;
  }
  if ($payment_scope === 'project_invoice') {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Use the project statement payment link to collect a Stripe payment.'));
    exit;
  }
  if ($invoice_id <= 0) {
    header('Location: /?page=payments/payments-create&error=Please%20select%20an%20invoice');
    exit;
  }
  if (!can_access_record($pdo, 'invoices', $invoice_id, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Permission denied'));
    exit;
  }
  // Keep the already CSRF-verified POST when handing off to checkout creation.
  $_POST['invoice_id'] = $invoice_id;
  $_POST['return_url'] = '/?page=payments/payments-create';
  require __DIR__ . '/stripe/stripe_charge.php';
  exit;
}

if ($amount <= 0) {
  header('Location: /?page=payments/payments-create&error=Invalid%20input');
  exit;
}

if ($payment_scope === 'project_invoice') {
  if ($project_invoice_id <= 0) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Select a project statement.'));
    exit;
  }
  $projectStmt = $pdo->prepare(
    'SELECT pi.project_id,pi.balance_due,pi.status,pi.finalized_at
     FROM project_invoices pi WHERE pi.id=?'
  );
  $projectStmt->execute([$project_invoice_id]);
  $projectInvoice = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $projectId = (int)($projectInvoice['project_id'] ?? 0);
  require_record_ownership($pdo, 'projects', $projectId);
  if (empty($projectInvoice['finalized_at']) || !in_array((string)($projectInvoice['status'] ?? ''), ['sent', 'unpaid', 'partial'], true)) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Finalize the project statement before recording payment.'));
    exit;
  }
  if ($amount > (float)($projectInvoice['balance_due'] ?? 0) + 0.005) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Payment cannot exceed the project statement balance.'));
    exit;
  }
  if (strtolower($method) === 'check' && $check_number === '') {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Check number is required.'));
    exit;
  }
  try {
    invoice_expire_active_checkout($pdo, 'project_invoices', $project_invoice_id, $appConfig);
    $projectPaymentId = project_invoice_record_manual_payment(
      $pdo, $project_invoice_id, $amount, $method, $check_number, $notes, $payment_date
    );
    if ($projectPaymentId === null) {
      throw new RuntimeException('Could not allocate the project statement payment.');
    }
  } catch (Throwable $e) {
    @error_log('[PaymentsCreate] Project statement payment error: ' . $e->getMessage());
    header('Location: /?page=payments/payments-create&error=' . urlencode($e->getMessage()));
    exit;
  }
  try {
    audit_log($pdo, 'payment.recorded', 'project_invoice', $project_invoice_id, [
      'project_payment_id' => $projectPaymentId,
      'amount' => $amount,
      'method' => $method,
    ]);
  } catch (Throwable $auditError) {
    @error_log('[PaymentsCreate] Project statement payment audit failed after commit: ' . $auditError->getMessage());
  }
  if ($send_receipt) {
    try {
      require_once __DIR__ . '/../utils/payment_receipts.php';
      project_payment_receipt_email_issue($pdo, $projectPaymentId, $appConfig ?? []);
    } catch (Throwable $receiptError) {
      @error_log('[PaymentsCreate] Project statement receipt failed after commit: ' . $receiptError->getMessage());
    }
  }
  header('Location: /?page=payments/payments-list&saved=1');
  exit;
}

if ($payment_scope === 'manual') {
  $userId = (int)($_SESSION['user']['id'] ?? 0);
  $job = null;
  if ($job_id_input > 0) {
    try {
      $job = (new ManualPaymentJobService($pdo))->accessibleJob($job_id_input, $userId);
    } catch (Throwable $e) {
      header('Location: /?page=payments/payments-create&error=' . urlencode($e->getMessage()));
      exit;
    }
  }
  if ($client_id_input > 0 && !can_access_record($pdo, 'clients', $client_id_input, $userId)) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Permission denied'));
    exit;
  }
  $jobClientId = (int)($job['client_id'] ?? 0);
  if ($client_id_input > 0 && $jobClientId > 0 && $client_id_input !== $jobClientId) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('The selected client does not match the service job.'));
    exit;
  }
  $client_id = $client_id_input > 0 ? $client_id_input : ($jobClientId > 0 ? $jobClientId : null);
  if (strtolower($method) === 'check' && empty($check_number)) {
    header('Location: /?page=payments/payments-create&error=Check%20number%20is%20required');
    exit;
  }

  $organization_id = !empty($job['organization_id']) ? (int)$job['organization_id'] : null;
  $clientEmail = null;
  if ($client_id !== null) {
    $clientStmt = $pdo->prepare('SELECT organization_id,email FROM clients WHERE id=?');
    $clientStmt->execute([$client_id]);
    $clientRecord = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $organization_id ??= (int)($clientRecord['organization_id'] ?? 0) ?: null;
    $clientEmail = filter_var((string)($clientRecord['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;
  }
  $send_receipt = $send_receipt && $clientEmail !== null;
  invoice_ensure_payments_schema($pdo);

  if ($job_id_input > 0 && !acl_table_has_column($pdo, 'payments', 'job_id')) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('The service-job payment migration has not been applied yet.'));
    exit;
  }

  $pdo->beginTransaction();
  try {
    $defaultNotes = $job !== null
      ? 'Manual payment for service job ' . (string)$job['job_code']
      : 'Standalone manual income';
    if (acl_table_has_column($pdo, 'payments', 'job_id')) {
      $insert = $pdo->prepare('
        INSERT INTO payments
          (client_id, invoice_id, job_id, contract_id, organization_id, amount, payment_method, reference_number, notes, status, payment_date)
        VALUES (?, NULL, ?, NULL, ?, ?, ?, ?, ?, "succeeded", ?)
      ');
      $insert->execute([
        $client_id,
        $job_id_input > 0 ? $job_id_input : null,
        $organization_id,
        $amount,
        $method ?: 'cash',
        $check_number ?: null,
        $notes !== '' ? $notes : $defaultNotes,
        $payment_date,
      ]);
    } else {
      $insert = $pdo->prepare('
        INSERT INTO payments
          (client_id, invoice_id, contract_id, organization_id, amount, payment_method, reference_number, notes, status, payment_date)
        VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, "succeeded", ?)
      ');
      $insert->execute([
        $client_id,
        $organization_id,
        $amount,
        $method ?: 'cash',
        $check_number ?: null,
        $notes !== '' ? $notes : $defaultNotes,
        $payment_date,
      ]);
    }
    $paymentId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $expectedCharge = !empty($job['expected_charge_known']) ? (float)$job['expected_charge'] : null;
    audit_log($pdo, 'payment.manual_recorded', 'payment', $paymentId, [
      'amount' => $amount,
      'method' => $method,
      'client_id' => $client_id,
      'job_id' => $job_id_input > 0 ? $job_id_input : null,
      'expected_charge' => $expectedCharge,
      'variance' => $expectedCharge !== null ? round($amount - $expectedCharge, 2) : null,
    ]);

    if (!empty($appConfig['payment_receipts_enabled'])) {
      try {
        require_once __DIR__ . '/../utils/payment_receipts.php';
        payment_receipt_issue($pdo, $paymentId, $appConfig ?? [], $send_receipt);
      } catch (Throwable $receiptError) {
        @error_log('[PaymentsCreate] Manual receipt issue failed: ' . $receiptError->getMessage());
      }
    }
  } catch (Throwable $e) {
    $pdo->rollBack();
    @error_log('[PaymentsCreate] Manual payment error: ' . $e->getMessage());
    $message = trim($e->getMessage()) !== '' ? substr($e->getMessage(), 0, 180) : 'Failed to save payment';
    header('Location: /?page=payments/payments-create&error=' . urlencode($message));
    exit;
  }

  header('Location: /?page=payments/payments-list&saved=1');
  exit;
}

if ($invoice_id <= 0) {
  header('Location: /?page=payments/payments-create&error=Invalid%20input');
  exit;
}

// Fetch invoice details to get client_id and contract_id
$invStmt = $pdo->prepare('SELECT client_id,contract_id,organization_id,status,finalized_at,total,collection_mode FROM invoices WHERE id=?');
$invStmt->execute([$invoice_id]);
$invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
if (!can_access_record($pdo, 'invoices', $invoice_id, (int)($_SESSION['user']['id'] ?? 0))) {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Permission denied'));
  exit;
}
if (!$invoice || ($invoice['status'] ?? '') === 'draft' || empty($invoice['finalized_at'])) {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Finalize the invoice before recording payment.'));
  exit;
}
if (($invoice['collection_mode'] ?? 'direct') !== 'direct') {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Record payment from the project invoice so it is allocated correctly.'));
  exit;
}

$client_id = (int)($invoice['client_id'] ?? 0);
$contract_id = !empty($invoice['contract_id']) ? (int)$invoice['contract_id'] : null;
$organization_id = !empty($invoice['organization_id']) ? (int)$invoice['organization_id'] : null;
invoice_ensure_payments_schema($pdo);
$paidStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount-disputed_amount,0)),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
$paidStmt->execute([$invoice_id]);
$outstanding = max(0.0, (float)$invoice['total'] - (float)$paidStmt->fetchColumn());
if ($amount > $outstanding + 0.005) {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Payment cannot exceed the outstanding balance.'));
  exit;
}
try {
  invoice_expire_active_checkout($pdo, 'invoices', $invoice_id, $appConfig);
} catch (Throwable $e) {
  header('Location: /?page=payments/payments-create&error=' . urlencode($e->getMessage()));
  exit;
}

// Validate check number if method is check
if (strtolower($method) === 'check' && empty($check_number)) {
  header('Location: /?page=payments/payments-create&error=Check%20number%20is%20required');
  exit;
}

$pdo->beginTransaction();
try {
  $result = invoice_record_locked_payment(
      $pdo,
      $invoice_id,
      $amount,
      $method ?: 'cash',
      $check_number ?: null,
      null,
      [
          'organization_id' => $organization_id,
          'complete_contract_when_paid' => !$paid_in_advance,
          'source' => 'manual_payment',
          'payment_date' => $payment_date,
      ]
  );
  $paymentId = (int)$result['payment_id'];
  $status = (string)$result['status'];

  $pdo->commit();

  audit_log($pdo, 'payment.recorded', 'invoice', $invoice_id, ['amount' => $amount, 'method' => $method, 'status' => $status]);

  try {
    require_once __DIR__ . '/../utils/payment_receipts.php';
    payment_receipt_issue($pdo, $paymentId, $appConfig ?? [], $send_receipt);
  } catch (Throwable $receiptError) {
    @error_log('[PaymentsCreate] Receipt issue failed: ' . $receiptError->getMessage());
  }

} catch (Throwable $e) {
  $pdo->rollBack();
  @error_log('[PaymentsCreate] Error: ' . $e->getMessage());
  $message = trim($e->getMessage()) !== '' ? substr($e->getMessage(), 0, 180) : 'Failed to save payment';
  header('Location: /?page=payments/payments-create&error=' . urlencode($message));
  exit;
}

header('Location: /?page=payments/payments-list&saved=1');
exit;
