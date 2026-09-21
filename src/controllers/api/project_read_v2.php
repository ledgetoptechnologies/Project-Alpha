<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') { header('Allow: GET'); http_response_code(405); exit; }
define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../utils/api_v2_project_lifecycle.php';
$requestId = api_v2_uuid(); header('X-Request-ID: ' . $requestId);
$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (preg_match('#^/api/v2/projects/([0-9a-f]{32})$#D', $path, $match) !== 1) { http_response_code(404); exit; }
try {
    $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('MYSQL_DATABASE') ?: 'project_alpha') . ';charset=utf8mb4',
        getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (Throwable $error) { error_log('[ApiV2Project] database unavailable: ' . get_class($error)); http_response_code(503); exit; }
require_once __DIR__ . '/../../utils/api_auth.php';
$key = api_require_key(['api.capabilities.read','projects.v2.read'], false);
if (in_array('full', api_normalize_scopes($key['scopes'] ?? ''), true)) { http_response_code(403); exit; }
try {
    $payload = api_v2_project_read($pdo, $match[1], (int)$key['id'], [
        'source'=>$_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID'] ?? null,
        'application'=>$_SERVER['HTTP_X_PA_APPLICATION_ID'] ?? null,
        'epoch'=>$_SERVER['HTTP_X_PA_HISTORY_EPOCH'] ?? null,
    ], $requestId);
    if ($payload === null) { http_response_code(409); exit; }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) > 32 * 1024) throw new RuntimeException('Project response limit.');
    echo $json;
} catch (Throwable $error) { error_log('[ApiV2Project] read unavailable: ' . get_class($error)); http_response_code(503); }
