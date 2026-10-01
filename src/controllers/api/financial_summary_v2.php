<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') { header('Allow: GET'); http_response_code(405); exit; }
define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../utils/api_v2_financial_summary.php';
$requestId = api_v2_uuid();
header('X-Request-ID: ' . $requestId);

$query = [];
parse_str((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?: ''), $query);
if (array_diff(array_keys($query), ['clientExternalId', 'organizationExternalId', 'projectExternalId', 'projectPublicId', 'cursor', 'limit']) !== []) { http_response_code(400); exit; }
$clientExternalId = $query['clientExternalId'] ?? null;
$organizationExternalId = $query['organizationExternalId'] ?? null;
$projectExternalId = $query['projectExternalId'] ?? null;
$projectPublicId = $query['projectPublicId'] ?? null;
$selectors = array_filter(['client' => $clientExternalId, 'organization' => $organizationExternalId, 'project' => $projectExternalId, 'project_public' => $projectPublicId], 'is_string');
if (count($selectors) !== 1) { http_response_code(400); exit; }
$resourceType = (string)array_key_first($selectors);
$externalId = (string)$selectors[$resourceType];
$cursor = array_key_exists('cursor', $query) ? (string)$query['cursor'] : null;
$limit = (string)($query['limit'] ?? '50');
if (($resourceType === 'project_public' ? preg_match('/^[0-9a-f]{32}$/D', $externalId) !== 1 : !api_v2_project_external_id_valid($externalId)) || ($cursor !== null && !api_v2_financial_cursor_valid($cursor)) || !ctype_digit($limit) || (int)$limit < 1 || (int)$limit > 100) { http_response_code(400); exit; }

try {
    $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('MYSQL_DATABASE') ?: 'project_alpha') . ';charset=utf8mb4', getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $error) { error_log('[ApiV2FinancialSummary] database unavailable: ' . get_class($error)); http_response_code(503); exit; }
require_once __DIR__ . '/../../utils/api_auth.php';
$key = api_require_key(['api.capabilities.read', 'financial.portal_summary.read'], false);
if (in_array('full', api_normalize_scopes($key['scopes'] ?? ''), true)) { http_response_code(403); exit; }
try {
    $outcome = api_v2_financial_summary_read($pdo, $resourceType, $externalId, $cursor, (int)$limit, (int)$key['id'], ['source' => $_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID'] ?? null, 'application' => $_SERVER['HTTP_X_PA_APPLICATION_ID'] ?? null, 'epoch' => $_SERVER['HTTP_X_PA_HISTORY_EPOCH'] ?? null], $requestId);
    http_response_code($outcome['status']);
    if (isset($outcome['payload'])) {
        $json = json_encode($outcome['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 128 * 1024) throw new RuntimeException('Financial summary response limit.');
        echo $json;
    }
} catch (Throwable $error) { error_log('[ApiV2FinancialSummary] read unavailable: ' . get_class($error)); http_response_code(503); }
