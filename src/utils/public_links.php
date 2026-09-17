<?php

declare(strict_types=1);

const PA_PUBLIC_LINK_TERMINAL_STATUS_DAYS = 7;
require_once __DIR__ . '/general_recipient_invoices.php';

function pa_public_link_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE public_links ADD COLUMN redirect VARCHAR(500) NULL');
    } catch (Throwable $e) {
    }

    $done = true;
}

function pa_public_link_redirect_path(string $type, string $reason): string
{
    return '/?page=public-redirect&type=' . rawurlencode($type) . '&reason=' . rawurlencode($reason);
}

/**
 * Return the newest currently accessible link for a document without changing
 * any historical row. Revoked and expired links deliberately do not qualify.
 *
 * @return array{id:int,token:string,expires_at:?string,expire_when_paid:int}|null
 */
function pa_public_link_active(PDO $pdo, string $type, int $id): ?array
{
    if (!in_array($type, ['quote', 'contract', 'invoice', 'project_invoice'], true) || $id <= 0) {
        throw new InvalidArgumentException('Unsupported public-link document.');
    }
    $stmt = $pdo->prepare(
        'SELECT id,token,expires_at,expire_when_paid
         FROM public_links
         WHERE document_type=? AND document_id=? AND revoked=0
           AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)
         ORDER BY created_at DESC,id DESC
         LIMIT 1'
    );
    $stmt->execute([$type, $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || trim((string)($row['token'] ?? '')) === '') {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'token' => (string)$row['token'],
        'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
        'expire_when_paid' => (int)($row['expire_when_paid'] ?? 0),
    ];
}

/**
 * Reuse an active document link or create one when none is active. This helper
 * never updates, reactivates, revokes, or rotates an existing row.
 *
 * @return array{id:int,token:string,created:bool}
 */
