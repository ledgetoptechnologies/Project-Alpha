<?php
// src/controllers/organization/organizations_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/organization_schema.php';
require_once __DIR__ . '/../../utils/address_book.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../../utils/api_v2_directory_management.php';
if (api_v2_directory_management_guard($pdo,'organization','create')) { header('Location: /?page=organization/organizations-list&directory_managed=1'); exit; }

$name = trim($_POST['name'] ?? '');
$generalEmail = strtolower(trim((string)($_POST['general_email'] ?? '')));
$generalPhone = mb_substr(trim((string)($_POST['general_phone'] ?? '')), 0, 50);
$notes = trim($_POST['notes'] ?? '');
$return_to = trim($_POST['return_to'] ?? '');
$addressValues = [
    'address_line1' => trim((string)($_POST['address_line1'] ?? '')),
    'address_line2' => trim((string)($_POST['address_line2'] ?? '')),
    'city' => trim((string)($_POST['city'] ?? '')),
    'state' => trim((string)($_POST['state'] ?? '')),
    'postal_code' => trim((string)($_POST['postal_code'] ?? '')),
    'country' => trim((string)($_POST['country'] ?? '')),
];

if ($name === '') {
    header('Location: /?page=organization/organizations-create&error=Name%20is%20required');
    exit;
}
if ($generalEmail !== '' && (mb_strlen($generalEmail) > 255 || !filter_var($generalEmail, FILTER_VALIDATE_EMAIL))) {
    header('Location: /?page=organization/organizations-create&error=' . rawurlencode('Enter a valid general email address.'));
    exit;
}

// Handle optional file upload (from full-page create)
$tax_filename = null;
$targetPath = null;
if (!empty($_FILES['tax_exempt_file']) && is_uploaded_file($_FILES['tax_exempt_file']['tmp_name'])) {
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];
    $tmp = $_FILES['tax_exempt_file']['tmp_name'];
    
    // Get MIME type (try multiple methods for better compatibility)
    $mime = null;
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp);
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
    
    if (!array_key_exists($mime, $allowed)) {
        header('Location: /?page=organization/organizations-create&error=Invalid%20file%20type%20(' . rawurlencode($mime) . ')');
        exit;
    }
    if ($_FILES['tax_exempt_file']['size'] > 8 * 1024 * 1024) {
        header('Location: /?page=organization/organizations-create&error=File%20too%20large');
        exit;
    }
    $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES['tax_exempt_file']['name']));
    $targetDir = __DIR__ . '/../../uploads/organizations';
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    $tax_filename = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
    $targetPath = $targetDir . '/' . $tax_filename;
    if (!move_uploaded_file($tmp, $targetPath)) {
        header('Location: /?page=organization/organizations-create&error=Failed%20to%20save%20file');
        exit;
    }
}

$addressColumns = pa_ensure_organization_address_columns($pdo);
$columns = ['name', 'general_email', 'general_phone', 'notes'];
$placeholders = ['?', '?', '?', '?'];
$params = [$name, $generalEmail ?: null, $generalPhone ?: null, $notes ?: null];
foreach ($addressValues as $column => $value) {
    if (isset($addressColumns[$column])) {
        $columns[] = $column;
        $placeholders[] = '?';
        $params[] = $value !== '' ? $value : null;
    }
}
$columns[] = 'tax_exempt_file';
$placeholders[] = '?';
$params[] = $tax_filename;
$columns[] = 'tax_exempt_uploaded_at';
$placeholders[] = $tax_filename ? 'NOW()' : 'NULL';
$columns[] = 'source_version';
$placeholders[] = '?';
$params[] = portal_projection_source_version();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO organizations (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    $stmt->execute($params);
    $id = (int)$pdo->lastInsertId();
    address_book_save($pdo, $addressValues + [
        'label'=>'Billing address','google_place_id'=>trim((string)($_POST['google_place_id']??'')),
        'source'=>trim((string)($_POST['google_place_id']??''))!==''?'google':'manual',
    ], 'organization', $id, 'billing', true, (int)($_SESSION['user']['id']??0));
    $projection=new App\Services\PortalProjectionMutationService();
    $projection->afterMutation($pdo,$projection->organizationScopes($pdo,$id));
    api_v2_directory_record($pdo, 'organization', $id);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_string($targetPath) && is_file($targetPath)) @unlink($targetPath);
    error_log('[organization_create] failed code='.substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12));
    header('Location: /?page=organization/organizations-create&error=Create%20failed');
    exit;
}
if ($return_to === 'clients-create') {
    // redirect back to client create with the new org info
    $q = http_build_query([
        'page' => 'clients-create',
        'org_created' => 1,
        'org_id' => $id,
        'org_name' => $name,
    ]);
    header('Location: /?' . $q);
    exit;
}

header('Location: /?page=organization/organizations-list&created=1');
exit;
