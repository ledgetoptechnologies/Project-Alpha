<?php
// src/controllers/webhook/stripe_webhooks.php
// Future-proof webhook router for Stripe events
// Routes events to appropriate handlers based on event type

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/webhook_logger.php';
require_once __DIR__ . '/../../utils/stripe_financial_events.php';
require_once __DIR__ . '/../../utils/stripe_webhook_signature.php';
require_once __DIR__ . '/../../utils/client_ip.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';

$endpointName = 'stripe-webhook';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$clientIp = get_client_ip();
$logId = null;

header('Content-Type: application/json');

try {
    $input = fopen('php://input', 'rb');
    $payload = pa_stripe_webhook_read_payload($input, $_SERVER['CONTENT_LENGTH'] ?? null);
} catch (LengthException $e) {
    http_response_code(413);
    echo json_encode(['error' => 'Payload too large']);
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
} finally {
    if (isset($input) && is_resource($input)) {
        fclose($input);
    }
}

if (!$payload) {
    if (!rate_limit_check($pdo, 'stripe_webhook_invalid', 300, 60, false)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many webhook requests']);
        exit;
    }
    http_response_code(400);
    $logId = webhook_log_insert($pdo, $endpointName, '', $sigHeader, $clientIp);
    webhook_log_update($pdo, $logId, false, 400, 'No payload');
    echo json_encode(['error' => 'No payload']);
    exit;
}

$signatureValid = null;
$responseCode = 200;
$errorMessage = null;
$stripeEventId = null;

