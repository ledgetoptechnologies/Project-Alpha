<?php

require_once __DIR__ . '/invoice_notification_queue.php';

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/invoice_content_links.php';
require_once __DIR__ . '/invoice_due_dates.php';
require_once __DIR__ . '/invoice_numbers.php';
require_once __DIR__ . '/document_pdf.php';
require_once __DIR__ . '/general_recipient_invoices.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/billing_recipients.php';

function invoice_notification_timezone(array $appConfig): DateTimeZone
{
    $configured = trim((string)($appConfig['timezone'] ?? ''));
    try {
        return new DateTimeZone($configured !== '' ? $configured : date_default_timezone_get());
    } catch (Throwable $error) {
        throw new RuntimeException('Configured invoice automation timezone is invalid.', 0, $error);
    }
}
function invoice_notification_recipient_key(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function invoice_notification_public_base(array $appConfig): string
{
    $configured = trim((string)($appConfig['app_host'] ?? ''));
    if ($configured === '') {
        throw new RuntimeException('Public application URL is not configured.');
    }
    $url = preg_match('#^https?://#i', $configured) ? $configured : 'https://' . $configured;
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
        throw new RuntimeException('Public application URL is invalid.');
    }
    return rtrim($url, '/');
}

/** @return array<string,mixed>|null */
function invoice_notification_invoice(PDO $pdo, int $invoiceId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.*,c.email,c.name AS client_name
         FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?'
    );
    $stmt->execute([$invoiceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function invoice_notification_enqueue(
    PDO $pdo,
    int $invoiceId,
    string $type,
    string $deliveryKey,
    string $email,
    string $status = 'pending',
    ?string $reason = null,
    ?DateTimeImmutable $availableAt = null
): bool {
    $email = trim($email);
    $recipientKey = invoice_notification_recipient_key($email);
    $availableAt = $availableAt ?: new DateTimeImmutable('now');
    $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $stmt = $pdo->prepare(
        $insert . ' INTO invoice_notifications
         (invoice_id,notification_type,delivery_key,recipient_key,delivery_status,
          attempt_count,next_attempt_at,last_error,email_to)
         VALUES (?,?,?,?,?,0,?,?,?)'
    );
    $stmt->execute([$invoiceId, $type, substr($deliveryKey, 0, 100), $recipientKey, $status, $availableAt->format('Y-m-d H:i:s'), $reason, $email ?: null]);
    return $stmt->rowCount() === 1;
}

function invoice_notification_enqueue_generated(PDO $pdo, int $invoiceId, array $appConfig): bool
{
    if (empty($appConfig['invoice_auto_email_on_generate'])) {
        return false;
    }
    $invoice = invoice_notification_invoice($pdo, $invoiceId);
    if (!$invoice || pa_invoice_is_general_recipient($invoice)
        || ($invoice['collection_mode'] ?? 'direct') !== 'direct'
        || ($invoice['invoice_type'] ?? '') !== 'long_term'
        || ($invoice['finalization_source'] ?? '') !== 'recurring_schedule') {
        return false;
    }
    $stats = invoice_notification_enqueue_resolved($pdo, $invoiceId, 'on_generate', 'generated');
    return $stats['queued'] + $stats['suppressed'] > 0;
}

/** Queue each approved recipient independently, never a comma-separated address. */
function invoice_notification_enqueue_resolved(PDO $pdo, int $invoiceId, string $type, string $deliveryKey, ?DateTimeImmutable $availableAt = null): array
{
    $resolution = pa_billing_resolve($pdo, 'invoice', $invoiceId);
    $stats = ['queued' => 0, 'suppressed' => 0];
    $recipients = $resolution['recipients'];
    if (!$recipients) {
        if (invoice_notification_enqueue($pdo, $invoiceId, $type, $deliveryKey, '', 'suppressed', $resolution['reason'] ?: 'No valid billing recipients. Review invoice billing recipients.', $availableAt)) {
            $stats['suppressed']++;
        }
        return $stats;
    }
    foreach ($recipients as $recipient) {
        $email = trim((string)$recipient['email']);
        $status = filter_var($email, FILTER_VALIDATE_EMAIL) ? 'pending' : 'suppressed';
        if (invoice_notification_enqueue($pdo, $invoiceId, $type, $deliveryKey, $email, $status, $status === 'suppressed' ? 'Missing or invalid billing email.' : null, $availableAt)) {
            $stats[$status === 'pending' ? 'queued' : 'suppressed']++;
        }
    }
    return $stats;
}

/**
 * Enqueue the current reminder stages. The date parameter makes scheduling
 * deterministic and timezone-safe for tests and cron catch-up.
 *
 * @return array{queued:int,suppressed:int}
 */
function invoice_notification_schedule_reminders(
    PDO $pdo,
    array $appConfig,
    ?DateTimeImmutable $today = null
): array {
    $today = $today ?: new DateTimeImmutable('today', invoice_notification_timezone($appConfig));
    $stats = ['queued' => 0, 'suppressed' => 0];
    $stmt = $pdo->prepare(
        'SELECT i.id,i.due_date,c.email,i.recipient_presentation_mode
         FROM invoices i JOIN clients c ON c.id=i.client_id
         WHERE i.status IN ("unpaid","partial","sent","overdue")
           AND i.finalized_at IS NOT NULL AND i.collection_mode="direct"
           AND i.due_date IS NOT NULL
           AND COALESCE(i.recipient_presentation_mode, "named") <> "general"'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$row['due_date']);
        if (!$due) {
            continue;
        }
        $stage = invoice_notification_reminder_stage($today, $due, $appConfig);
        if ($stage === null) {
            continue;
        }
        $type = $stage['type'];
        $key = $stage['key'];
        $queued = invoice_notification_enqueue_resolved($pdo, (int)$row['id'], $type, $key, $today);
        $stats['queued'] += $queued['queued'];
        $stats['suppressed'] += $queued['suppressed'];
    }
    return $stats;
}

function invoice_notification_suppress(PDO $pdo, int $id, string $reason): void
{
    invoice_notification_queue_suppress($pdo, 'invoice', $id, $reason);
}

/**
 * @param null|callable(string,string,string,array):array{0:bool,1:string} $sender
 * @return array{claimed:int,sent:int,retry:int,suppressed:int}
 */
function invoice_notification_process(
    PDO $pdo,
    array $appConfig,
    ?callable $sender = null,
    ?DateTimeImmutable $now = null,
    int $limit = 100,
    ?int $onlyInvoiceId = null
): array {
    $now = $now ?: new DateTimeImmutable('now', invoice_notification_timezone($appConfig));
    $stats = ['claimed' => 0, 'sent' => 0, 'retry' => 0, 'suppressed' => 0];
    $ids = invoice_notification_queue_candidates($pdo, 'invoice', $now, $limit, $onlyInvoiceId);
    foreach ($ids as $idValue) {
        $id = (int)$idValue;
        if (!invoice_notification_queue_claim($pdo, 'invoice', $id, $now)) {
            continue;
        }
        $stats['claimed']++;

        $rowStmt = $pdo->prepare(
            'SELECT n.*,i.doc_number,i.invoice_type,i.recipient_presentation_mode,i.status AS invoice_status,i.finalized_at,
                    i.collection_mode,i.total,i.amount_paid,i.due_date,i.payment_terms_days,i.due_date_source,
                    c.email AS current_email,c.name AS client_name
             FROM invoice_notifications n
             JOIN invoices i ON i.id=n.invoice_id JOIN clients c ON c.id=i.client_id WHERE n.id=?'
        );
        $rowStmt->execute([$id]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        $type = (string)$row['notification_type'];
        $status = strtolower((string)$row['invoice_status']);
        $resolution = pa_billing_resolve($pdo, 'invoice', (int)$row['invoice_id']);
        $currentRecipient = null;
        foreach ($resolution['recipients'] as $recipient) {
            if (invoice_notification_recipient_key((string)$recipient['email']) === (string)$row['recipient_key']) {
                $currentRecipient = $recipient;
                break;
            }
        }
        $currentEmail = trim((string)($currentRecipient['email'] ?? ''));
        $balance = max(0.0, (float)$row['total'] - (float)$row['amount_paid']);
        require_once __DIR__ . '/autopay_status.php';
        $autoPayNotice = pa_autopay_pending_notice($pdo, (int)$row['invoice_id']);
        $reason = null;
        if (pa_invoice_is_general_recipient($row)) {
            $reason = 'General-recipient invoices are shared manually and cannot be emailed.';
        } elseif (!in_array($status, ['sent', 'unpaid', 'partial', 'overdue'], true)
            || empty($row['finalized_at']) || ($row['collection_mode'] ?? 'direct') !== 'direct' || $balance <= 0.005) {
            $reason = 'Invoice is no longer eligible for client delivery.';
        } elseif (!filter_var($currentEmail, FILTER_VALIDATE_EMAIL)
            || invoice_notification_recipient_key($currentEmail) !== (string)$row['recipient_key']) {
            $reason = $resolution['reason'] ?: 'Billing recipient is missing, changed, or removed; this delivery was suppressed.';
        } elseif ($type === 'on_generate' && empty($appConfig['invoice_auto_email_on_generate'])) {
            $reason = 'Automatic generated-invoice email is disabled.';
        } elseif ($type === 'due_7' && empty($appConfig['invoice_auto_send_due_7days'])) {
            $reason = 'Pre-due reminders are disabled.';
        } elseif ($type === 'overdue_weekly' && empty($appConfig['invoice_auto_send_overdue_weekly'])) {
            $reason = 'Overdue reminders are disabled.';

        } elseif (in_array($type, ['on_generate', 'on_demand_generate', 'on_finalize'], true) && invoice_missing_content_links_behavior($appConfig) === 'block'
            && invoice_should_prompt_for_missing_content_links($pdo, 'invoice', (int)$row['invoice_id'], $appConfig)) {
            $reason = invoice_missing_content_links_message();
        }

        $due = !empty($row['due_date']) ? (string)$row['due_date'] : '';
        $dueDate = $due !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $due) : false;
        $stage = $dueDate ? invoice_notification_reminder_stage($now->setTime(0, 0), $dueDate, [
            'invoice_auto_send_due_7days' => true,
            'invoice_auto_send_overdue_weekly' => true,
        ], $due) : null;
        if ($reason === null && $type === 'due_7'
            && ($stage === null || $stage['type'] !== 'due_7' || (string)$row['delivery_key'] !== $stage['key'])) {
            $reason = 'Invoice due date changed before reminder delivery.';
        }
        if ($reason === null && $type === 'overdue_weekly') {
            $expectedKey = $stage !== null && $stage['type'] === 'overdue_weekly' ? $stage['key'] : '';
            if ((string)$row['delivery_key'] !== $expectedKey) {
                $reason = 'Invoice due date or overdue stage changed before reminder delivery.';
            }
        }
        if ($reason === null && $autoPayNotice !== '' && in_array($type, ['due_7', 'overdue_weekly'], true)) {
            $reason = 'Automatic payment is reserved; do not request another payment.';
        }
        if ($reason !== null) {
            invoice_notification_suppress($pdo, $id, $reason);
            $stats['suppressed']++;
            continue;
        }

        try {
            $linkId = 0;
            $url = '';
            if (!empty($appConfig['public_links_in_email'])) {
                $base = invoice_notification_public_base($appConfig);
                $publicLink = pa_public_link_reuse_or_create(
                    $pdo, 'invoice', (int)$row['invoice_id'], null, true
                );
                $token = (string)$publicLink['token'];
                $linkId = !empty($publicLink['created']) ? (int)$publicLink['id'] : 0;
                $url = $base . '/?page=public-doc&type=invoice&token=' . rawurlencode($token);
            }
            $doc = $row['doc_number'] ?: $row['invoice_id'];
            $invoiceLabel = pa_invoice_label($row['doc_number'], (string)$row['invoice_type'], $row['invoice_id']);
            $terms = invoice_payment_terms_text($row, $appConfig);
            if ($type === 'due_7') {
                $subject = 'Invoice ' . $invoiceLabel . ' is due ' . date('F j, Y', strtotime($due));
                $intro = 'This is a reminder that invoice <strong>' . htmlspecialchars($invoiceLabel) . '</strong> is coming due.';
            } elseif ($type === 'overdue_weekly') {
                $subject = 'Invoice ' . $invoiceLabel . ' is overdue';
                $intro = 'Invoice <strong>' . htmlspecialchars($invoiceLabel) . '</strong> remains overdue.';
            } else {
                $subject = 'Invoice ' . $invoiceLabel . ' is ready';
                $intro = 'Invoice <strong>' . htmlspecialchars($invoiceLabel) . '</strong> is ready.';
            }
            $body = '<p>Hello ' . htmlspecialchars((string)(($currentRecipient['name'] ?? '') ?: 'there')) . ',</p>'
                . '<p>' . $intro . '</p>'
                . '<p>Outstanding balance: <strong>$' . number_format($balance, 2) . '</strong></p>'
                . ($terms !== '' ? '<p>Payment terms: ' . htmlspecialchars($terms) . '</p>' : '')
                . ($url !== '' ? '<p><a href="' . htmlspecialchars($url) . '">View, print, or save the invoice as PDF</a></p>' : '');

            $body .= invoice_content_links_html(invoice_content_links_for_invoice($pdo, (int)$row['invoice_id'], $appConfig));
            if ($autoPayNotice !== '') $body .= '<p><strong>AutoPay:</strong> ' . htmlspecialchars($autoPayNotice) . '</p>';
            $attachment = document_pdf_attachment($pdo, $appConfig, 'invoice', (int)$row['invoice_id'], (string)$doc);
            $send = $sender ?: static fn(string $to, string $subject, string $body, array $options): array
                => EmailService::sendEmail($to, $subject, $body, $options);
            [$ok, $error] = $send($currentEmail, $subject, $body, [
                'attachments' => [$attachment],
                'document_type' => 'invoice',
                'document_id' => (int)$row['invoice_id'],
                'invoice_id' => (int)$row['invoice_id'],
                'message_key' => 'invoice-notification:' . $id,
            ]);
            if (!$ok) {
                throw new RuntimeException($error ?: 'Email transport failed.');
            }
            invoice_notification_queue_sent($pdo, 'invoice', $id, $now, $currentEmail, $subject, $body);
            $pdo->prepare('UPDATE invoices SET sent_at=COALESCE(sent_at,?) WHERE id=?')
                ->execute([$now->format('Y-m-d H:i:s'), (int)$row['invoice_id']]);
            $stats['sent']++;
        } catch (Throwable $error) {
            invoice_notification_queue_retry($pdo, 'invoice', $id, $now, (int)$row['attempt_count'], $error->getMessage());
            @error_log('[invoice_notifications] Delivery ' . $id . ' failed: ' . $error->getMessage());
            $stats['retry']++;
        }
    }
    return $stats;
}
