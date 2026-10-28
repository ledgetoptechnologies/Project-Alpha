<?php
// src/utils/webhook_logger.php
// Shared helper to log Stripe webhook attempts into webhook_event_log.
// Designed to be safe to call before DB is fully initialized.

/**
 * Insert a new webhook attempt row and return its id.
 * If the database is not available or the table does not exist, return null.
 */
function webhook_log_insert($pdo, string $endpoint, string $payload, string $sigHeader, string $ip, bool $verified = false): ?int {
    if (!$pdo) {
        return null;
    }
    try {
        $tableExists = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'webhook_event_log'")->fetchColumn();
        if (!$tableExists) {
            return null;
        }
        $event = $verified ? json_decode($payload, true) : null;
        $stmt = $pdo->prepare("INSERT INTO webhook_event_log
            (endpoint, received_at, event_type, event_id, signature_present, ip_address, payload_size, raw_payload)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $endpoint,
            is_array($event) ? substr((string)($event['type'] ?? ''), 0, 100) : null,
            is_array($event) ? substr((string)($event['id'] ?? ''), 0, 100) : null,
            !empty($sigHeader) ? 1 : 0,
            filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            strlen($payload),
            null // Event IDs and status are sufficient; never retain webhook bodies.
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        @error_log('[WebhookLogger] insert failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Update a webhook attempt row after validation / processing.
 */
function webhook_log_update($pdo, ?int $logId, ?bool $signatureValid, int $httpCode, ?string $errorMessage = null): void {
    if (!$pdo || !$logId) {
        return;
    }
    try {
        $stmt = $pdo->prepare("UPDATE webhook_event_log
            SET signature_valid = ?, http_response_code = ?, error_message = ?
            WHERE id = ?");
        $stmt->execute([
            $signatureValid === null ? null : ($signatureValid ? 1 : 0),
            $httpCode,
            $errorMessage,
            $logId
        ]);
    } catch (Throwable $e) {
        @error_log('[WebhookLogger] update failed: ' . $e->getMessage());
    }
}
