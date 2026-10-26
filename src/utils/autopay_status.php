<?php
declare(strict_types=1);
require_once __DIR__ . '/invoice_lifecycle.php';

/** Read reservations even when collection has been disabled during an incident. */
function pa_autopay_pending_notice(PDO $pdo, int $id, string $type = 'invoice'): string
{
    if (!in_array($type, ['invoice', 'project_invoice'], true)) throw new InvalidArgumentException('Invalid document type.');
    if (!invoice_table_has_column($pdo, 'autopay_collections', 'state')) return '';
    $query = $pdo->prepare('SELECT state,financial_hold FROM autopay_collections WHERE document_type=? AND document_id=?');
    $query->execute([$type, $id]);
    $collection = $query->fetch(PDO::FETCH_ASSOC);
    return match (!empty($collection['financial_hold']) ? 'held' : ($collection['state'] ?? '')) {
        'pending' => 'Your authorized automatic payment is queued. Please do not pay this invoice again. A receipt will be sent after payment succeeds.',
        'processing' => 'Your automatic payment is processing. Please do not pay this invoice again. A receipt will be sent after payment succeeds.',
        'requires_action' => 'Your automatic payment needs attention. Please contact us before making another payment.',
        'held' => 'We are reviewing your automatic payment. Please contact us before making another payment.',
        default => '',
    };
}
