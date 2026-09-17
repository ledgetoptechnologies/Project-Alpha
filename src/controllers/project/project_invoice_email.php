<?php
// src/controllers/project/project_invoice_email.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../utils/invoice_content_links.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-invoice-email');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=project/projects-list&error=' . urlencode('Invalid project invoice'));
    exit;
}

$stmt = $pdo->prepare('SELECT project_id FROM project_invoices WHERE id=?');
$stmt->execute([$id]);
$projectId = (int)($stmt->fetchColumn() ?: 0);
require_record_ownership($pdo, 'projects', $projectId);

$recipientClientIds = $_POST['recipient_client_ids'] ?? null;
if ($recipientClientIds !== null && !is_array($recipientClientIds)) {
    $recipientClientIds = [];
}
$recipientClientIds = $recipientClientIds === null ? null : array_map('intval', $recipientClientIds);
$recipientKeys = $_POST['recipient_keys'] ?? null;
if ($recipientKeys !== null && !is_array($recipientKeys)) {
    $recipientKeys = [];
}
$recipientKeys = $recipientKeys === null ? null : array_values(array_unique(array_map('strval', $recipientKeys)));
if (invoice_should_prompt_for_missing_content_links($pdo, 'project_invoice', $id, $appConfig, $recipientClientIds)) {
    $missingLinkBehavior = invoice_missing_content_links_behavior($appConfig);
    if ($missingLinkBehavior === 'block') {
        header('Location: /?page=project/project-invoice-details&id=' . $id . '&email_err=' . urlencode(invoice_missing_content_links_message()));
        exit;
    }
    if (empty($_POST['confirm_missing_content_links'])) {
        header('Location: /?page=project/project-invoice-details&id=' . $id . '&content_link_warning=1&email_panel=1');
        exit;
    }
}
$delivery = project_invoice_send_email_result($pdo, $id, $appConfig, $recipientClientIds, true, $recipientKeys);
$param = $delivery['sent'] > 0
    ? 'emailed=1'
    : 'email_err=' . urlencode((string)$delivery['message']);
header('Location: /?page=project/project-invoice-details&id=' . $id . '&' . $param);
exit;
