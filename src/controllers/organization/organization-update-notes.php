<?php
// src/controllers/organization/organization-update-notes.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// CSRF check (endpoint is bypassed from global CSRF gate because it is AJAX-capable)
if (!csrf_validate()) {
    header('Location: /?page=organization/organization-view&id=' . (int)($_POST['id'] ?? 0) . '&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if (!$id) {
    header('Location: /?page=organization/organizations-list&error=' . urlencode('Invalid organization ID'));
    exit;
}
require_record_ownership($pdo, 'organizations', $id);

// Verify organization exists
$checkStmt = $pdo->prepare('SELECT id FROM organizations WHERE id = ?');
$checkStmt->execute([$id]);
if (!$checkStmt->fetch()) {
    header('Location: /?page=organization/organizations-list&error=' . urlencode('Organization not found'));
    exit;
}

// Update notes
$stmt = $pdo->prepare('UPDATE organizations SET notes = ? WHERE id = ?');
$stmt->execute([$notes, $id]);

header('Location: /?page=organization/organization-view&id=' . $id . '&notes_updated=1');
exit;
