<?php
// src/views/pages/project/project-invoice-details.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../../utils/project_invoice_presentation.php';
require_once __DIR__ . '/../../../utils/document_sender.php';
require_once __DIR__ . '/../../../utils/document_recipient.php';
require_once __DIR__ . '/../../../utils/invoice_content_links.php';
require_once __DIR__ . '/../../../utils/public_links.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Invalid project invoice'; return; }

project_invoice_refresh_status($pdo, $id);

$stmt = $pdo->prepare('
    SELECT pi.*, p.name AS project_name, p.notes AS project_notes,
           o.name AS organization_name,
           o.general_email AS organization_email,
           o.general_phone AS organization_phone,
           o.address_line1 AS organization_address_line1,
           o.address_line2 AS organization_address_line2,
           o.city AS organization_city,
           o.state AS organization_state,
           o.postal_code AS organization_postal_code,
           o.country AS organization_country,
           c.name AS client_name,
           c.email AS client_email,
           c.phone AS client_phone,
           c.address_line1 AS client_address_line1,
           c.address_line2 AS client_address_line2,
           c.city AS client_city,
           c.state AS client_state,
           c.postal_code AS client_postal_code,
           c.country AS client_country
    FROM project_invoices pi
    JOIN projects p ON p.id = pi.project_id
    LEFT JOIN organizations o ON o.id = COALESCE(p.organization_id, pi.organization_id)
    LEFT JOIN clients c ON c.id = pi.primary_client_id
    WHERE pi.id = ?
');
$stmt->execute([$id]);
$pi = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pi) { http_response_code(404); echo 'Project invoice not found'; return; }

if (!defined('PUBLIC_VIEW') && !defined('PDF_MODE')) {
    require_record_ownership($pdo, 'projects', (int)$pi['project_id']);
}

$itemsStmt = $pdo->prepare('
    SELECT pii.*, i.project_code, i.invoice_type, i.status AS current_status, i.total AS current_total, i.amount_paid AS current_paid,
           i.subtotal, i.discount_type, i.discount_value, i.tax_percent, i.tax_amount, i.organization_id, i.revision_number,
           c.name AS client_name
    FROM project_invoice_items pii
    JOIN invoices i ON i.id = pii.invoice_id
    JOIN clients c ON c.id = i.client_id
    WHERE pii.project_invoice_id = ?
    ORDER BY COALESCE(pii.invoice_date, pii.created_at) ASC, pii.invoice_doc_number ASC, pii.invoice_id ASC
');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$hasBillingUnit = project_invoice_table_has_column($pdo, 'invoice_items', 'billing_unit');
$lineSql = $hasBillingUnit
    ? 'SELECT item, description, quantity, unit_price, line_total, billing_unit FROM invoice_items WHERE invoice_id=? ORDER BY sort_order ASC, id ASC'
    : 'SELECT item, description, quantity, unit_price, line_total, "each" AS billing_unit FROM invoice_items WHERE invoice_id=? ORDER BY sort_order ASC, id ASC';
$lineStmt = $pdo->prepare($lineSql);

$recipients = project_invoice_client_recipients($pdo, $id);
$contentLinks = invoice_content_links_for_project_invoice($pdo, $id, $appConfig);
$projectClientStmt = $pdo->prepare('
    SELECT c.id, c.name, c.email, pc.is_primary_billing,
           ' . (project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices') ? 'pc.send_project_invoices' : '1 AS send_project_invoices') . '
    FROM project_clients pc
    JOIN clients c ON c.id = pc.client_id
    WHERE pc.project_id = ?
    ORDER BY pc.is_primary_billing DESC, pc.sort_order ASC, c.name ASC
