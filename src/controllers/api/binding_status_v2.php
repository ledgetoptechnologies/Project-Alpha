<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') { header('Allow: GET'); http_response_code(405); exit; }
define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../../utils/api_v2_capabilities.php';
require_once __DIR__ . '/../../utils/api_v2_binding_status.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';
$requestId = api_v2_uuid(); header('X-Request-ID: ' . $requestId);
$route = api_v2_binding_status_route((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
if ($route === null || ($scope = api_v2_binding_status_scope($route['kind'])) === null) { http_response_code(404); exit; }
try {
    $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('MYSQL_DATABASE') ?: 'project_alpha') . ';charset=utf8mb4', getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $error) { error_log('[ApiV2BindingStatus] database unavailable: ' . get_class($error)); http_response_code(503); exit; }
require_once __DIR__ . '/../../utils/api_auth.php';
$key = api_require_key(['api.capabilities.read', $scope], false);
try {
    $result = api_v2_binding_status_read($pdo, $route['kind'], $route['externalId'], (int)$key['id'], ['source' => $_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID'] ?? null, 'application' => $_SERVER['HTTP_X_PA_APPLICATION_ID'] ?? null, 'epoch' => $_SERVER['HTTP_X_PA_HISTORY_EPOCH'] ?? null], $requestId);
    if ($result['status'] !== 200) { http_response_code($result['status']); exit; }
    echo json_encode($result['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) { error_log('[ApiV2BindingStatus] ' . get_class($error)); http_response_code(503); }
