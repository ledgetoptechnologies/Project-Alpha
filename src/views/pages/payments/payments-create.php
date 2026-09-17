<?php
// src/views/pages/payments-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../services/ManualPaymentJobService.php';

use App\Services\ManualPaymentJobService;

$manualJobs = [];
try {
  $manualJobs = (new ManualPaymentJobService($pdo))->availableJobs((int)($_SESSION['user']['id'] ?? 0));
} catch (Throwable $jobLoadError) {
  @error_log('[PaymentsCreate] Service jobs unavailable: ' . $jobLoadError->getMessage());
}

$invoices = $pdo->query("
  SELECT i.id, i.total, COALESCE(p.paid,0) AS paid, i.status,
         i.recipient_presentation_mode, c.name client
  FROM invoices i
  JOIN clients c ON c.id=i.client_id
  LEFT JOIN (
    SELECT invoice_id, SUM(GREATEST(amount-refunded_amount-disputed_amount,0)) AS paid
    FROM payments
    WHERE status='succeeded'
    GROUP BY invoice_id
  ) p ON p.invoice_id=i.id
  WHERE i.status IN ('unpaid','partial') AND COALESCE(i.collection_mode,'direct')='direct'
  ORDER BY i.created_at DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
[$projectScopeWhere, $projectScopeParams] = scope_clause($pdo, 'p', $currentUserId);
$projectScopeSql = $projectScopeWhere !== '' ? ' AND ' . $projectScopeWhere : '';
$projectInvoiceStmt = $pdo->prepare("
  SELECT pi.id,pi.project_id,pi.doc_number,pi.total,pi.balance_due,pi.status,p.name AS project_name
  FROM project_invoices pi
  JOIN projects p ON p.id=pi.project_id
  WHERE pi.status IN ('sent','unpaid','partial','overdue') AND pi.finalized_at IS NOT NULL AND pi.balance_due>0.005
  {$projectScopeSql}
  ORDER BY pi.created_at DESC
  LIMIT 200
");
$projectInvoiceStmt->execute($projectScopeParams);
$projectInvoices = $projectInvoiceStmt->fetchAll(PDO::FETCH_ASSOC);
$pref = (int)($_GET['invoice_id'] ?? 0);
$prefProjectInvoice = (int)($_GET['project_invoice_id'] ?? 0);
$prefAmount = '';
if ($pref > 0) {
  $st = $pdo->prepare("
    SELECT i.total, COALESCE(p.paid,0) AS paid
    FROM invoices i
    LEFT JOIN (
      SELECT invoice_id, SUM(GREATEST(amount-refunded_amount-disputed_amount,0)) AS paid
      FROM payments
      WHERE status='succeeded'
      GROUP BY invoice_id
    ) p ON p.invoice_id=i.id
    WHERE i.id=?
  ");
  $st->execute([$pref]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $remain = max(0, (float)$row['total'] - (float)$row['paid']);
    if ($remain > 0) { $prefAmount = number_format($remain, 2, '.', ''); }
  }
}
?>
<section>
  <h2>Record Payment</h2>
  <form method="post" action="/?page=payments/payments-create" id="recordPaymentForm" style="display:grid;gap:12px;max-width:560px">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">

    <div style="display:grid;gap:8px">
      <div style="font-weight:600">Payment Type</div>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="radio" name="payment_scope" value="invoice" <?php echo $prefProjectInvoice <= 0 ? 'checked' : ''; ?>>
        <span>Apply to an invoice</span>
      </label>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="radio" name="payment_scope" value="project_invoice" <?php echo $prefProjectInvoice > 0 ? 'checked' : ''; ?>>
        <span>Apply to a project statement</span>
      </label>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="radio" name="payment_scope" value="manual">
        <span>Manual payment not tied to an invoice</span>
      </label>
    </div>

    <div id="projectInvoicePaymentFields" style="display:none;gap:12px">
      <label>
        <div>Project Statement</div>
        <select name="project_invoice_id" id="projectInvoiceSelect" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="">Select project statement...</option>
          <?php foreach ($projectInvoices as $pi): ?>
            <option value="<?php echo (int)$pi['id']; ?>" data-remaining="<?php echo number_format((float)$pi['balance_due'], 2, '.', ''); ?>" <?php echo $prefProjectInvoice === (int)$pi['id'] ? 'selected' : ''; ?>>
              PI-<?php echo htmlspecialchars((string)($pi['doc_number'] ?: $pi['id'])); ?> - <?php echo htmlspecialchars((string)$pi['project_name']); ?> - $<?php echo number_format((float)$pi['balance_due'], 2); ?> due
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div id="invoicePaymentFields" style="display:grid;gap:12px">
      <label>
        <div>Invoice</div>
        <select required name="invoice_id" id="invoiceSelect" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="">Select invoice...</option>
          <?php foreach ($invoices as $i): $remain = max(0, (float)$i['total'] - (float)$i['paid']); ?>
            <option value="<?php echo (int)$i['id']; ?>" data-remaining="<?php echo number_format($remain,2,'.',''); ?>" data-general-recipient="<?php echo ($i['recipient_presentation_mode'] ?? 'named') === 'general' ? '1' : '0'; ?>" <?php echo $pref===(int)$i['id']?'selected':''; ?>>
              #<?php echo (int)$i['id']; ?> - <?php echo htmlspecialchars($i['client']); ?> - $<?php echo number_format((float)$i['total'],2); ?> (<?php echo htmlspecialchars($i['status']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div id="manualPaymentFields" style="display:none;gap:12px">
      <label>
        <div>Client <span style="color:var(--muted);font-weight:400">(optional)</span></div>
        <div style="position:relative">
          <input type="hidden" name="client_id" id="manualClientId">
          <input type="text" id="manualClientSearch" placeholder="Type a client name or email..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <div id="manualClientSuggest" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:30;max-height:220px;overflow:auto;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 12px 24px rgba(15,23,42,.12)"></div>
        </div>
        <small style="display:block;margin-top:6px;color:var(--muted)">Choose a client when one exists, or leave this blank for anonymous or walk-in income.</small>
      </label>

      <label>
        <div>Service Job <span style="color:var(--muted);font-weight:400">(optional)</span></div>
        <input type="search" id="manualJobSearch" placeholder="Filter by job code, service, or client..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px 8px 0 0;border:1px solid #ddd;border-bottom:0">
        <select name="job_id" id="manualJobSelect" size="5" style="width:100%;padding:8px;border-radius:0 0 8px 8px;border:1px solid #ddd">
          <option value="">No service job — standalone income</option>
          <?php foreach ($manualJobs as $job): ?>
            <?php
              $jobClient = trim((string)($job['client_name'] ?? ''));
              $jobServices = trim((string)($job['service_names'] ?? ''));
              $jobLabel = (string)$job['job_code']
                . ($jobServices !== '' ? ' — ' . $jobServices : '')
                . ($jobClient !== '' ? ' — ' . $jobClient : ' — No client');
            ?>
            <option
              value="<?php echo (int)$job['id']; ?>"
              data-search="<?php echo htmlspecialchars(strtolower($jobLabel), ENT_QUOTES, 'UTF-8'); ?>"
              data-client-id="<?php echo (int)($job['client_id'] ?? 0); ?>"
              data-client-name="<?php echo htmlspecialchars($jobClient, ENT_QUOTES, 'UTF-8'); ?>"
              data-client-email="<?php echo htmlspecialchars((string)($job['client_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
              data-expected-known="<?php echo !empty($job['expected_charge_known']) ? '1' : '0'; ?>"
              data-expected="<?php echo htmlspecialchars(number_format((float)($job['expected_charge'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
              data-currency="<?php echo htmlspecialchars((string)($job['expected_currency'] ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?>"
            ><?php echo htmlspecialchars($jobLabel, ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
        <small style="display:block;margin-top:6px;color:var(--muted)">Linking a job preserves which service produced the income. Leave it blank for generic standalone income.</small>
      </label>

      <div id="manualJobExpected" hidden style="padding:12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1e3a8a">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline">
          <strong>Expected service charge</strong>
          <span id="manualJobExpectedAmount" style="font-size:18px;font-weight:700"></span>
        </div>
        <div id="manualJobVariance" style="margin-top:5px;font-size:13px"></div>
      </div>
    </div>

    <label>
      <div>Payment Date</div>
      <input required type="date" name="payment_date" value="<?php echo htmlspecialchars($_GET['payment_date'] ?? date('Y-m-d')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>

    <label>
      <div>Amount ($)</div>
      <input required type="number" step="0.01" name="amount" id="amountInput" placeholder="0.00" value="<?php echo htmlspecialchars($prefAmount); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>

    <label>
      <div>Method</div>
      <?php
        require_once __DIR__ . '/../../../services/StripeService.php';
        require_once __DIR__ . '/../../../utils/payment_methods.php';
        $methods = pa_payment_methods_from_config($appConfig);
        $stripeConfigured = StripeService::isConfigured($appConfig);
      ?>
      <select required name="method" id="paymentMethod" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <?php foreach ($methods as $m): ?>
          <?php if (($m['key'] ?? '') === 'stripe') { continue; } ?>
          <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['label']); ?></option>
        <?php endforeach; ?>
        <?php if ($stripeConfigured && pa_payment_methods_has($appConfig, 'stripe')): ?>
          <option value="stripe">Credit Card (Stripe)</option>
        <?php endif; ?>
      </select>
    </label>

    <div id="noStaffPaymentMethodNotice" style="display:none;padding:12px;background:#fff7ed;border:1px solid #fb923c;border-radius:8px;font-size:14px">
      No staff-recorded payment method is configured for this payment type. Use the document's public payment link for Stripe, or enable another method in billing settings.
    </div>

    <div id="stripeNotice" style="display:none;padding:12px;background:#e6f4ff;border:1px solid #0284c7;border-radius:8px;font-size:14px">
      <strong>Stripe Checkout:</strong> You will be redirected to Stripe to collect the card payment.
    </div>

    <div id="checkNumberField" style="display:none">
      <label>
        <div id="referenceLabel">Check Number</div>
        <input type="text" name="reference_number" id="checkNumberInput" placeholder="Enter check number" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" placeholder="Optional payment notes" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>

    <?php if (!array_key_exists('payment_receipts_enabled', $appConfig) || !empty($appConfig['payment_receipts_enabled'])): ?>
      <label style="display:flex;align-items:start;gap:8px">
        <input type="checkbox" name="send_receipt" value="1" id="sendReceiptInput" style="margin-top:3px">
        <span>
          <span style="font-weight:600">Email receipt</span><br>
          <span id="sendReceiptHelp" style="font-size:13px;color:var(--muted)">Invoice payments default on. For standalone payments, choose a client with an email address.</span>
        </span>
      </label>
    <?php endif; ?>

    <button type="submit" id="savePaymentButton" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Payment</button>
  </form>
</section>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/payments-create-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
