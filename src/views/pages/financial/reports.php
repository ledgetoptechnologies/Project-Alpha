<?php
// src/views/pages/financial/reports.php
// Reports: income audit, expense report, mileage log and every scheduled report email.

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';

$canAudit = session_user_can($pdo, 'financial.audit');
$canManageFinance = session_user_can($pdo, 'financial.manage');

$shellPage = 'financial/reports';
$shellTitle = 'Reports';
$shellTabs = [];
// The income audit has always required financial.audit.
if ($canAudit) {
    $shellTabs['income'] = ['label' => 'Income audit', 'file' => '_income_audit_tab.php'];
}
$shellTabs['expense'] = ['label' => 'Expense report', 'file' => '_expense_report_tab.php'];
$shellTabs['mileage'] = ['label' => 'Mileage log', 'file' => '_mileage_log_tab.php'];
$shellTabs['schedules'] = ['label' => 'Schedules', 'file' => '_report_schedules_tab.php'];

$shellMessages = [];
foreach (['scheduled' => 'Schedule saved.', 'schedule_updated' => 'Schedule updated.', 'schedule_deleted' => 'Schedule deleted.'] as $flag => $text) {
    if (!empty($_GET[$flag])) {
        $shellMessages[] = ['ok', $text];
    }
}
if (!empty($_GET['error'])) {
    $shellMessages[] = ['bad', (string)$_GET['error']];
}

include __DIR__ . '/_tab_shell.php';
