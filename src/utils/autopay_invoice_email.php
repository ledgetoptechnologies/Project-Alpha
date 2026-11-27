<?php
declare(strict_types=1);

/** One delivery boundary for manual, scheduled and injectable invoice mail. */
function pa_autopay_deliver_invoice_email(PDO $pdo, array $config, string $to, string $subject, string $body, array $options, callable $sender): array
{
    $type = (string)($options['document_type'] ?? '');
    if (empty($options['invoice_notice']) || !in_array($type, ['invoice', 'project_invoice'], true)
        || !empty($options['_autopay_review_handled'])) {
        return $sender($to, $subject, $body, $options);
    }
    require_once __DIR__ . '/autopay_review.php';
    $notice = pa_autopay_prepare_invoice_notice($pdo, $type, (int)($options['document_id'] ?? 0), $config, $to);
    $options['_autopay_review_handled'] = true;
    if ($notice !== null) {
        if (!empty($options['is_html']) || !array_key_exists('is_html', $options)) {
            // Put the notice inside a complete document so the shared email
            // layout cannot discard it when extracting the original body.
            $body = preg_match('/<body\b[^>]*>/i', $body)
                ? (string)preg_replace_callback('/<body\b[^>]*>/i', static fn(array $match): string => $match[0] . $notice['html'], $body, 1)
                : $notice['html'] . $body;
        } else $body = $notice['text'] . "\n\n" . $body;
        $options['message_key'] = (string)($options['message_key'] ?? ('invoice:' . $type . ':' . (int)$options['document_id'] . ':' . hash('sha256', strtolower($to))))
            . ':autopay-review:' . (int)$notice['review_id'];
    }
    $result = $sender($to, $subject, $body, $options);
    if (!empty($result[0]) && $notice !== null) {
        // SMTP acceptance and the database update cannot be one transaction.
        // A lost acknowledgement leaves collection held; retry reconciles using
        // the original delivery log rather than starting another charge/window.
        try {
            pa_autopay_mark_invoice_notice_sent($pdo, $notice, $to, isset($result[2]) ? (int)$result[2] : null, $config);
        } catch (Throwable $error) {
            error_log('[autopay] Invoice notice delivered; collection schedule requires review.');
        }
    }
    return $result;
}
