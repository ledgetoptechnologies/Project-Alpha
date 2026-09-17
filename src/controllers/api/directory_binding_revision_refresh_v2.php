<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); header('Pragma: no-cache');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../../utils/api_v2_capabilities.php'; require_once __DIR__ . '/../../utils/api_v2_directory_revision.php'; require_once __DIR__ . '/../../utils/api_v2_directory_binding_revision_refresh.php';
$requestId = api_v2_uuid(); header('X-Request-ID: ' . $requestId);
$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (preg_match('#^/api/v2/directory/(clients|organizations)/bindings/revisions/commands$#D', $path, $match) !== 1) { http_response_code(404); exit; }
$type = $match[1] === 'clients' ? 'client' : 'organization'; $scope = 'directory.' . $match[1] . '.binding.revision.refresh';
if (preg_match('/^application\/json(?:\s*;\s*charset\s*=\s*utf-8)?\s*$/iD', (string)($_SERVER['CONTENT_TYPE'] ?? '')) !== 1) { http_response_code(415); exit; }
$length = $_SERVER['CONTENT_LENGTH'] ?? null; if ($length !== null && (!ctype_digit((string)$length) || (int)$length > 32 * 1024)) { http_response_code(413); exit; }
$stream = fopen('php://input', 'rb'); if ($stream === false) { http_response_code(400); exit; } $body = stream_get_contents($stream, 32 * 1024 + 1); fclose($stream);
if (!is_string($body) || strlen($body) > 32 * 1024) { http_response_code(413); exit; } $command = api_v2_directory_binding_revision_refresh_parse($body); if ($command === null) { http_response_code(400); exit; }
try { $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('MYSQL_DATABASE') ?: 'project_alpha') . ';charset=utf8mb4', getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); } catch (Throwable $error) { error_log('[ApiV2DirectoryBindingRefresh] database unavailable: ' . get_class($error)); http_response_code(503); exit; }
require_once __DIR__ . '/../../utils/api_auth.php'; $key = api_require_key(['api.capabilities.read', $scope], false); if (in_array('full', api_normalize_scopes($key['scopes'] ?? ''), true)) { http_response_code(403); exit; }
try { $outcome = api_v2_directory_binding_revision_refresh_write($pdo, $type, $command, (int)$key['id'], ['source' => $_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID'] ?? null, 'application' => $_SERVER['HTTP_X_PA_APPLICATION_ID'] ?? null, 'epoch' => $_SERVER['HTTP_X_PA_HISTORY_EPOCH'] ?? null], $requestId); if ($outcome['status'] !== 200) { http_response_code($outcome['status']); exit; } echo json_encode($outcome['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); } catch (Throwable $error) { error_log('[ApiV2DirectoryBindingRefresh] command unavailable: ' . get_class($error)); http_response_code(503); }
