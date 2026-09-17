<?php
// src/utils/project_invoice_billing.php
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/invoice_content_links.php';
require_once __DIR__ . '/invoice_notifications.php';
require_once __DIR__ . '/document_pdf.php';
require_once __DIR__ . '/project_invoice_notifications.php';
require_once __DIR__ . '/payment_methods.php';

function project_invoice_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table . ':' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return $cache[$key] = false;
    }
    try {
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if ((string)($row['name'] ?? '') === $column) {
                    return $cache[$key] = true;
                }
            }
            return $cache[$key] = false;
        }
        $stmt = $pdo->prepare('
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            LIMIT 1
        ');
        $stmt->execute([$table, $column]);
        return $cache[$key] = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function project_invoice_period_for_date(?string $date = null, bool $previousMonth = false): array
{
    $ts = strtotime($date ?: date('Y-m-d'));
    if ($ts === false) {
        $ts = time();
    }
    if ($previousMonth) {
        $ts = strtotime(date('Y-m-01', $ts) . ' -1 month');
    }
    return [
        date('Y-m-01', $ts),
        date('Y-m-t', $ts),
    ];
}

function project_invoice_due_date_for_period(array $project, array $appConfig, string $periodEnd): string
{
    $netTerms = (int)($appConfig['net_terms_days'] ?? 30);
    if (($project['invoice_net_terms_days'] ?? '') !== '') {
        $netTerms = max(0, (int)$project['invoice_net_terms_days']);
    }
    return date('Y-m-d', strtotime($periodEnd . ' +' . max(0, $netTerms) . ' days'));
}

function project_invoice_sync_clients(PDO $pdo, int $projectId, ?int $primaryClientId, array $clientIds, ?array $invoiceRecipientClientIds = null, ?array $invoiceLinkClientIds = null): void
{
    $clean = [];
    if ($primaryClientId) {
        $clean[] = $primaryClientId;
    }
    foreach ($clientIds as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) {
            $clean[] = $cid;
        }
    }
    $clean = array_values(array_unique($clean));
    $recipientIds = $invoiceRecipientClientIds === null
        ? $clean
        : array_values(array_unique(array_filter(array_map('intval', $invoiceRecipientClientIds), static fn($id) => $id > 0)));
    $linkViewerIds = $invoiceLinkClientIds === null
        ? $clean
        : array_values(array_unique(array_filter(array_map('intval', $invoiceLinkClientIds), static fn($id) => $id > 0)));
    if ($invoiceRecipientClientIds !== null && $primaryClientId && in_array($primaryClientId, $recipientIds, true) && !in_array($primaryClientId, $clean, true)) {
        $clean[] = $primaryClientId;
    }
    if ($invoiceLinkClientIds !== null && $primaryClientId && in_array($primaryClientId, $linkViewerIds, true) && !in_array($primaryClientId, $clean, true)) {
        $clean[] = $primaryClientId;
    }

    $pdo->prepare('DELETE FROM project_clients WHERE project_id = ?')->execute([$projectId]);
    $hasSendColumn = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices');
    $hasLinkColumn = project_invoice_table_has_column($pdo, 'project_clients', 'can_view_invoice_links');
    if ($hasSendColumn && $hasLinkColumn) {
        $ins = $pdo->prepare('INSERT IGNORE INTO project_clients (project_id, client_id, is_primary_billing, send_project_invoices, can_view_invoice_links, sort_order) VALUES (?,?,?,?,?,?)');
    } elseif ($hasSendColumn) {
        $ins = $pdo->prepare('INSERT IGNORE INTO project_clients (project_id, client_id, is_primary_billing, send_project_invoices, sort_order) VALUES (?,?,?,?,?)');
    } else {
        $ins = $pdo->prepare('INSERT IGNORE INTO project_clients (project_id, client_id, is_primary_billing, sort_order) VALUES (?,?,?,?)');
    }
    foreach ($clean as $idx => $cid) {
        $isPrimary = ($primaryClientId && $cid === $primaryClientId) ? 1 : 0;
        $sendProjectInvoices = in_array($cid, $recipientIds, true) ? 1 : 0;
        $canViewInvoiceLinks = in_array($cid, $linkViewerIds, true) ? 1 : 0;
        if ($hasSendColumn && $hasLinkColumn) {
            $ins->execute([$projectId, $cid, $isPrimary, $sendProjectInvoices, $canViewInvoiceLinks, $idx]);
        } elseif ($hasSendColumn) {
            $ins->execute([$projectId, $cid, $isPrimary, $sendProjectInvoices, $idx]);
        } else {
            $ins->execute([$projectId, $cid, $isPrimary, $idx]);
        }
    }
}

function project_invoice_normalize_manual_recipient_emails($value): array
{
    $values = is_array($value) ? $value : preg_split('/[\s,;]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
    $emails = [];
    foreach ($values ?: [] as $candidate) {
        $email = strtolower(trim((string)$candidate));
        if ($email === '') {
            continue;
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter valid manual project invoice recipient email addresses.');
        }
        $emails[$email] = $email;
    }
    return array_values($emails);
}

function project_invoice_has_deliverable_recipient_config(
    PDO $pdo,
    array $clientIds,
    array $manualEmails = [],
    array $organizationIds = []
): bool {
    if (project_invoice_normalize_manual_recipient_emails($manualEmails)) {
        return true;
    }

    $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn($id) => $id > 0)));
    if ($clientIds) {
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $pdo->prepare("SELECT email FROM clients WHERE archived=0 AND id IN ({$placeholders})");
        $stmt->execute($clientIds);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            if (filter_var(trim((string)$email), FILTER_VALIDATE_EMAIL)) {
                return true;
            }
        }
    }

    $organizationIds = array_values(array_unique(array_filter(array_map('intval', $organizationIds), static fn($id) => $id > 0)));
    if ($organizationIds) {
        $placeholders = implode(',', array_fill(0, count($organizationIds), '?'));
        $stmt = $pdo->prepare("SELECT general_email FROM organizations WHERE id IN ({$placeholders})");
        $stmt->execute($organizationIds);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            if (filter_var(trim((string)$email), FILTER_VALIDATE_EMAIL)) {
                return true;
            }
        }
    }

    return false;
}

