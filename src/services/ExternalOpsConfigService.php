<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalOpsConfigService
{
    private const CREDENTIALS_KEY = 'external_ops_credentials_enc';
    private const CONFIG_KEYS = [
        'external_ops_enabled',
        'external_ops_label',
        'external_ops_application_key',
        'external_ops_webhook_url',
        'external_ops_timeout_seconds',
        'external_ops_max_attempts',
        self::CREDENTIALS_KEY,
    ];

    /** @return array<string,mixed> */
    public function load(PDO $pdo): array
    {
        $values = [];
        $placeholders = implode(',', array_fill(0, count(self::CONFIG_KEYS), '?'));
        $statement = $pdo->prepare(
            "SELECT config_key,config_value FROM app_config WHERE organization_id=0 AND config_key IN ($placeholders)"
        );
        $statement->execute(self::CONFIG_KEYS);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string)$row['config_key']] = (string)$row['config_value'];
        }

        $credentialState = $this->decodeCredentials((string)($values[self::CREDENTIALS_KEY] ?? ''));
        $credentials = $credentialState['credentials'];
        $credentialsUnreadable = $credentialState['unreadable'];
        $signing = ExternalOpsSigner::publicState($credentials);

        $applicationKey = strtolower(trim((string)($values['external_ops_application_key'] ?? '')));
        $configuredEnabled = filter_var(
            $values['external_ops_enabled'] ?? 'false',
            FILTER_VALIDATE_BOOLEAN
        );
        $deliveryIssues = self::deliveryIssues([
            'application_key' => $applicationKey,
            'webhook_url' => trim((string)($values['external_ops_webhook_url'] ?? '')),
            'access_client_id' => trim((string)($credentials['access_client_id'] ?? '')),
            'access_client_secret' => trim((string)($credentials['access_client_secret'] ?? '')),
            'hmac_secret' => trim((string)($credentials['hmac_secret'] ?? '')),
            'signing_mode' => (string)$signing['signing_mode'],
            'signing_key_id' => (string)$signing['signing_key_id'],
            'signing_public_key' => (string)$signing['signing_public_key'],
            'signing_keys' => (array)$signing['signing_keys'],
            'credentials_unreadable' => $credentialsUnreadable,
        ], $credentials);
        $configurationComplete = $deliveryIssues === [];
        $deliveryReady = $configuredEnabled && $configurationComplete;

        return [
            // Preserve administrator intent separately from the effective runtime state.
            'configured_enabled' => $configuredEnabled,
            // Keep the established enabled contract for event capture and entitlement access.
            'enabled' => $configuredEnabled,
            'configuration_complete' => $configurationComplete,
            'delivery_ready' => $deliveryReady,
            'delivery_issues' => $deliveryIssues,
            'application_key' => $applicationKey,
            'label' => trim((string)($values['external_ops_label'] ?? 'External operations')) ?: 'External operations',
            'webhook_url' => trim((string)($values['external_ops_webhook_url'] ?? '')),
            'access_client_id' => trim((string)($credentials['access_client_id'] ?? '')),
            'access_client_secret' => trim((string)($credentials['access_client_secret'] ?? '')),
            'hmac_secret' => trim((string)($credentials['hmac_secret'] ?? '')),
            'signing_mode' => (string)$signing['signing_mode'],
            'signing_key_id' => (string)$signing['signing_key_id'],
            'signing_public_key' => (string)$signing['signing_public_key'],
            'signing_keys' => (array)$signing['signing_keys'],
            'timeout_seconds' => max(2, min(60, (int)($values['external_ops_timeout_seconds'] ?? 15))),
            'max_attempts' => max(1, min(100, (int)($values['external_ops_max_attempts'] ?? 12))),
            'credentials_unreadable' => $credentialsUnreadable,
        ];
    }

    /**
     * Non-disclosing cron diagnostic for encrypted delivery configuration.
     * It deliberately exposes neither key material, ciphertext, nor fields
     * within decrypted credentials.
     *
     * @return array{runtime_key:string,credential_record:string}
     */
    public function safeEncryptionDiagnostic(PDO $pdo): array
    {
        $statement = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key=? LIMIT 1');
        $statement->execute([self::CREDENTIALS_KEY]);
        $encrypted = trim((string)($statement->fetchColumn() ?: ''));
        if ($encrypted === '') {
            return [
                'runtime_key' => getenv('APP_ENCRYPTION_KEY') === false || getenv('APP_ENCRYPTION_KEY') === '' ? 'missing' : 'present',
                'credential_record' => 'absent',
            ];
        }
        $decoded = $this->decodeCredentials($encrypted);
        return [
            'runtime_key' => getenv('APP_ENCRYPTION_KEY') === false || getenv('APP_ENCRYPTION_KEY') === '' ? 'missing' : 'present',
            'credential_record' => $decoded['unreadable'] ? 'unreadable' : 'readable',
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(PDO $pdo, array $input): array
    {
        $current = $this->load($pdo);
        $enabled = !empty($input['enabled']);
        $label = trim((string)($input['label'] ?? '')) ?: 'External operations';
        $applicationKeyInput = trim((string)($input['application_key'] ?? $current['application_key']));
        $applicationKey = $applicationKeyInput === ''
            ? ''
            : ExternalOpsIntegrationService::normalizeApplicationKey($applicationKeyInput);
        $webhookUrl = trim((string)($input['webhook_url'] ?? ''));
        $timeout = max(2, min(60, (int)($input['timeout_seconds'] ?? 15)));
        $maxAttempts = max(1, min(100, (int)($input['max_attempts'] ?? 12)));

        if (mb_strlen($label) > 100) {
            throw new DomainException('The integration label cannot exceed 100 characters.');
        }
        if (!empty($current['configured_enabled'])
            && (string)$current['application_key'] !== ''
            && $applicationKey !== (string)$current['application_key']) {
            throw new DomainException('Disable the integration before changing its application key.');
        }
        if (mb_strlen($webhookUrl) > 1000) {
            throw new DomainException('The webhook URL cannot exceed 1000 characters.');
        }
        if ($webhookUrl !== '') {
            $parts = parse_url($webhookUrl);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || ($scheme !== 'https' && !$localHost)) {
                throw new DomainException('The webhook URL must be a valid HTTPS URL (HTTP is allowed only for localhost).');
            }
        }

        $storedCredentials = $this->readCredentials($pdo);
        if ($storedCredentials['unreadable']) {
            throw new DomainException('Stored delivery credentials cannot be decrypted. Restore the persisted application encryption key before editing this connection.');
        }
        $credentials = ExternalOpsSigner::normalizeCredentials(array_replace($storedCredentials['credentials'], [
            'access_client_id' => trim((string)($input['access_client_id'] ?? '')) ?: (string)$current['access_client_id'],
            'access_client_secret' => trim((string)($input['access_client_secret'] ?? '')) ?: (string)$current['access_client_secret'],
            'hmac_secret' => trim((string)($input['hmac_secret'] ?? '')) ?: (string)$current['hmac_secret'],
        ]));
        if (mb_strlen($credentials['access_client_id']) > 500 || mb_strlen($credentials['access_client_secret']) > 1000) {
            throw new DomainException('The Cloudflare Access credential is too long.');
        }
        if (strlen($credentials['hmac_secret']) > 1000) {
            throw new DomainException('The webhook HMAC secret is too long.');
        }
        if ($credentials['hmac_secret'] !== '' && strlen($credentials['hmac_secret']) < 32) {
            throw new DomainException('The webhook HMAC secret must be at least 32 characters.');
        }
        $signingIssue = ExternalOpsSigner::deliveryIssue($credentials);
        if ($enabled && ($applicationKey === '' || $webhookUrl === ''
            || $credentials['access_client_id'] === '' || $credentials['access_client_secret'] === '' || $signingIssue !== null)) {
            throw new DomainException('Application key, webhook URL, Cloudflare Access credentials, and a ready signing method are required before enabling this integration.');
        }

        require_once __DIR__ . '/../utils/crypto.php';
        $encryptedCredentials = crypto_encrypt(json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($encryptedCredentials === null) {
            throw new RuntimeException('Project Alpha could not encrypt the integration credentials. Verify the persisted application encryption key.');
        }

        $values = [
            'external_ops_enabled' => $enabled ? '1' : '0',
            'external_ops_label' => $label,
            'external_ops_application_key' => $applicationKey,
            'external_ops_webhook_url' => $webhookUrl,
            'external_ops_timeout_seconds' => (string)$timeout,
            'external_ops_max_attempts' => (string)$maxAttempts,
            self::CREDENTIALS_KEY => $encryptedCredentials,
        ];

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $this->assertPortalContractCanRotate($pdo, $current, [
                'application_key' => $applicationKey,
                'webhook_url' => $webhookUrl,
                'access_client_id' => $credentials['access_client_id'],
                'access_client_secret' => $credentials['access_client_secret'],
                'hmac_secret' => $credentials['hmac_secret'],
                'signing_contract' => $this->signingContract($credentials),
            ]);
            $saveSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?)
                   ON CONFLICT(organization_id,config_key) DO UPDATE SET config_value=excluded.config_value'
                : 'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?)
                   ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';
            $save = $pdo->prepare($saveSql);
            foreach ($values as $key => $value) {
                $save->execute([$key, $value]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return $this->load($pdo);
    }

    /** @return array{key_id:string,public_key:string} */
    public function generateEd25519Key(PDO $pdo): array
    {
        $result = $this->mutateSigning($pdo, static fn (array $credentials): array => ExternalOpsSigner::generateStagedKey($credentials), false);
        return ['key_id' => (string)$result['key_id'], 'public_key' => (string)$result['public_key']];
    }

    public function activateEd25519Key(PDO $pdo, string $keyId): void
    {
        $this->mutateSigning($pdo, static fn (array $credentials): array => ['credentials' => ExternalOpsSigner::activateStagedKey($credentials, $keyId)], true);
    }

    public function activateHmacSigning(PDO $pdo): void
    {
        $this->mutateSigning($pdo, static fn (array $credentials): array => ['credentials' => ExternalOpsSigner::activateHmac($credentials)], true);
    }

    public function retireEd25519Key(PDO $pdo, string $keyId): void
    {
        $this->mutateSigning($pdo, static fn (array $credentials): array => ['credentials' => ExternalOpsSigner::retireStagedKey($credentials, $keyId)], false);
    }

    /** @return list<string> */
    public function signingHeaders(PDO $pdo, string $timestamp, string $body): array
    {
        $payload = $this->readCredentials($pdo);
        if ($payload['unreadable']) {
            throw new RuntimeException('Stored delivery credentials cannot be decrypted.');
        }
        return ExternalOpsSigner::headersFromCredentials($payload['credentials'], $timestamp, $body);
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator */
    private function mutateSigning(PDO $pdo, callable $mutator, bool $changesSigningContract): array
    {
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            $current = $this->load($pdo);
            $payload = $this->readCredentials($pdo);
            if ($payload['unreadable']) throw new DomainException('Stored delivery credentials cannot be decrypted. Restore the persisted application encryption key first.');
            $result = $mutator($payload['credentials']);
            $credentials = ExternalOpsSigner::normalizeCredentials((array)($result['credentials'] ?? []));
            if ($changesSigningContract) {
                $this->assertPortalContractCanRotate($pdo, $current, [
                    'application_key' => (string)$current['application_key'],
                    'webhook_url' => (string)$current['webhook_url'],
                    'access_client_id' => (string)$current['access_client_id'],
                    'access_client_secret' => (string)$current['access_client_secret'],
                    'hmac_secret' => (string)$current['hmac_secret'],
                    'signing_contract' => $this->signingContract($credentials),
                ]);
            }
            $this->saveEncryptedCredentials($pdo, $credentials);
            if ($ownsTransaction) $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return array{credentials:array<string,mixed>,unreadable:bool} */
    private function readCredentials(PDO $pdo): array
    {
        $statement = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key=? LIMIT 1');
        $statement->execute([self::CREDENTIALS_KEY]);
        return $this->decodeCredentials((string)($statement->fetchColumn() ?: ''));
    }

    /** @return array{credentials:array<string,mixed>,unreadable:bool} */
    private function decodeCredentials(string $encrypted): array
    {
        $encrypted = trim($encrypted);
        if ($encrypted === '') {
            return ['credentials' => ExternalOpsSigner::normalizeCredentials([]), 'unreadable' => false];
        }
        require_once __DIR__ . '/../utils/crypto.php';
        $plaintext = crypto_decrypt($encrypted);
        $decoded = is_string($plaintext) ? json_decode($plaintext, true) : null;
        if (!is_array($decoded)) {
            return ['credentials' => ExternalOpsSigner::normalizeCredentials([]), 'unreadable' => true];
        }
        return ['credentials' => ExternalOpsSigner::normalizeCredentials($decoded), 'unreadable' => false];
    }

    /** @param array<string,mixed> $credentials */
    private function saveEncryptedCredentials(PDO $pdo, array $credentials): void
    {
        require_once __DIR__ . '/../utils/crypto.php';
        $encrypted = crypto_encrypt(json_encode(ExternalOpsSigner::normalizeCredentials($credentials), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($encrypted === null) {
            throw new RuntimeException('Project Alpha could not encrypt the integration credentials. Verify the persisted application encryption key.');
        }
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?) ON CONFLICT(organization_id,config_key) DO UPDATE SET config_value=excluded.config_value'
            : 'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';
        $pdo->prepare($sql)->execute([self::CREDENTIALS_KEY, $encrypted]);
    }

    /** @param array<string,mixed> $credentials */
    private function signingContract(array $credentials): string
    {
        $state = ExternalOpsSigner::publicState($credentials);
        return hash('sha256', implode("\n", [
            (string)$state['signing_mode'],
            (string)$state['signing_key_id'],
            (string)$state['signing_public_key'],
        ]));
    }

    /**
     * Portal records deliberately share this transport. Lock the projection
     * profiles and refuse to mutate its addressing or authentication while an
     * old-contract row remains unresolved; otherwise the sender could sign an
     * old grant or revocation with replacement credentials and deliver it to a
     * replacement receiver. Secrets are compared only in memory and are never
     * copied into the projection outbox.
     *
     * @param array<string,mixed> $current
     * @param array<string,string> $replacement
     */
    private function assertPortalContractCanRotate(PDO $pdo, array $current, array $replacement): void
    {
        $fields = ['application_key', 'webhook_url', 'access_client_id', 'access_client_secret', 'hmac_secret', 'signing_contract'];
        $established = false;
        $changed = false;
        foreach ($fields as $field) {
            $before = $field === 'signing_contract'
                ? hash('sha256', implode("\n", [(string)($current['signing_mode'] ?? ExternalOpsSigner::HMAC_SHA256), (string)($current['signing_key_id'] ?? 'external_ops_hmac_v1'), (string)($current['signing_public_key'] ?? '')]))
                : (string)($current[$field] ?? '');
            $after = (string)($replacement[$field] ?? '');
            // The default HMAC signing identity is implicit. It must not make
            // a first-time setup look like a rotation before any receiver
            // contract has been saved.
            $established = $established || ($field !== 'signing_contract' && $before !== '');
            $changed = $changed || !hash_equals($before, $after);
        }
        if (!$established || !$changed) {
            return;
        }

        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        // Projection producers lock their profile contract before enqueueing,
        // so taking the same locks closes the zero-pending/new-row race.
        $pdo->query('SELECT id FROM portal_integration_profiles ORDER BY id' . $lock)->fetchAll(PDO::FETCH_COLUMN);
        $pending = $pdo->query(
            'SELECT id FROM portal_projection_outbox
             WHERE delivered_at IS NULL AND (dead_lettered_at IS NULL OR is_revocation=1)
             ORDER BY id' . $lock
        )->fetchColumn();
        if ($pending !== false) {
            throw new DomainException(
                'Deliver or explicitly resolve every pending client portal projection before changing the External Operations URL, application key, or delivery credentials.'
            );
        }

        $ordinary = $pdo->prepare(
            'SELECT id FROM integration_outbox WHERE delivered_at IS NULL AND integration_key=? ORDER BY id LIMIT 1' . $lock
        );
        $ordinary->execute([(string)($current['application_key'] ?? '')]);
        if ($ordinary->fetchColumn() !== false) {
            throw new DomainException('Deliver or resolve every pending External Operations event before changing its signing contract.');
        }

        if ($this->tableExists($pdo, 'managed_delivery_intent_outbox')) {
            $managed = $pdo->query(
                "SELECT id FROM managed_delivery_intent_outbox
                 WHERE transport_mode='external_ops'
                   AND ((delivered_at IS NULL AND (dead_lettered_at IS NULL OR intent_type='revoke'))
                     OR (intent_type='provision' AND delivered_at IS NOT NULL AND revoked_at IS NULL))
                 ORDER BY id LIMIT 1" . $lock
            )->fetchColumn();
            if ($managed !== false) {
                throw new DomainException(
                    'Deliver or revoke every managed delivery tied to this External Operations contract before changing its URL, application key, or delivery credentials.'
                );
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
            $statement->execute([$table]);
            return (bool)$statement->fetchColumn();
        }
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        $statement->execute([$table]);
        return (bool)$statement->fetchColumn();
    }

    /**
     * Return only non-secret setting categories that block outbound delivery.
     *
     * @param array<string,mixed> $config
     * @return list<string>
     */
    public static function deliveryIssues(array $config, ?array $credentials = null): array
    {
        $issues = [];
        $applicationKey = trim((string)($config['application_key'] ?? ''));
        if ($applicationKey === '') {
            $issues[] = 'application key';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $applicationKey)) {
            $issues[] = 'valid application key';
        }
        $webhookUrl = trim((string)($config['webhook_url'] ?? ''));
        if ($webhookUrl === '') {
            $issues[] = 'signed event URL';
        } else {
            $parts = parse_url($webhookUrl);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || ($scheme !== 'https' && !$localHost)) {
                $issues[] = 'valid signed event URL';
            }
        }
        if (!empty($config['credentials_unreadable'])) {
            $issues[] = 'stored delivery credentials cannot be decrypted';
            return $issues;
        }
        foreach ([
            'access_client_id' => 'access service-token ID',
            'access_client_secret' => 'access service-token secret',
        ] as $field => $label) {
            if (trim((string)($config[$field] ?? '')) === '') {
                $issues[] = $label;
            }
        }
        if (!in_array('access service-token ID', $issues, true)
            && mb_strlen((string)$config['access_client_id']) > 500) {
            $issues[] = 'valid access service-token ID';
        }
        if (!in_array('access service-token secret', $issues, true)
            && mb_strlen((string)$config['access_client_secret']) > 1000) {
            $issues[] = 'valid access service-token secret';
        }
        $signingSource = $credentials ?? $config;
        $signingState = ExternalOpsSigner::publicState($signingSource);
        if ((string)$signingState['signing_mode'] === ExternalOpsSigner::HMAC_SHA256
            && trim((string)($signingSource['hmac_secret'] ?? '')) === '') {
            $issues[] = 'HMAC secret';
        } else {
            $signingIssue = ExternalOpsSigner::deliveryIssue($signingSource);
            if ($signingIssue !== null) {
                $issues[] = $signingIssue;
            }
        }

        return $issues;
    }
}
