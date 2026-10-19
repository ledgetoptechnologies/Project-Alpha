<?php

declare(strict_types=1);

require_once __DIR__ . '/workforce_availability.php';

use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;

/**
 * Payment recording must remain successful even when optional workforce
 * planning is unavailable during an upgrade. Readiness prevents normal traffic
 * on stale schemas; this guard keeps webhook retries from duplicating payment.
 */
function workforce_release_invoice_paid(PDO $pdo, int $invoiceId, int $actorId = 0): array
{
    if (!pa_workforce_enabled() || $invoiceId <= 0) return [];
    try {
        return (new JobWorkPlanningService($pdo, new CompensationRuleService($pdo)))
            ->releaseInvoicePaid($invoiceId, $actorId);
    } catch (Throwable $error) {
        error_log('[workforce_compensation] Invoice-paid release failed for invoice ' . $invoiceId . ': ' . $error->getMessage());
        return [];
    }
}
