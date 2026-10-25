<?php
// src/views/pages/contract/long-term-contract-print.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_recipient.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
$id = (int)($_GET['id'] ?? 0);
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/document_sender.php';
require_once __DIR__ . '/../../../utils/public_links.php';
require_once __DIR__ . '/../../../utils/document_pricing_adjustments.php';
if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')) {
    require_record_ownership($pdo, 'contracts', $id);
}
$c = $pdo->prepare('SELECT ltc.*, cl.name client_name, cl.email client_email, cl.phone client_phone, cl.address_line1 client_address_line1, cl.address_line2 client_address_line2, cl.city client_city, cl.state client_state, cl.postal_code client_postal_code, cl.country client_country, o.name organization_name, o.general_email organization_email, o.general_phone organization_phone, o.address_line1 organization_address_line1, o.address_line2 organization_address_line2, o.city organization_city, o.state organization_state, o.postal_code organization_postal_code, o.country organization_country FROM contracts ltc JOIN clients cl ON cl.id=ltc.client_id' . pa_document_effective_organization_joins('ltc', 'cl') . ' WHERE ltc.id=? AND ltc.contract_type="long_term"');
$c->execute([$id]);
$contract = $c->fetch(PDO::FETCH_ASSOC);
if(!$contract){ echo '<p>Long-term contract not found</p>'; return; }
$pricingSnapshot=(int)($contract['organization_id']??0)>0?pricing_document_snapshot($pdo,(int)$contract['organization_id'],'contract',$id,max(1,(int)($contract['revision_number']??1))):null;

$latestLongTermInvoice = null;
try {
    $latestInvoiceStmt = $pdo->prepare('SELECT id, doc_number, sent_at FROM invoices WHERE contract_id=? AND invoice_type="long_term" ORDER BY created_at DESC, id DESC LIMIT 1');
    $latestInvoiceStmt->execute([$id]);
    $latestLongTermInvoice = $latestInvoiceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $latestLongTermInvoice = null;
}

$recurringServices = [];
$contractAmendments = [];
$scheduledServiceChanges = [];
if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')) {
    try {
        $serviceStmt = $pdo->prepare('SELECT * FROM contract_recurring_services WHERE contract_id=? ORDER BY is_base DESC,status="ended",effective_from,id');
        $serviceStmt->execute([$id]);
        $recurringServices = $serviceStmt->fetchAll(PDO::FETCH_ASSOC);
        $amendmentStmt = $pdo->prepare('SELECT a.*,s.name AS service_name FROM contract_amendments a LEFT JOIN contract_recurring_services s ON s.id=a.recurring_service_id WHERE a.contract_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT 50');
        $amendmentStmt->execute([$id]);
        $contractAmendments = $amendmentStmt->fetchAll(PDO::FETCH_ASSOC);
        $changeStmt = $pdo->prepare('SELECT * FROM contract_recurring_service_changes WHERE contract_id=? AND state IN ("pending","approved") ORDER BY effective_date,id');
        $changeStmt->execute([$id]);
        $scheduledServiceChanges = $changeStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $recurringServices = [];
        $contractAmendments = [];
    }
}

$signatures = [];
try {
    $sigStmt = $pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id = ? ORDER BY display_order, id');
    $sigStmt->execute([$id]);
    $signatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $signatures = [];
}
if (!$signatures) {
    $signatures = [['signer_title' => 'Client Signature', 'is_required' => 1]];
}

// Get items if fixed_total pricing
$items = [];
if ($contract['pricing_type'] === 'fixed_total') {
    $itemsQuery = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total, billing_unit FROM contract_items WHERE contract_id=?');
    $itemsQuery->execute([$id]);
    $items = $itemsQuery->fetchAll();
}
$isHourlyBilling = ($contract['billing_mode'] ?? 'fixed') === 'hourly';

require_once __DIR__ . '/../../../utils/format.php';
$documentSender = document_sender_for_document($pdo, $appConfig, $contract);
$fromName = $documentSender['name'] ?? '';
$fromAddress = implode("\n", document_sender_lines($documentSender));
$fromPhone = $documentSender['phone'] ?? '';
$fromEmail = $documentSender['email'] ?? '';

// Resolve terms
$termsText = '';
if (!empty($contract['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
    $pm->execute([$contract['project_code']]);
    $pt = (string)$pm->fetchColumn();
    if (trim($pt) !== '') { $termsText = trim($pt); }
  } catch (Throwable $e) { /* ignore */ }
}
if ($termsText === '') { $termsText = trim((string)($contract['terms'] ?? '')); }
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }

