<?php
// src/views/pages/settings/documents.php
// Sub-tab for documents - default to quotes
$docTab = isset($_GET['doc_tab']) ? preg_replace('/[^a-z]/i', '', $_GET['doc_tab']) : 'quotes';
?>

<div class="settings-tab-list" style="display:flex;gap:12px;margin-bottom:16px;border-bottom:2px solid #e5e7eb">
  <a href="/?page=settings&tab=documents&doc_tab=quotes" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'quotes' ? '600' : '400'; ?>;color:<?php echo $docTab === 'quotes' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'quotes' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Quotes</a>
  <a href="/?page=settings&tab=documents&doc_tab=contracts" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'contracts' ? '600' : '400'; ?>;color:<?php echo $docTab === 'contracts' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'contracts' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Contracts</a>
  <a href="/?page=settings&tab=documents&doc_tab=invoices" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'invoices' ? '600' : '400'; ?>;color:<?php echo $docTab === 'invoices' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'invoices' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Invoices</a>
  <a href="/?page=settings&tab=documents&doc_tab=customization" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'customization' ? '600' : '400'; ?>;color:<?php echo $docTab === 'customization' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'customization' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Customization</a>
</div>


<?php if ($docTab !== 'customization'): ?>
<form method="post" action="/?page=settings&tab=documents&doc_tab=<?php echo htmlspecialchars($docTab); ?>" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:800px">
  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
  <input type="hidden" name="tab" value="documents">
  <input type="hidden" name="doc_tab" value="<?php echo htmlspecialchars($docTab); ?>">
<?php endif; ?>
<?php if ($docTab === 'quotes'): ?>
  <?php
  // Display approval flash messages
  $flash = $_SESSION['flash_quote_approve'] ?? null;
  unset($_SESSION['flash_quote_approve']);
  ?>
  <?php if ($flash): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1">
      <?php echo htmlspecialchars($flash['message']); ?>
    </div>
  <?php endif; ?>

  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
    <legend style="padding:0 6px;color:var(--muted)">Quote Options</legend>
    <div class="grid">
      <label>
        <input type="checkbox" name="quote_scope_enabled" value="1" <?php echo !empty($appConfig['quote_scope_enabled']) ? 'checked' : ''; ?>>
        <span class="font-600">Enable "Scope of Work" field on quotes</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">If enabled, quotes will have a scope field. If left blank, it will be excluded from PDF.</div>
      </label>
      <label style="display:block;margin-top:4px">
        <input type="checkbox" name="quotes_show_terms" value="1" <?php echo (!isset($appConfig['quotes_show_terms']) || (int)($appConfig['quotes_show_terms']) === 1) ? 'checked' : ''; ?>
        <span class="font-600">Show terms on Quotes</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">When enabled, the standard terms will be included on quote PDFs.</div>
      </label>
    </div>
  </fieldset>

<?php endif; ?>

