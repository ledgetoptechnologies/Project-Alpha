<?php

declare(strict_types=1);

/**
 * Workforce is shelved while the client/document integration is completed.
 * Keep its implementation and historical records, but do not execute its
 * experimental workflows. Re-enabling requires a reviewed code release, not
 * an environment variable or an old saved setting.
 */
function pa_workforce_enabled(): bool
{
    return false;
}

function pa_workforce_settings_tabs(): array
{
    return ['business-units', 'business-units-divisions', 'work-types', 'assignments', 'pay-periods'];
}

function pa_workforce_page_shelved(string $page, string $tab = ''): bool
{
    if (pa_workforce_enabled()) {
        return false;
    }
    $page = strtolower($page);
    $tab = strtolower($tab);
    if ($page === 'settings' && in_array($tab, pa_workforce_settings_tabs(), true)) {
        return true;
    }
    foreach (['workforce', 'time-tracking', 'employee-pay'] as $prefix) {
        if ($page === $prefix || str_starts_with($page, $prefix . '/')) {
            return true;
        }
    }
    return in_array($page, [
        'api/workforce-v1', 'settings/workforce-catalog-handler',
        'project/project-work-handler', 'financial/mileage-unbilled',
    ], true);
}

function pa_workforce_require_available(string $page, string $tab = ''): void
{
    if (!pa_workforce_page_shelved($page, $tab)) {
        return;
    }
    http_response_code(404);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Workforce is temporarily unavailable.');
}

/** Reject stale import forms before database/configuration bootstrap. */
function pa_workforce_require_no_invoice_imports(array $post): void
{
    if (pa_workforce_enabled()) return;
    $selected = static function ($value) use (&$selected): bool {
        if (is_array($value)) {
            foreach ($value as $entry) if ($selected($entry)) return true;
            return false;
        }
        return $value !== null && $value !== false && trim((string)$value) !== '' && trim((string)$value) !== '0';
    };
    foreach (['time_entry_ids', 'time_entry_id', 'mileage_allocation_ids'] as $field) {
        if (!$selected($post[$field] ?? null)) continue;
        http_response_code(409);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'code' => 'workforce_imports_paused', 'message' => 'Tracked time and allocated mileage imports are temporarily unavailable.']));
    }
}
