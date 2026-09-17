<?php
require_once __DIR__ . '/invoice_numbers.php';
// src/utils/recurring_billing.php
// Idempotent helper for generating a single long-term recurring invoice.
require_once __DIR__ . '/recurring_services.php';
require_once __DIR__ . '/invoice_lifecycle.php';
require_once __DIR__ . '/project_billing.php';
require_once __DIR__ . '/invoice_notifications.php';
require_once __DIR__ . '/document_pricing_adjustments.php';
require_once __DIR__ . '/document_organization.php';
require_once __DIR__ . '/../services/DocumentRevisionService.php';

function pa_recurring_money_to_minor(string $amount): int
{
    return pricing_money_to_minor($amount);
}
function pa_recurring_minor_to_money(int $minor): string
{
    if($minor<0)throw new DomainException('Recurring money value cannot be negative.');
    return pricing_minor_to_money($minor);
}
function pa_recurring_fixed_installment_minor(string $total,string $depositPaid,string $totalInvoiced,int $invoiceCount,int $generated): int
{
    $count=max(1,$invoiceCount);$slots=$count-$generated;
    $remaining=max(0,pa_recurring_money_to_minor($total)-pa_recurring_money_to_minor($depositPaid)-pa_recurring_money_to_minor($totalInvoiced));
    if($generated<0||$slots<=0)return 0;
    return intdiv($remaining,$slots)+(($remaining%$slots)>0?1:0);
}

function recurring_invoice_send_on_generate_if_enabled(PDO $pdo, ?int $invoiceId, array $appConfig): bool
{
    if ($invoiceId === null || empty($appConfig['invoice_auto_email_on_generate'])) {
        return false;
    }

    $configuredSender = $appConfig['_email_sender'] ?? null;
    $sender = is_callable($configuredSender) ? $configuredSender : null;
    $stats = invoice_notification_process($pdo, $appConfig, $sender, null, 10, $invoiceId);
    if ($stats['sent'] > 0) {
        return true;
    }
    $sent = $pdo->prepare(
        'SELECT 1 FROM invoice_notifications
         WHERE invoice_id=? AND notification_type="on_generate"
           AND delivery_status="sent" AND sent_at IS NOT NULL LIMIT 1'
    );
    $sent->execute([$invoiceId]);
    return $sent->fetchColumn() !== false;
}

