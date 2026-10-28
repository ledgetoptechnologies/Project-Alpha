<?php
// src/controllers/project/project_remove_document.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/JobAssignmentService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-remove-document');

$id = (int)($_POST['id'] ?? 0);
$redirect = '/?page=project/projects-list';
if ($id <= 0) { header('Location: ' . $redirect . '&error=Invalid'); exit; }

$pdo->beginTransaction();
try {
    $row = $pdo->prepare('SELECT project_id, document_type, document_id FROM project_documents WHERE id=? LIMIT 1 FOR UPDATE');
    $row->execute([$id]);
    $d = $row->fetch(PDO::FETCH_ASSOC);
    if (!$d) {
        throw new RuntimeException('Project document not found.', 404);
    }
    $projectId = (int)$d['project_id'];
    $documentId = (int)$d['document_id'];
    $actorId = (int)($_SESSION['user']['id'] ?? 0);
    $map = ['quote'=>'quotes', 'contract'=>'contracts', 'invoice'=>'invoices', 'recurring_invoice'=>'invoices', 'long_term_contract'=>'contracts', 'on_demand_contract'=>'contracts'];
    $table = $map[$d['document_type']] ?? null;
    if ($actorId <= 0 || !$table || !can_access_record($pdo, 'projects', $projectId, $actorId)
        || !can_access_record($pdo, $table, $documentId, $actorId)) {
        throw new RuntimeException('Permission denied.', 403);
    }
    $document = $pdo->prepare("SELECT job_id, project_id FROM {$table} WHERE id=? FOR UPDATE");
    $document->execute([$documentId]);
    $documentRow = $document->fetch(PDO::FETCH_ASSOC);
    if (!$documentRow || (int)($documentRow['project_id'] ?? 0) !== $projectId) {
        throw new RuntimeException('Project document association is stale.', 409);
    }
    $jobId = (int)($documentRow['job_id'] ?? 0);
    if ($jobId > 0) {
        $job = $pdo->prepare('SELECT project_id FROM jobs WHERE id=? FOR UPDATE');
        $job->execute([$jobId]);
        if ((int)$job->fetchColumn() !== $projectId) {
            throw new RuntimeException('Project job association is stale.', 409);
        }
        if (!can_access_record($pdo, 'jobs', $jobId, $actorId)) {
            throw new RuntimeException('Permission denied.', 403);
        }
        foreach (['quotes', 'contracts', 'invoices'] as $linkedTable) {
            $linked = $pdo->prepare("SELECT id FROM {$linkedTable} WHERE job_id=?");
            $linked->execute([$jobId]);
            foreach ($linked->fetchAll(PDO::FETCH_COLUMN) as $linkedId) {
                if (!can_access_record($pdo, $linkedTable, (int)$linkedId, $actorId)) {
                    throw new RuntimeException('Permission denied.', 403);
                }
            }
        }
    }
    $pdo->prepare('DELETE FROM project_documents WHERE id=?')->execute([$id]);
    if ($jobId > 0) {
        JobAssignmentService::assignProject($pdo, $jobId, null);
    } else {
        $pdo->prepare("UPDATE {$table} SET project_id=NULL WHERE id=? AND project_id=?")->execute([$documentId, $projectId]);
    }
    $pdo->commit();
    $redirect = '/?page=project/projects-details&id=' . $projectId;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(in_array($error->getCode(), [403, 404, 409], true) ? $error->getCode() : 422);
    exit($error->getMessage());
}
header('Location: ' . $redirect . '&removed_job=1');
exit;
