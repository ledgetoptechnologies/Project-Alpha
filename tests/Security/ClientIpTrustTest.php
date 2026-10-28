<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/client_ip.php';
require_once dirname(__DIR__, 2) . '/src/utils/api_ip_allowlist.php';

use PHPUnit\Framework\TestCase;

final class ClientIpTrustTest extends TestCase
{
    public function testPrivateDirectPeerCannotSpoofApiAllowlistOrLoginIdentity(): void
    {
        $original = getenv('TRUSTED_PROXIES');
        $server = $_SERVER;
        try {
            putenv('TRUSTED_PROXIES');
            $_SERVER = [
                'REMOTE_ADDR' => '10.0.0.50',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.8',
                'HTTP_CF_CONNECTING_IP' => '203.0.113.8',
            ];
            self::assertFalse(is_trusted_proxy('10.0.0.50'));
            self::assertSame('10.0.0.50', get_client_ip());
            self::assertFalse(api_ip_matches_exact_allowlist(get_client_ip(), ['203.0.113.8']));
        } finally {
            $_SERVER = $server;
            $original === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES=' . $original);
        }
    }

    public function testConfiguredProxyUsesNearestUntrustedForwardedPeer(): void
    {
        $original = getenv('TRUSTED_PROXIES');
        $server = $_SERVER;
        try {
            putenv('TRUSTED_PROXIES=10.0.0.2,10.0.0.3');
            $_SERVER = [
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.8, 198.51.100.22, 10.0.0.3',
                'HTTP_X_REAL_IP' => '203.0.113.8',
            ];
            self::assertSame('198.51.100.22', get_client_ip());
            self::assertTrue(api_ip_matches_exact_allowlist(get_client_ip(), ['198.51.100.22']));
            self::assertFalse(api_ip_matches_exact_allowlist(get_client_ip(), ['203.0.113.8']));
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.22, garbage';
            self::assertSame('10.0.0.2', get_client_ip());
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
            self::assertSame('10.0.0.2', get_client_ip());
        } finally {
            $_SERVER = $server;
            $original === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES=' . $original);
        }
    }
}
