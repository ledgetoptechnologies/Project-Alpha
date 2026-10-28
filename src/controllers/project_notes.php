<?php
// src/controllers/project_notes.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/project_notes_access.php';
header('Content-Type: application/json; charset=utf-8');

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$projectCode = isset($_GET['project_code']) ? trim((string)$_GET['project_code']) : '';
$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorId <= 0 || !user_can($pdo, $actorId, 'projects.view', 0)) {
  http_response_code(403);
  echo json_encode(['error' => 'Forbidden']);
  exit;
}

try {
  if ($projectCode !== '') {
    $job = project_notes_authorized_job($pdo, $projectCode, $actorId, $clientId);
    if (!$job) {
      http_response_code(404);
      echo json_encode(['error' => 'Project not found']);
      exit;
    }
    $st = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=? AND client_id=?');
    $st->execute([$projectCode, (int)$job['client_id']]);
    $notes = (string)$st->fetchColumn();
    echo json_encode(['notes'=>$notes]);
    exit;
  }
  if ($clientId > 0) {
    // Resolve through a job so another member's project notes cannot be read
    // merely by supplying the shared client ID.
    $st = $pdo->prepare('SELECT j.job_code, pm.notes FROM jobs j JOIN project_meta pm ON pm.project_code=j.job_code AND pm.client_id=j.client_id WHERE j.client_id=? AND j.archived=0 AND pm.notes IS NOT NULL AND pm.notes<>"" ORDER BY pm.updated_at DESC LIMIT 100');
    $st->execute([$clientId]);
    $notes = '';
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      if (project_notes_authorized_job($pdo, (string)$row['job_code'], $actorId, $clientId)) {
        $notes = (string)$row['notes'];
        break;
      }
    }
    echo json_encode(['notes'=>$notes]);
    exit;
  }
  echo json_encode(['notes'=>'']);
} catch (Throwable $e) {
  echo json_encode(['notes'=>'']);
}
