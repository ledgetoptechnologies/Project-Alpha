<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/autopay_staff_management.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$clientId = (int)($_POST['client_id'] ?? 0);
$actorId = (int)($_SESSION['user']['id'] ?? 0);
if (!csrf_validate() || !pa_autopay_staff_can_manage_client($pdo, $clientId, $actorId)) {
    http_response_code(403); echo 'Client and billing management permission required.'; exit;
}
$redirect = '/?page=client/client-details&id=' . $clientId;
try {
    if (($_POST['confirm_disable_autopay'] ?? '') !== '1') throw new DomainException('Confirm that you want to turn off all future automatic payments for this client.');
    pa_autopay_staff_disable_client($pdo, $clientId, $actorId, $appConfig);
    header('Location: ' . $redirect . '&autopay_disabled=1', true, 303);
} catch (Throwable $error) {
    $message = $error instanceof DomainException ? $error->getMessage() : 'AutoPay could not be updated. Review the client account before collecting payment.';
    header('Location: ' . $redirect . '&error=' . rawurlencode($message), true, 303);
}
exit;
