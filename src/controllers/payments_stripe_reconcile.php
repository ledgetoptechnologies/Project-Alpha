<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/stripe_single_payment_reconciliation.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('payments-list');
$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0 || !in_array(acl_user_role($pdo, $userId), ['admin', 'owner'], true)
    || !user_can($pdo, $userId, 'settings.manage', 0) || !user_can($pdo, $userId, 'invoices.mark_paid', 0)) {
    http_response_code(403); exit;
}
try {
    $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($paymentId === false) throw new RuntimeException('Choose an existing payment.');
    require_record_ownership($pdo, 'payments', $paymentId);
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) throw new RuntimeException('Stripe is not configured.');
    stripe_reconcile_existing_payment($pdo, $stripe, $appConfig, $paymentId, $userId);
    header('Location: /?page=payments-list&stripe_reconciled=1');
} catch (Throwable $error) {
    error_log('[stripe_single_reconciliation] ' . get_class($error));
    header('Location: /?page=payments-list&error=' . rawurlencode('Payment was not reconciled. Verify Stripe settlement, invoice identity and refund/dispute history before retrying.'));
}
exit;
