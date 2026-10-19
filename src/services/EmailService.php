<?php
// src/services/EmailService.php
// Centralizes SMTP config loading, password decryption, and email sending.

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/email_identity.php';
require_once __DIR__ . '/EmailProviders.php';

class EmailService {
    /**
     * Build an SMTP config array from appConfig, decrypting the password if present.
     *
     * @param array $appConfig
     * @return array{host:string,port:int,secure:string,username:string,password:string}
     */
    public static function getSmtpConfig(array $appConfig): array {
        $pass = '';
        if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
            $encVal = $appConfig['smtp_password_enc'];
            if (strpos($encVal, 'plain::') === 0) {
                $pass = substr($encVal, 7);
            } else {
                $pt = crypto_decrypt($encVal);
                if (is_string($pt)) { $pass = $pt; }
            }
        }

        $secure = strtolower((string)($appConfig['smtp_secure'] ?? 'tls'));
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $secure = 'tls';
        }

        return [
            'host'     => (string)($appConfig['smtp_host'] ?? ''),
            'port'     => (int)($appConfig['smtp_port'] ?? 587),
            'secure'   => $secure,
            'username' => (string)($appConfig['smtp_username'] ?? ''),
            'password' => $pass,
        ];
    }

    /**
     * Determine the from email address using smtp_from_email fallback rules.
     *
     * @param array $appConfig
     * @param array $smtpConfig
     * @return string
     */
    public static function getFromEmail(array $appConfig, array $smtpConfig): string {
        $from = trim((string)($appConfig['smtp_from_email'] ?? ''));
        if ($from === '') {
            $from = trim((string)($appConfig['from_email'] ?? ''));
        }
        if ($from === '' && $smtpConfig['username'] !== '') {
            $from = $smtpConfig['username'];
        }
        if ($from === '') {
            $from = 'no-reply@localhost';
        }
        return $from;
    }

    /**
     * Determine the from name using smtp_from_name fallback rules.
     *
     * @param array $appConfig
     * @return string
     */
    public static function getFromName(array $appConfig): string {
        return pa_email_sender_name($appConfig, true);
    }

    public static function applyAutomatedNotice(string $body, bool $isHtml, array $appConfig): string {
        if (empty($appConfig['email_no_reply_notice_enabled'])) {
            return $body;
        }

        $notice = trim((string)($appConfig['email_no_reply_notice_text'] ?? ''));
        if ($notice === '') {
            $notice = 'This is an automated message. Please do not reply to this email.';
        }

        if ($isHtml) {
            return $body
                . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0 12px">'
                . '<p style="color:#6b7280;font-size:12px;margin:0">'
                . htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p>';
        }

        return rtrim($body) . "\n\n" . $notice;
    }

    /**
     * Send through the one explicitly active provider. SMTP and Gmail may both
     * remain configured, but there is never an automatic provider fallback.
     *
     * Options:
     *   - from_email: override from email
     *   - from_name:  override from name
     *   - envelope_from: override envelope sender
     *   - attachments: array of ['filename','content','mime']
     *   - is_html:     whether body is HTML (default true)
     *   - message_id: optional stable RFC 5322 Message-ID
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param array $options
     * @return array{0:bool,1:string,2?:int|null} [ok, error, delivery log id]
     */
    public static function sendEmail(string $to, string $subject, string $body, array $options = []): array {
        global $appConfig, $pdo;

        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Invalid recipient email'];
        }

        $cfg        = self::getSmtpConfig($appConfig);
        $fromEmail  = trim((string)($options['from_email'] ?? self::getFromEmail($appConfig, $cfg)));
        $fromName   = trim((string)($options['from_name']  ?? self::getFromName($appConfig)));
        $envelope   = trim((string)($options['envelope_from'] ?? ($cfg['username'] ?: $fromEmail)));
        $attachments = is_array($options['attachments'] ?? null) ? $options['attachments'] : [];
        $isHtml     = (bool)($options['is_html'] ?? true);
        $messageId = trim((string)($options['message_id'] ?? ''));
        $body       = self::applyAutomatedNotice($body, $isHtml, $appConfig);

        if (!$pdo instanceof PDO) {
            return [false, 'Outgoing email is unavailable because the database is not connected.'];
        }
        require_once __DIR__ . '/EmailProviderManager.php';
        try {
            $replyTo = null;
            $issuerTable = ['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices','project_invoice'=>'project_invoices'][(string)($options['document_type'] ?? '')] ?? null;
            if ($issuerTable && (int)($options['document_id'] ?? 0) > 0) {
                $issuerQuery = $pdo->prepare("SELECT * FROM {$issuerTable} WHERE id=? LIMIT 1");
                $issuerQuery->execute([(int)$options['document_id']]);
                $issuerDocument = $issuerQuery->fetch(PDO::FETCH_ASSOC);
                if (!$issuerDocument) throw new RuntimeException('Document email issuer not found.');
                if (($issuerDocument['issuer_mode'] ?? 'legacy') !== 'legacy') {
                    if (empty($issuerDocument['issuer_snapshot'])) throw new RuntimeException('Document issuer must be saved before delivery.');
                    $issuer = json_decode((string)$issuerDocument['issuer_snapshot'], true, 32, JSON_THROW_ON_ERROR);
                    $fromName = (string)$issuer['email']['display_name'];
                    $replyTo = $issuer['email']['reply_to'] ?? null;
                    if ($replyTo !== null && (!filter_var($replyTo,FILTER_VALIDATE_EMAIL) || strpbrk($replyTo,"\r\n") !== false)) throw new RuntimeException('Invalid document reply address.');
                    $options['document_issuer_display'] = true;
                }
            }
            $manager = new EmailProviderManager($pdo, $appConfig);
            $message = new EmailMessage(
                $to,
                $subject,
                $body,
                $fromEmail,
                $fromName,
                $attachments,
                $isHtml,
                $messageId !== '' ? $messageId : null,
                $replyTo
            );
            $result = $manager->send($message, $options);
            return [$result->success, $result->message, $result->deliveryLogId];
        } catch (Throwable $error) {
            @error_log('[EmailService] ' . $error->getMessage());
            return [false, 'Outgoing email could not be sent. Review the active provider configuration.'];
        }
    }
}
