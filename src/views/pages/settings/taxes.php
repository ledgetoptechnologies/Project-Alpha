<?php
// src/views/pages/settings/taxes.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/tax_lookup.php';

if (session_status() !== PHP_SESSION_ACTIVE && !defined('PA_SESSION_READ_ONLY')) { session_start(); }

// Check for import success/error messages
$importSuccess = isset($_GET['import_success']);
$importError = isset($_GET['import_error']) ? $_GET['import_error'] : null;
$importSummary = $_SESSION['tax_import_summary'] ?? null;

// Clear session data after reading
if ($importSuccess) unset($_SESSION['tax_import_summary']);

$editTaxId = (int)($_GET['edit_tax_id'] ?? 0);
$taxRates = [];
$taxCountySearch = trim((string)($_GET['tax_county'] ?? ''));
$taxImportStates = pa_tax_state_options();
$taxImportStatus = [];
$taxImportRuns = [];
foreach ($taxImportStates as $stateOption) {
  $taxImportStatus[$stateOption['fips']] = [
    'state' => $stateOption,
    'counties' => 0,
    'jurisdictions' => 0,
    'boundaries' => 0,
    'files' => [],
  ];
}
$selectedImportFips = pa_tax_state_fips_for_hint((string)($_GET['tax_state'] ?? ($_SESSION['tax_import_state'] ?? 'WI'))) ?? '55';
$selectedImportState = pa_tax_state_abbr_for_fips($selectedImportFips);
// Detect whether the tax_rates table includes an `is_default` column (older DBs may not)
$hasDefault = false;
try {
  $hasDefault = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tax_rates' AND COLUMN_NAME='is_default'")->fetchColumn();
} catch (Throwable $_e) {
  // ignore
}
try {
  $cols = 'id, name, country, state, county, rate, is_active' . ($hasDefault ? ', is_default' : '') . ', created_at';
  $order = $hasDefault ? 'is_default DESC, country, state, county, name' : 'country, state, county, name';
  $where = '';
  $params = [];
  if ($taxCountySearch !== '') {
    $where = ' WHERE county LIKE ? OR name LIKE ?';
    $like = '%' . $taxCountySearch . '%';
    $params = [$like, $like];
  }
  $stmt = $pdo->prepare("SELECT {$cols} FROM tax_rates{$where} ORDER BY {$order}");
  $stmt->execute($params);
  $taxRates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // ignore if table doesn't exist or other DB issues
}
try {
  $rows = $pdo->query("SELECT state_fips, file_type, original_name, size_bytes, state_tax_rate, imported_at FROM tax_import_files ORDER BY imported_at DESC")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    $stateFips = (string)($row['state_fips'] ?? '');
    $fileType = (string)($row['file_type'] ?? '');
    if (isset($taxImportStatus[$stateFips]) && $fileType !== '') {
      $taxImportStatus[$stateFips]['files'][$fileType] = $row;
    }
  }
} catch (Throwable $e) {
  // ignore if import cache is not created yet
}
try {
  $taxImportRuns = $pdo->query(
    "SELECT id, state_fips, state_abbr, status, phase, message, fips_rows, rate_rows, boundary_rows, warning_count, started_at, updated_at, completed_at, error_message
     FROM tax_import_runs
     ORDER BY started_at DESC, id DESC
     LIMIT 8"
  )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // ignore if import runs table is not created yet
}
foreach ([
  'counties' => 'SELECT state_fips, COUNT(*) AS row_count FROM fips_counties GROUP BY state_fips',
  'jurisdictions' => 'SELECT state_fips, COUNT(*) AS row_count FROM tax_jurisdictions WHERE is_active = 1 GROUP BY state_fips',
  'boundaries' => 'SELECT state_fips, COUNT(*) AS row_count FROM tax_boundaries GROUP BY state_fips',
] as $key => $sql) {
  try {
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $stateFips = (string)($row['state_fips'] ?? '');
      if (isset($taxImportStatus[$stateFips])) {
        $taxImportStatus[$stateFips][$key] = (int)($row['row_count'] ?? 0);
      }
    }
  } catch (Throwable $e) {
    // ignore if import tables are not created yet
  }
}
$selectedRateFile = $taxImportStatus[$selectedImportFips]['files']['rates'] ?? null;
$selectedStateTaxRateValue = $selectedRateFile && $selectedRateFile['state_tax_rate'] !== null
  ? number_format((float)$selectedRateFile['state_tax_rate'], 2, '.', '')
  : ($selectedImportState === 'WI' ? '5.00' : '0.00');
