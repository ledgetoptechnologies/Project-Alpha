<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/project_files.php';
require_once __DIR__ . '/../../utils/public_project_links.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$project = pa_project_public_resolve($pdo, $token);

function public_project_fail(string $title, string $message, int $status = 404): never
{
    http_response_code($status);
    echo '<main style="max-width:720px;margin:48px auto;padding:24px;font-family:system-ui,sans-serif">';
    echo '<h1 style="margin:0 0 10px">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
    echo '<p style="color:#4b5563;line-height:1.5">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '</main>';
    exit;
}

if (!$project) {
    public_project_fail('Project link unavailable', 'This project link is not active or does not exist.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'unlock') {
    if (!csrf_validate()) {
        header('Location: /?page=public-project&token=' . rawurlencode($token) . '&error=' . rawurlencode('Invalid request.'));
        exit;
    }
    if (!rate_limit_check($pdo, 'public_project_unlock_' . hash('sha256', $token), 10, 60)) {
        header('Location: /?page=public-project&token=' . rawurlencode($token) . '&error=' . rawurlencode('Too many attempts. Please wait a minute and try again.'));
        exit;
    }
    $code = (string)($_POST['access_code'] ?? '');
    $hash = (string)($project['public_project_password_hash'] ?? '');
    if ($hash !== '' && password_verify($code, $hash)) {
        pa_project_public_mark_unlocked($token);
        header('Location: /?page=public-project&token=' . rawurlencode($token));
        exit;
    }
    header('Location: /?page=public-project&token=' . rawurlencode($token) . '&error=' . rawurlencode('That access code did not match.'));
    exit;
}

if (!pa_project_public_is_unlocked($project, $token)) {
    $error = (string)($_GET['error'] ?? '');
    ?>
    <main style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:system-ui,sans-serif;background:#f8fafc">
      <form method="post" action="/?page=public-project" style="width:min(420px,100%);display:grid;gap:14px;background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:22px;box-shadow:0 8px 24px rgba(15,23,42,.08)">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="unlock">
        <div>
          <h1 style="font-size:22px;margin:0 0 6px">Project access</h1>
          <p style="margin:0;color:#64748b;font-size:14px">Enter the access code for <?php echo htmlspecialchars((string)$project['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>.</p>
        </div>
        <?php if ($error !== ''): ?><div style="padding:10px;border:1px solid #fecaca;background:#fff1f2;border-radius:8px;color:#991b1b;font-size:13px"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
        <label style="display:grid;gap:6px;font-size:13px;font-weight:700">
          Access code
          <input type="password" name="access_code" required autofocus style="padding:11px;border:1px solid #cfd5dc;border-radius:8px;font-size:15px">
        </label>
        <button type="submit" style="padding:11px 14px;border:0;border-radius:8px;background:#111827;color:#fff;font-weight:700;cursor:pointer">Open project</button>
      </form>
    </main>
    <?php
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'request_change') {
    if (!csrf_validate()) {
        header('Location: /?page=public-project&token=' . rawurlencode($token) . '&error=' . rawurlencode('Invalid request.'));
        exit;
    }
    if (empty($project['public_project_can_request_changes'])) {
        header('Location: /?page=public-project&token=' . rawurlencode($token) . '&error=' . rawurlencode('Update requests are not enabled for this link.'));
        exit;
    }
    if (!rate_limit_check($pdo, 'public_project_request_' . hash('sha256', $token), 12, 60)) {
        header('Location: /?page=public-project&token=' . rawurlencode($token) . '&error=' . rawurlencode('Too many requests. Please wait a minute and try again.'));
        exit;
    }
    $message = trim((string)($_POST['message'] ?? ''));
    $clientLabel = trim((string)($_POST['client_label'] ?? ''));
    if ($message === '') {
        header('Location: /?page=public-project&token=' . rawurlencode($token) . '&error=' . rawurlencode('Enter an update request before sending.'));
        exit;
    }
    pa_project_public_log_event($pdo, (int)$project['id'], 'request_change', mb_substr($message, 0, 4000), null, mb_substr($clientLabel, 0, 190));
    header('Location: /?page=public-project&token=' . rawurlencode($token) . '&sent=1');
    exit;
}

$projectId = (int)$project['id'];
$files = [];
$quotes = [];
$contracts = [];
$invoices = [];
$projectInvoices = [];

if (!empty($project['public_project_can_view_documents'])) {
    $fileStmt = $pdo->prepare('SELECT * FROM project_files WHERE project_id = ? ORDER BY uploaded_at DESC, id DESC LIMIT 100');
    $fileStmt->execute([$projectId]);
    $files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

    $quoteStmt = $pdo->prepare('SELECT id, doc_number, status, total, created_at FROM quotes WHERE project_id = ? ORDER BY created_at DESC LIMIT 50');
    $quoteStmt->execute([$projectId]);
    $quotes = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);

    $contractStmt = $pdo->prepare('SELECT id, doc_number, status, total, created_at FROM contracts WHERE project_id = ? ORDER BY created_at DESC LIMIT 50');
    $contractStmt->execute([$projectId]);
    $contracts = $contractStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!empty($project['public_project_can_view_invoices'])) {
    $invoiceStmt = $pdo->prepare('
        SELECT i.id, i.doc_number, i.invoice_type, i.status, i.total, i.amount_paid, i.balance_due, i.created_at
        FROM invoices i
        LEFT JOIN project_invoice_items pii ON pii.invoice_id = i.id
        WHERE i.project_id = ?
          AND COALESCE(i.collection_mode, "direct") = "direct"
          AND pii.invoice_id IS NULL
        ORDER BY i.created_at DESC
        LIMIT 50
    ');
    $invoiceStmt->execute([$projectId]);
    $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

    $projectInvoiceStmt = $pdo->prepare('
        SELECT id, status, total, amount_paid, balance_due, billing_period_start, billing_period_end, generated_at
        FROM project_invoices
        WHERE project_id = ?
        ORDER BY billing_period_end DESC, id DESC
        LIMIT 50
    ');
    $projectInvoiceStmt->execute([$projectId]);
    $projectInvoices = $projectInvoiceStmt->fetchAll(PDO::FETCH_ASSOC);
}

$notice = !empty($_GET['sent']) ? 'Your update request was sent.' : (!empty($_GET['uploaded']) ? 'Your file was uploaded.' : '');
$error = (string)($_GET['error'] ?? '');

$renderDocRow = static function (PDO $pdo, array $appConfig, string $type, array $row, string $label): void {
    $id = (int)$row['id'];
    $url = pa_project_public_document_url($pdo, $type, $id, $appConfig);
    $documentNumber = $type === 'invoice'
        ? pa_invoice_label_from_row($row)
        : ($type === 'project_invoice'
            ? (string)($row['doc_number'] ?? $id)
            : '#' . (string)($row['doc_number'] ?? $id));
    ?>
    <div class="pp-row">
      <div>
        <div class="pp-row-title"><?php echo htmlspecialchars($label . ' ' . $documentNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <div class="pp-muted"><?php echo htmlspecialchars(ucfirst((string)($row['status'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> · <?php echo htmlspecialchars(date('M j, Y', strtotime((string)($row['created_at'] ?? $row['generated_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="pp-row-actions">
        <span class="pp-money">$<?php echo number_format((float)($row['total'] ?? 0), 2); ?></span>
        <?php if ($url): ?><a class="pp-btn pp-btn-small" href="<?php echo htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener">Open</a><?php endif; ?>
      </div>
    </div>
    <?php
};
?>

<main class="pp-page">
  <style>
    .pp-page{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8fafc;color:#111827;min-height:100vh;padding:24px}.pp-shell{max-width:1120px;margin:0 auto;display:grid;gap:18px}.pp-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:20px}.pp-title{margin:0;font-size:28px;line-height:1.2}.pp-muted{color:#64748b;font-size:13px}.pp-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start}.pp-panel{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:18px}.pp-panel h2{font-size:17px;margin:0 0 12px}.pp-list{display:grid;gap:9px}.pp-row{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #e5e7eb;border-radius:8px;padding:11px;background:#fbfcfd}.pp-row-title{font-weight:750}.pp-row-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}.pp-money{font-weight:750;white-space:nowrap}.pp-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 13px;border-radius:8px;background:#111827;color:#fff;text-decoration:none;border:0;font-weight:750;cursor:pointer}.pp-btn-small{padding:7px 10px;font-size:13px}.pp-field{display:grid;gap:6px;font-size:13px;font-weight:700}.pp-field input,.pp-field textarea{width:100%;padding:10px;border:1px solid #cfd5dc;border-radius:8px;font:inherit}.pp-alert{padding:10px 12px;border-radius:8px;font-size:13px}.pp-alert-ok{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534}.pp-alert-error{border:1px solid #fecaca;background:#fff1f2;color:#991b1b}.pp-empty{padding:18px;border:1px dashed #d1d5db;border-radius:8px;color:#64748b;text-align:center}@media(max-width:860px){.pp-grid{grid-template-columns:1fr}.pp-header,.pp-row{display:grid}.pp-row-actions{justify-content:flex-start}.pp-page{padding:16px}}
  </style>
  <div class="pp-shell">
    <section class="pp-header">
      <div>
        <h1 class="pp-title"><?php echo htmlspecialchars((string)$project['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h1>
        <div class="pp-muted">
          <?php echo htmlspecialchars((string)($project['organization_name'] ?: $project['client_name'] ?: 'Project'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          <?php if (!empty($project['department_name'])): ?> · <?php echo htmlspecialchars((string)$project['department_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><?php endif; ?>
        </div>
      </div>
      <div class="pp-muted">Status: <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst((string)$project['status'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
    </section>
    <?php if ($notice !== ''): ?><div class="pp-alert pp-alert-ok"><?php echo htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="pp-alert pp-alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
    <div class="pp-grid">
      <div class="pp-list">
        <?php if (!empty($project['public_project_can_view_documents'])): ?>
          <section class="pp-panel">
            <h2>Project Docs</h2>
            <div class="pp-list">
              <?php foreach ($quotes as $quote): $renderDocRow($pdo, $appConfig ?? [], 'quote', $quote, 'Quote'); endforeach; ?>
              <?php foreach ($contracts as $contract): $renderDocRow($pdo, $appConfig ?? [], 'contract', $contract, 'Contract'); endforeach; ?>
              <?php if (empty($quotes) && empty($contracts)): ?><div class="pp-empty">No project quotes or contracts are available.</div><?php endif; ?>
            </div>
          </section>
          <section class="pp-panel">
            <h2>Project Files</h2>
            <div class="pp-list">
              <?php foreach ($files as $file): ?>
                <?php $fileId = (int)$file['id']; $fileName = (string)($file['display_name'] ?: $file['original_name']); ?>
                <div class="pp-row">
                  <div>
                    <div class="pp-row-title"><?php echo htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <div class="pp-muted"><?php echo htmlspecialchars(project_files_format_size((int)($file['file_size'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?> · Uploaded <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$file['uploaded_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                  </div>
                  <div class="pp-row-actions">
                    <a class="pp-btn pp-btn-small" href="/?page=public-project-file&amp;token=<?php echo htmlspecialchars(rawurlencode($token), ENT_QUOTES, 'UTF-8'); ?>&amp;id=<?php echo $fileId; ?>" target="_blank" rel="noopener">Open</a>
                    <a class="pp-btn pp-btn-small" href="/?page=public-project-file&amp;token=<?php echo htmlspecialchars(rawurlencode($token), ENT_QUOTES, 'UTF-8'); ?>&amp;id=<?php echo $fileId; ?>&amp;download=1">Download</a>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (empty($files)): ?><div class="pp-empty">No project files are available.</div><?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
        <?php if (!empty($project['public_project_can_view_invoices'])): ?>
          <section class="pp-panel">
            <h2>Invoices</h2>
            <div class="pp-list">
              <?php foreach ($projectInvoices as $projectInvoice): $renderDocRow($pdo, $appConfig ?? [], 'project_invoice', $projectInvoice + ['doc_number' => 'PI-' . (int)$projectInvoice['id']], 'Project Invoice'); endforeach; ?>
              <?php foreach ($invoices as $invoice): $renderDocRow($pdo, $appConfig ?? [], 'invoice', $invoice, 'Invoice'); endforeach; ?>
              <?php if (empty($projectInvoices) && empty($invoices)): ?><div class="pp-empty">No project invoices are available.</div><?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
      </div>
      <aside class="pp-list">
        <?php if (!empty($project['public_project_can_upload'])): ?>
          <section class="pp-panel">
            <h2>Upload</h2>
            <form method="post" action="/?page=public-project-upload" enctype="multipart/form-data" class="pp-list">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
              <label class="pp-field">Your name or email <input name="client_label" maxlength="190" placeholder="Optional"></label>
              <label class="pp-field">File <input type="file" name="project_file" required></label>
              <button type="submit" class="pp-btn">Upload file</button>
            </form>
          </section>
        <?php endif; ?>
        <?php if (!empty($project['public_project_can_request_changes'])): ?>
          <section class="pp-panel">
            <h2>Request Update</h2>
            <form method="post" action="/?page=public-project" class="pp-list">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="action" value="request_change">
              <label class="pp-field">Your name or email <input name="client_label" maxlength="190" placeholder="Optional"></label>
              <label class="pp-field">Update request <textarea name="message" rows="5" required></textarea></label>
              <button type="submit" class="pp-btn">Send request</button>
            </form>
          </section>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</main>
