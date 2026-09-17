<?php
// src/views/pages/quote/quote-details.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_recipient.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../../utils/document_sender.php';
require_once __DIR__ . '/../../../utils/public_links.php';
require_once __DIR__ . '/../../../utils/document_pricing_adjustments.php';
$id = (int)($_GET['id'] ?? 0);
require_once __DIR__ . '/../../../utils/acl.php';
if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')) {
    require_record_ownership($pdo, 'quotes', $id);
}
$stmt = $pdo->prepare('SELECT q.*, c.name client_name, c.email client_email, c.phone client_phone, c.address_line1 client_address_line1, c.address_line2 client_address_line2, c.city client_city, c.state client_state, c.postal_code client_postal_code, c.country client_country, o.name organization_name, o.general_email organization_email, o.general_phone organization_phone, o.address_line1 organization_address_line1, o.address_line2 organization_address_line2, o.city organization_city, o.state organization_state, o.postal_code organization_postal_code, o.country organization_country FROM quotes q JOIN clients c ON c.id=q.client_id' . pa_document_effective_organization_joins('q', 'c') . ' WHERE q.id=?');
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$quote){ echo '<p>Quote not found</p>'; return; }
$pricingSnapshot=(int)($quote['organization_id']??0)>0?pricing_document_snapshot($pdo,(int)$quote['organization_id'],'quote',$id,max(1,(int)($quote['revision_number']??1))):null;

