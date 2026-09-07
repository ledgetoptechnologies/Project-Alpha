<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ExternalOpsSigner;
use PHPUnit\Framework\TestCase;

final class ExternalOpsEd25519SigningTest extends TestCase
{
    public function testHmacHeaderContractIsByteForByteCompatible(): void
    {
        $timestamp = '2026-09-07T00:00:00Z';
        $body = '{"event_id":"evt-1"}';
        $headers = ExternalOpsSigner::headersFromCredentials([
            'hmac_secret' => str_repeat('h', 32),
        ], $timestamp, $body);

        self::assertSame([
            'X-PA-Signature: sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, str_repeat('h', 32)),
        ], $headers);
    }

    public function testPublicStateNeverReturnsPrivateKeyMaterial(): void
    {
        $state = ExternalOpsSigner::publicState([
            'signing' => [
                'mode' => 'ed25519',
                'active_key_id' => 'pa_ed25519_0123456789abcdef',
                'keys' => [[
                    'key_id' => 'pa_ed25519_0123456789abcdef',
                    'state' => 'active',
                    'public_key_b64' => rtrim(strtr(base64_encode(str_repeat('p', 32)), '+/', '-_'), '='),
                    'private_key_b64' => base64_encode(str_repeat('s', 64)),
                ]],
            ],
        ]);

        self::assertSame('ed25519', $state['signing_mode']);
        self::assertSame('pa_ed25519_0123456789abcdef', $state['signing_key_id']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string)$state['signing_public_key']);
        self::assertArrayNotHasKey('signing_private_key_b64', $state);
        self::assertArrayNotHasKey('private_key_b64', $state['signing_keys'][0]);
    }

    public function testEd25519LifecycleSignsOnlyWhenSodiumIsAvailable(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('The current PHP runtime does not include ext-sodium.');
        }
        $created = ExternalOpsSigner::generateStagedKey([]);
        $credentials = ExternalOpsSigner::activateStagedKey($created['credentials'], $created['key_id']);
        $headers = ExternalOpsSigner::headersFromCredentials($credentials, '2026-09-07T00:00:00Z', '{}');
        $header = (string)end($headers);
        self::assertMatchesRegularExpression('/^X-PA-Signature-Ed25519: ed25519=[A-Za-z0-9_-]{86}$/', $header);
        $signature = substr($header, strlen('X-PA-Signature-Ed25519: ed25519='));
        $rawSignature = base64_decode(strtr($signature . str_repeat('=', (4 - strlen($signature) % 4) % 4), '-_', '+/'), true);
        $public = base64_decode(strtr($created['public_key'] . str_repeat('=', (4 - strlen($created['public_key']) % 4) % 4), '-_', '+/'), true);
        self::assertTrue(sodium_crypto_sign_verify_detached((string)$rawSignature, '2026-09-07T00:00:00Z.{}', (string)$public));

        $fallback = ExternalOpsSigner::activateHmac(array_replace_recursive($credentials, ['hmac_secret' => str_repeat('h', 32)]));
        $state = ExternalOpsSigner::publicState($fallback);
        self::assertSame('hmac-sha256', $state['signing_mode']);
        self::assertSame('external_ops_hmac_v1', $state['signing_key_id']);
        self::assertSame('retired', $fallback['signing']['keys'][0]['state']);
        self::assertSame('', $fallback['signing']['keys'][0]['private_key_b64']);
    }

    public function testEd25519ActivationRejectsAKeyWhosePublicAndPrivateBytesDoNotMatch(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('The current PHP runtime does not include ext-sodium.');
        }
        $created = ExternalOpsSigner::generateStagedKey([]);
        $created['credentials']['signing']['keys'][0]['public_key_b64'] = rtrim(strtr(base64_encode(str_repeat('q', 32)), '+/', '-_'), '=');
        $this->expectException(\DomainException::class);
        ExternalOpsSigner::activateStagedKey($created['credentials'], $created['key_id']);
    }

    public function testEd25519SigningRejectsCorruptPublicKeyWithoutATypeError(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('The current PHP runtime does not include ext-sodium.');
        }
        $created = ExternalOpsSigner::generateStagedKey([]);
        $credentials = ExternalOpsSigner::activateStagedKey($created['credentials'], $created['key_id']);
        $credentials['signing']['keys'][0]['public_key_b64'] = '=';
        $this->expectException(\DomainException::class);
        ExternalOpsSigner::headersFromCredentials($credentials, '2026-09-07T00:00:00Z', '{}');
    }

    public function testSigningUiAndHandlerStayGenericAndExplicit(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings/external-ops.php');
        $handler = (string)file_get_contents($root . '/src/controllers/settings/external_ops_handler.php');
        $migration = (string)file_get_contents($root . '/database/migrations/0086_external_operations_ed25519_signing.sql');

        self::assertStringContainsString('Generate staged Ed25519 key', $view);
        self::assertStringContainsString('Receiver has registered this exact public key', $view);
        self::assertStringNotContainsString('private_key_b64', $view);
        self::assertStringContainsString('$action === \'generate-ed25519-signing-key\'', $handler);
        self::assertStringContainsString('$action === \'activate-ed25519-signing-key\'', $handler);
        self::assertStringContainsString('receiver_key_registered', $handler);
        self::assertStringContainsString('external_ops_credentials_enc', $migration);
        self::assertStringNotContainsString('PRIVATE KEY', $migration);
    }
}