function project_invoice_recipient_client_ids_in_scope(PDO $pdo, array $clientIds, ?int $organizationId): bool
{
    $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn($id) => $id > 0)));
    if (!$clientIds) {
        return true;
    }
    $params = $clientIds;
    $organizationFilter = '';
    if (($organizationId ?? 0) > 0) {
        $organizationFilter = ' AND organization_id = ?';
        $params[] = $organizationId;
    }
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE archived=0 AND id IN ({$placeholders}){$organizationFilter}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() === count($clientIds);
}

function project_invoice_period_label(array $projectInvoice): string
{
    $end = date('M j, Y', strtotime((string)($projectInvoice['billing_period_end'] ?? 'now')));
    if (($projectInvoice['finalization_source'] ?? '') === 'billing_mode_transition') {
        return 'Closing statement through ' . $end;
    }
    $start = date('M j, Y', strtotime((string)($projectInvoice['billing_period_start'] ?? 'now')));
    return $start . ' - ' . $end;
}

/**
 * Allocate a globally unique visible Project Invoice number under the caller's
 * transaction. The sequence row serializes allocations across projects.
 */
function project_invoice_next_doc_number(PDO $pdo): int
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Project Invoice numbers must be allocated inside a transaction.');
    }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $maxNext = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0)+1 FROM project_invoices')->fetchColumn();
    $maxNext = max(1, $maxNext);
    if ($driver === 'sqlite') {
        $seed = $pdo->prepare(
            "INSERT INTO document_number_sequences (document_type,document_subtype,next_number)
             VALUES ('project_invoice','standard',?)
             ON CONFLICT(document_type,document_subtype) DO UPDATE SET next_number=MAX(next_number,excluded.next_number)"
        );
    } else {
        $seed = $pdo->prepare(
            "INSERT INTO document_number_sequences (document_type,document_subtype,next_number)
             VALUES ('project_invoice','standard',?)
             ON DUPLICATE KEY UPDATE next_number=GREATEST(next_number,VALUES(next_number))"
        );
    }
    $seed->execute([$maxNext]);
    $lockSuffix = $driver === 'sqlite' ? '' : ' FOR UPDATE';
    $row = $pdo->query(
        "SELECT next_number FROM document_number_sequences
         WHERE document_type='project_invoice' AND document_subtype='standard'" . $lockSuffix
    );
    $number = max($maxNext, (int)$row->fetchColumn());
    $update = $pdo->prepare(
        "UPDATE document_number_sequences SET next_number=?
         WHERE document_type='project_invoice' AND document_subtype='standard'"
    );
    $update->execute([$number + 1]);
    return $number;
}

