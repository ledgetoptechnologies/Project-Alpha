<?php
// Compatibility entry point. Never scan legacy client flags or old unpaid invoices.
require_once __DIR__ . '/../utils/autopay_beta.php';
if (!autopay_beta_enabled()) {
    if (PHP_SAPI === 'cli') fwrite(STDERR, "AutoPay is unavailable and disabled.\n");
    exit(78);
}
require __DIR__ . '/process_autopay_collections.php';
