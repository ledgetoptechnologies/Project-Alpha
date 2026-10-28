<?php
declare(strict_types=1);
require_once __DIR__ . '/stripe_payment_notifications.php';

require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/../services/PaymentProcessorImportService.php';
require_once __DIR__ . '/stripe_financial_events.php';
require_once __DIR__ . '/stripe_payment_accounting.php';
require_once __DIR__ . '/stripe_invoice_payment_amounts.php';
require_once __DIR__ . '/stripe_invoice_payment_state.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/invoice_lifecycle.php';
require_once __DIR__ . '/notifications.php';

require_once __DIR__ . '/autopay_reconciliation.php';

function stripe_reconcile_payment_intents(
    PDO $pdo,
    StripeService $stripe,
    array $appConfig,
    int $since,
    ?int $until = null,
    ?int $maxIntents = 1000,
    bool $forceStandaloneImport = false
): array {
    $recovery = pa_autopay_reconcile_known_collections($pdo, $stripe, $appConfig);
    $paymentIntents = $stripe->listPaymentIntentsBetween($since, $until, $maxIntents);
    $result = [
        'checked' => count($paymentIntents) + $recovery['checked'],
        'reconciled' => $recovery['reconciled'],
        'imported' => 0,
        'duplicates' => 0,
        'skipped' => 0,
        'errors' => $recovery['errors'],
    ];

    $importConfig = $appConfig;
    if ($forceStandaloneImport) {
        $importConfig['processor_import_standalone_income'] = 1;
    }

    foreach ($paymentIntents as $pi) {
        try {
            $collection = pa_autopay_reconciliation_collection($pdo, $pi);
            if ($collection) {
                if (pa_autopay_reconcile_collection($pdo, $stripe, $appConfig, $collection, (string)($pi['id'] ?? ''))) $result['reconciled']++;
                else $result['skipped']++;
                continue;
            }
            if (($pi['status'] ?? '') !== 'succeeded') {
                $result['skipped']++;
                continue;
            }

            if (!empty($pi['metadata']['pa_project_invoice_id']) || !empty($pi['metadata']['project_invoice_id'])) {
                require_once __DIR__ . '/project_invoice_billing.php';
                if (project_invoice_record_stripe_payment($pdo, $pi)) {
                    $result['reconciled']++;
                } else {
                    $result['errors']++;
                }
                continue;
            }

            $invoiceId = $pi['metadata']['pa_invoice_id'] ?? $pi['metadata']['invoice_id'] ?? null;
            if (!$invoiceId) {
                $standalone = PaymentProcessorImportService::importStandalone(
                    $pdo,
                    $importConfig,
                    $stripe->normalizePaymentIntentForImport($pi)
                );
                if (($standalone['status'] ?? '') === 'imported') {
                    $result['imported']++;
                    $result['reconciled']++;
                } elseif (($standalone['status'] ?? '') === 'duplicate') {
                    $result['duplicates']++;
                    $result['reconciled']++;
                } elseif (($standalone['status'] ?? '') === 'failed') {
                    $result['errors']++;
                } else {
                    $result['skipped']++;
                }
                continue;
            }

            $invoiceId = (int)$invoiceId;
            $piId = (string)($pi['id'] ?? '');
            $amounts = stripe_invoice_payment_amounts($pi, 'payment_intent');
            $paymentAmount = $amounts['payment_amount'];
            $surchargeAmount = $amounts['surcharge_amount'];
            $processorTx = $stripe->normalizePaymentIntentForImport($pi);

            // Use the same lock order as success and failure webhooks.
            $pdo->beginTransaction();
            $invStmt = $pdo->prepare('SELECT id, total, status, client_id FROM invoices WHERE id = ? FOR UPDATE');
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                $pdo->rollBack();
                @error_log("[stripe_reconciliation] Invoice {$invoiceId} not found for PI {$piId}");
                $result['skipped']++;
                continue;
            }

            $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
            $existsStmt->execute([$piId]);
            $existingPaymentId = (int)($existsStmt->fetchColumn() ?: 0);
            $transitionPaymentId = 0;
            if ($existingPaymentId > 0) {
                $existing = stripe_invoice_existing_payment($pdo, $existingPaymentId, $invoiceId, $piId);
                if ($existing['transition_required']) {
                    $transitionPaymentId = $existingPaymentId;
                } else {
                    stripe_update_payment_processor_fields($pdo, $existingPaymentId, $processorTx, $appConfig);
                    $pdo->commit();
                    if ($existing) {
                        stripe_invoice_deliver_payment_emails(
                            $pdo, $appConfig, $invoiceId, $existingPaymentId,
                            (float)$existing['amount'], (string)$existing['invoice_status'], false
                        );
                    }
                    $result['duplicates']++;
                    $result['reconciled']++;
                    continue;
                }
            }

            if (($invoice['status'] ?? '') === 'paid' && $transitionPaymentId === 0) {
                $pdo->rollBack();
                $result['skipped']++;
                continue;
            }

            if ($transitionPaymentId > 0) {
                stripe_invoice_promote_failed_payment(
                    $pdo, $transitionPaymentId, $invoiceId, $piId, null,
                    $paymentAmount, $surchargeAmount, $processorTx, $appConfig
                );
                $paymentId = $transitionPaymentId;
            } else {
                $processorFields = stripe_processor_fields_from_normalized($processorTx, $appConfig, $paymentAmount, $surchargeAmount);
                $pdo->prepare('
                    INSERT INTO payments
                        (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_payment_intent_id, auto_pay_attempt,
                         processor_provider, processor_payment_id, processor_gross_amount, processor_fee_amount, processor_net_amount,
                         processor_fee_policy, processor_fee_source, status, payment_date, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())
                ')->execute([
                    (int)$invoice['client_id'], $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $piId, 0,
                    $processorFields['processor_provider'], $processorFields['processor_payment_id'] ?: null,
                    $processorFields['processor_gross_amount'], $processorFields['processor_fee_amount'], $processorFields['processor_net_amount'],
                    $processorFields['processor_fee_policy'], $processorFields['processor_fee_source'], 'succeeded'
                ]);
                $paymentId = (int)$pdo->lastInsertId();
            }
            stripe_link_pending_financial_events($pdo, $paymentId, $piId);

            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
            $sumStmt->execute([$invoiceId]);
            $totalPaid = (float)$sumStmt->fetchColumn();

            $invoiceTotal = (float)$invoice['total'];
            $newStatus = ($totalPaid >= $invoiceTotal) ? 'paid' : 'partial';

            try {
                $pdo->prepare('UPDATE invoices SET status=?,amount_paid=?,balance_due=GREATEST(total-?,0),stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
                    ->execute([$newStatus, $totalPaid, $totalPaid, $invoiceId]);
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$newStatus, $invoiceId]);
            }

            if ($newStatus === 'paid') {
                pa_public_link_terminalize($pdo, 'invoice', $invoiceId, 'paid');

                invoice_complete_linked_contract_if_eligible($pdo, $invoiceId);
            }

            $pdo->commit();
            $result['imported']++;
            $result['reconciled']++;
            stripe_invoice_deliver_payment_emails(
                $pdo, $appConfig, $invoiceId, $paymentId,
                $paymentAmount, $newStatus, true
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['errors']++;
            @error_log('[stripe_reconciliation] Error processing PI ' . (string)($pi['id'] ?? 'unknown') . ': ' . $e->getMessage());
        }
    }

    $charges = $stripe->listChargesBetween($since, $until, $maxIntents);
    $result['checked'] += count($charges);

    foreach ($charges as $charge) {
        try {
            if (empty($charge['paid']) || !empty($charge['failure_code'])) {
                $result['skipped']++;
                continue;
            }

            $transaction = $stripe->normalizeChargeForImport($charge);
            $standalone = PaymentProcessorImportService::importStandalone($pdo, $importConfig, $transaction);
            if (($standalone['status'] ?? '') === 'imported') {
                $result['imported']++;
                $result['reconciled']++;
            } elseif (($standalone['status'] ?? '') === 'duplicate') {
                $result['duplicates']++;
                $result['reconciled']++;
            } elseif (($standalone['status'] ?? '') === 'failed') {
                $result['errors']++;
            } else {
                $result['skipped']++;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['errors']++;
            @error_log('[stripe_reconciliation] Error processing charge ' . (string)($charge['id'] ?? 'unknown') . ': ' . $e->getMessage());
        }
    }

    return $result;
}

function stripe_reconcile_summary(array $result): string {
    return sprintf(
        '%d checked; %d reconciled; %d imported; %d duplicates; %d skipped; %d errors',
        (int)($result['checked'] ?? 0),
        (int)($result['reconciled'] ?? 0),
        (int)($result['imported'] ?? 0),
        (int)($result['duplicates'] ?? 0),
        (int)($result['skipped'] ?? 0),
        (int)($result['errors'] ?? 0)
    );
}
