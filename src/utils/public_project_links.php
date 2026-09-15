<?php

declare(strict_types=1);

require_once __DIR__ . '/public_links.php';

function pa_project_public_link_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$key] = false;
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = $stmt->fetchColumn() !== false;
        return $cache[$key];
    } catch (Throwable $e) {
        $cache[$key] = false;
        return false;
    }
}

function pa_project_public_link_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'public_project_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'public_project_token' => 'VARCHAR(64) NULL',
        'public_project_require_password' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'public_project_password_hash' => 'VARCHAR(255) NULL',
        'public_project_can_view_documents' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'public_project_can_view_invoices' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'public_project_can_upload' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'public_project_can_request_changes' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];

    foreach ($columns as $column => $definition) {
        if (pa_project_public_link_has_column($pdo, 'projects', $column)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE projects ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
        }
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX uq_projects_public_project_token ON projects (public_project_token)');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS project_public_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                message TEXT NULL,
                file_id INT NULL,
                client_label VARCHAR(190) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_project_public_events_project (project_id),
                INDEX idx_project_public_events_type (event_type),
                CONSTRAINT fk_project_public_events_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_project_public_events_file FOREIGN KEY (file_id) REFERENCES project_files(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    $done = true;
}

function pa_project_public_token(): string
{
    return bin2hex(random_bytes(24));
}

function pa_project_public_base_url(array $appConfig): string
{
    $configured = trim((string)($appConfig['app_host'] ?? ''));
    if ($configured !== '') {
        return rtrim(preg_match('#^https?://#i', $configured) ? $configured : 'https://' . $configured, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return 'http://localhost';
    }
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && str_starts_with(strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']), 'https')) {
        $scheme = 'https';
    }
    return $scheme . '://' . rtrim($host, '/');
}

function pa_project_public_url(array $appConfig, ?string $token): string
{
    if (!$token) {
        return '';
    }
    return pa_project_public_base_url($appConfig) . '/?page=public-project&token=' . rawurlencode($token);
}

function pa_project_public_resolve(PDO $pdo, string $token): ?array
{
    pa_project_public_link_ensure_schema($pdo);
    if ($token === '' || !preg_match('/^[A-Fa-f0-9]{32,64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT p.*, c.name AS client_name, o.name AS organization_name, od.name AS department_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN organizations o ON o.id = p.organization_id
        LEFT JOIN organization_departments od ON od.id = p.department_id
        WHERE p.public_project_token = ? AND p.public_project_enabled = 1
          AND p.archived_at IS NULL AND p.portal_publish_enabled = 1
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    return $project ?: null;
}

function pa_project_public_session_key(string $token): string
{
    return 'public_project_unlocked_' . hash('sha256', $token);
}

function pa_project_public_requires_password(array $project): bool
{
    return !empty($project['public_project_require_password']) && trim((string)($project['public_project_password_hash'] ?? '')) !== '';
}

function pa_project_public_is_unlocked(array $project, string $token): bool
{
    if (!pa_project_public_requires_password($project)) {
        return true;
    }
    return !empty($_SESSION[pa_project_public_session_key($token)]);
}

function pa_project_public_mark_unlocked(string $token): void
{
    $_SESSION[pa_project_public_session_key($token)] = 1;
}

function pa_project_public_badge_html(array $project, array $appConfig): string
{
    $enabled = !empty($project['public_project_enabled']);
    $token = (string)($project['public_project_token'] ?? '');
    if (!$enabled) {
        return '<span class="badge" style="background:#f3f4f6;color:#6b7280">Public project link off</span>';
    }
    $url = pa_project_public_url($appConfig, $token);
    return '<a class="badge" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener" style="background:#dcfce7;color:#166534;text-decoration:none">Public project link live</a>';
}

function pa_project_public_document_url(PDO $pdo, string $type, int $id, array $appConfig): ?string
{
    if (!in_array($type, ['quote', 'contract', 'invoice', 'project_invoice'], true) || $id <= 0) {
        return null;
    }
    $table = [
        'quote' => 'quotes',
        'contract' => 'contracts',
        'invoice' => 'invoices',
        'project_invoice' => 'project_invoices',
    ][$type];
    $extraSelect = $type === 'invoice'
        ? ', finalized_at, collection_mode'
        : ($type === 'project_invoice' ? ', finalized_at' : '');
    try {
        $docStmt = $pdo->prepare("SELECT status{$extraSelect} FROM {$table} WHERE id = ? LIMIT 1");
        $docStmt->execute([$id]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            return null;
        }
        $status = strtolower((string)($doc['status'] ?? ''));
        $collectionMode = trim((string)($doc['collection_mode'] ?? ''));
        if ($collectionMode === '') {
            $collectionMode = 'direct';
        }
        if ($type === 'quote' && $status === 'rejected') {
            return null;
        }
        if ($type === 'contract' && in_array($status, ['denied', 'cancelled', 'void'], true)) {
            return null;
        }
        if ($type === 'invoice' && ($status === 'draft' || $status === 'void' || empty($doc['finalized_at']) || $collectionMode !== 'direct')) {
            return null;
        }
        if ($type === 'project_invoice' && ($status === 'draft' || $status === 'void' || empty($doc['finalized_at']))) {
            return null;
        }
    } catch (Throwable $e) {
        return null;
    }

    pa_public_link_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT token
        FROM public_links
        WHERE document_type = ? AND document_id = ? AND revoked = 0
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$type, $id]);
    $token = (string)($stmt->fetchColumn() ?: '');
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        $expireWhenPaid = in_array($type, ['invoice', 'project_invoice'], true) ? 1 : 0;
        $ins = $pdo->prepare('
            INSERT INTO public_links (document_type, document_id, token, expires_at, expire_when_paid, revoked)
            VALUES (?, ?, ?, NULL, ?, 0)
        ');
        $ins->execute([$type, $id, $token, $expireWhenPaid]);
    }

    return pa_project_public_base_url($appConfig) . '/?page=public-doc&type=' . rawurlencode($type) . '&token=' . rawurlencode($token);
}

function pa_project_public_log_event(PDO $pdo, int $projectId, string $eventType, ?string $message = null, ?int $fileId = null, ?string $clientLabel = null): void
{
    pa_project_public_link_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare('
            INSERT INTO project_public_events (project_id, event_type, message, file_id, client_label)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$projectId, $eventType, $message, $fileId, $clientLabel]);
    } catch (Throwable $e) {
        @error_log('[public_project_links] event log failed: ' . $e->getMessage());
    }
}
