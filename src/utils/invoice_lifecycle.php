<?php

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/invoice_content_links.php';
require_once __DIR__ . '/invoice_numbers.php';
require_once __DIR__ . '/invoice_notifications.php';
require_once __DIR__ . '/general_recipient_invoices.php';

function invoice_is_collectible_status(string $status): bool
{
    return in_array(strtolower($status), ['sent', 'unpaid', 'partial', 'overdue'], true);
}

/**
 * A draft is work in progress, not a receivable.  Only an issued collectible
 * invoice with an explicit due date in the past may be presented as overdue.
 *
 * @param array{status?:mixed,due_date?:mixed} $invoice
 */
function invoice_is_past_due(array $invoice, ?DateTimeImmutable $today = null): bool
{
    if (!invoice_is_collectible_status((string)($invoice['status'] ?? ''))) {
        return false;
    }

    // Project-aggregate child invoices are internal billing components. Their
    // project statement owns collection timing and overdue presentation.
    if (array_key_exists('collection_mode', $invoice)
        && strtolower(trim((string)$invoice['collection_mode'])) !== 'direct') {
        return false;
    }

    $dueDate = trim((string)($invoice['due_date'] ?? ''));
    if ($dueDate === '') {
        return false;
    }

    $due = DateTimeImmutable::createFromFormat('!Y-m-d', substr($dueDate, 0, 10));
    if ($due === false || $due->format('Y-m-d') !== substr($dueDate, 0, 10)) {
        return false;
    }

    $today ??= new DateTimeImmutable('today');
    return $due < $today->setTime(0, 0, 0);
}

function invoice_is_draft(array $invoice): bool
{
    return strtolower((string)($invoice['status'] ?? '')) === 'draft';
}

function invoice_public_base_url(array $appConfig): string
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
function invoice_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache;
    $cache ??= new WeakMap();
    $key = $table . ':' . $column;
    if (isset($cache[$pdo][$key])) {
        return true;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
            $found = in_array($column, array_column($columns, 'name'), true);
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
                 LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            $found = $stmt->fetchColumn() !== false;
        }
        // A compatibility repair can add a previously absent column in this request.
        if ($found) {
            $known = $cache[$pdo] ?? [];
            $known[$key] = true;
            $cache[$pdo] = $known;
        }
        return $found;
    } catch (Throwable $e) {
        return false;
    }
}

