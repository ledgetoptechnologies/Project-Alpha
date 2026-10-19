<?php

declare(strict_types=1);

require_once __DIR__ . '/workforce_availability.php';

use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;

function catalog_plan_document_work(PDO $pdo, string $documentType, int $documentId, int $actorId): array
{
    if (!pa_workforce_enabled()) { return []; }
    $planner = new JobWorkPlanningService($pdo, new CompensationRuleService($pdo));
    return $planner->materializeDocument($documentType, $documentId, $actorId);
}

function catalog_plan_direct_contract(PDO $pdo, int $contractId, int $actorId): array
{
    if (!pa_workforce_enabled()) { return []; }
    $stmt = $pdo->prepare('SELECT quote_id,job_id FROM contracts WHERE id=? LIMIT 1');
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract || !empty($contract['quote_id']) || empty($contract['job_id'])) {
        return [];
    }
    return catalog_plan_document_work($pdo, 'contract', $contractId, $actorId);
}

function catalog_plan_direct_invoice(PDO $pdo, int $invoiceId, int $actorId): array
{
    if (!pa_workforce_enabled()) { return []; }
    $stmt = $pdo->prepare('SELECT quote_id,contract_id,job_id FROM invoices WHERE id=? LIMIT 1');
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice || !empty($invoice['quote_id']) || !empty($invoice['contract_id']) || empty($invoice['job_id'])) {
        return [];
    }
    return catalog_plan_document_work($pdo, 'invoice', $invoiceId, $actorId);
}
