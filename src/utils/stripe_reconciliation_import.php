<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/../services/PaymentProcessorImportService.php';
require_once __DIR__ . '/stripe_financial_events.php';
require_once __DIR__ . '/stripe_payment_accounting.php';
require_once __DIR__ . '/stripe_invoice_payment_amounts.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/invoice_lifecycle.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/payment_receipts.php';

function stripe_reconcile_payment_intents(
    PDO $pdo,
    StripeService $stripe,
    array $appConfig,
    int $since,
    ?int $until = null,
    ?int $maxIntents = 1000,
    bool $forceStandaloneImport = false
): array {
    $paymentIntents = $stripe->listPaymentIntentsBetween($since, $until, $maxIntents);
    $result = [
        'checked' => count($paymentIntents),
        'reconciled' => 0,
        'imported' => 0,
        'duplicates' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    $importConfig = $appConfig;
    if ($forceStandaloneImport) {
        $importConfig['processor_import_standalone_income'] = 1;
    }

    foreach ($paymentIntents as $pi) {
        try {
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

            $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
            $existsStmt->execute([$piId]);
            $existingPaymentId = (int)($existsStmt->fetchColumn() ?: 0);
            if ($existingPaymentId > 0) {
                $deliveryState = $pdo->prepare(
                    'SELECT p.amount,i.status FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.id=?'
                );
                $deliveryState->execute([$existingPaymentId]);
                $existing = $deliveryState->fetch(PDO::FETCH_ASSOC);
                if (!$existing || !is_numeric($existing['amount'] ?? null)
                    || !is_finite((float)$existing['amount']) || (float)$existing['amount'] <= 0) {
                    throw new RuntimeException('Existing Stripe payment has no valid positive principal; manual review is required.');
                }
                stripe_update_payment_processor_fields($pdo, $existingPaymentId, $processorTx, $appConfig);
                if ($existing) {
                    payment_email_attempt_all(
                        static fn() => notify_admin_invoice_paid(
                            $pdo, $appConfig, $invoiceId, (float)$existing['amount'],
                            (string)$existing['status'], false, true, null, 'payment:' . $existingPaymentId
                        ),
                        static fn() => payment_receipt_issue(
                            $pdo, $existingPaymentId, $appConfig, true, null, true
                        )
                    );
                }
                $result['duplicates']++;
                $result['reconciled']++;
                continue;
            }

            $invStmt = $pdo->prepare('SELECT id, total, status, client_id FROM invoices WHERE id = ?');
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                @error_log("[stripe_reconciliation] Invoice {$invoiceId} not found for PI {$piId}");
                $result['skipped']++;
                continue;
            }

            if (($invoice['status'] ?? '') === 'paid') {
                $result['skipped']++;
                continue;
            }

            $pdo->beginTransaction();
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
            payment_email_attempt_all(
                static fn() => notify_admin_invoice_paid(
                    $pdo, $appConfig, $invoiceId, $paymentAmount, $newStatus,
                    true, true, null, 'payment:' . $paymentId
                ),
                static fn() => payment_receipt_issue(
                    $pdo, $paymentId, $appConfig, true, null, true
                )
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
