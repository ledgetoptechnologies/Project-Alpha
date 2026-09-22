<?php
// src/services/PaymentProcessorImportService.php
// Provider-neutral import of successful external processor payments.

require_once __DIR__ . '/../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../utils/api_v2_directory_management.php';

class PaymentProcessorImportService
{
    public static function standaloneImportEnabled(array $appConfig): bool
    {
        return !empty($appConfig['processor_import_standalone_income']);
    }

    public static function autoCreateClientsEnabled(array $appConfig): bool
    {
        return self::standaloneImportEnabled($appConfig)
            && !empty($appConfig['processor_import_auto_create_clients']);
    }

    public static function hasProjectMetadata(array $metadata): bool
    {
        return !empty($metadata['pa_project_invoice_id']) || !empty($metadata['project_invoice_id']);
    }

    public static function invoiceIdFromMetadata(array $metadata): ?int
    {
        $invoiceId = $metadata['pa_invoice_id'] ?? $metadata['invoice_id'] ?? null;
        if ($invoiceId === null || $invoiceId === '') {
            return null;
        }
        $invoiceId = (int)$invoiceId;
        return $invoiceId > 0 ? $invoiceId : null;
    }

    public static function hasPaMetadata(array $metadata): bool
    {
        return self::hasProjectMetadata($metadata) || self::invoiceIdFromMetadata($metadata) !== null;
    }

    public static function importStandalone(PDO $pdo, array $appConfig, array $transaction): array
    {
        self::ensureSchema($pdo);
        $provider = self::cleanProvider((string)($transaction['provider'] ?? ''));
        $providerPaymentId = trim((string)($transaction['provider_payment_id'] ?? ''));
        if ($provider === '' || $providerPaymentId === '') {
            throw new InvalidArgumentException('Processor transaction requires provider and provider payment ID.');
        }

        $metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];
        if (self::hasPaMetadata($metadata)) {
            return ['status' => 'skipped', 'reason' => 'pa_metadata_present', 'payment_id' => null];
        }

        $ledgerId = self::upsertTransaction($pdo, $transaction, 'skipped', null, null);
        $existingPayment = self::findExistingPaymentId($pdo, $provider, $providerPaymentId, $ledgerId);
        if ($existingPayment !== null) {
            self::markLedger($pdo, $ledgerId, 'imported', null, $existingPayment);
            return ['status' => 'duplicate', 'reason' => 'already_imported', 'payment_id' => $existingPayment, 'transaction_id' => $ledgerId];
        }

        if (!self::standaloneImportEnabled($appConfig)) {
            self::markLedger($pdo, $ledgerId, 'skipped', 'Standalone processor income import is disabled.', null);
            return ['status' => 'skipped', 'reason' => 'disabled', 'payment_id' => null, 'transaction_id' => $ledgerId];
        }

        if (strtolower((string)($transaction['status'] ?? '')) !== 'succeeded') {
            self::markLedger($pdo, $ledgerId, 'skipped', 'Processor payment is not succeeded.', null);
            return ['status' => 'skipped', 'reason' => 'not_succeeded', 'payment_id' => null, 'transaction_id' => $ledgerId];
        }

        $netAmount = $transaction['net_amount'] ?? null;
        $feeAmount = $transaction['fee_amount'] ?? null;
        if ($netAmount === null || $feeAmount === null || (float)$netAmount <= 0) {
            self::markLedger($pdo, $ledgerId, 'failed', 'Net payout and processor fee are required for standalone import.', null);
            return ['status' => 'failed', 'reason' => 'missing_net_or_fee', 'payment_id' => null, 'transaction_id' => $ledgerId];
        }

        [$clientId, $directoryOwnershipBlocksClientIdentity] = self::clientIdentityForStandaloneImport($pdo, $appConfig, $transaction);

        $paymentId = self::insertStandalonePayment(
            $pdo,
            $transaction,
            $ledgerId,
            $clientId,
            $directoryOwnershipBlocksClientIdentity
        );
        self::markLedger($pdo, $ledgerId, 'imported', null, $paymentId);

