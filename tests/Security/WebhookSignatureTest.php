<?php
require_once __DIR__ . '/../../src/utils/crypto.php';
require_once __DIR__ . '/../../src/utils/stripe_webhook_signature.php';

use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function testWebhookPayloadReadIsBoundedEvenWithoutContentLength(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat('x', 1024));
        rewind($stream);
        try {
            pa_stripe_webhook_read_payload($stream, null, 32);
            self::fail('Oversized stream was accepted.');
        } catch (LengthException) {
            self::assertSame(33, ftell($stream));
        } finally {
            fclose($stream);
        }

        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, 'valid');
        rewind($stream);
        try {
            self::assertSame('valid', pa_stripe_webhook_read_payload($stream, '5', 32));
            rewind($stream);
            $this->expectException(LengthException::class);
            pa_stripe_webhook_read_payload($stream, '33', 32);
        } finally {
            fclose($stream);
        }
    }

    private function buildStripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        return 't=' . $timestamp . ',v1=' . $signature;
    }

    public function testSignatureHeaderFormat(): void
    {
        $header = $this->buildStripeSignature('{"id":1}', 'whsec_test_secret');
        $this->assertStringContainsString('t=', $header);
        $this->assertStringContainsString('v1=', $header);
    }

    public function testWebhookRequiresSecretAndSignatureInEveryEnvironment(): void
    {
        $payload = '{"id":"evt_test","type":"checkout.session.completed"}';
        foreach (['production', 'prod', 'staging', 'test'] as $environment) {
            putenv('APP_ENV=' . $environment);
            try {
                pa_stripe_webhook_verify_signature($payload, null, '');
                self::fail('Secretless webhook accepted in ' . $environment);
            } catch (RuntimeException $error) {
                self::assertSame('Stripe webhook secret is required', $error->getMessage());
            }
        }
        putenv('APP_ENV');
    }

    public function testWebhookRejectsUnsignedInvalidAndStaleEventsButAcceptsValidSignature(): void
    {
        $payload = '{"id":"evt_test"}';
        $secret = 'whsec_synthetic';
        $now = 1_800_000_000;
        $valid = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $payload, $secret);
        pa_stripe_webhook_verify_signature($payload, $secret, $valid, $now);

        foreach ([
            ['', 'Missing webhook signature'],
            ['t=' . $now . ',v1=' . str_repeat('0', 64), 'Webhook signature verification failed'],
            ['t=' . ($now - 301) . ',v1=' . hash_hmac('sha256', ($now - 301) . '.' . $payload, $secret), 'Webhook timestamp too old'],
            ['t=not-a-time,v1=' . str_repeat('0', 64), 'Invalid webhook signature format'],
        ] as [$header, $expected]) {
            try {
                pa_stripe_webhook_verify_signature($payload, $secret, $header, $now);
                self::fail('Invalid webhook signature accepted: ' . $header);
            } catch (RuntimeException $error) {
                self::assertSame($expected, $error->getMessage());
            }
        }
    }

    public function testRouterVerifiesSignatureBeforeDispatchingEvents(): void
    {
        $router = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/webhook/stripe_webhooks.php');
        $verifyAt = strpos($router, 'pa_stripe_webhook_verify_signature($payload, $webhookSecret, $sigHeader)');
        $parseAt = strpos($router, '$event = json_decode($payload, true)');
        self::assertNotFalse($verifyAt);
        self::assertNotFalse($parseAt);
        self::assertLessThan($parseAt, $verifyAt);
    }

    public function testUnsignedCallbacksCannotExhaustTheStripeWebhookQuota(): void
    {
        $root = dirname(__DIR__, 2);
        $frontController = (string)file_get_contents($root . '/public/index.php');
        $router = (string)file_get_contents($root . '/src/controllers/webhook/stripe_webhooks.php');

        $skipListStart = strpos($frontController, '$skipGlobalRateLimitFor = [');
        $skipListEnd = strpos($frontController, '];', $skipListStart);
        self::assertNotFalse($skipListStart);
        self::assertNotFalse($skipListEnd);
        $skipList = substr($frontController, $skipListStart, $skipListEnd - $skipListStart);
        self::assertStringContainsString("'stripe-webhook',", $skipList);
        self::assertStringContainsString("'stripe-webhook-legacy',", $skipList);

        $payloadRead = strpos($router, 'pa_stripe_webhook_read_payload($input');
        $signatureCheck = strpos($router, 'pa_stripe_webhook_verify_signature($payload, $webhookSecret, $sigHeader)');
        $quotaCheck = strpos($router, 'rate_limit_check($pdo, \'stripe_webhook\', 300, 60, false)');
        $eventDispatch = strpos($router, '$event = json_decode($payload, true)');
        self::assertNotFalse($payloadRead);
        self::assertNotFalse($signatureCheck);
        self::assertNotFalse($quotaCheck);
        self::assertNotFalse($eventDispatch);
        self::assertLessThan($signatureCheck, $payloadRead);
        self::assertLessThan($quotaCheck, $signatureCheck);
        self::assertLessThan($eventDispatch, $quotaCheck);

        $invalidQuota = "rate_limit_check(\$pdo, 'stripe_webhook_invalid', 300, 60, false)";
        self::assertSame(2, substr_count($router, $invalidQuota));
        $emptyPayloadLog = strpos($router, 'webhook_log_insert($pdo, $endpointName, \'\', $sigHeader, $clientIp)');
        $invalidSignatureLog = strpos($router, 'webhook_log_insert($pdo, $endpointName, $payload, $sigHeader, $clientIp)');
        self::assertNotFalse($emptyPayloadLog);
        self::assertNotFalse($invalidSignatureLog);
        self::assertLessThan($emptyPayloadLog, strpos($router, $invalidQuota));
        self::assertLessThan($invalidSignatureLog, strrpos($router, $invalidQuota));
    }

    public function testWebhookBodiesAreLoggedOnlyAsMetadataAfterVerification(): void
    {
        $root = dirname(__DIR__, 2);
        $router = (string)file_get_contents($root . '/src/controllers/webhook/stripe_webhooks.php');
        $logger = (string)file_get_contents($root . '/src/utils/webhook_logger.php');
        $legacy = (string)file_get_contents($root . '/src/controllers/stripe/stripe_webhook.php');
        self::assertLessThan(
            strpos($router, 'webhook_log_insert($pdo, $endpointName, $payload, $sigHeader, $clientIp, true)'),
            strpos($router, 'pa_stripe_webhook_verify_signature($payload, $webhookSecret, $sigHeader)')
        );
        self::assertStringContainsString('null // Event IDs and status are sufficient; never retain webhook bodies.', $logger);
        self::assertStringNotContainsString("file_get_contents('php://input')", $legacy);
    }

    public function testCryptoRoundTrip(): void
    {
        $key = base64_encode(random_bytes(32));
        putenv('APP_ENCRYPTION_KEY=' . $key);

        $plaintext = 'sensitive webhook payload';
        $encrypted = crypto_encrypt($plaintext);
        $this->assertNotNull($encrypted);
        $this->assertStringStartsWith('enc::', $encrypted);

        $decrypted = crypto_decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testCleanStripeWebhookPathIsRewrittenToFrontController(): void
    {
        $root = dirname(__DIR__, 2);
        $htaccess = file_get_contents($root . '/public/.htaccess');

        $this->assertIsString($htaccess);
        $this->assertStringContainsString(
            'RewriteRule ^stripe-webhook/?$ index.php?page=stripe-webhook [QSA,L]',
            $htaccess
        );
        $this->assertStringContainsString(
            'RewriteRule ^stripe-webhook-legacy/?$ index.php?page=stripe-webhook-legacy [QSA,L]',
            $htaccess
        );
    }

    public function testApacheStartupAllowsHtaccessRouting(): void
    {
        $root = dirname(__DIR__, 2);
        $startScript = file_get_contents($root . '/docker/start.sh');

        $this->assertIsString($startScript);
        $this->assertStringContainsString('conf-available/project-alpha-routing.conf', $startScript);
        $this->assertStringContainsString('AllowOverride All', $startScript);
        $this->assertStringContainsString('a2enmod rewrite', $startScript);
    }
}
