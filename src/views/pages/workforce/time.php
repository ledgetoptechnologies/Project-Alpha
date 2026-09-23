<?php

use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\TimekeepingService;
use App\Modules\Timekeeping\WorkforceSettings;
use App\Services\PayPeriodService;

$userId = (int)($_SESSION['user']['id'] ?? 0);
$service = new TimekeepingService($pdo, new AuditRecorder($pdo));
$manageAll = WorkforceSettings::canManageAllTime($pdo, $userId);
if (!$manageAll && !user_can($pdo, $userId, 'timekeeping.self', 0)) {
    http_response_code(403);
    echo '<p style="padding:24px">You do not have permission to access timekeeping.</p>';
    return;
}

$timeUsers = $manageAll ? $service->usersForManager() : [];
$selectedUserId = $manageAll ? (int)($_GET['user'] ?? $userId) : $userId;
$selectedUser = null;
foreach ($timeUsers as $candidate) {
    if ((int)$candidate['id'] === $selectedUserId) {
        $selectedUser = $candidate;
        break;
    }
}
if ($manageAll && !$selectedUser) {
    $selectedUserId = $userId;
    foreach ($timeUsers as $candidate) {
        if ((int)$candidate['id'] === $selectedUserId) {
            $selectedUser = $candidate;
            break;
        }
    }
}

$selectedRole = (string)($selectedUser['role'] ?? ($_SESSION['user']['role'] ?? 'member'));
$selectedRelationship = (string)($selectedUser['relationship_type'] ?? '');
$selectedCompensationPolicy = (string)($selectedUser['compensation_policy'] ?? '');
$selectedIsOwner = $selectedRelationship === 'owner' && empty($selectedUser['relationship_review_required']);
$projects = $service->projectsFor($selectedUserId, $manageAll);
$jobs = $service->jobsFor($selectedUserId, $manageAll);
$workTypes = $service->workTypes();
$assignments = $service->assignmentsFor($selectedUserId);
$offeredAssignments = $selectedUserId === $userId ? $service->offeredAssignmentsFor($selectedUserId) : [];
$canEditInvoices = $manageAll && user_can($pdo, $userId, 'invoices.edit', 0);
$invoices = $canEditInvoices ? array_values(array_filter(
    $service->invoicesForManager(),
    static fn(array $invoice): bool => can_access_record($pdo, 'invoices', (int)$invoice['id'], $userId)
)) : [];
$running = $service->running($selectedUserId);
$entries = $service->entries($selectedUserId, false, 100);
$selectedWorkerProfile = $service->workerProfileFor($selectedUserId);
$settings = WorkforceSettings::load($pdo);
$timezone = (string)$settings['timezone'];
$today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
$defaultCaptureMode = in_array(($settings['default_capture_mode'] ?? 'duration'), ['duration', 'timer', 'exact'], true)
    ? (string)($settings['default_capture_mode'] ?? 'duration')
    : 'duration';
if ($running && $defaultCaptureMode === 'timer') {
    $defaultCaptureMode = 'duration';
}
$rawDefaultBillingTreatment = (string)($settings['default_billing_treatment'] ?? 'undecided');
$defaultBillingTreatment = [
    'internal' => 'nonbillable',
    'included' => 'included_fixed',
    'hourly' => 'ready',
][$rawDefaultBillingTreatment] ?? $rawDefaultBillingTreatment;
if (!in_array($defaultBillingTreatment, ['undecided', 'nonbillable', 'included_fixed', 'ready'], true)) {
    $defaultBillingTreatment = 'undecided';
}
$billingTreatmentLabels = [
    'undecided' => 'Undecided',
    'nonbillable' => 'Internal / nonbillable',
    'included_fixed' => 'Included in fixed-price work',
    'ready' => 'Ready for hourly billing',
];
$entryBillingLabels = [
    'decide_later' => 'Decide later',
    'internal' => 'Internal / nonbillable',
    'fixed_price_included' => 'Included in fixed-price work',
    'rate_needed' => 'Rate needed',
    'ready' => 'Ready to invoice',
    'partially_invoiced' => 'Partially invoiced',
    'invoiced' => 'Invoiced',
    'reversed' => 'Reversed',
];
$entryCompensationLabels = [
    'owner_no_pay' => 'Owner — no payroll pay',
    'nonpayable' => 'Nonpayable / internal',
    'needs_setup' => 'Needs pay setup',
    'provisional' => 'Provisional',
    'eligible' => 'Eligible',
    'approved' => 'Approved',
    'included' => 'Included on statement',
    'settled' => 'Settled',
    'adjusted' => 'Adjusted',
    'voided' => 'Voided',
];
$defaultPayable = $selectedCompensationPolicy === 'rules'
    || ($selectedCompensationPolicy === '' && $selectedRole === 'employee');