// Calculate billing display
$billingInterval = $contract['billing_interval_count'] . ' ' . ucfirst($contract['billing_interval_unit']);
if ($contract['billing_interval_count'] > 1) $billingInterval .= 's';

$pricingLabel = '';
$invoiceAmount = (float)($contract['total'] ?? 0);
if ($contract['pricing_type'] === 'per_invoice') {
    $pricingLabel = 'Recurring Amount (per invoice)';
} else {
    $pricingLabel = 'Fixed Total (billed over time)';
    // Calculate invoice amount with tax and discount
    $subtotal = (float)$contract['subtotal'];
    $discountType = $contract['discount_type'] ?? 'none';
    $discountValue = (float)($contract['discount_value'] ?? 0);
    $discount = 0;
    if ($discountType === 'percent') {
        $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
    } elseif ($discountType === 'fixed') {
        $discount = $discountValue;
    }
    $taxable = max(0, $subtotal - $discount);
    $tax = max(0, (float)$contract['tax_percent']) * $taxable / 100;
}

$depositType = $contract['deposit_type'] ?? 'none';
$depositCalc = (float)($contract['deposit_amount'] ?? 0);

$showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
$isOngoing = empty($contract['end_date']);
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Long-term Service Contract</div>
  <div style="text-align:center;color:#6b7280;margin-bottom:16px;font-size:13px">Recurring Billing Agreement</div>
  
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <div class="no-print document-actions">
    <a href="javascript:history.back()" class="btn btn-sm">Back</a>
    <a href="/?page=contract/long-term-contract-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
    <a href="/?page=contract/long-term-contract-pdf&id=<?php echo (int)$id; ?>" download="longterm-contract-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?>.pdf" class="btn btn-sm">Download</a>
    <?php $contractStatus = strtolower((string)($contract['status'] ?? '')); ?>
    <?php if (in_array($contractStatus, ['pending', 'active', 'paused'], true)): ?>
      <a href="/?page=contract/contracts-edit&id=<?php echo (int)$id; ?>" class="btn btn-sm">Edit</a>
    <?php endif; ?>
    <?php if (!in_array($contractStatus, ['denied','cancelled','void'], true)): ?>
      <form method="post" action="/?page=contract/email-send" style="display:inline">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="type" value="contract">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
        <button type="submit" class="btn btn-sm">Email</button>
      </form>
    <?php endif; ?>
    <?php if (in_array($contractStatus, ['draft','pending'], true) && empty($contract['signed_at']) && empty($contract['signed_pdf_path'])): ?>
      <form method="post" action="/?page=contract/contract-sign" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <input id="upload-signed-lt" type="file" name="signed_pdf" accept="application/pdf,.pdf" style="display:none" data-submit-on-file required>
        <button type="button" data-file-picker-target="upload-signed-lt" class="btn btn-sm">Upload Signed PDF</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($contract['signed_pdf_path'])): ?>
      <a href="<?php echo htmlspecialchars($contract['signed_pdf_path']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success">View Signed PDF</a>
    <?php endif; ?>
    <?php
      $noInvoicesYet = (int)($contract['invoices_generated'] ?? 0) === 0 && empty($contract['last_invoice_date']);
      $canGenerateFirstInvoice = $contractStatus === 'active' && !empty($contract['signed_pdf_path']) && $noInvoicesYet;
      $firstInvoiceButtonLabel = empty($contract['next_invoice_date']) ? 'Start Billing' : 'Generate Invoice Now';
    ?>
    <?php if ($canGenerateFirstInvoice): ?>
      <form method="post" action="/?page=long-term-contract-start-billing" style="display:inline">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm"><?php echo htmlspecialchars($firstInvoiceButtonLabel); ?></button>
      </form>
    <?php endif; ?>
    <?php if ($latestLongTermInvoice): ?>
      <?php if (empty($latestLongTermInvoice['sent_at'])): ?>
        <form method="post" action="/?page=invoice/email-send" style="display:inline">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="type" value="invoice">
          <input type="hidden" name="id" value="<?php echo (int)$latestLongTermInvoice['id']; ?>">
          <input type="hidden" name="redirect_to" value="/?page=invoice/invoice-details&id=<?php echo (int)$latestLongTermInvoice['id']; ?>">
          <button type="submit" class="btn btn-sm">Email Latest Invoice</button>
        </form>
      <?php else: ?>
        <a href="/?page=invoice/invoice-details&id=<?php echo (int)$latestLongTermInvoice['id']; ?>" class="btn btn-sm">Latest Invoice</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="/?page=invoice/recurring-invoices-list&status=all&contract_id=<?php echo (int)$id; ?>#invoice-history" class="btn btn-sm">Invoice History</a>
    <?php if ($contractStatus === 'active'): ?>
      <form method="post" action="/?page=long-term-contract-pause" style="display:inline">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Pause Billing</button>
      </form>
    <?php elseif ($contractStatus === 'paused'): ?>
      <form method="post" action="/?page=long-term-contract-resume" style="display:inline">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Resume Billing</button>
      </form>
    <?php endif; ?>
    <?php if (in_array($contractStatus, ['pending', 'active', 'paused'], true)): ?>
      <form method="post" action="/?page=long-term-contract-terminate" style="display:inline" onsubmit="return confirm('Terminate this long-term contract? Future recurring invoices will stop.');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Terminate</button>
      </form>
    <?php endif; ?>
    <?php if (!in_array($contractStatus, ['cancelled', 'completed', 'void'], true)): ?>
      <form method="post" action="/?page=contract/contract-void" onsubmit="return confirm('Void this contract and linked invoices?')" style="display:inline">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Void</button>
      </form>
    <?php endif; ?>
    <?php if (in_array($contractStatus, ['denied', 'cancelled', 'void'], true)): ?>
      <form method="post" action="/?page=document-reenable" style="display:inline" onsubmit="return confirm('Re-enable this contract? It will be set back to pending status.');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="type" value="contract">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Re-enable</button>
      </form>
    <?php endif; ?>
    <form method="post" action="/?page=document-date-update" style="display:inline" onsubmit="return confirm('Update document date to today? This will refresh the date shown on the PDF.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="contract">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" class="btn btn-sm">Update Document Date</button>
    </form>
    <?php if (!in_array($contractStatus, ['denied','cancelled','void'], true)): ?>
      <button type="button" onclick="generatePublicLink()" class="btn btn-sm">Share Link</button>
    <?php endif; ?>
  </div>
  <?php if (!empty($_GET['emailed'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Email sent.</div>
  <?php elseif (!empty($_GET['email_err'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['updated'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Contract updated successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['voided'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db">Contract voided successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['reenabled'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Contract re-enabled successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['date_updated'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd">Document date updated successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['service_saved']) || !empty($_GET['service_updated'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#166534;border:1px solid #86efac">Service terms saved. Approved changes take effect at renewal; pending changes require approval.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['service_invoice_generated'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd">The service was due, so its recurring invoice was generated immediately<?php echo !empty($_GET['service_invoice_sent']) ? ' and emailed automatically' : ''; ?>.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['proration_sent'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#166534;border:1px solid #86efac">The prorated invoice was generated and emailed.</div>
  <?php elseif (!empty($_GET['proration_send_error'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff7ed;color:#9a3412;border:1px solid #fdba74">The prorated invoice was generated, but email delivery failed. Open Invoice History to send it manually.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['service_error'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#9f1239;border:1px solid #fda4af"><?php echo htmlspecialchars((string)$_GET['service_error']); ?></div>
  <?php endif; ?>

  <?php require __DIR__ . '/../../components/recurring_service_panel.php'; ?>

  <div class="no-print" style="padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151">
    <strong>Created:</strong> <?php echo !empty($contract['created_at']) ? date('M j, Y g:i A', strtotime($contract['created_at'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <strong>Document Date:</strong> <?php echo !empty($contract['document_date']) ? date('M j, Y g:i A', strtotime($contract['document_date'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <?php echo pa_public_link_status_badge_html($pdo, 'contract', $id); ?>
    <?php if (!empty($contract['document_date_updated_at'])): ?>
      <span style="margin-left:8px;color:#6b7280;font-size:12px">(Updated: <?php echo date('M j, Y g:i A', strtotime($contract['document_date_updated_at'])); ?>)</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php
  $brand = $appConfig['brand_name'] ?? 'Project Alpha';
  $logoConf = trim((string)($appConfig['logo_path'] ?? ''));
  $projectRoot = realpath(__DIR__ . '/../../../../');
  $defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.png') : '';
  $logoPath = $logoConf !== '' ? $logoConf : $defaultLogo;
  $isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;
  
  if (preg_match('/page=serve-upload/i', $logoPath)) {
    $parsed = parse_url($logoPath);
    if (!empty($parsed['query'])) {
      parse_str($parsed['query'], $q);
      if (!empty($q['file'])) {
        $fname = basename($q['file']);
        $bases = ['/var/www/config/uploads'];
        foreach ($bases as $b) {
          $candidate = @realpath(rtrim($b, '/\\') . DIRECTORY_SEPARATOR . $fname);
          if ($candidate !== false && is_file($candidate)) { $logoPath = $candidate; $isUrl = false; break; }
        }
      }
    }
  }
  
  if (!$isUrl) {
    $root = $projectRoot ?: realpath(__DIR__ . '/../../../../');
    if ($logoPath !== '' && ($logoPath[0] === '/' || $logoPath[0] === '\\')) {
      if ($root) {
        $candidate = @realpath($root . $logoPath);
        if ($candidate) { $logoPath = $candidate; }
      }
    }
  }
  $canShowLogo = $isUrl || @is_file($logoPath);
  $logoSrc = $logoPath;
  if ($canShowLogo && !$isUrl) {
    $imgContents = @file_get_contents($logoPath);
    if ($imgContents !== false) {
      $mime = preg_match('/\.svg$/i', $logoPath) ? 'image/svg+xml' : 'image/png';
      $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($imgContents);
    }
  }
  ?>
  
  <table style="width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse">
    <tr>
      <td style="vertical-align:middle;width:70%">
        <div style="font-weight:700;font-size:20px"><?php echo htmlspecialchars($brand); ?></div>
        <?php if (!empty($contract['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Job <?php echo htmlspecialchars($contract['project_code']); ?></div><?php endif; ?>
        <?php if (!empty($contract['project_id'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project <?php echo htmlspecialchars($contract['project_id']); ?></div><?php endif; ?>
      </td>
      <td style="vertical-align:middle;width:30%;text-align:right">
        <?php if ($canShowLogo): ?>
          <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <!-- Contract Details Box -->
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb;background:#f9fafb">
    <tr>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Start Date</div>
        <div style="font-weight:600;color:#111"><?php echo date('M j, Y', strtotime($contract['start_date'])); ?></div>
      </td>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">End Date</div>
        <div style="font-weight:600;color:#111"><?php echo $isOngoing ? 'Ongoing' : date('M j, Y', strtotime($contract['end_date'])); ?></div>
      </td>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Billing Frequency</div>
        <div style="font-weight:600;color:#111"><?php echo htmlspecialchars($billingInterval); ?></div>
        <?php if (($contract['rate_basis'] ?? 'per_period') === 'monthly'): ?>
          <div style="font-size:12px;color:#475569">$<?php echo number_format((float)$contract['monthly_rate'], 2); ?>/month × <?php echo ($contract['billing_interval_unit'] ?? '') === 'year' ? '12' : '1'; ?> month(s)</div>
        <?php endif; ?>
      </td>
      <td style="width:25%;padding:8px;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Status</div>
        <div style="font-weight:600;color:#111;text-transform:capitalize"><?php echo htmlspecialchars($contract['status']); ?></div>
      </td>
    </tr>
  </table>

  <?php if ($showDepositInfo): ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #a7f3d0;background:#ecfdf5">
    <tr>
      <td style="padding:8px;vertical-align:top">
        <div style="font-size:11px;color:#065f46">Initial Deposit Due: <span style="font-weight:700;font-size:14px">$<?php echo number_format($depositCalc, 2); ?></span></div>
      </td>
    </tr>
  </table>
  <?php endif; ?>

  <table style="width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse">
    <tr>
      <td style="vertical-align:top;width:50%;padding-right:12px">
        <div class="font-600">Service Provider</div>
        <?php 
          $fromLines = document_sender_lines($documentSender);
        ?>
        <div><?php foreach ($fromLines as $ln) { echo '<div>'.htmlspecialchars($ln).'</div>'; } ?></div>
        <?php if ($fromPhone || $fromEmail): ?>
          <div style="margin-top:6px;color:#4b5563;font-size:13px">
            <?php if ($fromPhone): ?><div><?php echo format_phone($fromPhone); ?></div><?php endif; ?>
            <?php if ($fromEmail): ?><div><?php echo htmlspecialchars($fromEmail); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
      <td style="vertical-align:top;width:50%;padding-left:12px">
        <div class="font-600">Client</div>
        <?php $recipient = pa_document_recipient($contract); ?>
        <div><?php foreach ($recipient['lines'] as $ln) { echo '<div>'.htmlspecialchars($ln).'</div>'; } ?></div>
        <?php if ($recipient['phone'] !== null || $recipient['email'] !== null): ?>
          <div style="margin-top:6px;color:#4b5563;font-size:13px">
            <?php if ($recipient['phone'] !== null): ?><div><?php echo htmlspecialchars(format_phone($recipient['phone']), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($recipient['email'] !== null): ?><div><?php echo htmlspecialchars($recipient['email']); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <?php
  $scopeText = trim((string)($contract['scope'] ?? ''));
  $scopeEnabled = !isset($appConfig['contract_scope_enabled']) || !empty($appConfig['contract_scope_enabled']);
  if ($scopeEnabled && $scopeText !== ''):
  ?>
  <div style="margin:16px 0;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
    <div style="font-weight:600;margin-bottom:8px">Scope of Work</div>
    <div style="white-space:pre-wrap;font-size:14px;line-height:1.6;color:#374151"><?php echo nl2br(htmlspecialchars($scopeText)); ?></div>
  </div>
  <?php endif; ?>

  <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin-top:16px">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee;background:#f9fafb">
        <th colspan="4" style="padding:12px;font-size:15px"><?php echo htmlspecialchars($pricingLabel); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($contract['pricing_type'] === 'per_invoice'): ?>
        <tr>
          <td colspan="3" style="padding:12px"><?php echo !empty($contract['scope']) ? htmlspecialchars($contract['scope']) : 'Recurring service fee'; ?> (billed <?php echo htmlspecialchars(strtolower($billingInterval)); ?>)</td>
          <td style="padding:12px;text-align:right;font-weight:600">$<?php echo number_format($contract['price_per_invoice'], 2); ?></td>
        </tr>
        <?php echo pricing_adjustment_client_row($pricingSnapshot,'padding:10px','font-weight:600',2); ?>
      <?php else: ?>
        <?php if ($items): ?>
          <tr style="border-bottom:1px solid #eee">
            <th style="padding:10px">Description</th>
            <th style="padding:10px"><?php echo $isHourlyBilling ? 'Est. Hours' : 'Qty'; ?></th>
            <th style="padding:10px"><?php echo $isHourlyBilling ? 'Hourly Rate' : 'Unit'; ?></th>
            <th style="padding:10px">Line Total</th>
          </tr>
          <?php foreach ($items as $it): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><?php echo htmlspecialchars($it['description']); ?></td>
            <td style="padding:10px"><?php echo number_format($it['quantity'],2); ?></td>
            <td style="padding:10px">$<?php echo number_format($it['unit_price'],2); ?></td>
            <td style="padding:10px">$<?php echo number_format($it['line_total'],2); ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:600">Subtotal</td>
          <td style="padding:10px">$<?php echo number_format($contract['subtotal'] ?? 0,2); ?></td>
        </tr>
        <?php echo pricing_adjustment_client_row($pricingSnapshot,'padding:10px','font-weight:600',2); ?>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:600">Discount</td>
          <td style="padding:10px">
            <?php if (($contract['discount_type'] ?? 'none')==='percent'): ?>
              <?php echo number_format($contract['discount_value'] ?? 0,2); ?>%
            <?php elseif (($contract['discount_type'] ?? 'none')==='fixed'): ?>
              $<?php echo number_format($contract['discount_value'] ?? 0,2); ?>
            <?php else: ?>
              $0.00
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:600">Tax</td>
          <td style="padding:10px"><?php echo number_format($contract['tax_percent'] ?? 0,2); ?>%</td>
        </tr>
        <?php if (!$isOngoing): ?>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:700">Contract Total</td>
          <td style="padding:10px;font-weight:700">$<?php echo number_format($contract['total'] ?? 0,2); ?></td>
        </tr>
        <?php endif; ?>
      <?php endif; ?>
      <tr style="background:#ecfdf5;border-top:2px solid #10b981">
        <td colspan="3" style="padding:12px;font-weight:700;font-size:15px;color:#065f46">Amount Per Invoice</td>
        <td style="padding:12px;font-weight:700;font-size:16px;color:#065f46;text-align:right">$<?php echo number_format($invoiceAmount,2); ?></td>
      </tr>
    </tbody>
  </table>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW') && ($pricingProvenance=pricing_adjustment_staff_provenance($pricingSnapshot))!==''): ?><p class="pricing-provenance" data-pricing-provenance><?php echo htmlspecialchars($pricingProvenance,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?></p><?php endif; ?>


  <div style="margin-top:24px;padding:12px 10px;color:#374151;font-size:13px;line-height:1.4">
    <?php echo htmlspecialchars($appConfig['signature_agreement'] ?? 'By signing below, I acknowledge that this is a multi-page contract and that I have read and agree to the recurring billing terms and conditions.'); ?>
  </div>
  <!-- Signature block -->
  <table style="width:100%;border-collapse:collapse;margin-top:20px">
    <?php foreach ($signatures as $sig): ?>
    <tr>
      <td style="width:65%;height:58px;vertical-align:bottom;padding-right:40px;font-size:12px;color:#4b5563">
        <div style="border-top:1px solid #333;width:100%;height:1px;margin-bottom:4px"></div>
        <?php echo htmlspecialchars($sig['signer_title'] ?? 'Client Signature'); ?>
      </td>
      <td style="width:35%;height:58px;vertical-align:bottom;font-size:12px;color:#4b5563">
        <div style="border-top:1px solid #333;width:100%;height:1px;margin-bottom:4px"></div>
        Date
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div style="page-break-after:always"></div>
  <h3>Terms and Conditions</h3>
  <?php if ($termsText !== ''): ?>
    <div style="white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222;"><?php echo nl2br(htmlspecialchars($termsText)); ?></div>
  <?php else: ?>
    <ul>
      <li>This is a recurring billing agreement. Invoices will be generated automatically every <?php echo htmlspecialchars(strtolower($billingInterval)); ?>.</li>
      <li>Contract begins on <?php echo date('M j, Y', strtotime($contract['start_date'])); ?> and <?php echo $isOngoing ? 'continues until terminated by either party' : 'ends on ' . date('M j, Y', strtotime($contract['end_date'])); ?>.</li>
      <li>Payment due NET 30 unless otherwise specified.</li>
      <li>Termination requires written notice.</li>
      <li>Work product ownership and usage rights per agreement.</li>
    </ul>
  <?php endif; ?>

</section>
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
<?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
<div id="shareLinkModal" data-doc-type="contract" data-doc-id="<?php echo (int)$id; ?>" data-default-days="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0;font-size:18px">Share Long-term Contract Link</h3>
      <button onclick="closeShareModal()" style="border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <div id="shareLinkContent">
      <p style="color:#6b7280;margin:0 0 16px">Generate a public link that clients can use to view and upload a signed copy of this contract.</p>
      <label style="display:block;margin-bottom:12px">
        <div style="font-weight:600;margin-bottom:6px">Expires in days</div>
        <input type="number" id="linkDays" value="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" min="1" max="365" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
      </label>
      <button onclick="createPublicLink()" style="width:100%;padding:12px;background:#4f46e5;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer">Generate Link</button>
    </div>
    <div id="shareLinkResult" style="display:none">
      <div style="padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;margin-bottom:12px">
        <div style="font-weight:600;color:#166534;margin-bottom:4px">Link Generated</div>
        <div style="font-size:13px;color:#15803d" id="linkExpiry"></div>
      </div>
      <div style="position:relative">
        <input type="text" id="generatedLink" readonly style="width:100%;padding:10px;padding-right:80px;border:1px solid #ddd;border-radius:8px;font-size:13px;background:#f9fafb">
        <button onclick="copyLink()" style="position:absolute;right:4px;top:4px;padding:6px 12px;border:0;border-radius:6px;background:#4f46e5;color:#fff;cursor:pointer">Copy</button>
      </div>
    </div>
  </div>
</div>
<script>
function generatePublicLink() { document.getElementById('shareLinkModal').style.display = 'flex'; }
function closeShareModal() { document.getElementById('shareLinkModal').style.display = 'none'; }
function createPublicLink() {
  const formData = new FormData();
  formData.append('type', 'contract');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', document.getElementById('linkDays').value || '<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  fetch('/?page=public-link-create', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (!data.success) { alert(data.error || 'Failed to create link'); return; }
      document.getElementById('shareLinkContent').style.display = 'none';
      document.getElementById('shareLinkResult').style.display = 'block';
      document.getElementById('generatedLink').value = data.url;
      document.getElementById('linkExpiry').textContent = data.expires_at ? 'Expires: ' + data.expires_at : '';
    })
    .catch(() => alert('Failed to create link'));
}
function copyLink() {
  const input = document.getElementById('generatedLink');
  input.select();
  document.execCommand('copy');
}
document.getElementById('shareLinkModal').addEventListener('click', function(e) { if (e.target === this) closeShareModal(); });
</script>
<?php endif; ?>