function invoice_ensure_payments_schema(PDO $pdo): void
{
    static $done;
    $done ??= new WeakMap();
    if (isset($done[$pdo])) {
        return;
    }
    // The compatibility DDL below is MySQL-specific. SQLite fixtures already own
    // their schema, and must never receive MySQL ALTER/INDEX statements.
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }
    $columns = [
        'project_invoice_payment_id' => 'BIGINT NULL AFTER invoice_id',
        'contract_id' => 'INT NULL AFTER project_invoice_payment_id',
        'organization_id' => 'INT NULL AFTER contract_id',
        'refunded_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount',
        'disputed_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER refunded_amount',
        'reference_number' => 'VARCHAR(255) NULL AFTER payment_date',
        'notes' => 'TEXT NULL AFTER reference_number',
        'status' => "VARCHAR(32) NOT NULL DEFAULT 'succeeded'",
        'processor_provider' => 'VARCHAR(50) NULL AFTER payment_method',
        'processor_payment_id' => 'VARCHAR(255) NULL AFTER processor_provider',
        'processor_transaction_id' => 'BIGINT NULL AFTER processor_payment_id',
        'processor_gross_amount' => 'DECIMAL(12,2) NULL AFTER processor_transaction_id',
        'processor_fee_amount' => 'DECIMAL(12,2) NULL AFTER processor_gross_amount',
        'processor_net_amount' => 'DECIMAL(12,2) NULL AFTER processor_fee_amount',
        'processor_fee_policy' => "VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_net_amount",
        'processor_fee_source' => "VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_fee_policy",
    ];
    $metadata = $pdo->query(
        "SELECT COLUMN_NAME,IS_NULLABLE,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,COLUMN_DEFAULT
         FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $actual = array_column($metadata, null, 'COLUMN_NAME');
    if (!isset($actual['client_id'], $actual['payment_method'])) {
        throw new RuntimeException('Payments schema is unavailable; apply database migrations first.');
    }
    $ddl = [];
    if ($actual['client_id']['IS_NULLABLE'] !== 'YES') {
        $ddl[] = 'ALTER TABLE payments MODIFY COLUMN client_id INT NULL';
    }
    $method = $actual['payment_method'];
    if ($method['DATA_TYPE'] !== 'varchar' || (int)$method['CHARACTER_MAXIMUM_LENGTH'] < 50
        || $method['IS_NULLABLE'] !== 'NO' || $method['COLUMN_DEFAULT'] !== 'cash') {
        $width = max(50, $method['DATA_TYPE'] === 'varchar' ? (int)$method['CHARACTER_MAXIMUM_LENGTH'] : 50);
        $ddl[] = "ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR({$width}) NOT NULL DEFAULT 'cash'";
    }
    foreach ($columns as $column => $definition) {
        if (!isset($actual[$column])) {
            $ddl[] = "ALTER TABLE payments ADD COLUMN {$column} {$definition}";
        }
    }
    $indexRows = $pdo->query(
        "SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.statistics
         WHERE table_schema=DATABASE() AND table_name='payments' ORDER BY INDEX_NAME,SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_ASSOC);
    $indexes = [];
    foreach ($indexRows as $row) {
        $indexes[$row['INDEX_NAME']][] = [(int)$row['NON_UNIQUE'], $row['COLUMN_NAME']];
    }
    $requiredIndexes = [
        'uq_payments_processor_payment' => [[0, 'processor_provider'], [0, 'processor_payment_id']],
        'idx_payments_processor_transaction' => [[1, 'processor_transaction_id']],
    ];
    foreach ($requiredIndexes as $name => $shape) {
        if (isset($indexes[$name])) {
            if ($indexes[$name] !== $shape) {
                throw new RuntimeException('Payments index schema is incompatible; apply database migrations first.');
            }
        } else {
            $ddl[] = ($shape[0][0] === 0 ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . $name
                . ' ON payments (' . implode(', ', array_column($shape, 1)) . ')';
        }
    }
    if ($ddl && $pdo->inTransaction()) {
        throw new LogicException('Payments schema repair must finish before starting a document transaction.');
    }
    foreach ($ddl as $statement) {
        $pdo->exec($statement);
    }
    $done[$pdo] = true;
}

function invoice_expire_active_checkout(PDO $pdo, string $table, int $id, array $appConfig): void
{
    if (!in_array($table, ['invoices', 'project_invoices'], true)) {
        throw new InvalidArgumentException('Unsupported checkout record type.');
    }
    $stmt = $pdo->prepare("SELECT stripe_session_id,stripe_checkout_expires_at FROM {$table} WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $sessionId = trim((string)($row['stripe_session_id'] ?? ''));
    $expiresAt = !empty($row['stripe_checkout_expires_at']) ? strtotime((string)$row['stripe_checkout_expires_at']) : 0;
    if ($sessionId !== '' && $expiresAt > time()) {
        $stripe = StripeService::fromAppConfig($appConfig);
        if (!$stripe) {
            throw new RuntimeException('An active Stripe Checkout session must expire before recording another payment.');
        }
        try {
            $stripe->expireCheckoutSession($sessionId);
        } catch (Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'expired')) {
                throw new RuntimeException('Could not close the active Stripe Checkout session. Try again shortly.', 0, $e);
            }
        }
    }
    $pdo->prepare("UPDATE {$table} SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?")
        ->execute([$id]);
}

function invoice_effective_paid_total(PDO $pdo, int $invoiceId): float
{
    invoice_ensure_payments_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(GREATEST(amount - refunded_amount - disputed_amount, 0)), 0)
        FROM payments
        WHERE invoice_id = ? AND status = "succeeded"
    ');
    $stmt->execute([$invoiceId]);
    return (float)$stmt->fetchColumn();
}

