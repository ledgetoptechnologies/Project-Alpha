<?php
// src/controllers/project/projects_update_status.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../services/PortalProjectionMutationService.php';
require_once __DIR__ . '/../../services/ProjectContractEligibilityGuardService.php';
require_once __DIR__ . '/../../services/ProjectReceivablesSummaryService.php';
require_once __DIR__ . '/../../services/ProjectCloseGuardService.php';
require_once __DIR__ . '/../../services/ProjectLifecycleService.php';
require_once __DIR__ . '/../../services/ProjectRevisionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!csrf_validate()) {
    http_response_code(403);
    exit('CSRF token invalid');
}

$id = (int)($_POST['project_id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!$id) {
    http_response_code(400);
    exit('Project ID is required');
}

$validStatuses = ['not_started', 'active', 'completed', 'cancelled'];
if (!in_array($status, $validStatuses)) {
    http_response_code(400);
    exit('Invalid status');
}

try {
    $pdo->beginTransaction();
    $action = match ((string)$status) {
        'completed' => 'complete',
        'cancelled' => 'cancel',
        default => (string)$status,
    };
    $transition = (new App\Services\ProjectLifecycleService($pdo))->apply(
        $id,
        $action,
        (int)($_SESSION['user']['id'] ?? 0)
    );
    if (!$transition['transitioned']) {
        // The blocked attempt is itself a durable security/audit event. No
        // Project, schedule, projection, or outbox mutation has occurred.
        $pdo->commit();
        header(
            'Location: /?page=project/projects-details&id=' . $id
            . '&closeout_blocked=1&closeout_target=' . rawurlencode((string)$status)
            . '#project-closeout-alert'
        );
        exit;
    }
    ScheduleService::syncProject($pdo, $id, (string)($appConfig['timezone'] ?? 'UTC'), (int)($_SESSION['user']['id']??0));
    (new App\Services\PortalProjectionMutationService())->queueProject($pdo,$id);
    $pdo->commit();
} catch (Throwable $error) {
    if($pdo->inTransaction())$pdo->rollBack();
    http_response_code(500);exit('Project status could not be updated.');
}

header('Location: /?page=project/projects-details&id=' . $id . '&status_updated=1');
exit;
?>
