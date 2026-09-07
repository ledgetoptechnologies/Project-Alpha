<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * Owns the versioned, encrypted signing material for the one External
 * Operations connection.  The public representation deliberately excludes
 * private key bytes; callers may expose only key id and public key to an
 * administrator for receiver registration.
 */
final class ExternalOpsSigner
{
    public const HMAC_SHA256 = 'hmac-sha256';
    public const ED25519 = 'ed25519';
    private const HMAC_KEY_ID = 'external_ops_hmac_v1';

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public static function normalizeCredentials(array $credentials): array
    {
        $signing = is_array($credentials['signing'] ?? null)
            ? $credentials['signing']
            : [
                'mode' => (string)($credentials['signing_mode'] ?? self::HMAC_SHA256),
                'active_key_id' => (string)($credentials['signing_key_id'] ?? ''),
                'keys' => (array)($credentials['signing_keys'] ?? []),
            ];
        $mode = (string)($signing['mode'] ?? self::HMAC_SHA256);
        if (!in_array($mode, [self::HMAC_SHA256, self::ED25519], true)) {
            $mode = self::HMAC_SHA256;
        }
        $keys = [];
        foreach ((array)($signing['keys'] ?? []) as $key) {
            if (!is_array($key)) {
                continue;
            }
            $id = (string)($key['key_id'] ?? '');
            $state = (string)($key['state'] ?? '');
            $public = (string)($key['public_key_b64'] ?? '');
            if (preg_match('/^pa_ed25519_[a-f0-9]{16}$/D', $id) !== 1
                || !in_array($state, ['staged', 'active', 'retired'], true)
                || self::base64UrlDecode($public) === null) {
                continue;
            }
            $keys[] = [
                'key_id' => $id,
                'state' => $state,
                'public_key_b64' => $public,
                'private_key_b64' => (string)($key['private_key_b64'] ?? ''),
                'created_at' => (string)($key['created_at'] ?? ''),
                'activated_at' => (string)($key['activated_at'] ?? ''),
                'retired_at' => (string)($key['retired_at'] ?? ''),
            ];
        }
        $active = (string)($signing['active_key_id'] ?? '');
        if (!self::findKey($keys, $active, 'active')) {
            $active = '';
            foreach ($keys as $key) {
                if ($key['state'] === 'active') {
                    $active = $key['key_id'];
                    break;
                }
            }
        }
        return [
            'schema_version' => 2,
            'access_client_id' => (string)($credentials['access_client_id'] ?? ''),
            'access_client_secret' => (string)($credentials['access_client_secret'] ?? ''),
            'hmac_secret' => (string)($credentials['hmac_secret'] ?? ''),
            'signing' => [
                'mode' => $mode,
                'active_key_id' => $active,
                'keys' => $keys,
            ],
        ];
    }

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public static function publicState(array $credentials): array
    {
        $normalized = self::normalizeCredentials($credentials);
        $signing = (array)$normalized['signing'];
        $keys = [];
        foreach ((array)$signing['keys'] as $key) {
            $keys[] = [
                'key_id' => (string)$key['key_id'],
                'state' => (string)$key['state'],
                'public_key_b64' => (string)$key['public_key_b64'],
                'created_at' => (string)$key['created_at'],
                'activated_at' => (string)$key['activated_at'],
                'retired_at' => (string)$key['retired_at'],
            ];
        }
        $active = self::findKey((array)$signing['keys'], (string)$signing['active_key_id'], 'active');
        return [
            'signing_mode' => (string)$signing['mode'],
            'signing_key_id' => (string)($active['key_id'] ?? self::HMAC_KEY_ID),
            'signing_public_key' => (string)($active['public_key_b64'] ?? ''),
            'signing_keys' => $keys,
        ];
    }

