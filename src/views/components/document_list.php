<?php
// Shared pieces for the Quotes, Contracts and Invoices lists: one page per
// family, with kind tabs (one-time / recurring / as-needed), status chips,
// a search box, extra filters and a pager. Family pages supply the query and
// the row markup; this file owns the layout and the GET contract.

declare(strict_types=1);

const DOC_LIST_KINDS = [
    'all' => 'All',
    'one_time' => 'One-time',
    'recurring' => 'Recurring',
    'as_needed' => 'As-needed',
];

/** Selected kind from ?kind=, defaulting to all. */
function doc_list_kind(): string
{
    $kind = (string)($_GET['kind'] ?? 'all');
    return isset(DOC_LIST_KINDS[$kind]) ? $kind : 'all';
}

/**
 * SQL condition for a kind on a *_type column. One-time documents are stored
 * as NULL or 'regular'; recurring as 'long_term'; as-needed as 'on_demand'.
 */
function doc_list_kind_condition(string $kind, string $column): ?string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) {
        throw new InvalidArgumentException('Invalid type column.');
    }
    return match ($kind) {
        'one_time' => "({$column} IS NULL OR {$column} = 'regular')",
        'recurring' => "{$column} = 'long_term'",
        'as_needed' => "{$column} = 'on_demand'",
        default => null,
    };
}

/** Kind key for a stored *_type value. */
function doc_list_kind_of(?string $type): string
{
    return match ($type) {
        'long_term' => 'recurring',
        'on_demand' => 'as_needed',
        default => 'one_time',
    };
}

function doc_list_kind_label(?string $type): string
{
    return DOC_LIST_KINDS[doc_list_kind_of($type)];
}

/** Current list URL with some GET keys replaced (null removes a key). */
function doc_list_url(string $page, array $changes = []): string
{
    $qs = $_GET;
    unset($qs['page']);
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($qs[$key]);
        } else {
            $qs[$key] = $value;
        }
    }
    return '/?' . http_build_query(['page' => $page] + $qs);
}