?>

<div style="max-width:1000px">
  <h2 style="margin:0 0 8px 0">Tax Rates</h2>
  <p style="margin:0 0 24px 0;color:var(--muted)">Manage predefined tax rates for different jurisdictions. Select a tax rate when creating documents to auto-populate tax calculations.</p>

  <?php if ($taxRates || $taxCountySearch !== ''): ?>
    <div style="margin-bottom:20px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
      <div style="padding:12px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb;border-radius:8px 8px 0 0;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div>
          <span style="font-weight:600;color:#374151"><?php echo count($taxRates); ?> Tax Rate<?php echo count($taxRates) !== 1 ? 's' : ''; ?></span>
          <?php if ($taxCountySearch !== ''): ?>
            <span style="font-size:12px;color:#6b7280;margin-left:8px">matching "<?php echo htmlspecialchars($taxCountySearch); ?>"</span>
          <?php endif; ?>
        </div>
        <form method="get" action="/" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0">
          <input type="hidden" name="page" value="settings">
          <input type="hidden" name="tab" value="taxes">
          <label style="font-size:12px;color:#374151;font-weight:600" for="taxCountySearch">County</label>
          <input id="taxCountySearch" type="search" name="tax_county" value="<?php echo htmlspecialchars($taxCountySearch); ?>" placeholder="Search county..."
                 style="width:220px;max-width:100%;padding:8px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;background:#fff">
          <button type="submit" style="padding:8px 12px;border-radius:8px;border:0;background:#111827;color:#fff;font-size:13px;font-weight:600;cursor:pointer">Search</button>
          <?php if ($taxCountySearch !== ''): ?>
            <a href="/?page=settings&amp;tab=taxes" style="padding:8px 12px;border-radius:8px;border:1px solid #d1d5db;background:#fff;text-decoration:none;color:#374151;font-size:13px">Clear</a>
          <?php endif; ?>
        </form>
      </div>
      <div style="max-height:400px;overflow-y:auto">
        <table class="pa-table">
          <thead style="position:sticky;top:0;background:#f9fafb;z-index:1">
            <tr style="text-align:left;border-bottom:2px solid #e5e7eb">
              <th style="padding:12px">Name</th>
              <th style="padding:12px">Country</th>
              <th style="padding:12px">State</th>
              <th style="padding:12px">County</th>
              <th style="padding:12px">Rate %</th>
              <th style="padding:12px">Status</th>
              <?php if ($hasDefault): ?><th style="padding:12px">Default</th><?php endif; ?>
              <th style="padding:12px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$taxRates): ?>
            <tr>
              <td colspan="<?php echo $hasDefault ? 8 : 7; ?>" style="padding:24px;text-align:center;color:#6b7280">
                No tax rates matched that county search.
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($taxRates as $tr): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
              <td style="padding:12px;font-weight:600"><?php echo htmlspecialchars($tr['name']); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['country'] ?: '—'); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['state'] ?: '—'); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['county'] ?: '—'); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['rate']); ?>%</td>
              <td style="padding:12px">
                <?php if ($tr['is_active']): ?>
                  <span style="padding:4px 8px;border-radius:6px;background:#d1fae5;color:#065f46;font-size:12px;font-weight:600">Active</span>
                <?php else: ?>
                  <span style="padding:4px 8px;border-radius:6px;background:#f3f4f6;color:#6b7280;font-size:12px">Inactive</span>
                <?php endif; ?>
              </td>
              <?php if ($hasDefault): ?>
              <td style="padding:12px">
                <?php if ($tr['is_default']): ?>
                  <span style="padding:4px 8px;border-radius:6px;background:#dbeafe;color:#1e40af;font-size:12px;font-weight:600">✓ Default</span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td style="padding:12px">
                <div class="flex">
                  <a href="/?page=settings&tab=taxes&edit_tax_id=<?php echo (int)$tr['id']; ?>" 
                     style="padding:6px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;text-decoration:none;color:inherit;font-size:13px">
                    Edit
                  </a>
                  <form method="post" action="/?page=settings/tax-rates-handler" style="display:inline" onsubmit="return confirm('Delete this tax rate?');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$tr['id']; ?>">
                    <button type="submit" style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fff;color:#991b1b;font-size:13px;cursor:pointer">
                      Delete
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <div style="padding:40px;text-align:center;border:2px dashed #e5e7eb;border-radius:8px;background:#fafafa;margin-bottom:20px">
      <div style="font-size:48px;margin-bottom:12px">📋</div>
      <div style="font-size:16px;font-weight:600;margin-bottom:8px;color:#374151">No Tax Rates Configured</div>
      <div style="color:var(--muted);font-size:14px">Create your first tax rate to get started</div>
    </div>
  <?php endif; ?>

  <?php
    // Prefill edit values when editing
    $editRow = null;
    if ($editTaxId > 0) {
      foreach ($taxRates as $tr) { if ((int)$tr['id'] === $editTaxId) { $editRow = $tr; break; } }
    }
  ?>

  <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:24px;background:#fff">
    <legend style="padding:0 12px;font-weight:600;font-size:16px"><?php echo $editRow ? 'Edit Tax Rate' : 'Add New Tax Rate'; ?></legend>
    
    <form method="post" action="/?page=settings/tax-rates-handler" style="display:grid;gap:16px;max-width:720px">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)($editRow['id'] ?? 0); ?>">
      
      <label>
        <div class="label">Tax Rate Name *</div>
        <input type="text" name="name" value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>" required 
               placeholder="e.g., California State Tax, NYC Sales Tax"
               style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        <div style="margin-top:4px;color:var(--muted);font-size:12px">A descriptive name for this tax rate</div>
      </label>
      
      <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px">
        <label>
          <div class="label">Country</div>
          <input type="text" name="country" value="<?php echo htmlspecialchars($editRow['country'] ?? 'USA'); ?>" 
                 placeholder="USA"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
        <label>
          <div class="label">State/Province</div>
          <input type="text" name="state" value="<?php echo htmlspecialchars($editRow['state'] ?? ''); ?>" 
                 placeholder="e.g., CA, NY"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
        <label>
          <div class="label">County</div>
          <input type="text" name="county" value="<?php echo htmlspecialchars($editRow['county'] ?? ''); ?>" 
                 placeholder="Optional"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
      </div>
      
      <label>
        <div class="label">Tax Rate (%) *</div>
        <input type="number" step="0.01" min="0" max="100" name="rate" 
               value="<?php echo htmlspecialchars($editRow['rate'] ?? '0.00'); ?>" 
               required
               placeholder="e.g., 7.5"
               style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:200px;font-size:14px">
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Enter the percentage as a decimal (e.g., 7.5 for 7.5%)</div>
      </label>
      
      <div style="display:flex;gap:24px;padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
          <input type="checkbox" name="is_active" value="1" 
                 <?php echo (!isset($editRow) || ($editRow['is_active'] ?? 1)) ? 'checked' : ''; ?>>
          <div>
            <div class="font-600">Active</div>
            <div style="font-size:12px;color:var(--muted)">Available for selection in documents</div>
          </div>
        </label>
        <?php if ($hasDefault): ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
          <input type="checkbox" name="is_default" value="1" 
                 <?php echo ($editRow['is_default'] ?? 0) ? 'checked' : ''; ?>>
          <div>
            <div class="font-600">Set as Default</div>
            <div style="font-size:12px;color:var(--muted)">Auto-fill this rate when creating documents</div>
          </div>
        </label>
        <?php endif; ?>
      </div>
      
      <div style="display:flex;gap:12px;padding-top:8px">
        <button type="submit" 
                style="padding:10px 20px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;font-size:14px">
          <?php echo $editRow ? 'Update Tax Rate' : 'Save Tax Rate'; ?>
        </button>
        <?php if ($editRow): ?>
          <a href="/?page=settings&tab=taxes" 
             style="padding:10px 20px;border-radius:8px;border:1px solid #d1d5db;background:#fff;text-decoration:none;color:inherit;display:inline-block;font-size:14px">
            Cancel
          </a>
        <?php endif; ?>
      </div>
    </form>
  </fieldset>
  
  <div style="margin-top:24px;padding:16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;line-height:1.6">
    <strong>💡 How Tax Rates Work:</strong>
    <ul style="margin:8px 0 0 20px;padding:0">
      <li>When creating documents (quotes, contracts, invoices), you can select a predefined tax rate or manually enter a percentage.</li>
      <li>The <strong>default tax rate</strong> will be automatically selected when creating new documents.</li>
      <li>Only <strong>one</strong> tax rate can be set as default at a time.</li>
      <li>Inactive tax rates won't appear in document creation forms but remain in the system for historical records.</li>
    </ul>
  </div>
  
  <!-- Import Tax Rates Section -->
  <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:24px;background:#fff;margin-top:24px">
    <legend style="padding:0 12px;font-weight:600;font-size:16px">📥 Import Tax Rates</legend>
    
    <p style="margin:0 0 16px;color:#6b7280;font-size:14px">
      Import official source files for counties, rates, and ZIP boundaries. Upload one or more files; any missing file type is reused from the last successful import for that state.
    </p>

    <div style="margin-bottom:18px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb">
        <div>
          <div style="font-weight:600;color:#374151">Imported State Coverage</div>
          <div style="font-size:12px;color:#6b7280;margin-top:2px">Each state keeps separate county, rate, boundary, and source-file status.</div>
        </div>
        <div style="font-size:12px;color:#6b7280">Selected: <?php echo htmlspecialchars($selectedImportState); ?></div>
      </div>
      <div style="max-height:260px;overflow:auto">
        <table class="pa-table">
          <thead style="position:sticky;top:0;background:#f9fafb;z-index:1">
            <tr style="text-align:left;border-bottom:1px solid #e5e7eb">
              <th style="padding:10px 12px">State</th>
              <th style="padding:10px 12px">Counties</th>
              <th style="padding:10px 12px">Rates</th>
              <th style="padding:10px 12px">Boundaries</th>
              <th style="padding:10px 12px">Files</th>
              <th style="padding:10px 12px">State Tax</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($taxImportStatus as $stateFips => $stateStatus): ?>
              <?php
                $state = $stateStatus['state'];
                $files = $stateStatus['files'];
                $fileLabels = [];
                foreach (['fips' => 'FIPS', 'rates' => 'Rates', 'boundaries' => 'Boundaries'] as $fileType => $fileLabel) {
                  if (isset($files[$fileType])) {
                    $fileLabels[] = $fileLabel . ': ' . (string)$files[$fileType]['original_name'];
                  }
                }
                $rateFile = $files['rates'] ?? null;
                $rateDisplay = $rateFile && $rateFile['state_tax_rate'] !== null ? number_format((float)$rateFile['state_tax_rate'], 2) . '%' : '--';
                $isSelectedState = $stateFips === $selectedImportFips;
              ?>
              <tr style="border-bottom:1px solid #f3f4f6;<?php echo $isSelectedState ? 'background:#f0f9ff' : ''; ?>">
                <td style="padding:10px 12px;font-weight:600;white-space:nowrap">
                  <?php echo htmlspecialchars($state['abbr']); ?>
                  <span style="font-weight:400;color:#6b7280">- <?php echo htmlspecialchars($state['name']); ?></span>
                </td>
                <td style="padding:10px 12px"><?php echo number_format((int)$stateStatus['counties']); ?></td>
                <td style="padding:10px 12px"><?php echo number_format((int)$stateStatus['jurisdictions']); ?></td>
                <td style="padding:10px 12px"><?php echo number_format((int)$stateStatus['boundaries']); ?></td>
                <td style="padding:10px 12px;font-size:12px;color:#374151">
                  <?php echo $fileLabels ? htmlspecialchars(implode(' | ', $fileLabels)) : '<span style="color:#9ca3af">Not imported</span>'; ?>
                </td>
                <td style="padding:10px 12px;white-space:nowrap"><?php echo htmlspecialchars($rateDisplay); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($taxImportRuns): ?>
      <div style="margin-bottom:18px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb">
          <div>
            <div style="font-weight:600;color:#374151">Recent Import Runs</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px">Refresh this page to see the latest database progress. Server logs also include <code>[tax-import]</code> entries.</div>
          </div>
        </div>
        <div style="overflow:auto">
          <table class="pa-table">
            <thead style="background:#f9fafb">
              <tr style="text-align:left;border-bottom:1px solid #e5e7eb">
                <th style="padding:10px 12px">State</th>
                <th style="padding:10px 12px">Status</th>
                <th style="padding:10px 12px">Phase</th>
                <th style="padding:10px 12px">Rows</th>
                <th style="padding:10px 12px">Message</th>
                <th style="padding:10px 12px">Updated</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($taxImportRuns as $run): ?>
                <?php
                  $status = (string)($run['status'] ?? '');
                  $statusStyle = match ($status) {
                    'completed' => 'background:#d1fae5;color:#065f46',
                    'failed' => 'background:#fee2e2;color:#991b1b',
                    default => 'background:#dbeafe;color:#1e40af',
                  };
                  $rowCounts = 'FIPS ' . number_format((int)($run['fips_rows'] ?? 0))
                    . ' | Rates ' . number_format((int)($run['rate_rows'] ?? 0))
                    . ' | Boundaries ' . number_format((int)($run['boundary_rows'] ?? 0))
                    . ' | Warnings ' . number_format((int)($run['warning_count'] ?? 0));
                  $message = $run['error_message'] ?: ($run['message'] ?? '');
                ?>
                <tr style="border-bottom:1px solid #f3f4f6">
                  <td style="padding:10px 12px;font-weight:600;white-space:nowrap"><?php echo htmlspecialchars((string)$run['state_abbr']); ?></td>
                  <td style="padding:10px 12px;white-space:nowrap">
                    <span style="padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;<?php echo $statusStyle; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                  </td>
                  <td style="padding:10px 12px;white-space:nowrap"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$run['phase'])); ?></td>
                  <td style="padding:10px 12px;font-size:12px;white-space:nowrap;color:#374151"><?php echo htmlspecialchars($rowCounts); ?></td>
                  <td style="padding:10px 12px;min-width:220px;color:#374151"><?php echo htmlspecialchars((string)$message); ?></td>
                  <td style="padding:10px 12px;white-space:nowrap;color:#6b7280;font-size:12px"><?php echo htmlspecialchars((string)$run['updated_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
    
    <?php if ($importSuccess && $importSummary): ?>
      <div style="margin-bottom:16px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px">
        <pre style="margin:0;font-family:monospace;font-size:12px;white-space:pre-wrap;color:#047857"><?php echo htmlspecialchars($importSummary); ?></pre>
      </div>
    <?php endif; ?>
    <?php if ($importError): ?>
      <div style="margin-bottom:16px;padding:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px">
        <div style="color:#b91c1c;font-size:13px">❌ <?php echo htmlspecialchars($importError); ?></div>
      </div>
    <?php endif; ?>
    
    <form method="post" action="/?page=settings/tax-import-handler" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:760px" data-tax-import-form>
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <div data-tax-import-chunk-fields></div>

      <label>
        <div style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">State</div>
        <select name="tax_state" style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;max-width:360px;font-size:14px;background:#fff">
          <?php foreach ($taxImportStates as $stateOption): ?>
            <option value="<?php echo htmlspecialchars($stateOption['abbr']); ?>" <?php echo $stateOption['fips'] === $selectedImportFips ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($stateOption['abbr'] . ' - ' . $stateOption['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:4px;color:var(--muted);font-size:11px">PA imports and replaces rows only for this selected state.</div>
      </label>
      
      <div class="grid">
        <div>
          <label style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">📍 FIPS County File (.txt)</label>
          <input type="file" name="fips_file" accept=".txt" data-tax-import-file="fips_file"
                 style="padding:10px 12px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
          <div style="margin-top:4px;color:var(--muted);font-size:11px">Census Bureau county file (e.g., st55_wi_cou2020.txt). Reused when omitted.</div>
        </div>
        
        <div>
          <label style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">💰 Tax Rate File (.csv)</label>
          <input type="file" name="rate_file" accept=".csv" data-tax-import-file="rate_file"
                 style="padding:10px 12px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
          <div style="margin-top:4px;color:var(--muted);font-size:11px">State tax rate file (e.g., WIR072026.csv). Reused when omitted.</div>
        </div>

        <div>
          <label style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">🗺️ Boundary File (.csv)</label>
          <input type="file" name="boundary_file" accept=".csv" data-tax-import-file="boundary_file"
                 style="padding:10px 12px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
          <div style="margin-top:4px;color:var(--muted);font-size:11px">State boundary file (e.g., WIB072026.csv). Large files are streamed and may take several minutes.</div>
        </div>

        <div>
          <label style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">State Tax Rate (%)</label>
          <input type="number" step="0.01" min="0" max="20" name="state_tax_rate" value="<?php echo htmlspecialchars($selectedStateTaxRateValue); ?>"
                 style="padding:10px 12px;border-radius:6px;border:1px solid #d1d5db;width:120px;font-size:13px;background:#fff">
          <div style="margin-top:4px;color:var(--muted);font-size:11px">State sales tax to add before local county/city rates.</div>
        </div>
      </div>
      
      <button type="submit" style="padding:12px 24px;border-radius:8px;border:0;background:#059669;color:#fff;font-weight:600;cursor:pointer;font-size:14px;width:fit-content">
        📥 Import Tax Rates
      </button>
      <div data-tax-import-progress style="display:none;padding:10px 12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px"></div>
    </form>
    <script>
      (function () {
        var form = document.querySelector('[data-tax-import-form]');
        if (!form || form.dataset.taxChunkReady === '1') return;
        form.dataset.taxChunkReady = '1';
        var threshold = 80 * 1024 * 1024;
        var maxChunkedFileSize = 400 * 1024 * 1024;
        var chunkSize = 8 * 1024 * 1024;
        var progress = form.querySelector('[data-tax-import-progress]');
        var fields = form.querySelector('[data-tax-import-chunk-fields]');

        function setProgress(message, tone) {
          if (!progress) return;
          progress.style.display = message ? 'block' : 'none';
          progress.style.background = tone === 'error' ? '#fef2f2' : '#eff6ff';
          progress.style.borderColor = tone === 'error' ? '#fecaca' : '#bfdbfe';
          progress.style.color = tone === 'error' ? '#991b1b' : '#1e40af';
          progress.textContent = message || '';
        }

        function randomToken() {
          var bytes = new Uint8Array(16);
          if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
          } else {
            for (var i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
          }
          return Array.prototype.map.call(bytes, function (byte) {
            return byte.toString(16).padStart(2, '0');
          }).join('');
        }

        function putHidden(name, value) {
          var input = fields.querySelector('input[name="' + name + '"]');
          if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            fields.appendChild(input);
          }
          input.value = value;
        }

        async function uploadLargeFile(input, file) {
          if (file.size > maxChunkedFileSize) {
            throw new Error('Tax import files must be 400 MB or smaller.');
          }
          var field = input.getAttribute('data-tax-import-file');
          var token = randomToken();
          var total = Math.ceil(file.size / chunkSize);
          var csrf = form.querySelector('input[name="csrf"]').value;
          for (var index = 0; index < total; index += 1) {
            var start = index * chunkSize;
            var chunk = file.slice(start, Math.min(start + chunkSize, file.size));
            var body = new FormData();
            body.append('csrf', csrf);
            body.append('field', field);
            body.append('upload_id', token);
            body.append('file_name', file.name);
            body.append('file_size', String(file.size));
            body.append('chunk_index', String(index));
            body.append('total_chunks', String(total));
            body.append('chunk', chunk, file.name + '.part' + index);
            setProgress('Uploading ' + file.name + ' chunk ' + (index + 1) + ' of ' + total + '...');
            var response = await fetch('/?page=settings/tax-import-chunk', {
              method: 'POST',
              body: body,
              headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var result = await response.json();
            if (!result.ok) {
              throw new Error(result.error || 'Chunk upload failed.');
            }
          }
          return token;
        }

        form.addEventListener('submit', async function (event) {
          if (form.dataset.taxChunkComplete === '1') return;
          var largeInputs = Array.prototype.filter.call(form.querySelectorAll('[data-tax-import-file]'), function (input) {
            return input.files && input.files[0] && input.files[0].size > threshold;
          });
          if (!largeInputs.length) return;

          event.preventDefault();
          var submitter = form.querySelector('button[type="submit"]');
          if (submitter) submitter.disabled = true;
          try {
            fields.innerHTML = '';
            var uploaded = [];
            for (var i = 0; i < largeInputs.length; i += 1) {
              uploaded.push({
                input: largeInputs[i],
                field: largeInputs[i].getAttribute('data-tax-import-file'),
                token: await uploadLargeFile(largeInputs[i], largeInputs[i].files[0])
              });
            }
            uploaded.forEach(function (item) {
              putHidden(item.field + '_chunk_token', item.token);
              item.input.value = '';
            });
            form.dataset.taxChunkComplete = '1';
            setProgress('Large files uploaded. Starting tax import...');
            if (form.requestSubmit) {
              form.requestSubmit(submitter || undefined);
            } else {
              form.submit();
            }
          } catch (error) {
            setProgress(error.message || 'Large file upload failed.', 'error');
            if (submitter) submitter.disabled = false;
          }
        });
      })();
    </script>

    <div style="margin-top:20px;padding:12px;background:#f0f9ff;border-left:4px solid #0284c7;border-radius:4px;font-size:13px">
      <strong>📋 How it works:</strong>
      <ul style="margin:8px 0 0 16px;padding:0;color:#1e40af">
        <li>FIPS, rate, and boundary files are stored as reusable import sources.</li>
        <li>Each uploaded source file must be 400 MB or smaller.</li>
        <li>Select the state before uploading; PA only replaces imported rows for that state.</li>
        <li>If you upload only one updated file, PA keeps the other previously imported tables for that state.</li>
        <li>Rate imports replace current imported jurisdiction rows for the selected state and mirror county totals into the normal tax rate list.</li>
        <li>Boundary imports load into a staging table first, then replace the selected state's live boundary rows after the full file succeeds.</li>
        <li>ZIP lookup can still show multiple states when an imported ZIP crosses a state line.</li>
      </ul>
    </div>
    
    <details style="margin-top:16px">
      <summary style="cursor:pointer;font-weight:600;color:#374151;padding:8px 0">📖 Where to get these files</summary>
      <div style="padding:12px;background:#f9fafb;border-radius:8px;margin-top:8px;font-size:13px;line-height:1.6">
        <p style="margin:0 0 12px"><strong>1. FIPS Counties (.txt file):</strong></p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>US Census Bureau: <a href="https://www2.census.gov/geo/docs/reference/codes2020/cou/" target="_blank" rel="noopener" style="color:#2563eb">ANSI FIPS County Codes</a></li>
          <li>Download the county file for your state (e.g., st55_wi_cou2020.txt)</li>
        </ul>
        <p style="margin:0 0 12px"><strong>2. Tax Rates and Boundaries (.csv files):</strong></p>
        <p style="margin:0 0 8px">Examples of official state sources:</p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>Wisconsin: <a href="https://www.revenue.wi.gov/Pages/SSTP/ratebound.aspx" target="_blank" rel="noopener" style="color:#2563eb">WI Dept of Revenue - SSTP Rate and Boundary Files</a>
            <ul style="margin:4px 0 0 20px;padding:0">
              <li>Rate file: WIR062026.csv (or similar)</li>
              <li>Boundary file: WIB062026.csv (or similar)</li>
            </ul>
          </li>
          <li>Other states: use the equivalent SSTGB rate/boundary files from your state Department of Revenue.</li>
        </ul>
      </div>
    </details>
  </fieldset>
</div>
