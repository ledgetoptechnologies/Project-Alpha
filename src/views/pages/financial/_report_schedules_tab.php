<?php
// src/views/pages/financial/_report_schedules_tab.php (Reports > Schedules)
// Every scheduled report email, income audit and expense report, in one list.
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$scheduleOrgId = request_client_org_id();
$canAudit = $canAudit ?? session_user_can($pdo, 'financial.audit');
$canManageFinance = $canManageFinance ?? session_user_can($pdo, 'financial.manage');
// Pausing or deleting follows the same rule as creating: audit needs financial.audit, expense needs financial.manage.
$canChangeSchedule = static fn(string $type): bool => $type === 'expense' ? $canManageFinance : $canAudit;

$scheduleStmt = $pdo->prepare(
    'SELECT * FROM audit_schedules WHERE (?=0 OR organization_id=?)' . ($canAudit ? '' : " AND report_type='expense'") .
    " ORDER BY is_active DESC, report_type, next_run_at"
);
$scheduleStmt->execute([$scheduleOrgId, $scheduleOrgId]);
$reportSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

$scheduleReportLabel = static function (array $schedule): string {
    if (($schedule['report_type'] ?? 'audit') === 'expense') {
        return 'Expense report';
    }
    return 'Income audit (' . (($schedule['accounting_basis'] ?? 'cash') === 'accrual' ? 'accrual' : 'cash') . ')';
};
?>
<div class="fin-report">
  <?php if (!$reportSchedules): ?>
    <div class="finance-empty"><strong>No scheduled reports</strong></div>
  <?php else: ?>
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead><tr><th>Report</th><th>Every</th><th>Period</th><th>Sent to</th><th>Next</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($reportSchedules as $schedule):
            $scheduleType = (string)($schedule['report_type'] ?? 'audit');
            $scheduleEmails = json_decode((string)$schedule['email_addresses'], true);
          ?>
            <tr>
              <td><?php echo htmlspecialchars($scheduleReportLabel($schedule)); ?></td>
              <td><?php echo htmlspecialchars(ucfirst((string)$schedule['frequency'])); ?></td>
              <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$schedule['date_range_type']))); ?></td>
              <td><?php echo htmlspecialchars(is_array($scheduleEmails) ? implode(', ', $scheduleEmails) : ''); ?></td>
              <td><?php echo !empty($schedule['next_run_at']) ? htmlspecialchars(date('M j, Y', strtotime((string)$schedule['next_run_at']))) : 'Not scheduled'; ?></td>
              <td><span class="status-pill <?php echo !empty($schedule['is_active']) ? 'status-pill--active' : 'status-pill--paused'; ?>"><?php echo !empty($schedule['is_active']) ? 'Active' : 'Paused'; ?></span></td>
              <td>
                <?php if ($canChangeSchedule($scheduleType)): ?>
                  <div class="doc-actions">
                    <form method="post" action="/?page=financial/audit-schedule-handler">
                      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="report_type" value="<?php echo htmlspecialchars($scheduleType); ?>"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>">
                      <button type="submit" class="btn btn-sm"><?php echo !empty($schedule['is_active']) ? 'Pause' : 'Resume'; ?></button>
                    </form>
                    <form method="post" action="/?page=financial/audit-schedule-handler" onsubmit="return confirm('Delete this schedule?')">
                      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="report_type" value="<?php echo htmlspecialchars($scheduleType); ?>"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