try {
    // Initialize Stripe service
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Stripe not configured');
    }
    
    $webhookSecret = StripeService::webhookSecret($appConfig);
    pa_stripe_webhook_verify_signature($payload, $webhookSecret, $sigHeader);
    $signatureValid = true;
    if (!rate_limit_check($pdo, 'stripe_webhook', 300, 60, false)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many webhook requests']);
        exit;
    }
    $logId = webhook_log_insert($pdo, $endpointName, $payload, $sigHeader, $clientIp, true);
    
    // Parse the event
    $event = json_decode($payload, true);
    if (!$event || empty($event['type'])) {
        throw new Exception('Invalid event payload');
    }
    
    $stripeEventId = (string)($event['id'] ?? '');
    if ($stripeEventId === '') {
        throw new Exception('Stripe event ID is required');
    }
    @error_log('[StripeWebhook] Received event: ' . $event['type'] . ' - ' . $stripeEventId);

    $eventInsert = $pdo->prepare('INSERT IGNORE INTO stripe_events (stripe_event_id,event_type,status) VALUES (?,? ,"processing")');
    $eventInsert->execute([$stripeEventId, (string)$event['type']]);
    if ($eventInsert->rowCount() === 0) {
        $eventState = $pdo->prepare('SELECT status,updated_at FROM stripe_events WHERE stripe_event_id=?');
        $eventState->execute([$stripeEventId]);
        $existingEvent = $eventState->fetch(PDO::FETCH_ASSOC) ?: [];
        if (($existingEvent['status'] ?? '') === 'processed') {
            echo json_encode(['received' => true, 'duplicate' => true]);
            webhook_log_update($pdo, $logId, $signatureValid, 200, null);
            exit;
        }
        if (($existingEvent['status'] ?? '') === 'processing'
            && !empty($existingEvent['updated_at'])
            && strtotime((string)$existingEvent['updated_at']) > time() - 300) {
            echo json_encode(['received' => true, 'processing' => true]);
            webhook_log_update($pdo, $logId, $signatureValid, 200, null);
            exit;
        }
        $pdo->prepare('UPDATE stripe_events SET status="processing",attempts=attempts+1,last_error=NULL WHERE stripe_event_id=?')
            ->execute([$stripeEventId]);
    }
    
    // Route to appropriate handler based on event type
    $eventType = $event['type'];
    $eventData = $event['data']['object'] ?? [];
    $autoPayHandled = false;
    if ($eventType === 'setup_intent.succeeded' && !empty($eventData['metadata']['pa_autopay_authorization_id'])) {
        if ($signatureValid !== true) throw new RuntimeException('AutoPay requires a verified webhook signature.');
        require_once __DIR__ . '/../../utils/autopay_enrollment.php';
        // A temporary feature/configuration stop must not acknowledge and lose
        // a verified setup completion. Let Stripe retry once processing resumes.
        if (!pa_autopay_enabled($appConfig)) throw new RuntimeException('AutoPay setup processing is temporarily unavailable.');
        pa_autopay_setup_event($pdo, $event, $appConfig, static fn($method,$endpoint,$data,$key) => $stripe->autoPayRequest($method,$endpoint,$data,$key));
        $autoPayHandled = true;
    } elseif (str_starts_with($eventType, 'payment_intent.')) {
        require_once __DIR__ . '/../../utils/autopay_collection.php';
        $autoPayHandled = pa_autopay_handle_event($pdo, $event, $appConfig, $signatureValid === true);
    }
    
    if (!$autoPayHandled) switch ($eventType) {
        // Checkout events
        case 'checkout.session.completed':
            require_once __DIR__ . '/stripe_checkout_completed.php';
            handleCheckoutSessionCompleted($pdo, $eventData);
            break;
            
        case 'checkout.session.expired':
            if (!empty($eventData['id'])) {
                $pdo->prepare('UPDATE invoices SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE stripe_session_id=?')
                    ->execute([(string)$eventData['id']]);
                $pdo->prepare('UPDATE project_invoices SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE stripe_session_id=?')
                    ->execute([(string)$eventData['id']]);
            }
            @error_log('[StripeWebhook] Checkout session expired: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        // Payment Intent events
        case 'payment_intent.succeeded':
            require_once __DIR__ . '/stripe_payment_succeeded.php';
            handlePaymentIntentSucceeded($pdo, $eventData);
            break;
            
        case 'payment_intent.payment_failed':
            require_once __DIR__ . '/stripe_payment_failed.php';
            handlePaymentIntentFailed($pdo, $eventData);
            break;
            
        case 'payment_intent.canceled':
            @error_log('[StripeWebhook] Payment intent canceled: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        case 'payment_intent.requires_action':
            @error_log('[StripeWebhook] Payment requires action: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        // Subscription events (for future recurring billing)
        case 'invoice.payment_succeeded':
            @error_log('[StripeWebhook] Invoice payment succeeded (subscription): ' . ($eventData['id'] ?? 'unknown'));
            // Future: handle subscription invoice payments
            break;
            
        case 'invoice.payment_failed':
            @error_log('[StripeWebhook] Invoice payment failed (subscription): ' . ($eventData['id'] ?? 'unknown'));
            // Future: handle subscription payment failures
            break;
            
        case 'customer.subscription.created':
            @error_log('[StripeWebhook] Subscription created: ' . ($eventData['id'] ?? 'unknown'));
            // Future: record new subscription
            break;
            
        case 'customer.subscription.updated':
            @error_log('[StripeWebhook] Subscription updated: ' . ($eventData['id'] ?? 'unknown'));
            // Future: update subscription status
            break;
            
        case 'customer.subscription.deleted':
            @error_log('[StripeWebhook] Subscription deleted: ' . ($eventData['id'] ?? 'unknown'));
            // Future: mark subscription as cancelled
            break;
            
        // Customer events
        case 'customer.created':
            @error_log('[StripeWebhook] Customer created: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        case 'customer.updated':
            @error_log('[StripeWebhook] Customer updated: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        case 'customer.deleted':
            @error_log('[StripeWebhook] Customer deleted: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        // Refund events
        case 'charge.refunded':
        case 'refund.created':
        case 'refund.updated':
        case 'refund.failed':
            require_once __DIR__ . '/../../utils/autopay_collection.php';
            pa_autopay_hold_financial_event($pdo, $eventData);
            stripe_record_refund_event($pdo, $eventData, static fn(string $id): array => $stripe->getRefund($id));
            break;
            
        // Dispute events
        case 'charge.dispute.created':
        case 'charge.dispute.updated':
        case 'charge.dispute.closed':
        case 'charge.dispute.funds_withdrawn':
        case 'charge.dispute.funds_reinstated':
            require_once __DIR__ . '/../../utils/autopay_collection.php';
            pa_autopay_hold_financial_event($pdo, $eventData);
            stripe_record_dispute($pdo, $eventData, $eventType);
            break;
            
        default:
            @error_log('[StripeWebhook] Unhandled event type: ' . $eventType);
    }
    
    $pdo->prepare('UPDATE stripe_events SET status="processed",processed_at=NOW(),last_error=NULL WHERE stripe_event_id=?')
        ->execute([$stripeEventId]);
    echo json_encode(['received' => true]);
    
} catch (Throwable $e) {
    if ($signatureValid !== true
        && !rate_limit_check($pdo, 'stripe_webhook_invalid', 300, 60, false)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many webhook requests']);
        exit;
    }
    @error_log('[StripeWebhook] Error: ' . $e->getMessage());
    if ($logId === null && $signatureValid !== true) {
        $logId = webhook_log_insert($pdo, $endpointName, $payload, $sigHeader, $clientIp);
    }
    $responseCode = $stripeEventId ? 500 : 400;
    $errorMessage = $e->getMessage();
    if ($stripeEventId) {
        $pdo->prepare('UPDATE stripe_events SET status="failed",last_error=? WHERE stripe_event_id=?')
            ->execute([substr($errorMessage, 0, 2000), $stripeEventId]);
    }
    http_response_code($responseCode);
    echo json_encode(['error' => $e->getMessage()]);
}

webhook_log_update($pdo, $logId, $signatureValid, $responseCode, $errorMessage);
