<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); header('Pragma: no-cache');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../utils/api_v2_capabilities.php';
require_once __DIR__ . '/../../utils/api_v2_directory_organization_profile_command.php';
$requestId = api_v2_uuid(); header('X-Request-ID: ' . $requestId);
$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (preg_match('#^/api/v2/directory/organizations/([0-9a-f]{32})/profile/commands$#D', $path, $match) !== 1) { http_response_code(404); exit; }
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? ''); if (preg_match('/^application\/json(?:\s*;\s*charset\s*=\s*utf-8)?\s*$/iD', $contentType) !== 1) { http_response_code(415); exit; }
$contentLength = $_SERVER['CONTENT_LENGTH'] ?? null; if ($contentLength !== null && (!ctype_digit((string)$contentLength) || (int)$contentLength > 32 * 1024)) { http_response_code(413); exit; }
$stream = fopen('php://input', 'rb'); if ($stream === false) { http_response_code(400); exit; } $body = stream_get_contents($stream, 32 * 1024 + 1); fclose($stream);
if (!is_string($body) || strlen($body) > 32 * 1024) { http_response_code(413); exit; }
$command = api_v2_directory_organization_profile_command_parse($body); if ($command === null) { http_response_code(400); exit; }
try { $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('MYSQL_DATABASE') ?: 'project_alpha') . ';charset=utf8mb4', getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
catch (Throwable $error) { error_log('[ApiV2OrganizationProfile] database unavailable: ' . get_class($error)); http_response_code(503); exit; }
require_once __DIR__ . '/../../utils/api_auth.php';
$key = api_require_key(['api.capabilities.read', 'directory.organizations.write'], false);
if (in_array('full', api_normalize_scopes($key['scopes'] ?? ''), true)) { http_response_code(403); exit; }
try { $outcome = api_v2_directory_organization_profile_command_write($pdo, $match[1], $command, (int)$key['id'], ['source'=>$_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID'] ?? null, 'application'=>$_SERVER['HTTP_X_PA_APPLICATION_ID'] ?? null, 'epoch'=>$_SERVER['HTTP_X_PA_HISTORY_EPOCH'] ?? null], $requestId); if ($outcome['status'] !== 200) { http_response_code($outcome['status']); exit; } $json = json_encode($outcome['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); if (strlen($json) > 64 * 1024) throw new RuntimeException('Organization profile response limit'); echo $json; }
catch (Throwable $error) { error_log('[ApiV2OrganizationProfile] command unavailable: ' . get_class($error)); http_response_code(503); }