        return ['status' => 'imported', 'reason' => null, 'payment_id' => $paymentId, 'transaction_id' => $ledgerId];
    }

    public static function ensureSchema(PDO $pdo): void
    {
        invoice_ensure_payments_schema($pdo);
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS processor_payment_transactions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(50) NOT NULL,
                provider_payment_id VARCHAR(255) NOT NULL,
                provider_charge_id VARCHAR(255) NULL,
                provider_customer_id VARCHAR(255) NULL,
                status VARCHAR(50) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'usd',
                gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                fee_amount DECIMAL(12,2) NULL,
                net_amount DECIMAL(12,2) NULL,
                paid_at DATETIME NULL,
                payer_name VARCHAR(255) NULL,
                payer_email VARCHAR(255) NULL,
                payer_phone VARCHAR(50) NULL,
                payer_address_line1 VARCHAR(255) NULL,
                payer_address_line2 VARCHAR(255) NULL,
                payer_city VARCHAR(100) NULL,
                payer_state VARCHAR(50) NULL,
                payer_postal_code VARCHAR(20) NULL,
                payer_country VARCHAR(100) NULL,
                payment_id INT NULL,
                import_status ENUM('skipped','imported','failed') NOT NULL DEFAULT 'skipped',
                import_error VARCHAR(1000) NULL,
                metadata_json JSON NULL,
                raw_summary_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_processor_payment (provider, provider_payment_id),
                INDEX idx_processor_payment_status (provider, status, paid_at),
                INDEX idx_processor_import_status (import_status, updated_at),
                INDEX idx_processor_payment_link (payment_id),
                CONSTRAINT fk_processor_payment_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS processor_webhook_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(50) NOT NULL,
                provider_event_id VARCHAR(255) NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                status ENUM('processing','processed','failed') NOT NULL DEFAULT 'processing',
                attempts SMALLINT NOT NULL DEFAULT 1,
                last_error TEXT NULL,
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_processor_webhook_event (provider, provider_event_id),
                INDEX idx_processor_webhook_status (provider, status, received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function upsertTransaction(PDO $pdo, array $tx, string $importStatus, ?string $error, ?int $paymentId): int
    {
        $metadataJson = self::jsonOrNull($tx['metadata'] ?? []);
        $summaryJson = self::jsonOrNull($tx['raw_summary'] ?? []);
        $stmt = $pdo->prepare(
            'INSERT INTO processor_payment_transactions
                (provider, provider_payment_id, provider_charge_id, provider_customer_id, status, currency,
                 gross_amount, fee_amount, net_amount, paid_at, payer_name, payer_email, payer_phone,
                 payer_address_line1, payer_address_line2, payer_city, payer_state, payer_postal_code, payer_country,
                 payment_id, import_status, import_error, metadata_json, raw_summary_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                 provider_charge_id=VALUES(provider_charge_id),
                 provider_customer_id=VALUES(provider_customer_id),
                 status=VALUES(status),
                 currency=VALUES(currency),
                 gross_amount=VALUES(gross_amount),
                 fee_amount=VALUES(fee_amount),
                 net_amount=VALUES(net_amount),
                 paid_at=VALUES(paid_at),
                 payer_name=VALUES(payer_name),
                 payer_email=VALUES(payer_email),
                 payer_phone=VALUES(payer_phone),
                 payer_address_line1=VALUES(payer_address_line1),
                 payer_address_line2=VALUES(payer_address_line2),
                 payer_city=VALUES(payer_city),
                 payer_state=VALUES(payer_state),
                 payer_postal_code=VALUES(payer_postal_code),
                 payer_country=VALUES(payer_country),
                 payment_id=COALESCE(processor_payment_transactions.payment_id, VALUES(payment_id)),
                 import_status=IF(processor_payment_transactions.import_status="imported", processor_payment_transactions.import_status, VALUES(import_status)),
                 import_error=IF(processor_payment_transactions.import_status="imported", processor_payment_transactions.import_error, VALUES(import_error)),
                 metadata_json=VALUES(metadata_json),
                 raw_summary_json=VALUES(raw_summary_json)'
        );
        $stmt->execute([
            self::cleanProvider((string)$tx['provider']),
            trim((string)$tx['provider_payment_id']),
            self::nullableString($tx['provider_charge_id'] ?? null, 255),
            self::nullableString($tx['provider_customer_id'] ?? null, 255),
            self::nullableString($tx['status'] ?? 'succeeded', 50) ?: 'succeeded',
            strtolower(self::nullableString($tx['currency'] ?? 'usd', 3) ?: 'usd'),
            round((float)($tx['gross_amount'] ?? 0), 2),
            array_key_exists('fee_amount', $tx) && $tx['fee_amount'] !== null ? round((float)$tx['fee_amount'], 2) : null,
            array_key_exists('net_amount', $tx) && $tx['net_amount'] !== null ? round((float)$tx['net_amount'], 2) : null,
            self::dateTimeOrNull($tx['paid_at'] ?? null),
            self::nullableString($tx['payer_name'] ?? null, 255),
            self::emailOrNull($tx['payer_email'] ?? null),
            self::nullableString($tx['payer_phone'] ?? null, 50),
            self::nullableString($tx['payer_address_line1'] ?? null, 255),
            self::nullableString($tx['payer_address_line2'] ?? null, 255),
            self::nullableString($tx['payer_city'] ?? null, 100),
            self::nullableString($tx['payer_state'] ?? null, 50),
            self::nullableString($tx['payer_postal_code'] ?? null, 20),
            self::nullableString($tx['payer_country'] ?? null, 100),
            $paymentId,
            $importStatus,
            self::nullableString($error, 1000),
            $metadataJson,
            $summaryJson,
        ]);

        $id = (int)$pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $lookup = $pdo->prepare('SELECT id FROM processor_payment_transactions WHERE provider=? AND provider_payment_id=? LIMIT 1');
        $lookup->execute([self::cleanProvider((string)$tx['provider']), trim((string)$tx['provider_payment_id'])]);
        return (int)$lookup->fetchColumn();
    }

    private static function findExistingPaymentId(PDO $pdo, string $provider, string $providerPaymentId, int $ledgerId): ?int
    {
        $stmt = $pdo->prepare('SELECT payment_id FROM processor_payment_transactions WHERE id=? AND payment_id IS NOT NULL');
        $stmt->execute([$ledgerId]);
        $paymentId = (int)($stmt->fetchColumn() ?: 0);
        if ($paymentId > 0) {
            return $paymentId;
        }
        $payment = $pdo->prepare('SELECT id FROM payments WHERE processor_provider=? AND processor_payment_id=? LIMIT 1');
        $payment->execute([$provider, $providerPaymentId]);
        $paymentId = (int)($payment->fetchColumn() ?: 0);
        if ($paymentId <= 0 && $provider === 'stripe') {
            $legacy = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id=? LIMIT 1');
            $legacy->execute([$providerPaymentId]);
            $paymentId = (int)($legacy->fetchColumn() ?: 0);
        }
        return $paymentId > 0 ? $paymentId : null;
    }

    private static function markLedger(PDO $pdo, int $ledgerId, string $status, ?string $error, ?int $paymentId): void
    {
        $pdo->prepare('UPDATE processor_payment_transactions SET import_status=?, import_error=?, payment_id=COALESCE(?, payment_id) WHERE id=?')
            ->execute([$status, self::nullableString($error, 1000), $paymentId, $ledgerId]);
    }

    private static function matchOrCreateClient(PDO $pdo, array $tx): ?int
    {
        $name = self::nullableString($tx['payer_name'] ?? null, 150);
        $email = self::emailOrNull($tx['payer_email'] ?? null);
        if ($name === null || $email === null) {
            return null;
        }

        $existing = $pdo->prepare('SELECT id FROM clients WHERE LOWER(email)=LOWER(?) AND deleted_at IS NULL ORDER BY archived ASC, id ASC LIMIT 1');
        $existing->execute([$email]);
        $clientId = (int)($existing->fetchColumn() ?: 0);
        if ($clientId > 0) {
            self::enrichClient($pdo, $clientId, $tx);
            return $clientId;
        }

        $owns=!$pdo->inTransaction();
        try {
            if($owns)$pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO clients
                    (name, email, phone, address_line1, address_line2, city, state, postal_code, country, organization_id, client_type, notes, source_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "unknown", ?, ?)'
            );
            $insert->execute([
                $name,
                $email,
                self::nullableString($tx['payer_phone'] ?? null, 50),
                self::nullableString($tx['payer_address_line1'] ?? null, 255),
                self::nullableString($tx['payer_address_line2'] ?? null, 255),
                self::nullableString($tx['payer_city'] ?? null, 100),
                self::stateOrNull($tx['payer_state'] ?? null),
                self::nullableString($tx['payer_postal_code'] ?? null, 20),
                self::nullableString($tx['payer_country'] ?? null, 100) ?: 'US',
                self::defaultOrganizationId($pdo),
                'Created from ' . self::cleanProvider((string)$tx['provider']) . ' standalone payment import.',
                portal_projection_source_version(),
            ]);
            $clientId=(int)$pdo->lastInsertId();
            api_v2_directory_record($pdo, 'client', $clientId);
            $projection=new \App\Services\PortalProjectionMutationService();$projection->afterMutation($pdo,$projection->clientScopes($pdo,$clientId));if($owns)$pdo->commit();return$clientId;
        } catch (Throwable$error) {if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    /** @return array{0:?int,1:bool} client ID and whether manual assignment is required */
    private static function clientIdentityForStandaloneImport(PDO $pdo, array $appConfig, array $tx): array
    {
        // Processor jobs have no browser session and must not use the auditing
        // browser-write guard. A managed Directory is authoritative for client
        // identity, so retain the financial import but leave its client unset.
        $manualReviewRequired = self::directoryOwnershipBlocksClientIdentity($pdo);
        if ($manualReviewRequired || !self::autoCreateClientsEnabled($appConfig)) {
            return [null, $manualReviewRequired];
        }

        return [self::matchOrCreateClient($pdo, $tx), false];
    }

    /**
     * Background imports are not local directory writers.  Read status without
     * recording a browser/audit transition, and fail closed if that read cannot
     * establish that local client identity is available.
     */
    private static function directoryOwnershipBlocksClientIdentity(PDO $pdo): bool
    {
        try {
            $status = api_v2_directory_management_status($pdo, false);
            if (!is_array($status) || !array_key_exists('effective', $status) || !is_bool($status['effective'])) {
                return true;
            }

            return $status['effective'] || (($status['reason'] ?? null) === 'managed_degraded');
        } catch (Throwable) {
            return true;
        }
    }

    private static function enrichClient(PDO $pdo, int $clientId, array $tx): void
    {
        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $pdo->prepare(
            'UPDATE clients SET
                phone=COALESCE(NULLIF(phone, ""), ?),
                address_line1=COALESCE(NULLIF(address_line1, ""), ?),
                address_line2=COALESCE(NULLIF(address_line2, ""), ?),
                city=COALESCE(NULLIF(city, ""), ?),
                state=COALESCE(NULLIF(state, ""), ?),
                postal_code=COALESCE(NULLIF(postal_code, ""), ?),
                country=COALESCE(NULLIF(country, ""), ?)
             WHERE id=?'
            )->execute([
            self::nullableString($tx['payer_phone'] ?? null, 50),
            self::nullableString($tx['payer_address_line1'] ?? null, 255),
            self::nullableString($tx['payer_address_line2'] ?? null, 255),
            self::nullableString($tx['payer_city'] ?? null, 100),
            self::stateOrNull($tx['payer_state'] ?? null),
            self::nullableString($tx['payer_postal_code'] ?? null, 20),
            self::nullableString($tx['payer_country'] ?? null, 100),
            $clientId,
            ]);
            api_v2_directory_record($pdo, 'client', $clientId);
            if ($owns) $pdo->commit();
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function insertStandalonePayment(PDO $pdo, array $tx, int $ledgerId, ?int $clientId, bool $manualReviewRequired = false): int
    {
        $paidAt = self::dateTimeOrNull($tx['paid_at'] ?? null);
        $paymentDate = $paidAt ? substr($paidAt, 0, 10) : date('Y-m-d');
        $provider = self::cleanProvider((string)$tx['provider']);
        $providerPaymentId = trim((string)$tx['provider_payment_id']);
        $gross = round((float)($tx['gross_amount'] ?? 0), 2);
        $fee = round((float)($tx['fee_amount'] ?? 0), 2);
        $net = round((float)($tx['net_amount'] ?? 0), 2);
        $notes = trim((string)($tx['description'] ?? ''));
        if ($notes === '') {
            $notes = ucfirst($provider) . ' standalone processor income';
        }
        if ($manualReviewRequired) {
            $notes .= ' Client assignment requires manual review because automatic identity matching is unavailable.';
        }

        $insert = $pdo->prepare(
            'INSERT INTO payments
                (client_id, invoice_id, contract_id, organization_id, amount, payment_method,
                 processor_provider, processor_payment_id, processor_transaction_id,
                 processor_gross_amount, processor_fee_amount, processor_net_amount, processor_fee_policy, processor_fee_source,
                 stripe_payment_intent_id, reference_number, notes, status, payment_date, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            $clientId,
            null,
            null,
            self::defaultOrganizationId($pdo),
            $net,
            $provider,
            $provider,
            $providerPaymentId,
            $ledgerId,
            $gross,
            $fee,
            $net,
            'unknown',
            ($fee > 0 || $net > 0) ? 'actual' : 'unknown',
            $provider === 'stripe' ? $providerPaymentId : null,
            self::nullableString($tx['provider_charge_id'] ?? $providerPaymentId, 255),
            $notes,
            'succeeded',
            $paymentDate,
        ]);

        return (int)$pdo->lastInsertId();
    }

    private static function cleanProvider(string $provider): string
    {
        return substr(preg_replace('/[^a-z0-9_-]/', '', strtolower($provider)), 0, 50);
    }

    private static function defaultOrganizationId(PDO $pdo): ?int
    {
        return null;
    }

    private static function nullableString($value, int $max): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }

    private static function emailOrNull($value): ?string
    {
        $value = self::nullableString($value, 255);
        return $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private static function stateOrNull($value): ?string
    {
        $value = self::nullableString($value, 50);
        if ($value === null) {
            return null;
        }
        return mb_substr($value, 0, 2);
    }

    private static function dateTimeOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int)$value);
        }
        $time = strtotime((string)$value);
        return $time ? date('Y-m-d H:i:s', $time) : null;
    }

    private static function jsonOrNull($value): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return null;
        }
        return $json;
    }
}
