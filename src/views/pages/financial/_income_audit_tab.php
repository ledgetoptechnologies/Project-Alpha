<?php
// src/views/pages/financial/_income_audit_tab.php (Reports > Income audit, financial.audit)
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';

$auditYear = date('Y');
$auditBasisOptions = ['cash' => 'Cash: when payments arrive', 'accrual' => 'Accrual: when invoices are finalized'];
?>
<div class="fin-report">
  <form id="auditForm" method="POST" action="/?page=financial/audit-export" class="card fin-form">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('audit')); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="generate">
    <input type="hidden" name="report_type" value="audit">
    <input type="hidden" name="include_invoices" value="1">

    <div class="fin-form__grid">
      <label class="field"><span class="label-muted">Start date</span><input type="date" name="start_date" class="input" value="<?php echo $auditYear; ?>-01-01"></label>
      <label class="field"><span class="label-muted">End date</span><input type="date" name="end_date" class="input" value="<?php echo $auditYear; ?>-12-31"></label>
      <label class="field"><span class="label-muted">Basis</span>
        <select name="accounting_basis" class="input">
          <?php foreach ($auditBasisOptions as $value => $label): ?><option value="<?php echo $value; ?>"><?php echo $label; ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="fin-presets">
      <button type="button" class="btn btn-sm preset-btn" data-preset="this-year">This year</button>
      <button type="button" class="btn btn-sm preset-btn" data-preset="last-quarter">Last quarter</button>
      <button type="button" class="btn btn-sm preset-btn" data-preset="last-month">Last month</button>
      <button type="button" class="btn btn-sm preset-btn" data-preset="all-time">All time</button>
    </div>

    <fieldset class="fin-checks">
      <legend>Include</legend>
      <label><input type="checkbox" checked disabled> Paid and partially paid invoices</label>
      <label><input type="checkbox" name="include_unpaid_invoices" value="1"> Unpaid invoices</label>
      <label><input type="checkbox" name="include_contracts" value="1"> Contracts</label>
      <label><input type="checkbox" name="include_quotes" value="1"> Quotes</label>
    </fieldset>
    <fieldset class="fin-checks">
      <legend>Files</legend>
      <label><input type="checkbox" name="generate_csv" value="1" checked> CSV</label>
      <label><input type="checkbox" name="include_pdfs" value="1"> Document PDFs</label>
    </fieldset>

    <div class="fin-form__actions">
      <button type="reset" class="btn">Reset</button>
      <button type="submit" class="btn btn-primary">Export</button>
    </div>
  </form>

  <details class="card fin-schedule" id="auditSchedulePanel">
    <summary>Schedule this report</summary>
    <form id="auditScheduleForm" method="POST" action="/?page=financial/audit-schedule-handler" class="fin-form">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="report_type" value="audit">
      <input type="hidden" name="include_invoices" value="1">

      <div class="fin-form__grid">
        <label class="field"><span class="label-muted">Every</span>
          <select name="schedule_frequency" class="input">
            <option value="weekly">Week (Mondays)</option>
            <option value="monthly" selected>Month (the 1st)</option>
            <option value="quarterly">Quarter (Jan, Apr, Jul, Oct)</option>
            <option value="annually">Year (January 1)</option>
          </select>
        </label>
        <label class="field"><span class="label-muted">Period</span>
          <select name="schedule_date_range" class="input">
            <option value="last_week">Previous week</option>
            <option value="last_month">Previous month</option>
            <option value="last_quarter">Previous quarter</option>
            <option value="last_year">Previous year</option>
            <option value="current_year" selected>Current year to date</option>
            <option value="all_time">All time</option>
          </select>
        </label>
        <label class="field"><span class="label-muted">Basis</span>
          <select name="accounting_basis" class="input">
            <?php foreach ($auditBasisOptions as $value => $label): ?><option value="<?php echo $value; ?>"><?php echo $label; ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <fieldset class="fin-checks">
        <legend>Include</legend>
        <label><input type="checkbox" name="include_unpaid_invoices" value="1"> Unpaid invoices</label>
        <label><input type="checkbox" name="include_contracts" value="1"> Contracts</label>
        <label><input type="checkbox" name="include_quotes" value="1"> Quotes</label>
        <label><input type="checkbox" name="include_pdfs" value="1"> Document PDFs</label>
      </fieldset>
      <div class="field">
        <span class="label-muted">Send to (up to 5)</span>
        <div id="emailContainer" class="fin-emails">
          <input type="email" name="schedule_email[]" class="input" placeholder="name@example.com" required>
        </div>
        <button type="button" id="addEmailBtn" class="btn btn-sm">+ Add email</button>
      </div>
      <div class="fin-form__actions"><button type="submit" class="btn btn-primary">Save schedule</button></div>
    </form>
  </details>
</div>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/audit-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
