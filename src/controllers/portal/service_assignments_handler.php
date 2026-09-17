<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../services/PortalServiceAssignmentManager.php';

use App\Services\PortalServiceAssignmentManager;

$actorId = (int)($_SESSION['user']['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$allowedActions = ['create', 'update', 'deactivate', 'reactivate', 'remove'];
$assignmentErrorMessage = static function (string $code): string {
    return match ($code) {
        'service-assignment-duplicate' => 'That service is already assigned to this record.',
        'service-assignment-window-invalid' => 'The effective end must be later than the effective start.',
        'service-assignment-service-unavailable' => 'Choose an active, portal-requestable service.',
        'service-assignment-subject-invalid', 'service-assignment-subject-unavailable' => 'The assignment subject is unavailable.',
        'service-assignment-unavailable' => 'The service assignment is unavailable.',
        default => str_contains($code, ' ') ? $code : 'The service assignment could not be saved.',
    };
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $actorId < 1 || !in_array($action, $allowedActions, true)) {
    http_response_code(422);
    exit('Invalid service-assignment request.');
}

/** @return array{type:string,id:int,permission:string,ownership_table:string,ownership_id:int,redirect:string,anchor:string} */
$resolveSubject = static function (PDO $pdo, string $type, int $id): array {
    if ($id < 1) throw new DomainException('Choose a valid assignment subject.');
    if ($type === 'organization') {
        $statement = $pdo->prepare('SELECT id FROM organizations WHERE id=?');
        $permission = 'organizations.manage';
        $table = 'organizations';
    } elseif ($type === 'department') {
        $statement = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id=?');
        $permission = 'organizations.manage';
        $table = 'organizations';
    } elseif (in_array($type, ['client', 'standalone_client'], true)) {
        $statement = $pdo->prepare('SELECT id FROM clients WHERE id=? AND archived=0 AND deleted_at IS NULL');
        $permission = 'clients.edit';
        $table = 'clients';
    } elseif ($type === 'project') {
        $statement = $pdo->prepare("SELECT id FROM projects WHERE id=? AND status<>'cancelled'");
        $permission = 'projects.edit';
        $table = 'projects';
    } else {
        throw new DomainException('Choose a valid assignment subject.');
    }
    $statement->execute([$id]);
    $ownershipId = (int)($statement->fetchColumn() ?: 0);
    if ($ownershipId < 1) throw new DomainException('The assignment subject is unavailable.');
    [$redirect, $anchor] = match ($type) {
        'organization' => ['/?page=organization/organization-view&id=' . $id, 'assigned-services'],
        'department' => ['/?page=organization/organization-view&id=' . $ownershipId, 'department-' . $id],
        'client', 'standalone_client' => ['/?page=client/client-details&id=' . $id, 'assigned-services'],
        'project' => ['/?page=project/projects-details&id=' . $id, 'assigned-services'],
    };
    return ['type' => $type, 'id' => $id, 'permission' => $permission,
        'ownership_table' => $table, 'ownership_id' => $ownershipId, 'redirect' => $redirect, 'anchor' => $anchor];
};

/** @return array{type:string,id:int} */
$assignmentSubject = static function (PDO $pdo, int $assignmentId): array {
    if ($assignmentId < 1) throw new DomainException('Choose a valid service assignment.');
    $statement = $pdo->prepare('SELECT subject_type,subject_public_id FROM portal_service_assignments WHERE id=? AND deleted_at IS NULL');
    $statement->execute([$assignmentId]);
    $assignment = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) throw new DomainException('The service assignment is unavailable.');
    $type = (string)$assignment['subject_type'];
    $table = match ($type) {
        'organization' => 'organizations',
        'department' => 'organization_departments',
        'client', 'standalone_client' => 'clients',
        'project' => 'projects',
        default => throw new DomainException('The service assignment is unavailable.'),
    };
    $lookup = $pdo->prepare("SELECT id FROM {$table} WHERE public_id=?");
    $lookup->execute([(string)$assignment['subject_public_id']]);
    $subjectId = (int)($lookup->fetchColumn() ?: 0);
    if ($subjectId < 1) throw new DomainException('The service assignment subject is unavailable.');
    return ['type' => $type, 'id' => $subjectId];
};

$redirect = '/?page=user-dashboard';
try {
    if ($action === 'create') {
        $subject = $resolveSubject($pdo, (string)($_POST['subject_type'] ?? ''), (int)($_POST['subject_id'] ?? 0));
        $assignmentId = 0;
    } else {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $identity = $assignmentSubject($pdo, $assignmentId);
        $subject = $resolveSubject($pdo, $identity['type'], $identity['id']);
    }
    $redirect = $subject['redirect'];
    if (!user_can($pdo, $actorId, $subject['permission'], 0)) {
        deny_response('user-dashboard');
    }
    require_record_ownership($pdo, $subject['ownership_table'], $subject['ownership_id']);
    if (!csrf_validate()) {
        $messageScope = '&assignment_subject_type=' . rawurlencode($subject['type'])
            . '&assignment_subject_id=' . $subject['id'];
        header('Location: ' . $redirect . '&assignment_error=' . rawurlencode('Invalid request (CSRF)')
            . $messageScope . '#' . $subject['anchor']);
        exit;
    }

    $manager = new PortalServiceAssignmentManager();
    $itemId = (int)($_POST['item_library_id'] ?? 0);
    $effectiveFrom = trim((string)($_POST['effective_from'] ?? '')) ?: null;
    $effectiveUntil = trim((string)($_POST['effective_until'] ?? '')) ?: null;
    $result = match ($action) {
        'create' => $manager->create($pdo, $subject['type'], $subject['id'], $itemId,
            $effectiveFrom, $effectiveUntil, $actorId),
        'update' => $manager->update($pdo, $assignmentId, $itemId, $effectiveFrom, $effectiveUntil, $actorId),
        'deactivate' => $manager->deactivate($pdo, $assignmentId, $actorId),
        'reactivate' => $manager->reactivate($pdo, $assignmentId, $actorId),
        'remove' => $manager->softDelete($pdo, $assignmentId, $actorId),
    };
    $savedId = (int)($result['assignment']['id'] ?? $assignmentId);
    audit_log($pdo, 'portal_service_assignment.' . $action, 'portal_service_assignment', $savedId, [
        'subject_type' => $subject['type'],
        'subject_id' => $subject['id'],
        'projection_profile_count' => count($result['projectionProfiles'] ?? []),
    ], $actorId);
    $messageScope = '&assignment_subject_type=' . rawurlencode($subject['type'])
        . '&assignment_subject_id=' . $subject['id'];
    header('Location: ' . $redirect . '&assignment_saved=1' . $messageScope . '#' . $subject['anchor']);
    exit;
} catch (DomainException $error) {
    $anchor = isset($subject['anchor']) ? '#' . $subject['anchor'] : '';
    $messageScope = isset($subject)
        ? '&assignment_subject_type=' . rawurlencode($subject['type']) . '&assignment_subject_id=' . $subject['id']
        : '';
    header('Location: ' . $redirect . '&assignment_error=' . rawurlencode($assignmentErrorMessage($error->getMessage())) . $messageScope . $anchor);
    exit;
} catch (Throwable $error) {
    error_log('[portal-service-assignments] ' . $error->getMessage());
    $anchor = isset($subject['anchor']) ? '#' . $subject['anchor'] : '';
    $messageScope = isset($subject)
        ? '&assignment_subject_type=' . rawurlencode($subject['type']) . '&assignment_subject_id=' . $subject['id']
        : '';
    header('Location: ' . $redirect . '&assignment_error=' . rawurlencode('The service assignment could not be saved.') . $messageScope . $anchor);
    exit;
}
