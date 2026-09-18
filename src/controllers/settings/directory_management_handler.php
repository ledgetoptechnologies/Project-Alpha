<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/api_v2_directory_management.php';

$redirect = '/?page=settings&tab=directory-management';
$actor = (int)($_SESSION['user']['id'] ?? 0);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
    || $actor <= 0
    || !in_array((string)($_SESSION['user']['role'] ?? ''), ['admin','owner'], true)
    || !user_can($pdo, $actor, 'settings.manage', 0)) {
    http_response_code(403); header('Location: ' . $redirect . '&saved=0&error=' . rawurlencode('Permission denied.')); exit;
}
if (!csrf_validate()) {
    http_response_code(403); header('Location: ' . $redirect . '&saved=0&error=' . rawurlencode('Invalid request.')); exit;
}
try {
    if((string)($_POST['action']??'save')==='activate') api_v2_directory_management_activate($pdo,$actor,!empty($_POST['confirm_policy']));
    else api_v2_directory_management_save(
        $pdo,!empty($_POST['configured_enabled']),max(0,(int)($_POST['application_pk']??0)),
        $actor,!empty($_POST['confirm_policy'])
    );
    header('Location: ' . $redirect . '&saved=1');
} catch (Throwable $error) {
    error_log('[DirectoryManagementSettings] ' . get_class($error));
    header('Location: ' . $redirect . '&saved=0&error=' . rawurlencode($error instanceof DomainException ? $error->getMessage() : 'Directory policy could not be saved.'));
}
exit;
