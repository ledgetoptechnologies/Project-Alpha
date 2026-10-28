<?php
// src/controllers/organization/organizations_upload.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

error_log('ORG_UPLOAD: ===== START ===== Method: ' . $_SERVER['REQUEST_METHOD'] . ' POST: ' . json_encode(array_keys($_POST)) . ' FILES: ' . json_encode(array_keys($_FILES)));

$id = (int)($_POST['id'] ?? 0);
error_log('ORG_UPLOAD: Organization ID: ' . $id);

if ($id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20organization');
    exit;
}
require_record_ownership($pdo, 'organizations', $id);

if (empty($_FILES['tax_exempt_file'])) {
    error_log('ORG_UPLOAD: ERROR - No file in $_FILES array');
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=No%20file%20in%20upload');
    exit;
}

error_log('ORG_UPLOAD: File info: ' . json_encode($_FILES['tax_exempt_file']));

if (!is_uploaded_file($_FILES['tax_exempt_file']['tmp_name'])) {
    $error = $_FILES['tax_exempt_file']['error'] ?? 'unknown';
    error_log('ORG_UPLOAD: ERROR - File not uploaded properly. Error code: ' . $error);
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Upload%20failed%20(code:' . $error . ')');
    exit;
}

$allowed = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
];

$tmp = $_FILES['tax_exempt_file']['tmp_name'];

// Get MIME type using multiple methods for better compatibility
$mime = null;
if (function_exists('mime_content_type')) {
    $mime = @mime_content_type($tmp);
}
if (!$mime && function_exists('finfo_file')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp);
    if (PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }
}
if (!$mime) {
    $mime = $_FILES['tax_exempt_file']['type'];
}

error_log('ORG_UPLOAD: MIME detection - detected: ' . ($mime ?: 'NULL') . ' allowed: ' . json_encode($allowed));

if (!array_key_exists($mime, $allowed)) {
    error_log('ORG_UPLOAD: Invalid MIME type: ' . ($mime ?: 'NULL'));
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Invalid%20file%20type%20(' . rawurlencode($mime ?: 'unknown') . ')');
    exit;
}

if ($_FILES['tax_exempt_file']['size'] > 8 * 1024 * 1024) {
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=File%20too%20large');
    exit;
}

$safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES['tax_exempt_file']['name']));
$targetDir = __DIR__ . '/../../uploads/organizations';
if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
$filename = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
$targetPath = $targetDir . '/' . $filename;

error_log('ORG_UPLOAD: Attempting to save to: ' . $targetPath);

// Try multiple methods like the contract signing
$moved = false;
if (!empty($tmp) && is_uploaded_file($tmp)) {
    $moved = @move_uploaded_file($tmp, $targetPath);
    error_log('ORG_UPLOAD: move_uploaded_file result=' . ($moved ? '1' : '0'));
}
if (!$moved && !empty($tmp)) {
    $moved = @rename($tmp, $targetPath);
    error_log('ORG_UPLOAD: rename result=' . ($moved ? '1' : '0'));
}
if (!$moved && !empty($tmp)) {
    $moved = @copy($tmp, $targetPath);
    error_log('ORG_UPLOAD: copy result=' . ($moved ? '1' : '0'));
}
if ($moved) {
    @unlink($tmp);
    error_log('ORG_UPLOAD: File saved successfully to: ' . $targetPath);
} else {
    error_log('ORG_UPLOAD: FAILED to store file. tmp_exists=' . (is_file($tmp)?'1':'0') . ' dir_exists=' . (is_dir($targetDir)?'1':'0') . ' dir_writable=' . (is_writable($targetDir)?'1':'0') . ' cwd=' . getcwd());
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Failed%20to%20save%20file');
    exit;
}

// Fetch previous file name
$prevStmt = $pdo->prepare('SELECT tax_exempt_file FROM organizations WHERE id = ?');
$prevStmt->execute([$id]);
$prev = $prevStmt->fetchColumn();

// Update DB with new filename
error_log('ORG_UPLOAD: Updating database - filename: ' . $filename . ' org_id: ' . $id);
$stmt = $pdo->prepare('UPDATE organizations SET tax_exempt_file = ?, tax_exempt_uploaded_at = NOW() WHERE id = ?');
$stmt->execute([
    $filename,
    $id
]);
error_log('ORG_UPLOAD: Database updated. Rows affected: ' . $stmt->rowCount());

// Remove previous file (single-version policy)
if (!empty($prev) && $prev !== $filename) {
    $prevPath = $targetDir . '/' . $prev;
    if (is_file($prevPath)) @unlink($prevPath);
}

error_log('ORG_UPLOAD: ===== SUCCESS ===== Redirecting to view page');
header('Location: /?page=organization/organization-view&id=' . $id . '&uploaded=1');
exit;
