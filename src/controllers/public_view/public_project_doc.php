<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/public_project_links.php';

if (!rate_limit_check($pdo, 'public_project_doc', 30, 60)) {
    http_response_code(429);
    exit('Rate limited');
}

$token = (string)($_GET['token'] ?? '');
$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
try {
    $project = pa_project_public_resolve($pdo, $token);
    if (!$project || !pa_project_public_is_unlocked($project, $token)
        || !pa_project_public_document_eligible($pdo, $project, $type, $id)) {
        throw new DomainException('Project document unavailable.');
    }

    if (!defined('PUBLIC_VIEW')) {
        define('PUBLIC_VIEW', true);
    }
    $_GET['id'] = (string)$id;
    if ($type === 'quote') {
        require __DIR__ . '/../quote/quote_pdf.php';
    } elseif ($type === 'contract') {
        require __DIR__ . '/../contract/contract_pdf.php';
    } elseif ($type === 'invoice') {
        require __DIR__ . '/../invoice/invoice_pdf.php';
    } else {
        require __DIR__ . '/../project/project_invoice_pdf.php';
    }
} catch (Throwable $error) {
    @error_log('[public_project_doc] ' . $error->getMessage());
    http_response_code(404);
    exit('Project document unavailable.');
}
