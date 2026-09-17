<?php
// src/controllers/email_send.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/smtp.php';
require_once __DIR__ . '/../utils/logger.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/email_identity.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/invoice_content_links.php';
require_once __DIR__ . '/../utils/payment_methods.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/DocumentRevisionService.php';
require_once __DIR__ . '/../utils/invoice_notifications.php';
require_once __DIR__ . '/../utils/document_pdf.php';
require_once __DIR__ . '/../utils/general_recipient_invoices.php';
require_once __DIR__ . '/../utils/public_links.php';

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$redirectTo = isset($_POST['redirect_to']) ? (string)$_POST['redirect_to'] : null;
if (!in_array($type, ['quote','contract','invoice'], true) || $id <= 0) {
  $toUrl = $redirectTo ?: '/?page=home';
  header('Location: ' . $toUrl . (strpos($toUrl,'?')!==false?'&':'?') . 'email_err=' . urlencode('Invalid email request'));
  exit;
}

$ownershipTable = ['quote' => 'quotes', 'contract' => 'contracts', 'invoice' => 'invoices'][$type];
require_record_ownership($pdo, $ownershipTable, $id);
$publicLinkId = 0;

$invoiceLabel = '';
try {
  if ($type === 'quote') {
    $st = $pdo->prepare('SELECT q.id, q.doc_number, q.project_code, q.status, q.revision_number, c.email, c.name FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Quote Q-' . $docnum . ' for ' . $clientName;
    $baseView = '/?page=quote/quote-details&id='.$id;
  } elseif ($type === 'contract') {
    $st = $pdo->prepare('SELECT co.id, co.doc_number, co.project_code, co.status, co.revision_number, c.email, c.name FROM contracts co JOIN clients c ON c.id=co.client_id WHERE co.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Contract C-' . $docnum . ' for ' . $clientName;
    $baseView = '/?page=contract/contract-details&id='.$id;
  } else { // invoice
    $st = $pdo->prepare('SELECT i.id, i.doc_number, i.invoice_type, i.project_code, i.status, i.revision_number, i.due_date, i.payment_terms_days, i.due_date_source, i.recipient_presentation_mode, c.email, c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $invoiceLabel = pa_invoice_label_from_row($row ?: ['id' => $id]);
    $subject = 'Invoice ' . $invoiceLabel . ' for ' . $clientName;
    $baseView = '/?page=invoice/invoice-details&id='.$id;
  }

  if ($type === 'invoice' && $row && pa_invoice_is_general_recipient($row)) {
    $toUrl = $redirectTo ?: $baseView;
    header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('General-recipient invoices are shared manually by public link and cannot be emailed.'));
    exit;
  }
  if (!$row || empty($row['email'])) {
    $toUrl = $redirectTo ?: $baseView;
    app_log('email', 'missing client email', ['type'=>$type, 'id'=>$id]);
    header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('No client email on file'));
    exit;
  }

  // Block emailing for non-emailable statuses
  $st = strtolower((string)($row['status'] ?? ''));
  if (($type==='quote' && $st==='rejected') || ($type==='invoice' && in_array($st, ['draft','void','cancelled'], true)) || ($type==='contract' && in_array($st, ['denied','cancelled','void'], true))) {
    $toUrl = $redirectTo ?: $baseView;
    header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('Document status does not allow emailing'));
    exit;
  }
  if ($type === 'invoice') {
    $eligibility = $pdo->prepare('SELECT finalized_at, collection_mode FROM invoices WHERE id=?');
    $eligibility->execute([$id]);
    $invoiceEligibility = $eligibility->fetch(PDO::FETCH_ASSOC);
    $collectionMode = trim((string)($invoiceEligibility['collection_mode'] ?? ''));
    if ($collectionMode === '') {
      $collectionMode = 'direct';
    }
    if (!$invoiceEligibility || empty($invoiceEligibility['finalized_at']) || $collectionMode !== 'direct') {
      $toUrl = $redirectTo ?: $baseView;
      header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('Finalize this invoice before emailing it. Project-billed invoices are sent through the project statement.'));
      exit;
    }

    if (invoice_should_prompt_for_missing_content_links($pdo, 'invoice', $id, $appConfig)) {
      $missingLinkBehavior = invoice_missing_content_links_behavior($appConfig);
      $toUrl = $redirectTo ?: $baseView;
      $join = strpos($toUrl, '?') !== false ? '&' : '?';
      if ($missingLinkBehavior === 'block') {
        header('Location: ' . $toUrl . $join . 'email_err=' . urlencode(invoice_missing_content_links_message()));
        exit;
      }
      if (empty($_POST['confirm_missing_content_links'])) {
        header('Location: ' . $toUrl . $join . 'content_link_warning=1');
        exit;
      }
    }
  }

  $to = $row['email'];

  // First name from client name
  $clientName = trim((string)($row['name'] ?? ''));
  $firstName = $clientName !== '' ? preg_split('/\s+/', $clientName)[0] : 'there';

  $includePublicLink = !empty($appConfig['public_links_in_email']);
  $absoluteUrl = '';
  if ($includePublicLink) {
  // Pending quotes/contracts keep one stable public link across revisions.
  // Invoice links stay valid until payment or manual revocation; other
  // document links keep the configured date-based expiration.
  $days = isset($appConfig['documents_valid_days']) ? (int)$appConfig['documents_valid_days'] : 14;
  if ($days <= 0) { $days = 14; }
  $expireWhenPaid = $type === 'invoice';
  $exp = $expireWhenPaid ? null : date('Y-m-d H:i:s', time() + ($days * 24 * 60 * 60));
  $publicLink = pa_public_link_reuse_or_create($pdo, $type, $id, $exp, $expireWhenPaid);
  $token = (string)$publicLink['token'];
  $publicLinkId = !empty($publicLink['created']) ? (int)$publicLink['id'] : 0;

  // Public links always use the validated configured instance origin.
  $publicUrl = '/?page=public-doc&token=' . rawurlencode($token);
  $absoluteUrl = invoice_notification_public_base($appConfig) . $publicUrl;
  }

  // Compose body
  $invoiceTerms = $type === 'invoice' ? invoice_payment_terms_text($row, $appConfig) : '';
  $body = '<p>Hello '.htmlspecialchars($firstName).',</p>' .
    '<p>Please find your document attached.</p>' .
    ($invoiceTerms !== '' ? '<p>Payment terms: '.htmlspecialchars($invoiceTerms).'</p>' : '') .
    ($absoluteUrl !== '' ? '<p><a href="'.htmlspecialchars($absoluteUrl).'">View Document</a></p>' : '');

  // Add online card payment button for invoices if Stripe is configured
  if ($type === 'invoice') {
    $contentLinks = invoice_content_links_for_invoice($pdo, $id, $appConfig);
    $contentLinksHtml = invoice_content_links_html($contentLinks);
    if ($contentLinksHtml !== '') {
      $body .= $contentLinksHtml;
    }

    require_once __DIR__ . '/../services/StripeService.php';
    if ($absoluteUrl !== '' && pa_payment_methods_has($appConfig, 'stripe') && StripeService::isConfigured($appConfig)) {
      // Check if invoice is payable
      try {
        $invSt = $pdo->prepare('SELECT status, total, amount_paid FROM invoices WHERE id = ?');
        $invSt->execute([$id]);
        $invRow = $invSt->fetch(PDO::FETCH_ASSOC);
        if ($invRow) {
          $invStatus = strtolower($invRow['status'] ?? '');
          $amountDue = (float)($invRow['total'] ?? 0) - (float)($invRow['amount_paid'] ?? 0);
          if (in_array($invStatus, ['sent', 'unpaid', 'partial', 'overdue'], true) && $amountDue > 0) {
            $payUrl = invoice_notification_public_base($appConfig) . '/?page=stripe-checkout&token=' . rawurlencode($token);
            $body .= '<div style="margin:24px 0;padding:20px;background:#f0f7ff;border:1px solid #93c5fd;border-radius:8px;text-align:center">';
            $body .= '<p style="margin:0 0 12px;color:#1e40af;font-weight:600;font-size:16px">Ready to pay? Use our secure online card payment option:</p>';
            $body .= '<a href="'.htmlspecialchars($payUrl).'" style="display:inline-block;padding:12px 24px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Pay by Card</a>';
            $body .= '<p style="margin:12px 0 0;color:#6b7280;font-size:13px">Secure payment powered by Stripe</p>';
            $body .= '</div>';
          }
        }
      } catch (Throwable $e) { /* ignore */ }
    }

    $reviewLink = trim((string)($appConfig['review_link'] ?? ''));
    if ($reviewLink !== '' && filter_var($reviewLink, FILTER_VALIDATE_URL)) {
      $body .= '<div style="margin:24px 0;padding:18px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;text-align:center">';
      $body .= '<p style="margin:0 0 10px;color:#111827;font-weight:600">Happy with the work?</p>';
      $body .= '<a href="'.htmlspecialchars($reviewLink).'" style="display:inline-block;padding:10px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Leave a Review</a>';
      $body .= '</div>';
    }
  }

  if ($absoluteUrl !== '') {
    $expiryCopy = $expireWhenPaid
      ? 'This invoice link remains active until the invoice is paid in full or manually revoked.'
      : 'This link will expire in ' . htmlspecialchars((string)$days) . ' day' . ($days===1?'':'s') . '.';
    $body .= '<p>' . $expiryCopy . ' Do not share this link with untrusted parties!</p>';
  }
  $body .= '<p>Thank you.</p>';

  // Render before sending so a missing or oversized PDF cannot be reported as delivered.
  try {
    $attachment = document_pdf_attachment($pdo, $appConfig, $type, $id, $docnum);
    if ($type === 'invoice') {
      $attachment['filename'] = 'invoice_' . $invoiceLabel . '.pdf';
    }
    $attachments = [$attachment];
  } catch (Throwable $pdfError) {
    app_log('email', 'pdf attach failed', ['type'=>$type, 'id'=>$id, 'ex'=>$pdfError->getMessage()]);
    throw new RuntimeException('PDF generation failed; document email was not sent.', 0, $pdfError);
  }
  $revision = max(1, (int)($row['revision_number'] ?? 1));
  [$sent, $err, $deliveryLogId] = EmailService::sendEmail($to, $subject, $body, [
    'attachments' => $attachments,
    'document_type' => $type,
    'document_id' => $id,
    'document_revision' => $revision,
    'message_key' => implode(':', ['document', $type, $id, $revision, strtolower((string)$to)]),
  ]);
  $toUrl = $redirectTo ?: $baseView;
  $join = (strpos($toUrl,'?')!==false)?'&':'?';
  if ($sent) {
    app_log('email', 'email sent', ['type'=>$type, 'id'=>$id, 'to'=>$to]);
    try {
      if ($err === 'Already sent') {
        throw new LogicException('This revision was already delivered to this recipient.');
      }
      DocumentRevisionService::markDelivered($pdo, $type, $id, (string)$to, $deliveryLogId ?? null);
    } catch (LogicException $alreadyTracked) {
      // Idempotent retry: the provider deliberately skipped the duplicate.
    } catch (Throwable $deliveryError) {
      app_log('email', 'document delivery tracking failed', ['type'=>$type, 'id'=>$id, 'ex'=>$deliveryError->getMessage()]);
    }
    if ($type === 'invoice') {
      try {
        $pdo->prepare('UPDATE invoices SET sent_at=COALESCE(sent_at,NOW()) WHERE id=?')->execute([$id]);
        $notifType = 'manual_email_' . date('YmdHis');
        $pdo->prepare(
          'INSERT IGNORE INTO invoice_notifications (invoice_id,notification_type,sent_at,email_to,email_subject,email_body)
           VALUES (?,?,NOW(),?,?,?)'
        )->execute([$id, $notifType, $to, $subject, $body]);
      } catch (Throwable $trackError) {
        app_log('email', 'invoice sent tracking failed', ['id'=>$id, 'ex'=>$trackError->getMessage()]);
      }
    }
  } else {
    app_log('email', 'email failed', ['type'=>$type, 'id'=>$id, 'error'=>$err]);
  }
  header('Location: ' . $toUrl . $join . ($sent ? 'emailed=1' : ('email_err=' . urlencode($err))));
  exit;
} catch (Throwable $e) {
  app_log('email', 'email exception', ['type'=>$type, 'id'=>$id, 'ex'=>$e->getMessage()]);
  $toUrl = $redirectTo ?: '/?page=home';
  $join = (strpos($toUrl,'?')!==false)?'&':'?';
  header('Location: ' . $toUrl . $join . 'email_err=' . urlencode('Email failed'));
  exit;
}
