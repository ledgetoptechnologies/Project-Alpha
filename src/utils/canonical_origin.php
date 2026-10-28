<?php

declare(strict_types=1);

function app_canonical_https_origin(array $appConfig): ?string
{
    $configured = trim((string)($appConfig['app_host'] ?? ''));
    if ($configured === '') {
        $configured = trim((string)(getenv('APP_HOST') ?: ''));
    }
    $parts = parse_url($configured);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array((string)($parts['path'] ?? ''), ['', '/'], true)) {
        return null;
    }
    $host = (string)$parts['host'];
    if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        && !filter_var($host, FILTER_VALIDATE_IP)) {
        return null;
    }
    return 'https://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
}
