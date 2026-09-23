<?php

declare(strict_types=1);

$userId = (int)($_SESSION['user']['id'] ?? 0);
$userRole = acl_user_role($pdo, $userId);
$isAdmin = in_array($userRole, ['admin', 'owner'], true);
$canManagePayments = $isAdmin || user_can($pdo, $userId, 'workforce.payments.manage', 0);
$canManagePayrollExports = $isAdmin || user_can($pdo, $userId, 'workforce.payroll_exports.manage', 0);
$manage = $isAdmin
    || user_can($pdo, $userId, 'workforce.statements.manage', 0)
    || user_can($pdo, $userId, 'employee_pay.manage', 0);
$viewAll = $manage || user_can($pdo, $userId, 'employee_pay.view', 0);

$workerStatement = $pdo->prepare(
    'SELECT id,display_name,relationship_type,compensation_policy,status
     FROM worker_profiles WHERE user_id=? ORDER BY id LIMIT 1'
);
$workerStatement->execute([$userId]);
$currentWorker = $workerStatement->fetch(PDO::FETCH_ASSOC) ?: null;
$currentWorkerId = (int)($currentWorker['id'] ?? 0);
$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
};

$earningsSql =
    "SELECT e.*,wp.display_name,wp.relationship_type,
            CASE WHEN e.source_type='adjustment'
                       AND JSON_UNQUOTE(JSON_EXTRACT(e.calculation_snapshot,'$.direction'))='debit'
                 THEN -e.amount ELSE e.amount END AS display_amount,
            COALESCE(DATE(wt.start_time),DATE(wa.completed_at),DATE(e.eligible_at),DATE(e.approved_at),DATE(e.created_at)) AS source_work_date,
            wt.description AS time_description,jwc.name AS assignment_name,
            ws.id AS statement_id,ws.status AS statement_status,pp.period_start,pp.period_end
     FROM worker_earnings e
     JOIN worker_profiles wp ON wp.id=e.worker_profile_id
     LEFT JOIN work_time_entries wt ON wt.id=e.work_time_entry_id
     LEFT JOIN work_assignments wa ON wa.id=e.work_assignment_id
     LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
     LEFT JOIN worker_statement_lines sl ON sl.id=e.statement_line_id
     LEFT JOIN worker_statements ws ON ws.id=sl.worker_statement_id
     LEFT JOIN pay_periods pp ON pp.id=ws.pay_period_id";
if ($viewAll) {
    $earningsStatement = $pdo->query($earningsSql . ' ORDER BY source_work_date DESC,e.created_at DESC LIMIT 500');
} elseif ($currentWorkerId > 0) {
    $earningsStatement = $pdo->prepare(
        $earningsSql . ' WHERE e.worker_profile_id=? ORDER BY source_work_date DESC,e.created_at DESC LIMIT 500'
    );
    $earningsStatement->execute([$currentWorkerId]);
} else {
    $earningsStatement = $pdo->query($earningsSql . ' WHERE 1=0');
}
$earnings = $earningsStatement->fetchAll(PDO::FETCH_ASSOC);

$statementSql =
    'SELECT ws.*,pp.period_start,pp.period_end,wp.display_name,wp.relationship_type,
            (SELECT COUNT(*) FROM worker_statement_lines l WHERE l.worker_statement_id=ws.id) line_count,
            (SELECT COUNT(*) FROM worker_statement_lines l WHERE l.worker_statement_id=ws.id AND l.worker_earning_id IS NOT NULL) earning_line_count
     FROM worker_statements ws
     JOIN pay_periods pp ON pp.id=ws.pay_period_id
     JOIN worker_profiles wp ON wp.id=ws.worker_profile_id';
if ($viewAll) {
    $statementStatement = $pdo->query($statementSql . ' ORDER BY pp.period_end DESC,ws.id DESC');
} elseif ($currentWorkerId > 0) {
    $statementStatement = $pdo->prepare(
        $statementSql . ' WHERE ws.worker_profile_id=? ORDER BY pp.period_end DESC,ws.id DESC'
    );
    $statementStatement->execute([$currentWorkerId]);
} else {
    $statementStatement = $pdo->query($statementSql . ' WHERE 1=0');
}
$statements = $statementStatement->fetchAll(PDO::FETCH_ASSOC);
$paymentStatementsByWorker = [];
foreach ($statements as $statement) {
    if (in_array((string)$statement['status'], ['issued', 'settled'], true)) {
        $paymentStatementsByWorker[(int)$statement['worker_profile_id']][] = $statement;
    }
}

