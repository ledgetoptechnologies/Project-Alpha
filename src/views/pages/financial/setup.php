<?php
// src/views/pages/financial/setup.php
// Financial setup: vendors, expense categories and mileage rates.

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';

$canManageFinance = session_user_can($pdo, 'financial.manage');

$shellPage = 'financial/setup';
$shellTitle = 'Financial setup';
$shellTabs = [
    'vendors' => [
        'label' => 'Vendors',
        'file' => '_vendors_tab.php',
        'actions' => $canManageFinance ? [['+ Add vendor', '/?page=financial/vendor-form', true]] : [],
    ],
    'categories' => [
        'label' => 'Categories',
        'file' => '_categories_tab.php',
        'actions' => $canManageFinance ? [['+ Add category', null, true, 'onclick' => 'createCategory()']] : [],
    ],
];
// Mileage setup has always required financial.manage.
if ($canManageFinance) {
    $shellTabs['rates'] = ['label' => 'Mileage rates', 'file' => '_mileage_rates_tab.php', 'actions' => []];
}

include __DIR__ . '/_tab_shell.php';
