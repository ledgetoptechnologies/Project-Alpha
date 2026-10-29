<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/check_report_staff.php';
require_once __DIR__ . '/../../utils/check_reports.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$type = (string)($_POST['document_type'] ?? '');
$documentId = (int)($_POST['document_id'] ?? 0);
$actor = (int)($_SESSION['user']['id'] ?? 0);
if (!csrf_validate() || !pa_check_report_staff_access($pdo, $type, $documentId, $actor, 'invoices.mark_paid')) {
    http_response_code(403); echo 'Payment confirmation permission required.'; exit;
}
$redirect = $type === 'invoice' ? '/?page=invoice/invoice-details&id=' . $documentId : '/?page=project/project-invoice-details&id=' . $documentId;
try {
    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, ['received','rejected'], true) || ($_POST['confirm_check_action'] ?? '') !== '1') throw new DomainException('Explicitly confirm the check action before continuing.');
    $note = trim((string)($_POST['note'] ?? ''));
    if (strlen($note) > 500) throw new DomainException('Keep the review note within 500 characters.');
    if ($action === 'rejected' && $note === '') throw new DomainException('Add a reason before rejecting a check report.');
    $report = pa_check_report_resolve($pdo, $type, $documentId, $action, $actor, $note !== '' ? $note : null, $appConfig);
    $receiptWarning = false;
    if ($action === 'received' && empty($report['already_resolved']) && !empty($_POST['send_receipt'])) {
        try {
            require_once __DIR__ . '/../../utils/payment_receipts.php';
            if ($type === 'invoice') payment_receipt_issue($pdo, (int)$report['payment_id'], $appConfig, true, null, true);
            else project_payment_receipt_email_issue($pdo, (int)$report['payment_id'], $appConfig, null, true);
        } catch (Throwable $receiptError) { $receiptWarning = true; }
    }
    header('Location: ' . $redirect . '&check_action_saved=1' . ($receiptWarning ? '&check_receipt_warning=1' : ''));
} catch (DomainException | InvalidArgumentException $error) {
    header('Location: ' . $redirect . '&check_action_error=' . rawurlencode($error->getMessage()));
} catch (Throwable $error) {
    header('Location: ' . $redirect . '&check_action_error=' . rawurlencode('The check action could not be completed. Reload and review the current payment state before trying again.'));
}
exit;
