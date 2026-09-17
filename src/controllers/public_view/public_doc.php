<?php
// src/controllers/public_doc.php
// Render a public, tokenized view of a document without requiring auth
// TODO: We need to add more public views. One for contract, so a client can upload a signed contract via link/portal on public_contract_action. Use a mix of PHP and twig for page views.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_doc', 30, 60)) {
  http_response_code(429);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><title>Rate limited</title></head><body><h1>Rate limited</h1></body></html>';
  exit;
}
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/general_recipient_invoices.php';
require_once __DIR__ . '/../../utils/document_organization.php';

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '') {
  http_response_code(400);
  echo '<main><div class="auth-wrap"><h1>Invalid link</h1><p>This link is not valid.</p></div></main>';
  exit;
}

try {
  // First, ensure the expire_when_paid column exists (migration)
  try {
    $pdo->exec("ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Throwable $e) { /* column already exists */ }
  
  // Try query with expire_when_paid column
  try {
    $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, redirect, expire_when_paid FROM public_links WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    // Fallback query without expire_when_paid column
    $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, redirect FROM public_links WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $row['expire_when_paid'] = 0; // Default to false
    }
  }
  
  if (!$row) { throw new Exception('notfound'); }
  if (in_array((string)$row['document_type'], ['quote', 'contract', 'invoice', 'project_invoice'], true)) {
    $terminalReason = pa_public_link_terminalize($pdo, (string)$row['document_type'], (int)$row['document_id']);
    if ($terminalReason !== null) {
      $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, redirect, expire_when_paid FROM public_links WHERE token=? LIMIT 1');
      $st->execute([$token]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) { throw new Exception('notfound'); }
    }
  }

  // Legacy invoice links without any expiry may be upgraded to expire on
  // payment. Never rewrite a dated receipt link: general-recipient paid
  // invoices deliberately retain their seven-day receipt window.
  if (in_array((string)$row['document_type'], ['invoice', 'project_invoice'], true)
      && empty($row['expire_when_paid']) && empty($row['expires_at'])) {
    try {
      $pdo->exec("ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL");
      $up = $pdo->prepare('UPDATE public_links SET expire_when_paid=1, expires_at=NULL WHERE token=? AND revoked=0 AND document_type IN ("invoice","project_invoice")');
      $up->execute([$token]);
      $row['expire_when_paid'] = 1;
      $row['expires_at'] = null;
    } catch (Throwable $e) {
      // Keep serving the link with its existing expiration if the schema cannot be adjusted here.
    }
  }
  
  // Check if revoked
  if ((int)($row['revoked'] ?? 0) === 1) {
    $redirect = trim((string)($row['redirect'] ?? ''));
    $redirectExpiresAt = !empty($row['expires_at']) ? strtotime((string)$row['expires_at']) : null;
    if ($redirect !== '' && ($redirectExpiresAt === null || $redirectExpiresAt > time())) {
      header('Location: ' . $redirect);
      exit;
    }
    throw new Exception('expired');
  }
  
  // Check expiration - either by date or by payment status
  $expireWhenPaid = !empty($row['expire_when_paid']);
  if ($expireWhenPaid) {
    // For expire_when_paid links, check if the invoice is paid
    if (in_array($row['document_type'], ['invoice', 'project_invoice'], true)) {
      $table = $row['document_type'] === 'project_invoice' ? 'project_invoices' : 'invoices';
      $invCheck = $pdo->prepare("SELECT status FROM {$table} WHERE id = ?");
      $invCheck->execute([(int)$row['document_id']]);
      $invStatus = strtolower($invCheck->fetchColumn() ?: '');
      if ($invStatus === 'paid' || $invStatus === 'void') {
        throw new Exception('expired');
      }
    }
  } else {
    // For date-based expiration
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
      throw new Exception('expired');
    }
  }

  $type = (string)$row['document_type'];
  $rid = (int)$row['document_id'];

  if (!defined('PUBLIC_VIEW')) define('PUBLIC_VIEW', true);
  $_GET['id'] = (string)$rid;

  // Optional notice banners
  $notice = isset($_GET['ok']) && $_GET['ok'] === '1';
  $err = isset($_GET['error']) ? (string)$_GET['error'] : '';

  // For invoice links, ensure invoice remains in public-viewable states
  if ($type === 'invoice') {
    $vs = $pdo->prepare('SELECT status, finalized_at, collection_mode, recipient_presentation_mode, paid_at,
                                invoice_type,contract_id,project_id,job_id,service_location_id
                         FROM invoices WHERE id=? LIMIT 1');
    $vs->execute([$rid]);
    $publicInvoice = $vs->fetch(PDO::FETCH_ASSOC);
    $invStatus = strtolower((string)($publicInvoice['status'] ?? ''));
    if ($invStatus === '') {
      throw new Exception('invoice_not_found:' . $rid);
    }
    $isPaidGeneralReceipt = pa_general_recipient_public_receipt_window_open($publicInvoice);
    $isGeneralRecipientPublicInvoice = pa_invoice_is_general_recipient($publicInvoice);
    if ($isGeneralRecipientPublicInvoice
        && !pa_general_recipient_invoice_is_eligible($publicInvoice)) {
      throw new Exception('invoice_not_public');
    }
    if (!in_array($invStatus, ['sent', 'unpaid', 'partial', 'overdue'], true) && !$isPaidGeneralReceipt) {
      throw new Exception('invoice_status_blocked:' . $invStatus);
    }
    $collectionMode = trim((string)($publicInvoice['collection_mode'] ?? ''));
    if ($collectionMode === '') {
      $collectionMode = 'direct';
    }
    if (empty($publicInvoice['finalized_at']) || $collectionMode !== 'direct') {
      throw new Exception('invoice_not_public');
    }
  }
  if ($type === 'project_invoice') {
    $ps = $pdo->prepare('SELECT status, finalized_at FROM project_invoices WHERE id=? LIMIT 1');
    $ps->execute([$rid]);
    $projectInvoice = $ps->fetch(PDO::FETCH_ASSOC);
    if (!$projectInvoice || ($projectInvoice['status'] ?? '') === 'draft' || empty($projectInvoice['finalized_at'])) {
      throw new Exception('project_invoice_not_public');
    }
  }

  // Determine whether to show actions or upload form in the view
  $showActions = false; $showUpload = false;
  if ($type === 'quote') {
    try {
      $qs = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
      $qs->execute([$rid]);
      $status = (string)($qs->fetchColumn() ?: '');
      if ($status === 'pending') { $showActions = true; }
    } catch (Throwable $e) { /* ignore */ }
  }
  if ($type === 'contract') {
    try {
      $cs = $pdo->prepare('SELECT status FROM contracts WHERE id=? LIMIT 1');
      $cs->execute([$rid]);
      $cstat = (string)($cs->fetchColumn() ?: '');
      if ($cstat === 'pending') { $showUpload = true; }
    } catch (Throwable $e) { /* ignore */ }
  }

  // Prefer Twig template if available, otherwise include PHP view wrapper
  $twigTemplate = __DIR__ . '/../../views/public/doc-template.twig';
  $phpView = __DIR__ . '/../../views/public/doc-wrapper.php';
  if ($type !== 'project_invoice' && is_file($twigTemplate) && is_file(__DIR__ . '/../../vendor/autoload.php')) {
    // Attempt to render Twig if installed (non-fatal)
    try {
      require_once __DIR__ . '/../../vendor/autoload.php';
      // Code is correct, but it throws an error, ignore for now
      $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../views');
      $twig = new \Twig\Environment($loader);
      // If rendering a public quote, prepare the quote data and pass it into Twig
      $templateVars = ['type'=>$type, 'id'=>$rid, 'token'=>$token, 'notice'=>$notice, 'error'=>$err,
        'isGeneralRecipientPublicInvoice'=>$isGeneralRecipientPublicInvoice ?? false,
        'showActions'=>$showActions, 'showUpload'=>$showUpload, 'appConfig'=>$appConfig
      ];
      $templateVars['quoteCsrf'] = csrf_sf_token('public_quote_action');
      $templateVars['contractSignCsrf'] = csrf_sf_token('public_contract_sign');
      if ($type === 'quote') {
        try {
          $idParam = (int)$rid;
          $stmt = $pdo->prepare('SELECT q.*, c.name client_name, o.name AS client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal_code, c.country FROM quotes q JOIN clients c ON c.id=q.client_id' . pa_document_effective_organization_joins('q', 'c') . ' WHERE q.id=?');
          $stmt->execute([$idParam]);
          $quote = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($quote) {
            $itemsSt = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
            $itemsSt->execute([$idParam]);
            $items = $itemsSt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare sender info
            require_once __DIR__ . '/../../utils/email_identity.php';
            $fromName = pa_email_sender_name($appConfig, false);
            $fromPhone = $appConfig['from_phone'] ?? '';
            $fromEmail = $appConfig['from_email'] ?? '';

            // Resolve terms: project-level -> quote -> app
            $termsText = '';
            if (!empty($quote['project_code'])) {
              try {
                $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
                $pm->execute([$quote['project_code']]);
                $pt = (string)$pm->fetchColumn();
                if (trim($pt) !== '') { $termsText = trim($pt); }
              } catch (Throwable $_e) { /* ignore */ }
            }
            if ($termsText === '') { $termsText = trim((string)($quote['terms'] ?? '')); }
            if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }

            // Deposit calculation
            $depositType = $quote['deposit_type'] ?? 'none';
            $depositValue = (float)($quote['deposit_amount'] ?? 0);
            $quoteTotal = (float)($quote['total'] ?? 0);
            $depositCalc = 0.0;
            if ($depositType === 'percent') {
              $depositCalc = max(0, min(100, $depositValue)) * $quoteTotal / 100;
            } elseif ($depositType === 'fixed') {
              $depositCalc = $depositValue;
            }

            $fulfillmentDate = $quote['fulfillment_date'] ?? null;
            $showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
            $showFulfillmentDate = !empty($fulfillmentDate);

            $scopeText = trim((string)($quote['scope'] ?? ''));
            $scopeEnabled = !isset($appConfig['quote_scope_enabled']) || !empty($appConfig['quote_scope_enabled']);

            // Minimal logo handling: pass configured logo and let template decide how to render
            $logoConf = trim((string)($appConfig['logo_path'] ?? ''));

            $templateVars['quote'] = $quote;
            $templateVars['items'] = $items;
            $templateVars['fromName'] = $fromName;
            $templateVars['fromPhone'] = $fromPhone;
            $templateVars['fromEmail'] = $fromEmail;
            $templateVars['termsText'] = $termsText;
            $templateVars['depositCalc'] = $depositCalc;
            $templateVars['showDepositInfo'] = $showDepositInfo;
            $templateVars['showFulfillmentDate'] = $showFulfillmentDate;
            $templateVars['fulfillmentDate'] = $fulfillmentDate;
            $templateVars['scopeText'] = $scopeText;
            $templateVars['scopeEnabled'] = $scopeEnabled;
            $templateVars['logoPath'] = $logoConf;
          }
        } catch (Throwable $_e) { /* ignore and render wrapper */ }
      }

      echo $twig->render('public/doc-template.twig', $templateVars);
    } catch (Throwable $_t) {
      require $phpView;
    }
  } else {
    require $phpView;
  }
} catch (Throwable $e) {
  http_response_code(404);
  @error_log('[PublicDoc] Error: ' . $e->getMessage() . ' Token: ' . $token);
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link Expired</title>
  <style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;margin:0;padding:40px 20px;}
  .wrap{max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,0.1);}
  h1{color:#1f2937;font-size:24px;margin:0 0 12px;}p{color:#6b7280;margin:0 0 24px;line-height:1.6;}
  .icon{width:64px;height:64px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
  </style></head><body>
  <div class="wrap">
    <div class="icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    <h1>Link Expired</h1>
    <p>This link has expired or is no longer valid. Please contact us for a new link.</p>
  </div></body></html>';
  exit;
}
