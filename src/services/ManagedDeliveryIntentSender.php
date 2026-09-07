<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class ManagedDeliveryIntentSender
{
    private const CLAIM_TTL_SECONDS = 300;
    private const RESPONSE_LIMIT = 4096;

    /**
     * @param null|callable(string,list<string>,string,int):array{status:int,body?:string,error?:string} $transport
     * @return array{processed:int,accepted:int,retrying:int,dead_lettered:int}
     */
    public function deliverDue(PDO $pdo, int $limit = 25, ?callable $transport = null, int $maxRuntimeSeconds = 50): array
    {
        $summary = ['processed' => 0, 'accepted' => 0, 'retrying' => 0, 'dead_lettered' => 0];
        $config = (new ManagedDeliveryService())->config($pdo);
        if (empty($config['enabled'])) return $summary;
        $service = new ManagedDeliveryService();
        $transport ??= [$this, 'curlTransport'];
        $deadline = microtime(true) + max(1, min(300, $maxRuntimeSeconds));
        for ($index = 0; $index < max(1, min(100, $limit)); $index++) {
            if (microtime(true) >= $deadline) break;
            $claim = $this->claimNext($pdo);
            if ($claim === null) break;
            $summary['processed']++;
            $maxAttempts = max(1, min(50, (int)$claim['delivery_max_attempts']));
            try {
                $contract = $service->deliveryContractForClaim($pdo, $claim);
                $response = $this->send($contract, $claim, $transport);
                $status = (int)($response['status'] ?? 0);
                $receipt = empty($response['error']) ? $this->acceptedReceipt($claim, $status, (string)($response['body'] ?? '')) : null;
                if ($receipt !== null) {
                    $this->finish($pdo, $claim, true, $status, null, false, $receipt);
                    $summary['accepted']++;
                    continue;
                }
                $code = $status >= 300 && $status < 400 ? 'redirect_rejected'
                    : ($status === 429 ? 'http_429' : ($status >= 400 && $status < 500 ? 'http_4xx'
                        : ($status >= 500 ? 'http_5xx' : ($status === 202 ? 'invalid_response' : 'transport_failed'))));
                $dead = $this->shouldDeadLetter($claim, $status, $maxAttempts);
                $this->finish($pdo, $claim, false, $status, $code, $dead, null);
                $dead ? $summary['dead_lettered']++ : $summary['retrying']++;
            } catch (Throwable $error) {
                $dead = ((int)$claim['attempts'] + 1) >= $maxAttempts;
                $this->finish($pdo, $claim, false, 0, $this->safeError($error), $dead, null);
                $dead ? $summary['dead_lettered']++ : $summary['retrying']++;
            }
        }
        return $summary;
    }

    /**
     * Immediately attempts one specific manually-created intent without allowing an
     * older backlog row to consume the operator's bounded request.
     *
     * @param null|callable(string,list<string>,string,int):array{status:int,body?:string,error?:string} $transport
     * @return array{processed:int,accepted:int,retrying:int,dead_lettered:int}
     */
    public function deliverDeliveryId(PDO $pdo, string $deliveryId, ?callable $transport = null, bool $allowWhenProviderDisabled = false): array
    {
        $summary = ['processed' => 0, 'accepted' => 0, 'retrying' => 0, 'dead_lettered' => 0];
        if (!$allowWhenProviderDisabled) {
            $config = (new ManagedDeliveryService())->config($pdo);
            if (empty($config['enabled'])) return $summary;
        }
        $service = new ManagedDeliveryService();
        $claim = $this->claimDeliveryId($pdo, $deliveryId);
        if ($claim === null) return $summary;
        $transport ??= [$this, 'curlTransport'];
        $summary['processed'] = 1;
        $maxAttempts = max(1, min(50, (int)$claim['delivery_max_attempts']));
        try {
            $contract = $service->deliveryContractForClaim($pdo, $claim);
            $response = $this->send($pdo, $contract, $claim, $transport);
            $status = (int)($response['status'] ?? 0);
            $receipt = empty($response['error']) ? $this->acceptedReceipt($claim, $status, (string)($response['body'] ?? '')) : null;
            if ($receipt !== null) {
                $this->finish($pdo, $claim, true, $status, null, false, $receipt);
                $summary['accepted'] = 1;
                return $summary;
            }
            $code = $status >= 300 && $status < 400 ? 'redirect_rejected'
                : ($status === 429 ? 'http_429' : ($status >= 400 && $status < 500 ? 'http_4xx'
                    : ($status >= 500 ? 'http_5xx' : ($status === 202 ? 'invalid_response' : 'transport_failed'))));
            $dead = $this->shouldDeadLetter($claim, $status, $maxAttempts);
            $this->finish($pdo, $claim, false, $status, $code, $dead, null);
            $summary[$dead ? 'dead_lettered' : 'retrying'] = 1;
        } catch (Throwable $error) {
            $dead = ((int)$claim['attempts'] + 1) >= $maxAttempts;
            $this->finish($pdo, $claim, false, 0, $this->safeError($error), $dead, null);
            $summary[$dead ? 'dead_lettered' : 'retrying'] = 1;
        }
        return $summary;
    }

    /**
     * @param null|callable(string,list<string>,string,int):array{status:int,body?:string,error?:string} $transport
     * @return array{status:string,schemaVersion:int,integrationEnabled:bool,portalSupported:bool,guestSupported:bool,revocationSupported:bool}
     */
    public function preflight(PDO $pdo, ?callable $transport = null, ?string $deliveryId = null): array
    {
        $contract = (new ManagedDeliveryService())->deliveryContract($pdo, false);
        $deliveryId ??= self::uuid();
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,95}$/D', $deliveryId) !== 1) {
            throw new RuntimeException('managed_delivery_id_invalid');
        }
        $intent = [
            'schemaVersion' => 1,
            'applicationKey' => $contract['applicationKey'],
            'deliveryId' => $deliveryId,
            'occurredAt' => self::isoNow(),
        ];
        $body = $this->externalOpsEnvelope($contract, 'preflight', $intent);
        $url = $contract['url'];
        $headers = $this->externalOpsHeaders($pdo, $contract, $body);
        $transport ??= [$this, 'curlTransport'];
        $response = $transport($url, $headers, $body, $contract['timeout']);
        if ((int)($response['status'] ?? 0) !== 200 || !empty($response['error'])) throw new RuntimeException('managed_delivery_preflight_rejected');
        $outer = json_decode((string)($response['body'] ?? ''), true);
        $decoded = $this->externalOpsResult($outer, $this->externalOpsEventId('preflight', $deliveryId));
        $keys = is_array($decoded) ? array_keys($decoded) : [];
        sort($keys);
        $expected = ['guestSupported', 'integrationEnabled', 'portalSupported', 'revocationSupported', 'schemaVersion', 'status'];
        sort($expected);
        if ($keys !== $expected || $decoded['status'] !== 'ready' || $decoded['schemaVersion'] !== 1
            || !is_bool($decoded['integrationEnabled']) || $decoded['portalSupported'] !== true
            || !is_bool($decoded['guestSupported']) || $decoded['revocationSupported'] !== true) {
            throw new RuntimeException('managed_delivery_preflight_invalid_response');
        }
        return $decoded;
    }

    /** @return array<string,mixed>|null */
    private function claimNext(PDO $pdo): ?array
    {
        $now = self::dbNow();
        $expired = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . self::CLAIM_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
        $candidate = $pdo->prepare('SELECT id FROM managed_delivery_intent_outbox WHERE delivered_at IS NULL AND dead_lettered_at IS NULL AND next_attempt_at<=? AND attempts<delivery_max_attempts AND (claimed_at IS NULL OR claimed_at<?) ORDER BY id LIMIT 1');
        $candidate->execute([$now, $expired]);
        $id = $candidate->fetchColumn();
        if ($id === false) return null;
        $pdo->beginTransaction();
        try {
            $token = self::uuid();
            $update = $pdo->prepare('UPDATE managed_delivery_intent_outbox SET claim_token=?,claimed_at=? WHERE id=? AND delivered_at IS NULL AND dead_lettered_at IS NULL AND (claimed_at IS NULL OR claimed_at<?)');
            $update->execute([$token, $now, (int)$id, $expired]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $row = $pdo->prepare('SELECT * FROM managed_delivery_intent_outbox WHERE id=? AND claim_token=?');
            $row->execute([(int)$id, $token]);
            $claim = $row->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
            return $claim ?: null;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function claimDeliveryId(PDO $pdo, string $deliveryId): ?array
    {
        $now = self::dbNow();
        $expired = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . self::CLAIM_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
        $pdo->beginTransaction();
        try {
            $token = self::uuid();
            $update = $pdo->prepare('UPDATE managed_delivery_intent_outbox SET claim_token=?,claimed_at=? WHERE delivery_id=? AND delivered_at IS NULL AND dead_lettered_at IS NULL AND next_attempt_at<=? AND attempts<delivery_max_attempts AND (claimed_at IS NULL OR claimed_at<?)');
            $update->execute([$token, $now, $deliveryId, $now, $expired]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $row = $pdo->prepare('SELECT * FROM managed_delivery_intent_outbox WHERE delivery_id=? AND claim_token=?');
            $row->execute([$deliveryId, $token]);
            $claim = $row->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
            return $claim ?: null;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $contract @param array<string,mixed> $claim */
    private function send(PDO $pdo, array $contract, array $claim, callable $transport): array
    {
        $intentBody = (string)$claim['payload_json'];
        if (strlen($intentBody) > 32768) throw new RuntimeException('managed_delivery_payload_too_large');
        $url = $contract['url'];
        $intent = json_decode($intentBody, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($intent)) throw new RuntimeException('managed_delivery_payload_invalid');
        $kind = ($claim['intent_type'] ?? 'provision') === 'revoke' ? 'revoke' : 'provision';
        $body = $this->externalOpsEnvelope($contract, $kind, $intent);
        $headers = $this->externalOpsHeaders($pdo, $contract, $body);
        return $transport($url, $headers, $body, $contract['timeout']);
    }

    /** @param array<string,mixed> $claim */
    private function acceptedReceipt(array $claim, int $status, string $body): ?string
    {
        if ($status !== 200 || strlen($body) > self::RESPONSE_LIMIT) return null;
        $decoded = json_decode($body, true);
        $kind = ($claim['intent_type'] ?? 'provision') === 'revoke' ? 'revoke' : 'provision';
        $decoded = $this->externalOpsResult($decoded, $this->externalOpsEventId($kind, (string)$claim['delivery_id']));
        if (!is_array($decoded) || count($decoded) !== 2 || array_keys($decoded) !== ['receiptId', 'status']
            || $decoded['status'] !== 'accepted' || !is_string($decoded['receiptId'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $decoded['receiptId']) !== 1) return null;
        return $decoded['receiptId'];
    }

    /** @param array<string,mixed> $contract @param array<string,mixed> $intent */
    private function externalOpsEnvelope(array $contract, string $kind, array $intent): string
    {
        $deliveryId = (string)($intent['deliveryId'] ?? '');
        $occurredAt = (string)($intent['occurredAt'] ?? '');
        $eventId = $this->externalOpsEventId($kind, $deliveryId);
        return json_encode([
            'event_id' => $eventId,
            'event_type' => 'delivery.intent',
            'occurred_at' => $occurredAt,
            'schema_version' => 1,
            'application_key' => (string)$contract['applicationKey'],
            'intent_kind' => $kind,
            'intent' => $intent,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $contract @return list<string> */
    private function externalOpsHeaders(PDO $pdo, array $contract, string $body): array
    {
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $eventId = is_array($decoded) ? (string)($decoded['event_id'] ?? '') : '';
        $headers = [
            'Content-Type: application/json',
            'CF-Access-Client-Id: ' . (string)($contract['authHeaders']['CF-Access-Client-Id'] ?? ''),
            'CF-Access-Client-Secret: ' . (string)($contract['authHeaders']['CF-Access-Client-Secret'] ?? ''),
            'X-PA-Event-ID: ' . $eventId,
            'X-PA-Timestamp: ' . $timestamp,
        ];
        $signingHeaders = (string)($contract['signingMode'] ?? ExternalOpsSigner::HMAC_SHA256) === ExternalOpsSigner::HMAC_SHA256
            ? ExternalOpsSigner::headersFromCredentials(['hmac_secret' => (string)($contract['secret'] ?? '')], $timestamp, $body)
            : (new ExternalOpsConfigService())->signingHeaders($pdo, $timestamp, $body);
        return array_merge($headers, $signingHeaders);
    }

    /** @param mixed $decoded @return array<string,mixed>|null */
    private function externalOpsResult(mixed $decoded, string $eventId): ?array
    {
        if (!is_array($decoded) || array_keys($decoded) !== ['ok', 'event_id', 'status', 'result']
            || $decoded['ok'] !== true || !hash_equals($eventId, (string)$decoded['event_id'])
            || !in_array($decoded['status'], ['completed', 'duplicate'], true)
            || !is_array($decoded['result'])) {
            return null;
        }
        return $decoded['result'];
    }

    private function externalOpsEventId(string $kind, string $deliveryId): string
    {
        if (!in_array($kind, ['preflight', 'provision', 'revoke'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,95}$/D', $deliveryId) !== 1) {
            throw new RuntimeException('managed_delivery_id_invalid');
        }
        $eventId = 'delivery.intent:' . $kind . ':' . $deliveryId;
        if (strlen($eventId) > 128) throw new RuntimeException('managed_delivery_event_id_too_large');
        return $eventId;
    }

    /** @param array<string,mixed> $claim */
    private function finish(PDO $pdo, array $claim, bool $delivered, int $status, ?string $error, bool $dead, ?string $receipt): void
    {
        $attempt = (int)$claim['attempts'] + 1;
        $base = min(3600, 30 * (2 ** min(7, max(0, $attempt - 1))));
        $next = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . ($base + random_int(0, max(1, (int)floor($base * .25)))) . ' seconds')->format('Y-m-d H:i:s.u');
        $pdo->beginTransaction();
        try {
            $now = self::dbNow();
            $statement = $pdo->prepare('UPDATE managed_delivery_intent_outbox SET attempts=?,next_attempt_at=?,delivered_at=?,dead_lettered_at=?,last_http_status=?,last_error_code=?,receipt_id=?,claim_token=NULL,claimed_at=NULL WHERE id=? AND claim_token=? AND delivered_at IS NULL AND dead_lettered_at IS NULL');
            $statement->execute([$attempt, $next, $delivered ? $now : null, $dead ? $now : null, $status ?: null, $error, $receipt, (int)$claim['id'], (string)$claim['claim_token']]);
            if ($delivered && $statement->rowCount() === 1 && ($claim['intent_type'] ?? 'provision') === 'revoke') {
                $pdo->prepare("UPDATE managed_delivery_intent_outbox SET revoked_at=? WHERE delivery_id=? AND intent_type='provision' AND delivered_at IS NOT NULL AND revoked_at IS NULL")
                    ->execute([$now, (string)$claim['target_delivery_id']]);
            }
            $pdo->commit();
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }

    /** @param array<string,mixed> $claim */
    private function shouldDeadLetter(array $claim, int $status, int $maxAttempts): bool
    {
        return ((int)$claim['attempts'] + 1) >= $maxAttempts
            || ($status > 0 && $status !== 408 && $status !== 429 && ($status < 500 || $status >= 600));
    }

    private function safeError(Throwable $error): string
    {
        if (str_contains(strtolower($error->getMessage()), 'pinned managed delivery')) return 'pinned_contract_unavailable';
        foreach (['dns_no_public_address', 'redirect_rejected', 'curl_unavailable', 'invalid_response', 'payload_too_large'] as $code) {
            if (str_contains(strtolower($error->getMessage()), $code)) return $code;
        }
        return 'transport_failed';
    }

    /** @return array{status:int,body:string,error:string} */
    public function curlTransport(string $url, array $headers, string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('curl_unavailable');
        $parts = PortalProjectionDeliveryConfigService::validateDestination($url);
        $host = trim(strtolower((string)$parts['host']), '[]');
        $addresses = $this->publicAddresses($host);
        if ($addresses === []) throw new RuntimeException('dns_no_public_address');
        $port = (int)($parts['port'] ?? 443);
        $ip = $addresses[array_rand($addresses)];
        $responseBody = '';
        $responseTooLarge = false;
        $handle = curl_init($url);
        $options = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$responseTooLarge): int {
                $remaining = self::RESPONSE_LIMIT - strlen($responseBody);
                if (strlen($chunk) > $remaining) $responseTooLarge = true;
                if ($remaining > 0) $responseBody .= substr($chunk, 0, $remaining);
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $result = ['status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $responseBody, 'error' => $responseTooLarge ? 'response_too_large' : ($ok === false ? (string)curl_error($handle) : '')];
        curl_close($handle);
        return $result;
    }

    /** @return list<string> */
    private function publicAddresses(string $host, ?callable $resolver = null): array
    {
        $addresses = [];
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) $addresses[] = $host;
        else foreach ((array)($resolver !== null ? $resolver($host) : @dns_get_record($host, DNS_A | DNS_AAAA)) as $row) {
            $ip = (string)($row['ip'] ?? $row['ipv6'] ?? '');
            if ($ip !== '') $addresses[] = $ip;
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

    private static function isoNow(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'); }
    private static function dbNow(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    private static function uuid(): string { $hex = bin2hex(random_bytes(16)); return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-' . dechex((hexdec($hex[16]) & 3) | 8) . substr($hex, 17, 3) . '-' . substr($hex, 20); }
}