/** Common GET filters: q (client, organization, doc #, project), start/end on created_at, client_id. */
function doc_list_common_filters(string $alias, array &$where, array &$params, bool $hasDocNumber = true): void
{
    $clientId = (int)($_GET['client_id'] ?? 0);
    if ($clientId > 0) {
        $where[] = "{$alias}.client_id = ?";
        $params[] = $clientId;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $parts = ['c.name LIKE ?', 'o.name LIKE ?', "{$alias}.project_code LIKE ?"];
        array_push($params, $like, $like, $q . '%');
        $digits = preg_replace('/\D+/', '', $q);
        if ($hasDocNumber && $digits !== '' && strlen($digits) <= 9) {
            $parts[] = "{$alias}.doc_number = ?";
            $params[] = (int)$digits;
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    foreach (['start' => '>=', 'end' => '<='] as $key => $op) {
        $date = (string)($_GET[$key] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = "{$alias}.created_at {$op} ?";
            $params[] = $date . ($key === 'start' ? ' 00:00:00' : ' 23:59:59');
        }
    }
}

/** Page size and offset from ?per_page= and ?p=. */
function doc_list_paging(): array
{
    $per = (int)($_GET['per_page'] ?? 50);
    if (!in_array($per, [50, 100], true)) {
        $per = 50;
    }
    $pageN = max(1, (int)($_GET['p'] ?? 1));
    return [$per, $pageN, ($pageN - 1) * $per];
}

function doc_list_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Header, flash messages, kind tabs, search, status chips and extra filters.
 *
 * $opts: title, page, new_url (or null), statuses [value => label], status (current),
 *        messages [[tone, text]], extra_filters (HTML for the "More filters" panel).
 */
function doc_list_render_top(array $opts): void
{
    $page = $opts['page'];
    $kind = doc_list_kind();
    $status = (string)($opts['status'] ?? 'all');
    ?>
    <div class="page-head">
      <h2><?php echo doc_list_e($opts['title']); ?></h2>
      <?php if (!empty($opts['new_url'])): ?>
        <a class="btn btn-primary" href="<?php echo doc_list_e($opts['new_url']); ?>"><?php echo doc_list_e($opts['new_label'] ?? '+ New'); ?></a>
      <?php endif; ?>
    </div>
    <?php foreach ($opts['messages'] ?? [] as [$tone, $text]): ?>
      <div class="doc-flash doc-flash--<?php echo doc_list_e($tone); ?>" role="status"><?php echo doc_list_e($text); ?></div>
    <?php endforeach; ?>
    <nav class="doc-tabs" aria-label="Kind">
      <?php foreach (DOC_LIST_KINDS as $key => $label): ?>
        <a href="<?php echo doc_list_e(doc_list_url($page, ['kind' => $key === 'all' ? null : $key, 'status' => null, 'p' => null])); ?>"
           class="<?php echo $kind === $key ? 'active' : ''; ?>"<?php echo $kind === $key ? ' aria-current="page"' : ''; ?>><?php echo doc_list_e($label); ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="doc-filters" method="get" action="/">
      <input type="hidden" name="page" value="<?php echo doc_list_e($page); ?>">
      <?php foreach ($_GET as $key => $value): ?>
        <?php if (in_array($key, ['page', 'q', 'p', 'start', 'end', 'min', 'max'], true) || !is_scalar($value)) continue; ?>
        <input type="hidden" name="<?php echo doc_list_e((string)$key); ?>" value="<?php echo doc_list_e((string)$value); ?>">
      <?php endforeach; ?>
      <input type="search" name="q" value="<?php echo doc_list_e((string)($_GET['q'] ?? '')); ?>" placeholder="Search client, project or number" aria-label="Search">
      <?php foreach ($opts['statuses'] as $value => $label): ?>
        <a class="doc-chip <?php echo $status === (string)$value ? 'active' : ''; ?>"
           href="<?php echo doc_list_e(doc_list_url($page, ['status' => $value === 'all' ? null : $value, 'p' => null])); ?>"><?php echo doc_list_e($label); ?></a>
      <?php endforeach; ?>
      <details class="doc-more"<?php echo (!empty($_GET['start']) || !empty($_GET['end']) || !empty($_GET['min']) || !empty($_GET['max'])) ? ' open' : ''; ?>>
        <summary>More filters</summary>
        <div class="filter-form">
          <div><label>From<input type="date" name="start" value="<?php echo doc_list_e((string)($_GET['start'] ?? '')); ?>"></label></div>
          <div><label>To<input type="date" name="end" value="<?php echo doc_list_e((string)($_GET['end'] ?? '')); ?>"></label></div>
          <div><label>Min ($)<input type="number" step="0.01" name="min" value="<?php echo doc_list_e((string)($_GET['min'] ?? '')); ?>"></label></div>
          <div><label>Max ($)<input type="number" step="0.01" name="max" value="<?php echo doc_list_e((string)($_GET['max'] ?? '')); ?>"></label></div>
          <div class="filter-actions"><button class="btn btn-sm" type="submit">Apply</button>
            <a class="btn btn-sm btn-muted" href="<?php echo doc_list_e(doc_list_url($page, ['q' => null, 'start' => null, 'end' => null, 'min' => null, 'max' => null, 'client_id' => null, 'contract_id' => null, 'p' => null])); ?>">Clear</a></div>
        </div>
      </details>
    </form>
    <?php
}

/** Min/max on an amount expression from ?min= and ?max=. */
function doc_list_amount_filter(string $expr, array &$where, array &$params): void
{
    foreach (['min' => '>=', 'max' => '<='] as $key => $op) {
        $raw = trim((string)($_GET[$key] ?? ''));
        if ($raw !== '' && is_numeric($raw)) {
            $where[] = "{$expr} {$op} ?";
            $params[] = (float)$raw;
        }
    }
}

function doc_list_render_pager(string $page, int $total, int $per, int $pageN): void
{
    $last = max(1, (int)ceil($total / $per));
    ?>
    <div class="doc-pager">
      <span class="text-muted"><?php echo number_format($total); ?> <?php echo $total === 1 ? 'result' : 'results'; ?></span>
      <div class="doc-actions">
        <?php if ($pageN > 1): ?><a class="btn btn-sm" href="<?php echo doc_list_e(doc_list_url($page, ['p' => $pageN - 1])); ?>">Prev</a><?php endif; ?>
        <span class="text-muted" style="padding:6px 4px">Page <?php echo $pageN; ?> / <?php echo $last; ?></span>
        <?php if ($pageN < $last): ?><a class="btn btn-sm" href="<?php echo doc_list_e(doc_list_url($page, ['p' => $pageN + 1])); ?>">Next</a><?php endif; ?>
        <a class="btn btn-sm btn-muted" href="<?php echo doc_list_e(doc_list_url($page, ['per_page' => $per === 50 ? 100 : 50, 'p' => null])); ?>"><?php echo $per === 50 ? 'Show 100' : 'Show 50'; ?></a>
      </div>
    </div>
    <?php
}

/** Customer cell: organization (or client) with the contact and project underneath. */
function doc_list_customer_cell(array $r): string
{
    $html = doc_list_e((string)(($r['organization_name'] ?? '') ?: ($r['client_name'] ?? '')));
    $sub = [];
    if (!empty($r['organization_name']) && !empty($r['client_name'])) {
        $sub[] = '<a href="/?page=client/clients-list&amp;selected_client_id=' . (int)$r['client_id'] . '">' . doc_list_e((string)$r['client_name']) . '</a>';
    }
    if (!empty($r['project_code'])) {
        $sub[] = doc_list_e((string)$r['project_code']);
    }
    if ($sub) {
        $html .= '<span class="doc-sub">' . implode(' · ', $sub) . '</span>';
    }
    return $html;
}

function doc_list_status_pill(string $status, ?string $label = null): string
{
    $key = preg_replace('/[^a-z_]/', '', strtolower($status));
    return '<span class="status-pill status-pill--' . $key . '">' . doc_list_e($label ?? ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

/** A POST button form with csrf; $fields are extra hidden inputs. */
function doc_list_post_button(string $page, string $label, array $fields, ?string $confirm = null, string $class = 'btn btn-sm'): string
{
    $html = '<form method="post" action="/?page=' . doc_list_e($page) . '"'
        . ($confirm !== null ? ' onsubmit="return confirm(' . doc_list_e(json_encode($confirm)) . ')"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . doc_list_e(csrf_token()) . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . doc_list_e((string)$name) . '" value="' . doc_list_e((string)$value) . '">';
    }
    return $html . '<button type="submit" class="' . doc_list_e($class) . '">' . doc_list_e($label) . '</button></form>';
}

/** Email form shared by all three families. */
function doc_list_email_button(string $family, int $id, array $extra = []): string
{
    return doc_list_post_button($family . '/email-send', 'Email', ['type' => $family, 'id' => $id, 'redirect_to' => (string)($_SERVER['REQUEST_URI'] ?? '')] + $extra);
}
