<?php
declare(strict_types=1);

function pa_contract_billing_start_mode(array $contract): string
{
    $mode = (string)($contract['billing_start_mode'] ?? 'on_upload');
    return in_array($mode, ['on_upload', 'manual'], true) ? $mode : 'on_upload';
}

function pa_long_term_starts_billing_on_upload(array $contract): bool
{
    return (string)($contract['contract_type'] ?? '') === 'long_term'
        && pa_contract_billing_start_mode($contract) === 'on_upload';
}

/** Caller locks the contract first; the service cursor includes any recorded coverage. */
function pa_long_term_billing_start_date(PDO $pdo, array $contract, ?string $today = null): string
{
    return pa_long_term_selected_billing_date($pdo, $contract, $today) ?? ($today ?? date('Y-m-d'));
}

/** Uploading a file alone must not materialize an implicit billing date. */
function pa_long_term_selected_billing_date(PDO $pdo, array $contract, ?string $today = null): ?string
{
    if (!$pdo->inTransaction()) throw new LogicException('Billing activation requires a transaction.');
    $today ??= date('Y-m-d');
    $baseDate = null;
    if (($contract['pricing_type'] ?? '') === 'per_invoice') {
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT next_invoice_date FROM contract_recurring_services WHERE contract_id=? AND is_base=1 ORDER BY id LIMIT 1' . $suffix);
        $stmt->execute([(int)$contract['id']]);
        $baseDate = $stmt->fetchColumn() ?: null;
    }
    $date = (string)($baseDate ?: ($contract['next_invoice_date'] ?? ''));
    if ($date === '') {
        // An old service start date is not an instruction to invoice retroactively.
        $start = (string)($contract['start_date'] ?? '');
        if ($start <= $today) return null;
        $date = $start;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new DomainException('The selected billing start date is invalid.');
    return $date;
}

/** Historical payments are not evidence that the live schedule was activated. */
function pa_long_term_can_start_billing(PDO $pdo, array $contract): bool
{
    if (($contract['contract_type'] ?? '') !== 'long_term' || empty($contract['signed_pdf_path'])) return false;
    $status = (string)($contract['status'] ?? '');
    if (in_array($status, ['draft','pending'], true)) return true;
    if ($status !== 'active') return false;
    if (($contract['pricing_type'] ?? '') === 'per_invoice') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM contract_recurring_services WHERE contract_id=? AND approval_status='approved' AND status='active' AND next_invoice_date IS NOT NULL");
        $stmt->execute([(int)$contract['id']]);
        if ((int)$stmt->fetchColumn() > 0) return false;
    } elseif (!empty($contract['next_invoice_date'])) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE contract_id=? AND COALESCE(finalization_source,'')<>'historical_recurring_payment'");
    $stmt->execute([(int)$contract['id']]);
    return (int)$stmt->fetchColumn() === 0;
}

/** Revalidate current authority and billing settings after the upload was stored. */
function pa_contract_lock_public_signing(PDO $pdo, int $contractId, string $token): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Public signing requires a transaction.');
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=?' . $suffix);
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract || ($contract['status'] ?? '') !== 'pending' || !empty($contract['signed_at']) || !empty($contract['signed_pdf_path'])) {
        throw new DomainException('This contract is already signed or no longer pending.');
    }
    $stmt = $pdo->prepare('SELECT document_type,document_id,expires_at,revoked FROM public_links WHERE token=?' . $suffix);
    $stmt->execute([$token]);
    $authority = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$authority || $authority['document_type'] !== 'contract' || (int)$authority['document_id'] !== $contractId
        || (int)$authority['revoked'] !== 0 || (!empty($authority['expires_at']) && strtotime((string)$authority['expires_at']) < time())) {
        throw new DomainException('This contract link is no longer active.');
    }
    return $contract;
}
