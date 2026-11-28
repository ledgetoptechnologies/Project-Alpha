<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/recurring_expenses.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
[$scopeWhere, $scopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'created_by');

$stmt = $pdo->prepare('
    SELECT r.*,v.name AS vendor_name,ec.name AS category_name,c.name AS client_name,
           (SELECT COUNT(*) FROM expenses e WHERE e.recurring_expense_id=r.id) AS history_count
    FROM recurring_expenses r
    LEFT JOIN vendors v ON v.id=r.vendor_id
    LEFT JOIN expense_categories ec ON ec.id=r.category_id
    LEFT JOIN clients c ON c.id=r.client_id
    WHERE ' . $scopeWhere . '
    ORDER BY FIELD(r.status,"active","paused","ended"),r.next_expense_date IS NULL,r.next_expense_date,r.id
');
$stmt->execute($scopeParams);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeCount = 0;
$annualForecast = 0.0;
$dueSoon = 0;
$soonDate = date('Y-m-d', strtotime('+30 days'));
foreach ($schedules as $schedule) {
    if ((string)$schedule['status'] === 'active') {
        $activeCount++;
        $annualForecast += recurring_expense_annualized_amount($schedule);
        if (!empty($schedule['next_expense_date']) && (string)$schedule['next_expense_date'] <= $soonDate) {
            $dueSoon++;
        }
    }
}
?>

<section class="expense-ledger">
  <?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Recurring expense schedule saved.</div><?php endif; ?>
  <?php if (!empty($_GET['generated_expense_id'])): ?><div class="alert alert-success">The first expense was generated. <a href="/?page=financial/expense-detail&id=<?php echo (int)$_GET['generated_expense_id']; ?>">View expense</a></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>

  <div class="expense-summary">
    <div class="expense-stat"><span>Active schedules</span><strong><?php echo number_format($activeCount); ?></strong><small><?php echo number_format(count($schedules)); ?> total templates</small></div>
    <div class="expense-stat"><span>Annual forecast</span><strong>$<?php echo number_format($annualForecast, 2); ?></strong><small>Normalized active recurring cost</small></div>
    <div class="expense-stat"><span>Due in 30 days</span><strong><?php echo number_format($dueSoon); ?></strong><small>Includes anything currently overdue</small></div>
    <div class="expense-stat"><span>Automation</span><strong>Daily</strong><small>Runs when PA cron is enabled</small></div>
  </div>

  <?php if (!$schedules): ?>
    <div class="finance-empty"><strong>No recurring expenses yet</strong><p class="muted">Add a domain renewal, hosting fee, software subscription, or other predictable cost.</p></div>
  <?php else: ?>
    <div class="pa-table-wrap expense-table-wrap">
      <table class="pa-table expense-table">
        <thead><tr><th>Expense</th><th>Schedule</th><th>Next / Last</th><th class="text-right">Amount</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($schedules as $schedule):
            $status = (string)$schedule['status'];
            $isDue = $status === 'active' && !empty($schedule['next_expense_date']) && (string)$schedule['next_expense_date'] <= date('Y-m-d');
          ?>
            <tr>
              <td><div class="expense-primary"><strong><?php echo htmlspecialchars((string)$schedule['description']); ?></strong><span><?php echo htmlspecialchars($schedule['vendor_name'] ?: 'No vendor'); ?></span><small><?php echo htmlspecialchars($schedule['client_name'] ?: ($schedule['category_name'] ?: 'No client or category')); ?></small></div></td>
              <td><div class="expense-primary"><strong><?php echo htmlspecialchars(recurring_expense_schedule_label($schedule)); ?></strong><span><?php echo empty($schedule['end_date']) ? 'Ongoing' : 'Through ' . date('M j, Y', strtotime((string)$schedule['end_date'])); ?></span><small><?php echo number_format((int)$schedule['history_count']); ?> generated expense<?php echo (int)$schedule['history_count'] === 1 ? '' : 's'; ?></small></div></td>
              <td><div class="expense-primary"><strong><?php echo !empty($schedule['next_expense_date']) ? date('M j, Y', strtotime((string)$schedule['next_expense_date'])) : 'No next date'; ?></strong><span>Last: <?php echo !empty($schedule['last_generated_date']) ? date('M j, Y', strtotime((string)$schedule['last_generated_date'])) : 'Never'; ?></span></div></td>
              <td class="text-right"><div class="expense-amount"><strong>$<?php echo number_format((float)$schedule['amount'], 2); ?></strong><span>$<?php echo number_format(recurring_expense_annualized_amount($schedule), 2); ?>/yr</span></div></td>
              <td><span class="status-pill status-pill--<?php echo htmlspecialchars($status === 'active' ? 'active' : ($status === 'paused' ? 'pending' : 'void')); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
              <td><div class="expense-row-actions" style="flex-wrap:wrap">
                <a href="/?page=financial/recurring-expense-form&id=<?php echo (int)$schedule['id']; ?>" class="btn btn-sm">Edit</a>
                <a href="/?page=financial/expenses-list&tab=expenses&recurring_expense_id=<?php echo (int)$schedule['id']; ?>" class="btn btn-sm">History</a>
                <?php if ($isDue): ?><form method="post" action="/?page=financial/recurring-expense-handler" style="display:inline"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="generate_due"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>"><button class="btn btn-sm btn-primary" type="submit">Generate Due</button></form><?php endif; ?>
                <?php if ($status === 'active'): ?><form method="post" action="/?page=financial/recurring-expense-handler" style="display:inline"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="pause"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>"><button class="btn btn-sm" type="submit">Pause</button></form><?php endif; ?>
                <?php if ($status === 'paused'): ?><form method="post" action="/?page=financial/recurring-expense-handler" style="display:inline"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="resume"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>"><button class="btn btn-sm btn-primary" type="submit">Resume</button></form><?php endif; ?>
                <?php if ($status !== 'ended'): ?><form method="post" action="/?page=financial/recurring-expense-handler" style="display:inline" onsubmit="return confirm('End this recurring expense? Generated history will remain.');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="end"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>"><button class="btn btn-sm" type="submit" style="color:#991b1b">End</button></form><?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
