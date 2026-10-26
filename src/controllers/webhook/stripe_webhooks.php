<?php
// src/controllers/webhook/stripe_webhooks.php
// Future-proof webhook router for Stripe events
// Routes events to appropriate handlers based on event type

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/webhook_logger.php';
require_once __DIR__ . '/../../utils/stripe_financial_events.php';

$endpointName = 'stripe-webhook';
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$logId = webhook_log_insert($pdo, $endpointName, $payload, $sigHeader, $clientIp);

header('Content-Type: application/json');

if (!$payload) {
    http_response_code(400);
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
    
    // Verify webhook signature if webhook secret is configured
    $webhookSecret = StripeService::webhookSecret($appConfig);
    
    // Verify webhook signature if secret is available
    if ($webhookSecret && $sigHeader) {
        // Parse Stripe signature header: t=timestamp,v1=signature
        $sigParts = [];
        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) $sigParts[$kv[0]] = $kv[1];
        }
        $timestamp = $sigParts['t'] ?? '';
        $sig = $sigParts['v1'] ?? '';
        
        if (!$timestamp || !$sig) {
            throw new Exception('Invalid webhook signature format');
        }
        
        // Reject if timestamp is older than 5 minutes (replay protection)
        if (abs(time() - (int)$timestamp) > 300) {
            throw new Exception('Webhook timestamp too old');
        }
        
        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);
        if (!hash_equals($expected, $sig)) {
            throw new Exception('Webhook signature verification failed');
        }
        $signatureValid = true;
    } elseif ($webhookSecret) {
        // Secret configured but no signature header = reject
        throw new Exception('Missing webhook signature');
    } elseif (strtolower((string)(getenv('APP_ENV') ?: 'production')) === 'production') {
        throw new Exception('Stripe webhook secret is required in production');
    }
    
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
    @error_log('[StripeWebhook] Error: ' . $e->getMessage());
    $responseCode = $stripeEventId ? 500 : 400;
    $errorMessage = $e->getMessage();
    if ($stripeEventId) {
        $pdo->prepare('UPDATE stripe_events SET status="failed",last_error=? WHERE stripe_event_id=?')
            ->execute([substr($errorMessage, 0, 2000), $stripeEventId]);
    }
    http_response_code($responseCode);
    webhook_log_update($pdo, $logId, $signatureValid, $responseCode, $errorMessage);
    echo json_encode(['error' => $e->getMessage()]);
}

webhook_log_update($pdo, $logId, $signatureValid, $responseCode, $errorMessage);
