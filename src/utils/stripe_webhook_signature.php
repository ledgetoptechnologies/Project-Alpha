<?php
declare(strict_types=1);

/** Require Stripe authentication before a webhook event can reach payment handlers. */
function pa_stripe_webhook_verify_signature(string $payload, ?string $secret, string $header, ?int $now = null): void
{
    if ($secret === null || $secret === '') {
        throw new RuntimeException('Stripe webhook secret is required');
    }
    if ($header === '') {
        throw new RuntimeException('Missing webhook signature');
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) {
            continue;
        }
        if ($pair[0] === 't') {
            if ($timestamp !== null) {
                throw new RuntimeException('Invalid webhook signature format');
            }
            $timestamp = $pair[1];
        } elseif ($pair[0] === 'v1') {
            $signatures[] = $pair[1];
        }
    }
    if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
        throw new RuntimeException('Invalid webhook signature format');
    }
    if (abs(($now ?? time()) - (int)$timestamp) > 300) {
        throw new RuntimeException('Webhook timestamp too old');
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return;
        }
    }
    throw new RuntimeException('Webhook signature verification failed');
}
