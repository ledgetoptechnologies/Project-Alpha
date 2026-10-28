<?php

declare(strict_types=1);

/** Secrets are deployment configuration. No application key has a built-in value. */
function portal_integration_hmac_secret(string $applicationKey, string $capability): string
{
    return portal_integration_hmac_secrets($applicationKey,$capability)[0]??'';
}

/**
 * Current and, during its explicitly bounded overlap, previous inbound secret.
 * The legacy string form remains valid as the current secret.
 *
 * @return list<string>
 */
function portal_integration_hmac_secrets(string $applicationKey,string $capability):array
{
    $raw = getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
    if (!is_string($raw) || trim($raw) === '') return [];
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }
    if (!is_array($decoded) || !isset($decoded[$applicationKey]) || !is_array($decoded[$applicationKey])) return [];
    $configured=$decoded[$applicationKey][$capability]??null;
    if(is_string($configured))return strlen($configured)>=32?[$configured]:[];
    if(!is_array($configured))return[];
    $current=$configured['current']??'';$previous=$configured['previous']??'';$previousUntil=$configured['previousValidUntil']??null;$secrets=[];
    if(is_string($current)&&strlen($current)>=32)$secrets[]=$current;
    if(is_string($previous)&&strlen($previous)>=32&&is_string($previousUntil)){
        $until=strtotime($previousUntil);if($until!==false&&$until>=time())$secrets[]=$previous;
    }
    return array_values(array_unique($secrets));
}

function portal_integration_flag_enabled(string $name): bool
{
    return getenv($name) === 'true';
}

/** Only the exact signed API path may dispatch a server integration command. */
function portal_integration_route_key(string $requestPath, string $page): ?string
{
    if (preg_match('#^/api/v2/integrations/([a-z0-9][a-z0-9_-]{1,63})/(pricing-hints|draft-quotes)$#D', $requestPath, $match) !== 1) {
        return null;
    }
    $pricing = $match[2] === 'pricing-hints';
    if ($page !== ($pricing ? 'api-integration-pricing-hints' : 'api-integration-draft-quotes')) {
        return null;
    }
    $flag = $pricing ? 'APP_PORTAL_PRICING_PREVIEW_ENABLED' : 'APP_PORTAL_DRAFT_QUOTES_ENABLED';
    return portal_integration_flag_enabled($flag) ? $match[1] : null;
}

function portal_integration_failure(int $status, string $code): never
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode(['code' => $code], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
