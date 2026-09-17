<?php

require_once __DIR__ . '/project_invoice_billing.php';

function project_invoice_notification_enqueue(
    PDO $pdo,
    int $projectInvoiceId,
    string $type,
    string $deliveryKey,
    string $email,
    string $status = 'pending',
    ?string $error = null,
    ?DateTimeImmutable $availableAt = null
): bool {
    $email = trim($email);
    $recipientKey = invoice_notification_recipient_key($email);
    $availableAt = $availableAt ?: new DateTimeImmutable('now');
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO project_invoice_notifications
         (project_invoice_id,notification_type,delivery_key,recipient_key,delivery_status,
          attempt_count,next_attempt_at,last_error,email_to)
         VALUES (?,?,?,?,?,0,?,?,?)'
    );
    $stmt->execute([
        $projectInvoiceId, $type, substr($deliveryKey, 0, 100), $recipientKey, $status,
        $availableAt->format('Y-m-d H:i:s'), $error, $email,
    ]);
    return $stmt->rowCount() === 1;
}

/** @return array{queued:int,suppressed:int} */
function project_invoice_notification_schedule_reminders(
    PDO $pdo,
    array $appConfig,
    ?DateTimeImmutable $today = null
): array {
    $today = $today ?: new DateTimeImmutable('today', invoice_notification_timezone($appConfig));
    $stats = ['queued' => 0, 'suppressed' => 0];
    $rows = $pdo->query(
        'SELECT id,due_date FROM project_invoices
         WHERE status IN ("sent","unpaid","partial")
           AND finalized_at IS NOT NULL AND balance_due>0.005 AND due_date IS NOT NULL'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$row['due_date']);
        if (!$due) {
            continue;
        }
        $days = (int)$today->diff($due)->format('%r%a');
        $type = '';
        $key = '';
        if (!empty($appConfig['invoice_auto_send_due_7days']) && $days > 0 && $days <= 7) {
            $type = 'due_7';
            $key = 'due:' . $due->format('Y-m-d');
        } elseif (!empty($appConfig['invoice_auto_send_overdue_weekly']) && $days < 0) {
            $type = 'overdue_weekly';
            $key = 'due:' . $due->format('Y-m-d') . ':week:' . intdiv(abs($days) - 1, 7);
        }
        if ($type === '') {
            continue;
        }
        $recipients = project_invoice_client_recipients($pdo, (int)$row['id']);
        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            $status = filter_var($email, FILTER_VALIDATE_EMAIL) ? 'pending' : 'suppressed';
            $error = $status === 'suppressed' ? 'Missing or invalid project invoice recipient email.' : null;
            if (project_invoice_notification_enqueue(
                $pdo, (int)$row['id'], $type, $key, $email, $status, $error, $today
            )) {
                $stats[$status === 'pending' ? 'queued' : 'suppressed']++;
            }
        }
    }
    return $stats;
}

/**
 * @param null|callable(string,string,string,array):array{0:bool,1:string} $sender
 * @return array{claimed:int,sent:int,retry:int,suppressed:int}
 */
