<?php
// src/utils/api_auth.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/api_keys_schema.php';

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

function api_require_key(array $requiredScopes = []) {
    global $pdo;
    $token = api_get_token();
    if (!$token) api_json_error(401, 'Missing API key');
    $hash = hash('sha256', $token);
    try {
        pa_ensure_api_keys_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM api_keys WHERE key_hash=? AND (revoked_at IS NULL)');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_json_error(401, 'Invalid API key');
        // Optional IP allowlist
        $ip = get_client_ip();
        if (!empty($row['allowed_ips'])) {
            $ips = array_filter(array_map('trim', preg_split('/[\s,]+/', (string)$row['allowed_ips'])));
            if ($ips && !in_array($ip, $ips, true)) api_json_error(403, 'IP not allowed');
        }
        // Scope check (simple CSV/JSON list in column)
        if ($requiredScopes) {
            $scopes = strtolower((string)($row['scopes'] ?? 'full'));
            if ($scopes !== 'full') {
                $have = array_filter(array_map('trim', preg_split('/[\s,]+/', $scopes)));
                foreach ($requiredScopes as $need) {
                    if (!in_array(strtolower($need), $have, true)) {
                        api_json_error(403, 'Insufficient scope');
                    }
                }
            }
        }
        // Rate limit per key (per-minute)
        $limit = (int)(getenv('API_RATE_LIMIT_PER_MIN') ?: 60);
        if ($limit < 1) $limit = 60;
        $since = date('Y-m-d H:i:s', time() - 60);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM api_usage WHERE api_key_id=? AND used_at>=?');
        $cnt->execute([(int)$row['id'], $since]);
        if ((int)$cnt->fetchColumn() >= $limit) api_json_error(429, 'Rate limit exceeded');
        // Record usage and touch last_used
        try {
            $pdo->prepare('INSERT INTO api_usage (api_key_id) VALUES (?)')->execute([(int)$row['id']]);
            $pdo->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        } catch (Throwable $e) {}
        return $row;
    } catch (Throwable $e) {
        api_json_error(500, 'API auth error');
    }
}
