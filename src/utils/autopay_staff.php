<?php
declare(strict_types=1);

require_once __DIR__ . '/autopay_collection.php';
require_once __DIR__ . '/invoice_lifecycle.php';

/** Staff-only projection; never expose processor identifiers or authorization secrets. */
function pa_autopay_staff_state(PDO $pdo, array $document, array $config, string $type = 'invoice'): array
{
    $state = ['enabled'=>false, 'eligible'=>false, 'authorization_id'=>null, 'state'=>'off', 'method'=>'', 'scope'=>'', 'amount'=>(string)($document['balance_due'] ?? $document['total'] ?? '0.00')];
    $enabled = pa_autopay_enabled($config);
    if (!empty($document['id']) && invoice_table_has_column($pdo, 'autopay_collections', 'state')) {
        $query = $pdo->prepare('SELECT state,financial_hold,last_error FROM autopay_collections WHERE document_type=? AND document_id=?');
        $query->execute([$type, (int)$document['id']]);
        if ($collection = $query->fetch(PDO::FETCH_ASSOC)) {
            $state['enabled'] = true;
            $state['state'] = $collection['financial_hold'] ? 'held' : $collection['state'];
            if ($state['state'] === 'failed' && in_array($collection['last_error'], ['Canceled before submission.', 'Canceled by processor.'], true)) $state['state'] = 'canceled';
            return $state;
        }
    }
    if (!$enabled) return $state;
    $state['enabled'] = true;
    $state['state'] = 'not_authorized';
    if (($document['collection_mode'] ?? 'direct') !== 'direct' || ($document['recipient_presentation_mode'] ?? '') === 'general') {
        $state['state'] = 'separate_collection';
        return $state;
    }
    $authorization = pa_autopay_authorization_for_invoice($pdo, $document, $config);
    if (!$authorization) return $state;
    $state['authorization_id'] = (int)$authorization['id'];
    $state['eligible'] = true;
    $state['state'] = 'authorized';
    $state['scope'] = $authorization['scope'] === 'agreement' ? 'This recurring agreement' : 'Future invoices for this client account';
    $last4 = (string)($authorization['last4'] ?? '');
    $state['method'] = ($authorization['method_type'] === 'us_bank_account' ? 'Bank account' : 'Card') . (preg_match('/^\d{4}$/D', $last4) ? ' ending ' . $last4 : ' saved with Stripe');
    if (!empty($document['finalized_at']) || (!empty($document['status']) && $document['status'] !== 'draft')) {
        $state['eligible'] = false;
        $state['state'] = 'future_only';
    }
    return $state;
}

function pa_autopay_staff_preview_hash(array $document, array $state, string $type, string $secret): string
{
    if (strlen($secret) < 32) throw new LogicException('Reload the invoice before finalizing.');
    return hash_hmac('sha256', json_encode([
        $type, (int)($document['id'] ?? 0), (int)($document['client_id'] ?? $document['primary_client_id'] ?? 0),
        (int)($document['contract_id'] ?? 0), (string)($document['revision_number'] ?? 1),
        (string)($document['total'] ?? '0'), (string)($document['balance_due'] ?? '0'),
        $state['authorization_id'], $state['method'], $state['scope'], $state['state'],
    ], JSON_THROW_ON_ERROR), $secret);
}

/** Validate the exact server-calculated invoice and choice while its row is locked. */
function pa_autopay_staff_finalization(PDO $pdo, array $document, array $config, string $type = 'invoice'): string
{
    $state = pa_autopay_staff_state($pdo, $document, $config, $type);
    if (!$state['eligible']) return 'none';
    $posted = $config['_autopay_staff_choice'] ?? [];
    $hash = pa_autopay_staff_preview_hash($document, $state, $type, (string)($config['_autopay_staff_secret'] ?? ''));
    if (!is_array($posted) || !hash_equals($hash, (string)($posted['preview_hash'] ?? '')) || !in_array($posted['action'] ?? '', ['collect', 'hold'], true)) {
        throw new DomainException('AutoPay is enabled for this client. Open the invoice and review the final amount and payment method before finalizing.');
    }
    return $posted['action'];
}

function pa_autopay_staff_request_config(array $config, array $post, string $secret): array
{
    $config['_autopay_staff_choice'] = ['action'=>(string)($post['autopay_action'] ?? ''), 'preview_hash'=>(string)($post['autopay_preview_hash'] ?? '')];
    $config['_autopay_staff_secret'] = $secret;
    return $config;
}