function project_invoice_has_saved_deliverable_recipient(PDO $pdo, int $projectId): bool
{
    foreach (project_invoice_saved_recipients($pdo, $projectId) as $recipient) {
        if (filter_var(trim((string)($recipient['email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            return true;
        }
    }
    return false;
}

function project_invoice_sync_recipients(PDO $pdo, int $projectId, array $clientIds, array $manualEmails = [], array $organizationIds = []): void
{
    if (!project_invoice_table_has_column($pdo, 'project_invoice_recipients', 'recipient_key')) {
        return;
    }

    $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn($id) => $id > 0)));
    $organizationIds = array_values(array_unique(array_filter(array_map('intval', $organizationIds), static fn($id) => $id > 0)));
    $manualEmails = project_invoice_normalize_manual_recipient_emails($manualEmails);
    $pdo->prepare('DELETE FROM project_invoice_recipients WHERE project_id = ?')->execute([$projectId]);
    $insert = $pdo->prepare(
        'INSERT INTO project_invoice_recipients
            (project_id, client_id, organization_id, manual_email, recipient_key, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $sortOrder = 0;
    foreach ($clientIds as $clientId) {
        $insert->execute([$projectId, $clientId, null, null, 'client:' . $clientId, $sortOrder++]);
    }
    foreach ($organizationIds as $organizationId) {
        $insert->execute([$projectId, null, $organizationId, null, 'organization:' . $organizationId, $sortOrder++]);
    }
    foreach ($manualEmails as $email) {
        $insert->execute([$projectId, null, null, $email, 'email:' . $email, $sortOrder++]);
    }
}

function project_invoice_saved_recipients(PDO $pdo, int $projectId): array
{
    if (!project_invoice_table_has_column($pdo, 'project_invoice_recipients', 'recipient_key')) {
        return [];
    }
    $organizationLabel = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'o.name || " (Company email)"'
        : 'CONCAT(o.name, " (Company email)")';
    $stmt = $pdo->prepare(
        'SELECT pir.client_id AS id,
                pir.organization_id,
                pir.recipient_key,
                CASE
                    WHEN pir.organization_id IS NOT NULL THEN ' . $organizationLabel . '
                    ELSE COALESCE(NULLIF(c.name, ""), NULLIF(pir.manual_name, ""), pir.manual_email)
                END AS name,
                CASE
                    WHEN pir.organization_id IS NOT NULL THEN NULLIF(o.general_email, "")
                    ELSE COALESCE(NULLIF(c.email, ""), pir.manual_email)
                END AS email,
                pir.manual_email
         FROM project_invoice_recipients pir
         LEFT JOIN clients c ON c.id = pir.client_id AND c.archived = 0
         LEFT JOIN organizations o ON o.id = pir.organization_id
         WHERE pir.project_id = ?
           AND (pir.client_id IS NOT NULL OR pir.organization_id IS NOT NULL)
         ORDER BY pir.sort_order ASC, pir.id ASC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function project_invoice_client_recipients(PDO $pdo, int $projectInvoiceId, ?array $clientIds = null): array
{
    $selectedClientIds = $clientIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn($id) => $id > 0)));
    if ($selectedClientIds === null && project_invoice_table_has_column($pdo, 'project_invoice_recipients', 'recipient_key')) {
        $projectIdStmt = $pdo->prepare('SELECT project_id FROM project_invoices WHERE id = ?');
        $projectIdStmt->execute([$projectInvoiceId]);
        $projectId = (int)($projectIdStmt->fetchColumn() ?: 0);
        return $projectId > 0 ? project_invoice_saved_recipients($pdo, $projectId) : [];
    }

    $params = [$projectInvoiceId];
    $sendFilter = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices')
        ? 'AND pc.send_project_invoices = 1'
        : '';
    $selectedFilter = '';
    if ($selectedClientIds !== null) {
        if (!$selectedClientIds) {
            return [];
        }
        $selectedFilter = 'AND c.id IN (' . implode(',', array_fill(0, count($selectedClientIds), '?')) . ')';
        $params = array_merge($params, $selectedClientIds);
        $sendFilter = '';
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.email
        FROM project_invoices pi
        JOIN project_clients pc ON pc.project_id = pi.project_id
        JOIN clients c ON c.id = pc.client_id
        WHERE pi.id = ? AND c.email IS NOT NULL AND c.email <> \"\"
          {$sendFilter}
          {$selectedFilter}
        ORDER BY pc.is_primary_billing DESC, pc.sort_order ASC, c.name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        return $rows;
    }
    if ($selectedClientIds !== null) {
        return [];
    }

    $fallback = $pdo->prepare('
        SELECT c.id, c.name, c.email
        FROM project_invoices pi
        JOIN projects p ON p.id = pi.project_id
        JOIN clients c ON c.id = COALESCE(pi.primary_client_id, p.client_id)
        WHERE pi.id = ? AND c.email IS NOT NULL AND c.email <> ""
    ');
    $fallback->execute([$projectInvoiceId]);
    return $fallback->fetchAll(PDO::FETCH_ASSOC);
}

function project_invoice_create_public_link(PDO $pdo, int $projectInvoiceId, array $appConfig): string
{
    return (string)project_invoice_ensure_public_link($pdo, $projectInvoiceId, $appConfig)['token'];
}

/** @return array{id:int,token:string,created:bool} */
function project_invoice_ensure_public_link(PDO $pdo, int $projectInvoiceId, array $appConfig): array
{
    return pa_public_link_reuse_or_create($pdo, 'project_invoice', $projectInvoiceId, null, true);
}

function project_invoice_base_url(array $appConfig): string
{
    return invoice_notification_public_base($appConfig);
}
function project_invoice_refresh_status(PDO $pdo, int $projectInvoiceId): void
{
    $stmt = $pdo->prepare('
        SELECT pi.total, pi.status, pi.finalized_at,
               COALESCE(SUM(
                   GREATEST(
                       0,
                       pii.amount_due_at_generation - LEAST(
                           pii.amount_due_at_generation,
                           GREATEST(0, i.total - COALESCE(p.paid, 0))
                       )
                   )
               ), 0) AS paid
        FROM project_invoices pi
        LEFT JOIN project_invoice_items pii ON pii.project_invoice_id = pi.id
        LEFT JOIN invoices i ON i.id = pii.invoice_id
        LEFT JOIN (
            SELECT invoice_id, SUM(GREATEST(amount-COALESCE(refunded_amount,0)-COALESCE(disputed_amount,0),0)) AS paid
            FROM payments
            WHERE status = "succeeded"
            GROUP BY invoice_id
        ) p ON p.invoice_id = pii.invoice_id
        WHERE pi.id = ?
        GROUP BY pi.id, pi.total, pi.status, pi.finalized_at
    ');
    $stmt->execute([$projectInvoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $total = (float)$row['total'];
    $paid = min($total, (float)$row['paid']);
    $balance = max(0.0, $total - $paid);
    $status = empty($row['finalized_at']) ? 'draft' : 'unpaid';
    $paidAtSql = 'paid_at = NULL';
    if (!empty($row['finalized_at']) && $paid > 0 && $balance > 0) {
        $status = 'partial';
    } elseif (!empty($row['finalized_at']) && $balance <= 0.005) {
        $status = 'paid';
        $paidAtSql = 'paid_at = COALESCE(paid_at, NOW())';
    }

    $pdo->prepare("UPDATE project_invoices SET status=?, amount_paid=?, balance_due=?, {$paidAtSql} WHERE id=? AND status <> 'void'")
        ->execute([$status, $paid, $balance, $projectInvoiceId]);
}

/**
 * @return array{status:string,project_invoice_id:?int,included_count:int,balance:float,message:string}
 */
function project_invoice_create_for_period_result(PDO $pdo, int $projectId, string $periodStart, string $periodEnd, array $appConfig, bool $sendEmail = false, bool $finalize = false): array
{
    $emptyResult = [
        'status' => 'empty',
        'project_invoice_id' => null,
        'included_count' => 0,
        'balance' => 0.0,
        'message' => 'No outstanding project charges are ready for this statement.',
    ];
    $createdBy = (int)($_SESSION['user']['id'] ?? 0) ?: null;
    // Email delivery finalizes only after a usable recipient is confirmed by
    // project_invoice_send_email(). This prevents an undeliverable monthly
    // statement from silently entering receivables aging.
    $shouldFinalize = $finalize;

    try {
        $pdo->beginTransaction();

        $projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? FOR UPDATE');
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$project || ($project['invoice_billing_period'] ?? 'per_invoice') !== 'monthly') {
            $pdo->rollBack();
            return $emptyResult;
        }

        // The cutoff identifies an immutable generation attempt. Checking it
        // before deriving the next start makes repeat clicks and cron retries
        // idempotent even when earlier statements closed part of the month.
        $existing = $pdo->prepare(
            'SELECT id,status,balance_due FROM project_invoices
             WHERE project_id=? AND billing_period_end=?
             ORDER BY (status<>"void") DESC,id DESC LIMIT 1'
        );
        $existing->execute([$projectId, $periodEnd]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC) ?: [];
        $existingId = (int)($existingRow['id'] ?? 0);
        if ($existingId > 0) {
            // Recalculate before making any delivery decision. Payments may
            // have changed the balance since this immutable snapshot was
            // created, and a zero-balance statement must never be emailed.
            project_invoice_refresh_status($pdo, $existingId);
            $existing->execute([$projectId, $periodEnd]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC) ?: $existingRow;
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_invoice_items WHERE project_invoice_id=?');
            $countStmt->execute([$existingId]);
            $includedCount = (int)$countStmt->fetchColumn();
            $existingBalance = max(0.0, (float)($existingRow['balance_due'] ?? 0));
            if ($shouldFinalize && ($existingRow['status'] ?? '') !== 'void' && $existingBalance > 0.005) {
                $pdo->prepare('UPDATE project_invoices SET status=IF(status="draft","unpaid",status), finalized_at=COALESCE(finalized_at,NOW()), finalization_source=COALESCE(finalization_source,"project_billing") WHERE id=? AND status<>"void"')
                    ->execute([$existingId]);
            }
            $pdo->commit();
            if ($sendEmail && ($existingRow['status'] ?? '') !== 'void' && $existingBalance > 0.005) {
                project_invoice_send_email($pdo, $existingId, $appConfig);
            }
            return [
                'status' => 'existing',
                'project_invoice_id' => $existingId,
                'included_count' => $includedCount,
                'balance' => $existingBalance,
                'message' => ($existingRow['status'] ?? '') === 'void'
                    ? 'A void project statement already uses this billing cutoff.'
                    : ($existingBalance > 0.005
                        ? 'The existing project statement for this cutoff was reused.'
                        : 'The existing project statement has no outstanding balance.'),
            ];
        }

        $previous = $pdo->prepare(
            'SELECT MAX(billing_period_end) FROM project_invoices
             WHERE project_id=? AND status<>"void" AND billing_period_end<?'
        );
        $previous->execute([$projectId, $periodEnd]);
        $previousEnd = trim((string)($previous->fetchColumn() ?: ''));
        $effectiveStart = $periodStart;
        if ($previousEnd !== '') {
            $nextDay = date('Y-m-d', strtotime($previousEnd . ' +1 day'));
            if ($nextDay > $effectiveStart) {
                $effectiveStart = $nextDay;
            }
        }

        $invoiceStmt = $pdo->prepare('
            SELECT i.id, i.doc_number, i.status, i.total, i.due_date,
                   DATE(COALESCE(i.fulfillment_date, i.document_date, i.created_at)) AS invoice_date,
                   COALESCE(p.paid, 0) AS paid
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(GREATEST(amount-COALESCE(refunded_amount,0)-COALESCE(disputed_amount,0),0)) AS paid
                FROM payments
                WHERE status = "succeeded"
                GROUP BY invoice_id
            ) p ON p.invoice_id = i.id
            LEFT JOIN project_invoice_items pii ON pii.invoice_id = i.id
            WHERE i.project_id = ?
              AND i.status IN ("sent", "unpaid", "partial", "overdue")
              AND i.finalized_at IS NOT NULL
              AND COALESCE(i.collection_mode, "direct") = "project_aggregate"
              AND DATE(COALESCE(i.fulfillment_date, i.document_date, i.created_at)) <= ?
              AND pii.id IS NULL
            ORDER BY invoice_date ASC, i.doc_number ASC, i.id ASC
        ');
        $invoiceStmt->execute([$projectId, $periodEnd]);
        $children = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0;
        $includedCount = 0;
        foreach ($children as $child) {
            $due = max(0.0, (float)$child['total'] - (float)$child['paid']);
            if ($due > 0.005) {
                $total += $due;
                $includedCount++;
            }
        }
        if ($total <= 0.005 || $includedCount === 0) {
            $pdo->rollBack();
            return $emptyResult;
        }

        $primaryClientId = null;
        $primaryStmt = $pdo->prepare('SELECT client_id FROM project_clients WHERE project_id=? ORDER BY is_primary_billing DESC, sort_order ASC, id ASC LIMIT 1');
        $primaryStmt->execute([$projectId]);
        $primaryClientId = (int)($primaryStmt->fetchColumn() ?: ($project['client_id'] ?? 0)) ?: null;

        $docNumber = project_invoice_next_doc_number($pdo);
        $dueDate = project_invoice_due_date_for_period($project, $appConfig, $periodEnd);
        $insert = $pdo->prepare('
            INSERT INTO project_invoices
                (project_id, organization_id, primary_client_id, doc_number, status, billing_period_start, billing_period_end, due_date, subtotal, total, amount_paid, balance_due, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $insert->execute([
            $projectId,
            !empty($project['organization_id']) ? (int)$project['organization_id'] : null,
            $primaryClientId,
            $docNumber,
            $shouldFinalize ? 'unpaid' : 'draft',
            $effectiveStart,
            $periodEnd,
            $dueDate,
            $total,
            $total,
            0,
            $total,
            $createdBy,
        ]);
        $projectInvoiceId = (int)$pdo->lastInsertId();
        if ($shouldFinalize) {
            $pdo->prepare('UPDATE project_invoices SET finalized_at=NOW(), finalization_source="project_billing" WHERE id=?')
                ->execute([$projectInvoiceId]);
        }

        $item = $pdo->prepare('
            INSERT INTO project_invoice_items
                (project_invoice_id, invoice_id, invoice_doc_number, invoice_date, invoice_due_date, invoice_status, invoice_total, amount_paid_at_generation, amount_due_at_generation)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        foreach ($children as $child) {
            $due = max(0.0, (float)$child['total'] - (float)$child['paid']);
            if ($due <= 0.005) {
                continue;
            }
            $item->execute([
                $projectInvoiceId,
                (int)$child['id'],
                $child['doc_number'] !== null ? (int)$child['doc_number'] : null,
                $child['invoice_date'],
                $child['due_date'] ?: null,
                (string)$child['status'],
                (float)$child['total'],
                (float)$child['paid'],
                $due,
            ]);
            $pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')
                ->execute([(int)$child['id']]);
        }

        $pdo->commit();

        if ($sendEmail) {
            project_invoice_send_email($pdo, $projectInvoiceId, $appConfig);
        }

        return [
            'status' => 'created',
            'project_invoice_id' => $projectInvoiceId,
            'included_count' => $includedCount,
            'balance' => $total,
            'message' => 'Project statement created.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @error_log('[project_invoice_billing] create failed: ' . $e->getMessage());
        $emptyResult['status'] = 'error';
        $emptyResult['message'] = 'Project statement generation failed.';
        return $emptyResult;
    }
}

function project_invoice_create_for_period(PDO $pdo, int $projectId, string $periodStart, string $periodEnd, array $appConfig, bool $sendEmail = false, bool $finalize = false): ?int
{
    $result = project_invoice_create_for_period_result(
        $pdo, $projectId, $periodStart, $periodEnd, $appConfig, $sendEmail, $finalize
    );
    return $result['project_invoice_id'];
}

/**
 * @return array{sent:int,already_sent:int,retry:int,suppressed:int,message:string}
 */
function project_invoice_send_email_result(PDO $pdo, int $projectInvoiceId, array $appConfig, ?array $clientIds = null, bool $allowResend = false, ?array $recipientKeys = null, bool $manualIntent = false, ?callable $sender = null): array
{
    $result = ['sent' => 0, 'already_sent' => 0, 'retry' => 0, 'suppressed' => 0, 'message' => ''];
    $recipients = [];
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $lockSuffix = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare('SELECT status,finalized_at,balance_due FROM project_invoices WHERE id=?' . $lockSuffix);
        $stmt->execute([$projectInvoiceId]);
        $projectInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$projectInvoice || ($projectInvoice['status'] ?? '') === 'void') {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            $result['message'] = 'This project invoice is not available for email delivery.';
            return $result;
        }

        $recipients = project_invoice_client_recipients($pdo, $projectInvoiceId, $clientIds);
        if ($recipientKeys !== null) {
            $selectedKeys = array_fill_keys(array_map('strval', $recipientKeys), true);
            $recipients = array_values(array_filter($recipients, static function (array $recipient) use ($selectedKeys): bool {
                return isset($selectedKeys[(string)($recipient['recipient_key'] ?? '')]);
            }));
        }
        $validRecipientCount = count(array_filter($recipients, static function (array $recipient): bool {
            return filter_var(trim((string)($recipient['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
        }));
        if ($validRecipientCount === 0) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            $result['message'] = 'Select at least one saved recipient with a valid email address.';
            return $result;
        }

        // Recalculate under the statement lock. This both preserves a normal
        // draft and repairs production rows that an older balance refresh left
        // as unpaid without a finalized timestamp.
        project_invoice_refresh_status($pdo, $projectInvoiceId);
        $stmt->execute([$projectInvoiceId]);
        $projectInvoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((float)($projectInvoice['balance_due'] ?? 0) <= 0.005) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            $result['message'] = 'This project invoice has no outstanding balance, so no email was sent.';
            return $result;
        }
        if (empty($projectInvoice['finalized_at'])) {
            $pdo->prepare(
                'UPDATE project_invoices
                 SET status="unpaid",finalized_at=NOW(),
                     finalization_source=COALESCE(finalization_source,"manual_email")
                 WHERE id=? AND status<>"void" AND finalized_at IS NULL AND balance_due>0.005'
            )->execute([$projectInvoiceId]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @error_log('[project_invoice_billing] Email preparation failed: ' . $error->getMessage());
        $result['message'] = 'The project invoice could not be prepared for email delivery.';
        return $result;
    }

    $notificationType = ($allowResend || $manualIntent) ? 'manual' : 'on_generate';
    foreach ($recipients as $recipient) {
        $email = trim((string)($recipient['email'] ?? ''));
        $status = filter_var($email, FILTER_VALIDATE_EMAIL) ? 'pending' : 'suppressed';
        $error = $status === 'suppressed' ? 'Missing or invalid project invoice recipient email.' : null;
        $deliveryKey = $allowResend
            ? 'manual:' . date('YmdHis') . ':' . bin2hex(random_bytes(4))
            : 'generated';
        project_invoice_notification_enqueue(
            $pdo, $projectInvoiceId, $notificationType, $deliveryKey, $email, $status, $error
        );
    }
    $stats = project_invoice_notification_process($pdo, $appConfig, $sender, null, 100, $projectInvoiceId);
    $result['sent'] = (int)$stats['sent'];
    $result['retry'] = (int)$stats['retry'];
    $result['suppressed'] = (int)$stats['suppressed'];
    if ($stats['sent'] > 0) {
        $pdo->prepare(
            'UPDATE project_invoices SET status=IF(status="draft","sent",status),sent_at=COALESCE(sent_at,NOW()) WHERE id=?'
        )->execute([$projectInvoiceId]);
        return $result;
    }

    // Match the regular invoice delivery path: an idempotent repeat of a
    // successful generated send is success, not a misleading zero-send error.
    if (!$allowResend) {
        $recipientHashes = [];
        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipientHashes[] = invoice_notification_recipient_key($email);
            }
        }
        if ($recipientHashes) {
            $placeholders = implode(',', array_fill(0, count($recipientHashes), '?'));
            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM project_invoice_notifications
                 WHERE project_invoice_id=? AND notification_type=? AND delivery_key="generated"
                   AND delivery_status="sent" AND recipient_key IN (' . $placeholders . ')'
            );
            $check->execute(array_merge([$projectInvoiceId, $notificationType], $recipientHashes));
            $result['already_sent'] = (int)$check->fetchColumn();
            if ($result['already_sent'] > 0) {
                return $result;
            }
        }
    }

    $latest = $pdo->prepare(
        'SELECT delivery_status,last_error FROM project_invoice_notifications
         WHERE project_invoice_id=? ORDER BY id DESC LIMIT 1'
    );
    $latest->execute([$projectInvoiceId]);
    $delivery = $latest->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($delivery['delivery_status'] ?? '') === 'retry') {
        $result['message'] = 'The email could not be delivered and is queued for retry. Check outgoing email settings and the delivery log.';
    } elseif (($delivery['delivery_status'] ?? '') === 'suppressed') {
        $result['message'] = trim((string)($delivery['last_error'] ?? '')) ?: 'The email was not sent because this delivery is no longer eligible.';
    } else {
        $result['message'] = 'No project invoice emails were sent. Check the saved recipients and delivery status.';
    }
    return $result;
}

function project_invoice_send_email(PDO $pdo, int $projectInvoiceId, array $appConfig, ?array $clientIds = null, bool $allowResend = false, ?array $recipientKeys = null, bool $manualIntent = false): int
{
    $result = project_invoice_send_email_result(
        $pdo, $projectInvoiceId, $appConfig, $clientIds, $allowResend, $recipientKeys, $manualIntent
    );
    return (int)$result['sent'];
}
function project_invoice_allocate_payment(PDO $pdo, int $projectInvoiceId, float $amount, string $method, string $reference = '', string $notes = '', ?int $projectPaymentId = null, ?string $paymentDate = null): bool
{
    if ($amount <= 0) {
        return false;
    }
    $paymentDate = trim((string)$paymentDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        $paymentDate = date('Y-m-d');
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $parent = $pdo->prepare('SELECT * FROM project_invoices WHERE id=? AND status IN ("unpaid","partial","sent","overdue","paid") FOR UPDATE');
        $parent->execute([$projectInvoiceId]);
        $pi = $parent->fetch(PDO::FETCH_ASSOC);
        if (!$pi) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        if ($projectPaymentId) {
            $allocated = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE project_invoice_payment_id=?');
            $allocated->execute([$projectPaymentId]);
            if ((int)$allocated->fetchColumn() > 0) {
                $pdo->prepare('UPDATE project_invoice_payments SET status="succeeded" WHERE id=?')
                    ->execute([$projectPaymentId]);
                project_invoice_refresh_status($pdo, $projectInvoiceId);
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return true;
            }
        }

        $children = $pdo->prepare('
            SELECT i.id, i.client_id, i.contract_id, i.organization_id, i.total,
                   COALESCE(p.paid, 0) AS paid
            FROM project_invoice_items pii
            JOIN invoices i ON i.id = pii.invoice_id
            LEFT JOIN (
                SELECT invoice_id, SUM(GREATEST(amount-COALESCE(refunded_amount,0)-COALESCE(disputed_amount,0),0)) AS paid
                FROM payments
                WHERE status = "succeeded"
                GROUP BY invoice_id
            ) p ON p.invoice_id = i.id
            WHERE pii.project_invoice_id = ?
            ORDER BY COALESCE(i.due_date, i.created_at) ASC, i.doc_number ASC, i.id ASC
        ');
        $children->execute([$projectInvoiceId]);

        $childRows = $children->fetchAll(PDO::FETCH_ASSOC);
        $available = 0.0;
        foreach ($childRows as $child) {
            $available += max(0.0, (float)$child['total'] - (float)$child['paid']);
        }
        if ($amount > $available + 0.005) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $remaining = $amount;
        $pay = $pdo->prepare('
            INSERT INTO payments (client_id, invoice_id, project_invoice_payment_id, contract_id, organization_id, amount, payment_method, reference_number, notes, status, payment_date)
            VALUES (?,?,?,?,?,?,?,?,?, "succeeded", ?)
        ');
        $updateInvoice = $pdo->prepare('UPDATE invoices SET status=?, amount_paid=?, balance_due=?, paid_at = IF(? = "paid", COALESCE(paid_at, NOW()), paid_at) WHERE id=?');

        foreach ($childRows as $child) {
            if ($remaining <= 0.005) {
                break;
            }
            $childTotal = (float)$child['total'];
            $childPaid = (float)$child['paid'];
            $childDue = max(0.0, $childTotal - $childPaid);
            if ($childDue <= 0) {
                continue;
            }
            $apply = min($remaining, $childDue);
            $paymentNotes = trim($notes . "\nProject invoice PI-" . ($pi['doc_number'] ?: $projectInvoiceId));
            $pay->execute([
                (int)$child['client_id'],
                (int)$child['id'],
                $projectPaymentId,
                !empty($child['contract_id']) ? (int)$child['contract_id'] : null,
                !empty($child['organization_id']) ? (int)$child['organization_id'] : null,
                $apply,
                $method ?: 'cash',
                $reference ?: null,
                $paymentNotes ?: null,
                $paymentDate,
            ]);

            $newPaid = $childPaid + $apply;
            $balance = max(0.0, $childTotal - $newPaid);
            $status = $balance <= 0.005 ? 'paid' : 'partial';
            $updateInvoice->execute([$status, $newPaid, $balance, $status, (int)$child['id']]);
            $pdo->prepare('UPDATE project_invoice_items SET amount_applied = amount_applied + ? WHERE project_invoice_id=? AND invoice_id=?')
                ->execute([$apply, $projectInvoiceId, (int)$child['id']]);
            $remaining -= $apply;
        }

        project_invoice_refresh_status($pdo, $projectInvoiceId);
        if ($projectPaymentId) {
            $pdo->prepare('UPDATE project_invoice_payments SET status="succeeded" WHERE id=?')
                ->execute([$projectPaymentId]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @error_log('[project_invoice_billing] payment allocation failed: ' . $e->getMessage());
        if (!$ownsTransaction) {
            throw $e;
        }
        return false;
    }
}

function project_invoice_record_stripe_payment(PDO $pdo, array $stripeObject): bool
{
    require_once __DIR__ . '/../services/StripeService.php';
    require_once __DIR__ . '/stripe_payment_accounting.php';

    $metadata = $stripeObject['metadata'] ?? [];
    $projectInvoiceId = (int)($metadata['pa_project_invoice_id'] ?? $metadata['project_invoice_id'] ?? 0);
    if ($projectInvoiceId <= 0) {
        return false;
    }
    $sessionId = str_starts_with((string)($stripeObject['id'] ?? ''), 'cs_') ? (string)$stripeObject['id'] : null;
    $paymentIntentId = $sessionId
        ? (is_string($stripeObject['payment_intent'] ?? null) ? $stripeObject['payment_intent'] : null)
        : (string)($stripeObject['id'] ?? '');
    $amountCents = $sessionId ? ($stripeObject['amount_total'] ?? 0) : ($stripeObject['amount_received'] ?? $stripeObject['amount'] ?? 0);
    $grossAmount = ((float)$amountCents) / 100;
    $amount = isset($metadata['original_amount']) ? (float)$metadata['original_amount'] : $grossAmount;
    if ($amount <= 0 || (!$sessionId && $paymentIntentId === '')) {
        return false;
    }

    $processorTx = [
        'provider' => 'stripe',
        'provider_payment_id' => $paymentIntentId ?: '',
        'status' => 'succeeded',
        'gross_amount' => $grossAmount,
        'fee_amount' => null,
        'net_amount' => null,
        'metadata' => $metadata,
    ];
    if ($paymentIntentId) {
        $stripe = StripeService::fromAppConfig($GLOBALS['appConfig'] ?? []);
        if ($stripe) {
            try {
                $processorTx = $stripe->normalizePaymentIntentForImport(
                    str_starts_with((string)($stripeObject['id'] ?? ''), 'pi_')
                        ? $stripeObject
                        : $stripe->getPaymentIntentWithBalanceTransaction($paymentIntentId)
                );
            } catch (Throwable $e) {
                @error_log('[project_invoice_billing] Stripe fee/net lookup failed for ' . $paymentIntentId . ': ' . $e->getMessage());
            }
        }
    }
    $processorFields = stripe_processor_fields_from_normalized($processorTx, $GLOBALS['appConfig'] ?? [], $amount, 0.0);

    $pdo->prepare(
        'INSERT IGNORE INTO project_invoice_payments
         (project_invoice_id,amount,payment_method,processor_provider,processor_payment_id,processor_gross_amount,
          processor_fee_amount,processor_net_amount,processor_fee_policy,processor_fee_source,
          stripe_session_id,stripe_payment_intent_id,status,payment_date)
         VALUES (?,? ,"stripe",?,?,?,?,?,?,?,?,? ,"processing",CURDATE())'
    )->execute([
        $projectInvoiceId,
        $amount,
        $processorFields['processor_provider'],
        $processorFields['processor_payment_id'] ?: null,
        $processorFields['processor_gross_amount'],
        $processorFields['processor_fee_amount'],
        $processorFields['processor_net_amount'],
        $processorFields['processor_fee_policy'],
        $processorFields['processor_fee_source'],
        $sessionId,
        $paymentIntentId ?: null,
    ]);
    $lookup = $pdo->prepare(
        'SELECT id,status FROM project_invoice_payments
         WHERE (stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id=?)
            OR (stripe_session_id IS NOT NULL AND stripe_session_id=?) LIMIT 1'
    );
    $lookup->execute([$paymentIntentId ?: '', $sessionId ?: '']);
    $projectPayment = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$projectPayment) {
        return false;
    }
    $projectPaymentId = (int)$projectPayment['id'];
    if (($projectPayment['status'] ?? '') === 'succeeded') {
        stripe_update_project_payment_processor_fields($pdo, $projectPaymentId, $processorTx, $GLOBALS['appConfig'] ?? []);
        require_once __DIR__ . '/notifications.php';
        require_once __DIR__ . '/payment_receipts.php';
        $status = $pdo->prepare('SELECT status FROM project_invoices WHERE id=?');
        $status->execute([$projectInvoiceId]);
        $statusValue = (string)$status->fetchColumn();
        payment_email_attempt_all(
            static fn() => notify_admin_project_invoice_paid(
                $pdo, $GLOBALS['appConfig'] ?? [], $projectInvoiceId, $amount,
                $statusValue === 'paid' ? 'paid' : 'partial', false, true, null,
                'project-payment:' . $projectPaymentId
            ),
            static fn() => project_payment_receipt_email_issue(
                $pdo, $projectPaymentId, $GLOBALS['appConfig'] ?? [], null, true
            )
        );
        return true;
    }
    $pdo->prepare('UPDATE project_invoice_payments SET stripe_session_id=COALESCE(stripe_session_id,?),stripe_payment_intent_id=COALESCE(stripe_payment_intent_id,?),amount=? WHERE id=?')
        ->execute([$sessionId, $paymentIntentId ?: null, $amount, $projectPaymentId]);
    stripe_update_project_payment_processor_fields($pdo, $projectPaymentId, $processorTx, $GLOBALS['appConfig'] ?? []);

    $ok = project_invoice_allocate_payment(
        $pdo,
        $projectInvoiceId,
        $amount,
        'stripe',
        $paymentIntentId ?: ($sessionId ?: ''),
        'Client-approved one-time Stripe payment',
        $projectPaymentId
    );
    $pdo->prepare('UPDATE project_invoice_payments SET status=? WHERE id=?')
        ->execute([$ok ? 'succeeded' : 'failed', $projectPaymentId]);
    if ($ok) {
        require_once __DIR__ . '/stripe_financial_events.php';
        require_once __DIR__ . '/notifications.php';
        stripe_link_pending_project_financial_events($pdo, $projectPaymentId, $paymentIntentId ?: null);
        stripe_allocate_project_processor_fields($pdo, $projectPaymentId, $processorFields);
        $pdo->prepare('UPDATE project_invoices SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$projectInvoiceId]);
        $status = $pdo->prepare('SELECT status FROM project_invoices WHERE id=?');
        $status->execute([$projectInvoiceId]);
        $statusValue = (string)$status->fetchColumn();
        if ($statusValue === 'paid') {
            pa_public_link_terminalize($pdo, 'project_invoice', $projectInvoiceId, 'paid');
        }
        require_once __DIR__ . '/payment_receipts.php';
        payment_email_attempt_all(
            static fn() => notify_admin_project_invoice_paid(
                $pdo, $GLOBALS['appConfig'] ?? [], $projectInvoiceId, $amount,
                $statusValue === 'paid' ? 'paid' : 'partial', true, true, null,
                'project-payment:' . $projectPaymentId
            ),
            static fn() => project_payment_receipt_email_issue(
                $pdo, $projectPaymentId, $GLOBALS['appConfig'] ?? [], null, true
            )
        );
    }
    return $ok;
}

/** @return array{processed:int,generated:int,existing:int,empty:int,drafted:int,delivered:int,already_delivered:int,delivery_pending:int,delivery_failed:int} */
function project_invoice_generate_due_monthly_result(
    PDO $pdo,
    array $appConfig,
    ?DateTimeInterface $runAt = null,
    ?callable $sender = null
): array
{
    $runDate = $runAt ? $runAt->format('Y-m-d') : date('Y-m-d');
    [$start, $end] = project_invoice_period_for_date($runDate, true);
    $hasAutoEmail = project_invoice_table_has_column($pdo, 'projects', 'project_invoice_auto_email');
    $selectAuto = $hasAutoEmail ? ', project_invoice_auto_email' : '';
    // Terminal or overdue Projects can still have finalized, unassigned work
    // from before their status changed. The child-invoice eligibility query is
    // the authority; status must not strand an otherwise collectible balance.
    $stmt = $pdo->query('SELECT id' . $selectAuto . ' FROM projects WHERE invoice_billing_period = "monthly"');
    $stats = [
        'processed' => 0,
        'generated' => 0,
        'existing' => 0,
        'empty' => 0,
        'drafted' => 0,
        'delivered' => 0,
        'already_delivered' => 0,
        'delivery_pending' => 0,
        'delivery_failed' => 0,
    ];
    $stateStmt = $pdo->prepare('SELECT status,sent_at,balance_due FROM project_invoices WHERE id=?');
    $deliveryStmt = $pdo->prepare(
        'SELECT delivery_status,COUNT(*) AS status_count FROM project_invoice_notifications
         WHERE project_invoice_id=? AND notification_type="on_generate" AND delivery_key="generated"
         GROUP BY delivery_status'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $project) {
        $projectId = (int)$project['id'];
        $sendEmail = $hasAutoEmail ? !empty($project['project_invoice_auto_email']) : true;
        $generation = project_invoice_create_for_period_result($pdo, $projectId, $start, $end, $appConfig, false, false);
        if (($generation['status'] ?? '') === 'error') {
            $stats['delivery_failed']++;
            @error_log('[project_invoice_billing] Monthly project statement generation failed for project ' . $projectId . '.');
            continue;
        }
        if (($generation['status'] ?? '') === 'empty') {
            $stats['empty']++;
            continue;
        }
        $id = (int)($generation['project_invoice_id'] ?? 0);
        if ($id <= 0) {
            $stats['empty']++;
            continue;
        }

        $stats['processed']++;
        $stats[($generation['status'] ?? '') === 'created' ? 'generated' : 'existing']++;
        $stateStmt->execute([$id]);
        $state = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (($state['status'] ?? '') === 'void' || (float)($state['balance_due'] ?? 0) <= 0.005) {
            $stats['empty']++;
            continue;
        }
        if (!$sendEmail) {
            $stats['drafted']++;
            continue;
        }

        if (!empty($state['sent_at'])) {
            $stats['already_delivered']++;
            continue;
        }
        if (!project_invoice_has_saved_deliverable_recipient($pdo, $projectId)) {
            $stats['delivery_failed']++;
            @error_log('[project_invoice_billing] Monthly project invoice delivery blocked: project ' . $projectId . ' has no valid saved recipient.');
            continue;
        }

        $deliveryResult = project_invoice_send_email_result(
            $pdo,
            $id,
            $appConfig,
            null,
            false,
            null,
            false,
            $sender
        );
        $sent = (int)$deliveryResult['sent'];
        if ($sent > 0) {
            $stats['delivered'] += $sent;
        }

        $deliveryStmt->execute([$id]);
        $deliveryStatuses = [];
        foreach ($deliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $deliveryRow) {
            $deliveryStatuses[(string)$deliveryRow['delivery_status']] = (int)$deliveryRow['status_count'];
        }
        $hasPendingDelivery = array_sum(array_intersect_key($deliveryStatuses, array_flip(['pending', 'processing', 'retry']))) > 0;
        $hasFailedDelivery = array_sum(array_intersect_key($deliveryStatuses, array_flip(['failed', 'suppressed']))) > 0;
        if ($hasPendingDelivery) {
            $stats['delivery_pending']++;
            @error_log('[project_invoice_billing] Monthly project invoice delivery pending or retrying for project ' . $projectId . '.');
        }
        if ($hasFailedDelivery || ($sent === 0 && !$hasPendingDelivery)) {
            $stats['delivery_failed']++;
            @error_log('[project_invoice_billing] Monthly project invoice delivery failed for project ' . $projectId . '.');
        }
    }
    return $stats;
}

function project_invoice_record_manual_payment(PDO $pdo, int $projectInvoiceId, float $amount, string $method, string $reference = '', string $notes = '', ?string $paymentDate = null): ?int
{
    $paymentDate = trim((string)$paymentDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        $paymentDate = date('Y-m-d');
    }
    $method = project_invoice_payment_method_key($method);
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $insert = $pdo->prepare(
            'INSERT INTO project_invoice_payments
             (project_invoice_id,amount,payment_method,status,payment_date)
             VALUES (?,?,?,"processing",?)'
        );
        $insert->execute([$projectInvoiceId, $amount, $method, $paymentDate]);
        $projectPaymentId = (int)$pdo->lastInsertId();
        $ok = project_invoice_allocate_payment(
            $pdo, $projectInvoiceId, $amount, $method, $reference, $notes, $projectPaymentId, $paymentDate
        );
        if (!$ok) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $projectPaymentId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @error_log('[project_invoice_billing] manual project payment failed: ' . $e->getMessage());
        return null;
    }
}

function project_invoice_payment_method_key(string $method): string
{
    $method = pa_payment_method_key($method);
    return match ($method) {
        'cheque' => 'check',
        'bank' => 'bank_transfer',
        'credit_card' => 'card',
        '' => 'other',
        default => $method,
    };
}

function project_invoice_generate_due_monthly(PDO $pdo, array $appConfig): int
{
    return project_invoice_generate_due_monthly_result($pdo, $appConfig)['processed'];
}
