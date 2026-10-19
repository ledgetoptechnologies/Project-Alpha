<?php

require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/crypto.php';

final class EmailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public string $fromEmail,
        public string $fromName = '',
        public array $attachments = [],
        public bool $isHtml = true,
        public ?string $messageId = null,
        public ?string $replyTo = null
    ) {}
}

final class SendResult
{
    public function __construct(
        public bool $success,
        public string $message = '',
        public ?string $providerMessageId = null,
        public ?string $providerResponseId = null,
        public ?int $deliveryLogId = null
    ) {}
}

interface EmailProviderInterface
{
    public function send(EmailMessage $message): SendResult;
}

final class SmtpEmailProvider implements EmailProviderInterface
{
    public function __construct(private array $credentials) {}

    public function send(EmailMessage $message): SendResult
    {
        if (trim((string)($this->credentials['host'] ?? '')) === '') {
            return new SendResult(false, 'The active SMTP provider has no server host configured.');
        }
        [$ok, $error] = mailer_send(
            $this->credentials,
            $message->to,
            $message->subject,
            $message->body,
            $message->fromEmail,
            $message->fromName,
            (string)($this->credentials['username'] ?? $message->fromEmail),
            $message->attachments,
            $message->isHtml,
            (string)($message->messageId ?? ''),
            300,
            $message->replyTo
        );
        return new SendResult($ok, $ok ? '' : ($error ?: 'SMTP send failed'), $message->messageId);
    }
}

final class GmailEmailProvider implements EmailProviderInterface
{
    public function __construct(private array $credentials, private $persistCredentials) {}

    public function send(EmailMessage $message): SendResult
    {
        $tokenResult = $this->accessToken();
        if (!$tokenResult['success']) {
            return new SendResult(false, (string)$tokenResult['message']);
        }
        [$mimeOk, $mime, $mimeError] = mailer_build_mime(
            $message->to,
            $message->subject,
            $message->body,
            $message->fromEmail,
            $message->fromName,
            $message->attachments,
            $message->isHtml,
            $message->messageId,
            $message->replyTo
        );
        if (!$mimeOk) {
            return new SendResult(false, $mimeError);
        }

        $response = self::request(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            ['Authorization: Bearer ' . $tokenResult['access_token'], 'Content-Type: application/json'],
            json_encode(['raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '=')], JSON_UNESCAPED_SLASHES)
        );
        if ($response['status'] === 401) {
            unset($this->credentials['access_token'], $this->credentials['access_token_expires_at']);
            $tokenResult = $this->accessToken(true);
            if (!$tokenResult['success']) {
                return new SendResult(false, (string)$tokenResult['message']);
            }
            $response = self::request(
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
                ['Authorization: Bearer ' . $tokenResult['access_token'], 'Content-Type: application/json'],
                json_encode(['raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '=')], JSON_UNESCAPED_SLASHES)
            );
        }
        $payload = json_decode((string)$response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $detail = is_array($payload) ? (string)($payload['error']['message'] ?? '') : '';
            return new SendResult(false, $detail !== '' ? $detail : 'Gmail send failed');
        }
        $providerId = is_array($payload) ? (string)($payload['id'] ?? '') : '';
        $threadId = is_array($payload) ? (string)($payload['threadId'] ?? '') : '';
        return new SendResult(true, '', $providerId ?: $message->messageId, $threadId ?: null);
    }

    private function accessToken(bool $force = false): array
    {
        $token = (string)($this->credentials['access_token'] ?? '');
        $expiresAt = (int)($this->credentials['access_token_expires_at'] ?? 0);
        if (!$force && $token !== '' && $expiresAt > time() + 60) {
            return ['success' => true, 'access_token' => $token];
        }
        $refreshToken = (string)($this->credentials['refresh_token'] ?? '');
        $clientId = (string)($this->credentials['client_id'] ?? '');
        $clientSecret = (string)($this->credentials['client_secret'] ?? '');
        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => 'Google email authorization is incomplete. Reconnect Google.'];
        }
        $response = self::request(
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])
        );
        $payload = json_decode((string)$response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($payload) || empty($payload['access_token'])) {
            $detail = is_array($payload) ? (string)($payload['error_description'] ?? $payload['error'] ?? '') : '';
            return ['success' => false, 'message' => $detail !== '' ? $detail : 'Google email authorization expired. Reconnect Google.'];
        }
        $this->credentials['access_token'] = (string)$payload['access_token'];
        $this->credentials['access_token_expires_at'] = time() + max(60, (int)($payload['expires_in'] ?? 3600));
        ($this->persistCredentials)($this->credentials, $this->credentials['access_token_expires_at']);
        return ['success' => true, 'access_token' => $this->credentials['access_token']];
    }

    public static function request(string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'cURL is not installed'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
        ]);
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $responseBody === false ? '' : $responseBody, 'error' => $error];
    }
}
