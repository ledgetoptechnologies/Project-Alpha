<?php

require_once __DIR__ . '/EmailProviders.php';
require_once __DIR__ . '/../utils/email_identity.php';

final class EmailProviderManager
{
    public function __construct(private PDO $pdo, private array $appConfig) {}

    public function connections(): array
    {
        return $this->pdo->query(
            'SELECT c.id,c.provider,c.display_name,c.sender_email,c.sender_name,c.status,c.token_expires_at,c.last_verified_at,c.last_error,
                    (s.active_connection_id=c.id) AS is_active
             FROM email_provider_connections c
             CROSS JOIN email_provider_state s
             WHERE s.id=1
             ORDER BY c.provider'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeConnection(): ?array
    {
        $this->importLegacySmtpIfNeeded();
        $statement = $this->pdo->query(
            'SELECT c.* FROM email_provider_state s
             JOIN email_provider_connections c ON c.id=s.active_connection_id
             WHERE s.id=1 LIMIT 1'
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function send(EmailMessage $message, array $context = []): SendResult
    {
        $connection = $this->activeConnection();
        if (!$connection) {
            return new SendResult(false, 'Outgoing email is not configured. Choose an active email provider in Settings.');
        }
        if (!in_array((string)$connection['status'], ['configured', 'connected'], true)) {
            $message = (string)$connection['provider'] === 'gmail'
                ? 'Google email needs authorization. Reconnect Google in Settings.'
                : 'The active email provider is unavailable. Review Outgoing Email settings.';
            return new SendResult(false, $message);
        }

        $credentials = $this->decryptCredentials((string)$connection['credentials_enc']);
        if ($credentials === null) {
            return new SendResult(false, 'The active email credentials could not be decrypted. Check APP_ENCRYPTION_KEY.');
        }
        $message->fromEmail = trim((string)($connection['sender_email'] ?? '')) ?: $message->fromEmail;
        if (empty($context['document_issuer_display'])) {
            $message->fromName = trim((string)($connection['sender_name'] ?? '')) ?: $message->fromName;
        }

        $messageKey = trim((string)($context['message_key'] ?? ''));
        if ($messageKey === '') {
            $messageKey = 'msg-' . bin2hex(random_bytes(16));
        }
        $message->messageId ??= '<' . hash('sha256', $messageKey) . '@project-alpha.local>';
        $reservation = $this->reserveDelivery($messageKey, (int)$connection['id'], $message, $context);
        if ($reservation['status'] === 'sent') {
            return new SendResult(true, 'Already sent');
        }
        if ($reservation['status'] === 'in_progress') {
            return new SendResult(false, 'Delivery is already in progress');
        }
        $deliveryId = $reservation['id'];

        if ((string)$connection['provider'] === 'gmail') {
            $provider = new GmailEmailProvider($credentials, function (array $updated, int $expiresAt) use ($connection): void {
                $encrypted = $this->encryptCredentials($updated);
                $statement = $this->pdo->prepare(
                    'UPDATE email_provider_connections
                     SET credentials_enc=?,token_expires_at=FROM_UNIXTIME(?),status="connected",last_error=NULL
                     WHERE id=?'
                );
                $statement->execute([$encrypted, $expiresAt, (int)$connection['id']]);
            });
        } else {
            $provider = new SmtpEmailProvider($credentials);
        }

        $result = $provider->send($message);
        $result->deliveryLogId = $deliveryId;
        $this->finishDelivery($deliveryId, (int)$connection['id'], $result);
        return $result;
    }

    public function upsertSmtp(array $credentials, string $senderEmail, string $senderName, ?int $actorId = null): int
    {
        $encrypted = $this->encryptCredentials($credentials);
        $statement = $this->pdo->prepare(
            'INSERT INTO email_provider_connections
                (provider,display_name,sender_email,sender_name,credentials_enc,status,last_error,created_by)
             VALUES ("smtp","SMTP",?,?,?,"configured",NULL,?)
             ON DUPLICATE KEY UPDATE sender_email=VALUES(sender_email),sender_name=VALUES(sender_name),
                credentials_enc=VALUES(credentials_enc),status="configured",last_error=NULL,updated_at=NOW()'
        );
        $statement->execute([$senderEmail, $senderName, $encrypted, $actorId]);
        return (int)$this->pdo->query('SELECT id FROM email_provider_connections WHERE provider="smtp"')->fetchColumn();
    }

    public function upsertGmail(array $credentials, string $senderEmail, string $senderName, ?int $actorId = null): int
    {
        $encrypted = $this->encryptCredentials($credentials);
        $statement = $this->pdo->prepare(
            'INSERT INTO email_provider_connections
                (provider,display_name,sender_email,sender_name,credentials_enc,status,last_verified_at,last_error,created_by)
             VALUES ("gmail","Google Gmail",?,?,?,"connected",NOW(),NULL,?)
             ON DUPLICATE KEY UPDATE sender_email=VALUES(sender_email),sender_name=VALUES(sender_name),
                credentials_enc=VALUES(credentials_enc),status="connected",last_verified_at=NOW(),last_error=NULL,updated_at=NOW()'
        );
        $statement->execute([$senderEmail, $senderName, $encrypted, $actorId]);
        return (int)$this->pdo->query('SELECT id FROM email_provider_connections WHERE provider="gmail"')->fetchColumn();
    }

    public function activate(int $connectionId, ?int $actorId = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE email_provider_state s
             JOIN email_provider_connections c ON c.id=?
             SET s.active_connection_id=c.id,s.updated_by=?,s.updated_at=NOW()
             WHERE s.id=1 AND c.status IN ("configured","connected")'
        );
        $statement->execute([$connectionId, $actorId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Select a configured provider before activating it.');
        }
    }

    public function disconnect(int $connectionId, ?int $actorId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE email_provider_state SET active_connection_id=NULL,updated_by=? WHERE id=1 AND active_connection_id=?')
                ->execute([$actorId, $connectionId]);
            $this->pdo->prepare('UPDATE email_provider_connections SET status="disabled",credentials_enc=?,token_expires_at=NULL WHERE id=?')
                ->execute([$this->encryptCredentials([]), $connectionId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function markGmailReauthorizationRequired(int $connectionId, string $error): void
    {
        $statement = $this->pdo->prepare('UPDATE email_provider_connections SET status="reauth_required",last_error=? WHERE id=?');
        $statement->execute([mb_substr($error, 0, 1000), $connectionId]);
    }

    private function importLegacySmtpIfNeeded(): void
    {
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM email_provider_connections')->fetchColumn();
        if ($count > 0 || trim((string)($this->appConfig['smtp_host'] ?? '')) === '') {
            return;
        }
        $credentials = EmailService::getSmtpConfig($this->appConfig);
        $senderEmail = EmailService::getFromEmail($this->appConfig, $credentials);
        $id = $this->upsertSmtp($credentials, $senderEmail, EmailService::getFromName($this->appConfig));
        $this->activate($id);
    }

    private function encryptCredentials(array $credentials): string
    {
        $encrypted = crypto_encrypt(json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($encrypted === null) {
            throw new RuntimeException('APP_ENCRYPTION_KEY must be configured before saving email credentials.');
        }
        return $encrypted;
    }

    private function decryptCredentials(string $encrypted): ?array
    {
        $plaintext = crypto_decrypt($encrypted);
        if (!is_string($plaintext)) {
            return null;
        }
        $decoded = json_decode($plaintext, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{status:'reserved'|'sent'|'in_progress',id:?int} */
    private function reserveDelivery(string $messageKey, int $connectionId, EmailMessage $message, array $context): array
    {
        $documentType = (string)($context['document_type'] ?? 'other');
        $allowedTypes = ['quote','contract','invoice','project_invoice','onboarding','notification','other'];
        if (!in_array($documentType, $allowedTypes, true)) {
            $documentType = 'other';
        }
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO email_delivery_log
                    (message_key,provider_connection_id,document_type,document_id,document_revision,recipient,subject,status)
                 VALUES (?,?,?,?,?,?,?,"pending")'
            );
            $statement->execute([
                $messageKey, $connectionId, $documentType, $context['document_id'] ?? null,
                $context['document_revision'] ?? null, $message->to, $message->subject,
            ]);
            return ['status' => 'reserved', 'id' => (int)$this->pdo->lastInsertId()];
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000') {
                throw $error;
            }
            $statement = $this->pdo->prepare('SELECT id,status,updated_at FROM email_delivery_log WHERE message_key=?');
            $statement->execute([$messageKey]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            $pendingIsFresh = ($existing['status'] ?? '') === 'pending'
                && strtotime((string)($existing['updated_at'] ?? '')) > time() - 600;
            if (($existing['status'] ?? '') === 'sent') {
                return ['status' => 'sent', 'id' => (int)$existing['id']];
            }
            if ($pendingIsFresh) {
                return ['status' => 'in_progress', 'id' => (int)$existing['id']];
            }
            $this->pdo->prepare('UPDATE email_delivery_log SET status="pending",error_message=NULL,updated_at=NOW() WHERE id=?')
                ->execute([(int)$existing['id']]);
            return ['status' => 'reserved', 'id' => (int)$existing['id']];
        }
    }

    private function finishDelivery(int $deliveryId, int $connectionId, SendResult $result): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE email_delivery_log SET status=?,provider_message_id=?,error_message=?,sent_at=?,updated_at=NOW() WHERE id=?'
        );
        $statement->execute([
            $result->success ? 'sent' : 'failed',
            $result->providerMessageId,
            $result->success ? null : mb_substr($result->message, 0, 1000),
            $result->success ? date('Y-m-d H:i:s') : null,
            $deliveryId,
        ]);
        if ($result->success) {
            $this->pdo->prepare('UPDATE email_provider_connections SET last_verified_at=NOW(),last_error=NULL WHERE id=?')->execute([$connectionId]);
        } else {
            $this->pdo->prepare('UPDATE email_provider_connections SET last_error=? WHERE id=?')
                ->execute([mb_substr($result->message, 0, 1000), $connectionId]);
            if(str_contains(strtolower($result->message),'authorization')||str_contains(strtolower($result->message),'invalid_grant')){
                $this->markGmailReauthorizationRequired($connectionId,$result->message);
            }
        }
    }
}
