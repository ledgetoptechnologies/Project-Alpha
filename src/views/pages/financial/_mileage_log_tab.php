<?php
// src/views/pages/financial/_mileage_log_tab.php (Reports > Mileage log)
// Trip-by-trip CSV for tax records; same driver scope as Expenses > Mileage.
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/mileage.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$mileageViewer = mileage_viewer($pdo, $userId);
$mileageDrivers = $mileageViewer['all_drivers'] ? mileage_driver_options($pdo, $userId) : [];
?>
<div class="fin-report">
  <?php if (!$mileageViewer['can_log']): ?>
    <div class="alert alert-danger">Mileage access is not enabled for this account.</div>
  <?php else: ?>
    <form method="get" action="/" class="card fin-form">
      <input type="hidden" name="page" value="financial/mileage-export">
      <div class="fin-form__grid">
        <label class="field"><span class="label-muted">Start date</span><input type="date" name="start" class="input" value="<?php echo date('Y'); ?>-01-01"></label>
        <label class="field"><span class="label-muted">End date</span><input type="date" name="end" class="input" value="<?php echo date('Y'); ?>-12-31"></label>
        <label class="field"><span class="label-muted">Purpose</span>
          <select name="purpose" class="input">
            <option value="">All</option>
            <?php foreach (['business', 'medical', 'moving', 'charitable', 'personal'] as $purposeOption): ?>
              <option value="<?php echo $purposeOption; ?>"<?php echo $purposeOption === 'business' ? ' selected' : ''; ?>><?php echo ucfirst($purposeOption); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($mileageViewer['all_drivers']): ?>
          <label class="field"><span class="label-muted">Driver</span>
            <select name="driver" class="input">
              <option value="mine">Me</option>
              <option value="all">Everyone</option>
              <?php foreach ($mileageDrivers as $driverRow): if ((int)$driverRow['id'] === $userId) continue; ?>
                <option value="<?php echo (int)$driverRow['id']; ?>"><?php echo htmlspecialchars((string)$driverRow['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
      </div>
      <div class="fin-form__actions"><button type="submit" class="btn btn-primary">Export CSV</button></div>
    </form>
  <?php endif; ?>
</div>