// Determine quote type for back link and display
$quoteType = $quote['quote_type'] ?? 'regular';
$backPage = 'quote/quotes-list';
$quoteTypeLabel = 'Quote';
$approveConfirm = 'Approve this quote and generate contract + invoice?';
if ($quoteType === 'long_term') {
    $backPage = 'quote/long-term-quotes-list';
    $quoteTypeLabel = 'Long-Term Quote';
    $approveConfirm = 'Approve this long-term quote and generate contract?';
} elseif ($quoteType === 'on_demand') {
    $backPage = 'quote/on-demand-quotes-list';
    $quoteTypeLabel = 'On-Demand Quote';
    $approveConfirm = 'Approve this on-demand quote and generate contract?';
}
$items = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total, billing_unit FROM quote_items WHERE quote_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
$isHourlyBilling = ($quote['billing_mode'] ?? 'fixed') === 'hourly';
require_once __DIR__ . '/../../../utils/format.php';
$documentSender = document_sender_for_creator($pdo, $appConfig, !empty($quote['created_by']) ? (int)$quote['created_by'] : null);
$fromName = $documentSender['name'] ?? '';
$fromAddress = implode("\n", document_sender_lines($documentSender));
$fromPhone = $documentSender['phone'] ?? '';
$fromEmail = $documentSender['email'] ?? '';
// Resolve terms: project-level terms override quote terms override app settings
$termsText = '';
if (!empty($quote['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
    $pm->execute([$quote['project_code']]);
    $pt = (string)$pm->fetchColumn();
    if (trim($pt) !== '') { $termsText = trim($pt); }
  } catch (Throwable $e) { /* ignore */ }
}
if ($termsText === '') { $termsText = trim((string)($quote['terms'] ?? '')); }
if ($termsText === '' && ($quote['quote_type'] ?? '') === 'on_demand') { $termsText = trim((string)($appConfig['on_demand_terms'] ?? '')); }
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
// Detect PDF mode for conditional page breaks
$isPdf = defined('PDF_MODE');
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px"><?php echo htmlspecialchars($quoteTypeLabel); ?></div>
  <div style="text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px">Valid for <?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?> days</div>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <?php 
    // Status banner styling
    $status = strtolower($quote['status'] ?? 'pending');
    $statusColors = [
      'pending' => ['bg' => '#fffbeb', 'text' => '#92400e', 'border' => '#fbbf24'],
      'approved' => ['bg' => '#ecfdf5', 'text' => '#065f46', 'border' => '#10b981'],
      'rejected' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#ef4444']
    ];
    $colors = $statusColors[$status] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
  ?>
  <div class="no-print" style="padding:12px 16px;background:<?php echo $colors['bg']; ?>;color:<?php echo $colors['text']; ?>;border-left:4px solid <?php echo $colors['border']; ?>;border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px">
    Status: <?php echo htmlspecialchars($quote['status']); ?>
  </div>
  <div class="no-print document-actions">
    <a href="/?page=<?php echo htmlspecialchars($backPage); ?>" class="btn btn-sm">Back</a>
    <a href="/?page=quote/quote-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
    <a href="/?page=quote/quote-pdf&id=<?php echo (int)$id; ?>" download="quote-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?>.pdf" class="btn btn-sm">Download</a>
    <?php if (in_array($quote['status'], ['draft','pending'], true)): ?>
      <a href="/?page=quote/quotes-edit&id=<?php echo (int)$id; ?>" class="btn btn-sm">Edit</a>
    <?php endif; ?>
    <?php if((int)($quote['last_sent_revision']??0)>0&&(int)($quote['revision_number']??1)>(int)$quote['last_sent_revision']): ?><span class="alert alert-warning" style="padding:6px 9px">Revised <?php echo htmlspecialchars((string)($quote['revision_updated_at']??'')); ?> · Resend required</span><?php endif; ?>
    <?php if (!empty($quote['status']) && strtolower($quote['status']) !== 'rejected'): ?>
    <form method="post" action="/?page=quote/email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" class="btn btn-sm">Email</button>
    </form>
    <?php endif; ?>
    <?php if ($quote['status'] === 'pending'): ?>
      <form method="post" action="/?page=quote/quote-approve" style="display:inline" onsubmit="return confirm('<?php echo htmlspecialchars($approveConfirm); ?>');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm btn-success">Approve</button>
      </form>
      <form method="post" action="/?page=quote/quote-reject" style="display:inline" onsubmit="return confirm('Deny this quote?');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm btn-danger">Deny</button>
      </form>
    <?php endif; ?>
    <?php if (!in_array(strtolower((string)($quote['status']??'')), ['draft','pending'], true)): ?>
    <form method="post" action="/?page=quote/quote-clone" style="display:inline" onsubmit="return confirm('Clone this quote into a new editable draft?');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" class="btn btn-sm btn-warning">Clone to new draft</button>
    </form>
    <?php endif; ?>
    <?php if (in_array(strtolower((string)($quote['status']??'')), ['draft','pending'], true)): ?>
    <form method="post" action="/?page=document-date-update" style="display:inline" onsubmit="return confirm('Update document date to today? This will refresh the date shown on the PDF.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" class="btn btn-sm btn-info">Update Document Date</button>
    </form>
    <?php endif; ?>
    <?php if (strtolower($quote['status'] ?? '') !== 'rejected'): ?>
    <button type="button" onclick="generatePublicLink()" class="btn btn-sm btn-info">Share Link</button>
    <?php endif; ?>
  </div>
  <?php if (!empty($_GET['error'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#fee2e2;color:#991b1b;border-radius:6px;margin-bottom:8px;font-size:14px">⚠ Error: <?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['reenabled'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Quote re-enabled successfully</div>
  <?php endif; ?>
  <?php if (!empty($_GET['date_updated'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Document date updated successfully</div>
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
  // Resolve default logo under project root public/assets
  $projectRoot = realpath(__DIR__ . '/../../../../');
  $defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.png') : '';
  $logoPath = $logoConf !== '' ? $logoConf : $defaultLogo;
  $isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;
  // If the configured logo is an internal routed URL like "/?page=serve-upload&file=...",
  // resolve it to the actual uploaded file so we can embed it for Dompdf.
  if (preg_match('/page=serve-upload/i', $logoPath)) {
    $parsed = parse_url($logoPath);
    if (!empty($parsed['query'])) {
      parse_str($parsed['query'], $q);
      if (!empty($q['file'])) {
        $fname = basename($q['file']);
        $bases = [];
        // Prefer project-root config/uploads
        $projectRoot = realpath(__DIR__ . '/../../../../');
        if ($projectRoot) {
          $cfg = realpath($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads');
          if ($cfg) { $bases[] = $cfg; } else { $bases[] = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads'; }
          $internal = realpath(__DIR__ . '/../uploads');
          $bases[] = $internal ? $internal : (__DIR__ . '/../uploads');
        }
        // Container path
        $bases[] = '/var/www/config/uploads';
        foreach ($bases as $b) {
          $candidate = @realpath(rtrim($b, '/\\') . DIRECTORY_SEPARATOR . $fname);
          if ($candidate !== false && is_file($candidate)) { $logoPath = $candidate; $isUrl = false; break; }
        }
      }
    }
  }
  if (!$isUrl) {
    // Map leading-slash paths like /public/... and /config/uploads/... to the project root
    $root = $projectRoot ?: realpath(__DIR__ . '/../../../../');
    if ($logoPath !== '' && ($logoPath[0] === '/' || $logoPath[0] === '\\')) {
      if ($root) {
        $candidate = @realpath($root . $logoPath);
        if ($candidate) { $logoPath = $candidate; }
      }
    } else {
      // For relative paths (e.g., public/assets/logo.png or config/uploads/logo.png)
      if ($root) {
        $candidate = @realpath($root . DIRECTORY_SEPARATOR . $logoPath);
        if ($candidate) { $logoPath = $candidate; }
      }
    }
  }
  $canShowLogo = $isUrl || @is_file($logoPath);
  // Prefer embedding local images as data URIs so Dompdf can render them reliably
  $logoSrc = $logoPath;
  if ($canShowLogo && !$isUrl) {
    // Try to read the file and build a data URI (base64). This avoids file:// or remote restrictions
    $imgContents = @file_get_contents($logoPath);
    if ($imgContents !== false) {
      $mime = null;
      // Prefer explicit SVG mime type when extension indicates SVG
      if (preg_match('/\.svg$/i', $logoPath)) {
        $mime = 'image/svg+xml';
      } else {
        if (function_exists('finfo_open')) {
          $finfo = @finfo_open(FILEINFO_MIME_TYPE);
          if ($finfo) {
            $det = @finfo_buffer($finfo, $imgContents);
            if ($det) { $mime = $det; }
            if (PHP_VERSION_ID < 80500) {
                @finfo_close($finfo);
            }
          }
        }
        if ($mime === null) { $mime = 'image/png'; }
      }
      $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($imgContents);
    } else {
      // If embedding failed, fall back to a file:/// URL which Dompdf can sometimes read
      $normalized = str_replace('\\', '/', $logoPath);
      if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || strpos($normalized, '/') === 0) {
        $logoSrc = 'file:///' . ltrim($normalized, '/');
      }
    }
  }
  ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse">
    <tr>
      <td style="vertical-align:middle;width:70%">
        <div style="font-weight:700;font-size:20px"><?php echo htmlspecialchars($brand); ?></div>
        <div style="color:#374151;font-size:13px;margin-top:2px">Quote Q-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?></div>
        <?php if (!empty($quote['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Job <?php echo htmlspecialchars($quote['project_code']); ?></div><?php endif; ?>
        <?php if (!empty($quote['project_id'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project <?php echo htmlspecialchars($quote['project_id']); ?></div><?php endif; ?>
      </td>
      <td style="vertical-align:middle;width:30%;text-align:right">
        <?php if ($canShowLogo): ?>
          <?php if (!$isUrl && preg_match('/\.svg$/i', $logoPath) && is_file($logoPath)): ?>
            <?php if (defined('PDF_MODE')): ?>
              <?php echo @file_get_contents($logoPath); ?>
            <?php else: ?>
              <?php $svgContents = @file_get_contents($logoPath); if ($svgContents !== false) { $svgData = 'data:image/svg+xml;base64,'.base64_encode($svgContents); ?>
                <img src="<?php echo htmlspecialchars($svgData); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
              <?php } else { ?>
                <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
              <?php } ?>
            <?php endif; ?>
          <?php else: ?>
            <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <?php 
    $depositType = $quote['deposit_type'] ?? 'none';
    $depositValue = (float)($quote['deposit_amount'] ?? 0);
    $quoteTotal = (float)($quote['total'] ?? 0);
    $depositCalc = 0;
    if ($depositType === 'percent') {
      $depositCalc = max(0, min(100, $depositValue)) * $quoteTotal / 100;
    } elseif ($depositType === 'fixed') {
      $depositCalc = $depositValue;
    }
    $fulfillmentDate = $quote['fulfillment_date'] ?? null;
    $showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
    $showFulfillmentDate = !empty($fulfillmentDate);
    
    // Get custom fields for display
    $documentType = $quote['quote_type'] ?? 'regular';
    
    $customFieldValues = !empty($quote['custom_fields']) ? json_decode($quote['custom_fields'], true) : [];
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
  ?>
  <?php if ($showDepositInfo || $showFulfillmentDate || $hasCustomFields): ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb">
    <tr>
      <?php if ($showDepositInfo): ?>
      <td style="padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Deposit Due: <span style="font-weight:600;color:#059669">$<?php echo number_format($depositCalc, 2); ?></span></div>
      </td>
      <?php endif; ?>
      <?php if ($showFulfillmentDate): ?>
      <td style="padding:8px;<?php echo $hasCustomFields ? 'border-right:1px solid #e5e7eb;' : ''; ?>vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Fulfillment Date: <span style="font-weight:600;color:#2563eb"><?php echo date('M j, Y', strtotime($fulfillmentDate)); ?></span></div>
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
            <?php if ($fromPhone): ?><div><?php echo htmlspecialchars(format_phone($fromPhone), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
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

  <?php
    $scopeText = trim((string)($quote['scope'] ?? ''));
    $scopeEnabled = !isset($appConfig['quote_scope_enabled']) || !empty($appConfig['quote_scope_enabled']);
    if ($scopeEnabled && $scopeText !== ''): 
  ?>
  <div style="page-break-before:auto;margin-top:20px">
    <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;color:#111">Scope of Work</h3>
    <div style="white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px"><?php echo nl2br(htmlspecialchars($scopeText)); ?></div>
  </div>
  <div style="page-break-after:always"></div>
  <?php endif; ?>

  <?php
  // Long-term / on-demand billing summary for quote PDFs
  $qtType = $quote['quote_type'] ?? 'regular';
  if (in_array($qtType, ['long_term', 'on_demand'], true)):
    $qBiCount = (int)($quote['billing_interval_count'] ?? 1);
    $qBiUnit = $quote['billing_interval_unit'] ?? 'month';
    $qBiText = $qBiCount . ' ' . ucfirst($qBiUnit);
    if ($qBiCount > 1) $qBiText .= 's';
    $qSvcDesc = trim((string)($quote['scope'] ?? ''));
    $qAmtPerInv = (float)($quote['price_per_invoice'] ?? 0);
    $qPricingType = $quote['pricing_type'] ?? null;
  ?>
  <div style="margin:16px 0;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px">
    <div style="font-weight:700;font-size:15px;margin-bottom:10px;color:#065f46">
      <?php echo $qtType === 'long_term' ? 'Recurring Billing Summary' : 'On-Demand Billing Summary'; ?>
    </div>
    <?php if ($qSvcDesc !== ''): ?>
      <div style="margin-bottom:8px"><strong>Service:</strong> <?php echo htmlspecialchars($qSvcDesc); ?></div>
    <?php endif; ?>
    <?php if ($qtType === 'long_term'): ?>
      <div style="margin-bottom:8px"><strong>Billing Cycle:</strong> Every <?php echo htmlspecialchars($qBiText); ?></div>
    <?php endif; ?>
    <?php if ($qPricingType === 'per_invoice' || $qtType === 'on_demand'): ?>
      <div style="font-size:16px;font-weight:700;color:#065f46">
        Amount Per Invoice: $<?php echo number_format($qAmtPerInv, 2); ?>
        <?php if ($qtType === 'long_term'): ?>/<?php echo htmlspecialchars(strtolower($qBiUnit)); ?><?php endif; ?>
      </div>
    <?php elseif ($qPricingType === 'fixed_total'): ?>
      <div style="font-size:16px;font-weight:700;color:#065f46">Total: $<?php echo number_format((float)($quote['total'] ?? 0), 2); ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <table style="width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee">
        <th style="padding:10px;width:25%;vertical-align:top;text-align:center">Item</th>
        <th style="padding:10px;width:35%;vertical-align:top">Description</th>
        <th style="padding:10px;width:10%;text-align:right;vertical-align:top"><?php echo $isHourlyBilling ? 'Est. Hours' : 'Qty'; ?></th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top"><?php echo $isHourlyBilling ? 'Hourly Rate' : 'Unit Price'; ?></th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top">Line Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr style="border-top:1px solid #f3f4f6">
        <td style="padding:10px;font-weight:600;vertical-align:top;text-align:center"><?php echo htmlspecialchars($it['item'] ?? ''); ?></td>
        <td style="padding:10px;color:#6b7280;font-size:13px;vertical-align:top"><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top"><?php echo number_format($it['quantity'],2); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['unit_price'],2); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['line_total'],2); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals Section - uses table for PDF compatibility -->
  <table style="width:100%;border-collapse:collapse;margin-top:12px">
    <tr>
      <td style="width:60%"></td>
      <td style="width:40%">
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <td style="padding:8px 12px;text-align:right;color:#6b7280">Subtotal</td>
            <td style="padding:8px 12px;text-align:right;width:120px">$<?php echo number_format($quote['subtotal'],2); ?></td>
          </tr>
          <?php echo pricing_adjustment_client_row($pricingSnapshot,'padding:8px 12px','text-align:right;color:#6b7280'); ?>
          <tr>
            <td style="padding:8px 12px;text-align:right;color:#6b7280">Discount</td>
            <td style="padding:8px 12px;text-align:right">
              <?php if ($quote['discount_type']==='percent'): ?>
                <?php echo number_format($quote['discount_value'],2); ?>%
              <?php elseif ($quote['discount_type']==='fixed'): ?>
                $<?php echo number_format($quote['discount_value'],2); ?>
              <?php else: ?>
                $0.00
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 12px;text-align:right;color:#6b7280">Tax</td>
            <td style="padding:8px 12px;text-align:right"><?php echo number_format($quote['tax_percent'],2); ?>%</td>
          </tr>
          <tr style="border-top:2px solid #e5e7eb">
            <td style="padding:8px 12px;text-align:right;font-weight:700">Total</td>
            <td style="padding:8px 12px;text-align:right;font-weight:700;font-size:16px">$<?php echo number_format($quote['total'],2); ?></td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW') && ($pricingProvenance=pricing_adjustment_staff_provenance($pricingSnapshot))!==''): ?><p class="pricing-provenance" data-pricing-provenance><?php echo htmlspecialchars($pricingProvenance,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?></p><?php endif; ?>
<?php if (!isset($appConfig['quotes_show_terms']) || (int)$appConfig['quotes_show_terms'] === 1): ?>
    <div style="page-break-after:always"></div>
    <h3>Terms and Conditions</h3>
    <?php if ($termsText !== ''): ?>
      <div style="white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222"><?php echo nl2br(htmlspecialchars($termsText)); ?></div>
    <?php else: ?>
      <p class="lead">By accepting this quote, the client agrees to the scope and payment terms. Additional terms can be customized in Settings.</p>
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

<!-- Share Link Modal -->
<div id="shareLinkModal" data-doc-type="quote" data-doc-id="<?php echo (int)$id; ?>" data-default-days="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0;font-size:18px">🔗 Share Quote Link</h3>
      <button onclick="closeShareModal()" style="border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <div id="shareLinkContent">
      <p style="color:#6b7280;margin:0 0 16px">Generate a public link that clients can use to view and approve/deny this quote.</p>
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
  const formData = new FormData();
  formData.append('type', 'quote');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', '<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-create', { method: 'POST', body: formData })
  .then(r => r.json())
  .then(data => {
    if (data.success && data.existing) {
      document.getElementById('generatedLink').value = data.url;
      document.getElementById('linkStatus').textContent = '✓ Existing Link';
      document.getElementById('revokeBtn').style.display = 'block';
      document.getElementById('linkExpiry').textContent = 'Expires: ' + data.expires_at + ' (' + data.expires_in_days + ' days remaining)';
      document.getElementById('shareLinkContent').style.display = 'none';
      document.getElementById('shareLinkResult').style.display = 'block';
    }
  }).catch(() => {});
}

function closeShareModal() { document.getElementById('shareLinkModal').style.display = 'none'; }

function createPublicLink() {
  const days = document.getElementById('linkDays').value || 14;
  const formData = new FormData();
  formData.append('type', 'quote');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', days);
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-create', { method: 'POST', body: formData })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.getElementById('generatedLink').value = data.url;
      document.getElementById('linkStatus').textContent = data.existing ? '✓ Existing Link Found' : '✓ Link Generated!';
      document.getElementById('revokeBtn').style.display = data.existing ? 'block' : 'none';
      document.getElementById('linkExpiry').textContent = 'Expires: ' + data.expires_at + ' (' + data.expires_in_days + ' days)';
      document.getElementById('shareLinkContent').style.display = 'none';
      document.getElementById('shareLinkResult').style.display = 'block';
    } else { alert('Error: ' + (data.error || 'Failed to generate link')); }
  }).catch(err => alert('Error: ' + err.message));
}

function copyLink() {
  const input = document.getElementById('generatedLink');
  input.select();
  document.execCommand('copy');
  event.target.textContent = 'Copied!';
  setTimeout(() => { event.target.textContent = 'Copy'; }, 2000);
}

function revokeAndCreateNew() {
  if (!confirm('Revoke existing link and create new?')) return;
  const days = document.getElementById('linkDays').value || 14;
  const formData = new FormData();
  formData.append('type', 'quote');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', days);
  formData.append('force_new', '1');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-create', { method: 'POST', body: formData })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.getElementById('generatedLink').value = data.url;
      document.getElementById('linkStatus').textContent = '✓ New Link Created!';
      document.getElementById('revokeBtn').style.display = 'none';
      document.getElementById('linkExpiry').textContent = 'Expires: ' + data.expires_at + ' (' + data.expires_in_days + ' days)';
    } else { alert('Error: ' + (data.error || 'Failed')); }
  }).catch(err => alert('Error: ' + err.message));
}
</script>
