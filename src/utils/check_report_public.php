<?php
declare(strict_types=1);

require_once __DIR__ . '/payment_methods.php';
require_once __DIR__ . '/autopay_enrollment.php';

/** Resolve every public request from its bearer token; never accept document IDs. */
function pa_check_report_public_context(PDO $pdo, string $token, array $config): array
{
    if ((!pa_payment_methods_has($config, 'check') && !pa_payment_methods_has($config, 'cheque')) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new DomainException('This mailed-check link is unavailable.');
    }
    $statement = $pdo->prepare('SELECT document_type,document_id,revoked,expires_at FROM public_links WHERE token=? LIMIT 1');
    $statement->execute([$token]); $link = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$link || !empty($link['revoked']) || !in_array($link['document_type'], ['invoice','project_invoice'], true)) {
        throw new DomainException('This mailed-check link is unavailable.');
    }
    if (!empty($link['expires_at'])) {
        $expires = strtotime((string)$link['expires_at'] . ' UTC');
        if ($expires === false || $expires <= time()) throw new DomainException('This mailed-check link is unavailable.');
    }
    $type = (string)$link['document_type'];
    $table = $type === 'invoice' ? 'invoices' : 'project_invoices';
    $statement = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id=?');
    $statement->execute([(int)$link['document_id']]); $document = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$document || !in_array(strtolower((string)$document['status']), ['sent','unpaid','partial','overdue'], true)
        || empty($document['finalized_at']) || ($document['recipient_presentation_mode'] ?? 'named') === 'general'
        || ($type === 'invoice' && ($document['collection_mode'] ?? 'direct') !== 'direct')
        || (float)$document['total'] - (float)($document['amount_paid'] ?? 0) <= 0.005) {
        throw new DomainException('This invoice is not available for a mailed-check report.');
    }
    $clientId = (int)($document[$type === 'invoice' ? 'client_id' : 'primary_client_id'] ?? 0);
    $statement = $pdo->prepare('SELECT id,public_id,name,email FROM clients WHERE id=? AND archived=0');
    $statement->execute([$clientId]); $client = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$client || !filter_var((string)$client['email'], FILTER_VALIDATE_EMAIL)) throw new DomainException('Contact the business to report your mailed check.');
    return ['type'=>$type, 'document_id'=>(int)$document['id'], 'document'=>$document, 'client'=>$client];
}

/** A mailed-check proof is separate from payment-method management authority. */
function pa_check_report_public_authority(array $context, string $token, array $session): bool
{
    $proof = $session['mailed_check_verified'] ?? [];
    return hash_equals(hash('sha256', $token), (string)($proof['token_hash'] ?? ''))
        && pa_check_report_same_client($proof, $context['client'])
        && !empty($context['document']['public_id'])
        && hash_equals((string)$context['document']['public_id'], (string)($proof['document_public_id'] ?? ''))
        && pa_autopay_authority($context['client'], ['autopay_verified'=>$proof]);
}

function pa_check_report_public_anchor(array $context, string $token): string
{
    $clientPublicId = (string)($context['client']['public_id'] ?? '');
    $documentPublicId = (string)($context['document']['public_id'] ?? '');
    if ($clientPublicId === '' || $documentPublicId === '') return '';
    return hash('sha256', json_encode([$clientPublicId, $documentPublicId, $token], JSON_THROW_ON_ERROR));
}

/** Numeric client IDs may be reused after archive; financial disclosure may not. */
function pa_check_report_same_client(array $report, array $client): bool
{
    $reportedPublicId = (string)($report['client_public_id'] ?? '');
    $currentPublicId = (string)($client['public_id'] ?? '');
    return $reportedPublicId !== '' && $currentPublicId !== '' && hash_equals($reportedPublicId, $currentPublicId);
}

function pa_check_report_money(int $cents, string $currency): string
{
    return strtoupper(trim($currency)) . ' ' . number_format($cents / 100, 2);
}

function pa_check_report_display_date(string $value, array $config): string
{
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string)($config['timezone'] ?? 'UTC')))->format('M j, Y g:i A T');
    } catch (Throwable $error) { return $value; }
}
