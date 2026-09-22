<?php

declare(strict_types=1);

namespace Tests\Payments;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProcessorImportManagedDirectoryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/services/PaymentProcessorImportService.php';
    }

    public function testUnmanagedImportKeepsAutomaticCreateAndEnrichment(): void
    {
        $pdo = $this->clientDatabase();
        $config = ['processor_import_standalone_income' => true, 'processor_import_auto_create_clients' => true];

        [$createdClientId, $manualReview] = $this->clientIdentity($pdo, $config, $this->transaction('new@example.test', 'New payer'));
        self::assertFalse($manualReview);
        self::assertNotNull($createdClientId);
        self::assertSame('New payer', $pdo->query('SELECT name FROM clients WHERE id=' . (int)$createdClientId)->fetchColumn());

        $pdo->prepare('INSERT INTO clients(public_id,name,email,phone,client_type) VALUES(?,?,?,?,?)')
            ->execute([str_repeat('a', 32), 'Existing payer', 'existing@example.test', null, 'unknown']);
        $existingClientId = (int)$pdo->lastInsertId();
        [$matchedClientId, $existingManualReview] = $this->clientIdentity($pdo, $config, $this->transaction('existing@example.test', 'Changed display name'));

        self::assertFalse($existingManualReview);
        self::assertSame($existingClientId, $matchedClientId);
        self::assertSame('555-0100', $pdo->query('SELECT phone FROM clients WHERE id=' . $existingClientId)->fetchColumn());
        self::assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
    }

    public function testActivatedOwnershipLeavesProcessorDataUnassignedWithoutDirectoryChanges(): void
    {
        $pdo = $this->clientDatabase();
        $this->activateDirectoryOwnership($pdo);
        $pdo->prepare('INSERT INTO clients(public_id,name,email,phone,client_type) VALUES(?,?,?,?,?)')
            ->execute([str_repeat('b', 32), 'Directory client', 'managed@example.test', null, 'unknown']);
        $beforeClients = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();

        [$clientId, $manualReview] = $this->clientIdentity(
            $pdo,
            ['processor_import_standalone_income' => true, 'processor_import_auto_create_clients' => true],
            $this->transaction('managed@example.test', 'Untrusted payer name')
        );

        self::assertNull($clientId, 'An email match is not an authority mapping while Directory ownership is active.');
        self::assertTrue($manualReview);
        self::assertSame($beforeClients, (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertNull($pdo->query("SELECT phone FROM clients WHERE email='managed@example.test'")->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_state')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_management_audit')->fetchColumn(), 'Background reads must not emit management audit transitions.');
    }

    public function testDegradedSentinelAlsoRequiresManualClientAssignment(): void
    {
        $pdo = $this->clientDatabase();
        $pdo->prepare('UPDATE app_config SET config_value=? WHERE organization_id=0 AND config_key=?')
            ->execute(['1', API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        $beforeClients = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();

        [$clientId, $manualReview] = $this->clientIdentity(
            $pdo,
            ['processor_import_standalone_income' => true, 'processor_import_auto_create_clients' => true],
            $this->transaction('sentinel@example.test', 'Sentinel payer')
        );

        self::assertNull($clientId);
        self::assertTrue($manualReview);
        self::assertSame($beforeClients, (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
    }

    public function testUnknownDirectoryManagementStateFailsClosedForClientIdentity(): void
    {
        $pdo = $this->clientDatabase();
        $pdo->exec('DELETE FROM app_config');

        [$clientId, $manualReview] = $this->clientIdentity(
            $pdo,
            ['processor_import_standalone_income' => true, 'processor_import_auto_create_clients' => true],
            $this->transaction('unknown@example.test', 'Unknown state payer')
        );

        self::assertNull($clientId);
        self::assertTrue($manualReview);
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
    }

    public function testManagedImportCanRetainAnUnassignedFinancialRecordForManualReview(): void
    {
        $pdo = $this->clientDatabase();
        $insert = new ReflectionMethod(\PaymentProcessorImportService::class, 'insertStandalonePayment');
        $paymentId = $insert->invoke(null, $pdo, array_replace($this->transaction('review@example.test', 'Review payer'), [
            'provider_payment_id' => 'payment-review-1',
            'gross_amount' => 125.00,
            'fee_amount' => 5.00,
            'net_amount' => 120.00,
        ]), 41, null, true);

        $payment = $pdo->query('SELECT client_id,processor_provider,processor_payment_id,processor_transaction_id,processor_net_amount,notes FROM payments WHERE id=' . (int)$paymentId)
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(null, $payment['client_id']);
        self::assertSame('example_processor', $payment['processor_provider']);
        self::assertSame('payment-review-1', $payment['processor_payment_id']);
        self::assertSame(41, (int)$payment['processor_transaction_id']);
        self::assertSame(120.0, (float)$payment['processor_net_amount']);
        self::assertStringContainsString('manual review', $payment['notes']);

        $service = (string)file_get_contents(dirname(__DIR__, 2) . '/src/services/PaymentProcessorImportService.php');

        self::assertLessThan(
            strpos($service, 'clientIdentityForStandaloneImport'),
            strpos($service, 'findExistingPaymentId'),
            'Duplicate detection must remain ahead of identity handling.'
        );
        self::assertStringContainsString('automatic identity matching is unavailable', $service);
        self::assertStringContainsString('insertStandalonePayment(', $service);
        self::assertStringContainsString('$directoryOwnershipBlocksClientIdentity', $service);
    }

    /** @return array{0:?int,1:bool} */
    private function clientIdentity(PDO $pdo, array $config, array $transaction): array
    {
        $method = new ReflectionMethod(\PaymentProcessorImportService::class, 'clientIdentityForStandaloneImport');
        return $method->invoke(null, $pdo, $config, $transaction);
    }

    private function transaction(string $email, string $name): array
    {
        return [
            'provider' => 'example_processor',
            'payer_name' => $name,
            'payer_email' => $email,
            'payer_phone' => '555-0100',
            'payer_address_line1' => '10 Example Street',
            'payer_city' => 'Exampletown',
            'payer_state' => 'IL',
            'payer_postal_code' => '60601',
            'payer_country' => 'US',
        ];
    }

    private function clientDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE clients(
                id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT, name TEXT, email TEXT, phone TEXT,
                client_type TEXT, organization_id INTEGER, address_line1 TEXT, address_line2 TEXT, city TEXT,
                state TEXT, postal_code TEXT, country TEXT, deleted_at TEXT, archived INTEGER DEFAULT 0,
                notes TEXT, source_version TEXT
            );
            CREATE TABLE organizations(id INTEGER PRIMARY KEY, public_id TEXT, archived INTEGER DEFAULT 0, deleted_at TEXT);
            CREATE TABLE app_config(organization_id INTEGER NOT NULL, config_key TEXT NOT NULL, config_value TEXT, PRIMARY KEY(organization_id, config_key));
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT, public_id TEXT, revision INTEGER, projection_sha256 TEXT, present INTEGER, PRIMARY KEY(resource_type, public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT, public_id TEXT, revision INTEGER, action TEXT, PRIMARY KEY(resource_type, public_id, revision));
            CREATE TABLE payments(
                id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, invoice_id INTEGER, contract_id INTEGER, organization_id INTEGER,
                amount REAL, payment_method TEXT, processor_provider TEXT, processor_payment_id TEXT, processor_transaction_id INTEGER,
                processor_gross_amount REAL, processor_fee_amount REAL, processor_net_amount REAL, processor_fee_policy TEXT,
                processor_fee_source TEXT, stripe_payment_intent_id TEXT, reference_number TEXT, notes TEXT, status TEXT,
                payment_date TEXT, created_at TEXT
            );
            CREATE TRIGGER client_public_id AFTER INSERT ON clients WHEN NEW.public_id IS NULL BEGIN
                UPDATE clients SET public_id=lower(hex(randomblob(16))) WHERE id=NEW.id;
            END;'
        );
        $pdo->prepare('INSERT INTO app_config(organization_id,config_key,config_value) VALUES(0,?,?)')
            ->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY, '0']);
        return $pdo;
    }

    private function activateDirectoryOwnership(PDO $pdo): void
    {
        $source = '123e4567-e89b-42d3-a456-426614174000';
        $application = '223e4567-e89b-42d3-a456-426614174000';
        $epoch = '323e4567-e89b-42d3-a456-426614174000';
        $pdo->exec(
            'CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY, filename TEXT);
            CREATE TABLE api_v2_directory_management_policy(singleton INTEGER PRIMARY KEY, configured_enabled INTEGER, ownership_active INTEGER, application_pk INTEGER, source_instance_id TEXT, application_id TEXT, history_epoch TEXT, release_attestation_sha256 TEXT, last_effective INTEGER, last_reason TEXT, configured_by INTEGER, configured_at TEXT, updated_at TEXT);
            CREATE TABLE api_v2_directory_management_attestations(attestation_sha256 TEXT PRIMARY KEY, attestation_json TEXT, created_by INTEGER);
            CREATE TABLE api_v2_directory_management_audit(id INTEGER PRIMARY KEY, event_type TEXT, outcome TEXT, reason TEXT, application_pk INTEGER, actor_user_id INTEGER, target_type TEXT, action_name TEXT, metadata_json TEXT);
            CREATE TABLE api_v2_directory_lifecycle_command_receipts(application_pk INTEGER, resource_type TEXT, history_epoch TEXT, command_id TEXT, request_sha256 TEXT, action_name TEXT, public_id TEXT, expected_revision INTEGER, expected_authorization_generation INTEGER, result_revision INTEGER, result_authorization_generation INTEGER);
            CREATE TABLE api_v2_directory_relationship_command_receipts(application_pk INTEGER, history_epoch TEXT, command_id TEXT, request_sha256 TEXT, action_name TEXT, client_public_id TEXT, expected_client_revision INTEGER, expected_authorization_generation INTEGER, result_client_revision INTEGER, result_authorization_generation INTEGER);
            CREATE TABLE api_v2_directory_binding_revoke_command_receipts(application_pk INTEGER, resource_type TEXT, history_epoch TEXT, command_id TEXT, request_sha256 TEXT, external_id TEXT, public_id TEXT, expected_resource_revision INTEGER, expected_authorization_generation INTEGER, result_authorization_generation INTEGER);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY, application_id TEXT, name TEXT);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY, source_instance_id TEXT, history_epoch TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY, authorization_generation INTEGER);
            CREATE TABLE api_keys(id INTEGER PRIMARY KEY, api_v2_application_id INTEGER, scopes TEXT, revoked_at TEXT);'
        );
        foreach (range(88, 103) as $version) {
            $pdo->prepare('INSERT INTO schema_migrations(version,filename) VALUES(?,?)')
                ->execute([$version, sprintf('%04d_', $version) . match ($version) {
                    88 => 'api_v2_application_identity.sql', 89 => 'api_v2_directory_revision_foundation.sql',
                    90 => 'api_v2_directory_binding_status_foundation.sql', 91 => 'api_v2_directory_binding_command_receipts.sql',
                    92 => 'api_v2_directory_binding_revision_refresh_receipts.sql', 93 => 'api_v2_directory_organization_profile_command_receipts.sql',
                    94 => 'api_v2_directory_client_profile_command_receipts.sql', 95 => 'api_v2_directory_binding_lifecycle.sql',
                    96 => 'api_v2_directory_backfill_attestations.sql', 97 => 'api_v2_directory_create_command_receipts.sql',
                    98 => 'external_directory_management_policy.sql', 99 => 'api_v2_directory_lifecycle_relationships.sql',
                    100 => 'project_lifecycle_api_foundation.sql', 101 => 'project_archive_presentation_revocation.sql',
                    102 => 'api_v2_project_synchronization.sql', 103 => 'external_directory_management_sentinel.sql',
                }]);
        }
        $pdo->prepare('INSERT INTO api_v2_directory_management_policy VALUES(1,1,1,?,?,?,?,?,1,?,NULL,NULL,NULL)')
            ->execute([$application === '' ? 0 : 1, $source, $application, $epoch, null, 'ready']);
    }
}
