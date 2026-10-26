<?php

/**
 * BETA / UNAVAILABLE: AutoPay is not a production Project Alpha feature.
 *
 * This guard intentionally fails closed. Enrollment and collection are available
 * only with the explicit staging/test flag, a test Stripe key, and installation
 * and processor-account bindings. Production cannot enable this beta.
 */
function autopay_beta_enabled(): bool
{
    $enabled = filter_var(getenv('AUTOPAY_BETA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));

    return $enabled && in_array($environment, ['staging', 'development', 'dev', 'test', 'testing'], true);
}

function pa_autopay_enabled(array $config): bool
{
    return autopay_beta_enabled() && pa_autopay_test_context_enabled($config);
}

/** Existing reservations may settle after collection is switched off. */
function pa_autopay_test_context_enabled(array $config): bool
{
    require_once __DIR__ . '/../services/StripeService.php';
    $environment=strtolower(trim((string)(getenv('APP_ENV')?:'production')));
    return in_array($environment,['staging','development','dev','test','testing'],true) && StripeService::hasAutoPayTestKey($config)
        && preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', (string)getenv('AUTOPAY_INSTALLATION_ID'))
        && preg_match('/^acct_[a-zA-Z0-9]+$/D', (string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID'));
}

/**
 * @throws RuntimeException Always in production and by default elsewhere.
 */
function require_autopay_beta(): void
{
    if (!autopay_beta_enabled()) {
        throw new RuntimeException('AutoPay is an unavailable beta feature and is disabled.');
    }
}
