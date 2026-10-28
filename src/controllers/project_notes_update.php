<?php
// src/controllers/project_notes_update.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/project_notes_access.php';
require_once __DIR__ . '/../utils/csrf.php';
csrf_verify_post_or_redirect('project-notes-update');
// add projects to their own sub folder, and change how it creates the project ID, so we can identify if it is regular Long term, or on demand.

$project_code = trim((string)($_POST['project_code'] ?? ''));
$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$notes = trim((string)($_POST['notes'] ?? ''));
$redirect = $_POST['redirect'] ?? '/?page=jobs-list';
if (!is_string($redirect) || !str_starts_with($redirect, '/?page=') || str_contains($redirect, "\r") || str_contains($redirect, "\n")) {
  $redirect = '/?page=jobs/jobs-list';
}

if ($project_code === '' || $client_id <= 0) {
  header('Location: '.$redirect.'&error=Invalid%20project%20data');
  exit;
}

$actorId = (int)($_SESSION['user']['id'] ?? 0);
$job = $actorId > 0 && user_can($pdo, $actorId, 'projects.edit', 0)
  ? project_notes_authorized_job($pdo, $project_code, $actorId, $client_id)
  : null;
if (!$job) {
  http_response_code(404);
  exit('Project not found.');
}
$client_id = (int)$job['client_id'];

try {
  $st = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
  $st->execute([$project_code, $client_id, $notes]);
} catch (Throwable $e) {
  header('Location: '.$redirect.'&error=Failed%20to%20save%20notes');
  exit;
}

header('Location: '.$redirect.'&saved=1');
exit;
