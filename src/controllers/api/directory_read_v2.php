<?php
declare(strict_types=1);

// Must be dispatched by the front controller before session and page routing.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../../utils/api_v2_capabilities.php';
require_once __DIR__ . '/../../utils/api_v2_directory_read.php';
$requestId = api_v2_uuid();
header('X-Request-ID: ' . $requestId);
$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
if (preg_match('#^/api/v2/directory/(clients|organizations)/([0-9a-f]{32})$#D', $path, $match) !== 1) {
    http_response_code(404);
    echo json_encode(['error' => 'Resource unavailable']);
    exit;
}
$type = $match[1] === 'clients' ? 'client' : 'organization';
$scope = 'directory.' . $match[1] . '.read';
try {
    $host = getenv('DB_HOST') ?: 'db';
    $dbName = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $dbUser = getenv('MYSQL_USER') ?: 'root';
    $dbPass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass';
    $pdo = new PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $error) {
    error_log('[ApiV2Directory] database unavailable: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['error' => 'Directory unavailable']);
    exit;
}
require_once __DIR__ . '/../../utils/api_auth.php';
$key = api_require_key(['api.capabilities.read', $scope], false);
if (in_array('full', api_normalize_scopes($key['scopes'] ?? ''), true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Dedicated API v2 key required']);
    exit;
}
try {
    $result = api_v2_directory_read($pdo, $type, $match[2], (int)$key['id'], [
        'source' => $_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID'] ?? null,
        'application' => $_SERVER['HTTP_X_PA_APPLICATION_ID'] ?? null,
        'epoch' => $_SERVER['HTTP_X_PA_HISTORY_EPOCH'] ?? null,
    ], $requestId);
    if ($result === null) {
        http_response_code(409);
        echo json_encode(['error' => 'Directory state requires reconciliation']);
        exit;
    }
    $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > 64 * 1024) throw new RuntimeException('Directory response limit');
    echo $json;
} catch (Throwable $error) {
    error_log('[ApiV2Directory] read unavailable: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['error' => 'Directory unavailable']);
}
