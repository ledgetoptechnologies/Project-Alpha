<?php
// src/controllers/serve_upload.php
// Securely serve files stored in config/uploads (preferred) or src/uploads (fallback)
//
// Access policy:
// - Root-level image files (logos) are publicly accessible (needed for login page branding)
// - Everything else (PDFs, files in subdirectories like signed_contracts/) requires an
//   authenticated session — these contain client PII and contract data.

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$fnameRaw = isset($_GET['file']) ? (string)$_GET['file'] : '';
// sanitize and allow a single subdirectory (e.g. "signed_contracts/filename.pdf" or "organizations/filename.pdf")
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
// The front controller is the single authority for development auth bypass and
// creates a real session there. Never interpret environment flags in this
// publicly routed file handler: staging must still require a session.
if (!$isPublicAsset && empty($_SESSION['user'])) {
  http_response_code(403);
  exit;
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
if (strpos($path, $base) !== 0) { http_response_code(404); exit; }

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
