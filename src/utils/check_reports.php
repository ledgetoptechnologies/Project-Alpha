<?php
declare(strict_types=1);

require_once __DIR__ . '/invoice_lifecycle.php';
require_once __DIR__ . '/payment_methods.php';
require_once __DIR__ . '/stripe_invoice_payment_amounts.php';

function pa_check_report_table(string $type): string
{
    return match ($type) {
        'invoice' => 'invoices',
        'project_invoice' => 'project_invoices',
        default => throw new InvalidArgumentException('Unsupported check document.'),
    };
}

function pa_check_report_available(PDO $pdo): bool
{
    return invoice_table_has_column($pdo, 'mailed_check_reports', 'client_public_id');
}

function pa_check_report_lookup(PDO $pdo, string $type, int $documentId): ?array
{
    $table = pa_check_report_table($type);
    if (!pa_check_report_available($pdo)) return null;
    $suffix = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $clientColumn = $type === 'invoice' ? 'client_id' : 'primary_client_id';
    $stmt = $pdo->prepare("SELECT r.*,d.id AS live_document_id,d.{$clientColumn} AS live_client_id, (r.status=\"pending\" AND r.expected_by<UTC_TIMESTAMP()) AS overdue FROM {$table} d JOIN mailed_check_reports r ON r.document_public_id=d.public_id AND r.document_type=? WHERE d.id=?" . $suffix);
    $stmt->execute([$type, $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['original_document_id'] = $row['document_id'];
    $row['original_client_id'] = $row['client_id'];
    $row['document_id'] = (int)$row['live_document_id'];
    $row['client_id'] = (int)$row['live_client_id'];
    unset($row['live_document_id'], $row['live_client_id']);
    $row['overdue'] = (bool)$row['overdue'];
    return $row;
}

/** Caller holds the document lock. A deadline never releases this hold. */
function pa_check_report_assert_available(PDO $pdo, string $type, int $documentId): void
{
    $row = pa_check_report_lookup($pdo, $type, $documentId);
    if ($row && $row['status'] === 'pending') {
        throw new DomainException('A mailed check is pending. Confirm receipt or reject the report before another payment or invoice change.');
    }
    if ($type === 'project_invoice' && pa_check_report_available($pdo)) {
        $suffix = $pdo->inTransaction() ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT r.id FROM mailed_check_reports r JOIN invoices d ON d.public_id=r.document_public_id JOIN project_invoice_items i ON i.invoice_id=d.id WHERE r.document_type="invoice" AND r.status="pending" AND i.project_invoice_id=? LIMIT 1' . $suffix);
        $stmt->execute([$documentId]);
        if ($stmt->fetchColumn()) throw new DomainException('A project invoice item has a mailed check pending review.');
    }
}

/** Project before parent before child, shared with statement creation/mutation. */
function pa_check_report_lock_document(PDO $pdo, string $type, int $documentId, bool $resolving = false): array
{
    require_once __DIR__ . '/autopay_collection.php';
    $table = pa_check_report_table($type);
    // Resolution may release its own child hold; canonical recording rechecks
    // every remaining parent/child/processor hold after that release.
    if ($type === 'invoice') pa_autopay_lock_parent_for_invoice_mutation($pdo, $documentId, !$resolving);
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? FOR UPDATE");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) throw new DomainException('Invoice not found.');
    return $document;
}

function pa_check_report_balance_cents(PDO $pdo, string $type, array $document): int
{
    if ($type === 'project_invoice') {
        // Parent is locked by the caller. Lock children before their ledgers,
        // retaining the statement's existing capped-allocation accounting.
        $suffix = $pdo->inTransaction() ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT i.id,i.total,p.amount_due_at_generation FROM project_invoice_items p JOIN invoices i ON i.id=p.invoice_id WHERE p.project_invoice_id=? ORDER BY i.id' . $suffix);
        $stmt->execute([(int)$document['id']]);
        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = stripe_invoice_payment_metadata_cents(['amount'=>(string)$document['total']], ['amount']);
        if ($total === null) throw new DomainException('Project invoice amount is invalid.');
        $paid = 0;
        foreach ($children as $child) {
            $childTotal = stripe_invoice_payment_metadata_cents(['amount'=>(string)$child['total']], ['amount']) ?? 0;
            $originalDue = stripe_invoice_payment_metadata_cents(['amount'=>(string)$child['amount_due_at_generation']], ['amount']) ?? 0;
            $outstanding = max(0, $childTotal - invoice_effective_paid_cents($pdo, (int)$child['id']));
            $applied = $originalDue - min($originalDue, $outstanding);
            if ($applied > PHP_INT_MAX - $paid) throw new OverflowException('Project payment total exceeds the supported amount.');
            $paid += $applied;
        }
        return max(0, $total - $paid);
    }
    $total = stripe_invoice_payment_metadata_cents(['amount'=>(string)$document['total']], ['amount']);
    if ($total === null) throw new DomainException('Invoice amount is invalid.');
    $paid = invoice_effective_paid_cents($pdo, (int)$document['id']);
    return max(0, $total - $paid);
}

function pa_check_report_client_public_id(PDO $pdo, int $clientId): string
{
    $stmt = $pdo->prepare('SELECT public_id FROM clients WHERE id=?');
    $stmt->execute([$clientId]);
    $identity = (string)($stmt->fetchColumn() ?: '');
    if (!preg_match('/^[0-9a-f]{32}$/D', $identity)) throw new DomainException('Client identity is unavailable.');
    return $identity;
}

function pa_check_report_currency(array $document, array $config): string
{
    $currency = strtolower(trim((string)($document['currency'] ?? $config['workforce_currency'] ?? 'usd')));
    if (!in_array($currency, ['usd','cad','eur','gbp','aud','nzd','chf'], true)) throw new DomainException('Check reporting requires a supported two-decimal currency.');
    return $currency;
}

/** Authenticated public controller supplies the freshly verified document client. */
function pa_check_report_submit(PDO $pdo, string $type, int $documentId, int $clientId, string $checkNumber, array $config): array
{
    if (!pa_check_report_available($pdo)) throw new DomainException('Check reporting is unavailable until the database upgrade is complete.');
    if (!pa_payment_methods_has($config, 'check') && !pa_payment_methods_has($config, 'cheque')) throw new DomainException('Check payments are not enabled.');
    $checkNumber = trim($checkNumber);
    if ($checkNumber === '' || strlen($checkNumber) > 64 || preg_match('/[\x00-\x1f\x7f]/', $checkNumber)) throw new DomainException('Enter a valid check number of at most 64 characters.');
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    $savepoint = $owns ? null : 'mailed_check_' . bin2hex(random_bytes(8));
    if ($savepoint !== null) $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $document = pa_check_report_lock_document($pdo, $type, $documentId);
        require_once __DIR__ . '/general_recipient_invoices.php';
        if ($type === 'invoice' && pa_invoice_is_general_recipient($document)) throw new DomainException('Check reporting requires a named client recipient.');
        $owner = (int)($document[$type === 'invoice' ? 'client_id' : 'primary_client_id'] ?? 0);
        if ($clientId <= 0 || $clientId !== $owner) throw new DomainException('Check reporting is unavailable for this recipient.');
        $clientPublicId = pa_check_report_client_public_id($pdo, $clientId);
        $documentPublicId = (string)($document['public_id'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/D', $documentPublicId)) throw new DomainException('Invoice identity is unavailable.');
        $currency = pa_check_report_currency($document, $config);
        $existing = pa_check_report_lookup($pdo, $type, $documentId);
        if ($existing) {
            if ($existing['client_public_id'] !== $clientPublicId || $existing['check_number'] !== $checkNumber || $existing['status'] === 'rejected' || $existing['currency'] !== $currency) throw new DomainException('This invoice already has a check report. Contact staff to review it.');
            if ($owns) $pdo->commit();
            elseif ($savepoint !== null) $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $existing;
        }
        if (empty($document['finalized_at']) || !in_array($document['status'], ['unpaid','sent','partial','overdue'], true) || ($document['collection_mode'] ?? 'direct') !== 'direct') throw new DomainException('Only a finalized invoice with an outstanding balance can receive a check report.');
        pa_autopay_assert_payment_available($pdo, $documentId, $type);
        if (!empty($document['stripe_session_id'])) throw new DomainException('An online payment session already exists. Contact staff before mailing a check.');
        $paymentsTable = $type === 'invoice' ? 'payments' : 'project_invoice_payments';
        $paymentsColumn = $type === 'invoice' ? 'invoice_id' : 'project_invoice_id';
        $pending = $pdo->prepare("SELECT id FROM {$paymentsTable} WHERE {$paymentsColumn}=? AND status IN (\"pending\",\"processing\") LIMIT 1 FOR UPDATE");
        $pending->execute([$documentId]);
        if ($pending->fetchColumn()) throw new DomainException('A payment is still processing. Contact staff before mailing a check.');
        $cents = pa_check_report_balance_cents($pdo, $type, $document);
        if ($cents <= 0) throw new DomainException('This invoice has no outstanding balance.');
        $stmt = $pdo->prepare('INSERT INTO mailed_check_reports(document_type,document_id,document_public_id,client_id,client_public_id,check_number,amount_cents,currency,reported_at,expected_by) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 DAY))');
        $stmt->execute([$type, $documentId, $documentPublicId, $clientId, $clientPublicId, $checkNumber, $cents, $currency]);
        $report = pa_check_report_lookup($pdo, $type, $documentId);
        if ($owns) $pdo->commit();
        elseif ($savepoint !== null) $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return $report;
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif ($savepoint !== null && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $error;
    }
}

/** Caller verifies staff permission and document ownership before resolving. */
function pa_check_report_resolve(PDO $pdo, string $type, int $documentId, string $action, int $actorId, ?string $note = null, ?array $config = null): array
{
    if (!in_array($action, ['received','rejected'], true) || $actorId <= 0) throw new DomainException('Invalid check resolution.');
    if (!pa_check_report_available($pdo)) throw new DomainException('Check reporting is unavailable.');
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    $savepoint = $owns ? null : 'mailed_check_' . bin2hex(random_bytes(8));
    if ($savepoint !== null) $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $document = pa_check_report_lock_document($pdo, $type, $documentId, true);
        $report = pa_check_report_lookup($pdo, $type, $documentId);
        if (!$report) throw new DomainException('Check report not found.');
        if ($report['status'] !== 'pending') {
            if ($report['status'] !== $action) throw new DomainException('This check report has already been resolved.');
            // A historical numeric payment ID can be remapped during restore.
            // The original receipt was issued at confirmation; do not resend it.
            $report['already_resolved'] = true;
            if ($owns) $pdo->commit();
            elseif ($savepoint !== null) $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $report;
        }
        if ($action === 'received' && ($report['currency'] !== pa_check_report_currency($document, $config ?? ($GLOBALS['appConfig'] ?? [])) || $report['client_public_id'] !== pa_check_report_client_public_id($pdo, (int)($document[$type === 'invoice' ? 'client_id' : 'primary_client_id'] ?? 0)) || pa_check_report_balance_cents($pdo, $type, $document) !== (int)$report['amount_cents'])) throw new DomainException('The invoice recipient, balance, or currency changed. Review the check before recording payment.');
        // Temporarily release only this report inside the accounting transaction.
        // Any canonical payment failure rolls the status back with the ledger.
        $pdo->prepare('UPDATE mailed_check_reports SET status=?,resolved_by=?,resolved_at=UTC_TIMESTAMP(),resolution_note=? WHERE id=?')->execute([$action, $actorId, substr(trim((string)$note), 0, 1000), $report['id']]);
        if ($action === 'received') {
            $amount = (int)$report['amount_cents'] / 100;
            if ($type === 'invoice') {
                $payment = invoice_record_locked_payment($pdo, $documentId, $amount, 'check', $report['check_number'], $note, ['complete_contract_when_paid'=>true, 'source'=>'mailed_check']);
                $paymentId = (int)$payment['payment_id'];
            } else {
                require_once __DIR__ . '/project_invoice_billing.php';
                $paymentId = project_invoice_record_manual_payment($pdo, $documentId, $amount, 'check', $report['check_number'], (string)$note);
                if (!$paymentId) throw new DomainException('The project check payment could not be allocated.');
            }
            $pdo->prepare('UPDATE mailed_check_reports SET payment_id=? WHERE id=?')->execute([$paymentId, $report['id']]);
        }
        $report = pa_check_report_lookup($pdo, $type, $documentId);
        $report['already_resolved'] = false;
        if ($owns) $pdo->commit();
        elseif ($savepoint !== null) $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return $report;
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif ($savepoint !== null && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $error;
    }
}
