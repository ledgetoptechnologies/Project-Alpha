<?php
// src/views/pages/contract-print.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_recipient.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/document_sender.php';
require_once __DIR__ . '/../../../utils/public_links.php';
require_once __DIR__ . '/../../../utils/document_pricing_adjustments.php';

$id = (int)($_GET['id'] ?? 0);
if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')) {
    require_record_ownership($pdo, 'contracts', $id);
}
$c = $pdo->prepare('SELECT co.*, cl.name client_name, cl.email client_email, cl.phone client_phone, cl.address_line1 client_address_line1, cl.address_line2 client_address_line2, cl.city client_city, cl.state client_state, cl.postal_code client_postal_code, cl.country client_country, o.name organization_name, o.general_email organization_email, o.general_phone organization_phone, o.address_line1 organization_address_line1, o.address_line2 organization_address_line2, o.city organization_city, o.state organization_state, o.postal_code organization_postal_code, o.country organization_country FROM contracts co JOIN clients cl ON cl.id=co.client_id' . pa_document_effective_organization_joins('co', 'cl') . ' WHERE co.id=?');
$c->execute([$id]);
$contract = $c->fetch(PDO::FETCH_ASSOC);
if (!$contract) {
  echo '<p>Contract not found</p>';
  return;
}
$pricingSnapshot=(int)($contract['organization_id']??0)>0?pricing_document_snapshot($pdo,(int)$contract['organization_id'],'contract',$id,max(1,(int)($contract['revision_number']??1))):null;
$items = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total, billing_unit FROM contract_items WHERE contract_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
$isHourlyBilling = ($contract['billing_mode'] ?? 'fixed') === 'hourly';

