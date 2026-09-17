<?php
// src/controllers/project/project_invoice_generate.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-invoice-generate');

$projectId = (int)($_POST['project_id'] ?? 0);
if ($projectId <= 0) {
    header('Location: /?page=project/projects-list&error=' . urlencode('Invalid project'));
    exit;
}
require_record_ownership($pdo, 'projects', $projectId);

$period = (string)($_POST['period'] ?? 'current');
$sendEmail = !empty($_POST['send_email']);
if ($sendEmail && !project_invoice_has_saved_deliverable_recipient($pdo, $projectId)) {
    header('Location: /?page=project/projects-details&id=' . $projectId . '&billing_missing_recipients=1&billing_msg=' . urlencode('Add at least one valid saved contact or the company email before generating and emailing the statement.'));
    exit;
}
if ($period === 'previous') {
    [$start, $end] = project_invoice_period_for_date(date('Y-m-d'), true);
} else {
    $start = date('Y-m-01');
    $end = date('Y-m-d');
}

$generation = project_invoice_create_for_period_result($pdo, $projectId, $start, $end, $appConfig, false, false);
$projectInvoiceId = (int)($generation['project_invoice_id'] ?? 0);
if ($projectInvoiceId > 0) {
    if (($generation['status'] ?? '') === 'existing' && (float)($generation['balance'] ?? 0) <= 0.005) {
        header('Location: /?page=project/project-invoice-details&id=' . $projectInvoiceId . '&existing=1&email_err=' . urlencode((string)$generation['message']));
        exit;
    }
    $emailResult = '';
    if ($sendEmail) {
        if (invoice_should_prompt_for_missing_content_links($pdo, 'project_invoice', $projectInvoiceId, $appConfig)) {
            if (invoice_missing_content_links_behavior($appConfig) === 'block') {
                header('Location: /?page=project/project-invoice-details&id=' . $projectInvoiceId . '&generated=1&email_err=' . urlencode(invoice_missing_content_links_message()));
            } else {
                header('Location: /?page=project/project-invoice-details&id=' . $projectInvoiceId . '&generated=1&content_link_warning=1&email_panel=1');
            }
            exit;
        }
        // This button is an explicit staff send, even when automatic monthly
        // delivery is disabled. Keep its stable key so retries and double
        // submissions remain idempotent.
        $delivery = project_invoice_send_email_result($pdo, $projectInvoiceId, $appConfig, null, false, null, true);
        $emailResult = ($delivery['sent'] + $delivery['already_sent']) > 0
            ? '&emailed=1'
            : '&email_err=' . urlencode((string)$delivery['message']);
    }
    $generationParam = ($generation['status'] ?? '') === 'created' ? 'generated=1' : 'existing=1';
    header('Location: /?page=project/project-invoice-details&id=' . $projectInvoiceId . '&' . $generationParam . $emailResult);
    exit;
}

header('Location: /?page=project/projects-details&id=' . $projectId . '&billing_msg=' . urlencode((string)$generation['message']));
exit;
