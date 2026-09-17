<?php
// src/views/pages/invoice-print.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/document_sender.php';
require_once __DIR__ . '/../../../utils/invoice_content_links.php';
require_once __DIR__ . '/../../../utils/payment_methods.php';
require_once __DIR__ . '/../../../utils/public_links.php';
require_once __DIR__ . '/../../../services/StripeService.php';
require_once __DIR__ . '/../../../utils/invoice_due_dates.php';
require_once __DIR__ . '/../../../utils/general_recipient_invoices.php';
require_once __DIR__ . '/../../../utils/document_recipient.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/document_pricing_adjustments.php';
$id = (int)($_GET['id'] ?? 0);
if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')) {
    require_record_ownership($pdo, 'invoices', $id);
}
$st = $pdo->prepare('SELECT i.*, c.name client_name, c.email client_email, c.phone client_phone, c.address_line1 client_address_line1, c.address_line2 client_address_line2, c.city client_city, c.state client_state, c.postal_code client_postal_code, c.country client_country, o.name organization_name, o.general_email organization_email, o.general_phone organization_phone, o.address_line1 organization_address_line1, o.address_line2 organization_address_line2, o.city organization_city, o.state organization_state, o.postal_code organization_postal_code, o.country organization_country FROM invoices i JOIN clients c ON c.id=i.client_id' . pa_document_effective_organization_joins('i', 'c') . ' WHERE i.id=?');
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if(!$inv){ echo '<p>Invoice not found</p>'; return; }
$pricingSnapshot=(int)($inv['organization_id']??0)>0?pricing_document_snapshot($pdo,(int)$inv['organization_id'],'invoice',$id,max(1,(int)($inv['revision_number']??1))):null;
$invoiceOrganizationId=($inv['organization_id']??null)===null?null:(int)$inv['organization_id'];
$invoiceTotalAdjustments=pricing_invoice_total_adjustments($pdo,$invoiceOrganizationId,$id);
$invoiceCurrency=(string)($pricingSnapshot['currency']??$inv['currency']??$appConfig['document_currency']??$appConfig['currency']??$appConfig['workforce_currency']??'USD');
$isGeneralRecipientInvoice = pa_invoice_is_general_recipient($inv);
$generalRecipientToken = null;
if (!defined('PUBLIC_VIEW') && !defined('PDF_MODE')) {
    $generalRecipientFlash = $_SESSION['flash_general_recipient_link'] ?? null;
    unset($_SESSION['flash_general_recipient_link']);
    if ($isGeneralRecipientInvoice
        && is_array($generalRecipientFlash)
        && (int)($generalRecipientFlash['invoice_id'] ?? 0) === $id
        && preg_match('/^[a-f0-9]{64}$/', (string)($generalRecipientFlash['token'] ?? ''))) {
        $generalRecipientToken = (string)$generalRecipientFlash['token'];
    }
}
$items = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total, billing_unit, is_extra_charge FROM invoice_items WHERE invoice_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
$isHourlyBilling = ($inv['billing_mode'] ?? 'fixed') === 'hourly';
$documentSender = document_sender_for_creator($pdo, $appConfig, !empty($inv['created_by']) ? (int)$inv['created_by'] : null);
$fromName = $documentSender['name'] ?? '';
$fromAddress = implode("\n", document_sender_lines($documentSender));
$fromPhone = $documentSender['phone'] ?? '';
$fromEmail = $documentSender['email'] ?? '';
// Load project notes if available and resolve terms fallback
$projectNotes = null;
$termsText = '';
if (!empty($inv['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
    $pm->execute([$inv['project_code']]);
    $row = $pm->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      if (!empty($row['notes'])) { $projectNotes = $row['notes']; }
      if (!empty($row['terms'])) { $termsText = trim((string)$row['terms']); }
    }
  } catch (Throwable $e) {
    // Fallback for older schemas without 'terms'
    try {
      $pm = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
      $pm->execute([$inv['project_code']]);
      $row = $pm->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['notes'])) { $projectNotes = $row['notes']; }
    } catch (Throwable $e2) { /* ignore */ }
  }
}
if ($termsText === '') { $termsText = trim((string)($inv['terms'] ?? '')); }
if ($termsText === '' && ($inv['invoice_type'] ?? '') === 'on_demand') { $termsText = trim((string)($appConfig['on_demand_terms'] ?? '')); }
// Compute outstanding balance
$total = (float) ($inv['total'] ?? 0);
$paid = (float) ($inv['amount_paid'] ?? 0);
$outstanding = max(0, $total - $paid);
$invoiceCollectionMode = trim((string)($inv['collection_mode'] ?? ''));
if ($invoiceCollectionMode === '') {
  $invoiceCollectionMode = 'direct';
}

