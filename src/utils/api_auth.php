<?php
// src/utils/api_auth.php
if (!defined('PA_STATELESS_API_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../config/db.php';
}
require_once __DIR__ . '/api_keys_schema.php';
require_once __DIR__ . '/api_scopes.php';
require_once __DIR__ . '/api_ip_allowlist.php';
require_once __DIR__ . '/api_response.php';

function api_json_error(int $code, string $msg): void {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function api_get_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($hdr && stripos($hdr, 'bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    $alt = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($alt) return trim($alt);
    return null;
}

require_once __DIR__ . '/client_ip.php';

function api_get_client_ip(): string {
    return get_client_ip();
}

function api_require_key(
    array $requiredScopes = [],
    bool $allowFullScope = true,
    array $integrationAudit = []
) {
    global $pdo;
    $auditDeny=static function(int$status,string$code,string$message,int$apiKeyId=0)use($pdo,$integrationAudit):never{
        if($integrationAudit!==[]){
            try{(new \App\Services\PortalIntegrationAuditService())->recordCommand($pdo,(string)($integrationAudit['application_key']??''),$apiKeyId,(string)($integrationAudit['capability']??'integration.auth'),'denied',api_request_id(),$code);}
            catch(Throwable$error){error_log('[PortalIntegrationAuth]['.api_request_id().'] audit='.get_class($error));header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');http_response_code(503);echo json_encode(['code'=>'AUDIT_UNAVAILABLE']);exit;}
            header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');http_response_code($status);echo json_encode(['code'=>$code]);exit;
        }
        api_json_error($status,$message);exit;
    };
    $token = api_get_token();
    if(!$token)$auditDeny(401,'AUTHENTICATION_DENIED','Missing API key');
    $hash = hash('sha256', $token);
    try {
        pa_ensure_api_keys_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM api_keys WHERE key_hash=? AND (revoked_at IS NULL)');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if(!$row)$auditDeny(401,'AUTHENTICATION_DENIED','Invalid API key');
        // Optional IP allowlist
        $ip = get_client_ip();
        if (trim((string)($row['allowed_ips'] ?? '')) !== '') {
            $ips = api_parse_exact_ip_allowlist($row['allowed_ips']);
            if($ips===[]||!api_ip_matches_exact_allowlist($ip,$ips))$auditDeny(403,'SOURCE_DENIED','IP not allowed',(int)$row['id']);
        }
        // Scope check (simple CSV/JSON list in column)
        if ($requiredScopes) {
            foreach ($requiredScopes as $need) {
                if (!api_key_has_scope($row['scopes'] ?? '', (string)$need, $allowFullScope)) {
                    $auditDeny(403,'SCOPE_DENIED','Insufficient API scope: '.(string)$need,(int)$row['id']);
                }
            }
        }
        // Rate limit per key (per-minute)
        $limit = (int)(getenv('API_RATE_LIMIT_PER_MIN') ?: 60);
        if ($limit < 1) $limit = 60;
        $since = date('Y-m-d H:i:s', time() - 60);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM api_usage WHERE api_key_id=? AND used_at>=?');
        $cnt->execute([(int)$row['id'], $since]);
        if((int)$cnt->fetchColumn()>=$limit)$auditDeny(429,'RATE_LIMITED','Rate limit exceeded',(int)$row['id']);
        // Record usage and touch last_used
        try {
            $pdo->prepare('INSERT INTO api_usage (api_key_id) VALUES (?)')->execute([(int)$row['id']]);
            $pdo->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        } catch (Throwable $e) {}
        $GLOBALS['pa_api_key'] = $row;
        // API keys are explicit service principals. They never synthesize an
        // administrator user session, so future write-capable APIs cannot
        // accidentally inherit interactive-admin authorization.
        $servicePrincipal = [
            'type' => 'api_key',
            'api_key_id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? $row['key_prefix'] ?? 'external'),
            'scopes' => api_normalize_scopes($row['scopes'] ?? ''),
        ];
        $GLOBALS['pa_service_principal'] = $servicePrincipal;
        return $row;
    }catch(Throwable$e){
        if($integrationAudit!==[])$auditDeny(503,'AUTHENTICATION_UNAVAILABLE','API auth error');
        if (defined('PA_STATELESS_API_NO_SESSION') && PA_STATELESS_API_NO_SESSION) {
            api_json_error(503, 'API auth unavailable');
        }
        api_json_error(500,'API auth error');
    }
}