// Fetch contract signatures
$signatures = [];
try {
    $sigStmt = $pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id = ? ORDER BY display_order, id');
    $sigStmt->execute([$id]);
    $signatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Column names may differ across schema versions — signatures are optional
}
// If no signatures defined, use a default
if (empty($signatures)) {
  $signatures = [['signer_title' => 'Client Signature', 'is_required' => 1]];
}
$documentSender = document_sender_for_creator($pdo, $appConfig, !empty($contract['created_by']) ? (int)$contract['created_by'] : null);
$fromName = $documentSender['name'] ?? '';
$fromAddress = implode("\n", document_sender_lines($documentSender));
$fromPhone = $documentSender['phone'] ?? '';
$fromEmail = $documentSender['email'] ?? '';
// Resolve terms: project-level terms override contract terms override app settings
$termsText = '';
if (!empty($contract['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
    $pm->execute([$contract['project_code']]);
    $pt = (string)$pm->fetchColumn();
    if (trim($pt) !== '') {
      $termsText = trim($pt);
    }
  } catch (Throwable $e) { /* ignore */
  }
}
if ($termsText === '') {
  $termsText = trim((string)($contract['terms'] ?? ''));
}
if ($termsText === '') {
  $termsText = trim((string)($appConfig['terms'] ?? ''));
}
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Contract</div>
  <div style="text-align:center;color:#6b7280;margin-bottom:16px;font-size:13px">Valid for <?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?> days</div>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
    <?php
    // Status banner styling
    $cstatus = strtolower($contract['status'] ?? 'pending');
    $cstatusColors = [
      'pending' => ['bg' => '#fffbeb', 'text' => '#92400e', 'border' => '#fbbf24'],
      'active' => ['bg' => '#dbeafe', 'text' => '#1e40af', 'border' => '#3b82f6'],
      'completed' => ['bg' => '#ecfdf5', 'text' => '#065f46', 'border' => '#10b981'],
      'cancelled' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#ef4444'],
      'denied' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#ef4444'],
      'void' => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'border' => '#9ca3af']
    ];
    $ccolors = $cstatusColors[$cstatus] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];

    // Check deposit info
    // Note: deposit_amount already stores the calculated dollar amount (not percentage)
    $depositType = $contract['deposit_type'] ?? 'none';
    $depositCalc = (float)($contract['deposit_amount'] ?? 0);
    $depositPaid = (float)($contract['deposit_paid'] ?? 0);
    $needsDeposit = $depositType !== 'none' && $depositCalc > 0 && $depositPaid < $depositCalc;
    ?>
    <div class="no-print" style="padding:12px 16px;background:<?php echo $ccolors['bg']; ?>;color:<?php echo $ccolors['text']; ?>;border-left:4px solid <?php echo $ccolors['border']; ?>;border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px">
      Status: <?php echo htmlspecialchars($contract['status']); ?>
    </div>
    <div class="no-print document-actions">
      <a href="javascript:history.back()" class="btn btn-sm">Back</a>
      <a href="/?page=contract/contract-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
      <a href="/?page=contract/contract-pdf&id=<?php echo (int)$id; ?>" download="contract-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?>.pdf" class="btn btn-sm">Download</a>
      <?php if (in_array($contract['status'], ['draft','pending'], true) && empty($contract['signed_at']) && empty($contract['signed_pdf_path'])): ?>
        <a href="/?page=contract/contracts-edit&id=<?php echo (int)$id; ?>" class="btn btn-sm">Edit</a>
      <?php endif; ?>
      <?php if((int)($contract['last_sent_revision']??0)>0&&(int)($contract['revision_number']??1)>(int)$contract['last_sent_revision']): ?><span class="alert alert-warning" style="padding:6px 9px">Revised <?php echo htmlspecialchars((string)($contract['revision_updated_at']??'')); ?> · Resend required</span><?php endif; ?>
      <?php $st = strtolower((string)($contract['status'] ?? ''));
      if (!in_array($st, ['denied', 'cancelled', 'void'], true)): ?>
        <form method="post" action="/?page=contract/email-send" style="display:inline">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="type" value="contract">
          <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
          <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
          <button type="submit" class="btn btn-sm">Email</button>
        </form>
      <?php endif; ?>
      <?php if (in_array($contract['status'], ['draft','pending'], true) && empty($contract['signed_at']) && empty($contract['signed_pdf_path'])): ?>
        <form method="post" action="/?page=contract/contract-sign" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
          <input id="upload-signed" type="file" name="signed_pdf" accept="application/pdf,.pdf" style="display:none" data-submit-on-file required>
          <button type="button" data-file-picker-target="upload-signed" class="btn btn-sm">Upload Signed PDF</button>
        </form>
      <?php endif; ?>
      <?php if (!empty($contract['signed_pdf_path'])): ?>
        <a href="<?php echo htmlspecialchars($contract['signed_pdf_path']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success">View Signed PDF</a>
      <?php endif; ?>
      <?php if ($needsDeposit && $contract['status'] === 'pending'): ?>
        <form method="post" action="/?page=contract/contract-deposit-received" style="display:inline" onsubmit="return confirm('Mark deposit as received ($<?php echo number_format($depositCalc, 2); ?>)?');">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
          <button type="submit" class="btn btn-sm btn-success">Deposit Received ($<?php echo number_format($depositCalc, 2); ?>)</button>
        </form>
      <?php endif; ?>
      <?php if ($contract['status'] === 'active'): ?>
        <form method="post" action="/?page=contract/contract-complete" style="display:inline" onsubmit="return confirm('Complete this contract, finalize its invoice, and send it when automatic delivery is enabled?');">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
          <button type="submit" class="btn btn-sm btn-success">Complete</button>
        </form>
      <?php endif; ?>
      <?php if ($contract['status'] !== 'cancelled' && $contract['status'] !== 'completed'): ?>
        <form method="post" action="/?page=contract/contract-void" onsubmit="return confirm('Void this contract and linked invoices?')" style="display:inline">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
          <button type="submit" class="btn btn-sm btn-muted">Void</button>
        </form>
      <?php endif; ?>
      <?php if (in_array($st, ['denied', 'cancelled', 'void'], true)): ?>
        <form method="post" action="/?page=document-reenable" style="display:inline" onsubmit="return confirm('Re-enable this contract? It will be set back to pending status and related invoices will be restored.');">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="type" value="contract">
          <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
          <button type="submit" class="btn btn-sm btn-warning">Re-enable</button>
        </form>
      <?php endif; ?>
      <form method="post" action="/?page=document-date-update" style="display:inline" onsubmit="return confirm('Update document date to today? This will refresh the date shown on the PDF.');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="type" value="contract">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" class="btn btn-sm btn-info">Update Document Date</button>
      </form>
      <?php if (!in_array($cstatus, ['denied', 'cancelled', 'void'], true)): ?>
      <button type="button" onclick="generatePublicLink()" class="btn btn-sm btn-info">Share Link</button>
      <?php endif; ?>
    </div>
    <?php if (!empty($_GET['reenabled'])): ?>
      <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Contract re-enabled successfully</div>
    <?php endif; ?>
    <?php if (!empty($_GET['date_updated'])): ?>
      <div class="no-print" style="padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Document date updated successfully</div>
    <?php endif; ?>
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
        $projectRoot = realpath(__DIR__ . '/../../../');
        if ($projectRoot) {
          $cfg = realpath($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads');
          if ($cfg) {
            $bases[] = $cfg;
          } else {
            $bases[] = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads';
          }
          $internal = realpath(__DIR__ . '/../../../uploads');
          $bases[] = $internal ? $internal : (__DIR__ . '/../../../uploads');
        }
        // Container path
        $bases[] = '/var/www/config/uploads';
        foreach ($bases as $b) {
          $candidate = @realpath(rtrim($b, '/\\') . DIRECTORY_SEPARATOR . $fname);
          if ($candidate !== false && is_file($candidate)) {
            $logoPath = $candidate;
            $isUrl = false;
            break;
          }
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
        if ($candidate) {
          $logoPath = $candidate;
        }
      }
    } else {
      // For relative paths (e.g., public/assets/logo.png or config/uploads/logo.png)
      if ($root) {
        $candidate = @realpath($root . DIRECTORY_SEPARATOR . $logoPath);
        if ($candidate) {
          $logoPath = $candidate;
        }
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
            if ($det) {
              $mime = $det;
            }
            if (PHP_VERSION_ID < 80500) {
                @finfo_close($finfo);
            }
          }
        }
        if ($mime === null) {
          $mime = 'image/png';
        }
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
        <?php if (!empty($contract['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Job <?php echo htmlspecialchars($contract['project_code']); ?></div><?php endif; ?>
        <?php if (!empty($contract['project_id'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project <?php echo htmlspecialchars($contract['project_id']); ?></div><?php endif; ?>
      </td>
      <td style="vertical-align:middle;width:30%;text-align:right">
        <?php if ($canShowLogo): ?>
          <?php if (!$isUrl && preg_match('/\.svg$/i', $logoPath) && is_file($logoPath)): ?>
            <?php if (defined('PDF_MODE')): ?>
              <?php echo @file_get_contents($logoPath); ?>
            <?php else: ?>
              <?php $svgContents = @file_get_contents($logoPath);
              if ($svgContents !== false) {
                $svgData = 'data:image/svg+xml;base64,' . base64_encode($svgContents); ?>
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
  $depositType = $contract['deposit_type'] ?? 'none';
  $depositCalc = (float)($contract['deposit_amount'] ?? 0);
  $fulfillmentDate = $contract['fulfillment_date'] ?? null;
  $showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
  $showFulfillmentDate = !empty($fulfillmentDate);

  // Get custom fields for display
  $documentType = 'regular';

  $customFieldValues = !empty($contract['custom_fields']) ? json_decode($contract['custom_fields'], true) : [];
  if (!is_array($customFieldValues)) $customFieldValues = [];

  // Fetch custom field definitions (non-builtin only)
  $customFieldDefs = [];
  try {
    $cfStmt = $pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0 ORDER BY display_order, id');
    $cfStmt->execute([$documentType]);
    $customFieldDefs = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { /* ignore */
  }

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
        <div><?php foreach ($fromLines as $ln) {
                echo '<div>' . htmlspecialchars($ln) . '</div>';
              } ?></div>
        <?php if ($fromPhone || $fromEmail): ?>
          <div style="margin-top:6px;color:#4b5563;font-size:13px">
            <?php if ($fromPhone): ?><div><?php echo htmlspecialchars(format_phone($fromPhone), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($fromEmail): ?><div><?php echo htmlspecialchars($fromEmail); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
      <td style="vertical-align:top;width:50%;padding-left:12px">
        <div class="font-600">To</div>
        <?php $recipient = pa_document_recipient($contract); ?>
        <div><?php foreach ($recipient['lines'] as $ln) {
                echo '<div>' . htmlspecialchars($ln) . '</div>';
              } ?></div>
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
    <div style="page-break-before:auto;margin-top:12px">
      <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;color:#111">Scope of Work</h3>
      <div style="white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px"><?php echo nl2br(htmlspecialchars($scopeText)); ?></div>
    </div>
  <?php endif; ?>

  <?php
  // Long-term / on-demand billing summary (shows what the client is buying)
  $ctType = $contract['contract_type'] ?? 'regular';
  if (in_array($ctType, ['long_term', 'on_demand'], true)):
    $biCount = (int)($contract['billing_interval_count'] ?? 1);
    $biUnit = $contract['billing_interval_unit'] ?? 'month';
    $biText = $biCount . ' ' . ucfirst($biUnit);
    if ($biCount > 1) $biText .= 's';
    $svcDesc = trim((string)($contract['scope'] ?? ''));
    $amtPerInv = (float)($contract['price_per_invoice'] ?? 0);
    if ($ctType === 'on_demand' && $amtPerInv <= 0) {
      $amtPerInv = (float)($contract['subtotal'] ?? 0);
    }
    $pricingType = $contract['pricing_type'] ?? null;
  ?>
  <div style="margin:8px 0;padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px">
    <div style="font-weight:700;font-size:14px;margin-bottom:6px;color:#065f46">
      <?php echo $ctType === 'long_term' ? 'Recurring Billing Summary' : 'On-Demand Billing Summary'; ?>
    </div>
    <?php if ($svcDesc !== ''): ?>
      <div style="margin-bottom:4px"><strong>Service:</strong> <?php echo htmlspecialchars($svcDesc); ?></div>
    <?php endif; ?>
    <?php if ($ctType === 'long_term'): ?>
      <div style="margin-bottom:4px"><strong>Billing Cycle:</strong> Every <?php echo htmlspecialchars($biText); ?></div>
    <?php endif; ?>
    <?php if ($pricingType === 'per_invoice' || $ctType === 'on_demand'): ?>
      <div style="font-size:14px;font-weight:700;color:#065f46">
        Amount Per Invoice: $<?php echo number_format($amtPerInv, 2); ?>
        <?php if ($ctType === 'long_term'): ?>/<?php echo htmlspecialchars(strtolower($biUnit)); ?><?php endif; ?>
      </div>
    <?php elseif ($pricingType === 'fixed_total'): ?>
      <div style="font-size:14px;font-weight:700;color:#065f46">Contract Total: $<?php echo number_format((float)($contract['total'] ?? 0), 2); ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <table style="width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee">
        <th style="padding:10px;width:25%;vertical-align:top;text-align:center">Item</th>
        <th style="padding:10px;width:35%;vertical-align:top">Description</th>
        <th style="padding:10px;width:10%;text-align:right;vertical-align:top"><?php echo $isHourlyBilling ? 'Est. Hours' : 'Qty / Unit'; ?></th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top"><?php echo $isHourlyBilling ? 'Hourly Rate' : 'Unit Price'; ?></th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top">Line Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr style="border-top:1px solid #f3f4f6">
          <td style="padding:10px;font-weight:600;vertical-align:top;text-align:center"><?php echo htmlspecialchars($it['item'] ?? ''); ?></td>
          <td style="padding:10px;color:#6b7280;font-size:13px;vertical-align:top"><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
          <td style="padding:10px;text-align:right;vertical-align:top"><?php echo number_format($it['quantity'], 2); ?><?php echo ($it['billing_unit'] ?? 'each') === 'mile' ? ' mi' : ''; ?></td>
          <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['unit_price'], 2); ?></td>
          <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['line_total'], 2); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals section - uses table for PDF compatibility -->
  <?php
  $depType = $contract['deposit_type'] ?? 'none';
  $contractTotal = (float)($contract['total'] ?? 0);
  if (($contract['contract_type'] ?? 'regular') === 'on_demand' && $contractTotal <= 0 && (float)($contract['subtotal'] ?? 0) > 0) {
    $displaySubtotal = (float)($contract['subtotal'] ?? 0);
    $displayDiscount = 0.0;
    if (($contract['discount_type'] ?? 'none') === 'percent') {
      $displayDiscount = max(0, min(100, (float)($contract['discount_value'] ?? 0))) * $displaySubtotal / 100;
    } elseif (($contract['discount_type'] ?? 'none') === 'fixed') {
      $displayDiscount = max(0, (float)($contract['discount_value'] ?? 0));
    }
    $displayTaxable = max(0, $displaySubtotal - $displayDiscount);
    $contractTotal = max(0, $displayTaxable + (max(0, (float)($contract['tax_percent'] ?? 0)) * $displayTaxable / 100));
  }
  $depositCalc = (float)($contract['deposit_amount'] ?? 0);
  $showDeposit = $depType !== 'none' && $depositCalc > 0;
  ?>

  <table style="width:100%;border-collapse:collapse;margin-top:16px">
    <tr>
      <td style="width:60%"></td>
      <td style="width:40%">
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <td style="padding:8px 10px;font-weight:600;text-align:right">Subtotal</td>
            <td style="padding:8px 10px;text-align:right;width:120px">$<?php echo number_format($contract['subtotal'] ?? 0, 2); ?></td>
          </tr>
          <?php echo pricing_adjustment_client_row($pricingSnapshot); ?>
          <tr>
            <td style="padding:8px 10px;font-weight:600;text-align:right">Discount</td>
            <td style="padding:8px 10px;text-align:right">
              <?php if (($contract['discount_type'] ?? 'none') === 'percent'): ?>
                <?php echo number_format($contract['discount_value'] ?? 0, 2); ?>%
              <?php elseif (($contract['discount_type'] ?? 'none') === 'fixed'): ?>
                $<?php echo number_format($contract['discount_value'] ?? 0, 2); ?>
              <?php else: ?>
                $0.00
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 10px;font-weight:600;text-align:right">Tax</td>
            <td style="padding:8px 10px;text-align:right"><?php echo number_format($contract['tax_percent'] ?? 0, 2); ?>%</td>
          </tr>
          <tr style="border-top:1px solid #e5e7eb">
            <td style="padding:8px 10px;font-weight:700;text-align:right">Total</td>
            <td style="padding:8px 10px;font-weight:700;text-align:right">$<?php echo number_format($contractTotal, 2); ?></td>
          </tr>
          <?php if ($showDeposit): ?>
            <tr style="background:#f9fafb">
              <td style="padding:8px 10px;font-weight:700;font-size:15px;color:#059669;text-align:right">Deposit Due</td>
              <td style="padding:8px 10px;font-weight:700;font-size:15px;color:#059669;text-align:right">$<?php echo number_format($depositCalc, 2); ?></td>
            </tr>
          <?php endif; ?>
        </table>
      </td>
    </tr>
  </table>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW') && ($pricingProvenance=pricing_adjustment_staff_provenance($pricingSnapshot))!==''): ?><p class="pricing-provenance" data-pricing-provenance><?php echo htmlspecialchars($pricingProvenance,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?></p><?php endif; ?>

  <!-- Signature section -->
  <div style="margin-top:24px;padding:12px 10px;color:#374151;font-size:13px;line-height:1.4">
    <?php echo htmlspecialchars($appConfig['signature_agreement'] ?? 'By signing below, I acknowledge that this is a multi-page contract and that I have read and agree to the terms and conditions.'); ?>
  </div>
  <?php foreach ($signatures as $sig): ?>
  <table style="width:100%;border-collapse:collapse;margin-top:20px">
    <tr>
      <td style="width:65%;height:50px;vertical-align:bottom;padding-right:40px;font-size:12px;color:#4b5563">
        <div style="border-top:1px solid #333;width:100%;height:1px;margin-bottom:4px"></div>
        <?php echo htmlspecialchars($sig['signer_title'] ?? 'Client Signature'); ?>
        <?php if (!empty($sig['is_required'])): ?><span style="color:#dc2626">*</span><?php endif; ?>
      </td>
      <td style="width:35%;height:50px;vertical-align:bottom;font-size:12px;color:#4b5563">
        <div style="border-top:1px solid #333;width:100%;height:1px;margin-bottom:4px"></div>
        Date
      </td>
    </tr>
  </table>
  <?php endforeach; ?>
  <div style="page-break-after:always"></div>
  <h3>Terms and Conditions</h3>
  <?php if ($termsText !== ''): ?>
    <div style="white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222;"><?php echo nl2br(htmlspecialchars($termsText)); ?></div>
  <?php else: ?>
    <!-- <p class="lead">By signing, the client agrees to the scope, timeline, and payment schedule indicated in this contract. Additional terms can be customized later.</p> -->
    <ul>
      <li>Payment due NET 30 unless otherwise specified.</li>
      <li>Cancellation requires written notice.</li>
      <li>Work product ownership and usage rights per agreement.</li>
    </ul>
  <?php endif; ?>


</section>
<style>
  .no-print {
    display: flex
  }

  .print-footer {
    display: none
  }

  @media print {
    .no-print {
      display: none !important
    }

    .side-nav,
    .nav-footer {
      display: none
    }

    .main-content {
      margin-left: 0
    }

    body {
      background: #fff
    }

    .print-footer {
      display: block;
      position: fixed;
      bottom: 6px;
      left: 12px;
      color: #374151;
      font-size: 12px
    }
  }
</style>
<div class="print-footer"><a href="https://project-alpha.tech" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">Powered by Project Alpha</a></div>

<!-- Share Link Modal -->
<div id="shareLinkModal" data-doc-type="contract" data-doc-id="<?php echo (int)$id; ?>" data-default-days="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0;font-size:18px">🔗 Share Contract Link</h3>
      <button onclick="closeShareModal()" style="border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <div id="shareLinkContent">
      <p style="color:#6b7280;margin:0 0 16px">Generate a public link that clients can use to view and upload a signed copy of this contract.</p>
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
  formData.append('type', 'contract');
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
  formData.append('type', 'contract');
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
    } else { alert('Error: ' + (data.error || 'Failed')); }
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
  formData.append('type', 'contract');
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