if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
$paymentTermsSummary = invoice_payment_terms_text($inv, $appConfig);
$showInvoiceDueDate = !array_key_exists('invoice_show_due_date', $appConfig) || !empty($appConfig['invoice_show_due_date']);
$showInvoiceTerms = !array_key_exists('invoice_show_terms', $appConfig) || !empty($appConfig['invoice_show_terms']);
?>
<section>
  <?php if (strtolower((string)($inv['status'] ?? '')) === 'void'): ?>
    <div style="margin:0 0 12px;padding:10px 14px;border:3px solid #6b7280;color:#4b5563;text-align:center;font-size:28px;font-weight:800;letter-spacing:8px">VOID</div>
  <?php endif; ?>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Invoice</div>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <?php 
    // Status banner styling
    $istatus = strtolower($inv['status'] ?? 'unpaid');
    $istatusColors = [
      'unpaid' => ['bg' => '#fffbeb', 'text' => '#92400e', 'border' => '#fbbf24'],
      'partial' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#f59e0b'],
      'paid' => ['bg' => '#ecfdf5', 'text' => '#065f46', 'border' => '#10b981'],
      'draft' => ['bg' => '#eff6ff', 'text' => '#1e40af', 'border' => '#60a5fa'],
      'sent' => ['bg' => '#f5f3ff', 'text' => '#5b21b6', 'border' => '#8b5cf6'],
      'overdue' => ['bg' => '#fff1f2', 'text' => '#9f1239', 'border' => '#fb7185'],
      'void' => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'border' => '#9ca3af']
    ];
    $icolors = $istatusColors[$istatus] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
  ?>
  <div class="no-print" style="padding:12px 16px;background:<?php echo $icolors['bg']; ?>;color:<?php echo $icolors['text']; ?>;border-left:4px solid <?php echo $icolors['border']; ?>;border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px">
    Status: <?php echo htmlspecialchars($inv['status']); ?>
  </div>
  <div class="no-print document-actions">
    <a href="javascript:history.back()" class="btn btn-sm">Back</a>
    <a href="/?page=invoice/invoice-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
    <a href="/?page=invoice/invoice-pdf&id=<?php echo (int)$id; ?>" download="invoice-<?php echo htmlspecialchars(pa_invoice_label_from_row($inv)); ?>.pdf" class="btn btn-sm">Download</a>
    <?php
      $actionStatus = strtolower((string)$inv['status']);
      $canEditInvoice = !in_array($actionStatus, ['void','cancelled'], true);
    ?>
    <?php if ($canEditInvoice): ?>
      <a href="/?page=invoice/invoices-edit&id=<?php echo (int)$id; ?>" class="btn btn-sm">Edit</a>
    <?php endif; ?>
    <?php if((int)($inv['last_sent_revision']??0)>0&&(int)($inv['revision_number']??1)>(int)$inv['last_sent_revision']): ?><span class="alert alert-warning" style="padding:6px 9px">Revised <?php echo htmlspecialchars((string)($inv['revision_updated_at']??'')); ?> · Resend required</span><?php endif; ?>
    <?php if((float)($inv['credit_due']??0)>0.005): ?><span class="alert alert-warning" style="padding:6px 9px">Credit due: $<?php echo number_format((float)$inv['credit_due'],2); ?>. Collection is disabled; use the audited credit/refund workflow.</span><?php endif; ?>
    <?php if (strtolower((string)$inv['status']) === 'draft'): ?>
      <?php
        $finalizeConfirmation = $isGeneralRecipientInvoice
          ? 'Finalize this invoice and create its manual public link?'
          : ($invoiceCollectionMode === 'project_aggregate'
            ? 'Finalize this invoice for the monthly project statement? It will not be emailed separately.'
            : 'Finalize this invoice and email it to the client?');
        $finalizeLabel = $isGeneralRecipientInvoice
          ? 'Finalize & Create Link'
          : ($invoiceCollectionMode === 'project_aggregate' ? 'Finalize for Project Billing' : 'Finalize & Send');
      ?>
      <form method="post" action="/?page=invoice/invoice-finalize" style="display:inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($finalizeConfirmation), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>);">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm btn-success"><?php echo htmlspecialchars($finalizeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></button>
      </form>
      <?php if ($invoiceCollectionMode === 'project_aggregate'): ?><span style="color:var(--muted);font-size:12px">Included in the project statement; not emailed separately.</span><?php endif; ?>
    <?php endif; ?>
    <?php if (!$isGeneralRecipientInvoice && !empty($inv['status']) && in_array(strtolower((string)$inv['status']), ['sent','unpaid','partial','overdue'], true) && $invoiceCollectionMode === 'direct'): ?>
    <form method="post" action="/?page=invoice/email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="invoice">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" class="btn btn-sm">Email</button>
    </form>
    <?php endif; ?>
    <?php if (in_array(strtolower((string)$inv['status']), ['sent','unpaid','partial','overdue'], true) && $invoiceCollectionMode === 'direct'): ?>
      <a href="/?page=payments/payments-create&invoice_id=<?php echo (int)$id; ?>&amount=<?php echo urlencode(number_format($outstanding, 2, '.', '')); ?>" 
         class="btn btn-sm btn-success">Mark as Paid</a>
    <?php endif; ?>
    <?php if ($outstanding > 0 && pa_payment_methods_has($appConfig, 'stripe') && StripeService::isConfigured($appConfig) && !empty($inv['finalized_at']) && $invoiceCollectionMode === 'direct' && in_array(strtolower((string)$inv['status']), ['sent','unpaid','partial','overdue'], true)): ?>
      <form method="post" action="/?page=stripe-charge" style="display:inline" onsubmit="return confirm('Open Stripe Checkout so you can enter this client payment on Stripe? PA will not store card details.');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="invoice_id" value="<?php echo (int)$id; ?>">
        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
        <button type="submit" class="btn btn-sm btn-info">Open Stripe Checkout</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($inv['status']) && strtolower($inv['status']) === 'void'): ?>
    <form method="post" action="/?page=invoice/invoice-reenable" style="display:inline" onsubmit="return confirm('Re-enable this invoice? Previous public and payment links will remain revoked.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" class="btn btn-sm btn-warning">Re-enable</button>
    </form>
    <?php endif; ?>
    <?php if (in_array($actionStatus, ['draft','sent','unpaid','overdue'], true)): ?>
      <details style="display:inline-block;position:relative;vertical-align:top">
        <summary class="btn btn-sm" style="list-style:none;background:#fff1f2;color:#9f1239;border-color:#fda4af;cursor:pointer">Void Invoice</summary>
        <form method="post" action="/?page=invoice/invoice-void" style="position:absolute;z-index:20;right:0;top:calc(100% + 6px);width:min(360px,80vw);padding:12px;background:#fff;border:1px solid #fecdd3;border-radius:8px;box-shadow:0 10px 25px rgba(15,23,42,.16)" onsubmit="return confirm('Void this invoice? This keeps the record for audit history and revokes all payment links.');">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
          <label style="display:grid;gap:6px;font-size:13px;font-weight:600;color:#374151">
            Reason for voiding
            <textarea name="reason" maxlength="500" required rows="3" placeholder="Example: Created for the wrong client" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;resize:vertical"></textarea>
          </label>
          <div style="margin-top:7px;font-size:12px;color:#6b7280">Paid, partially paid, and project-aggregated invoices cannot be voided here.</div>
          <button type="submit" class="btn btn-sm" style="margin-top:10px;background:#be123c;color:#fff;border-color:#be123c">Confirm Void</button>
        </form>
      </details>
    <?php endif; ?>
    <?php if ($actionStatus !== 'void'): ?>
    <form method="post" action="/?page=document-date-update" style="display:inline" onsubmit="return confirm('Update document date to today? This will refresh the date shown on the PDF.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="invoice">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" class="btn btn-sm btn-info">Update Document Date</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($inv['finalized_at']) && $invoiceCollectionMode === 'direct' && in_array(strtolower((string)$inv['status']), ['sent','unpaid','partial','overdue'], true)): ?>
      <button type="button" onclick="generatePublicLink()" class="btn btn-sm btn-info">Share Link</button>
    <?php endif; ?>
  </div>
  <?php if (!empty($_GET['error'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;border-radius:6px;margin-bottom:8px;font-size:14px"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['voided'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:6px;margin-bottom:8px;font-size:14px">Invoice voided. It remains in invoice history and its public/payment links have been revoked.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['reenabled'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">Invoice re-enabled successfully. Create a new public link before sending it again.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['payment_corrected'])): ?>
    <div class="no-print" style="padding:10px 12px;background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;border-radius:6px;margin-bottom:8px;font-size:14px">
      Payment allocation corrected. The original processor transaction now applies to this invoice; no Stripe refund or new charge was created.<?php if (!empty($_GET['source_invoice_id'])): ?> The duplicate source invoice #<?php echo (int)$_GET['source_invoice_id']; ?> was retained in history as void.<?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($actionStatus === 'void'): ?>
    <div class="no-print" style="padding:12px 14px;background:#f9fafb;border:1px solid #d1d5db;border-radius:8px;margin-bottom:12px">
      <div style="font-weight:700;color:#374151">Void reason</div>
      <div style="margin-top:4px;color:#4b5563"><?php echo htmlspecialchars(trim((string)($inv['void_reason'] ?? '')) ?: 'No reason was recorded for this legacy void.'); ?></div>
      <?php if (!empty($inv['voided_at'])): ?><div style="margin-top:5px;font-size:12px;color:#6b7280">Voided <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$inv['voided_at']))); ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($_GET['payment']) && $_GET['payment'] === 'success'): ?>
    <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Payment processed successfully! The invoice status will update shortly.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['payment']) && $_GET['payment'] === 'cancelled'): ?>
    <div class="no-print" style="padding:8px 12px;background:#fef3c7;color:#92400e;border-radius:6px;margin-bottom:8px;font-size:14px">Payment was cancelled.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['stripe_error'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#fee2e2;color:#991b1b;border-radius:6px;margin-bottom:8px;font-size:14px">Stripe error: <?php echo htmlspecialchars($_GET['stripe_error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['date_updated'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Document date updated successfully</div>
  <?php endif; ?>
  <?php if (!empty($_GET['emailed'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">Invoice emailed.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['project_billing'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px">Invoice finalized for project billing. It will be included in the project statement and was not emailed separately.</div>
  <?php endif; ?>
  <?php if ($generalRecipientToken !== null): ?>
    <?php
      try {
        $generalRecipientUrl = invoice_public_base_url($appConfig) . '/?page=public-doc&token=' . rawurlencode($generalRecipientToken);
      } catch (Throwable $e) {
        $generalRecipientUrl = '/?page=public-doc&token=' . rawurlencode($generalRecipientToken);
      }
    ?>
    <div class="no-print" style="padding:10px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">
      <strong>Invoice finalized. Manual public link created:</strong>
      <a href="<?php echo htmlspecialchars($generalRecipientUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($generalRecipientUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a>
    </div>
  <?php endif; ?>
  <?php if (!empty($_GET['email_err'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#fee2e2;color:#991b1b;border-radius:6px;margin-bottom:8px;font-size:14px">Email error: <?php echo htmlspecialchars((string)$_GET['email_err']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['content_link_warning'])): ?>
    <?php
      $invoiceWarningParams = $_GET;
      unset($invoiceWarningParams['content_link_warning']);
      $invoiceWarningReturn = '/?' . http_build_query($invoiceWarningParams);
      $invoiceWarningIsDraft = strtolower((string)($inv['status'] ?? '')) === 'draft';
    ?>
    <div class="no-print" style="display:block;padding:12px 14px;background:#fffbeb;color:#92400e;border:1px solid #facc15;border-radius:8px;margin-bottom:8px;font-size:14px">
      <strong>No invoice content links found.</strong>
      <div style="margin-top:4px">This invoice has no eligible links marked "Include on invoices." Add a content link first, or send it anyway.</div>
      <form method="post" action="<?php echo $invoiceWarningIsDraft ? '/?page=invoice/invoice-finalize' : '/?page=invoice/email-send'; ?>" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <?php if (!$invoiceWarningIsDraft): ?><input type="hidden" name="type" value="invoice"><?php endif; ?>
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <?php if (!$invoiceWarningIsDraft): ?><input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($invoiceWarningReturn); ?>"><?php endif; ?>
        <input type="hidden" name="confirm_missing_content_links" value="1">
        <button type="submit" class="btn btn-sm btn-warning"><?php echo $invoiceWarningIsDraft ? 'Finalize & Send Anyway' : 'Send Anyway'; ?></button>
        <a class="btn btn-sm" href="<?php echo htmlspecialchars($invoiceWarningReturn); ?>">Cancel</a>
      </form>
    </div>
  <?php endif; ?>
  <div class="no-print" style="padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151">
    <strong>Created:</strong> <?php echo !empty($inv['created_at']) ? date('M j, Y g:i A', strtotime($inv['created_at'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <strong>Document Date:</strong> <?php echo !empty($inv['document_date']) ? date('M j, Y g:i A', strtotime($inv['document_date'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <?php echo pa_public_link_status_badge_html($pdo, 'invoice', $id); ?>
    <?php if (!empty($inv['document_date_updated_at'])): ?>
      <span style="margin-left:8px;color:#6b7280;font-size:12px">(Updated: <?php echo date('M j, Y g:i A', strtotime($inv['document_date_updated_at'])); ?>)</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php
    $documentBrandLabel = 'Invoice ' . pa_invoice_label_from_row($inv);
    $documentBrandMetaLines = [];
    if (!empty($inv['project_code'])) { $documentBrandMetaLines[] = 'Job ' . $inv['project_code']; }
    if (!empty($inv['project_id'])) { $documentBrandMetaLines[] = 'Project ' . $inv['project_id']; }
    require __DIR__ . '/../../components/document_brand_header.php';
  ?>

  <?php
    // Get custom fields for display
    $documentType = 'regular';
    
    $customFieldValues = !empty($inv['custom_fields']) ? json_decode($inv['custom_fields'], true) : [];
    if (!is_array($customFieldValues)) $customFieldValues = [];
    
    // Fetch custom field definitions (non-builtin only)
    $customFieldDefs = [];
    try {
      $cfStmt = $pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0 ORDER BY display_order, id');
      $cfStmt->execute([$documentType]);
      $customFieldDefs = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignore */ }
    
    // Build array of custom fields with values to display
    $displayCustomFields = [];
    foreach ($customFieldDefs as $cf) {
      $key = $cf['field_key'];
      if (isset($customFieldValues[$key]) && $customFieldValues[$key] !== '') {
        $val = $customFieldValues[$key];
        // Format based on type
        if ($cf['field_type'] === 'date' && !empty($val)) {
          $val = date('M j, Y', strtotime($val));
        } elseif ($cf['field_type'] === 'number' && is_numeric($val)) {
          $val = number_format((float)$val, 2);
        }
        $displayCustomFields[] = ['label' => $cf['field_label'], 'value' => $val];
      }
    }
    $hasCustomFields = !empty($displayCustomFields);
    $showFulfillmentDate = !empty($inv['fulfillment_date']);
  ?>
  <?php if ($hasCustomFields || $showFulfillmentDate): ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb">
    <tr>
      <?php if ($showFulfillmentDate): ?>
      <td style="padding:8px;<?php echo $hasCustomFields ? 'border-right:1px solid #e5e7eb;' : ''; ?>vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Fulfillment Date: <span style="font-weight:600;color:#2563eb"><?php echo date('M j, Y', strtotime($inv['fulfillment_date'])); ?></span></div>
      </td>
      <?php endif; ?>
      <?php foreach ($displayCustomFields as $idx => $cf): ?>
      <td style="padding:8px;<?php echo $idx < count($displayCustomFields) - 1 ? 'border-right:1px solid #e5e7eb;' : ''; ?>vertical-align:top">
        <div style="font-size:11px;color:#6b7280"><?php echo htmlspecialchars($cf['label']); ?>: <span style="font-weight:600;color:#374151"><?php echo htmlspecialchars($cf['value']); ?></span></div>
      </td>
      <?php endforeach; ?>
    </tr>
  </table>
  <?php endif; ?>

  <?php
    $documentPartySender = $documentSender;
    $documentPartyRecipient = pa_document_recipient($inv, $isGeneralRecipientInvoice);
    require __DIR__ . '/../../components/document_parties.php';
  ?>

  <?php if (!empty($projectNotes)): ?>
  <div style="margin:12px 0;padding:10px;border:1px solid #eee;border-radius:8px;background:#f8fafc">
          <div style="font-weight:600;margin-bottom:6px">Job Notes</div>

    <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars($projectNotes); ?></pre>
  </div>
  <?php endif; ?>



  <table style="width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee">
        <th style="padding:10px;width:25%;vertical-align:top;text-align:center">Item</th>
        <th style="padding:10px;width:35%;vertical-align:top">Description</th>
        <th style="padding:10px;width:10%;text-align:right;vertical-align:top"><?php echo $isHourlyBilling ? 'Hours' : 'Qty / Unit'; ?></th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top"><?php echo $isHourlyBilling ? 'Hourly Rate' : 'Unit Price'; ?></th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top">Line Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr style="border-top:1px solid #f3f4f6<?php echo (int)($it['is_extra_charge'] ?? 0) ? ';background:#fffbeb' : ''; ?>">
        <td style="padding:10px;vertical-align:top;text-align:center">
          <div class="font-600"><?php echo htmlspecialchars($it['item'] ?? ''); ?></div>
          <?php if ((int)($it['is_extra_charge'] ?? 0) === 1): ?>
            <span style="display:inline-block;margin-top:4px;padding:2px 6px;background:#fbbf24;color:#92400e;border-radius:3px;font-size:10px;font-weight:600">Extra Charge</span>
          <?php endif; ?>
        </td>
        <td style="padding:10px;color:#6b7280;font-size:13px;vertical-align:top"><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top"><?php echo number_format($it['quantity'],2); ?><?php echo ($it['billing_unit'] ?? 'each') === 'mile' ? ' mi' : ''; ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['unit_price'],2); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['line_total'],2); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals section - uses table for PDF compatibility -->
  <?php
    $invoiceTotal = (float)($inv['total'] ?? 0);
    // Calculate amount_paid from payments table for accuracy
    $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount-disputed_amount,0)), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
    $paidStmt->execute([$id]);
    $amountPaid = (float)$paidStmt->fetchColumn();
    $amountDue = max(0, $invoiceTotal - $amountPaid);
    $invStatus = strtolower($inv['status'] ?? 'unpaid');
    $isPartial = $invStatus === 'partial';
    $isPaid = $invStatus === 'paid';
  ?>
  <?php
    $pricingRowsFromHtml = static function (string $html): array {
      if ($html === '') return [];
      preg_match_all('~<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>~si', $html, $matches, PREG_SET_ORDER);
      return array_map(static fn(array $match): array => [
        'label' => html_entity_decode(strip_tags((string)$match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'value' => html_entity_decode(strip_tags((string)$match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
      ], $matches);
    };
    $pricingAdjustmentRows = $pricingRowsFromHtml(pricing_adjustment_client_row($pricingSnapshot));
    $invoiceAdjustmentRows = $pricingRowsFromHtml(pricing_invoice_adjustment_client_rows($invoiceTotalAdjustments,$invoiceCurrency));
    if (($inv['discount_type'] ?? 'none') === 'percent') {
      $discountDisplay = number_format((float)$inv['discount_value'], 2) . '%';
    } elseif (($inv['discount_type'] ?? 'none') === 'fixed') {
      $discountDisplay = pricing_currency_amount($inv['discount_value'], $invoiceCurrency);
    } else {
      $discountDisplay = pricing_currency_amount(0, $invoiceCurrency);
    }
    $documentTotalRows = [
      ['label' => 'Subtotal', 'value' => pricing_currency_amount($inv['subtotal'], $invoiceCurrency)],
      ...$pricingAdjustmentRows,
      ['label' => 'Discount', 'value' => $discountDisplay],
      ['label' => 'Tax', 'value' => number_format((float)$inv['tax_percent'], 2) . '%'],
      ...$invoiceAdjustmentRows,
      ['label' => $isPartial ? 'Invoice Total' : 'Total', 'value' => pricing_currency_amount($invoiceTotal, $invoiceCurrency), 'tone' => 'total'],
    ];
    if ($isPartial) {
      $documentTotalRows[] = ['label' => 'Amount Paid', 'value' => pricing_currency_amount($amountPaid, $invoiceCurrency, true), 'tone' => 'paid'];
      $documentTotalRows[] = ['label' => 'Amount Due', 'value' => pricing_currency_amount($amountDue, $invoiceCurrency), 'tone' => 'due'];
    } elseif ($isPaid) {
      $documentTotalRows[] = ['label' => '✓ Paid in Full', 'value' => pricing_currency_amount(0, $invoiceCurrency), 'tone' => 'paid_full'];
    }
    require __DIR__ . '/../../components/document_totals.php';
  ?>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW') && ($pricingProvenance=pricing_adjustment_staff_provenance($pricingSnapshot))!==''): ?><p class="pricing-provenance" data-pricing-provenance><?php echo htmlspecialchars($pricingProvenance,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?></p><?php endif; ?>

  <?php if (!$isGeneralRecipientInvoice): ?>
  <?php
    $invoiceContentLinksHtml = invoice_content_links_html(invoice_content_links_for_invoice($pdo, $id, $appConfig));
    if ($invoiceContentLinksHtml !== '') {
      echo $invoiceContentLinksHtml;
    }
  ?>
  <?php endif; ?>
  <?php if (($showInvoiceDueDate && $paymentTermsSummary !== '') || ($showInvoiceTerms && $termsText !== '')): ?>
  <div style="margin:12px 0;padding:12px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc">
    <?php if ($showInvoiceDueDate && $paymentTermsSummary !== ''): ?>
      <div style="font-weight:700;color:#0f172a">Payment terms: <?php echo htmlspecialchars($paymentTermsSummary); ?></div>
    <?php endif; ?>
    <?php if ($showInvoiceTerms && $termsText !== ''): ?>
      <div style="margin-top:6px;color:#334155;white-space:pre-wrap"><?php echo htmlspecialchars($termsText); ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php
  // Review link section - show if configured and invoice is paid
  $reviewLink = trim($appConfig['review_link'] ?? '');
  if ($reviewLink !== '' && $isPaid):
?>
<div style="margin-top:24px;padding:16px;background:linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);border-radius:12px;text-align:center">
  <div style="font-size:16px;font-weight:600;color:#92400e;margin-bottom:8px">⭐ Enjoyed our service?</div>
  <div style="color:#78350f;margin-bottom:12px">We'd love to hear your feedback!</div>
  <a href="<?php echo htmlspecialchars($reviewLink); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:10px 20px;background:#f59e0b;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Leave a Review</a>
</div>
<?php endif; ?>

<style>
  .no-print{display:flex}
  .print-footer{display:none}
  @media print {
    .no-print{display:none !important}
    .side-nav,.nav-footer{display:none}
    .main-content{margin-left:0}
    body{background:#fff}
    .print-footer{display:block; position:fixed; bottom:6px; left:12px; color:#374151; font-size:12px}
  }
</style>
<div class="print-footer"><a href="https://project-alpha.tech" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">Powered by Project Alpha</a></div>

<!-- Share Link Modal -->
<div id="shareLinkModal" data-doc-type="invoice" data-doc-id="<?php echo (int)$id; ?>" data-default-days="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0;font-size:18px">🔗 Share Invoice Link</h3>
      <button onclick="closeShareModal()" style="border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <div id="shareLinkContent">
      <p style="color:#6b7280;margin:0 0 16px">Generate a public link that clients can use to view and pay this invoice.</p>
      <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer">
        <input type="checkbox" id="expireWhenPaid" onchange="toggleDaysInput()" style="width:18px;height:18px" checked disabled>
        <span style="font-weight:500">Invoice links expire when paid in full or manually revoked</span>
      </label>
      <label id="daysLabel" style="display:block;margin-bottom:12px">
        <div style="font-weight:500;margin-bottom:4px">Link expires in (days)</div>
        <input type="number" id="linkDays" value="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" min="1" max="365" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
      </label>
      <button onclick="createPublicLink()" style="width:100%;padding:12px;background:#4f46e5;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer">Generate Link</button>
    </div>
    <div id="shareLinkResult" style="display:none">
      <div style="padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;margin-bottom:12px">
        <div style="font-weight:600;color:#166534;margin-bottom:4px" id="linkStatus">✓ Link Generated!</div>
        <div style="font-size:13px;color:#15803d" id="linkExpiry"></div>
      </div>
      <div style="position:relative">
        <input type="text" id="generatedLink" readonly style="width:100%;padding:10px;padding-right:80px;border:1px solid #ddd;border-radius:8px;font-size:13px;background:#f9fafb">
        <button onclick="copyLink()" style="position:absolute;right:4px;top:4px;padding:6px 12px;background:#4f46e5;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer">Copy</button>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px">
        <button id="revokeBtn" onclick="revokeAndCreateNew()" style="flex:1;padding:10px;background:#fee2e2;color:#991b1b;border:0;border-radius:8px;cursor:pointer;display:none">Revoke & Create New</button>
        <button onclick="closeShareModal()" style="flex:1;padding:10px;background:#4f46e5;color:#fff;border:0;border-radius:8px;cursor:pointer">Done</button>
      </div>
    </div>
  </div>
</div>

<script>
function generatePublicLink() {
  document.getElementById('shareLinkModal').style.display = 'flex';
  
  // First, check if a link already exists
  const formData = new FormData();
  formData.append('type', 'invoice');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', '<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>');
  formData.append('expire_when_paid', '1');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-create', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success && data.existing) {
      // Show the existing link directly
      document.getElementById('generatedLink').value = data.url;
      document.getElementById('linkStatus').textContent = '\u2713 Existing Link';
      document.getElementById('revokeBtn').style.display = 'block';
      
      if (data.expire_when_paid) {
        document.getElementById('linkExpiry').textContent = <?php echo json_encode($isGeneralRecipientInvoice ? 'Remains available as a receipt for seven days after payment' : 'Expires when invoice is paid in full'); ?>;
      } else {
        document.getElementById('linkExpiry').textContent = 'Expires: ' + data.expires_at + ' (' + data.expires_in_days + ' days remaining)';
      }
      
      document.getElementById('shareLinkContent').style.display = 'none';
      document.getElementById('shareLinkResult').style.display = 'block';
    } else {
      // No existing link, show the create form
      document.getElementById('shareLinkContent').style.display = 'block';
      document.getElementById('shareLinkResult').style.display = 'none';
      document.getElementById('expireWhenPaid').checked = true;
      toggleDaysInput();
    }
  })
  .catch(err => {
    // On error, show the create form
    document.getElementById('shareLinkContent').style.display = 'block';
    document.getElementById('shareLinkResult').style.display = 'none';
    document.getElementById('expireWhenPaid').checked = true;
    toggleDaysInput();
  });
}

function closeShareModal() {
  document.getElementById('shareLinkModal').style.display = 'none';
}

function toggleDaysInput() {
  const expireWhenPaid = true;
  const daysLabel = document.getElementById('daysLabel');
  const daysInput = document.getElementById('linkDays');
  if (expireWhenPaid) {
    daysLabel.style.opacity = '0.5';
    daysInput.disabled = true;
  } else {
    daysLabel.style.opacity = '1';
    daysInput.disabled = false;
  }
}

function createPublicLink() {
  const expireWhenPaid = true;
  const days = document.getElementById('linkDays').value || 14;
  const formData = new FormData();
  formData.append('type', 'invoice');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', days);
  formData.append('expire_when_paid', expireWhenPaid ? '1' : '0');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-create', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.getElementById('generatedLink').value = data.url;
      
      // Update status and expiry display
      const statusEl = document.getElementById('linkStatus');
      const expiryEl = document.getElementById('linkExpiry');
      const revokeBtn = document.getElementById('revokeBtn');
      
      if (data.existing) {
        statusEl.textContent = '✓ Existing Link Found';
        revokeBtn.style.display = 'block';
      } else {
        statusEl.textContent = '✓ Link Generated!';
        revokeBtn.style.display = 'none';
      }
      
      if (data.expire_when_paid) {
        expiryEl.textContent = <?php echo json_encode($isGeneralRecipientInvoice ? 'Remains available as a receipt for seven days after payment' : 'Expires when invoice is paid in full'); ?>;
      } else {
        expiryEl.textContent = 'Expires: ' + data.expires_at + ' (' + data.expires_in_days + ' days)';
      }
      
      document.getElementById('shareLinkContent').style.display = 'none';
      document.getElementById('shareLinkResult').style.display = 'block';
    } else {
      alert('Error: ' + (data.error || 'Failed to generate link'));
    }
  })
  .catch(err => {
    alert('Error generating link: ' + err.message);
  });
}

function copyLink() {
  const input = document.getElementById('generatedLink');
  input.select();
  document.execCommand('copy');
  const btn = event.target;
  btn.textContent = 'Copied!';
  setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
}

function revokeAndCreateNew() {
  if (!confirm('This will revoke the existing link (it will no longer work). Continue?')) {
    return;
  }
  
  // Revoke the existing link
  const formData = new FormData();
  formData.append('type', 'invoice');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-revoke', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      // Reset to creation form view
      document.getElementById('shareLinkContent').style.display = 'block';
      document.getElementById('shareLinkResult').style.display = 'none';
      document.getElementById('generatedLink').value = '';
      document.getElementById('linkStatus').textContent = '✓ Link Generated!';
      document.getElementById('revokeBtn').style.display = 'none';
      document.getElementById('linkExpiry').textContent = '';
      // Reset to default days
      document.getElementById('linkDays').value = '<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>';
      document.getElementById('expireWhenPaid').checked = false;
      toggleDaysInput();
    } else {
      alert('Error: ' + (data.error || 'Failed to revoke link'));
    }
  })
  .catch(err => {
    alert('Error: ' + err.message);
  });
}

// Close modal on outside click
document.getElementById('shareLinkModal').addEventListener('click', function(e) {
  if (e.target === this) closeShareModal();
});
</script>
