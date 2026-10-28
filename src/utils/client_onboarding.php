<?php

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/crypto.php';

function client_onboarding_base_url(array $appConfig): string
{
    $host = trim((string)($appConfig['app_host'] ?? ''));
    if ($host !== '') {
        return rtrim(preg_match('#^https?://#i', $host) ? $host : 'https://' . $host, '/');
    }
    $requestHost = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    return ($https ? 'https' : 'http') . '://' . $requestHost;
}

function client_onboarding_find_invitation(PDO $pdo, string $token, bool $lock = false): ?array
{
    client_onboarding_revoke_stale($pdo);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $sql = 'SELECT * FROM client_onboarding_invitations WHERE token_hash=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([hash('sha256', $token)]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invite) {
        return null;
    }
    if (($invite['status'] ?? '') === 'pending' && strtotime((string)$invite['expires_at']) < time()) {
        $pdo->prepare('UPDATE client_onboarding_invitations SET status="expired" WHERE id=? AND status="pending"')
            ->execute([(int)$invite['id']]);
        $invite['status'] = 'expired';
    }
    return $invite;
}

function client_onboarding_revoke_stale(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->prepare(
            'UPDATE client_onboarding_invitations
             SET status="revoked", consumed_at=NOW()
             WHERE status IN ("pending","verified")
               AND (expires_at < NOW() OR created_at < DATE_SUB(NOW(), INTERVAL 14 DAY))'
        )->execute();
    } catch (Throwable $e) {
        @error_log('[client_onboarding] Stale revocation failed: ' . $e->getMessage());
    }

    $done = true;
}

function client_onboarding_store_token(string $token): ?string
{
    return crypto_encrypt($token);
}

function client_onboarding_token_from_invitation(array $invitation): string
{
    $encrypted = trim((string)($invitation['token_enc'] ?? ''));
    if ($encrypted === '') {
        return '';
    }
    return crypto_decrypt($encrypted) ?: '';
}

function client_onboarding_link_for_invitation(array $appConfig, array $invitation): string
{
    $token = client_onboarding_token_from_invitation($invitation);
    if ($token === '') {
        return '';
    }
    return client_onboarding_base_url($appConfig) . '/?page=client-onboarding&token=' . rawurlencode($token);
}

