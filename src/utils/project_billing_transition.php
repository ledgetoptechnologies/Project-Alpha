<?php

require_once __DIR__ . '/project_invoice_billing.php';
require_once __DIR__ . '/invoice_lifecycle.php';
require_once __DIR__ . '/job_work_materialization.php';
require_once __DIR__ . '/audit.php';

/**
 * Change a project's future invoice collection mode while resolving any
 * unassigned monthly children as one atomic accounting transition.
 *
 * Existing project statements and their children are intentionally excluded:
 * project_invoice_items is the immutable ownership boundary for those rows.
 *
 * @return array<string,mixed>
 */
function project_billing_mode_transition(
    PDO $pdo,
    int $projectId,
    string $fromMode,
    string $toMode,
    ?string $strategy,
    string $deliveryAction,
    array $appConfig,
    ?int $actorId = null,
    ?string $transitionDate = null
): array {
    $fromMode = $fromMode === 'monthly' ? 'monthly' : 'per_invoice';
    $toMode = $toMode === 'monthly' ? 'monthly' : 'per_invoice';
    $strategy = in_array($strategy, ['convert_to_direct', 'final_project_statement'], true)
        ? $strategy
        : null;
    $deliveryAction = $deliveryAction === 'send_all' ? 'send_all' : 'review';
    $transitionDate = trim((string)$transitionDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transitionDate)) {
        $transitionDate = date('Y-m-d');
    }

    $result = [
        'changed' => $fromMode !== $toMode,
        'from_mode' => $fromMode,
        'to_mode' => $toMode,
        'strategy' => $strategy,
        'delivery_action' => $deliveryAction,
        'transition_date' => $transitionDate,
        'candidate_count' => 0,
        'affected_invoice_ids' => [],
        'candidate_balance' => 0.0,
        'converted_count' => 0,
        'finalized_count' => 0,
        'project_invoice_id' => null,
        'included_count' => 0,
        'preserved_link_count' => 0,
        'delivery_invoice_ids' => [],
        'notice_code' => 'unchanged',
        'message' => 'Invoice billing mode was unchanged.',
    ];
    $isPerInvoiceRecovery = $fromMode === 'per_invoice'
        && $toMode === 'per_invoice'
        && $strategy !== null;
    if ($fromMode === $toMode && !$isPerInvoiceRecovery) {
        return $result;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $lockSuffix = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id=?' . $lockSuffix);
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            throw new DomainException('Project not found.');
        }
        $storedMode = (string)($project['invoice_billing_period'] ?? 'per_invoice');
        // projects_update writes the requested value before invoking this helper;
        // standalone callers invoke it before that write. Both forms are valid,
        // but any third value indicates a stale transition request.
        if (!in_array($storedMode, [$fromMode, $toMode], true)) {
            throw new DomainException('Project billing mode changed before this request could be applied. Reload and try again.');
        }

        $candidates = project_billing_transition_candidates($pdo, $projectId, true);
        $result['candidate_count'] = count($candidates);
        $result['affected_invoice_ids'] = array_values(array_map(
            static fn(array $invoice): int => (int)$invoice['id'],
            $candidates
        ));
        $result['candidate_balance'] = array_sum(array_column($candidates, 'outstanding'));
        $result['preserved_link_count'] = project_billing_transition_existing_link_count($pdo, $projectId);

        if ($isPerInvoiceRecovery && !$candidates) {
            $result['notice_code'] = 'no_stranded_monthly';
            $result['message'] = 'No stranded monthly invoices remain. Per-invoice billing was left unchanged.';
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        }

        if ($toMode === 'per_invoice' && $candidates && ($fromMode === 'monthly' || $isPerInvoiceRecovery)) {
            if ($strategy === null) {
                throw new DomainException('Choose how to handle pending monthly invoices before switching to per-invoice billing.');
            }
            if ($deliveryAction === 'send_all') {
                if ($strategy === 'convert_to_direct') {
                    $invalidRecipients = array_filter($candidates, static function (array $invoice): bool {
                        return ($invoice['recipient_presentation_mode'] ?? 'named') === 'general'
                            || !filter_var(trim((string)($invoice['client_email'] ?? '')), FILTER_VALIDATE_EMAIL);
                    });
                    if ($invalidRecipients) {
                        throw new DomainException('Every pending invoice needs a saved client email before converting and sending all invoices.');
                    }
                } elseif (!project_invoice_has_saved_deliverable_recipient($pdo, $projectId)) {
                    throw new DomainException('Add at least one valid saved project invoice recipient before creating and sending the final statement.');
                }
            }

            $netDays = project_billing_transition_net_days($project, $appConfig);
            $transitionDueDate = date('Y-m-d', strtotime($transitionDate . ' +' . $netDays . ' days'));

            if ($strategy === 'convert_to_direct') {
                foreach ($candidates as $invoice) {
                    $status = (float)$invoice['paid'] > 0.005 ? 'partial' : 'unpaid';
                    $wasUnfinalized = empty($invoice['finalized_at']);
                    $update = $pdo->prepare(
                        "UPDATE invoices
                         SET collection_mode='direct',status=?,amount_paid=?,balance_due=?,due_date=?,
                             payment_terms_days=?,due_date_source='terms',
                             finalized_at=COALESCE(finalized_at,CURRENT_TIMESTAMP),
                             finalized_by=COALESCE(finalized_by,?),
                             finalization_source=COALESCE(finalization_source,'billing_mode_transition')
                         WHERE id=? AND collection_mode='project_aggregate'
                           AND NOT EXISTS (SELECT 1 FROM project_invoice_items WHERE invoice_id=invoices.id)"
                    );
                    $update->execute([
                        $status,
                        (float)$invoice['paid'],
                        (float)$invoice['outstanding'],
                        $transitionDueDate,
                        $netDays,
                        $actorId,
                        (int)$invoice['id'],
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('A pending monthly invoice changed during the billing transition.');
                    }
                    $result['converted_count']++;
                    if ($wasUnfinalized) {
                        $result['finalized_count']++;
                        catalog_plan_direct_invoice($pdo, (int)$invoice['id'], (int)($actorId ?? 0));
                    }
                    if ($deliveryAction === 'send_all') {
                        $result['delivery_invoice_ids'][] = (int)$invoice['id'];
                    }
                }
                $result['notice_code'] = 'converted_to_direct';
                $result['message'] = sprintf(
                    'Billing changed to per invoice. %d pending monthly invoice(s) were finalized for individual collection.',
                    $result['converted_count']
                );
            } else {
                $activeCheckoutIds = [];
                foreach ($candidates as $invoice) {
                    $sessionId = trim((string)($invoice['stripe_session_id'] ?? ''));
                    // A local timestamp is not authoritative: a checkout can
                    // complete just before expiry while its webhook is delayed.
                    // The Stripe expired/completed webhook clears this pointer.
                    if ($sessionId !== '') {
                        $activeCheckoutIds[] = (int)$invoice['id'];
                    }
                }
                if ($activeCheckoutIds) {
                    throw new DomainException(
                        'A pending monthly invoice still has an unresolved card checkout. Wait for Stripe to confirm it as paid or expired before creating the final Project Invoice.'
                    );
                }
                foreach ($candidates as $candidateIndex => $invoice) {
                    $status = (float)$invoice['paid'] > 0.005 ? 'partial' : 'unpaid';
                    $wasUnfinalized = empty($invoice['finalized_at']);
                    $update = $pdo->prepare(
                        "UPDATE invoices
                         SET status=?,amount_paid=?,balance_due=?,stripe_session_id=NULL,stripe_checkout_expires_at=NULL,
                             finalized_at=COALESCE(finalized_at,CURRENT_TIMESTAMP),
                             finalized_by=COALESCE(finalized_by,?),
                             finalization_source=COALESCE(finalization_source,'billing_mode_transition')
                         WHERE id=? AND collection_mode='project_aggregate'
                           AND NOT EXISTS (SELECT 1 FROM project_invoice_items WHERE invoice_id=invoices.id)"
                    );
                    $update->execute([
                        $status,
                        (float)$invoice['paid'],
                        (float)$invoice['outstanding'],
                        $actorId,
                        (int)$invoice['id'],
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('A pending monthly invoice changed during the billing transition.');
                    }
                    if ($wasUnfinalized) {
                        $result['finalized_count']++;
                        catalog_plan_direct_invoice($pdo, (int)$invoice['id'], (int)($actorId ?? 0));
                    }
                    $candidates[$candidateIndex]['status'] = $status;
                }

                $statement = project_billing_transition_create_statement(
                    $pdo,
                    $project,
                    $candidates,
                    $transitionDate,
                    $appConfig,
                    $actorId
                );
                $result['project_invoice_id'] = $statement['project_invoice_id'];
                $result['included_count'] = $statement['included_count'];
                $result['notice_code'] = 'final_statement_created';
                $result['message'] = sprintf(
                    'Billing changed to per invoice. A final project statement was created with %d invoice(s) totaling $%s.',
                    $statement['included_count'],
                    number_format((float)$statement['balance'], 2)
                );
            }
        } elseif ($toMode === 'per_invoice' && ($fromMode === 'monthly' || $isPerInvoiceRecovery)) {
            $result['notice_code'] = 'no_pending_monthly';
            $result['message'] = 'Billing changed to per invoice. No pending monthly invoices required conversion.';
        } else {
            $result['notice_code'] = 'monthly_enabled';
            $result['message'] = 'Billing changed to monthly. Existing direct invoices remain individually payable; new invoices use project statements.';
        }

        if ($storedMode !== $toMode) {
            $pdo->prepare('UPDATE projects SET invoice_billing_period=? WHERE id=?')->execute([$toMode, $projectId]);
        }

        audit_log($pdo, $isPerInvoiceRecovery
            ? 'project.billing_mode.recovered'
            : 'project.billing_mode.changed', 'project', $projectId, [
            'from' => $fromMode,
            'to' => $toMode,
            'strategy' => $result['strategy'],
            'delivery_action' => $deliveryAction,
            'transition_date' => $transitionDate,
            'candidate_count' => $result['candidate_count'],
            'candidate_balance' => round((float)$result['candidate_balance'], 2),
            'converted_count' => $result['converted_count'],
            'finalized_count' => $result['finalized_count'],
            'project_invoice_id' => $result['project_invoice_id'],
            'included_count' => $result['included_count'],
            'preserved_link_count' => $result['preserved_link_count'],
        ], $actorId);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function project_billing_transition_existing_link_count(PDO $pdo, int $projectId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM public_links pl
         WHERE (pl.document_type='invoice' AND EXISTS (
                   SELECT 1 FROM invoices i WHERE i.id=pl.document_id AND i.project_id=?
               ))
            OR (pl.document_type='project_invoice' AND EXISTS (
                   SELECT 1 FROM project_invoices pi WHERE pi.id=pl.document_id AND pi.project_id=?
               ))"
    );
    $stmt->execute([$projectId, $projectId]);
    return (int)$stmt->fetchColumn();
}

/** @return list<array<string,mixed>> */
function project_billing_transition_candidates(PDO $pdo, int $projectId, bool $lock = false): array
{
    $lockSuffix = $lock && (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
        "SELECT i.id,i.client_id,i.doc_number,i.status,i.total,i.due_date,i.document_date,i.fulfillment_date,i.created_at,
                i.finalized_at,
                i.recipient_presentation_mode,i.stripe_session_id,i.stripe_checkout_expires_at,
                c.email AS client_email,
                COALESCE(p.paid,0) AS paid
         FROM invoices i
         JOIN clients c ON c.id=i.client_id
         LEFT JOIN (
             SELECT invoice_id,SUM(CASE WHEN amount-refunded_amount-disputed_amount>0
                                        THEN amount-refunded_amount-disputed_amount ELSE 0 END) AS paid
             FROM payments WHERE status='succeeded' GROUP BY invoice_id
         ) p ON p.invoice_id=i.id
         LEFT JOIN project_invoice_items pii ON pii.invoice_id=i.id
         WHERE i.project_id=? AND i.collection_mode='project_aggregate' AND pii.id IS NULL
           AND i.status NOT IN ('void','cancelled','paid')
         ORDER BY DATE(COALESCE(i.fulfillment_date,i.document_date,i.created_at)),i.id" . $lockSuffix
    );
    $stmt->execute([$projectId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['paid'] = max(0.0, (float)$row['paid']);
        $row['outstanding'] = max(0.0, (float)$row['total'] - (float)$row['paid']);
        if ((float)$row['outstanding'] > 0.005) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function project_billing_transition_net_days(array $project, array $appConfig): int
{
    if (($project['invoice_net_terms_days'] ?? '') !== '') {
        return max(0, (int)$project['invoice_net_terms_days']);
    }
    return max(0, (int)($appConfig['net_terms_days'] ?? 30));
}

/** @return array{project_invoice_id:int,included_count:int,balance:float} */
function project_billing_transition_create_statement(
    PDO $pdo,
    array $project,
    array $candidates,
    string $transitionDate,
    array $appConfig,
    ?int $actorId
): array {
    $projectId = (int)$project['id'];
    $sameCutoff = $pdo->prepare('SELECT id FROM project_invoices WHERE project_id=? AND billing_period_end=? LIMIT 1');
    $sameCutoff->execute([$projectId, $transitionDate]);
    if ($sameCutoff->fetchColumn()) {
        throw new DomainException('A project statement already uses today as its cutoff. Choose individual conversion or retry on a later date.');
    }

    $previous = $pdo->prepare("SELECT MAX(billing_period_end) FROM project_invoices WHERE project_id=? AND status<>'void'");
    $previous->execute([$projectId]);
    $previousEnd = trim((string)($previous->fetchColumn() ?: ''));
    $periodStart = date('Y-m-01', strtotime($transitionDate));
    if ($previousEnd !== '') {
        $periodStart = max($periodStart, date('Y-m-d', strtotime($previousEnd . ' +1 day')));
    }
    if ($periodStart > $transitionDate) {
        throw new DomainException('The final project statement cannot overlap an existing statement. Choose individual conversion or retry after the current statement cutoff.');
    }

    $total = array_sum(array_column($candidates, 'outstanding'));
    if ($total <= 0.005 || !$candidates) {
        throw new DomainException('No outstanding project charges are ready for a final statement.');
    }

    $primaryStmt = $pdo->prepare('SELECT client_id FROM project_clients WHERE project_id=? ORDER BY is_primary_billing DESC,sort_order,id LIMIT 1');
    $primaryStmt->execute([$projectId]);
    $primaryClientId = (int)($primaryStmt->fetchColumn() ?: ($project['client_id'] ?? 0)) ?: null;
    $docNumber = project_invoice_next_doc_number($pdo);
    $dueDate = date(
        'Y-m-d',
        strtotime($transitionDate . ' +' . project_billing_transition_net_days($project, $appConfig) . ' days')
    );
    $insert = $pdo->prepare(
        'INSERT INTO project_invoices
            (project_id,organization_id,primary_client_id,doc_number,status,billing_period_start,billing_period_end,
             due_date,subtotal,total,amount_paid,balance_due,created_by,finalized_at,finalization_source)
         VALUES (?,?,?, ?,"unpaid",?,?,?,?,?,0,?,?,CURRENT_TIMESTAMP,"billing_mode_transition")'
    );
    $insert->execute([
        $projectId,
        !empty($project['organization_id']) ? (int)$project['organization_id'] : null,
        $primaryClientId,
        $docNumber,
        $periodStart,
        $transitionDate,
        $dueDate,
        $total,
        $total,
        $total,
        $actorId,
    ]);
    $statementId = (int)$pdo->lastInsertId();

    $item = $pdo->prepare(
        'INSERT INTO project_invoice_items
            (project_invoice_id,invoice_id,invoice_doc_number,invoice_date,invoice_due_date,invoice_status,
             invoice_total,amount_paid_at_generation,amount_due_at_generation)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($candidates as $invoice) {
        $invoiceDate = substr(
            (string)($invoice['fulfillment_date'] ?: $invoice['document_date'] ?: $invoice['created_at']),
            0,
            10
        );
        $item->execute([
            $statementId,
            (int)$invoice['id'],
            $invoice['doc_number'] !== null ? (int)$invoice['doc_number'] : null,
            $invoiceDate,
            $invoice['due_date'] ?: null,
            strtolower((string)$invoice['status']) === 'draft' ? 'unpaid' : (string)$invoice['status'],
            (float)$invoice['total'],
            (float)$invoice['paid'],
            (float)$invoice['outstanding'],
        ]);
    }

    return [
        'project_invoice_id' => $statementId,
        'included_count' => count($candidates),
        'balance' => (float)$total,
    ];
}

/**
 * Execute only after the transaction that applied the transition has committed.
 * Transport errors are reported but never undo the accounting transition.
 *
 * @return array{attempted:int,sent:int,failed:int,message:string}
 */
function project_billing_transition_deliver(PDO $pdo, array $transition, array $appConfig): array
{
    $delivery = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'message' => ''];
    if (($transition['delivery_action'] ?? 'review') !== 'send_all') {
        return $delivery;
    }

    $statementId = (int)($transition['project_invoice_id'] ?? 0);
    if ($statementId > 0) {
        $delivery['attempted'] = 1;
        try {
            $result = project_invoice_send_email_result($pdo, $statementId, $appConfig, null, false, null, true);
            if ((int)$result['sent'] + (int)$result['already_sent'] > 0) {
                $delivery['sent'] = 1;
            } else {
                $delivery['failed'] = 1;
                $delivery['message'] = (string)($result['message'] ?? 'The final project statement email was not sent.');
            }
        } catch (Throwable $error) {
            $delivery['failed'] = 1;
            $delivery['message'] = 'The final project statement was saved, but its email could not be sent.';
            @error_log('[project_billing_transition] Statement delivery failed: ' . $error->getMessage());
        }
        return $delivery;
    }

    foreach (array_values(array_unique(array_map('intval', (array)($transition['delivery_invoice_ids'] ?? [])))) as $invoiceId) {
        if ($invoiceId <= 0) {
            continue;
        }
        $delivery['attempted']++;
        try {
            if (invoice_send_finalized($pdo, $invoiceId, $appConfig, 'billing_mode_transition')) {
                $delivery['sent']++;
            } else {
                $delivery['failed']++;
            }
        } catch (Throwable $error) {
            $delivery['failed']++;
            @error_log('[project_billing_transition] Direct invoice delivery failed: ' . $error->getMessage());
        }
    }
    if ($delivery['failed'] > 0) {
        $delivery['message'] = sprintf(
            'The billing change was saved, but %d of %d invoice email(s) could not be delivered and remain available for retry.',
            $delivery['failed'],
            $delivery['attempted']
        );
    }
    return $delivery;
}