function pa_public_link_reuse_or_create(
    PDO $pdo,
    string $type,
    int $id,
    ?string $expiresAt,
    bool $expireWhenPaid
): array {
    $tables = [
        'quote' => 'quotes',
        'contract' => 'contracts',
        'invoice' => 'invoices',
        'project_invoice' => 'project_invoices',
    ];
    if (!isset($tables[$type]) || $id <= 0) {
        throw new InvalidArgumentException('Unsupported public-link document.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lockSuffix = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $document = $pdo->prepare('SELECT id FROM ' . $tables[$type] . ' WHERE id=?' . $lockSuffix);
        $document->execute([$id]);
        if (!$document->fetchColumn()) {
            throw new DomainException('Public-link document not found.');
        }

        // Recheck only after locking the document. Concurrent email workers now
        // converge on one stable token instead of creating competing links.
        $active = pa_public_link_active($pdo, $type, $id);
        if ($active !== null) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['id' => $active['id'], 'token' => $active['token'], 'created' => false];
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO public_links
             (document_type,document_id,token,redirect,expires_at,expire_when_paid,revoked)
             VALUES (?,?,?,NULL,?,?,0)'
        );
        $stmt->execute([$type, $id, $token, $expiresAt, $expireWhenPaid ? 1 : 0]);
        $result = ['id' => (int)$pdo->lastInsertId(), 'token' => $token, 'created' => true];
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function pa_public_link_terminal_reason(PDO $pdo, string $type, int $id): ?string
{
    if ($id <= 0) {
        return null;
    }

    try {
        if ($type === 'quote') {
            $stmt = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
            $stmt->execute([$id]);
            $status = strtolower((string)($stmt->fetchColumn() ?: ''));
            return match ($status) {
                'approved' => 'approved',
                'rejected' => 'denied',
                default => null,
            };
        }

        if ($type === 'contract') {
            $stmt = $pdo->prepare('SELECT status, signed_pdf_path FROM contracts WHERE id=? LIMIT 1');
            $stmt->execute([$id]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$contract) {
                return null;
            }
            $status = strtolower((string)($contract['status'] ?? ''));
            if (!empty($contract['signed_pdf_path'])) {
                return 'signed';
            }
            return match ($status) {
                'active' => 'signed',
                'completed' => 'completed',
                'denied' => 'denied',
                'cancelled' => 'cancelled',
                'void' => 'void',
                default => null,
            };
        }

        if ($type === 'invoice' || $type === 'project_invoice') {
            $table = $type === 'project_invoice' ? 'project_invoices' : 'invoices';
            $stmt = $pdo->prepare("SELECT status FROM {$table} WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $status = strtolower((string)($stmt->fetchColumn() ?: ''));
            return match ($status) {
                'paid' => 'paid',
                'void' => 'void',
                'cancelled' => 'cancelled',
                'denied' => 'denied',
                default => null,
            };
        }
    } catch (Throwable $e) {
        @error_log('[public_links] terminal reason failed: ' . $e->getMessage());
    }

    return null;
}

function pa_public_link_terminalize(
    PDO $pdo,
    string $type,
    int $id,
    ?string $reason = null,
    bool $refreshPreviouslyTerminalLinks = false
): ?string
{
    if (!in_array($type, ['quote', 'contract', 'invoice', 'project_invoice'], true) || $id <= 0) {
        return null;
    }

    $reason = $reason ?: pa_public_link_terminal_reason($pdo, $type, $id);
    if ($reason === null) {
        return null;
    }

    if (!$pdo->inTransaction()) {
        pa_public_link_ensure_schema($pdo);
    }
    $redirect = pa_public_link_redirect_path($type, $reason);
    try {
        // A paid general-recipient invoice remains an active, non-payable receipt
        // for seven days. It must not redirect to a generic terminal page because
        // the caller deliberately needs the PDF/receipt after paying.
        if ($type === 'invoice' && $reason === 'paid') {
            $invoiceStmt = $pdo->prepare('SELECT recipient_presentation_mode,status,paid_at FROM invoices WHERE id=? LIMIT 1');
            $invoiceStmt->execute([$id]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (pa_invoice_is_general_recipient($invoice)) {
                if (!pa_general_recipient_public_receipt_window_open($invoice)) {
                    $expired = $pdo->prepare(
                        'UPDATE public_links
                         SET revoked=1, redirect=NULL, expire_when_paid=0, expires_at=NOW()
                         WHERE document_type="invoice" AND document_id=?'
                    );
                    $expired->execute([$id]);
                    return $reason;
                }
                $receipt = $pdo->prepare(
                    'UPDATE public_links
                     SET revoked=0, redirect=NULL, expire_when_paid=0,
                         expires_at=DATE_ADD(COALESCE(?, NOW()), INTERVAL ' . PA_PUBLIC_LINK_TERMINAL_STATUS_DAYS . ' DAY)
                     WHERE document_type="invoice" AND document_id=?
                       AND revoked=0'
                );
                // invoices.paid_at controls the grace period, not the time a
                // public page happens to be opened. Re-applying this exact
                // timestamp also corrects a stale first-payment expiry after
                // a refund followed by a later repayment without extending
                // the window on duplicate events or ordinary views.
                $receipt->execute([$invoice['paid_at'] ?? null, $id]);
                return $reason;
            }
        }
        $revokedClause = $refreshPreviouslyTerminalLinks ? '' : ' AND revoked = 0';
        $stmt = $pdo->prepare(
            'UPDATE public_links
             SET revoked = 1,
                 redirect = ?,
                 expires_at = DATE_ADD(NOW(), INTERVAL ' . PA_PUBLIC_LINK_TERMINAL_STATUS_DAYS . ' DAY),
                 expire_when_paid = 0
             WHERE document_type = ?
               AND document_id = ?' . $revokedClause
        );
        $stmt->execute([$redirect, $type, $id]);
    } catch (Throwable $e) {
        @error_log('[public_links] terminalize failed: ' . $e->getMessage());
    }

    return $reason;
}

/**
 * @return array{state:string,label:string,detail:string,color:string,background:string,border:string}
 */
function pa_public_link_status(PDO $pdo, string $type, int $id): array
{
    pa_public_link_terminalize($pdo, $type, $id);

    $fallback = [
        'state' => 'not_accessible',
        'label' => 'Not accessible',
        'detail' => 'No active public link is available.',
        'color' => '#374151',
        'background' => '#f3f4f6',
        'border' => '#d1d5db',
    ];

    try {
        pa_public_link_ensure_schema($pdo);
        $stmt = $pdo->prepare(
            'SELECT token, expires_at, expire_when_paid, revoked, redirect, created_at
             FROM public_links
             WHERE document_type = ? AND document_id = ?
             ORDER BY
               CASE
                 WHEN revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) THEN 0
                 WHEN revoked = 1 AND redirect IS NOT NULL AND redirect <> "" AND (expires_at IS NULL OR expires_at > NOW()) THEN 1
                 ELSE 2
               END,
               created_at DESC,
               id DESC
             LIMIT 1'
        );
        $stmt->execute([$type, $id]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            return $fallback;
        }

        $isGeneralRecipientInvoice = false;
        if ($type === 'invoice') {
            $invoiceMode = $pdo->prepare('SELECT recipient_presentation_mode FROM invoices WHERE id=?');
            $invoiceMode->execute([$id]);
            $isGeneralRecipientInvoice = pa_invoice_is_general_recipient([
                'recipient_presentation_mode' => $invoiceMode->fetchColumn() ?: 'named',
            ]);
        }

        $expiresAt = trim((string)($link['expires_at'] ?? ''));
        $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
        $isExpired = $expiresTs !== false && $expiresTs <= time();
        $isRedirected = (int)($link['revoked'] ?? 0) === 1 && trim((string)($link['redirect'] ?? '')) !== '';

        if ((int)($link['revoked'] ?? 0) === 0 && !$isExpired) {
            return [
                'state' => 'accessible',
                'label' => 'Accessible',
                'detail' => !empty($link['expire_when_paid'])
                    ? ($isGeneralRecipientInvoice
                        ? 'Recipient can pay through this link; after payment it remains a receipt for seven days.'
                        : 'Client can open this link until the invoice is paid in full.')
                    : ($expiresAt !== '' ? 'Client can open this link until ' . date('M j, Y g:i A', strtotime($expiresAt)) . '.' : 'Client can open this link.'),
                'color' => '#065f46',
                'background' => '#ecfdf5',
                'border' => '#a7f3d0',
            ];
        }

        if ($isRedirected && !$isExpired) {
            return [
                'state' => 'redirected',
                'label' => 'Redirected',
                'detail' => $expiresAt !== ''
                    ? 'Client sees a status message until ' . date('M j, Y g:i A', strtotime($expiresAt)) . '.'
                    : 'Client sees a status message.',
                'color' => '#1e40af',
                'background' => '#eff6ff',
                'border' => '#bfdbfe',
            ];
        }
    } catch (Throwable $e) {
        @error_log('[public_links] status failed: ' . $e->getMessage());
    }

    return $fallback;
}

function pa_public_link_status_badge_html(PDO $pdo, string $type, int $id): string
{
    $status = pa_public_link_status($pdo, $type, $id);
    return '<span style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;padding:3px 8px;border:1px solid '
        . htmlspecialchars($status['border'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ';border-radius:999px;background:'
        . htmlspecialchars($status['background'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ';color:'
        . htmlspecialchars($status['color'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ';font-weight:600" title="'
        . htmlspecialchars($status['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">Public Link: '
        . htmlspecialchars($status['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</span>';
}
