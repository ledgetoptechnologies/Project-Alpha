<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/check_report_staff.php';
require_once __DIR__ . '/../../utils/autopay_review.php';
require_once __DIR__ . '/../../utils/audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$type = (string)($_POST['document_type'] ?? '');
$id = (int)($_POST['document_id'] ?? 0);
$actor = (int)($_SESSION['user']['id'] ?? 0);
if (!csrf_validate() || !pa_autopay_test_context_enabled($appConfig)
    || !pa_check_report_staff_access($pdo, $type, $id, $actor, 'invoices.send')) {
    http_response_code(403); echo 'Invoice management permission required.'; exit;
}
$redirect = '/?page=' . ($type === 'invoice' ? 'invoice/invoice-details' : 'project/project-invoice-details') . '&id=' . $id;
try {
    $note = trim((string)($_POST['resolution_note'] ?? ''));
    if (($_POST['confirm_review_resolution'] ?? '') !== '1' || $note === '' || mb_strlen($note) > 1000) {
        throw new DomainException('Confirm the resolution and provide a note of 1 to 1000 characters.');
    }
    $review = pa_autopay_invoice_review_staff_restart($pdo, $type, $id, $actor, $appConfig, $note);
    audit_log($pdo, 'invoice.autopay_review_resolved', $type, $id, ['review_id'=>(int)$review['id']], $actor);
    header('Location: ' . $redirect . '&autopay_review_resolved=1', true, 303);
} catch (Throwable $error) {
    $message = $error instanceof DomainException ? $error->getMessage() : 'The invoice remains held. Review its payment status before collecting.';
    header('Location: ' . $redirect . '&' . ($type === 'invoice' ? 'error' : 'payment_err') . '=' . rawurlencode($message), true, 303);
}
exit;
