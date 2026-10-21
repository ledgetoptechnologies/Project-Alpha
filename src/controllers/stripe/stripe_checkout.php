<?php
// src/controllers/stripe/stripe_checkout.php
// Initiates a Stripe Checkout Session for invoice payment via public token

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/StripeFeeCalculator.php';
require_once __DIR__ . '/../../utils/payment_methods.php';
require_once __DIR__ . '/../../utils/general_recipient_invoices.php';

header('Content-Type: text/html; charset=UTF-8');

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';

if ($token === '') {
    http_response_code(400);
    echo '<main><div style="max-width:500px;margin:60px auto;padding:20px;text-align:center;"><h1>Invalid Request</h1><p>No payment token provided.</p></div></main>';
    exit;
}

try {
    // Validate the token and get invoice info
    // Try to get expire_when_paid column
    try {
        $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, expire_when_paid FROM public_links WHERE token=? LIMIT 1');
        $st->execute([$token]);
        $linkRow = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback without expire_when_paid
        $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
        $st->execute([$token]);
        $linkRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($linkRow) {
            $linkRow['expire_when_paid'] = 0;
        }
    }
    
    if (!$linkRow) {
        throw new Exception('Link not found');
    }

    if (in_array((string)$linkRow['document_type'], ['invoice', 'project_invoice'], true) && empty($linkRow['expire_when_paid'])) {
        try {
            $pdo->exec("ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec("ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL");
            $up = $pdo->prepare('UPDATE public_links SET expire_when_paid=1, expires_at=NULL WHERE token=? AND revoked=0 AND expires_at IS NULL AND document_type IN ("invoice","project_invoice")');
            $up->execute([$token]);
            $linkRow['expire_when_paid'] = 1;
            $linkRow['expires_at'] = null;
        } catch (Throwable $e) {
            // Keep validating against the existing expiration if the schema cannot be adjusted here.
        }
    }
    
    // Check if revoked
    if ((int)($linkRow['revoked'] ?? 0) === 1) {
        throw new Exception('Link has expired');
    }
    
    // Check expiration based on type
    $expireWhenPaid = !empty($linkRow['expire_when_paid']);
    if ($expireWhenPaid) {
        // For expire_when_paid, check invoice status
        // (will be checked again below, but we need to validate the link first)
    } else {
        // Date-based expiration - only check if expires_at is set
        if (!empty($linkRow['expires_at']) && strtotime((string)$linkRow['expires_at']) < time()) {
            throw new Exception('Link has expired');
        }
    }
    
    $documentType = (string)$linkRow['document_type'];
    if (!in_array($documentType, ['invoice', 'project_invoice'], true)) {
        throw new Exception('Payment is only available for finalized invoices');
    }
    
    $invoiceId = (int)$linkRow['document_id'];
    
    if (!pa_payment_methods_has($appConfig, 'stripe')) {
        throw new Exception('Online card payment is not enabled for this invoice. Please contact us for alternative payment methods.');
    }

    // Check if Stripe is configured
    if (!StripeService::isConfigured($appConfig)) {
        throw new Exception('Online payment is not configured. Please contact us for alternative payment methods.');
    }
    
    // Get invoice details
    $invSt = $documentType === 'project_invoice'
        ? $pdo->prepare('SELECT pi.*,p.name AS project_name,p.name AS client_name,NULL AS client_email FROM project_invoices pi JOIN projects p ON p.id=pi.project_id WHERE pi.id=?')
        : $pdo->prepare('SELECT i.*,c.name AS client_name,c.email AS client_email FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?');
    $invSt->execute([$invoiceId]);
    $invoice = $invSt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    // Check invoice status
    $status = strtolower($invoice['status'] ?? '');
    if (!in_array($status, ['sent', 'unpaid', 'partial', 'overdue'], true)) {
        throw new Exception('This invoice cannot be paid online. Status: ' . $status);
    }
    if (empty($invoice['finalized_at'])) {
        throw new Exception('This invoice has not been finalized for payment.');
    }
    $collectionMode = trim((string)($invoice['collection_mode'] ?? ''));
    if ($collectionMode === '') {
        $collectionMode = 'direct';
    }
    if ($documentType === 'invoice' && $collectionMode !== 'direct') {
        throw new Exception('This invoice is collected through its project billing statement.');
    }
    if ($documentType === 'invoice' && pa_invoice_is_general_recipient($invoice)
        && !pa_general_recipient_invoice_is_eligible($invoice)) {
        throw new Exception('This general-recipient invoice is not eligible for public payment.');
    }
    
    // Calculate amount due from payments table for accuracy
    $total = (float)($invoice['total'] ?? 0);
    if ($documentType === 'project_invoice') {
        $amountPaid = (float)($invoice['amount_paid'] ?? 0);
    } else {
        $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $paidStmt->execute([$invoiceId]);
        $amountPaid = (float)$paidStmt->fetchColumn();
    }
    $amountDue = $total - $amountPaid;
    
    if ($amountDue <= 0) {
        throw new Exception('This invoice has already been paid in full.');
    }

    $surchargeInfo = StripeFeeCalculator::calculateSurcharge($amountDue, $appConfig);
    $surchargeAmount = max(0.0, (float)($surchargeInfo['client_pays'] ?? 0));
    $checkoutTotal = $amountDue + $surchargeAmount;
    
    // Build URLs
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' && !empty($appConfig['app_host'])) {
        $host = (string)$appConfig['app_host'];
    }
    if ($host === '') {
        $host = 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $host;
    
    $docNumber = $invoice['doc_number'] ?? $invoiceId;
    $documentLabel = $documentType === 'project_invoice'
        ? 'Project invoice PI-' . $docNumber
        : 'Invoice ' . pa_invoice_label_from_row($invoice + ['id' => $invoiceId]);
    $successUrl = $baseUrl . '/?page=stripe-success&token=' . rawurlencode($token) . '&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = $baseUrl . '/?page=public-doc&token=' . rawurlencode($token) . '&cancelled=1';
    
    // Create Stripe checkout session
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Failed to initialize payment service');
    }
    
    $brandName = $appConfig['brand_name'] ?? 'Project Alpha';
    $description = "{$documentLabel} from {$brandName}";

    $expectedCents = (int)round($checkoutTotal * 100);
    $existingSessionId = trim((string)($invoice['stripe_session_id'] ?? ''));
    $existingExpiresAt = !empty($invoice['stripe_checkout_expires_at'])
        ? strtotime((string)$invoice['stripe_checkout_expires_at'])
        : 0;
    if ($existingSessionId !== '' && $existingExpiresAt > time()) {
        try {
            $existing = $stripe->getCheckoutSession($existingSessionId);
            $existingInvoiceId = $documentType === 'project_invoice'
                ? (int)($existing['metadata']['pa_project_invoice_id'] ?? $existing['metadata']['project_invoice_id'] ?? 0)
                : (int)($existing['metadata']['pa_invoice_id'] ?? $existing['metadata']['invoice_id'] ?? 0);
            if (($existing['status'] ?? '') === 'open'
                && ($existing['payment_status'] ?? '') === 'unpaid'
                && (int)($existing['amount_total'] ?? 0) === $expectedCents
                && $existingInvoiceId === $invoiceId
                && !empty($existing['url'])) {
                header('Location: ' . $existing['url']);
                exit;
            }
            if (($existing['payment_status'] ?? '') === 'paid') {
                throw new Exception('This payment is already being processed. Please refresh the invoice shortly.');
            }
        } catch (Throwable $sessionError) {
            if (str_contains($sessionError->getMessage(), 'already being processed')) {
                throw $sessionError;
            }
            @error_log('[StripeCheckout] Existing session could not be reused: ' . $sessionError->getMessage());
        }
    }

    $idempotencyKey = 'pa-' . str_replace('_', '-', $documentType) . '-' . $invoiceId . '-' . $expectedCents . '-' . date('YmdH');
    $checkoutMetadata = $documentType === 'project_invoice'
        ? [
            'pa_project_invoice_id' => (string)$invoiceId,
            'project_invoice_id' => (string)$invoiceId,
            'doc_number' => (string)$docNumber,
            'token' => $token,
            'original_amount' => number_format($amountDue, 2, '.', ''),
            'surcharge_amount' => number_format($surchargeAmount, 2, '.', ''),
            'surcharge_type' => (string)($surchargeInfo['surcharge_type'] ?? 'merchant'),
            'pa_fee_policy' => (string)($surchargeInfo['surcharge_type'] ?? 'merchant'),
            'pa_invoice_amount' => number_format($amountDue, 2, '.', ''),
            'pa_surcharge_amount' => number_format($surchargeAmount, 2, '.', ''),
            'pa_fee_split_percent' => (string)($appConfig['stripe_surcharge_split_percent'] ?? ''),
        ]
        : [
            'pa_invoice_id' => (string)$invoiceId,
            'invoice_id' => (string)$invoiceId,
            'doc_number' => (string)$docNumber,
            'token' => $token,
            'original_amount' => number_format($amountDue, 2, '.', ''),
            'surcharge_amount' => number_format($surchargeAmount, 2, '.', ''),
            'surcharge_type' => (string)($surchargeInfo['surcharge_type'] ?? 'merchant'),
            'pa_fee_policy' => (string)($surchargeInfo['surcharge_type'] ?? 'merchant'),
            'pa_invoice_amount' => number_format($amountDue, 2, '.', ''),
            'pa_surcharge_amount' => number_format($surchargeAmount, 2, '.', ''),
            'pa_fee_split_percent' => (string)($appConfig['stripe_surcharge_split_percent'] ?? ''),
        ];
    
    $session = $stripe->createCheckoutSessionWithSurcharge(
        $amountDue,
        $surchargeAmount,
        'usd', // TODO: Make currency configurable
        $description,
        $successUrl,
        $cancelUrl,
        $checkoutMetadata,
        null,
        $idempotencyKey
    );
    
    if (empty($session['url'])) {
        throw new Exception('Failed to create payment session');
    }

    $expiresAt = !empty($session['expires_at'])
        ? date('Y-m-d H:i:s', (int)$session['expires_at'])
        : date('Y-m-d H:i:s', strtotime('+24 hours'));
    $checkoutTable = $documentType === 'project_invoice' ? 'project_invoices' : 'invoices';
    $pdo->prepare("UPDATE {$checkoutTable} SET stripe_session_id=?,stripe_checkout_expires_at=? WHERE id=?")
        ->execute([(string)$session['id'], $expiresAt, $invoiceId]);
    
    // Log the checkout initiation
    @error_log('[StripeCheckout] Session created for invoice ' . $invoiceId . ': ' . ($session['id'] ?? 'unknown'));
    
    // Redirect to Stripe Checkout
    header('Location: ' . $session['url']);
    exit;
    
} catch (Throwable $e) {
    @error_log('[StripeCheckout] Error: ' . $e->getMessage());
    http_response_code(400);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Error</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f9fafb; margin: 0; padding: 20px; }
            .error-wrap { max-width: 500px; margin: 60px auto; padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
            h1 { color: #dc2626; font-size: 24px; margin: 0 0 16px; }
            p { color: #4b5563; line-height: 1.6; margin: 0 0 20px; }
            .back-link { display: inline-block; padding: 10px 20px; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 500; }
            .back-link:hover { background: #2563eb; }
        </style>
    </head>
    <body>
        <div class="error-wrap">
            <h1>Payment Error</h1>
            <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
            <?php if ($token): ?>
            <a href="/?page=public-doc&token=<?php echo htmlspecialchars(rawurlencode($token)); ?>" class="back-link">Back to Invoice</a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}