function invoice_status_for_balance(float $total, float $paid): array
{
    $paid = max(0.0, $paid);
    $balance = max(0.0, $total - $paid);
    if (($total > 0 || $paid > 0) && $balance <= 0.005) {
        return ['paid', $paid, 0.0];
    }
    if ($paid > 0.005) {
        return ['partial', $paid, $balance];
    }
    return ['unpaid', 0.0, max(0.0, $total)];
}

function invoice_refresh_payment_totals(PDO $pdo, int $invoiceId, bool $revokePaidPublicLinks = true): array
{
    $totalStmt = $pdo->prepare('SELECT total,status FROM invoices WHERE id = ?');
    $totalStmt->execute([$invoiceId]);
    $invoice = $totalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (float)($invoice['total'] ?? 0);
    $paid = invoice_effective_paid_total($pdo, $invoiceId);

    // Payment audit cleanup must never resurrect a void invoice. Void records
    // intentionally have no collectible balance, even when their historical
    // payment rows are later reversed or reconciled.
    if (strtolower((string)($invoice['status'] ?? '')) === 'void') {
        $pdo->prepare('UPDATE invoices SET amount_paid=?,balance_due=0,paid_at=NULL WHERE id=?')
            ->execute([max(0.0, $paid), $invoiceId]);
        return [
            'status' => 'void',
            'amount_paid' => max(0.0, $paid),
            'balance_due' => 0.0,
            'total' => $total,
        ];
    }

    [$status, $storedPaid, $balanceDue] = invoice_status_for_balance($total, $paid);

    $paidAtSql = $status === 'paid'
        ? ', paid_at = COALESCE(paid_at, NOW())'
        : ', paid_at = NULL';
    $creditDue = max(0.0, $storedPaid - $total);
    $pdo->prepare("UPDATE invoices SET status = ?, amount_paid = ?, balance_due = ?, credit_due = ?{$paidAtSql} WHERE id = ?")
        ->execute([$status, $storedPaid, $balanceDue, $creditDue, $invoiceId]);

    if ($revokePaidPublicLinks && !in_array($status, ['unpaid', 'partial'], true)) {
        pa_public_link_terminalize($pdo, 'invoice', $invoiceId, $status);
    }
    if ($status === 'paid') {
        require_once __DIR__ . '/workforce_compensation.php';
        workforce_release_invoice_paid($pdo, $invoiceId, (int)($_SESSION['user']['id'] ?? 0));
    }

    return [
        'status' => $status,
        'amount_paid' => $storedPaid,
        'balance_due' => $balanceDue,
        'total' => $total,
        'credit_due' => $creditDue,
    ];
}

/**
 * Complete the linked contract only when the paid invoice belongs to a
 * one-time contract. Long-term and on-demand contracts represent an ongoing
 * service relationship; paying one invoice must never end that relationship.
 */
