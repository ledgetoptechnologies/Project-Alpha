<?php
declare(strict_types=1);
require_once __DIR__ . '/invoice_lifecycle.php';

/** Read payment reservations even when collection has been disabled. */
function pa_autopay_pending_notice(PDO $pdo, int $id, string $type = 'invoice'): string
{
    if (!in_array($type, ['invoice', 'project_invoice'], true)) throw new InvalidArgumentException('Invalid document type.');
    require_once __DIR__ . '/check_reports.php';
    try {
        pa_check_report_assert_available($pdo, $type, $id);
    } catch (DomainException $heldCheck) {
        return 'A mailed check is awaiting receipt or staff review. Please contact us before making another payment. This invoice is not paid until receipt is confirmed.';
    }
    if (!invoice_table_has_column($pdo, 'autopay_collections', 'state')) return '';
    $query = $pdo->prepare('SELECT state,financial_hold FROM autopay_collections WHERE document_type=? AND document_id=?');
    $query->execute([$type, $id]);
    $collection = $query->fetch(PDO::FETCH_ASSOC);
    if(($collection['state']??'')==='pending' && empty($collection['financial_hold'])){
        require_once __DIR__.'/autopay_review.php';
        $review=pa_autopay_invoice_review_state($pdo,$type,$id);
        if(!$review || $review['review_status']==='awaiting_delivery')return 'AutoPay is waiting for successful delivery of this invoice and your seven-day review period. This invoice is not paid yet.';
        if($review['review_status']==='scheduled' && $review['scheduled_at'])return 'AutoPay is scheduled on or after '.(new DateTimeImmutable($review['scheduled_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($review['timezone']))->format('M j, Y g:i A T').'. You can review, skip AutoPay for this invoice, or ask a billing question using the link in your invoice email. This invoice is not paid yet.';
    }
    return match (!empty($collection['financial_hold']) ? 'held' : ($collection['state'] ?? '')) {
        'pending' => 'Your authorized automatic payment is queued. Please do not pay this invoice again. A receipt will be sent after payment succeeds.',
        'processing' => 'Your automatic payment is processing. Please do not pay this invoice again. A receipt will be sent after payment succeeds.',
        'requires_action' => 'Your automatic payment needs attention. Please contact us before making another payment.',
        'held' => 'We are reviewing your automatic payment. Please contact us before making another payment.',
        default => '',
    };
}
