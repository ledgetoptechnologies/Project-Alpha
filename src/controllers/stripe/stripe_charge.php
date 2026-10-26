<?php
// src/controllers/stripe/stripe_charge.php
// Creates a Stripe Checkout session for admin to process card payment
// Used when merchant wants to charge a card manually (e.g., customer on phone)

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/StripeFeeCalculator.php';
require_once __DIR__ . '/../../utils/InvoiceSurcharge.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/payment_methods.php';
require_once __DIR__ . '/../../utils/general_recipient_invoices.php';

$invoiceId = (int)($_POST['invoice_id'] ?? $_GET['invoice_id'] ?? 0);
$returnUrl = $_POST['return_url'] ?? $_GET['return_url'] ?? '';

if ($invoiceId <= 0) {
    header('Location: /?page=home&error=' . urlencode('Invalid invoice'));
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate()) {
        throw new Exception('Invalid request. Please refresh and try again.');
    }

    if (!can_access_record($pdo, 'invoices', $invoiceId, (int)($_SESSION['user']['id'] ?? 0))) {
        throw new Exception('Permission denied');
    }

    if (!pa_payment_methods_has($appConfig, 'stripe')) {
        throw new Exception('Online card payment is not enabled. Add Stripe in Settings -> Billing payment methods first.');
    }

    // Check if Stripe is configured
    if (!StripeService::isConfigured($appConfig)) {
        throw new Exception('Stripe is not configured. Please add your Stripe keys in Settings → Billing.');
    }
    
    require_once __DIR__ . '/../../utils/autopay_collection.php';
    $pdo->beginTransaction();
    // Get invoice details
    $invSt = $pdo->prepare('
        SELECT i.*, c.name as client_name, c.email as client_email 
        FROM invoices i 
        JOIN clients c ON c.id = i.client_id 
        WHERE i.id = ? FOR UPDATE
    ');
    $invSt->execute([$invoiceId]);
    $invoice = $invSt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    pa_autopay_assert_payment_available($pdo, $invoiceId);
    pa_autopay_reserve_checkout($pdo, $invoice, $appConfig);
    $pdo->commit();
    
    // Check invoice status
    $status = strtolower($invoice['status'] ?? '');
    if (!in_array($status, ['sent', 'unpaid', 'partial', 'overdue'], true)) {
        throw new Exception('This invoice cannot be charged. Status: ' . $status);
    }
    $collectionMode = trim((string)($invoice['collection_mode'] ?? ''));
    if ($collectionMode === '') {
        $collectionMode = 'direct';
    }
    if (empty($invoice['finalized_at']) || $collectionMode !== 'direct') {
        throw new Exception('This invoice is not eligible for individual online payment.');
    }
    
    // Calculate amount due from payments table for accuracy
    $total = (float)($invoice['total'] ?? 0);
    $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
    $paidStmt->execute([$invoiceId]);
    $amountPaid = (float)$paidStmt->fetchColumn();
    $amountDue = $total - $amountPaid;
    
    if ($amountDue <= 0) {
        throw new Exception('This invoice has already been paid in full.');
    }
    
    $docNumber = $invoice['doc_number'] ?? $invoiceId;
    $invoiceLabel = pa_invoice_label_from_row($invoice + ['id' => $invoiceId]);
    $checkoutRecipient = pa_invoice_is_general_recipient($invoice)
        ? 'General Recipient'
        : (string)$invoice['client_name'];

    // Calculate surcharge if applicable
    $surchargeInfo = InvoiceSurcharge::getInfo($amountDue, $appConfig);
    $surchargeAmount = $surchargeInfo['has_surcharge'] ? ($surchargeInfo['client_pays'] ?? 0) : 0;
    $chargeDescription = $surchargeInfo['has_surcharge'] 
        ? "Invoice {$invoiceLabel} - {$checkoutRecipient} (includes $" . number_format($surchargeAmount, 2) . " processing fee)"
        : "Invoice {$invoiceLabel} - {$checkoutRecipient}";
    
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
    
    $successUrl = $baseUrl . '/?page=invoice/invoice-details&id=' . $invoiceId . '&payment=success&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = $baseUrl . '/?page=invoice/invoice-details&id=' . $invoiceId . '&payment=cancelled';
    
    // Create Stripe checkout session
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Failed to initialize Stripe');
    }
    
    $brandName = $appConfig['brand_name'] ?? 'Project Alpha';
    $description = "Invoice {$invoiceLabel} - {$checkoutRecipient}";
    
    $session = $stripe->createCheckoutSessionWithSurcharge(
        $amountDue,
        $surchargeAmount,
        'usd',
        $chargeDescription,
        $successUrl,
        $cancelUrl,
        [
            'pa_invoice_id' => (string)$invoiceId,
            'invoice_id' => (string)$invoiceId,
            'doc_number' => $docNumber,
            'charged_by' => 'admin_hosted_checkout',
            'admin_user_id' => (string)($_SESSION['user']['id'] ?? ''),
            'surcharge_amount' => number_format($surchargeAmount, 2, '.', ''),
            'original_amount' => number_format($amountDue, 2, '.', ''),
            'pa_fee_policy' => (string)($surchargeInfo['surcharge_type'] ?? 'merchant'),
            'pa_invoice_amount' => number_format($amountDue, 2, '.', ''),
            'pa_surcharge_amount' => number_format($surchargeAmount, 2, '.', ''),
            'pa_fee_split_percent' => (string)($appConfig['stripe_surcharge_split_percent'] ?? '')
        ]
    );
    
    if (empty($session['url'])) {
        throw new Exception('Failed to create payment session');
    }
    $pdo->prepare('UPDATE invoices SET stripe_session_id=?,stripe_checkout_expires_at=? WHERE id=?')->execute([
        (string)$session['id'],date('Y-m-d H:i:s',(int)($session['expires_at']??(time()+86400))),$invoiceId
    ]);
    
    @error_log('[StripeCharge] Admin checkout session created for invoice ' . $invoiceId);
    
    // Redirect to Stripe Checkout
    @error_log('[StripeCharge] Redirecting to: ' . $session['url']);
    header('Location: ' . $session['url']);
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[StripeCharge] Error: ' . $e->getMessage());
    $errorUrl = $returnUrl ?: '/?page=invoice/invoice-details&id=' . $invoiceId;
    header('Location: ' . $errorUrl . '&stripe_error=' . urlencode($e->getMessage()));
    exit;
}