function invoice_complete_linked_contract_if_eligible(PDO $pdo, int $invoiceId): bool
{
    $stmt = $pdo->prepare('
        SELECT c.id, c.contract_type, c.status
        FROM invoices i
        JOIN contracts c ON c.id = i.contract_id
        WHERE i.id = ?
        LIMIT 1
    ');
    $stmt->execute([$invoiceId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract || strtolower((string)$contract['contract_type']) !== 'regular') {
        return false;
    }

    $update = $pdo->prepare('
        UPDATE contracts
        SET status = "completed", completed_at = COALESCE(completed_at, NOW())
        WHERE id = ?
          AND contract_type = "regular"
          AND status NOT IN ("completed", "cancelled", "denied", "void")
    ');
    $update->execute([(int)$contract['id']]);
    return $update->rowCount() === 1;
}

function invoice_record_locked_payment(
    PDO $pdo,
    int $invoiceId,
    float $amount,
    string $method,
    ?string $reference,
    ?string $notes,
    array $options = []
): array {
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    invoice_ensure_payments_schema($pdo);
    $activeOrgId = $options['organization_id'] ?? null;
    $completeContractWhenPaid = (bool)($options['complete_contract_when_paid'] ?? false);
    $allowUnfinalized = (bool)($options['allow_unfinalized'] ?? false);
    $source = substr((string)($options['source'] ?? 'manual'), 0, 50);
    $paymentDate = trim((string)($options['payment_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        $paymentDate = date('Y-m-d');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $where = 'id = ?';
        $params = [$invoiceId];
        if ($activeOrgId !== null) {
            $where .= ' AND organization_id = ?';
            $params[] = (int)$activeOrgId;
        }

        $lock = $pdo->prepare("
            SELECT id, client_id, contract_id, organization_id, status, finalized_at, total, collection_mode
            FROM invoices
            WHERE {$where}
            FOR UPDATE
        ");
        $lock->execute($params);
        $invoice = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $invoiceStatus = strtolower((string)$invoice['status']);
        require_once __DIR__ . '/autopay_collection.php';
        pa_autopay_assert_payment_available($pdo, $invoiceId);
        if (in_array($invoiceStatus, ['void', 'cancelled'], true)) {
            throw new RuntimeException('Payments cannot be recorded against a void or cancelled invoice.');
        }
        if (!$allowUnfinalized && (empty($invoice['finalized_at']) || $invoiceStatus === 'draft')) {
            throw new RuntimeException('Finalize the invoice before recording payment.');
        }
        if (($invoice['collection_mode'] ?? 'direct') !== 'direct') {
            throw new RuntimeException('Record payment from the project invoice so it is allocated correctly.');
        }

        $paid = invoice_effective_paid_total($pdo, $invoiceId);
        $outstanding = max(0.0, (float)$invoice['total'] - $paid);
        if ($amount > $outstanding + 0.005) {
            throw new RuntimeException('Payment cannot exceed the outstanding balance.');
        }

        $insert = $pdo->prepare('
            INSERT INTO payments
                (client_id, invoice_id, contract_id, organization_id, amount, payment_method, reference_number, notes, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "succeeded", ?)
        ');
        $insert->execute([
            (int)$invoice['client_id'],
            $invoiceId,
            !empty($invoice['contract_id']) ? (int)$invoice['contract_id'] : null,
            !empty($invoice['organization_id']) ? (int)$invoice['organization_id'] : null,
            $amount,
            $method !== '' ? $method : 'cash',
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null,
            $paymentDate,
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        $totals = invoice_refresh_payment_totals($pdo, $invoiceId);
        if ($completeContractWhenPaid && $totals['status'] === 'paid' && !empty($invoice['contract_id'])) {
            invoice_complete_linked_contract_if_eligible($pdo, $invoiceId);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        $totals['payment_id'] = $paymentId;
        $totals['source'] = $source;
        return $totals;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Finalize an invoice exactly once. Finalization is the boundary that permits
 * client delivery, public links, reminders, and one-time Checkout payments.
 */
function invoice_finalize(PDO $pdo, int $invoiceId, array $appConfig, string $source, ?int $userId = null): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $status = strtolower((string)$invoice['status']);
        if (in_array($status, ['paid', 'void', 'cancelled'], true)) {
            throw new RuntimeException('Invoice status does not allow finalization.');
        }

        if ($status === 'draft') {
            require_once __DIR__ . '/autopay_staff.php';
            $autoPayChoice = in_array($source, ['manual_create','manual_finalize','on_demand_send','contract_complete'], true)
                ? pa_autopay_staff_finalization($pdo, $invoice, $appConfig)
                : 'none';
            $netDays = max(0, (int)($appConfig['net_terms_days'] ?? 30));
            $dueDate = !empty($invoice['due_date'])
                ? $invoice['due_date']
                : date('Y-m-d', strtotime('+' . $netDays . ' days'));

            $update = $pdo->prepare(
                'UPDATE invoices
                 SET status="unpaid", due_date=?, finalized_at=COALESCE(finalized_at,NOW()),
                     finalized_by=COALESCE(finalized_by,?), finalization_source=COALESCE(finalization_source,?)
                 WHERE id=? AND status="draft"'
            );
            $update->execute([$dueDate, $userId, substr($source, 0, 50), $invoiceId]);
            if ($autoPayChoice === 'collect') {
                if (pa_autopay_enqueue_invoice($pdo, $invoiceId, $appConfig, $userId, $source) === null) {
                    throw new DomainException('AutoPay eligibility changed. Review the invoice again or finalize with AutoPay held.');
                }
            } elseif ($autoPayChoice === 'hold') {
                require_once __DIR__ . '/audit.php';
                audit_log($pdo, 'invoice.autopay_held', 'invoice', $invoiceId, ['source'=>$source], $userId);
            }
        }

        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        require_once __DIR__ . '/job_work_materialization.php';
        catalog_plan_direct_invoice($pdo, $invoiceId, (int)($userId ?? 0));

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $invoice;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Finalize a general-recipient invoice and create its manual public link as a
 * single database operation. This never queues email because there is no
 * externally safe recipient for this presentation mode.
 *
 * @return array{invoice:array<string,mixed>,token:string,existing:bool}
 */
function invoice_finalize_and_create_general_recipient_link(
    PDO $pdo,
    int $invoiceId,
    array $appConfig,
    ?int $userId = null
): array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $invoice = invoice_finalize($pdo, $invoiceId, $appConfig, 'general_recipient_manual_link', $userId);
        if (!pa_general_recipient_invoice_is_eligible($invoice)
            || !invoice_is_collectible_status((string)($invoice['status'] ?? ''))
            || empty($invoice['finalized_at'])) {
            throw new RuntimeException('Only a finalized one-off general-recipient invoice can create this public link.');
        }

        $existing = $pdo->prepare(
            'SELECT token FROM public_links
             WHERE document_type="invoice" AND document_id=? AND revoked=0
               AND expire_when_paid=1 AND expires_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $existing->execute([$invoiceId]);
        $token = (string)($existing->fetchColumn() ?: '');
        $wasExisting = $token !== '';
        if (!$wasExisting) {
            // invoice_finalize() holds the invoice row FOR UPDATE for this
            // outer transaction, serializing concurrent link creation. The
            // token's unique key remains a separate entropy collision guard.
            $token = bin2hex(random_bytes(32));
            $insert = $pdo->prepare(
                'INSERT INTO public_links (document_type,document_id,token,expires_at,expire_when_paid,revoked,redirect)
                 VALUES ("invoice",?,?,NULL,1,0,NULL)'
            );
            $insert->execute([$invoiceId, $token]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['invoice' => $invoice, 'token' => $token, 'existing' => $wasExisting];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Deliver a finalized invoice once. This intentionally creates only a normal
 * one-time payment link; it never saves or reuses a payment method.
 */
function invoice_send_finalized(PDO $pdo, int $invoiceId, array $appConfig, string $notificationType = 'on_finalize'): bool
{
    $invoice = invoice_notification_invoice($pdo, $invoiceId);
    if (!$invoice || !invoice_is_collectible_status((string)$invoice['status'])
        || empty($invoice['finalized_at']) || ($invoice['collection_mode'] ?? 'direct') !== 'direct') {
        return false;
    }

    // General-recipient invoices have no externally safe recipient. They may only
    // be shared through a deliberately created public link, never queued for email.
    if (pa_invoice_is_general_recipient($invoice)) {
        return false;
    }

    $deliveryKey = $notificationType === 'on_generate' ? 'generated' : 'finalized';
    invoice_notification_enqueue_resolved($pdo, $invoiceId, $notificationType, $deliveryKey);
    invoice_notification_process($pdo, $appConfig, null, null, 10, $invoiceId);
    $resolution = pa_billing_resolve($pdo, 'invoice', $invoiceId);
    if (!$resolution['recipients']) return false;
    $check = $pdo->prepare(
        'SELECT delivery_status FROM invoice_notifications
         WHERE invoice_id=? AND notification_type=? AND delivery_key=? AND recipient_key=? LIMIT 1'
    );
    foreach ($resolution['recipients'] as $recipient) {
        $check->execute([$invoiceId, $notificationType, $deliveryKey, invoice_notification_recipient_key((string)$recipient['email'])]);
        if ($check->fetchColumn() !== 'sent') return false;
    }
    return true;
}
function invoice_reopen_draft(PDO $pdo, int $invoiceId): void
{
    $pdo->beginTransaction();
    try {
        require_once __DIR__ . '/autopay_collection.php';
        pa_autopay_lock_parent_for_invoice_mutation($pdo, $invoiceId);
        $stmt = $pdo->prepare('SELECT status,stripe_session_id,stripe_checkout_expires_at FROM invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = strtolower((string)($invoice['status'] ?? ''));
        require_once __DIR__ . '/autopay_collection.php';
        pa_autopay_assert_payment_available($pdo, $invoiceId);
        if (!in_array($status, ['sent', 'unpaid', 'overdue'], true)) {
            throw new RuntimeException('Only an unpaid finalized invoice can be reopened.');
        }

        $payment = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id=? AND status IN ("succeeded","pending")');
        $payment->execute([$invoiceId]);
        if ((int)$payment->fetchColumn() > 0) {
            throw new RuntimeException('This invoice has payment activity and cannot be reopened.');
        }

        $intent = $pdo->prepare('SELECT COUNT(*) FROM payment_intents WHERE invoice_id=? AND status IN ("pending","processing","requires_action")');
        $intent->execute([$invoiceId]);
        if ((int)$intent->fetchColumn() > 0) {
            throw new RuntimeException('This invoice has an active payment attempt and cannot be reopened.');
        }

        if (!empty($invoice['stripe_session_id'])
            && !empty($invoice['stripe_checkout_expires_at'])
            && strtotime((string)$invoice['stripe_checkout_expires_at']) > time()) {
            throw new RuntimeException('This invoice has an active Stripe Checkout session and cannot be reopened yet.');
        }

        $pdo->prepare('UPDATE public_links SET revoked=1 WHERE document_type="invoice" AND document_id=? AND revoked=0')
            ->execute([$invoiceId]);
        $pdo->prepare('UPDATE invoices SET status="draft", finalized_at=NULL, finalized_by=NULL, finalization_source=NULL, sent_at=NULL, stripe_session_id=NULL, stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$invoiceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Void an unpaid invoice without deleting its accounting history.
 *
 * @return array{previous_status:string,reason:string}
 */
function invoice_void(PDO $pdo, int $invoiceId, array $appConfig, string $reason, ?int $userId = null): array
{
    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
    if ($reason === '') {
        throw new RuntimeException('A reason is required to void an invoice.');
    }
    if (mb_strlen($reason) > 500) {
        throw new RuntimeException('The void reason must be 500 characters or fewer.');
    }

    invoice_ensure_payments_schema($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT id,status,collection_mode,stripe_session_id,stripe_checkout_expires_at FROM invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $previousStatus = strtolower((string)$invoice['status']);
        require_once __DIR__ . '/autopay_collection.php';
        pa_autopay_assert_payment_available($pdo, $invoiceId);
        if (!in_array($previousStatus, ['draft', 'sent', 'unpaid', 'overdue'], true)) {
            if (in_array($previousStatus, ['paid', 'partial'], true)) {
                throw new RuntimeException('Paid or partially paid invoices cannot be voided. Refund or credit the payment instead.');
            }
            throw new RuntimeException('This invoice status cannot be voided.');
        }

        $projectParent = $pdo->prepare('SELECT pi.id,pi.doc_number,pi.status FROM project_invoice_items pii JOIN project_invoices pi ON pi.id=pii.project_invoice_id WHERE pii.invoice_id=? LIMIT 1');
        $projectParent->execute([$invoiceId]);
        $parent = $projectParent->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($parent) {
            $parentNumber = (int)($parent['doc_number'] ?? $parent['id']);
            throw new RuntimeException('This invoice is already included in project invoice PI-' . $parentNumber . ' and cannot be voided individually.');
        }

        $payment = $pdo->prepare('
            SELECT COUNT(*)
            FROM payments
            WHERE invoice_id=?
              AND (
                  status="pending"
                  OR (
                      status="succeeded"
                      AND GREATEST(
                          COALESCE(amount,0)-COALESCE(refunded_amount,0)-COALESCE(disputed_amount,0),
                          0
                      ) > 0.005
                  )
              )
        ');
        $payment->execute([$invoiceId]);
        if ((int)$payment->fetchColumn() > 0) {
            throw new RuntimeException('This invoice still has a pending or active payment balance. Refund, reverse, or reallocate it before voiding.');
        }

        $intent = $pdo->prepare('SELECT COUNT(*) FROM payment_intents WHERE invoice_id=? AND status IN ("pending","processing","requires_action")');
        $intent->execute([$invoiceId]);
        if ((int)$intent->fetchColumn() > 0) {
            throw new RuntimeException('This invoice has an active payment attempt and cannot be voided yet.');
        }

        invoice_expire_active_checkout($pdo, 'invoices', $invoiceId, $appConfig);

        $pdo->prepare(
            'UPDATE invoices
             SET status="void", balance_due=0, voided_at=NOW(), voided_by=?, void_reason=?, void_previous_status=?
             WHERE id=?'
        )->execute([$userId, $reason, $previousStatus, $invoiceId]);

        $pdo->prepare('UPDATE time_entries SET billed=0,invoice_item_id=NULL,invoice_id=NULL WHERE invoice_id=?')
            ->execute([$invoiceId]);
        $pdo->prepare('UPDATE mileage_charge_allocations SET billed=0,invoice_item_id=NULL,invoice_id=NULL WHERE invoice_id=?')
            ->execute([$invoiceId]);
        $pdo->prepare('UPDATE mileage_logs m SET billed=0,invoice_item_id=NULL,invoice_id=NULL WHERE EXISTS (SELECT 1 FROM mileage_charge_allocations a WHERE a.mileage_log_id=m.id AND a.invoice_id IS NULL)')
            ->execute();
        // A corrected invoice may already have links terminalized as paid.
        // Refresh those terminal redirects so the retained history is
        // consistently identified as void without re-enabling any link.
        pa_public_link_terminalize($pdo, 'invoice', $invoiceId, 'void', true);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['previous_status' => $previousStatus, 'reason' => $reason];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Restore a void invoice while leaving every old public/payment link revoked.
 *
 * @return array{restored_status:string,reason:string}
 */
function invoice_reenable_void(PDO $pdo, int $invoiceId): array
{
    invoice_ensure_payments_schema($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT status,total,void_reason,void_previous_status FROM invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }
        if (strtolower((string)$invoice['status']) !== 'void') {
            throw new RuntimeException('Only void invoices can be re-enabled.');
        }

        $payment = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id=? AND status IN ("succeeded","pending")');
        $payment->execute([$invoiceId]);
        if ((int)$payment->fetchColumn() > 0) {
            throw new RuntimeException('This void invoice has payment activity and cannot be re-enabled automatically.');
        }

        $previousStatus = strtolower((string)($invoice['void_previous_status'] ?? ''));
        $restoredStatus = $previousStatus === 'draft' ? 'draft' : 'unpaid';
        $balanceDue = $restoredStatus === 'draft' ? 0.0 : max(0.0, (float)$invoice['total']);
        $reason = trim((string)($invoice['void_reason'] ?? ''));

        $pdo->prepare(
            'UPDATE invoices
             SET status=?, amount_paid=0, balance_due=?, paid_at=NULL,
                 voided_at=NULL, voided_by=NULL, void_reason=NULL, void_previous_status=NULL,
                 stripe_session_id=NULL, stripe_checkout_expires_at=NULL
             WHERE id=?'
        )->execute([$restoredStatus, $balanceDue, $invoiceId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['restored_status' => $restoredStatus, 'reason' => $reason];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
