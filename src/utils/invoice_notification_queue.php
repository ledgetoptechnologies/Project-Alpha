<?php

declare(strict_types=1);

/** SQL identifiers are selected here, never supplied by a request or caller. */
function invoice_notification_queue_definition(string $type): array
{
    return match ($type) {
        'invoice' => ['invoice_notifications', 'invoice_id'],
        'project_invoice' => ['project_invoice_notifications', 'project_invoice_id'],
        default => throw new InvalidArgumentException('Unsupported invoice notification queue.'),
    };
}

function invoice_notification_queue_candidates(PDO $pdo, string $type, DateTimeImmutable $now, int $limit, ?int $documentId): array
{
    [$table, $column] = invoice_notification_queue_definition($type);
    $eligible = '(delivery_status IN ("pending","retry") AND (next_attempt_at IS NULL OR next_attempt_at<=?))
                 OR (delivery_status="processing" AND claimed_at<?)';
    $params = [$now->format('Y-m-d H:i:s'), $now->modify('-10 minutes')->format('Y-m-d H:i:s')];
    $where = '(' . $eligible . ')';
    if ($documentId !== null) {
        $where = $column . '=? AND ' . $where;
        array_unshift($params, $documentId);
    }
    $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id ASC LIMIT ' . max(1, min(500, $limit)));
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Keep the conditional claim atomic without holding a transaction during email delivery. */
function invoice_notification_queue_claim(PDO $pdo, string $type, int $id, DateTimeImmutable $now): bool
{
    [$table] = invoice_notification_queue_definition($type);
    $stmt = $pdo->prepare('UPDATE ' . $table . '
        SET delivery_status="processing",claimed_at=?,last_attempt_at=?,attempt_count=attempt_count+1
        WHERE id=? AND ((delivery_status IN ("pending","retry") AND (next_attempt_at IS NULL OR next_attempt_at<=?))
          OR (delivery_status="processing" AND claimed_at<?))');
    $timestamp = $now->format('Y-m-d H:i:s');
    $stmt->execute([$timestamp, $timestamp, $id, $timestamp, $now->modify('-10 minutes')->format('Y-m-d H:i:s')]);
    return $stmt->rowCount() === 1;
}

function invoice_notification_queue_suppress(PDO $pdo, string $type, int $id, string $reason): void
{
    [$table] = invoice_notification_queue_definition($type);
    // Preserve the existing invoice truncation and project reason behavior.
    if ($type === 'invoice') {
        $reason = substr($reason, 0, 1000);
    }
    $pdo->prepare('UPDATE ' . $table . '
        SET delivery_status="suppressed",claimed_at=NULL,next_attempt_at=NULL,last_error=? WHERE id=?')
        ->execute([$reason, $id]);
}

function invoice_notification_queue_sent(PDO $pdo, string $type, int $id, DateTimeImmutable $now, string $email, string $subject, string $body): void
{
    [$table] = invoice_notification_queue_definition($type);
    $pdo->prepare('UPDATE ' . $table . '
        SET delivery_status="sent",sent_at=?,claimed_at=NULL,next_attempt_at=NULL,last_error=NULL,
            email_to=?,email_subject=?,email_body=? WHERE id=?')
        ->execute([$now->format('Y-m-d H:i:s'), $email, $subject, $body, $id]);
}

function invoice_notification_queue_retry(PDO $pdo, string $type, int $id, DateTimeImmutable $now, int $attempt, string $error): void
{
    [$table] = invoice_notification_queue_definition($type);
    $delay = min(1440, 5 * (2 ** min(8, max(1, $attempt) - 1)));
    $pdo->prepare('UPDATE ' . $table . '
        SET delivery_status="retry",claimed_at=NULL,next_attempt_at=?,last_error=? WHERE id=?')
        ->execute([$now->modify('+' . $delay . ' minutes')->format('Y-m-d H:i:s'), substr($error, 0, 1000), $id]);
}

/** Pure policy shared by scheduling and delivery-time revalidation. */
function invoice_notification_reminder_stage(DateTimeImmutable $today, DateTimeImmutable $due, array $config, ?string $deliveryDueDate = null): ?array
{
    $days = (int)$today->diff($due)->format('%r%a');
    // Scheduling uses the parsed date; delivery historically checks the stored
    // date verbatim, including legacy dates normalized by DateTime parsing.
    $keyDate = $deliveryDueDate ?? $due->format('Y-m-d');
    if (!empty($config['invoice_auto_send_due_7days']) && $days > 0 && $days <= 7) {
        return ['type' => 'due_7', 'key' => 'due:' . $keyDate];
    }
    if (!empty($config['invoice_auto_send_overdue_weekly']) && $days < 0) {
        return ['type' => 'overdue_weekly', 'key' => 'due:' . $keyDate . ':week:' . intdiv(abs($days) - 1, 7)];
    }
    return null;
}