<?php if ($docTab === 'contracts'): ?>
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
    <legend style="padding:0 6px;color:var(--muted)">Contract Options</legend>
    <div class="grid">
      <label>
        <input type="checkbox" name="contract_scope_enabled" value="1" <?php echo !empty($appConfig['contract_scope_enabled']) || !isset($appConfig['contract_scope_enabled']) ? 'checked' : ''; ?>
        <span class="font-600">Enable "Scope of Contract" field</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Available for regular, long-term, and on-demand contracts. If left blank, excluded from PDF and public links.</div>
      </label>
      <label>
        <input type="checkbox" name="contract_memo_enabled" value="1" <?php echo !empty($appConfig['contract_memo_enabled']) ? 'checked' : ''; ?>
        <span class="font-600">Enable "Memo" field</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Add a memo/notes section to contracts for additional context.</div>
      </label>
    </div>
  </fieldset>
  
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
    <legend style="padding:0 6px;color:var(--muted)">Signature Agreement Text</legend>
    <p style="margin:0 0 8px;color:var(--muted);font-size:13px">This text appears above the signature line on all contracts</p>
    <textarea name="signature_agreement" rows="3" class="input" placeholder="Enter signature agreement text..."><?php echo htmlspecialchars($appConfig['signature_agreement'] ?? 'By signing below, I acknowledge that this is a multi-page contract and that I have read and agree to the terms and conditions.'); ?></textarea>
  </fieldset>
  
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
    <legend style="padding:0 6px;color:var(--muted)">Custom Contract Sections</legend>
    <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Define custom sections that appear on all contracts. Enabled sections render on contract detail/PDF views between items and terms.</p>
    
    <?php
    $customSections = $appConfig['contract_custom_sections'] ?? [];
    if (!is_array($customSections)) $customSections = [];
    ?>
    
    <div id="customSectionsListCo" style="display:grid;gap:8px;margin-bottom:12px">
      <!-- Built-in (read-only) -->
      <div style="padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="color:#9ca3af">⋮⋮</span>
          <strong>Scope of Work</strong>
          <span style="margin-left:auto;font-size:12px;color:var(--muted)">Built-in</span>
        </div>
      </div>
      <div style="padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="color:#9ca3af">⋮⋮</span>
          <strong>Terms & Conditions</strong>
          <span style="margin-left:auto;font-size:12px;color:var(--muted)">Built-in</span>
        </div>
      </div>
      
      <!-- User-defined sections -->
      <?php foreach ($customSections as $idx => $sec): ?>
        <div class="custom-section-row" style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff">
          <div class="grid">
            <div style="display:flex;align-items:center;gap:8px">
              <label style="display:flex;align-items:center;gap:6px">
                <input type="checkbox" name="section_enabled[<?php echo $idx; ?>]" value="1" <?php echo !empty($sec['is_enabled']) ? 'checked' : ''; ?>>
                <span style="font-size:13px">Enabled</span>
              </label>
              <button type="button" onclick="this.closest('.custom-section-row').remove()" style="margin-left:auto;padding:4px 10px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;border-radius:4px;font-size:12px;cursor:pointer">Remove</button>
            </div>
            <input type="text" name="section_title[]" value="<?php echo htmlspecialchars($sec['title'] ?? ''); ?>" placeholder="Section Title" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-weight:600">
            <textarea name="section_content[]" rows="3" placeholder="Section content..." style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-size:13px"><?php echo htmlspecialchars($sec['content'] ?? ''); ?></textarea>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    
    <button type="button" onclick="addCustomSection()" style="padding:8px 14px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer;font-size:13px">+ Add Section</button>
    
    <script>
    function addCustomSection() {
      var list = document.getElementById('customSectionsListCo');
      var idx = list.querySelectorAll('.custom-section-row').length;
      var div = document.createElement('div');
      div.className = 'custom-section-row';
      div.style.cssText = 'padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff';
      div.innerHTML = '<div class="grid">'+
        '<div style="display:flex;align-items:center;gap:8px">'+
          '<label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="section_enabled['+idx+']" value="1" checked><span style="font-size:13px">Enabled</span></label>'+
          '<button type="button" onclick="this.closest(\'.custom-section-row\').remove()" style="margin-left:auto;padding:4px 10px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;border-radius:4px;font-size:12px;cursor:pointer">Remove</button>'+
        '</div>'+
        '<input type="text" name="section_title[]" placeholder="Section Title" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-weight:600">'+
        '<textarea name="section_content[]" rows="3" placeholder="Section content..." style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-size:13px"></textarea>'+
      '</div>';
      list.appendChild(div);
    }
    </script>
  </fieldset>
<?php endif; ?>

<?php if ($docTab === 'invoices'): ?>
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
    <legend style="padding:0 6px;color:var(--muted)">Invoice Display Options</legend>
    <div class="grid">
      <label>
        <input type="checkbox" name="invoice_show_terms" value="1" <?php echo (!isset($appConfig['invoice_show_terms']) || !empty($appConfig['invoice_show_terms'])) ? 'checked' : ''; ?>>
        <span class="font-600">Show terms on Invoices</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">When enabled, the standard terms will appear on invoice PDFs.</div>
      </label>
      <label>
        <input type="checkbox" name="invoice_show_project_code" value="1" <?php echo (!isset($appConfig['invoice_show_project_code']) || !empty($appConfig['invoice_show_project_code'])) ? 'checked' : ''; ?>>
        <span class="font-600">Show project code on Invoices</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Display the project/job code on invoice detail and PDF views.</div>
      </label>
      <label>
        <input type="checkbox" name="invoice_show_due_date" value="1" <?php echo (!isset($appConfig['invoice_show_due_date']) || !empty($appConfig['invoice_show_due_date'])) ? 'checked' : ''; ?>>
        <span class="font-600">Show due date on Invoices</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Display the payment due date prominently on invoice views.</div>
      </label>
    </div>
  </fieldset>

  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
    <legend style="padding:0 6px;color:var(--muted)">Invoice Defaults</legend>
    <div class="grid">
      <label>
        <div style="margin-bottom:4px;font-weight:600">Default Payment Terms (NET days)</div>
        <input type="number" name="net_terms_days" value="<?php echo (int)($appConfig['net_terms_days'] ?? 30); ?>" min="0" max="365" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Number of days until an invoice is considered overdue (e.g., NET 30).</div>
      </label>
    </div>
  </fieldset>

  <div style="margin-top:16px;padding:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:13px">
    ℹ️ <strong>Invoice automation and email reminders</strong> are managed on the <a href="/?page=settings&tab=notifications" style="color:var(--nav-accent);font-weight:600">Notifications</a> tab.
  </div>
<?php endif; ?>

<?php if ($docTab !== 'customization'): ?>
  <div>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save</button>
  </div>
</form>
<?php endif; ?>

<?php if ($docTab === 'customization'): ?>
  <?php include __DIR__ . '/documents/customization.php'; ?>
<?php endif; ?>
