<?php
// src/controllers/serve_upload.php
// Serve configured root-level image assets and record-authorized uploads.
//
// Access policy:
// - Root-level image files (logos) are publicly accessible (needed for login page branding)
// - Recognized private stores require a session and their owning record's read access.
// - Unrecognized paths and stores with dedicated download routes are denied.

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$fnameRaw = isset($_GET['file']) ? (string)$_GET['file'] : '';
// Normalize request input before applying the store policy.
$fnameRaw = str_replace(chr(0), '', $fnameRaw);
$fname = ltrim($fnameRaw, '/\\');
if ($fname === '' || strpos($fname, '..') !== false) { http_response_code(404); exit; }

// These stores have dedicated download routes that check the owning record.
// A known storage filename must not bypass those checks through this route.
$uploadCategory = strtolower(strtok(str_replace('\\', '/', $fname), '/'));
if (in_array($uploadCategory, ['worker-documents', 'contractor-invoices', 'projects'], true)) {
  http_response_code(404);
  exit;
}

$ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
$isImage = in_array($ext, ['png','jpg','jpeg','webp','gif','svg'], true);
$inSubdir = strpos($fname, '/') !== false || strpos($fname, '\\') !== false;

// Public access is ONLY allowed for root-level images (logos).
// PDFs and anything inside a subdirectory require authentication.
$isPublicAsset = $isImage && !$inSubdir;
if (!$isPublicAsset && !in_array($uploadCategory, ['organizations', 'receipts', 'forms', 'signed_contracts', 'contract_amendments'], true)) {
  http_response_code(404);
  exit;
}
// The front controller is the single authority for development auth bypass and
// creates a real session there. Never interpret environment flags in this
// publicly routed file handler: staging must still require a session.
if (!$isPublicAsset && empty($_SESSION['user'])) {
  http_response_code(403);
  exit;
}

if ($uploadCategory === 'organizations') {
  $segments = explode('/', str_replace('\\', '/', $fname));
  if (count($segments) !== 2 || $segments[1] === '') {
    http_response_code(404);
    exit;
  }
  require_once __DIR__ . '/../config/db.php';
  require_once __DIR__ . '/../utils/acl.php';
  $actorId = (int)($_SESSION['user']['id'] ?? 0);
  $organization = $pdo->prepare('SELECT id FROM organizations WHERE tax_exempt_file = ? LIMIT 1');
  $organization->execute([$segments[1]]);
  $organizationId = (int)($organization->fetchColumn() ?: 0);
  if ($actorId <= 0 || $organizationId <= 0
      || !user_can($pdo, $actorId, 'organizations.view', 0)
      || !can_access_record($pdo, 'organizations', $organizationId, $actorId)) {
    http_response_code(404);
    exit;
  }
}

if (in_array($uploadCategory, ['receipts', 'forms'], true)) {
  require_once __DIR__ . '/../config/db.php';
  require_once __DIR__ . '/../utils/upload_access.php';
  $actorId = (int)($_SESSION['user']['id'] ?? 0);
  $filePath = '/src/uploads/' . str_replace('\\', '/', $fname);
  if (!upload_financial_file_accessible($pdo, $uploadCategory, $filePath, $actorId, request_client_org_id())) {
    http_response_code(404);
    exit;
  }
}

if (in_array($uploadCategory, ['signed_contracts', 'contract_amendments'], true)) {
  require_once __DIR__ . '/../config/db.php';
  require_once __DIR__ . '/../utils/upload_access.php';
  if (!upload_contract_file_accessible($pdo, $uploadCategory, str_replace('\\', '/', $fname), (int)($_SESSION['user']['id'] ?? 0))) {
    http_response_code(404);
    exit;
  }
}

$bases = [
  __DIR__ . '/../uploads', // src/uploads for contract files
  '/var/www/config/uploads', // fallback for Docker environments
];
$path = false;
foreach ($bases as $b) {
  $candidate = realpath($b . '/' . $fname);
  if ($candidate !== false && is_file($candidate)) { $path = $candidate; $base = realpath($b); break; }
}
if ($path === false) { http_response_code(404); exit; }
if (!str_starts_with($path, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }

$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
elseif ($isImage) {
  $map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'];
  $mime = $map[$ext] ?? $mime;
}

$disposition = (isset($_GET['download']) && $_GET['download']=='1') ? 'attachment' : 'inline';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
// SVG can contain scripts — neutralize with a restrictive CSP on the response
if ($ext === 'svg') {
  header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
}
header('Content-Disposition: ' . $disposition . '; filename="' . basename(rawurldecode($fname)) . '"');
header('Content-Length: ' . filesize($path));
@readfile($path);
exit;
