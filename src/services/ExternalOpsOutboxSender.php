<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalOpsOutboxSender
{
    /**
     * @param array<string,mixed> $config
     * @param null|callable(string,list<string>,string,int):array{status:int,body?:string,error?:string} $transport
     * @return array{processed:int,delivered:int,failed:int}
     */
    public function deliverDue(PDO $pdo, array $config, int $limit = 25, ?callable $transport = null, int $maxRuntimeSeconds = 50): array
    {
        if (empty($config['enabled'])) {
            return ['processed' => 0, 'delivered' => 0, 'failed' => 0];
        }
        $issues = (array)($config['delivery_issues'] ?? ExternalOpsConfigService::deliveryIssues($config));
        if ($issues !== []) {
            throw new RuntimeException(
                'External operations outbound delivery is paused. Complete: ' . implode(', ', $issues) . '.'
            );
        }
        if (!filter_var((string)$config['webhook_url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('External operations webhook URL is invalid.');
        }

        $limit = max(1, min(100, $limit));
        $deadline = microtime(true) + max(1, min(300, $maxRuntimeSeconds));
        $maxAttempts = max(1, (int)($config['max_attempts'] ?? 12));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $statement = $pdo->prepare(
            'SELECT id,event_id,event_type,payload_json,attempts
             FROM integration_outbox
             WHERE integration_key = ? AND delivered_at IS NULL AND next_attempt_at <= ? AND attempts < ?
             ORDER BY id ASC LIMIT ' . $limit
        );
        $statement->execute([
            (string)$config['application_key'],
            $now->format('Y-m-d H:i:s.u'),
            $maxAttempts,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['processed' => 0, 'delivered' => 0, 'failed' => 0];
        $transport ??= [$this, 'curlTransport'];

        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) break;
            $summary['processed']++;
            $body = (string)$row['payload_json'];
            $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            $headers = [
                'Content-Type: application/json',
                'CF-Access-Client-Id: ' . (string)$config['access_client_id'],
                'CF-Access-Client-Secret: ' . (string)$config['access_client_secret'],
                'X-PA-Event-ID: ' . (string)$row['event_id'],
                'X-PA-Timestamp: ' . $timestamp,
            ];
            $signingHeaders = (string)($config['signing_mode'] ?? ExternalOpsSigner::HMAC_SHA256) === ExternalOpsSigner::HMAC_SHA256
                ? ExternalOpsSigner::headersFromCredentials(['hmac_secret' => (string)$config['hmac_secret']], $timestamp, $body)
                : (new ExternalOpsConfigService())->signingHeaders($pdo, $timestamp, $body);
            $headers = array_merge($headers, $signingHeaders);

            try {
                $remainingSeconds = max(2, (int)ceil($deadline - microtime(true)));
                $response = $transport(
                    (string)$config['webhook_url'],
                    $headers,
                    $body,
                    min(max(2, (int)($config['timeout_seconds'] ?? 15)), $remainingSeconds)
                );
                $status = (int)($response['status'] ?? 0);
                if ($status >= 200 && $status < 300) {
                    $pdo->prepare('UPDATE integration_outbox SET attempts = attempts + 1, delivered_at = ?, last_error = NULL WHERE id = ? AND delivered_at IS NULL')
                        ->execute([(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), (int)$row['id']]);
                    $summary['delivered']++;
                    continue;
                }
                $message = (string)($response['error'] ?? ('HTTP ' . $status));
                if (!empty($response['body'])) {
                    $message .= ': ' . substr(trim((string)$response['body']), 0, 300);
                }
                throw new RuntimeException($message);
            } catch (Throwable $error) {
                $attempt = (int)$row['attempts'] + 1;
                $baseDelay = min(3600, 30 * (2 ** min(10, max(0, $attempt - 1))));
                $jitter = random_int(0, max(1, (int)floor($baseDelay * 0.25)));
                $nextAttempt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->modify('+' . ($baseDelay + $jitter) . ' seconds');
                $safeError = substr(preg_replace('/[\r\n\t]+/', ' ', $error->getMessage()) ?: 'Delivery failed', 0, 1000);
                $pdo->prepare('UPDATE integration_outbox SET attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ? AND delivered_at IS NULL')
                    ->execute([$attempt, $nextAttempt->format('Y-m-d H:i:s.u'), $safeError, (int)$row['id']]);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /** @return array{status:int,body:string,error:string} */
    public function curlTransport(string $url, array $headers, string $body, int $timeout): array
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('invalid_external_ops_destination');
        }
        $host = trim(strtolower((string)$parts['host']), '[]');
        $port = (int)($parts['port'] ?? 443);
        $addresses = $this->publicAddresses($host);
        if ($addresses === []) throw new RuntimeException('dns_no_public_address');
        $resolve = $host . ':' . $port . ':' . implode(',', array_map(
            static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $addresses
        ));
        $responseBody = '';
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('external_ops_curl_unavailable');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody): int {
                $remaining = 4096 - strlen($responseBody);
                if ($remaining > 0) $responseBody .= substr($chunk, 0, $remaining);
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $response = curl_exec($handle);
        $result = [
            'status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'body' => $responseBody,
            'error' => (string)curl_error($handle),
        ];
        curl_close($handle);
        return $result;
    }

    /** @return list<string> */
    private function publicAddresses(string $host): array
    {
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            foreach ((array)@dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') $addresses[] = $ip;
            }
        }
        if ($addresses === []) return [];
        foreach ($addresses as $ip) if (!self::isPublicAddress($ip)) return [];
        return array_values(array_unique($addresses));
    }

    private static function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) return false;
        if (substr($packed, 0, 10) === str_repeat("\0", 10) && substr($packed, 10, 2) === "\xff\xff") return false;
        $embedded = null;
        if (substr($packed, 0, 12) === str_repeat("\0", 12)) $embedded = substr($packed, 12, 4);
        elseif (substr($packed, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) $embedded = substr($packed, 12, 4);
        elseif (substr($packed, 0, 6) === "\x00\x64\xff\x9b\x00\x01") return false;
        elseif (substr($packed, 0, 8) === str_repeat("\0", 8) && substr($packed, 8, 4) === "\xff\xff\x00\x00") $embedded = substr($packed, 12, 4);
        elseif (in_array(substr($packed, 8, 4), ["\x00\x00\x5e\xfe", "\x02\x00\x5e\xfe"], true)) $embedded = substr($packed, 12, 4);
        elseif (substr($packed, 0, 2) === "\x20\x02") $embedded = substr($packed, 2, 4);
        elseif (substr($packed, 0, 4) === "\x20\x01\x00\x00") return false;
        if ($embedded !== null) {
            $v4 = inet_ntop($embedded);
            if ($v4 === false || filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
