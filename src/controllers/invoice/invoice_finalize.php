<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/general_recipient_invoices.php';
require_once __DIR__ . '/../../utils/invoice_content_links.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/autopay_staff.php';
$appConfig = pa_autopay_staff_request_config($appConfig, $_POST, csrf_token());

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=invoice/invoices-list&error=' . urlencode('Invalid invoice.'));
    exit;
}
require_record_ownership($pdo, 'invoices', $id);
$invoiceStmt = $pdo->prepare('SELECT recipient_presentation_mode, collection_mode FROM invoices WHERE id=? LIMIT 1');
$invoiceStmt->execute([$id]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$isGeneralRecipientInvoice = pa_invoice_is_general_recipient($invoice);
$isProjectAggregateInvoice = ($invoice['collection_mode'] ?? 'direct') === 'project_aggregate';

try {
    if (!$isGeneralRecipientInvoice && !$isProjectAggregateInvoice && invoice_should_prompt_for_missing_content_links($pdo, 'invoice', $id, $appConfig)) {
        $missingLinkBehavior = invoice_missing_content_links_behavior($appConfig);
        if ($missingLinkBehavior === 'block') {
            header('Location: /?page=invoice/invoice-details&id=' . $id . '&email_err=' . urlencode(invoice_missing_content_links_message()));
            exit;
        }
        if (empty($_POST['confirm_missing_content_links'])) {
            header('Location: /?page=invoice/invoice-details&id=' . $id . '&content_link_warning=1');
            exit;
        }
    }
    $userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
    if ($isGeneralRecipientInvoice) {
        $link = invoice_finalize_and_create_general_recipient_link($pdo, $id, $appConfig, $userId);
        audit_log($pdo, 'invoice.finalize_with_manual_link', 'invoice', $id, ['user_id' => $userId, 'existing_link' => $link['existing']]);
        $_SESSION['flash_general_recipient_link'] = ['invoice_id' => $id, 'token' => $link['token']];
        $message = '&finalized=1';
    } elseif ($isProjectAggregateInvoice) {
        invoice_finalize($pdo, $id, $appConfig, 'manual_project_billing_finalize', $userId);
        audit_log($pdo, 'invoice.finalize', 'invoice', $id, ['sent' => false, 'project_billing' => true, 'user_id' => $userId]);
        $message = '&finalized=1&project_billing=1';
    } else {
        invoice_finalize($pdo, $id, $appConfig, 'manual_finalize', $userId);
        $sent = invoice_send_finalized($pdo, $id, $appConfig, 'manual_finalize_' . date('YmdHis'));
        audit_log($pdo, 'invoice.finalize', 'invoice', $id, ['sent' => $sent, 'user_id' => $userId]);
        $message = $sent ? '&finalized=1&emailed=1' : '&finalized=1&email_err=' . urlencode('Invoice finalized, but email was not sent.');
    }
    header('Location: /?page=invoice/invoice-details&id=' . $id . $message);
} catch (Throwable $e) {
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . urlencode($e->getMessage()));
}
exit;