$paymentRecords = [];
if ($tableExists('worker_payment_records')) {
    $paymentSql =
        'SELECT pr.*,wp.display_name,wp.relationship_type,
                COUNT(a.id) allocation_count,COALESCE(SUM(a.amount),0) allocated_amount
         FROM worker_payment_records pr
         JOIN worker_profiles wp ON wp.id=pr.worker_profile_id
         LEFT JOIN worker_payment_allocations a ON a.worker_payment_record_id=pr.id';
    if ($viewAll) {
        $paymentStatement = $pdo->query($paymentSql . ' GROUP BY pr.id ORDER BY pr.payment_date DESC,pr.created_at DESC LIMIT 250');
    } elseif ($currentWorkerId > 0) {
        $paymentStatement = $pdo->prepare($paymentSql . ' WHERE pr.worker_profile_id=? GROUP BY pr.id ORDER BY pr.payment_date DESC,pr.created_at DESC LIMIT 250');
        $paymentStatement->execute([$currentWorkerId]);
    } else {
        $paymentStatement = $pdo->query($paymentSql . ' WHERE 1=0 GROUP BY pr.id');
    }
    $paymentRecords = $paymentStatement->fetchAll(PDO::FETCH_ASSOC);
}

$payrollExports = [];
if ($canManagePayrollExports && $tableExists('payroll_exports')) {
    $payrollExports = $pdo->query(
        'SELECT x.*,p.period_start,p.period_end FROM payroll_exports x
         LEFT JOIN pay_periods p ON p.id=x.pay_period_id
         ORDER BY x.created_at DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);
}
$payPeriodsForExport = $canManagePayrollExports
    ? $pdo->query('SELECT id,period_start,period_end,status FROM pay_periods ORDER BY period_end DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$currentPeriod = null;
if ($currentWorkerId > 0) {
    $periodStatement = $pdo->prepare(
        "SELECT pp.*,COALESCE(wps.status,'not_submitted') AS submission_status,wps.submitted_at,wps.notes
         FROM pay_periods pp
         LEFT JOIN worker_period_submissions wps ON wps.pay_period_id=pp.id AND wps.worker_profile_id=?
         WHERE pp.status='open' AND CURRENT_DATE BETWEEN pp.period_start AND pp.period_end
         ORDER BY pp.period_start DESC LIMIT 1"
    );
    $periodStatement->execute([$currentWorkerId]);
    $currentPeriod = $periodStatement->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Legacy accruals are visible only when migration did not create a canonical
// earning for the source entry. They are read-only compatibility records.
$legacySql =
    "SELECT a.*,s.time_entry_id,s.start_time,wp.id AS worker_profile_id
     FROM work_pay_accruals a
     JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
     JOIN worker_profiles wp ON wp.user_id=a.employee_user_id
     WHERE NOT EXISTS (
         SELECT 1 FROM worker_earnings e WHERE e.work_time_entry_id=s.time_entry_id
     )";
if ($viewAll) {
    $legacyStatement = $pdo->query($legacySql . ' ORDER BY s.start_time DESC LIMIT 250');
} elseif ($currentWorkerId > 0) {
    $legacyStatement = $pdo->prepare($legacySql . ' AND wp.id=? ORDER BY s.start_time DESC LIMIT 250');
    $legacyStatement->execute([$currentWorkerId]);
} else {
    $legacyStatement = $pdo->query($legacySql . ' AND 1=0');
}
$legacyRows = $legacyStatement->fetchAll(PDO::FETCH_ASSOC);

$totals = ['attention' => [], 'approved' => [], 'included' => [], 'settled' => []];
foreach ($earnings as $earning) {
    $status = (string)$earning['status'];
    $bucket = in_array($status, ['provisional', 'needs_setup', 'eligible'], true) ? 'attention' : $status;
    if (isset($totals[$bucket]) && $earning['display_amount'] !== null) {
        $currency = (string)$earning['currency'];
        $totals[$bucket][$currency] = ($totals[$bucket][$currency] ?? 0) + (float)$earning['display_amount'];
    }
}

$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatTotals = static function (array $values): string {
    if (!$values) {
        return '&mdash;';
    }
    $parts = [];
    foreach ($values as $currency => $amount) {
        $parts[] = htmlspecialchars((string)$currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format((float)$amount, 2);
    }
    return implode(' &middot; ', $parts);
};
$earningDescription = static function (array $earning): string {
    $description = trim((string)($earning['assignment_name'] ?: $earning['time_description']));
    if ($description !== '') {
        return $description;
    }
    return match ((string)$earning['source_type']) {
        'work_assignment' => 'Work assignment',
        'time_entry' => 'Time entry',
        'mileage' => 'Mileage reimbursement',
        'adjustment' => 'Compensation adjustment',
        default => ucwords(str_replace('_', ' ', (string)$earning['source_type'])),
    };
};
$statusLabel = static fn(string $status): string => match ($status) {
    'needs_setup' => 'Needs setup',
    'provisional' => 'Provisional',
    'eligible' => 'Awaiting approval',
    'approved' => 'Approved for period',
    'included' => 'On statement',
    'settled' => 'Settled',
    'adjusted' => 'Adjusted',
    'voided' => 'Voided',
    default => ucfirst($status),
};
?>

<section class="workforce-page" data-workforce-page>
  <div class="workforce-head">
    <div>
      <p class="workforce-eyebrow">Workforce</p>
      <h2><?= $viewAll ? 'Earnings & Pay' : 'My Earnings & Pay' ?></h2>
      <p class="workforce-subtitle">Review gross earnings, period statements, confirmed payment records, and payroll exports. Client billing is tracked separately and never determines worker pay.</p>
    </div>
    <div class="workforce-head__actions">
      <a class="btn" href="/?page=workforce/overview">Overview</a>
      <a class="btn" href="/?page=workforce/time">Time</a>
    </div>
  </div>

  <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= $h($_GET['success']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= $h($_GET['error']) ?></div><?php endif; ?>

  <?php if ($currentWorker && in_array((string)$currentWorker['compensation_policy'], ['nonpayable', 'owner_no_pay'], true)): ?>
    <div class="alert alert-info">This worker profile is configured as nonpayable. Time may still be tracked for operations, costing, and client billing, but it does not create worker earnings or a pay statement.</div>
  <?php elseif ($currentWorker && $currentPeriod): ?>
    <article class="card workforce-card">
      <div class="card-head">
        <div>
          <h3 class="card-title">Current period</h3>
          <p class="muted text-sm mb-0"><?= $h($currentPeriod['period_start']) ?> &ndash; <?= $h($currentPeriod['period_end']) ?></p>
        </div>
        <span class="status-pill status-pill--<?= $h($currentPeriod['submission_status']) ?>"><?= $h(ucwords(str_replace('_', ' ', (string)$currentPeriod['submission_status']))) ?></span>
      </div>
      <p class="muted mb-0">Submission confirms your period is ready for review; approved earnings are still controlled by their individual work and compensation rules.</p>
    </article>
  <?php endif; ?>

  <div class="workforce-kpis">
    <article class="workforce-kpi"><span>Needs review</span><strong style="font-size:20px"><?= $formatTotals($totals['attention']) ?></strong><small>Provisional, setup, or approval required</small></article>
    <article class="workforce-kpi"><span>Approved</span><strong style="font-size:20px"><?= $formatTotals($totals['approved']) ?></strong><small>Ready for an open pay period</small></article>
    <article class="workforce-kpi"><span>On statements</span><strong style="font-size:20px"><?= $formatTotals($totals['included']) ?></strong><small>Issued, not yet settled</small></article>
    <article class="workforce-kpi"><span>Settled</span><strong style="font-size:20px"><?= $formatTotals($totals['settled']) ?></strong><small>Recorded as paid or settled</small></article>
  </div>

  <div class="workforce-tabs" data-workforce-tabs>
    <div class="workforce-tab-list" role="tablist" aria-label="Earnings and pay sections">
      <button class="workforce-tab is-active" type="button" role="tab" aria-selected="true" data-workforce-tab="earnings">Earnings <span class="workforce-tab-count"><?= count($earnings) ?></span></button>
      <button class="workforce-tab" type="button" role="tab" aria-selected="false" data-workforce-tab="statements">Statements <span class="workforce-tab-count"><?= count($statements) ?></span></button>
      <button class="workforce-tab" type="button" role="tab" aria-selected="false" data-workforce-tab="payments">Payment records</button>
      <?php if ($canManagePayrollExports): ?><button class="workforce-tab" type="button" role="tab" aria-selected="false" data-workforce-tab="exports">Payroll exports</button><?php endif; ?>
    </div>

    <div data-workforce-tab-panel="earnings" role="tabpanel">
  <article class="card workforce-card workforce-card--table">
    <div class="card-head">
      <div>
        <h3 class="card-title">Earnings ledger</h3>
        <p class="muted text-sm mb-0">One canonical earning per approved source revision. Client rates and invoice amounts are intentionally not shown here.</p>
      </div>
    </div>
    <div class="pa-table-wrap">
      <table class="pa-table workforce-table">
        <thead><tr><?php if ($viewAll): ?><th>Worker</th><?php endif; ?><th>Work date</th><th>Source</th><th>Method</th><th>Quantity</th><th>Rate</th><th>Amount</th><th>Status</th><th>Period</th><?php if ($manage): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($earnings as $earning): ?>
            <tr>
              <?php if ($viewAll): ?><td><strong><?= $h($earning['display_name']) ?></strong><small><?= $h(ucfirst((string)$earning['relationship_type'])) ?></small></td><?php endif; ?>
              <td><?= $h($earning['source_work_date'] ?: date('Y-m-d', strtotime((string)$earning['created_at']))) ?></td>
              <td><strong><?= $h($earningDescription($earning)) ?></strong><small><?= $h(ucwords(str_replace('_', ' ', (string)$earning['source_type']))) ?></small></td>
              <td><?= $h(ucwords(str_replace('_', ' ', (string)$earning['method']))) ?></td>
              <td><?= $h(number_format((float)$earning['quantity'], 2)) ?></td>
              <td><?= $earning['rate'] === null ? '&mdash;' : $h($earning['currency'] . ' ' . number_format((float)$earning['rate'], 2)) ?></td>
              <td><?= $earning['display_amount'] === null ? '<span class="text-warning">Needs setup</span>' : $h($earning['currency'] . ' ' . number_format((float)$earning['display_amount'], 2)) ?></td>
              <td><span class="status-pill status-pill--<?= $h($earning['status']) ?>"><?= $h($statusLabel((string)$earning['status'])) ?></span></td>
              <td><?= $earning['statement_id'] ? $h($earning['period_start'] . ' – ' . $earning['period_end']) : '&mdash;' ?></td>
              <?php if ($manage): ?><td class="text-right">
                <?php if ((string)$earning['status'] === 'eligible' && $earning['amount'] !== null): ?>
                  <form method="post" action="/?page=workforce/action" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="earning-approve">
                    <input type="hidden" name="earning_id" value="<?= $h($earning['id']) ?>">
                    <button class="btn btn-sm btn-primary" type="submit">Approve earning</button>
                  </form>
                <?php elseif ((string)$earning['status'] === 'needs_setup'): ?>
                  <a class="btn btn-sm" href="/?page=settings&amp;tab=work-types">Resolve setup</a>
                <?php endif; ?>
              </td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$earnings): ?><tr><td colspan="<?= ($viewAll ? 9 : 8) + ($manage ? 1 : 0) ?>" class="workforce-empty">No earnings are visible yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
    </div>

    <div data-workforce-tab-panel="statements" role="tabpanel" hidden>
  <article class="card workforce-card workforce-card--table">
    <div class="card-head">
      <div>
        <h3 class="card-title">Period statements</h3>
        <p class="muted text-sm mb-0">Employees receive pay statements. Contractors receive settlement statements and may attach their own invoice.</p>
      </div>
    </div>
    <div class="pa-table-wrap">
      <table class="pa-table workforce-table">
        <thead><tr><?php if ($viewAll): ?><th>Worker</th><?php endif; ?><th>Period</th><th>Type</th><th>Earnings</th><th>Total</th><th>Status</th><th>Contractor invoice</th><?php if ($manage): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($statements as $statement): ?>
            <tr>
              <?php if ($viewAll): ?><td><strong><?= $h($statement['display_name']) ?></strong><small><?= $h(ucfirst((string)$statement['relationship_type'])) ?></small></td><?php endif; ?>
              <td><?= $h($statement['period_start'] . ' – ' . $statement['period_end']) ?></td>
              <td><?= $h($statement['statement_type'] === 'contractor_settlement' ? 'Contractor settlement' : 'Employee pay') ?></td>
              <td><?= (int)$statement['earning_line_count'] ?><?php if ((int)$statement['line_count'] !== (int)$statement['earning_line_count']): ?><small><?= (int)$statement['line_count'] ?> total lines</small><?php endif; ?></td>
              <td><?= $h($statement['currency'] . ' ' . number_format((float)$statement['total_amount'], 2)) ?></td>
              <td><span class="status-pill status-pill--<?= $h($statement['status']) ?>"><?= $h(ucfirst((string)$statement['status'])) ?></span></td>
              <td>
                <?php if ($statement['statement_type'] === 'contractor_settlement'): ?>
                  <?php if ($statement['contractor_invoice_path']): ?>
                    <a class="btn btn-sm" href="/?page=workforce/contractor-invoice-download&amp;id=<?= (int)$statement['id'] ?>">Download</a>
                  <?php else: ?>
                    <form method="post" enctype="multipart/form-data" action="/?page=workforce/contractor-invoice" class="inline-form">
                      <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
                      <input type="hidden" name="statement_id" value="<?= (int)$statement['id'] ?>">
                      <input class="input input--small" type="file" name="contractor_invoice" accept="application/pdf,.pdf" required>
                      <button class="btn btn-sm" type="submit">Attach</button>
                    </form>
                  <?php endif; ?>
                <?php else: ?>&mdash;<?php endif; ?>
              </td>
              <?php if ($manage): ?>
                <td class="text-right">
                  <?php if ($statement['status'] === 'issued'): ?>
                    <form method="post" action="/?page=workforce/action">
                      <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="statement-settle">
                      <input type="hidden" name="statement_id" value="<?= (int)$statement['id'] ?>">
                      <button class="btn btn-sm" type="submit">Mark settled</button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$statements): ?><tr><td colspan="<?= ($viewAll ? 1 : 0) + ($manage ? 7 : 6) ?>" class="workforce-empty">No period statements yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
    </div>

    <div data-workforce-tab-panel="payments" role="tabpanel" hidden>
      <article class="card workforce-card workforce-card--table">
        <div class="card-head"><div><h3 class="card-title">Worker Payment Records</h3><p class="muted text-sm mb-0">Admin-confirmed facts about what was actually paid. Statements and payment records remain separate.</p></div></div>
        <?php if ($canManagePayments && $tableExists('worker_payment_records') && $paymentStatementsByWorker): ?><details class="workforce-create-panel"><summary class="btn btn-primary">Record worker payment</summary><div class="workforce-payment-forms">
          <?php foreach ($paymentStatementsByWorker as $paymentWorkerId => $workerStatements): ?><?php $paymentWorker = $workerStatements[0]; ?><form class="workforce-form workforce-payment-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="worker-payment-record"><input type="hidden" name="worker_profile_id" value="<?= $paymentWorkerId ?>"><h4><?= $h($paymentWorker['display_name']) ?></h4><div class="workforce-context-grid"><label class="field"><span class="label">Payment date</span><input class="input" type="date" name="payment_date" value="<?= $h(date('Y-m-d')) ?>" required></label><label class="field"><span class="label">Total amount</span><input class="input" type="number" name="amount" min="0.01" step="0.01" required></label><label class="field"><span class="label">Currency</span><input class="input" name="currency" maxlength="3" value="<?= $h($paymentWorker['currency']) ?>" required></label><label class="field"><span class="label">Method</span><input class="input" name="method" maxlength="50" placeholder="ACH, check, cash…" required></label><label class="field"><span class="label">Reference <small>optional</small></span><input class="input" name="reference" maxlength="255"></label><label class="field"><span class="label">Notes <small>optional</small></span><input class="input" name="notes" maxlength="1000"></label></div><fieldset><legend>Statement allocations</legend><div class="workforce-submission-list"><?php foreach ($workerStatements as $statement): ?><label><input type="checkbox" name="statement_ids[]" value="<?= (int)$statement['id'] ?>" data-workforce-payment-statement><span><strong><?= $h($statement['period_start'] . ' – ' . $statement['period_end']) ?></strong><small><?= $h($statement['currency'] . ' ' . number_format((float)$statement['total_amount'], 2)) ?></small><input class="input input--small" type="number" name="allocation_amounts[]" min="0.01" step="0.01" placeholder="Amount" data-workforce-payment-allocation disabled required></span></label><?php endforeach; ?></div></fieldset><button class="btn btn-primary" type="submit">Confirm payment record</button></form><?php endforeach; ?>
        </div></details><?php endif; ?>
        <?php if ($tableExists('worker_payment_records')): ?><div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><?php if ($viewAll): ?><th>Worker</th><?php endif; ?><th>Payment date</th><th>Method</th><th>Reference</th><th>Amount</th><th>Allocated</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($paymentRecords as $payment): ?><tr><?php if ($viewAll): ?><td><strong><?= $h($payment['display_name']) ?></strong><small><?= $h(ucfirst((string)$payment['relationship_type'])) ?></small></td><?php endif; ?><td><?= $h($payment['payment_date']) ?></td><td><?= $h(ucwords(str_replace('_', ' ', (string)$payment['payment_method']))) ?></td><td><?= $h($payment['reference_number'] ?: '—') ?></td><td><?= $h($payment['currency'] . ' ' . number_format((float)$payment['amount'], 2)) ?></td><td><?= $h($payment['currency'] . ' ' . number_format((float)$payment['allocated_amount'], 2)) ?><small><?= (int)$payment['allocation_count'] ?> statement<?= (int)$payment['allocation_count'] === 1 ? '' : 's' ?></small></td><td><span class="status-pill status-pill--<?= $h($payment['status']) ?>"><?= $h(ucfirst((string)$payment['status'])) ?></span><?php if ($canManagePayments && (string)$payment['status'] === 'confirmed'): ?><details class="workforce-entry-edit"><summary class="btn btn-sm btn-danger">Void</summary><form class="workforce-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="worker-payment-void"><input type="hidden" name="payment_record_id" value="<?= $h($payment['id']) ?>"><label class="field"><span class="label">Why is this payment record being voided?</span><textarea class="input" name="reason" rows="3" required></textarea></label><button class="btn btn-danger" type="submit">Void payment record</button></form></details><?php endif; ?></td></tr><?php endforeach; ?>
          <?php if (!$paymentRecords): ?><tr><td colspan="<?= ($viewAll ? 7 : 6) ?>" class="workforce-empty">No confirmed payment records yet.</td></tr><?php endif; ?>
        </tbody></table></div><?php else: ?><p class="workforce-queue-note">Payment records become available after the workforce ledger migration is installed.</p><?php endif; ?>
      </article>
    </div>

    <?php if ($canManagePayrollExports): ?><div data-workforce-tab-panel="exports" role="tabpanel" hidden>
      <article class="card workforce-card">
        <div class="card-head"><div><h3 class="card-title">Payroll Exports</h3><p class="muted text-sm mb-0">Create an audited gross-earnings file for approved payroll software.</p></div></div>
        <?php $exportableEarnings = array_values(array_filter($earnings, static fn(array $earning): bool => in_array((string)$earning['status'], ['approved','included','settled'], true))); if ($tableExists('payroll_exports') && $exportableEarnings): ?><details class="workforce-create-panel"><summary class="btn btn-primary">Generate payroll CSV</summary><form class="workforce-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="payroll-export-generate"><div class="workforce-context-grid"><label class="field"><span class="label">Export key</span><input class="input" name="export_key" maxlength="190" value="payroll-<?= $h(gmdate('Ymd-His')) ?>" required></label><label class="field"><span class="label">Pay period <small>optional</small></span><select class="input" name="pay_period_id"><option value="">Selected earnings</option><?php foreach ($payPeriodsForExport as $period): ?><option value="<?= (int)$period['id'] ?>"><?= $h($period['period_start'] . ' – ' . $period['period_end'] . ' · ' . $period['status']) ?></option><?php endforeach; ?></select></label></div><fieldset><legend>Approved gross earnings</legend><div class="workforce-submission-list"><?php foreach ($exportableEarnings as $earning): ?><label><input type="checkbox" name="earning_ids[]" value="<?= $h($earning['id']) ?>"><span><strong><?= $h($earning['display_name']) ?> · <?= $h($earning['source_work_date']) ?></strong><small><?= $h($earningDescription($earning) . ' · ' . $earning['currency'] . ' ' . number_format((float)$earning['display_amount'], 2)) ?></small></span></label><?php endforeach; ?></div></fieldset><button class="btn btn-primary" type="submit">Generate immutable CSV</button></form></details><?php endif; ?>
        <?php if ($tableExists('payroll_exports')): ?><div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><th>Created</th><th>Period</th><th>File</th><th>Rows</th><th>Gross</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($payrollExports as $export): ?><tr><td><?= $h($export['created_at']) ?></td><td><?= $export['period_start'] ? $h($export['period_start'] . ' – ' . $export['period_end']) : 'Selected earnings' ?></td><td><strong><?= $h($export['file_name']) ?></strong><small>SHA-256 <?= $h(substr((string)$export['content_sha256'], 0, 12)) ?>…</small></td><td><?= (int)$export['row_count'] ?></td><td><?= $h(($export['currency'] ?: '') . ' ' . number_format((float)$export['gross_total'], 2)) ?></td><td><span class="status-pill status-pill--<?= $h($export['status']) ?>"><?= $h(ucfirst((string)$export['status'])) ?></span><?php if ((string)$export['status'] === 'generated'): ?><details class="workforce-entry-edit"><summary class="btn btn-sm btn-danger">Void</summary><form class="workforce-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="payroll-export-void"><input type="hidden" name="export_id" value="<?= $h($export['id']) ?>"><label class="field"><span class="label">Void reason</span><textarea class="input" name="reason" rows="3" required></textarea></label><button class="btn btn-danger" type="submit">Void payroll export</button></form></details><?php endif; ?></td></tr><?php endforeach; ?>
          <?php if (!$payrollExports): ?><tr><td colspan="6" class="workforce-empty">No payroll exports have been generated.</td></tr><?php endif; ?>
        </tbody></table></div><?php else: ?><p class="workforce-queue-note">Payroll exports become available after the workforce ledger migration is installed.</p><?php endif; ?>
        <p class="workforce-queue-note">Exports never mark a worker as paid; confirm the actual payment separately.</p>
      </article>
    </div><?php endif; ?>
  </div>

  <?php if ($legacyRows): ?>
    <details class="card workforce-card workforce-card--table">
      <summary><strong>Legacy pay records (<?= count($legacyRows) ?>)</strong> <span class="muted">Compatibility records without a unified earning</span></summary>
      <div class="pa-table-wrap">
        <table class="pa-table workforce-table">
          <thead><tr><?php if ($viewAll): ?><th>Worker</th><?php endif; ?><th>Work date</th><th>Hours</th><th>Rate</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($legacyRows as $row): ?><tr><?php if ($viewAll): ?><td><strong><?= $h($row['employee_name']) ?></strong></td><?php endif; ?><td><?= $h(date('Y-m-d', strtotime((string)$row['start_time']))) ?></td><td><?= $h($row['hours']) ?></td><td><?= $h($row['currency'] . ' ' . number_format((float)$row['rate'], 2)) ?></td><td><?= $h($row['currency'] . ' ' . number_format((float)$row['amount'], 2)) ?></td><td><span class="status-pill status-pill--<?= $h($row['status']) ?>"><?= $h(ucfirst((string)$row['status'])) ?></span></td></tr><?php endforeach; ?></tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>
</section>
