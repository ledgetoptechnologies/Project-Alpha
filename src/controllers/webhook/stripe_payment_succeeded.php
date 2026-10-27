<?php
// Compatibility adapter for the signed webhook router and existing integrations.
require_once __DIR__ . '/../../utils/stripe_payment_settlement.php';

function handlePaymentIntentSucceeded($pdo, $paymentIntent) {
    stripe_settle_payment_intent($pdo, $paymentIntent, $GLOBALS['appConfig'] ?? []);
}