$defaultPaySummary = in_array($selectedCompensationPolicy, ['needs_setup', 'needs_review'], true)
    ? 'Needs pay setup'
    : ($defaultPayable ? 'Provisional' : 'Nonpayable / internal');
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$displayTime = static function (?string $value) use ($timezone): string {
    if (!$value) {
        return '-';
    }
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('M j, Y g:i A');
};
$inputTime = static function (?string $value) use ($timezone): string {
    if (!$value) {
        return '';
    }
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('Y-m-d\TH:i');
};
$invoiceLabel = static function (array $entry): string {
    if (empty($entry['invoice_number'])) {
        return '';
    }
    $prefix = match ((string)($entry['invoice_type'] ?? 'regular')) {
        'long_term' => 'LTI-',
        'on_demand' => 'ODI-',
        default => 'I-',
    };
    return $prefix . $entry['invoice_number'];
};

$hoursTotal = 0.0;
$reviewCount = 0;
$submissionGroups = [];
$periods = new PayPeriodService($pdo);
foreach ($entries as $entry) {
    if (!in_array($entry['status'], ['cancelled', 'voided'], true)) {
        $hoursTotal += ((int)$entry['duration_seconds']) / 3600;
    }
    if (($entry['workflow_status'] ?? '') === 'submitted') {
        $reviewCount++;
    }
    if ($selectedWorkerProfile
        && in_array((string)($entry['workflow_status'] ?? ''), ['draft','returned'], true)
        && !empty($entry['end_time'])
        && (int)$entry['duration_seconds'] > 0) {
        $entryDate = (new DateTimeImmutable((string)$entry['start_time'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timezone));
        $period = $periods->periodFor($entryDate);
        if (($period['status'] ?? '') === 'open') {
            $periodId = (int)$period['id'];
            $submissionGroups[$periodId] ??= ['period' => $period, 'entries' => []];
            $submissionGroups[$periodId]['entries'][] = $entry;
        }
    }
}
?>

<section class="workforce-page" data-workforce-page data-workforce-time-page data-running-start="<?= $h($running['start_time'] ?? '') ?>">
  <div class="workforce-head">
    <div>
      <p class="workforce-eyebrow">Workforce</p>
      <h2>Time</h2>
      <p class="workforce-subtitle">Record the Service and Work Activity completed. PA keeps client billing and worker compensation separate. Entries are stored in UTC.</p>
    </div>
    <div class="workforce-head__actions">
      <a class="btn" href="/?page=workforce/overview">Overview</a>
      <?php if ($manageAll): ?><a class="btn" href="/?page=accounts">Manage worker accounts</a><?php endif; ?>
    </div>
  </div>

  <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= $h($_GET['success']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= $h($_GET['error']) ?></div><?php endif; ?>

  <?php if ($manageAll): ?>
    <form class="workforce-person-switcher" method="get" action="/">
      <input type="hidden" name="page" value="workforce/time">
      <label class="field">
        <span class="label">Enter or review time for</span>
        <select class="input" name="user" onchange="this.form.submit()">
          <?php foreach ($timeUsers as $person): ?>
            <option value="<?= (int)$person['id'] ?>" <?= (int)$person['id'] === $selectedUserId ? 'selected' : '' ?>>
              <?= $h($person['display_name']) ?><?= $person['role'] !== 'employee' ? ' &middot; ' . $h(ucfirst((string)$person['role'])) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  <?php endif; ?>

  <div class="workforce-kpis">
    <article class="workforce-kpi"><span>Recorded hours</span><strong><?= number_format($hoursTotal, 2) ?></strong><small>Most recent 100 entries</small></article>
    <article class="workforce-kpi"><span>Awaiting review</span><strong><?= number_format($reviewCount) ?></strong><small>Submitted entries</small></article>
    <article class="workforce-kpi"><span>Timer</span><strong class="<?= $running ? 'is-running' : '' ?>"><?= $running ? 'Running' : 'Stopped' ?></strong><small id="workforce-timer-display"><?= $running ? '00:00:00' : 'Ready to start' ?></small></article>
  </div>

  <?php if ($offeredAssignments): ?>
    <article class="card workforce-card">
      <div class="card-head"><div><h3 class="card-title">Assignment offers</h3><p class="muted text-sm mb-0">Review the scope and estimated compensation before accepting.</p></div></div>
      <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Job / work</th><th>Expected time</th><th>Estimated compensation</th><th class="text-right">Decision</th></tr></thead><tbody>
        <?php foreach ($offeredAssignments as $offer): ?><tr>
          <td><strong><?= $h($offer['job_code']) ?></strong><small><?= $h($offer['name'] . ' · ' . $offer['work_type_name']) ?></small></td>
          <td><?= $offer['expected_duration_minutes'] !== null ? (int)$offer['expected_duration_minutes'] . ' min' : 'Not estimated' ?></td>
          <td><?= $offer['estimated_pay'] !== null ? '$' . number_format((float)$offer['estimated_pay'], 2) . ' ' . $h($offer['currency']) : 'Nonpayable / internal' ?></td>
          <td class="text-right">
            <form method="post" action="/?page=workforce/action" class="inline-form"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="assignment-accept"><input type="hidden" name="assignment_id" value="<?= (int)$offer['id'] ?>"><button class="btn btn-sm btn-primary">Accept</button></form>
            <form method="post" action="/?page=workforce/action" class="inline-form"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="assignment-decline"><input type="hidden" name="assignment_id" value="<?= (int)$offer['id'] ?>"><input class="input input--small" name="reason" placeholder="Reason" required><button class="btn btn-sm">Decline</button></form>
          </td>
        </tr><?php endforeach; ?>
      </tbody></table></div>
    </article>
  <?php endif; ?>

  <?php if ($selectedUserId === $userId && $assignments): ?>
    <article class="card workforce-card">
      <div class="card-head"><h3 class="card-title">My active assignments</h3></div>
      <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Job / work</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody>
        <?php foreach ($assignments as $assignment): ?><tr>
          <td><strong><?= $h($assignment['job_code']) ?></strong><small><?= $h($assignment['name'] . ' · ' . $assignment['work_type_name']) ?></small></td>
          <td><?= $h(ucfirst($assignment['status'])) ?></td>
          <td class="text-right"><form method="post" action="/?page=workforce/action" class="inline-form"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><input type="hidden" name="action" value="<?= $assignment['status'] === 'accepted' ? 'assignment-start' : 'assignment-complete' ?>"><button class="btn btn-sm"><?= $assignment['status'] === 'accepted' ? 'Start work' : 'Mark completed' ?></button></form></td>
        </tr><?php endforeach; ?>
      </tbody></table></div>
    </article>
  <?php endif; ?>

  <article class="card workforce-card workforce-record-card" data-workforce-record-card>
    <div class="card-head">
      <div><h3 class="card-title">Record time</h3><p class="muted text-sm mb-0">Choose how to capture time. Client billing and worker compensation remain separate decisions.</p></div>
    </div>

    <?php if ($running): ?>
      <div class="workforce-running" data-workforce-running-timer>
        <strong><?= $h($running['project_name'] ?: 'General time') ?></strong>
        <?php if ($manageAll && ($running['client_name'] || $running['invoice_number'])): ?><span><?= $h($running['client_name'] ?: '') ?><?= $running['invoice_number'] ? ' &middot; ' . $h($invoiceLabel($running)) : '' ?></span><?php endif; ?>
        <p><?= $h($running['description']) ?></p>
        <small>Timer started <?= $h($displayTime($running['start_time'])) ?></small>
        <div class="workforce-actions">
          <form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="clock-out"><input type="hidden" name="entry_id" value="<?= $h($running['id']) ?>"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><button class="btn btn-primary">Stop timer</button></form>
          <?php if ($running['open_break_id']): ?>
            <form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="break-end"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><input type="hidden" name="break_id" value="<?= $h($running['open_break_id']) ?>"><button class="btn">End break</button></form>
          <?php else: ?>
            <form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="break-start"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><input type="hidden" name="entry_id" value="<?= $h($running['id']) ?>"><button class="btn">Start break</button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="workforce-capture-modes" role="radiogroup" aria-label="Time capture mode">
      <label><input type="radio" name="workforce_capture_mode" value="duration" <?= $defaultCaptureMode === 'duration' ? 'checked' : '' ?> data-workforce-capture-mode> <span>Add duration<small>Completed time</small></span></label>
      <label><input type="radio" name="workforce_capture_mode" value="timer" <?= $defaultCaptureMode === 'timer' ? 'checked' : '' ?> data-workforce-capture-mode <?= $running ? 'disabled' : '' ?>> <span>Start timer<small><?= $running ? 'Already running' : 'Track from now' ?></small></span></label>
      <label><input type="radio" name="workforce_capture_mode" value="exact" <?= $defaultCaptureMode === 'exact' ? 'checked' : '' ?> data-workforce-capture-mode> <span>Exact times<small>Start and end</small></span></label>
    </div>

    <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form data-workforce-record-form>
      <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $defaultCaptureMode === 'timer' ? 'clock-in' : 'manual-create' ?>" data-workforce-action>
      <input type="hidden" name="capture_mode" value="<?= $h($defaultCaptureMode) ?>" data-workforce-capture-mode-value>
      <input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>">
      <input type="hidden" name="start_time" data-workforce-start-time>
      <input type="hidden" name="end_time" data-workforce-end-time>

      <section class="workforce-capture-panel" data-workforce-capture-panel="duration" <?= $defaultCaptureMode !== 'duration' ? 'hidden' : '' ?>>
        <div class="workforce-context-grid">
          <label class="field"><span class="label">Work date</span><input class="input" type="date" name="work_date" value="<?= $h($today) ?>" data-workforce-work-date></label>
          <label class="field"><span class="label">Hours</span><input class="input" type="number" min="0" max="24" step="1" value="0" inputmode="numeric" data-workforce-duration-hours></label>
          <label class="field"><span class="label">Minutes</span><input class="input" type="number" min="0" max="59" step="1" value="0" inputmode="numeric" data-workforce-duration-minute-part></label>
          <input type="hidden" name="duration_minutes" value="0" data-workforce-duration-minutes>
          <label class="field"><span class="label">Start time <small>optional</small></span><input class="input" type="time" name="duration_start_time" data-workforce-duration-start></label>
        </div>
        <p class="muted text-sm mb-0">Without a start time, the entry begins at midnight on the selected work date.</p>
      </section>
      <section class="workforce-capture-panel" data-workforce-capture-panel="timer" <?= $defaultCaptureMode !== 'timer' ? 'hidden' : '' ?>><p class="workforce-mode-note">The timer starts when you submit this form. You can stop it or record breaks from this page.</p></section>
      <section class="workforce-capture-panel" data-workforce-capture-panel="exact" <?= $defaultCaptureMode !== 'exact' ? 'hidden' : '' ?>>
        <div class="workforce-context-grid workforce-context-grid--time">
          <label class="field"><span class="label">Start</span><input class="input" type="datetime-local" data-workforce-exact-start></label>
          <label class="field"><span class="label">End</span><input class="input" type="datetime-local" data-workforce-exact-end></label>
        </div>
      </section>

      <fieldset class="workforce-form-section workforce-form-section--primary">
        <legend>What did you do?</legend>
        <div class="workforce-context-grid">
          <label class="field workforce-search-select" data-workforce-search-select><span class="label">Work Activity <small>optional</small></span><input class="input input--filter" type="search" placeholder="Search Work Activities" autocomplete="off" data-workforce-option-filter><select class="input" name="work_type_id" data-workforce-work-type><option value="">Unclassified</option><?php foreach ($workTypes as $type): ?><option value="<?= (int)$type['id'] ?>" data-billing-treatment="<?= $h($type['billing_treatment']) ?>" data-billing-rate="<?= $h($type['default_billing_rate'] ?? '') ?>" data-billing-currency="<?= $h($type['billing_currency'] ?? 'USD') ?>"><?= $h($type['name']) ?></option><?php endforeach; ?></select><small data-workforce-work-type-guidance>Activities classify the work. Owners define billing and compensation separately.</small></label>
          <label class="field"><span class="label">Description <small>optional unless required by settings</small></span><textarea class="input" name="description" rows="2" placeholder="What work was completed?"></textarea></label>
        </div>
        <p class="alert alert-warning workforce-unclassified-warning" data-workforce-unclassified-warning>Unclassified time is allowed and remains in Work Review until an owner or manager adds the missing context.</p>
      </fieldset>

      <fieldset class="workforce-form-section">
        <legend>Job context <small>optional while recording</small></legend>
        <div class="workforce-context-grid">
          <?php if ($manageAll): ?>
            <label class="field workforce-combobox" data-workforce-client-combobox><span class="label">Client</span><input class="input" type="search" autocomplete="off" placeholder="Type to search clients" data-workforce-client-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="client_id" data-workforce-client><span class="workforce-combobox__results" data-workforce-client-results role="listbox" hidden></span></label>
          <?php endif; ?>
          <label class="field workforce-search-select" data-workforce-search-select><span class="label"><?= $manageAll ? 'Project' : 'Assigned project' ?></span><input class="input input--filter" type="search" placeholder="Search projects" autocomplete="off" data-workforce-option-filter><select class="input" name="project_id" data-workforce-project><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" data-client-id="<?= (int)($project['client_id'] ?? 0) ?>" data-client-name="<?= $h($project['client_name'] ?? '') ?>"><?= $h($project['name']) ?><?= !empty($project['client_name']) ? ' · ' . $h($project['client_name']) : '' ?></option><?php endforeach; ?></select></label>
          <label class="field workforce-search-select" data-workforce-search-select><span class="label">Job <small>optional</small></span><input class="input input--filter" type="search" placeholder="Search Jobs" autocomplete="off" data-workforce-option-filter><select class="input" name="job_id" data-workforce-job><option value="">No Job — keep unclassified</option><?php foreach ($jobs as $job): ?><?php $jobClientName = trim((string)($job['client_name'] ?? '')); ?><option value="<?= (int)$job['id'] ?>" data-client-id="<?= (int)($job['client_id'] ?? 0) ?>" data-client-name="<?= $h($jobClientName) ?>" data-project-id="<?= (int)($job['project_id'] ?? 0) ?>" data-service-id="<?= (int)($job['service_item_id'] ?? 0) ?>"><?= $h($job['job_code'] . ($jobClientName !== '' ? ' · ' . $jobClientName : '')) ?></option><?php endforeach; ?></select><small>Billable time needs a Job before it can be added to an invoice.</small></label>
          <?php if ($manageAll): ?>
            <label class="field workforce-search-select" data-workforce-search-select><span class="label">Draft invoice <small>optional</small></span><input class="input input--filter" type="search" placeholder="Search draft invoices" autocomplete="off" data-workforce-option-filter><select class="input" name="invoice_id" data-workforce-invoice><option value="">Add to an invoice later</option><?php foreach ($invoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>"><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select><small>If selected, confirmed billable time is added to this mutable draft invoice. Finalized invoices are never offered.</small></label>
          <?php endif; ?>
          <label class="field workforce-search-select" data-workforce-search-select><span class="label">Accepted assignment</span><input class="input input--filter" type="search" placeholder="Search assignments" autocomplete="off" data-workforce-option-filter><select class="input" name="work_assignment_id"><option value="">No assignment</option><?php foreach ($assignments as $assignment): ?><option value="<?= (int)$assignment['id'] ?>" data-job-id="<?= (int)$assignment['job_id'] ?>" data-work-type-id="<?= (int)$assignment['work_type_id'] ?>"><?= $h($assignment['job_code'] . ' · ' . $assignment['name']) ?></option><?php endforeach; ?></select></label>
        </div>
      </fieldset>

      <?php if ($manageAll): ?><div class="workforce-outcome-grid">
        <fieldset class="workforce-outcome workforce-outcome--billing">
          <legend>Client billing</legend>
            <label class="field"><span class="label">Billing treatment</span><select class="input" name="billing_treatment" data-workforce-billing-treatment data-default-treatment="<?= $h($defaultBillingTreatment) ?>"><?php foreach ($billingTreatmentLabels as $value => $label): ?><option value="<?= $h($value) ?>" <?= $value === $defaultBillingTreatment ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label>
            <input type="hidden" name="billable" value="<?= $defaultBillingTreatment === 'ready' ? '1' : '0' ?>" data-workforce-billable>
            <p class="workforce-outcome__summary"><strong data-workforce-billing-summary><?= $h($billingTreatmentLabels[$defaultBillingTreatment]) ?></strong><span>Invoice linking happens after time is confirmed.</span></p>
        </fieldset>
        <fieldset class="workforce-outcome workforce-outcome--compensation">
          <legend>Worker compensation</legend>
          <?php if (!in_array($selectedCompensationPolicy, ['nonpayable', 'owner_no_pay'], true)): ?>
            <label class="workforce-outcome__choice"><input type="checkbox" name="is_payable" value="1" <?= $defaultPayable ? 'checked' : '' ?> data-workforce-payable> <span>Eligible for worker compensation<small>Final eligibility is determined after time approval.</small></span></label>
            <p class="workforce-outcome__summary"><strong data-workforce-pay-summary><?= $h($defaultPaySummary) ?></strong><span>Compensation remains separate from client billing.</span></p>
          <?php elseif (in_array($selectedCompensationPolicy, ['nonpayable', 'owner_no_pay'], true)): ?>
            <input type="hidden" name="is_payable" value="0"><p class="workforce-outcome__summary"><strong>Nonpayable by worker policy</strong><span>Time may still be available for client billing.</span></p>
          <?php else: ?>
            <p class="workforce-outcome__summary"><strong>Based on worker pay setup</strong><span>Approval confirms time; compensation rules are applied separately.</span></p>
          <?php endif; ?>
        </fieldset>
      </div><?php else: ?><input type="hidden" name="billing_treatment" value="<?= $h($defaultBillingTreatment) ?>" data-workforce-billing-treatment data-default-treatment="<?= $h($defaultBillingTreatment) ?>"><input type="hidden" name="billable" value="<?= $defaultBillingTreatment === 'ready' ? '1' : '0' ?>" data-workforce-billable><?php endif; ?>

      <div class="workforce-record-submit"><button class="btn btn-primary" data-workforce-submit-label>Record time</button><small>Workers record work only. Billing and pay rules are owner-controlled.</small></div>
    </form>
  </article>

  <?php if ($submissionGroups): ?>
    <article class="card workforce-card workforce-card--submit">
      <div class="card-head"><div><h3 class="card-title">Submit time for review</h3><p class="muted text-sm mb-0">Draft time stays editable and out of the approval queue until you submit it. Returned entries can be corrected and submitted again.</p></div></div>
      <?php foreach ($submissionGroups as $group): ?>
        <form method="post" action="/?page=workforce/action" class="workforce-submission-form">
          <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
          <input type="hidden" name="action" value="submit-period">
          <input type="hidden" name="worker_profile_id" value="<?= (int)$selectedWorkerProfile['id'] ?>">
          <input type="hidden" name="pay_period_id" value="<?= (int)$group['period']['id'] ?>">
          <div class="workforce-submission-form__head">
            <div><strong><?= $h($group['period']['period_start']) ?> &ndash; <?= $h($group['period']['period_end']) ?></strong><small><?= count($group['entries']) ?> draft entr<?= count($group['entries']) === 1 ? 'y' : 'ies' ?></small></div>
            <button class="btn btn-primary btn-sm">Submit selected</button>
          </div>
          <div class="workforce-submission-list">
            <?php foreach ($group['entries'] as $entry): ?>
              <label>
                <input type="checkbox" name="entry_ids[]" value="<?= $h($entry['id']) ?>" checked>
                <span><strong><?= $h($displayTime($entry['start_time'])) ?> &middot; <?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> h</strong><small><?= $h($entry['job_code'] ?: ($entry['project_name'] ?: ($entry['description'] ?: 'General work'))) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
          <label class="field"><span class="label">Submission note <small>optional</small></span><input class="input" name="notes" maxlength="1000" placeholder="Anything the reviewer should know?"></label>
        </form>
      <?php endforeach; ?>
    </article>
  <?php endif; ?>

  <article class="card workforce-card workforce-card--table">
    <div class="card-head"><div><h3 class="card-title">Time entries</h3><p class="muted text-sm mb-0">Client billing and worker compensation are tracked independently.</p></div></div>
    <div class="pa-table-wrap">
      <table class="pa-table workforce-table">
        <thead><tr><th>Date</th><th>Job / work</th><th>Duration</th><th>Time status</th><th>Client billing</th><th>Worker compensation</th><th>Description</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
          <?php
            $projectionBilled = !empty($entry['billing_projection_billed']) || !empty($entry['billing_invoice_item_id']);
            $canonicalWorkflowState = (string)($entry['workflow_status'] ?? '');
            $canEditEntry = !$projectionBilled
                && in_array($canonicalWorkflowState, ['draft','returned','submitted'], true);
            $editActionLabel = $canonicalWorkflowState === 'submitted' ? 'Withdraw and edit' : 'Edit';
            $canonicalBillingState = (string)($entry['billing_state'] ?? '');
            $timeIsConfirmed = $canonicalWorkflowState === 'confirmed' && (string)$entry['status'] === 'approved';
            $billingState = $projectionBilled
                ? 'Invoiced'
                : (!$timeIsConfirmed && !empty($entry['billable'])
                    ? 'Available after confirmation'
                    : ($entryBillingLabels[$canonicalBillingState] ?? (!empty($entry['billable'])
                        ? 'Ready to invoice'
                        : 'Internal / undecided')));
            $canonicalCompensationState = (string)($entry['compensation_state'] ?? '');
            $compensationState = $entryCompensationLabels[$canonicalCompensationState] ?? (!empty($entry['is_payable'])
                ? ((string)$entry['status'] === 'approved' ? 'Eligible' : 'Awaiting approval')
                : 'Nonpayable / internal');
            $timeState = (string)($entry['workflow_status'] ?? $entry['status']);
            $availableInvoices = array_values(array_filter(
                $invoices,
                static fn(array $invoice): bool => !empty($invoice['job_id'])
                    && (empty($entry['client_id']) || (int)$invoice['client_id'] === (int)$entry['client_id'])
                    && (empty($entry['job_id']) || (int)$invoice['job_id'] === (int)$entry['job_id'])
            ));
          ?>
          <tr>
            <td><?= $h($displayTime($entry['start_time'])) ?></td>
            <td><strong><?= $h($entry['job_code'] ?: ($entry['project_name'] ?: 'No Job')) ?></strong><?php if ($entry['work_type_name']): ?><small><?= $h($entry['work_type_name']) ?></small><?php endif; ?></td>
            <td><?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> h</td>
            <td><span class="status-pill status-pill--<?= $h($timeState) ?>"><?= $h(ucfirst($timeState)) ?></span><?php if ($entry['rejection_reason']): ?><small class="workforce-reason"><?= $h($entry['rejection_reason']) ?></small><?php endif; ?></td>
            <td><strong><?= $h($billingState) ?></strong><?php if ($manageAll): ?><small><?= $h($entry['client_name'] ?: 'No client') ?><?= $entry['invoice_number'] ? ' · ' . $h($invoiceLabel($entry)) : '' ?></small><?php endif; ?></td>
            <td><strong><?= $h($compensationState) ?></strong></td>
            <td><?= $h($entry['description']) ?></td>
            <td class="text-right">
              <?php if ($canEditEntry): ?>
                <details class="workforce-entry-edit">
                  <summary class="btn btn-sm"><?= $h($editActionLabel) ?></summary>
                  <?php if ($canonicalWorkflowState === 'submitted'): ?>
                  <div class="alert" style="margin:10px 0 6px;padding:10px 12px;border:1px solid #fde68a;background:#fffbeb;border-radius:8px;font-size:13px">
                    <strong>Withdraw &amp; edit:</strong> Saving pulls this entry out of the review queue and returns it to draft. Make your changes, then re-submit it for review when it&rsquo;s ready.
                  </div>
                  <?php endif; ?>
                  <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
                    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><input type="hidden" name="work_assignment_id" value="<?= (int)($entry['work_assignment_id'] ?? 0) ?>" data-job-id="<?= (int)($entry['job_id'] ?? 0) ?>">
                    <?php if ($manageAll): ?>
                      <div class="workforce-context-grid">
                        <label class="field workforce-combobox" data-workforce-client-combobox><span class="label">Client <small>optional</small></span><input class="input" type="search" value="<?= $h($entry['client_name'] ?? '') ?>" data-selected-name="<?= $h($entry['client_name'] ?? '') ?>" autocomplete="off" placeholder="Type to search clients" data-workforce-client-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="client_id" value="<?= (int)($entry['client_id'] ?? 0) ?>" data-workforce-client><span class="workforce-combobox__results" data-workforce-client-results role="listbox" hidden></span></label>
                        <label class="field"><span class="label">Project</span><select class="input" name="project_id" data-workforce-project><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" data-client-id="<?= (int)($project['client_id'] ?? 0) ?>" data-client-name="<?= $h($project['client_name'] ?? '') ?>" <?= (int)$entry['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span class="label">Draft invoice <small>optional</small></span><select class="input" name="invoice_id" data-workforce-invoice><option value="">Add to an invoice later</option><?php foreach ($availableInvoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>" <?= (int)($entry['invoice_id'] ?? 0) === (int)$invoice['id'] ? 'selected' : '' ?>><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select></label>
                      </div>
                    <?php else: ?>
                      <label class="field"><span class="label">Assigned project</span><select class="input" name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= (int)$entry['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                    <div class="workforce-context-grid">
                      <label class="field"><span class="label">Job</span><select class="input" name="job_id"><option value="">No Job</option><?php foreach ($jobs as $job): ?><option value="<?= (int)$job['id'] ?>" data-client-id="<?= (int)$job['client_id'] ?>" data-client-name="<?= $h($job['client_name']) ?>" data-project-id="<?= (int)($job['project_id'] ?? 0) ?>" <?= (int)$entry['job_id'] === (int)$job['id'] ? 'selected' : '' ?>><?= $h($job['job_code'] . ' · ' . $job['client_name']) ?></option><?php endforeach; ?></select></label>
                      <label class="field"><span class="label">Work Activity</span><select class="input" name="work_type_id"><option value="">Unclassified</option><?php foreach ($workTypes as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (int)$entry['work_type_id'] === (int)$type['id'] ? 'selected' : '' ?>><?= $h($type['name']) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <div class="workforce-context-grid workforce-context-grid--time"><label class="field"><span class="label">Start</span><input class="input" type="datetime-local" name="start_time" value="<?= $h($inputTime($entry['start_time'])) ?>" required></label><label class="field"><span class="label">End</span><input class="input" type="datetime-local" name="end_time" value="<?= $h($inputTime($entry['end_time'])) ?>" required></label></div>
                    <label class="field"><span class="label">Description</span><textarea class="input" name="description" rows="2"><?= $h($entry['description']) ?></textarea></label>
                    <?php if ($manageAll): ?>
                      <div class="workforce-outcome-grid workforce-outcome-grid--compact">
                        <fieldset class="workforce-outcome workforce-outcome--billing"><legend>Client billing</legend><input type="hidden" name="billable" value="0"><label class="workforce-outcome__choice"><input type="checkbox" name="billable" value="1" <?= $entry['billable'] ? 'checked' : '' ?>> <span>Available for client billing</span></label></fieldset>
                        <fieldset class="workforce-outcome workforce-outcome--compensation"><legend>Worker compensation</legend><input type="hidden" name="is_payable" value="0"><label class="workforce-outcome__choice"><input type="checkbox" name="is_payable" value="1" <?= $entry['is_payable'] ? 'checked' : '' ?>> <span>Eligible for compensation</span></label></fieldset>
                      </div>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-sm"><?= $canonicalWorkflowState === 'submitted' ? 'Withdraw and save draft' : 'Save changes' ?></button>
                  </form>
                </details>
              <?php endif; ?>
              <?php if ($canonicalWorkflowState === 'confirmed' && (string)$entry['status'] === 'approved'): ?>
                <details class="workforce-entry-edit">
                  <summary class="btn btn-sm"><?= $manageAll ? 'Edit confirmed time' : 'Request correction' ?></summary>
                  <?php if ($manageAll): ?>
                  <div class="alert" style="margin:10px 0 6px;padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;font-size:13px">
                    <strong>Audited edit:</strong> Confirmed time is immutable. This creates a new audited revision &mdash; existing approval, billing, and pay history is preserved. A reason is required for the audit trail.
                  </div>
                  <?php endif; ?>
                  <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
                    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $manageAll ? 'admin-correction-apply' : 'correction-request' ?>"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><?php if ($manageAll): ?><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><?php endif; ?>
                    <div class="workforce-context-grid workforce-context-grid--time"><label class="field"><span class="label">Corrected start</span><input class="input" type="datetime-local" name="start_time" value="<?= $h($inputTime($entry['start_time'])) ?>" required></label><label class="field"><span class="label">Corrected end</span><input class="input" type="datetime-local" name="end_time" value="<?= $h($inputTime($entry['end_time'])) ?>" required></label></div>
                    <label class="field"><span class="label">Work Activity</span><select class="input" name="work_type_id"><option value="">Unclassified</option><?php foreach ($workTypes as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (int)$entry['work_type_id'] === (int)$type['id'] ? 'selected' : '' ?>><?= $h($type['name']) ?></option><?php endforeach; ?></select></label>
                    <?php if ($manageAll): ?><div class="workforce-context-grid">
                      <label class="field workforce-combobox" data-workforce-client-combobox><span class="label">Client</span><input class="input" type="search" value="<?= $h($entry['client_name'] ?? '') ?>" data-selected-name="<?= $h($entry['client_name'] ?? '') ?>" autocomplete="off" placeholder="Type to search clients" data-workforce-client-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="client_id" value="<?= (int)($entry['client_id'] ?? 0) ?>" data-workforce-client><span class="workforce-combobox__results" data-workforce-client-results role="listbox" hidden></span></label>
                      <label class="field"><span class="label">Project</span><select class="input" name="project_id" data-workforce-project><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" data-client-id="<?= (int)($project['client_id'] ?? 0) ?>" data-client-name="<?= $h($project['client_name'] ?? '') ?>" <?= (int)$entry['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
                      <label class="field"><span class="label">Job</span><select class="input" name="job_id" data-workforce-job><option value="">No Job</option><?php foreach ($jobs as $job): ?><option value="<?= (int)$job['id'] ?>" data-client-id="<?= (int)$job['client_id'] ?>" data-client-name="<?= $h($job['client_name']) ?>" data-project-id="<?= (int)($job['project_id'] ?? 0) ?>" <?= (int)$entry['job_id'] === (int)$job['id'] ? 'selected' : '' ?>><?= $h($job['job_code'] . ' · ' . $job['client_name']) ?></option><?php endforeach; ?></select></label>
                      <?php
                        $currentInvoiceId = (int)($entry['invoice_id'] ?? 0);
                        $currentInvoiceIsListed = $currentInvoiceId > 0 && (bool)array_filter(
                            $availableInvoices,
                            static fn(array $invoice): bool => (int)$invoice['id'] === $currentInvoiceId
                        );
                      ?>
                      <label class="field"><span class="label">Invoice destination</span><select class="input" name="invoice_id" data-workforce-invoice><option value="">No draft invoice</option><?php if ($currentInvoiceId > 0 && !$currentInvoiceIsListed): ?><option value="<?= $currentInvoiceId ?>" selected><?= $h($invoiceLabel($entry)) ?> · locked/current</option><?php endif; ?><?php foreach ($availableInvoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>" <?= $currentInvoiceId === (int)$invoice['id'] ? 'selected' : '' ?>><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select><small>Moving time is limited to mutable draft invoices. Changes affecting a finalized invoice require billing resolution.</small></label>
                    </div>
                    <div class="workforce-outcome-grid workforce-outcome-grid--compact">
                      <fieldset class="workforce-outcome workforce-outcome--billing"><legend>Client billing</legend><input type="hidden" name="billable" value="0"><label class="workforce-outcome__choice"><input type="checkbox" name="billable" value="1" <?= !empty($entry['billable']) ? 'checked' : '' ?>> <span>Available for client billing</span></label></fieldset>
                      <fieldset class="workforce-outcome workforce-outcome--compensation"><legend>Worker compensation</legend><input type="hidden" name="is_payable" value="0"><label class="workforce-outcome__choice"><input type="checkbox" name="is_payable" value="1" <?= !empty($entry['is_payable']) ? 'checked' : '' ?>> <span>Eligible for compensation</span></label></fieldset>
                    </div><?php endif; ?>
                    <label class="field"><span class="label">Corrected description</span><textarea class="input" name="description" rows="2"><?= $h($entry['description']) ?></textarea></label>
                    <label class="field"><span class="label">Reason for correction <small>required for audit trail</small></span><textarea class="input" name="reason" rows="2" maxlength="1000" required placeholder="e.g. Wrong end time recorded, correcting from 3:30 PM to 4:00 PM"></textarea></label>
                    <?php if (!$manageAll): ?><p class="muted text-sm mb-0">This does not overwrite confirmed history. An authorized reviewer approves or rejects the proposed revision.</p><?php endif; ?>
                    <button class="btn btn-primary btn-sm" type="submit"><?= $manageAll ? 'Save audited edit' : 'Submit correction request' ?></button>
                  </form>
                </details>
              <?php endif; ?>
              <?php if ($manageAll && (string)$entry['status'] === 'approved' && !empty($entry['billable']) && !$projectionBilled && $availableInvoices): ?>
                <details class="workforce-entry-edit"><summary class="btn btn-sm btn-primary">Add to invoice</summary>
                  <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
                    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="link-invoice"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>">
                    <label class="field"><span class="label">Invoice</span><select class="input" name="invoice_id" data-workforce-invoice required><option value="">Choose an invoice</option><?php foreach ($availableInvoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>"><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span class="label">Hourly billing rate <small>only needed when PA cannot infer it</small></span><input class="input" type="number" name="billing_rate" min="0.01" step="0.01" value="<?= (float)($entry['billing_rate'] ?? 0) > 0 ? $h((string)$entry['billing_rate']) : '' ?>" placeholder="Use saved or invoice rate"></label>
                    <p class="muted text-sm">PA will add a visible tracked-time line and update this entry's client, Project, Job, and invoice automatically.</p>
                    <button class="btn btn-primary btn-sm">Add time to invoice</button>
                  </form>
                </details>
              <?php elseif ($projectionBilled): ?><span class="status-pill status-pill--approved">Invoiced</span><?php endif; ?>
              <?php if (in_array($canonicalWorkflowState, ['draft','returned'], true)): ?>
                <form method="post" action="/?page=workforce/action" onsubmit="return confirm('Cancel this time entry?');"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><button class="btn btn-sm">Cancel</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?><tr><td colspan="8" class="workforce-empty">No time entries yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