    /** @param array<string,mixed> $credentials @return array{credentials:array<string,mixed>,public_key:string,key_id:string} */
    public static function generateStagedKey(array $credentials): array
    {
        self::requireSodium();
        $normalized = self::normalizeCredentials($credentials);
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $secret = sodium_crypto_sign_secretkey($pair);
        $id = 'pa_ed25519_' . bin2hex(random_bytes(8));
        $signing = (array)$normalized['signing'];
        $keys = (array)$signing['keys'];
        $keys[] = [
            'key_id' => $id,
            'state' => 'staged',
            // The receiver accepts only URL-safe, unpadded base64. Keep the
            // stored/admin-visible public key in that exact representation.
            'public_key_b64' => self::base64UrlEncode($public),
            'private_key_b64' => base64_encode($secret),
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'activated_at' => '',
            'retired_at' => '',
        ];
        $normalized['signing']['keys'] = $keys;
        return ['credentials' => $normalized, 'public_key' => self::base64UrlEncode($public), 'key_id' => $id];
    }

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public static function activateStagedKey(array $credentials, string $keyId): array
    {
        self::requireSodium();
        $normalized = self::normalizeCredentials($credentials);
        $keys = (array)$normalized['signing']['keys'];
        $target = self::findKey($keys, $keyId, 'staged');
        if (!$target || !self::validEd25519Material($target)) {
            throw new DomainException('The selected staged Ed25519 key is unavailable. Generate a new key instead.');
        }
        foreach ($keys as &$key) {
            if ($key['state'] === 'active') {
                $key['state'] = 'retired';
                $key['retired_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
                $key['private_key_b64'] = '';
            }
            if (hash_equals((string)$key['key_id'], $keyId)) {
                $key['state'] = 'active';
                $key['activated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
            }
        }
        unset($key);
        $normalized['signing'] = ['mode' => self::ED25519, 'active_key_id' => $keyId, 'keys' => $keys];
        return $normalized;
    }

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public static function activateHmac(array $credentials): array
    {
        $normalized = self::normalizeCredentials($credentials);
        if (strlen((string)$normalized['hmac_secret']) < 32) {
            throw new DomainException('Configure a 32-character HMAC secret before returning to HMAC signing.');
        }
        $keys = (array)$normalized['signing']['keys'];
        foreach ($keys as &$key) {
            if ($key['state'] === 'active') {
                // The caller has already drained the old signing contract.
                // Do not leave a live private key stranded in an unreachable
                // state after an explicit fallback.
                $key['state'] = 'retired';
                $key['private_key_b64'] = '';
                $key['retired_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
            }
        }
        unset($key);
        $normalized['signing'] = [
            'mode' => self::HMAC_SHA256,
            'active_key_id' => '',
            'keys' => $keys,
        ];
        return $normalized;
    }

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public static function retireStagedKey(array $credentials, string $keyId): array
    {
        $normalized = self::normalizeCredentials($credentials);
        $keys = (array)$normalized['signing']['keys'];
        $found = false;
        foreach ($keys as &$key) {
            if (!hash_equals((string)$key['key_id'], $keyId)) {
                continue;
            }
            if ($key['state'] === 'active') {
                throw new DomainException('Activate a replacement signing method before retiring the active Ed25519 key.');
            }
            if ($key['state'] !== 'staged') {
                throw new DomainException('Only a staged Ed25519 key can be retired.');
            }
            $key['state'] = 'retired';
            $key['private_key_b64'] = '';
            $key['retired_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
            $found = true;
        }
        unset($key);
        if (!$found) {
            throw new DomainException('The selected staged Ed25519 key is unavailable.');
        }
        $normalized['signing']['keys'] = $keys;
        return $normalized;
    }

    /** @param array<string,mixed> $credentials @return list<string> */
    public static function headersFromCredentials(array $credentials, string $timestamp, string $body): array
    {
        $normalized = self::normalizeCredentials($credentials);
        $state = self::publicState($normalized);
        $active = self::findKey((array)$normalized['signing']['keys'], (string)$normalized['signing']['active_key_id'], 'active');
        $mode = (string)$state['signing_mode'];
        if ($mode === self::HMAC_SHA256) {
            return ['X-PA-Signature: sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, (string)$normalized['hmac_secret'])];
        }
        if ($mode !== self::ED25519) {
            throw new DomainException('The configured outbound signing method is invalid.');
        }
        self::requireSodium();
        if (!$active) {
            throw new DomainException('The active Ed25519 signing key is incomplete.');
        }
        $private = base64_decode((string)($active['private_key_b64'] ?? ''), true);
        $public = self::base64UrlDecode((string)$state['signing_public_key']);
        $keyId = (string)$state['signing_key_id'];
        if (!is_string($private) || !is_string($public) || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || preg_match('/^pa_ed25519_[a-f0-9]{16}$/D', $keyId) !== 1
            || !self::validEd25519Material($active)) {
            throw new DomainException('The active Ed25519 signing key is incomplete.');
        }
        $signature = rtrim(strtr(
            base64_encode(sodium_crypto_sign_detached($timestamp . '.' . $body, $private)),
            '+/',
            '-_'
        ), '=');
        return [
            // HMAC keeps X-PA-Signature. The deployed receiver deliberately
            // selects Ed25519 from this distinct header without an algorithm
            // negotiation field, so no HMAC parser can mistake it for a MAC.
            'X-PA-Signature-Ed25519: ed25519=' . $signature,
        ];
    }

    /** @param array<string,mixed> $credentials */
    public static function deliveryIssue(array $credentials): ?string
    {
        $normalized = self::normalizeCredentials($credentials);
        $state = self::publicState($normalized);
        $active = self::findKey((array)$normalized['signing']['keys'], (string)$normalized['signing']['active_key_id'], 'active');
        $mode = (string)$state['signing_mode'];
        if ($mode === self::HMAC_SHA256) {
            $length = strlen((string)$normalized['hmac_secret']);
            return $length >= 32 && $length <= 1000 ? null : 'valid HMAC secret';
        }
        if ($mode !== self::ED25519) {
            return 'valid signing method';
        }
        if (!extension_loaded('sodium')) {
            return 'Ed25519 signing support';
        }
        if (!$active) {
            return 'active Ed25519 signing key';
        }
        $private = base64_decode((string)($active['private_key_b64'] ?? ''), true);
        $public = self::base64UrlDecode((string)$state['signing_public_key']);
        $keyId = (string)$state['signing_key_id'];
        if (!is_string($private) || !is_string($public)
            || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || preg_match('/^pa_ed25519_[a-f0-9]{16}$/D', $keyId) !== 1
            || !self::validEd25519Material($active)) {
            return 'active Ed25519 signing key';
        }
        return null;
    }

    /** @param list<array<string,mixed>> $keys @return array<string,mixed>|null */
    private static function findKey(array $keys, string $keyId, ?string $state = null): ?array
    {
        foreach ($keys as $key) {
            if (hash_equals((string)($key['key_id'] ?? ''), $keyId)
                && ($state === null || (string)($key['state'] ?? '') === $state)) {
                return $key;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $key */
    private static function validEd25519Material(array $key): bool
    {
        $private = base64_decode((string)($key['private_key_b64'] ?? ''), true);
        $public = self::base64UrlDecode((string)($key['public_key_b64'] ?? ''));
        if (!is_string($private) || !is_string($public)
            || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return hash_equals(sodium_crypto_sign_publickey_from_secretkey($private), $public);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            return null;
        }
        $remainder = strlen($encoded) % 4;
        if ($remainder === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded . str_repeat('=', (4 - $remainder) % 4), '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }

    private static function requireSodium(): void
    {
        if (!extension_loaded('sodium')) {
            throw new DomainException('This Project Alpha PHP runtime does not include the required ext-sodium Ed25519 support.');
        }
    }
}
