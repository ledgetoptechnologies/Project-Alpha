<?php
// src/controllers/organization/organization_document_upload.php
// Generic handler for uploading COI, W-9, and Tax Exempt documents for organizations
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

error_log('ORG_DOC_UPLOAD: ===== START ===== Method: ' . $_SERVER['REQUEST_METHOD']);

$id = (int)($_POST['id'] ?? 0);
$docType = $_POST['doc_type'] ?? 'tax_exempt'; // coi, w9, or tax_exempt

error_log('ORG_DOC_UPLOAD: Organization ID: ' . $id . ' Doc Type: ' . $docType);

if ($id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20organization');
    exit;
}
require_record_ownership($pdo, 'organizations', $id);

// Validate doc type
$validDocTypes = ['coi', 'w9', 'tax_exempt'];
if (!in_array($docType, $validDocTypes, true)) {
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Invalid%20document%20type');
    exit;
}

// Determine which file input and DB columns to use
$fileInput = $docType . '_file';
$dbFileColumn = $docType . '_file';
$dbDateColumn = $docType . '_uploaded_at';

if (empty($_FILES[$fileInput])) {
    error_log('ORG_DOC_UPLOAD: ERROR - No file in $_FILES[' . $fileInput . ']');
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=No%20file%20in%20upload');
    exit;
}

error_log('ORG_DOC_UPLOAD: File info: ' . json_encode($_FILES[$fileInput]));

if (!is_uploaded_file($_FILES[$fileInput]['tmp_name'])) {
    $error = $_FILES[$fileInput]['error'] ?? 'unknown';
    error_log('ORG_DOC_UPLOAD: ERROR - File not uploaded properly. Error code: ' . $error);
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Upload%20failed%20(code:' . $error . ')');
    exit;
}

$allowed = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
];

$tmp = $_FILES[$fileInput]['tmp_name'];

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
    $mime = $_FILES[$fileInput]['type'];
}

error_log('ORG_DOC_UPLOAD: MIME detection - detected: ' . ($mime ?: 'NULL'));

if (!array_key_exists($mime, $allowed)) {
    error_log('ORG_DOC_UPLOAD: Invalid MIME type: ' . ($mime ?: 'NULL'));
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Invalid%20file%20type%20(' . rawurlencode($mime ?: 'unknown') . ')');
    exit;
}

if ($_FILES[$fileInput]['size'] > 8 * 1024 * 1024) {
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=File%20too%20large');
    exit;
}

$safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES[$fileInput]['name']));
$targetDir = __DIR__ . '/../../uploads/organizations';
if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
$filename = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
$targetPath = $targetDir . '/' . $filename;

error_log('ORG_DOC_UPLOAD: Attempting to save to: ' . $targetPath);

// Try multiple methods like the contract signing
$moved = false;
if (!empty($tmp) && is_uploaded_file($tmp)) {
    $moved = @move_uploaded_file($tmp, $targetPath);
    error_log('ORG_DOC_UPLOAD: move_uploaded_file result=' . ($moved ? '1' : '0'));
}
if (!$moved && !empty($tmp)) {
    $moved = @rename($tmp, $targetPath);
    error_log('ORG_DOC_UPLOAD: rename result=' . ($moved ? '1' : '0'));
}
if (!$moved && !empty($tmp)) {
    $moved = @copy($tmp, $targetPath);
    error_log('ORG_DOC_UPLOAD: copy result=' . ($moved ? '1' : '0'));
}
if ($moved) {
    @unlink($tmp);
    error_log('ORG_DOC_UPLOAD: File saved successfully to: ' . $targetPath);
} else {
    error_log('ORG_DOC_UPLOAD: FAILED to store file. tmp_exists=' . (is_file($tmp)?'1':'0') . ' dir_exists=' . (is_dir($targetDir)?'1':'0') . ' dir_writable=' . (is_writable($targetDir)?'1':'0'));
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Failed%20to%20save%20file');
    exit;
}

// Fetch previous file name
$prevStmt = $pdo->prepare("SELECT {$dbFileColumn} FROM organizations WHERE id = ?");
$prevStmt->execute([$id]);
$prev = $prevStmt->fetchColumn();

// Update DB with new filename
error_log('ORG_DOC_UPLOAD: Updating database - filename: ' . $filename . ' org_id: ' . $id);
$stmt = $pdo->prepare("UPDATE organizations SET {$dbFileColumn} = ?, {$dbDateColumn} = NOW() WHERE id = ?");
$stmt->execute([
    $filename,
    $id
]);
error_log('ORG_DOC_UPLOAD: Database updated. Rows affected: ' . $stmt->rowCount());

// Remove previous file (single-version policy)
if (!empty($prev) && $prev !== $filename) {
    $prevPath = $targetDir . '/' . $prev;
    if (is_file($prevPath)) @unlink($prevPath);
    error_log('ORG_DOC_UPLOAD: Removed previous file: ' . $prevPath);
}

error_log('ORG_DOC_UPLOAD: ===== SUCCESS ===== Redirecting to view page');
header('Location: /?page=organization/organization-view&id=' . $id . '&uploaded=' . $docType);
exit;