');
$projectClientStmt->execute([(int)$pi['project_id']]);
$projectClients = $projectClientStmt->fetchAll(PDO::FETCH_ASSOC);
$status = strtolower((string)$pi['status']);
$docNum = $pi['doc_number'] ?: $pi['id'];
$isPdf = defined('PDF_MODE') && PDF_MODE;
$isPublic = defined('PUBLIC_VIEW') && PUBLIC_VIEW;
$showEmailPanel = !empty($_GET['email_panel']) || !empty($_GET['content_link_warning']);
$billingRecipient = pa_document_recipient($pi);
$documentSender = document_sender_for_creator($pdo, $appConfig, !empty($pi['created_by']) ? (int)$pi['created_by'] : null);
?>
<section class="document-detail-page" style="max-width:980px;margin:0 auto;padding:<?php echo $isPublic ? '0' : '24px'; ?>">
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Project Invoice</div>
  <?php if (!$isPdf && !$isPublic): ?>
    <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <a class="btn btn-sm" href="/?page=project/projects-details&id=<?php echo (int)$pi['project_id']; ?>">Back to Project</a>
      <a class="btn btn-sm" href="/?page=project/project-invoice-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener">View PDF</a>
      <?php if ($status !== 'draft'): ?>
        <button type="button" class="btn btn-sm" onclick="generatePublicLink()">Share Link</button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-success" onclick="openEmailPanel()"><?php echo $status === 'draft' ? 'Finalize & Email' : 'Email'; ?></button>
    </div>
    <?php foreach (['generated' => 'Project invoice generated.', 'existing' => 'Existing project invoice opened; no duplicate was created.', 'emailed' => 'Project invoice emailed.', 'paid' => 'Payment recorded.'] as $key => $msg): ?>
      <?php if (!empty($_GET[$key])): ?><div class="alert alert-success no-print"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <?php endforeach; ?>
    <?php foreach (['email_err', 'payment_err'] as $key): ?>
      <?php if (!empty($_GET[$key])): ?><div class="alert alert-danger no-print"><?php echo htmlspecialchars((string)$_GET[$key]); ?></div><?php endif; ?>
    <?php endforeach; ?>
    <div class="no-print" style="padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:12px;font-size:13px;color:#374151">
      <strong>Created:</strong> <?php echo !empty($pi['created_at']) ? date('M j, Y g:i A', strtotime($pi['created_at'])) : 'N/A'; ?>
      <span style="margin:0 8px">|</span>
      <strong>Generated:</strong> <?php echo !empty($pi['generated_at']) ? date('M j, Y g:i A', strtotime($pi['generated_at'])) : 'N/A'; ?>
      <span style="margin:0 8px">|</span>
      <?php echo pa_public_link_status_badge_html($pdo, 'project_invoice', $id); ?>
    </div>
  <?php endif; ?>

  <?php if (!$isPdf && !$isPublic): ?>
    <div id="emailPanel" class="no-print" style="display:<?php echo $showEmailPanel ? 'block' : 'none'; ?>;border:1px solid #dbeafe;background:#eff6ff;border-radius:8px;padding:14px;margin-bottom:16px">
      <form method="post" action="/?page=project/project-invoice-email" style="display:grid;gap:10px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <?php if (!empty($_GET['content_link_warning'])): ?>
          <div style="padding:12px 14px;background:#fffbeb;color:#92400e;border:1px solid #facc15;border-radius:8px;font-size:14px">
            <strong>No invoice content links found.</strong>
            <div style="margin-top:4px">This project invoice has no eligible links marked "Include on invoices." Add a content link first, or send it anyway.</div>
            <input type="hidden" name="confirm_missing_content_links" value="1">
          </div>
        <?php endif; ?>
        <div>
          <div style="font-weight:700;margin-bottom:4px">Send Project Invoice</div>
          <div style="font-size:13px;color:#4b5563">Choose from the saved project invoice recipients. Change this list from Edit Project.</div>
        </div>
        <?php if ($recipients): ?>
          <div style="display:grid;gap:6px">
            <?php foreach ($recipients as $client): ?>
              <?php $hasEmail = !empty($client['email']) && filter_var((string)$client['email'], FILTER_VALIDATE_EMAIL); ?>
              <label style="display:flex;gap:8px;align-items:flex-start;padding:8px;border:1px solid #bfdbfe;border-radius:8px;background:#fff;<?php echo $hasEmail ? '' : 'opacity:.6'; ?>">
                <input type="checkbox" name="recipient_keys[]" value="<?php echo htmlspecialchars((string)($client['recipient_key'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo $hasEmail ? 'checked' : ''; ?> <?php echo $hasEmail ? '' : 'disabled'; ?> style="margin-top:3px">
                <span>
                  <span style="font-weight:600"><?php echo htmlspecialchars((string)$client['name']); ?></span>
                  <span style="display:block;font-size:12px;color:#6b7280"><?php echo $hasEmail ? htmlspecialchars((string)$client['email']) : 'No email address on file'; ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="submit" class="btn btn-sm btn-success">Send Email</button>
            <button type="button" class="btn btn-sm" onclick="closeEmailPanel()">Cancel</button>
          </div>
        <?php else: ?>
          <div style="color:#92400e">No project invoice recipients are saved. Add a saved client contact or the company email from Edit Project.</div>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php
    $statementPeriod = project_invoice_period_label($pi);
    $documentBrandLabel = 'Project Invoice PI-' . $docNum;
    $documentBrandMetaLines = [
      'Project ' . $pi['project_name'],
      'Billing Period ' . $statementPeriod,
    ];
    require __DIR__ . '/../../components/document_brand_header.php';
  ?>

  <div style="border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-bottom:18px;background:#fff">
    <div style="<?php echo $isPdf ? 'display:table;width:100%;table-layout:fixed' : 'display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px'; ?>">
      <div style="<?php echo $isPdf ? 'display:table-cell;vertical-align:top;width:25%;padding-right:10px' : ''; ?>">
        <div style="font-size:12px;color:#6b7280">Total Due</div>
        <div style="font-size:24px;font-weight:700">$<?php echo number_format((float)$pi['balance_due'], 2); ?></div>
      </div>
      <div style="<?php echo $isPdf ? 'display:table-cell;vertical-align:top;width:35%;padding-right:10px' : ''; ?>">
        <div style="font-size:12px;color:#6b7280"><?php echo ($pi['finalization_source'] ?? '') === 'billing_mode_transition' ? 'Closing Period' : 'Billing Period'; ?></div>
        <div style="font-weight:700"><?php echo htmlspecialchars($statementPeriod); ?></div>
      </div>
      <div style="<?php echo $isPdf ? 'display:table-cell;vertical-align:top;width:25%;padding-right:10px' : ''; ?>">
        <div style="font-size:12px;color:#6b7280">Due Date</div>
        <div style="font-weight:700"><?php echo $pi['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($pi['due_date']))) : 'Not set'; ?></div>
      </div>
      <div style="<?php echo $isPdf ? 'display:table-cell;vertical-align:top;width:15%' : ''; ?>">
        <div style="font-size:12px;color:#6b7280">Status</div>
        <div style="font-weight:700;text-transform:capitalize"><?php echo htmlspecialchars($status); ?></div>
      </div>
    </div>
  </div>

  <?php
    $documentPartySender = $documentSender;
    $documentPartyRecipient = $billingRecipient;
    require __DIR__ . '/../../components/document_parties.php';
  ?>

  <h2 style="font-size:18px;margin:0 0 10px;page-break-after:avoid">Included Invoices</h2>
  <?php foreach ($items as $invoiceSectionIndex => $item): ?>
    <?php
      $lineStmt->execute([(int)$item['invoice_id']]);
      $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
      $itemOrganizationId = isset($item['organization_id']) ? (int)$item['organization_id'] : null;
      $itemPricingSnapshot = $itemOrganizationId > 0
        ? pricing_document_snapshot($pdo, $itemOrganizationId, 'invoice', (int)$item['invoice_id'], max(1, (int)$item['revision_number']))
        : null;
      $itemAdjustments = pricing_invoice_total_adjustments($pdo, $itemOrganizationId, (int)$item['invoice_id']);
      $invoiceSectionTotalRows = project_invoice_item_total_rows($item, $itemPricingSnapshot, $itemAdjustments);
      require __DIR__ . '/../../components/project_invoice_item.php';
    ?>
  <?php endforeach; ?>

  <?php if (!empty($contentLinks)): ?>
    <?php echo invoice_content_links_html($contentLinks); ?>
  <?php elseif (!$isPublic && !$isPdf): ?>
    <div class="no-print" style="border:1px dashed #cbd5e1;border-radius:8px;padding:14px;background:#f8fafc;margin-bottom:22px;color:#475569">
      <strong>No invoice content links.</strong>
      Add manual links to the project department, organization, client, or enabled project links and mark them "Include on invoices."
    </div>
  <?php endif; ?>

  <?php
    $projectInvoiceTotal = (float)$pi['total'];
    $projectInvoicePaid = (float)$pi['amount_paid'];
    $projectInvoiceDue = (float)$pi['balance_due'];
    $documentTotalRows = [
      ['label' => 'Project Invoice Total', 'value' => '$' . number_format($projectInvoiceTotal, 2), 'tone' => 'total'],
    ];
    if ($status === 'partial') {
      $documentTotalRows[] = ['label' => 'Amount Paid', 'value' => '- $' . number_format($projectInvoicePaid, 2), 'tone' => 'paid'];
      $documentTotalRows[] = ['label' => 'Amount Due', 'value' => '$' . number_format($projectInvoiceDue, 2), 'tone' => 'due'];
    } elseif ($status === 'paid') {
      $documentTotalRows[] = ['label' => '✓ Paid in Full', 'value' => '$0.00', 'tone' => 'paid_full'];
    }
    require __DIR__ . '/../../components/document_totals.php';
  ?>

  <?php if (!$isPdf && !$isPublic && !in_array($status, ['draft','paid','void'], true)): ?>
    <div class="no-print" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;margin-top:18px">
      <h3 style="margin:0 0 10px">Record Project Invoice Payment</h3>
      <form method="post" action="/?page=project/project-invoice-payment" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <label><div>Amount</div><input name="amount" type="number" min="0.01" step="0.01" value="<?php echo htmlspecialchars(number_format((float)$pi['balance_due'], 2, '.', '')); ?>" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px"></label>
        <label><div>Method</div><input name="method" value="check" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px"></label>
        <label><div>Reference</div><input name="reference_number" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px"></label>
        <label><div>Payment date</div><input name="payment_date" type="date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px"></label>
        <?php if (!array_key_exists('payment_receipts_enabled', $appConfig) || !empty($appConfig['payment_receipts_enabled'])): ?>
          <label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="send_receipt" value="1"><span>Email receipt to saved project invoice recipients</span></label>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-success">Record Payment</button>
      </form>
    </div>
  <?php endif; ?>
</section>

<?php if (!$isPdf && !$isPublic): ?>
<div id="shareLinkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:10px;padding:22px;max-width:520px;width:92%">
    <h3 style="margin:0 0 12px">Share Project Invoice</h3>
    <label><div>Expires in days</div><input type="number" id="linkDays" value="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" min="1" max="365" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px"></label>
    <div style="display:flex;gap:8px;margin-top:14px">
      <button class="btn btn-sm" onclick="createPublicLink()">Generate Link</button>
      <button class="btn btn-sm" onclick="closeShareModal()">Cancel</button>
    </div>
    <input id="generatedLink" readonly style="display:none;margin-top:12px;width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
  </div>
</div>
<script>
function generatePublicLink(){document.getElementById('shareLinkModal').style.display='flex'}
function closeShareModal(){document.getElementById('shareLinkModal').style.display='none'}
function openEmailPanel(){document.getElementById('emailPanel').style.display='block'}
function closeEmailPanel(){document.getElementById('emailPanel').style.display='none'}
function createPublicLink(){
  const fd = new FormData();
  fd.append('type','project_invoice');
  fd.append('id','<?php echo (int)$id; ?>');
  fd.append('days',document.getElementById('linkDays').value || '14');
  fd.append('csrf','<?php echo htmlspecialchars(csrf_token()); ?>');
  fetch('/?page=public-link-create',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
    if(!data.success){alert(data.error || 'Failed to create link'); return;}
    const input=document.getElementById('generatedLink');
    input.style.display='block';
    input.value=data.url;
    input.select();
    document.execCommand('copy');
  }).catch(()=>alert('Failed to create link'));
}
</script>
<?php endif; ?>
