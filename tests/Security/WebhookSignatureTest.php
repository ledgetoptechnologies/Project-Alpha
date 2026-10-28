<?php
require_once __DIR__ . '/../../src/utils/crypto.php';
require_once __DIR__ . '/../../src/utils/stripe_webhook_signature.php';

use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
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
