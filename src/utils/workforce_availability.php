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
    // Existing invoice editors may still consume historical approved time.
    if ($page === 'time-tracking/unbilled') {
        return false;
    }
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
        'project/project-work-handler',
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
