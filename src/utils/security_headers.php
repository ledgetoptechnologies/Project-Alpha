<?php
// src/utils/security_headers.php
// Sends a consistent set of security response headers.

require_once __DIR__ . '/request_security.php';

function send_security_headers(bool $allowStripeCheckout = false): void {
    $isHttps = request_is_https();

    // Prevent cross-site clickjacking while allowing PA to embed its own PDF previews.
    header('X-Frame-Options: SAMEORIGIN');
    // Prevent MIME-sniffing attacks.
    header('X-Content-Type-Options: nosniff');
    // Deprecated but retained for legacy browser defense-in-depth.
    header('X-XSS-Protection: 1; mode=block');
    // Enforce HTTPS for one year, including subdomains (only over HTTPS; never over plain HTTP).
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    // Leak no more referrer than scheme/host/path on cross-origin requests.
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Disable powerful browser features by default.
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self), payment=(), usb=(), magnetometer=(), gyroscope=()');
    // Hardened Content Security Policy. unsafe-inline is required by the existing
    // inline scripts/styles in this legacy codebase; remove as code is migrated.
    // Upgrade mixed content only when serving over HTTPS.
    $csp = "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://js.stripe.com https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob:; connect-src 'self' https://api.stripe.com; frame-src 'self' https://js.stripe.com https://hooks.stripe.com; font-src 'self' https://fonts.gstatic.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self';";
    // Browsers also apply form-action to redirects after a form POST. Only the
    // hosted AutoPay setup page needs this exact external destination.
    if ($allowStripeCheckout) {
        $csp = str_replace("form-action 'self';", "form-action 'self' https://checkout.stripe.com;", $csp);
    }
    if ($isHttps) {
        $csp .= ' upgrade-insecure-requests;';
    }
    header($csp);
    // Remove the X-Powered-By header to reduce fingerprinting.
    header_remove('X-Powered-By');
}
