<?php
// src/views/pages/quote/long-term-quote-print.php
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
    require_record_ownership($pdo, 'quotes', $id);
}
$q = $pdo->prepare('SELECT q.*, cl.name client_name, cl.email client_email, cl.phone client_phone, cl.address_line1 client_address_line1, cl.address_line2 client_address_line2, cl.city client_city, cl.state client_state, cl.postal_code client_postal_code, cl.country client_country, o.name organization_name, o.general_email organization_email, o.general_phone organization_phone, o.address_line1 organization_address_line1, o.address_line2 organization_address_line2, o.city organization_city, o.state organization_state, o.postal_code organization_postal_code, o.country organization_country FROM quotes q JOIN clients cl ON cl.id=q.client_id' . pa_document_effective_organization_joins('q', 'cl') . ' WHERE q.id=? AND q.quote_type="long_term"');
$q->execute([$id]);
$quote = $q->fetch(PDO::FETCH_ASSOC);
if(!$quote){ echo '<p>Long-term quote not found</p>'; return; }
$pricingSnapshot=(int)($quote['organization_id']??0)>0?pricing_document_snapshot($pdo,(int)$quote['organization_id'],'quote',$id,max(1,(int)($quote['revision_number']??1))):null;

// Get items if fixed_total pricing
$items = [];
if ($quote['pricing_type'] === 'fixed_total') {
    $itemsQuery = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total, billing_unit FROM quote_items WHERE quote_id=?');
    $itemsQuery->execute([$id]);
    $items = $itemsQuery->fetchAll();
}
$isHourlyBilling = ($quote['billing_mode'] ?? 'fixed') === 'hourly';

require_once __DIR__ . '/../../../utils/format.php';
$documentSender = document_sender_for_creator($pdo, $appConfig, !empty($quote['created_by']) ? (int)$quote['created_by'] : null);
$fromName = $documentSender['name'] ?? '';
$fromAddress = implode("\n", document_sender_lines($documentSender));
$fromPhone = $documentSender['phone'] ?? '';
$fromEmail = $documentSender['email'] ?? '';

// Resolve terms
$termsText = '';
if (!empty($quote['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
    $pm->execute([$quote['project_code']]);
    $pt = (string)$pm->fetchColumn();
    if (trim($pt) !== '') { $termsText = trim($pt); }
  } catch (Throwable $e) { /* ignore */ }
}
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }

// Calculate billing display
$billingInterval = $quote['billing_interval_count'] . ' ' . ucfirst($quote['billing_interval_unit']);
if ($quote['billing_interval_count'] > 1) $billingInterval .= 's';

$pricingLabel = '';
$invoiceAmount = (float)($quote['total'] ?? 0);
if ($quote['pricing_type'] === 'per_invoice') {
    $pricingLabel = 'Recurring Amount (per invoice)';
} else {
    $pricingLabel = 'Fixed Total (billed over time)';
    // Calculate invoice amount with tax and discount
    $subtotal = (float)$quote['subtotal'];
    $discountType = $quote['discount_type'] ?? 'none';
    $discountValue = (float)($quote['discount_value'] ?? 0);
    $discount = 0;
    if ($discountType === 'percent') {
        $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
    } elseif ($discountType === 'fixed') {
        $discount = $discountValue;
    }
    $taxable = max(0, $subtotal - $discount);
    $tax = max(0, (float)$quote['tax_percent']) * $taxable / 100;
}

$depositType = $quote['deposit_type'] ?? 'none';
$depositValue = (float)($quote['deposit_amount'] ?? 0);
$quoteTotal = (float)($quote['total'] ?? 0);
$depositCalc = 0;
if ($depositType === 'percent') {
    $depositCalc = max(0, min(100, $depositValue)) * $quoteTotal / 100;
} elseif ($depositType === 'fixed') {
    $depositCalc = $depositValue;
}

$showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
$isOngoing = empty($quote['end_date']);
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Long-term Service Quote</div>
  <div style="text-align:center;color:#6b7280;margin-bottom:16px;font-size:13px">Recurring Billing Proposal</div>
  <div style="text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px">Valid for <?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?> days</div>
  
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <div class="no-print document-actions">
    <a href="javascript:history.back()" class="btn btn-sm">Back</a>
    <a href="/?page=quote/long-term-quote-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
    <a href="/?page=quote/long-term-quote-pdf&id=<?php echo (int)$id; ?>" download="longterm-quote-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?>.pdf" class="btn btn-sm">Download</a>
    <?php if (!empty($quote['status']) && strtolower($quote['status']) !== 'rejected'): ?>
    <form method="post" action="/?page=quote/email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" class="btn btn-sm">Email</button>
    </form>
    <?php endif; ?>
    <?php if (in_array(($quote['status'] ?? ''), ['draft','pending'], true)): ?>
      <a href="/?page=quote/quotes-edit&id=<?php echo (int)$id; ?>" class="btn btn-sm">Edit</a>
    <?php if (($quote['status'] ?? '') === 'pending'): ?>
      <form method="post" action="/?page=quote/quote-approve" style="display:inline">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Approve</button>
      </form>
      <form method="post" action="/?page=quote/quote-reject" style="display:inline" onsubmit="return confirm('Reject this quote?')">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Deny</button>
      </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!in_array(strtolower((string)($quote['status']??'')), ['draft','pending'], true)): ?>
      <form method="post" action="/?page=quote/quote-clone" style="display:inline" onsubmit="return confirm('Clone this quote into a new editable draft?');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm">Clone to new draft</button>
      </form>
    <?php endif; ?>
    <?php if (in_array(strtolower((string)($quote['status']??'')), ['draft','pending'], true)): ?>
    <form method="post" action="/?page=document-date-update" style="display:inline" onsubmit="return confirm('Update document date to today? This will refresh the date shown on the PDF.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" class="btn btn-sm">Update Document Date</button>
    </form>
    <?php endif; ?>
    <?php if (strtolower($quote['status'] ?? '') !== 'rejected'): ?>
      <button type="button" onclick="generatePublicLink()" class="btn btn-sm">Share Link</button>
    <?php endif; ?>
  </div>
  <?php if (!empty($_GET['emailed'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Email sent.</div>
  <?php elseif (!empty($_GET['email_err'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['reenabled'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Quote re-enabled successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['date_updated'])): ?>
    <div class="no-print" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd">Document date updated successfully.</div>
  <?php endif; ?>
  <div class="no-print" style="padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151">
    <strong>Created:</strong> <?php echo !empty($quote['created_at']) ? date('M j, Y g:i A', strtotime($quote['created_at'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <strong>Document Date:</strong> <?php echo !empty($quote['document_date']) ? date('M j, Y g:i A', strtotime($quote['document_date'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <?php echo pa_public_link_status_badge_html($pdo, 'quote', $id); ?>
    <?php if (!empty($quote['document_date_updated_at'])): ?>
      <span style="margin-left:8px;color:#6b7280;font-size:12px">(Updated: <?php echo date('M j, Y g:i A', strtotime($quote['document_date_updated_at'])); ?>)</span>
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
        <div style="color:#374151;font-size:13px;margin-top:2px">Long-term Quote Q-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?></div>
        <?php if (!empty($quote['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Job <?php echo htmlspecialchars($quote['project_code']); ?></div><?php endif; ?>
        <?php if (!empty($quote['project_id'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project <?php echo htmlspecialchars($quote['project_id']); ?></div><?php endif; ?>
      </td>
      <td style="vertical-align:middle;width:30%;text-align:right">
        <?php if ($canShowLogo): ?>
          <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <!-- Quote Details Box -->
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb;background:#f9fafb">
    <tr>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Start Date</div>
        <div style="font-weight:600;color:#111"><?php echo date('M j, Y', strtotime($quote['start_date'])); ?></div>
      </td>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">End Date</div>
        <div style="font-weight:600;color:#111"><?php echo $isOngoing ? 'Ongoing' : date('M j, Y', strtotime($quote['end_date'])); ?></div>
      </td>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Billing Frequency</div>
        <div style="font-weight:600;color:#111"><?php echo htmlspecialchars($billingInterval); ?></div>
      </td>
      <td style="width:25%;padding:8px;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Status</div>
        <div style="font-weight:600;color:#111;text-transform:capitalize"><?php echo htmlspecialchars($quote['status']); ?></div>
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
        <div class="font-600">From</div>
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
        <div class="font-600">To</div>
        <?php $recipient = pa_document_recipient($quote); ?>
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

  <?php if (!empty($quote['scope'])): ?>
  <div style="margin:16px 0;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
    <div style="font-weight:600;margin-bottom:8px">Scope of Work</div>
    <div style="white-space:pre-wrap;font-size:14px;line-height:1.6;color:#374151"><?php echo nl2br(htmlspecialchars($quote['scope'])); ?></div>
  </div>
  <?php endif; ?>

  <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin-top:16px">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee;background:#f9fafb">
        <th colspan="4" style="padding:12px;font-size:15px"><?php echo htmlspecialchars($pricingLabel); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($quote['pricing_type'] === 'per_invoice'): ?>
        <tr>
          <td colspan="3" style="padding:12px"><?php echo !empty($quote['scope']) ? htmlspecialchars($quote['scope']) : 'Recurring service fee'; ?> (billed <?php echo htmlspecialchars(strtolower($billingInterval)); ?>)</td>
          <td style="padding:12px;text-align:right;font-weight:600">$<?php echo number_format($quote['price_per_invoice'], 2); ?></td>
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
          <td style="padding:10px">$<?php echo number_format($quote['subtotal'] ?? 0,2); ?></td>
        </tr>
        <?php echo pricing_adjustment_client_row($pricingSnapshot,'padding:10px','font-weight:600',2); ?>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:600">Discount</td>
          <td style="padding:10px">
            <?php if (($quote['discount_type'] ?? 'none')==='percent'): ?>
              <?php echo number_format($quote['discount_value'] ?? 0,2); ?>%
            <?php elseif (($quote['discount_type'] ?? 'none')==='fixed'): ?>
              $<?php echo number_format($quote['discount_value'] ?? 0,2); ?>
            <?php else: ?>
              $0.00
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:600">Tax</td>
          <td style="padding:10px"><?php echo number_format($quote['tax_percent'] ?? 0,2); ?>%</td>
        </tr>
        <?php if (!$isOngoing): ?>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:700">Quote Total</td>
          <td style="padding:10px;font-weight:700">$<?php echo number_format($quote['total'] ?? 0,2); ?></td>
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

  <?php if (!isset($appConfig['quotes_show_terms']) || (int)$appConfig['quotes_show_terms'] === 1): ?>
  <div style="page-break-after:always"></div>
  <h3>Terms and Conditions</h3>
  <?php if ($termsText !== ''): ?>
    <div style="white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222;"><?php echo nl2br(htmlspecialchars($termsText)); ?></div>
  <?php else: ?>
    <ul>
      <li>This is a recurring billing proposal. If approved, invoices will be generated automatically every <?php echo htmlspecialchars(strtolower($billingInterval)); ?>.</li>
      <li>Service begins on <?php echo date('M j, Y', strtotime($quote['start_date'])); ?> and <?php echo $isOngoing ? 'continues until terminated by either party' : 'ends on ' . date('M j, Y', strtotime($quote['end_date'])); ?>.</li>
      <li>Payment due NET 30 unless otherwise specified.</li>
      <li>Termination requires written notice.</li>
      <li>Work product ownership and usage rights per agreement.</li>
    </ul>
  <?php endif; ?>
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
<div id="shareLinkModal" data-doc-type="quote" data-doc-id="<?php echo (int)$id; ?>" data-default-days="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0;font-size:18px">Share Long-term Quote Link</h3>
      <button onclick="closeShareModal()" style="border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <div id="shareLinkContent">
      <p style="color:#6b7280;margin:0 0 16px">Generate a public link that clients can use to view and approve or deny this quote.</p>
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
  formData.append('type', 'quote');
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
