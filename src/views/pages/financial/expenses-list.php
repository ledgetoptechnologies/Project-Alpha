<?php
// src/views/pages/financial/expenses-list.php
// Expenses: the ledger, recurring schedules, receipts and mileage.

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/acl.php';

$canManageFinance = session_user_can($pdo, 'financial.manage');
$manageOnly = static fn(array $action): array => $canManageFinance ? [$action] : [];

$shellPage = 'financial/expenses-list';
$shellTitle = 'Expenses';
$shellTabs = [
    'expenses' => [
        'label' => 'Expenses',
        'file' => '_expenses_tab.php',
        'actions' => $canManageFinance ? [
            ['Import CSV', '/?page=financial/csv-import'],
            ['+ Add expense', '/?page=financial/expense-create', true],
        ] : [],
    ],
    'recurring' => [
        'label' => 'Recurring',
        'file' => '_recurring_expenses_tab.php',
        'actions' => $manageOnly(['+ Add recurring expense', '/?page=financial/recurring-expense-form', true]),
    ],
    'receipts' => [
        'label' => 'Receipts',
        'file' => '_receipts_tab.php',
        'actions' => $manageOnly(['+ Upload receipt', '/?page=financial/receipt-upload', true]),
    ],
    'mileage' => [
        'label' => 'Mileage',
        'file' => 'mileage-list.php',
        'actions' => array_merge(
            $canManageFinance && !empty($appConfig['mileage_tracking_enabled']) ? [['Track miles', '/?page=financial/mileage-track']] : [],
            [['+ Log trip', '/?page=financial/mileage-create', true]]
        ),
    ],
];

include __DIR__ . '/_tab_shell.php';
