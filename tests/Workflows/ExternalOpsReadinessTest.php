<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ExternalOpsConfigService;
use App\Services\ExternalOpsOutboxSender;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/src/services/ExternalOpsConfigService.php';
require_once dirname(__DIR__, 2) . '/src/services/ExternalOpsOutboxSender.php';

final class ExternalOpsReadinessTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE app_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 0,
                config_key TEXT NOT NULL,
                config_value TEXT NOT NULL,
                UNIQUE (organization_id, config_key)
            )'
        );
    }

    public function testHistoricalEnabledButIncompleteDeliveryIsEffectivelyPaused(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO app_config (organization_id, config_key, config_value) VALUES (0, ?, ?)'
        );
        $insert->execute(['external_ops_enabled', '1']);
        $insert->execute(['external_ops_application_key', 'legacy_pull']);

        $config = (new ExternalOpsConfigService())->load($this->pdo);

        self::assertTrue($config['configured_enabled']);
        self::assertTrue($config['enabled']);
        self::assertFalse($config['configuration_complete']);
        self::assertFalse($config['delivery_ready']);
        self::assertSame('legacy_pull', $config['application_key']);
        self::assertContains('signed event URL', $config['delivery_issues']);
        self::assertContains('access service-token ID', $config['delivery_issues']);
        self::assertContains('access service-token secret', $config['delivery_issues']);
        self::assertContains('HMAC secret', $config['delivery_issues']);
        try {
            (new ExternalOpsOutboxSender())->deliverDue($this->pdo, $config);
            self::fail('Paused delivery was incorrectly sent.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('outbound delivery is paused', $error->getMessage());
        }
    }

    public function testUnreadableStoredCredentialsPauseWithoutExposingCredentialDetails(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO app_config (organization_id, config_key, config_value) VALUES (0, ?, ?)'
        );
        foreach ([
            'external_ops_enabled' => '1',
            'external_ops_application_key' => 'legacy_pull',
            'external_ops_webhook_url' => 'https://ops.example.test/events',
            'external_ops_credentials_enc' => 'unreadable-ciphertext',
        ] as $key => $value) {
            $insert->execute([$key, $value]);
        }

        $config = (new ExternalOpsConfigService())->load($this->pdo);

        self::assertTrue($config['configured_enabled']);
        self::assertTrue($config['enabled']);
        self::assertFalse($config['configuration_complete']);
        self::assertFalse($config['delivery_ready']);
        self::assertSame(
            ['stored delivery credentials cannot be decrypted'],
            $config['delivery_issues']
        );
        self::assertSame('', $config['access_client_id']);
        self::assertSame('', $config['access_client_secret']);
        self::assertSame('', $config['hmac_secret']);
    }

    public function testSafeEncryptionDiagnosticUsesOnlyFixedCategories(): void
    {
        $previousKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY');
        try {
            self::assertSame(
                ['runtime_key' => 'missing', 'credential_record' => 'absent'],
                (new ExternalOpsConfigService())->safeEncryptionDiagnostic($this->pdo)
            );
            $this->pdo->prepare('INSERT INTO app_config (organization_id, config_key, config_value) VALUES (0, ?, ?)')
                ->execute(['external_ops_credentials_enc', 'not-a-valid-envelope']);
            self::assertSame(
                ['runtime_key' => 'missing', 'credential_record' => 'unreadable'],
                (new ExternalOpsConfigService())->safeEncryptionDiagnostic($this->pdo)
            );
            require_once dirname(__DIR__, 2) . '/src/utils/crypto.php';
            putenv('APP_ENCRYPTION_KEY=external-ops-diagnostic-correct-key');
            $encrypted = crypto_encrypt('{"diagnostic":"opaque"}');
            self::assertIsString($encrypted);
            $this->pdo->prepare('UPDATE app_config SET config_value=? WHERE organization_id=0 AND config_key=?')
                ->execute([$encrypted, 'external_ops_credentials_enc']);
            self::assertSame(
                ['runtime_key' => 'present', 'credential_record' => 'readable'],
                (new ExternalOpsConfigService())->safeEncryptionDiagnostic($this->pdo)
            );
            putenv('APP_ENCRYPTION_KEY=external-ops-diagnostic-wrong-key');
            self::assertSame(
                ['runtime_key' => 'present', 'credential_record' => 'unreadable'],
                (new ExternalOpsConfigService())->safeEncryptionDiagnostic($this->pdo)
            );
        } finally {
            $previousKey === false ? putenv('APP_ENCRYPTION_KEY') : putenv('APP_ENCRYPTION_KEY=' . $previousKey);
        }
    }
    public function testDisabledCompleteConfigurationIsCompleteButNotDeliveryReady(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/crypto.php';
        $previousKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=external-ops-readiness-test-key');
        try {
            $credentials = crypto_encrypt(json_encode([
                'access_client_id' => 'client-id',
                'access_client_secret' => 'client-secret',
                'hmac_secret' => str_repeat('h', 32),
            ], JSON_THROW_ON_ERROR));
            self::assertIsString($credentials);
            $insert = $this->pdo->prepare(
                'INSERT INTO app_config (organization_id, config_key, config_value) VALUES (0, ?, ?)'
            );
            foreach ([
                'external_ops_enabled' => '0',
                'external_ops_application_key' => 'legacy_pull',
                'external_ops_webhook_url' => 'https://ops.example.test/events',
                'external_ops_credentials_enc' => $credentials,
            ] as $key => $value) {
                $insert->execute([$key, $value]);
            }

            $config = (new ExternalOpsConfigService())->load($this->pdo);
            self::assertFalse($config['configured_enabled']);
            self::assertFalse($config['enabled']);
            self::assertTrue($config['configuration_complete']);
            self::assertFalse($config['delivery_ready']);
            self::assertSame([], $config['delivery_issues']);
        } finally {
            $previousKey === false
                ? putenv('APP_ENCRYPTION_KEY')
                : putenv('APP_ENCRYPTION_KEY=' . $previousKey);
        }
    }

    public function testHistoricalShortHmacSecretIsNotReady(): void
    {
        $issues = ExternalOpsConfigService::deliveryIssues([
            'application_key' => 'legacy_pull',
            'webhook_url' => 'https://ops.example.test/events',
            'access_client_id' => 'client-id',
            'access_client_secret' => 'client-secret',
            'hmac_secret' => 'short',
        ]);

        self::assertSame(['valid HMAC secret'], $issues);
    }

    public function testDirectMalformedDeliveryAttemptNamesOnlyMissingSettingCategories(): void
    {
        $config = [
            'enabled' => true,

            'application_key' => 'legacy_pull',
            'webhook_url' => '',
            'access_client_id' => '',
            'access_client_secret' => '',
            'hmac_secret' => '',
        ];

        try {
            (new ExternalOpsOutboxSender())->deliverDue($this->pdo, $config);
            self::fail('Malformed outbound delivery configuration was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('outbound delivery is paused', $error->getMessage());
            self::assertStringContainsString('signed event URL', $error->getMessage());
            self::assertStringContainsString('HMAC secret', $error->getMessage());
            self::assertStringNotContainsString('required integration configuration is incomplete', $error->getMessage());
        }
    }

    public function testSettingsAndCronExplainPausedPushWithoutDisablingPullSync(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings/external-ops.php');
        $handler = (string)file_get_contents($root . '/src/controllers/settings/external_ops_handler.php');
        $cron = (string)file_get_contents($root . '/src/cron/send_external_ops_outbox.php');
        $snapshot = (string)file_get_contents($root . '/src/controllers/api/ops_snapshot.php');

        self::assertStringContainsString("\$config['configured_enabled']", $view);
        self::assertStringContainsString('Outbound signed-event delivery is paused.', $view);
        self::assertStringContainsString('continues recording authoritative events', $view);
        self::assertStringContainsString('access administration remain available', $view);
        self::assertStringContainsString('Sync now', $view);
        self::assertStringContainsString('ExternalOpsSyncOrchestrator', $handler);
        self::assertGreaterThan(
            strpos($handler, 'in_array($action'),
            strpos($handler, 'elseif ($action === \'send-now\')')
        );
        self::assertStringContainsString("\$config['delivery_ready']", $handler);
        self::assertStringContainsString("\$config['delivery_ready']", $cron);
        self::assertStringContainsString('Outbound delivery paused:', $cron);
        self::assertStringContainsString('pa_external_ops_application_key($pdo)', $snapshot);
        self::assertStringNotContainsString('pa_external_ops_enabled($pdo)', $snapshot);
    }
}