function generate_recurring_invoice(PDO $pdo, array $contract, array $appConfig): ?int {
    $logPrefix = '[generate_recurring_invoice]';
    $today = date('Y-m-d');

    try {
        $pdo->beginTransaction();

        // Idempotency check: the contract must still be active and due today or earlier.
        $checkStmt = $pdo->prepare('
            SELECT * FROM contracts
            WHERE id = ?
            AND status = ?
            AND contract_type = "long_term"
            AND next_invoice_date IS NOT NULL
            AND next_invoice_date <= ?
            FOR UPDATE
        ');
        $checkStmt->execute([$contract['id'], 'active', $today]);
        $freshContract = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$freshContract) {
            $pdo->rollBack();
            return null;
        }

        $contract = $freshContract;
        $contractId = $contract['id'];
        $clientId = $contract['client_id'];
        $projectCode = $contract['project_code'];
        $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
        $organizationId = pa_document_effective_organization_id($pdo, 'contract', (int)$contractId);
        $createdBy = !empty($contract['created_by']) ? (int)$contract['created_by'] : null;

        // Calculate invoice amount
        $subtotal = 0;
        $items = [];
        $dueServices = [];
        $usesServiceSchedules = false;

        if ($contract['pricing_type'] === 'per_invoice') {
            $usesServiceSchedules = pa_recurring_services_exist($pdo, (int)$contractId);
            if ($usesServiceSchedules) {
                $dueServices = pa_recurring_services_due($pdo, (int)$contractId, $today);
                if (!$dueServices) {
                    pa_recurring_service_sync_contract_next_date($pdo, (int)$contractId);
                    $pdo->commit();
                    return null;
                }
                foreach ($dueServices as $service) {
                    $subtotal += max(0.0, (float)$service['amount']);
                }
            } else {
                // Compatibility fallback for a database awaiting the service-schedule migration.
                $subtotal = (float)$contract['price_per_invoice'];
            }
        } elseif ($contract['pricing_type'] === 'fixed_total') {
            // Fixed total - divide total by invoice_count
            $invoiceCount = (int)($contract['invoice_count'] ?? 1);
            $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
            $subtotal = pa_recurring_minor_to_money(pa_recurring_fixed_installment_minor(
                (string)$contract['total'],(string)($contract['deposit_paid']??'0'),(string)($contract['total_invoiced']??'0'),$invoiceCount,$contractInvoicesGenerated
            ));

            // Load items for display (will be shown proportionally)
            $itemsQuery = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
            $itemsQuery->execute([$contractId]);
            $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        }

        // Apply discount and tax (already factored into subtotal for fixed_total)
        $discountType = $contract['discount_type'] ?? 'none';
        $discountValue = (float)($contract['discount_value'] ?? 0);
        $taxPercent = (float)$contract['tax_percent'];
        if($contract['pricing_type']==='fixed_total'){
            $discountType='none';$discountValue=0.0;$taxPercent=0.0;
        }

        // For fixed_total, discount and tax are already calculated in the contract total
        // For per_invoice, apply them per invoice
        if ($contract['pricing_type'] === 'per_invoice') {
            $discount = 0;
            if ($discountType === 'percent') {
                $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
            } elseif ($discountType === 'fixed') {
                $discount = $discountValue;
            }
            $taxable = max(0, $subtotal - $discount);
            $tax = max(0, $taxPercent) * $taxable / 100;
            $total = max(0, $taxable + $tax);
        } else {
            // fixed_total: subtotal already has discount/tax baked in
            $total = $subtotal;
        }

        // Check if we've reached the invoice limit (for fixed_total pricing)
        if ($contract['pricing_type'] === 'fixed_total') {
            $invoiceCount = (int)($contract['invoice_count'] ?? 1);
            $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);

            if ($contractInvoicesGenerated >= $invoiceCount) {
                // All invoices generated - mark as completed
                $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL, completed_at=COALESCE(completed_at,NOW()) WHERE id=? AND contract_type="long_term"')
                    ->execute(['completed', $contractId]);
                @error_log("$logPrefix Contract LTC-{$contract['doc_number']} all {$invoiceCount} invoices generated, marked as completed");
                $pdo->commit();
                return null;
            }
        }

        // Create invoice
        $documentDate = $today;
        $projectBillingContext = project_invoice_billing_context($pdo, $projectId, $appConfig, $documentDate, true);
        $paymentTermsDays = (int)$projectBillingContext['net_terms_days'];
        $dueDate = (string)$projectBillingContext['due_date'];

        $insertInvoice = $pdo->prepare('
            INSERT INTO invoices (
                contract_id, quote_id, client_id, project_id, job_id, service_location_id, project_code, organization_id, show_contact_on_document, created_by, invoice_type, document_date,
                discount_type, discount_value, tax_percent,
                subtotal, total, amount_paid, balance_due, status, due_date, payment_terms_days, due_date_source, finalized_at, finalization_source, created_at, collection_mode
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, "terms", NOW(), "recurring_schedule", NOW(), ?)
        ');

        $insertInvoice->execute([
            $contractId, // Link to long-term contract
            !empty($contract['quote_id']) ? (int)$contract['quote_id'] : null,
            $clientId,
            $projectId,
            !empty($contract['job_id']) ? (int)$contract['job_id'] : null,
            !empty($contract['service_location_id']) ? (int)$contract['service_location_id'] : null,
            $projectCode,
            $organizationId,
            (int)($contract['show_contact_on_document'] ?? 0),
            $createdBy,
            'long_term',
            $documentDate,
            $discountType,
            $discountValue,
            $taxPercent,
            $subtotal,
            $total,
            $total,
            'unpaid',
            $dueDate,
            $paymentTermsDays,
            $projectBillingContext['collection_mode']
        ]);

        $invoiceId = (int)$pdo->lastInsertId();

        // Assign doc number
        $docNumber = pa_next_invoice_doc_number($pdo, 'long_term');
        $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$docNumber, $invoiceId]);

        // Add invoice items
        if ($contract['pricing_type'] === 'per_invoice') {
            $itemInsert = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
            if ($usesServiceSchedules) {
                foreach ($dueServices as $service) {
                    $serviceName = trim((string)$service['name']) ?: 'Recurring service';
                    $serviceDescription = trim((string)($service['description'] ?? ''));
                    $interval = max(1, (int)$service['billing_interval_count']) . ' ' . ucfirst((string)$service['billing_interval_unit']);
                    if ((int)$service['billing_interval_count'] > 1) {
                        $interval .= 's';
                    }
                    $description = 'Billed every ' . strtolower($interval);
                    if ($serviceDescription !== '') {
                        $description .= ' — ' . $serviceDescription;
                    }
                    $amount = max(0.0, (float)$service['amount']);
                    $itemInsert->execute([$invoiceId, $serviceName, $description, 1, $amount, $amount]);
                }
            } else {
                $billingInterval = $contract['billing_interval_count'] . ' ' . ucfirst($contract['billing_interval_unit']);
                if ($contract['billing_interval_count'] > 1) $billingInterval .= 's';
                $description = 'Recurring service fee (' . strtolower($billingInterval) . ')';
                if (!empty($contract['scope'])) {
                    $description .= ' - ' . substr($contract['scope'], 0, 100);
                }
                $itemInsert->execute([$invoiceId, 'Recurring service', $description, 1, $subtotal, $subtotal]);
            }
        } elseif ($contract['pricing_type'] === 'fixed_total') {
            // The installment allocates the already-priced final contract total.
            // A single exact line avoids presenting pre-discount contract items as
            // though they reconcile to this post-tax installment amount.
            $invoiceCount = (int)($contract['invoice_count'] ?? 1);
            $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
            $invoiceNum = $contractInvoicesGenerated + 1;
            $description='Payment '.$invoiceNum.' of '.$invoiceCount.' under contract '.($contract['doc_number']??$contractId);
            $pdo->prepare('INSERT INTO invoice_items (invoice_id,item,description,quantity,unit_price,line_total,billing_unit,pricing_status) VALUES (?,"Contract installment",?,1,?,?,"each","standard")')
                ->execute([$invoiceId,$description,$subtotal,$subtotal]);
        }

        if($contract['pricing_type']==='per_invoice'){
            pricing_finalize_derived_document_revision(
                $pdo,$organizationId,'invoice',$invoiceId,$createdBy,(string)($appConfig['workforce_currency']??'USD'),
                'contract',(int)$contractId,pricing_contract_source_revision($contract)
            );
            $finalTotal=$pdo->prepare('SELECT total FROM invoices WHERE id=?');$finalTotal->execute([$invoiceId]);$totalMoney=(string)$finalTotal->fetchColumn();$total=(float)$totalMoney;
        }else{
            DocumentRevisionService::snapshotAndSave($pdo,'invoice',$invoiceId,$createdBy,false);
            $totalMoney=(string)$subtotal;
        }

        $newTotalInvoiced = pa_recurring_minor_to_money(pa_recurring_money_to_minor((string)($contract['total_invoiced']??'0'))+pa_recurring_money_to_minor($totalMoney));
        $newInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0) + 1;

        if ($usesServiceSchedules) {
            foreach ($dueServices as $service) {
                $serviceNextDate = pa_recurring_service_next_date(
                    (string)$service['next_invoice_date'],
                    (int)$service['billing_interval_count'],
                    (string)$service['billing_interval_unit'],
                    (string)$service['effective_from']
                );
                $serviceStatus = 'active';
                if (!empty($service['effective_until']) && $serviceNextDate > (string)$service['effective_until']) {
                    $serviceNextDate = null;
                    $serviceStatus = 'ended';
                }
                $pdo->prepare('UPDATE contract_recurring_services SET next_invoice_date=?,last_invoice_date=?,status=? WHERE id=? AND contract_id=?')
                    ->execute([$serviceNextDate, $today, $serviceStatus, (int)$service['id'], $contractId]);
            }
            pa_recurring_service_sync_contract_next_date($pdo, (int)$contractId);
            $pdo->prepare('UPDATE contracts SET last_invoice_date=?,total_invoiced=?,invoices_generated=? WHERE id=? AND contract_type="long_term"')
                ->execute([$today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);

            // Commit the durable delivery action with the invoice and service schedule.
            invoice_notification_enqueue_generated($pdo, $invoiceId, $appConfig);
            $pdo->commit();
            @error_log("$logPrefix Generated recurring-service invoice " . pa_invoice_label($docNumber, 'long_term') . " for contract LTC-{$contract['doc_number']} (\${$total})");
            return $invoiceId;
        }

        // Calculate next invoice date for legacy/fixed-total schedules.
        $currentDate = $contract['next_invoice_date'];
        $intervalCount = (int)$contract['billing_interval_count'];
        $intervalUnit = $contract['billing_interval_unit'];

        $nextDate = date('Y-m-d', strtotime($currentDate . ' +' . $intervalCount . ' ' . $intervalUnit));

        // Check if we should continue invoicing
        $shouldContinue = true;
        if (!empty($contract['end_date'])) {
            if ($nextDate > $contract['end_date']) {
                $shouldContinue = false;
                $nextDate = null;
            }
        }

        if ($shouldContinue) {
            $pdo->prepare('UPDATE contracts SET next_invoice_date=?, last_invoice_date=?, total_invoiced=?, invoices_generated=? WHERE id=? AND contract_type="long_term"')
                ->execute([$nextDate, $today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);
        } else {
            $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL, last_invoice_date=?, total_invoiced=?, invoices_generated=?, completed_at=COALESCE(completed_at,NOW()) WHERE id=? AND contract_type="long_term"')
                ->execute(['completed', $today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);
        }

        // The outbox row is committed atomically with the invoice and contract schedule.
        invoice_notification_enqueue_generated($pdo, $invoiceId, $appConfig);

        $pdo->commit();

        @error_log("$logPrefix Generated invoice " . pa_invoice_label($docNumber, 'long_term') . " for contract LTC-{$contract['doc_number']} (\${$total})");

        return $invoiceId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @error_log("$logPrefix Error processing contract {$contract['id']}: " . $e->getMessage());
        return null;
    }
}

function generate_recurring_proration_invoice(
    PDO $pdo,
    int $contractId,
    int $serviceId,
    float $subtotal,
    string $description,
    array $appConfig,
    ?string $generationKey=null
): ?int {
    if ($subtotal <= 0) {
        return null;
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('
            SELECT c.*,s.name AS service_name
            FROM contracts c
            JOIN contract_recurring_services s ON s.contract_id=c.id
            WHERE c.id=? AND s.id=? AND c.contract_type="long_term"
            FOR UPDATE
        ');
        $stmt->execute([$contractId, $serviceId]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contract) {
            throw new RuntimeException('Recurring service not found for proration.');
        }
        $organizationId = pa_document_effective_organization_id($pdo, 'contract', $contractId);
        if($generationKey!==null){$existing=$pdo->prepare('SELECT id FROM invoices WHERE contract_id=? AND generation_key=? LIMIT 1');$existing->execute([$contractId,$generationKey]);$existingId=(int)$existing->fetchColumn();if($existingId>0){if($ownsTransaction)$pdo->commit();return$existingId;}}

        $discount = 0.0;
        if (($contract['discount_type'] ?? 'none') === 'percent') {
            $discount = max(0, min(100, (float)$contract['discount_value'])) * $subtotal / 100;
        } elseif (($contract['discount_type'] ?? 'none') === 'fixed') {
            $discount = min($subtotal, max(0, (float)$contract['discount_value']));
        }
        $taxable = max(0, $subtotal - $discount);
        $tax = max(0, (float)$contract['tax_percent']) * $taxable / 100;
        $total = max(0, $taxable + $tax);
        $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
        $projectBillingContext = project_invoice_billing_context($pdo, $projectId, $appConfig, date('Y-m-d'), true);
        $dueDate = (string)$projectBillingContext['due_date'];

        $insert = $pdo->prepare('
            INSERT INTO invoices
                (contract_id,quote_id,client_id,project_id,job_id,service_location_id,project_code,organization_id,show_contact_on_document,created_by,invoice_type,
                 discount_type,discount_value,tax_percent,subtotal,total,balance_due,status,due_date,payment_terms_days,due_date_source,
                 finalized_at,finalization_source,generated_at,created_at,generation_key,collection_mode)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"terms",NOW(),"amendment_proration",NOW(),NOW(),?,?)
        ');
        $insert->execute([
            $contractId,
            !empty($contract['quote_id']) ? (int)$contract['quote_id'] : null,
            (int)$contract['client_id'],
            !empty($contract['project_id']) ? (int)$contract['project_id'] : null,
            !empty($contract['job_id']) ? (int)$contract['job_id'] : null,
            !empty($contract['service_location_id']) ? (int)$contract['service_location_id'] : null,
            $contract['project_code'] ?? null,
            $organizationId,
            (int)($contract['show_contact_on_document'] ?? 0),
            !empty($contract['created_by']) ? (int)$contract['created_by'] : null,
            'long_term',
            $contract['discount_type'] ?? 'none',
            (float)($contract['discount_value'] ?? 0),
            (float)($contract['tax_percent'] ?? 0),
            $subtotal,
            $total,
            $total,
            'unpaid',
            $dueDate,
            $projectBillingContext['net_terms_days'],
            $generationKey,
            $projectBillingContext['collection_mode'],
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
        $docNumber = pa_next_invoice_doc_number($pdo, 'long_term');
        $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$docNumber, $invoiceId]);

        $lineDescription = trim($description) !== '' ? trim($description) : 'Prorated recurring service charge';
        $pdo->prepare('INSERT INTO invoice_items (invoice_id,item,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)')
            ->execute([$invoiceId, (string)$contract['service_name'], $lineDescription, 1, $subtotal, $subtotal]);

        pricing_finalize_derived_document_revision(
            $pdo,$organizationId,'invoice',$invoiceId,
            !empty($contract['created_by'])?(int)$contract['created_by']:null,(string)($appConfig['workforce_currency']??'USD'),
            'contract',$contractId,pricing_contract_source_revision($contract)
        );
        $finalTotal=$pdo->prepare('SELECT total FROM invoices WHERE id=?');$finalTotal->execute([$invoiceId]);$totalMoney=(string)$finalTotal->fetchColumn();$total=(float)$totalMoney;

        $newTotalInvoiced=pricing_minor_to_money(pricing_money_to_minor((string)($contract['total_invoiced']??'0'))+pricing_money_to_minor($totalMoney));
        $pdo->prepare('UPDATE contracts SET total_invoiced=?,invoices_generated=invoices_generated+1,last_invoice_date=? WHERE id=?')
            ->execute([$newTotalInvoiced, date('Y-m-d'), $contractId]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $invoiceId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
