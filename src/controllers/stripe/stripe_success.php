<?php
// src/controllers/stripe/stripe_success.php
// Handles successful Stripe payment redirect

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/stripe_success_policy.php';

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$sessionId = isset($_GET['session_id']) ? (string)$_GET['session_id'] : '';

$brandName = $appConfig['brand_name'] ?? 'Project Alpha';
$documentLabel = 'Unknown';
$paymentVerified = false;
$linkValid = false;

if ($token === '' || $sessionId === '') {
    http_response_code(400);
    echo '<main><div style="max-width:500px;margin:60px auto;padding:20px;text-align:center;"><h1>Invalid Request</h1><p>Missing payment information.</p></div></main>';
    exit;
}

try {
    // Validate token
    $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $linkRow = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!pa_stripe_success_link_valid($linkRow, time())) {
        throw new Exception('Invalid payment link');
    }
    $linkValid = true;
    $isProjectInvoice = $linkRow['document_type'] === 'project_invoice';
    
    $invoiceId = (int)$linkRow['document_id'];
    
    // Get invoice details
    $invSt = $pdo->prepare('SELECT * FROM ' . ($isProjectInvoice ? 'project_invoices' : 'invoices') . ' WHERE id=?');
    $invSt->execute([$invoiceId]);
    $invoice = $invSt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    $documentLabel = $isProjectInvoice
        ? 'PI-' . ($invoice['doc_number'] ?? $invoiceId)
        : pa_invoice_label_from_row($invoice + ['id' => $invoiceId]);

    // Verify the paid Checkout Session with Stripe before claiming success.
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new RuntimeException('Stripe verification is unavailable.');
    }
    $session = $stripe->getCheckoutSession($sessionId);
    $paymentVerified = pa_stripe_success_session_verified($session, $invoice, (string)$linkRow['document_type'], $invoiceId, $token, $sessionId);
    
} catch (Throwable $e) {
    @error_log('[StripeSuccess] Error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $paymentVerified ? 'Payment Confirmed' : 'Payment Status Pending'; ?> - <?php echo htmlspecialchars($brandName); ?></title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f8fafc;
            margin: 0; 
            padding: 20px;
            min-height: 100vh;
        }
        body.verified { background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); }
        .success-wrap { 
            max-width: 500px; 
            margin: 60px auto; 
            padding: 40px; 
            background: #fff; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.08); 
            text-align: center; 
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .success-icon svg {
            width: 40px;
            height: 40px;
            stroke: white;
            stroke-width: 3;
            fill: none;
        }
        h1 { 
            color: #334155;
            font-size: 28px; 
            margin: 0 0 12px; 
        }
        body.verified h1 { color: #065f46; }
        .subtitle {
            color: #6b7280;
            font-size: 16px;
            margin: 0 0 24px;
        }
        .invoice-ref {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin: 0 0 24px;
        }
        .invoice-ref strong {
            color: #374151;
        }
        p { 
            color: #4b5563; 
            line-height: 1.6; 
            margin: 0 0 20px; 
        }
        .back-link { 
            display: inline-block; 
            padding: 12px 24px; 
            background: #10b981; 
            color: #fff; 
            text-decoration: none; 
            border-radius: 8px; 
            font-weight: 600;
            transition: background 0.2s;
        }
        .back-link:hover { 
            background: #059669; 
        }
        .note {
            margin-top: 24px;
            padding: 16px;
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            font-size: 14px;
            color: #92400e;
        }
    </style>
</head>
<body class="<?php echo $paymentVerified ? 'verified' : 'pending'; ?>">
    <div class="success-wrap">
        <?php if ($paymentVerified): ?><div class="success-icon">
            <svg viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div><?php endif; ?>
        <h1><?php echo $paymentVerified ? 'Payment Confirmed' : 'Payment Not Yet Confirmed'; ?></h1>
        <p class="subtitle"><?php echo $paymentVerified ? 'Thank you for your payment.' : 'We cannot confirm this payment yet.'; ?></p>
        
        <div class="invoice-ref">
            <strong>Invoice <?php echo htmlspecialchars($documentLabel); ?></strong>
        </div>
        
        <p><?php echo $paymentVerified
            ? 'Stripe confirmed the payment. The invoice will update as soon as reconciliation finishes.'
            : 'Please check the invoice status before trying another payment. If it does not update, contact us.'; ?></p>
        
        <?php if ($linkValid): ?>
        <a href="/?page=public-doc&token=<?php echo htmlspecialchars(rawurlencode($token)); ?>" class="back-link">View Invoice</a>
        <?php endif; ?>
        
        <div class="note">
            <strong>Note:</strong> If the invoice does not update shortly, please contact us before attempting another payment.
        </div>
    </div>
</body>
<script>
    // Keep unverified returns visible so the user sees the pending state.
    if (<?php echo $paymentVerified ? 'true' : 'false'; ?> && window.opener && window.opener !== window) {
        window.close();
    }
</script>
</html>
