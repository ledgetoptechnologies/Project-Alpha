<?php
// src/controllers/organization/org_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

header('Content-Type: application/json');

// CSRF will be verified by public/index.php POST handler, but double-check if available
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method not allowed']);
    exit;
}

// Verify CSRF token - check session and POST token match
$token = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || empty($token) || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Invalid request (CSRF)']);
    exit;
}

$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
if ($name === '') {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Organization name required']);
    exit;
}

try {
    // Prevent duplicate names (case-insensitive)
    $check = $pdo->prepare('SELECT id FROM organizations WHERE LOWER(name)=LOWER(?) LIMIT 1');
    $check->execute([$name]);
    $existing = $check->fetchColumn();
    if ($existing) {
        echo json_encode(['success'=>true,'id'=> (int)$existing,'name'=>$name,'message'=>'Already exists']);
        exit;
    }

    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO organizations (name, source_version, created_at) VALUES (?, ?, NOW())');
    $ins->execute([$name,portal_projection_source_version()]);
    $id = (int)$pdo->lastInsertId();
    api_v2_directory_record($pdo, 'organization', $id);
    $projection=new App\Services\PortalProjectionMutationService();
    $projection->afterMutation($pdo,$projection->organizationScopes($pdo,$id));
    $pdo->commit();
    echo json_encode(['success'=>true,'id'=>$id,'name'=>$name]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed to create organization']);
    exit;
}
