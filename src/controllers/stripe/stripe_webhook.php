<?php
// Compatibility entry point. All Stripe webhook traffic uses the hardened,
// idempotent handler.
require __DIR__ . '/../webhook/stripe_webhooks.php';