function project_invoice_notification_process(
    PDO $pdo,
    array $appConfig,
    ?callable $sender = null,
    ?DateTimeImmutable $now = null,
    int $limit = 100,
    ?int $onlyProjectInvoiceId = null
): array {
    $now = $now ?: new DateTimeImmutable('now', invoice_notification_timezone($appConfig));
    $stats = ['claimed' => 0, 'sent' => 0, 'retry' => 0, 'suppressed' => 0];
    $sql = 'SELECT id FROM project_invoice_notifications
            WHERE (delivery_status IN ("pending","retry") AND (next_attempt_at IS NULL OR next_attempt_at<=?))
               OR (delivery_status="processing" AND claimed_at<?)';
    $params = [$now->format('Y-m-d H:i:s'), $now->modify('-10 minutes')->format('Y-m-d H:i:s')];
    if ($onlyProjectInvoiceId !== null) {
        $sql = 'SELECT id FROM project_invoice_notifications
                WHERE project_invoice_id=? AND ((delivery_status IN ("pending","retry") AND (next_attempt_at IS NULL OR next_attempt_at<=?))
                   OR (delivery_status="processing" AND claimed_at<?))';
        array_unshift($params, $onlyProjectInvoiceId);
    }
    $sql .= ' ORDER BY id LIMIT ' . max(1, min(500, $limit));
    $ids = $pdo->prepare($sql);
    $ids->execute($params);    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $idValue) {
        $id = (int)$idValue;
        $claim = $pdo->prepare(
            'UPDATE project_invoice_notifications
             SET delivery_status="processing",claimed_at=?,last_attempt_at=?,attempt_count=attempt_count+1
             WHERE id=? AND ((delivery_status IN ("pending","retry") AND (next_attempt_at IS NULL OR next_attempt_at<=?))
                OR (delivery_status="processing" AND claimed_at<?))'
        );
        $claim->execute([
            $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $id,
            $now->format('Y-m-d H:i:s'), $now->modify('-10 minutes')->format('Y-m-d H:i:s'),
        ]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $stats['claimed']++;
        $stmt = $pdo->prepare(
            'SELECT n.*,pi.doc_number,pi.revision_number,pi.status AS invoice_status,pi.finalized_at,pi.balance_due,pi.due_date,
                    pi.project_id,p.name AS project_name,p.invoice_net_terms_days,p.project_invoice_auto_email
             FROM project_invoice_notifications n
             JOIN project_invoices pi ON pi.id=n.project_invoice_id
             JOIN projects p ON p.id=pi.project_id WHERE n.id=?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        $currentRecipient = null;
        foreach (project_invoice_client_recipients($pdo, (int)$row['project_invoice_id']) as $recipient) {
            if (invoice_notification_recipient_key((string)$recipient['email']) === (string)$row['recipient_key']) {
                $currentRecipient = $recipient;
                break;
            }
        }
        if (!$currentRecipient && $row['notification_type'] === 'manual') {
            $queuedEmail = trim((string)($row['email_to'] ?? ''));
            if (filter_var($queuedEmail, FILTER_VALIDATE_EMAIL)) {
                // A manual send is an explicit, authenticated point-in-time
                // choice. Retries honor that queued destination even when it
                // is not part of the project's automatic reminder list.
                $currentRecipient = ['id' => null, 'name' => 'there', 'email' => $queuedEmail];
            }
        }
        $reason = null;
        if (!in_array((string)$row['invoice_status'], ['sent', 'unpaid', 'partial'], true)
            || empty($row['finalized_at']) || (float)$row['balance_due'] <= 0.005) {
            $reason = in_array((string)$row['notification_type'], ['due_7', 'overdue_weekly'], true)
                ? 'Project invoice is no longer eligible for reminders.'
                : 'Project invoice is no longer eligible for email delivery.';
        } elseif (!$currentRecipient) {
            $reason = 'Project invoice recipient is missing, changed, or opted out.';
        } elseif ($row['notification_type'] === 'due_7' && empty($appConfig['invoice_auto_send_due_7days'])) {
            $reason = 'Pre-due reminders are disabled.';
        } elseif ($row['notification_type'] === 'overdue_weekly' && empty($appConfig['invoice_auto_send_overdue_weekly'])) {
            $reason = 'Overdue reminders are disabled.';
        } elseif ($row['notification_type'] === 'on_generate' && empty($row['project_invoice_auto_email'])) {
            $reason = 'Automatic project invoice email is disabled.';
        } elseif (in_array($row['notification_type'], ['on_generate', 'manual'], true)
            && invoice_missing_content_links_behavior($appConfig) === 'block'
            && invoice_should_prompt_for_missing_content_links($pdo, 'project_invoice', (int)$row['project_invoice_id'], $appConfig)) {
            $reason = invoice_missing_content_links_message();
        }

        $due = (string)$row['due_date'];
        $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
        $days = $dueDate ? (int)$now->setTime(0, 0)->diff($dueDate)->format('%r%a') : 0;
        if ($reason === null && $row['notification_type'] === 'due_7'
            && ((string)$row['delivery_key'] !== 'due:' . $due || $days < 1 || $days > 7)) {
            $reason = 'Project invoice due date changed before reminder delivery.';
        }
        if ($reason === null && $row['notification_type'] === 'overdue_weekly') {
            $expected = $days < 0 ? 'due:' . $due . ':week:' . intdiv(abs($days) - 1, 7) : '';
            if ((string)$row['delivery_key'] !== $expected) {
                $reason = 'Project invoice due date or overdue stage changed before reminder delivery.';
            }
        }
        if ($reason !== null) {
            $pdo->prepare(
                'UPDATE project_invoice_notifications
                 SET delivery_status="suppressed",claimed_at=NULL,next_attempt_at=NULL,last_error=? WHERE id=?'
            )->execute([$reason, $id]);
            $stats['suppressed']++;
            continue;
        }

        $linkId = 0;
        try {
            $url = '';
            if (!empty($appConfig['public_links_in_email'])) {
                $publicLink = project_invoice_ensure_public_link($pdo, (int)$row['project_invoice_id'], $appConfig);
                $token = (string)$publicLink['token'];
                $linkId = !empty($publicLink['created']) ? (int)$publicLink['id'] : 0;
                $url = project_invoice_base_url($appConfig) . '/?page=public-doc&token=' . rawurlencode($token);
            }
            $doc = (string)($row['doc_number'] ?: $row['project_invoice_id']);
            $attachment = document_pdf_attachment(
                $pdo, $appConfig, 'project_invoice', (int)$row['project_invoice_id'], $doc
            );
            if ($row['notification_type'] === 'due_7') {
                $subject = 'Project invoice PI-' . $doc . ' is due ' . date('F j, Y', strtotime($due));
                $intro = 'is coming due.';
            } elseif ($row['notification_type'] === 'overdue_weekly') {
                $subject = 'Project invoice PI-' . $doc . ' is overdue';
                $intro = 'remains overdue.';
            } else {
                $subject = 'Project invoice PI-' . $doc . ' is ready';
                $intro = 'is ready.';
            }
            $termDays = max(0, (int)($row['invoice_net_terms_days'] ?? ($appConfig['net_terms_days'] ?? 30)));
            $body = '<p>Hello ' . htmlspecialchars((string)($currentRecipient['name'] ?: 'there')) . ',</p>'
                . '<p>Project invoice <strong>PI-' . htmlspecialchars($doc) . '</strong> '
                . $intro . '</p>'
                . '<p>Outstanding balance: <strong>$' . number_format((float)$row['balance_due'], 2) . '</strong></p>'
                . '<p>Payment terms: Net ' . $termDays . ' (due ' . date('F j, Y', strtotime($due)) . ')</p>'
                . ($url !== '' ? '<p><a href="' . htmlspecialchars($url) . '">View project invoice</a></p>' : '');
            $contentLinkClientIds = !empty($currentRecipient['id']) ? [(int)$currentRecipient['id']] : [];
            $body .= invoice_content_links_html(invoice_content_links_for_project_invoice($pdo, (int)$row['project_invoice_id'], $appConfig, $contentLinkClientIds));
            $send = $sender ?: static fn(string $to, string $subject, string $body, array $options): array
                => EmailService::sendEmail($to, $subject, $body, $options);
            [$ok, $error] = $send((string)$currentRecipient['email'], $subject, $body, [
                'attachments' => [$attachment],
                'document_type' => 'project_invoice',
                'document_id' => (int)$row['project_invoice_id'],
                'document_revision' => (int)($row['revision_number'] ?? 1),
                'message_key' => 'project-invoice-notification:' . $id,
            ]);
            if (!$ok) {
                throw new RuntimeException($error ?: 'Email transport failed.');
            }
            $pdo->prepare(
                'UPDATE project_invoice_notifications
                 SET delivery_status="sent",sent_at=?,claimed_at=NULL,next_attempt_at=NULL,last_error=NULL,
                     email_to=?,email_subject=?,email_body=? WHERE id=?'
            )->execute([$now->format('Y-m-d H:i:s'), $currentRecipient['email'], $subject, $body, $id]);
            $pdo->prepare(
                'UPDATE project_invoices SET sent_at=COALESCE(sent_at,?),status=IF(status="draft","sent",status) WHERE id=?'
            )->execute([$now->format('Y-m-d H:i:s'), (int)$row['project_invoice_id']]);
            $stats['sent']++;
        } catch (Throwable $error) {
            $attempt = max(1, (int)$row['attempt_count']);
            $delay = min(1440, 5 * (2 ** min(8, $attempt - 1)));
            $pdo->prepare(
                'UPDATE project_invoice_notifications
                 SET delivery_status="retry",claimed_at=NULL,next_attempt_at=?,last_error=? WHERE id=?'
            )->execute([$now->modify('+' . $delay . ' minutes')->format('Y-m-d H:i:s'), substr($error->getMessage(), 0, 1000), $id]);
            @error_log('[project_invoice_notifications] Delivery ' . $id . ' failed: ' . $error->getMessage());
            $stats['retry']++;
        }
    }
    return $stats;
}