function client_onboarding_clean_text(mixed $value, int $maxLength): string
{
    $value = str_replace("\0", '', (string)$value);
    $value = strip_tags($value);
    $value = preg_replace('/[^\P{C}\t\r\n]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value);
    return mb_substr($value, 0, $maxLength);
}

function client_onboarding_normalize_email(mixed $value): string
{
    return strtolower(trim((string)$value));
}

function client_onboarding_normalize_phone(mixed $value): string
{
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function client_onboarding_normalize_text(mixed $value): string
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function client_onboarding_address_key(array $row): string
{
    return client_onboarding_normalize_text(implode(' ', [
        $row['address_line1'] ?? '',
        $row['address_line2'] ?? '',
        $row['city'] ?? '',
        $row['state'] ?? '',
        $row['postal_code'] ?? '',
        $row['country'] ?? '',
    ]));
}

function client_onboarding_submitted_email(array $data, array $submission = []): string
{
    $email = client_onboarding_normalize_email($data['email'] ?? '');
    if ($email !== '') {
        return $email;
    }
    $email = client_onboarding_normalize_email($submission['invited_email'] ?? '');
    if ($email !== '') {
        return $email;
    }
    return client_onboarding_normalize_email($submission['current_client_email'] ?? '');
}

/**
 * @return list<array<string,mixed>>
 */
function client_onboarding_find_client_matches(PDO $pdo, array $data, ?int $ownerOrganizationId = null, ?int $creatorId = null): array
{
    $email = client_onboarding_submitted_email($data);
    $phone = client_onboarding_normalize_phone($data['phone'] ?? '');
    $name = client_onboarding_normalize_text($data['name'] ?? '');
    $address = client_onboarding_address_key($data);

    $sql = 'SELECT c.id,c.name,c.email,c.phone,c.address_line1,c.address_line2,c.city,c.state,c.postal_code,c.country,c.client_type,c.organization_id,o.name AS organization_name
            FROM clients c
            LEFT JOIN organizations o ON o.id=c.organization_id
            WHERE c.archived=0';
    $params = [];
    if ($ownerOrganizationId !== null && $ownerOrganizationId > 0) {
        $sql .= ' AND (c.organization_id=? OR c.organization_id IS NULL)';
        $params[] = $ownerOrganizationId;
    }
    if ($creatorId !== null) {
        $sql .= ' AND c.created_by=?';
        $params[] = $creatorId;
    }
    $sql .= ' ORDER BY c.updated_at DESC, c.id DESC LIMIT 400';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $score = 0;
        $reasons = [];
        $rowEmail = client_onboarding_normalize_email($row['email'] ?? '');
        $rowPhone = client_onboarding_normalize_phone($row['phone'] ?? '');
        $rowName = client_onboarding_normalize_text($row['name'] ?? '');
        $rowAddress = client_onboarding_address_key($row);

        if ($email !== '' && $rowEmail !== '' && $email === $rowEmail) {
            $score += 45;
            $reasons[] = 'email';
        }
        if ($phone !== '' && $rowPhone !== '' && $phone === $rowPhone) {
            $score += 35;
            $reasons[] = 'phone';
        }
        if ($address !== '' && $rowAddress !== '' && $address === $rowAddress) {
            $score += 25;
            $reasons[] = 'address';
        }
        if ($name !== '' && $rowName !== '') {
            similar_text($name, $rowName, $namePercent);
            if ($name === $rowName) {
                $score += 25;
                $reasons[] = 'name';
            } elseif ($namePercent >= 82.0) {
                $score += 15;
                $reasons[] = 'similar name';
            }
        }
        if ($score >= 25 || count($reasons) >= 2) {
            $row['match_score'] = $score;
            $row['match_reasons'] = $reasons;
            $matches[] = $row;
        }
    }

    usort($matches, static fn(array $a, array $b): int => ((int)$b['match_score'] <=> (int)$a['match_score']) ?: ((int)$b['id'] <=> (int)$a['id']));
    return array_slice($matches, 0, 5);
}

/**
 * @return list<array<string,mixed>>
 */
function client_onboarding_find_organization_matches(PDO $pdo, string $organizationName): array
{
    $needle = client_onboarding_normalize_text($organizationName);
    if ($needle === '') {
        return [];
    }

    $rows = $pdo->query('SELECT id,name,address_line1,address_line2,city,state,postal_code,country FROM organizations ORDER BY updated_at DESC, id DESC LIMIT 400')
        ->fetchAll(PDO::FETCH_ASSOC);
    $matches = [];
    foreach ($rows as $row) {
        $name = client_onboarding_normalize_text($row['name'] ?? '');
        if ($name === '') {
            continue;
        }
        similar_text($needle, $name, $percent);
        $score = 0;
        $reasons = [];
        if ($needle === $name) {
            $score = 100;
            $reasons[] = 'exact name';
        } elseif ($percent >= 82.0) {
            $score = (int)round($percent);
            $reasons[] = 'similar name';
        } elseif (str_contains($name, $needle) || str_contains($needle, $name)) {
            $score = 70;
            $reasons[] = 'partial name';
        }
        if ($score > 0) {
            $row['match_score'] = $score;
            $row['match_reasons'] = $reasons;
            $matches[] = $row;
        }
    }

    usort($matches, static fn(array $a, array $b): int => ((int)$b['match_score'] <=> (int)$a['match_score']) ?: ((int)$b['id'] <=> (int)$a['id']));
    return array_slice($matches, 0, 5);
}

function client_onboarding_merge_value(array $data, array $existing, string $field, array $mergeFields): mixed
{
    if (in_array($field, $mergeFields, true)) {
        return ($data[$field] ?? '') !== '' ? $data[$field] : null;
    }
    return ($existing[$field] ?? '') !== '' ? $existing[$field] : null;
}
