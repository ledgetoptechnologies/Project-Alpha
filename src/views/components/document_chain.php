<?php
// Quote → Contract → Invoices → Payments strip shown at the top of every
// document detail page, so the whole agreement can be followed in one click.
// Not rendered in PDFs or public views.

declare(strict_types=1);

require_once __DIR__ . '/../../utils/invoice_numbers.php';

function doc_chain_quote_label(array $q): string
{
    $prefix = match ($q['quote_type'] ?? null) { 'long_term' => 'LQ', 'on_demand' => 'ODQ', default => 'Q' };
    return $prefix . '-' . (int)(($q['doc_number'] ?? null) ?: $q['id']);
}

function doc_chain_contract_label(array $c): string
{
    $prefix = match ($c['contract_type'] ?? null) { 'long_term' => 'LTC', 'on_demand' => 'ODC', default => 'C' };
    return $prefix . '-' . (int)(($c['doc_number'] ?? null) ?: $c['id']);
}

/**
 * Find the documents linked to one quote, contract or invoice.
 * Returns ['current' => type, 'quote' => ?row, 'contract' => ?row, 'invoice' => ?row (the one being viewed),
 *          'invoices' => rows, 'paid' => float, 'payment_count' => int].
 */
function doc_chain_load(PDO $pdo, string $current, int $id): array
{
    $one = static function (string $sql, array $params) use ($pdo): ?array {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };
    $quoteSql = 'SELECT id, doc_number, quote_type, status, client_id FROM quotes WHERE id = ?';
    $contractCols = 'id, doc_number, contract_type, status, quote_id, client_id';

    $chain = ['current' => $current, 'quote' => null, 'contract' => null, 'invoice' => null, 'invoices' => [], 'paid' => 0.0, 'payment_count' => 0];
    if ($current === 'invoice') {
        $chain['invoice'] = $one('SELECT id, doc_number, invoice_type, status, total, contract_id, quote_id, client_id FROM invoices WHERE id = ?', [$id]);
        if (!$chain['invoice']) {
            return $chain;
        }
        if ($chain['invoice']['contract_id']) {
            $chain['contract'] = $one("SELECT {$contractCols} FROM contracts WHERE id = ?", [(int)$chain['invoice']['contract_id']]);
        }
        $quoteId = (int)($chain['invoice']['quote_id'] ?: ($chain['contract']['quote_id'] ?? 0));
        $chain['quote'] = $quoteId ? $one($quoteSql, [$quoteId]) : null;
    } elseif ($current === 'contract') {
        $chain['contract'] = $one("SELECT {$contractCols} FROM contracts WHERE id = ?", [$id]);
        if (!$chain['contract']) {
            return $chain;
        }
        $chain['quote'] = $chain['contract']['quote_id'] ? $one($quoteSql, [(int)$chain['contract']['quote_id']]) : null;
    } else {
        $chain['quote'] = $one($quoteSql, [$id]);
        if (!$chain['quote']) {
            return $chain;
        }
        $chain['contract'] = $one("SELECT {$contractCols} FROM contracts WHERE quote_id = ? ORDER BY id DESC LIMIT 1", [$id]);
    }

    // Invoices of the agreement: the contract's, else the quote's, else just the one being viewed.
    if ($chain['contract']) {
        $st = $pdo->prepare('SELECT id, doc_number, invoice_type, status, total FROM invoices WHERE contract_id = ? ORDER BY id');
        $st->execute([(int)$chain['contract']['id']]);
    } elseif ($chain['quote']) {
        $st = $pdo->prepare('SELECT id, doc_number, invoice_type, status, total FROM invoices WHERE quote_id = ? ORDER BY id');
        $st->execute([(int)$chain['quote']['id']]);
    } else {
        $st = $pdo->prepare('SELECT id, doc_number, invoice_type, status, total FROM invoices WHERE id = ?');
        $st->execute([$id]);
    }
    $chain['invoices'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $ids = array_map(static fn($r) => (int)$r['id'], $chain['invoices']);
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) paid
            FROM payments WHERE invoice_id IN ({$marks}) AND status = 'succeeded'");
        $st->execute($ids);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'paid' => 0];
        $chain['payment_count'] = (int)$p['n'];
        $chain['paid'] = (float)$p['paid'];
    }
    return $chain;
}

