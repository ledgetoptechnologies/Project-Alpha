<?php
declare(strict_types=1);

// Invoked before the interactive session, CORS, CSRF, and page router.
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
$requestId = api_v2_uuid();
header('X-Request-ID: ' . $requestId);
// The ordinary db.php emits connection details on failure; this route must not.
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
    error_log('[ApiV2Capabilities] database unavailable: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['error' => 'Capability identity unavailable']);
    exit;
}
require_once __DIR__ . '/../../utils/api_auth.php';

$key = api_require_key(['api.capabilities.read'], false);
try {
    $stmt = $pdo->prepare(
        'SELECT history.source_instance_id, history.history_epoch, app.application_id
         FROM api_keys AS api_key
         INNER JOIN api_v2_applications AS app ON app.id = api_key.api_v2_application_id
         INNER JOIN api_v2_history_identity AS history ON history.singleton = 1
         WHERE api_key.id = ? AND api_key.revoked_at IS NULL'
    );
    $stmt->execute([(int)$key['id']]);
    $identity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$identity) {
        http_response_code(403);
        echo json_encode(['error' => 'Application binding required']);
        exit;
    }
    if (!api_v2_identity_is_valid($identity)) {
        throw new RuntimeException('Invalid provisioned API v2 identity');
    }
    $features = [
        'directory_read' => api_v2_enabled('APP_API_V2_DIRECTORY_READ_ENABLED'),
        'binding_status' => api_v2_enabled('APP_API_V2_BINDING_STATUS_ENABLED'),
        'directory_binding' => api_v2_enabled('APP_API_V2_DIRECTORY_BINDING_ENABLED'),
        'directory_binding_refresh' => api_v2_enabled('APP_API_V2_DIRECTORY_BINDING_REFRESH_ENABLED'),
        'directory_organization_write' => api_v2_enabled('APP_API_V2_DIRECTORY_ORGANIZATIONS_WRITE_ENABLED'),
        'directory_client_write' => api_v2_enabled('APP_API_V2_DIRECTORY_CLIENTS_WRITE_ENABLED'),
        'directory_organization_create' => api_v2_enabled('APP_API_V2_DIRECTORY_ORGANIZATIONS_CREATE_ENABLED'),
        'directory_client_create' => api_v2_enabled('APP_API_V2_DIRECTORY_CLIENTS_CREATE_ENABLED'),
        'directory_client_archive' => api_v2_enabled('APP_API_V2_DIRECTORY_CLIENTS_ARCHIVE_ENABLED'),
        'directory_client_restore' => api_v2_enabled('APP_API_V2_DIRECTORY_CLIENTS_RESTORE_ENABLED'),
        'directory_organization_archive' => api_v2_enabled('APP_API_V2_DIRECTORY_ORGANIZATIONS_ARCHIVE_ENABLED'),
        'directory_organization_restore' => api_v2_enabled('APP_API_V2_DIRECTORY_ORGANIZATIONS_RESTORE_ENABLED'),
        'directory_relationship_write' => api_v2_enabled('APP_API_V2_DIRECTORY_RELATIONSHIPS_WRITE_ENABLED'),
        'directory_binding_revoke' => api_v2_enabled('APP_API_V2_DIRECTORY_BINDING_REVOKE_ENABLED'),
        'directory_inventory' => api_v2_enabled('APP_API_V2_DIRECTORY_INVENTORY_ENABLED'),
    ];
    echo json_encode(api_v2_capabilities_payload($identity, $requestId, api_normalize_scopes($key['scopes'] ?? ''), $features), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('[ApiV2Capabilities] ' . get_class($error));
    http_response_code(503);
    echo json_encode(['error' => 'Capability identity unavailable']);
}
