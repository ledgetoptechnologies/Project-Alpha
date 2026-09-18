<?php
// src/controllers/project/projects_delete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../services/ProjectLifecycleService.php';
require_once __DIR__ . '/../../services/ProjectRevisionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-delete');

$id = (int)($_POST['id'] ?? 0); if (!$id) { header('Location: /?page=project/projects-list&error=Invalid'); exit; }
$redirect = (string)($_POST['redirect'] ?? '/?page=project/projects-list');
$action = (string)($_POST['lifecycle_action'] ?? 'archive') === 'restore' ? 'restore' : 'archive';

$portalProjection = new \App\Services\PortalProjectionMutationService();
$pdo->beginTransaction();
try {
    $portalBeforeScopes = $portalProjection->lockedProjectScopes($pdo, $id);
    // "Delete" is intentionally a compatibility route. Established Projects,
    // their document mappings, uploads, financial links, and revision history
    // are permanent; the browser action now performs a reversible archive.
    (new \App\Services\ProjectLifecycleService($pdo))->apply(
        $id,
        $action,
        (int)($_SESSION['user']['id'] ?? 0)
    );
    $portalProjection->afterMutation($pdo, $portalBeforeScopes);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

header('Location: ' . $redirect . '&' . ($action === 'restore' ? 'restored=1' : 'archived=1'));
exit;