/** Markup for a loaded chain. Pure, so it can be tested without a database. */
function doc_chain_html(array $chain): string
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $status = static fn(?string $s) => $s ? ucfirst(str_replace('_', ' ', $s)) : '';
    $cells = [];

    $q = $chain['quote'];
    $cells[] = ['Quote', $q ? doc_chain_quote_label($q) : 'None', $q ? $status($q['status']) : 'Created directly',
        $q ? '/?page=quote/quote-details&id=' . (int)$q['id'] : null, $chain['current'] === 'quote'];

    $c = $chain['contract'];
    $contractPage = ($c['contract_type'] ?? '') === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
    $cells[] = ['Contract', $c ? doc_chain_contract_label($c) : 'Not yet', $c ? $status($c['status']) : ($q && $q['status'] === 'pending' ? 'After approval' : ''),
        $c ? '/?page=' . $contractPage . '&id=' . (int)$c['id'] : null, $chain['current'] === 'contract'];

    $invoices = $chain['invoices'];
    $total = array_sum(array_map(static fn($r) => in_array($r['status'], ['void', 'cancelled'], true) ? 0 : (float)$r['total'], $invoices));
    $paidCount = count(array_filter($invoices, static fn($r) => $r['status'] === 'paid'));
    if (count($invoices) === 1) {
        $inv = $invoices[0];
        $invoiceCell = [pa_invoice_label_from_row($inv), $status($inv['status']), '/?page=invoice/invoice-details&id=' . (int)$inv['id']];
    } elseif ($invoices) {
        $invoiceCell = [count($invoices) . ' invoices', $paidCount . ' of ' . count($invoices) . ' paid',
            $c ? '/?page=invoice/invoices-list&kind=' . match ($c['contract_type'] ?? null) { 'long_term' => 'recurring', 'on_demand' => 'as_needed', default => 'one_time' } . '&contract_id=' . (int)$c['id'] : null];
    } else {
        $invoiceCell = ['None yet', '', null];
    }
    $here = $chain['current'] === 'invoice';
    $label = $here && $chain['invoice'] ? pa_invoice_label_from_row($chain['invoice']) . (count($invoices) > 1 ? ' · ' . count($invoices) . ' total' : '') : $invoiceCell[0];
    $cells[] = ['Invoices', $label, $invoiceCell[1], $here ? null : $invoiceCell[2], $here];

    $paymentHref = count($invoices) === 1 ? '/?page=invoice/invoice-details&id=' . (int)$invoices[0]['id'] : null;
    $cells[] = ['Payments', $chain['payment_count'] ? '$' . number_format($chain['paid'], 2) : 'None yet',
        $total > 0 ? 'of $' . number_format($total, 2) . ($chain['payment_count'] > 1 ? ' · ' . $chain['payment_count'] . ' payments' : '') : '',
        $chain['payment_count'] ? $paymentHref : null, false];

    $html = '<nav class="doc-chain no-print" aria-label="Document chain">';
    foreach ($cells as [$lab, $val, $sub, $href, $isHere]) {
        $inner = '<span class="doc-chain__lab">' . $e($lab) . '</span><span class="doc-chain__val">' . $e($val) . '</span>'
            . ($sub !== '' ? '<span class="doc-chain__sub">' . $e($sub) . '</span>' : '');
        $cls = 'doc-chain__step' . ($isHere ? ' is-here' : '') . ($val === 'None yet' || $val === 'Not yet' || $val === 'None' ? ' is-empty' : '');
        $html .= ($href && !$isHere)
            ? '<a class="' . $cls . '" href="' . $e($href) . '">' . $inner . '</a>'
            : '<div class="' . $cls . '"' . ($isHere ? ' aria-current="page"' : '') . '>' . $inner . '</div>';
    }
    return $html . '</nav>';
}

/** Render the strip for a detail page; silent outside the staff UI or on error. */
function doc_chain_render(PDO $pdo, string $current, int $id): void
{
    if (defined('PDF_MODE') || defined('PUBLIC_VIEW') || $id <= 0) {
        return;
    }
    try {
        echo doc_chain_html(doc_chain_load($pdo, $current, $id));
    } catch (Throwable $error) {
        error_log('Document chain failed: ' . $error->getMessage());
    }
}
