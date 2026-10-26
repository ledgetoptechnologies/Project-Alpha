<?php
declare(strict_types=1);

require_once __DIR__ . '/recurring_billing.php';
require_once __DIR__ . '/audit.php';

function pa_recurring_history_input(array $input, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $first = trim((string)($input['first_period_start'] ?? ''));
    $paymentDate = trim((string)($input['payment_date'] ?? ''));
    $months = filter_var($input['months'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>24]]);
    $reference = trim((string)($input['reference_number'] ?? ''));
    $key = strtolower(trim((string)($input['generation_key'] ?? '')));
    if (!pa_recurring_valid_date($first) || !pa_recurring_valid_date($paymentDate) || $first > $today || $paymentDate > $today) throw new DomainException('Enter valid historical service and payment dates, no later than today.');
    if ($months === false) throw new DomainException('Choose between 1 and 24 monthly periods.');
    if ($reference === '' || mb_strlen($reference) > 255 || preg_match('/[\x00-\x1f]/', $reference)) throw new DomainException('Enter a check reference of at most 255 characters.');
    if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw new DomainException('Reload the historical payment form before continuing.');
    if (($input['payment_method'] ?? 'check') !== 'check') throw new DomainException('Historical reconciliation currently supports checks only.');
    $paid = pa_recurring_rate_money(pa_recurring_rate_minor(trim((string)($input['paid_amount'] ?? ''))));
    return ['first_period_start'=>$first,'months'=>(int)$months,'payment_date'=>$paymentDate,'reference_number'=>$reference,'paid_amount'=>$paid,'payment_method'=>'check','generation_key'=>$key];
}

function pa_recurring_history_percentage(int $minor, string $rate): int
{
    if (!preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/D', trim($rate), $match)) throw new DomainException('The agreement has an unsupported percentage. Review its pricing first.');
    $units = (int)$match[1] * 10000 + (int)str_pad($match[2] ?? '', 4, '0');
    if ($units > 1000000) throw new DomainException('Historical percentage rates must be between zero and 100.');
    return intdiv($minor, 1000000) * $units + intdiv(($minor % 1000000) * $units + 500000, 1000000);
}

/** All money is rounded per invoice, then summed to the single check amount. */
function pa_recurring_history_amounts(array $contract, array $service): array
{
    $subtotal = pa_recurring_rate_minor((string)$service['amount']);
    if (($service['rate_basis'] ?? 'per_period') === 'monthly' && pa_recurring_rate_minor((string)$service['monthly_rate']) !== $subtotal) throw new DomainException('The monthly service rate and invoice amount disagree. Review the service first.');
    $discount = match ((string)($contract['discount_type'] ?? 'none')) {
        'none' => 0,
        'fixed' => min($subtotal, pricing_money_to_minor((string)($contract['discount_value'] ?? '0'))),
        'percent' => pa_recurring_history_percentage($subtotal, (string)($contract['discount_value'] ?? '0')),
        default => throw new DomainException('The agreement discount type is unsupported.'),
    };
    if ($discount < 0) throw new DomainException('Review the negative agreement discount before reconciling payments.');
    $tax = pa_recurring_history_percentage($subtotal - $discount, (string)($contract['tax_percent'] ?? '0'));
    $total = $subtotal - $discount + $tax;
    if ($total <= 0) throw new DomainException('Each historical invoice must have a positive total.');
    return ['subtotal'=>pricing_minor_to_money($subtotal),'discount'=>pricing_minor_to_money($discount),'tax'=>pricing_minor_to_money($tax),'total'=>pa_recurring_rate_money($total)];
}

function pa_recurring_history_lock(PDO $pdo, int $contractId, int $serviceId): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Historical reconciliation requires a transaction.');
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=?' . $suffix); $stmt->execute([$contractId]); $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT * FROM contract_recurring_services WHERE id=? AND contract_id=?' . $suffix); $stmt->execute([$serviceId,$contractId]); $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract || !$service) throw new DomainException('Agreement or service not found.');
    return [$contract,$service];
}

function pa_recurring_history_plan_locked(PDO $pdo, array $contract, array $service, array $input, array $appConfig): array
{
    if (($contract['contract_type'] ?? '') !== 'long_term' || ($contract['pricing_type'] ?? '') !== 'per_invoice'
        || !in_array($contract['status'], ['draft','pending','active','paused'], true)
        || !in_array($service['status'], ['pending','active','paused'], true)
        || ($service['approval_status'] ?? '') !== 'approved'
        || (int)$service['billing_interval_count'] !== 1 || $service['billing_interval_unit'] !== 'month') {
        throw new DomainException('Select an approved monthly recurring service on a draft, pending, active or paused agreement.');
    }
    $change = $pdo->prepare("SELECT id FROM contract_recurring_service_changes WHERE recurring_service_id=? AND state IN ('pending','approved') LIMIT 1"); $change->execute([$service['id']]);
    if ($change->fetchColumn()) throw new DomainException('Resolve the queued service change before reconciling historical payments.');
    $context = project_invoice_billing_context($pdo, !empty($contract['project_id']) ? (int)$contract['project_id'] : null, $appConfig, $input['first_period_start'], true);
    if ($context['collection_mode'] !== 'direct') throw new DomainException('This agreement is billed through project statements. Reconcile its payment from the project invoice instead.');
    $organizationId = pa_document_effective_organization_id($pdo, 'contract', (int)$contract['id']);
    if (pricing_adjustments_enabled($pdo) && $organizationId && !empty($contract['project_id'])) throw new DomainException('Historical reconciliation with inherited pricing adjustments requires individual invoice review.');
    $unknown = $pdo->prepare("SELECT i.id FROM invoices i WHERE i.contract_id=? AND NOT EXISTS (SELECT 1 FROM recurring_invoice_coverage c WHERE c.invoice_id=i.id) LIMIT 1"); $unknown->execute([$contract['id']]);
    if ($unknown->fetchColumn()) throw new DomainException('Existing agreement invoices have untracked coverage. Reconcile those invoices before adding historical periods.');
    $amounts = pa_recurring_history_amounts($contract, $service);
    $periods = []; $start = $input['first_period_start'];
    $historicalService = array_replace($service, ['effective_from'=>$start,'billing_anchor_date'=>$start]);
    for ($index = 0; $index < $input['months']; $index++) {
        if ($start > date('Y-m-d')) throw new DomainException('A historical payment cannot create periods that have not started yet.');
        $end = pa_recurring_service_period_end($historicalService, $start);
        if (!empty($service['effective_until']) && (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d') > $service['effective_until']) throw new DomainException('Historical coverage exceeds the service end date.');
        $periods[] = ['start'=>$start,'end'=>$end] + $amounts;
        $start = $end;
    }
    $next = (string)($service['next_invoice_date'] ?? '');
    if (!pa_recurring_valid_date($next) || $next < $input['first_period_start'] || $next > $start) throw new DomainException('These periods must include the existing renewal date or end exactly at it. Resolve any gap before recording payment.');
    if (!in_array($next, array_merge(array_column($periods, 'start'), [$start]), true)) throw new DomainException('The existing renewal date must match a historical period boundary. Review the billing dates first.');
    // Compare a full calendar year to catch clipped February anniversaries as well
    // as ordinary day mismatches without changing the live service anchor.
    $future = $start;
    $liveAnchor = (string)($service['billing_anchor_date'] ?? $service['effective_from']);
    for ($month = 0; $month < 12; $month++) {
        $liveEnd = pa_recurring_service_next_date($future, 1, 'month', $liveAnchor);
        $historicalEnd = pa_recurring_service_next_date($future, 1, 'month', $input['first_period_start']);
        if ($liveEnd !== $historicalEnd) throw new DomainException('Historical periods and the future billing anniversary disagree. Review the service schedule before recording payment.');
        $future = $liveEnd;
    }
    $overlap = $pdo->prepare('SELECT id FROM recurring_invoice_coverage WHERE recurring_service_id=? AND coverage_start<? AND coverage_end>? LIMIT 1'); $overlap->execute([$service['id'],$start,$input['first_period_start']]);
    if ($overlap->fetchColumn()) throw new DomainException('One or more periods already have invoices. Record payment against those invoices instead.');
    $total = pa_recurring_rate_money(pricing_money_to_minor($amounts['total']) * $input['months']);
    if ($total !== $input['paid_amount']) throw new DomainException('The check amount must equal the combined invoice total of ' . $total . '. Review the selected months, taxes and discounts.');
    $history = $pdo->prepare('SELECT id,status,total,amount_paid,balance_due FROM invoices WHERE contract_id=? ORDER BY id'); $history->execute([$contract['id']]);
    $fingerprint = hash('sha256', json_encode([$contract,$service,$context,$organizationId,$history->fetchAll(PDO::FETCH_ASSOC),$input,$periods], JSON_THROW_ON_ERROR));
    $secret = (string)($appConfig['_history_preview_secret'] ?? '');
    if (strlen($secret) < 32) throw new LogicException('Historical preview authorization is unavailable.');
    return ['periods'=>$periods,'total'=>$total,'months'=>$input['months'],'next_invoice_date'=>$start,'preview_hash'=>hash_hmac('sha256',$fingerprint,$secret),'organization_id'=>$organizationId,'historical_service'=>$historicalService];
}

function pa_recurring_history_preview(PDO $pdo, int $contractId, int $serviceId, array $input, array $appConfig): array
{
    $input = pa_recurring_history_input($input);
    if ($pdo->inTransaction()) throw new LogicException('Preview requires its own transaction.');
    $pdo->beginTransaction();
    try {
        [$contract,$service] = pa_recurring_history_lock($pdo,$contractId,$serviceId);
        $plan = pa_recurring_history_plan_locked($pdo,$contract,$service,$input,$appConfig);
        $pdo->rollBack();
        unset($plan['organization_id'],$plan['historical_service']);
        return $plan;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function pa_recurring_history_generation_key(int $contractId, int $serviceId, string $requestKey, int $index): string
{
    return hash('sha256', 'recurring-history:' . $contractId . ':' . $serviceId . ':' . $requestKey . ':' . $index);
}

function pa_recurring_history_record(PDO $pdo, int $contractId, int $serviceId, array $posted, array $appConfig, ?int $actor): array
{
    $input = pa_recurring_history_input($posted);
    if (empty($posted['history_confirmed'])) throw new DomainException('Review and confirm the historical payment preview first.');
    if ($pdo->inTransaction()) throw new LogicException('Reconciliation requires its own transaction.');
    $pdo->beginTransaction();
    try {
        [$contract,$service] = pa_recurring_history_lock($pdo,$contractId,$serviceId);
        $payloadHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $firstKey = pa_recurring_history_generation_key($contractId,$serviceId,$input['generation_key'],0);
        $existing = $pdo->prepare('SELECT i.id,c.schedule_snapshot FROM invoices i JOIN recurring_invoice_coverage c ON c.invoice_id=i.id WHERE i.contract_id=? AND i.generation_key=? AND c.recurring_service_id=?'); $existing->execute([$contractId,$firstKey,$serviceId]);
        if ($replay = $existing->fetch(PDO::FETCH_ASSOC)) {
            $snapshot = json_decode($replay['schedule_snapshot'],true,32,JSON_THROW_ON_ERROR);
            if (!hash_equals((string)($snapshot['history_payload_hash'] ?? ''), $payloadHash)) throw new DomainException('This request was already recorded with different values. Reload the form.');
            $invoiceIds=[]; $paymentIds=[];
            for ($index=0;$index<$input['months'];$index++) {
                $stmt=$pdo->prepare('SELECT i.id,p.id AS payment_id FROM invoices i JOIN payments p ON p.invoice_id=i.id WHERE i.contract_id=? AND i.generation_key=? AND p.payment_method="check" AND p.reference_number=? ORDER BY p.id LIMIT 1');
                $stmt->execute([$contractId,pa_recurring_history_generation_key($contractId,$serviceId,$input['generation_key'],$index),$input['reference_number']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new DomainException('The previous reconciliation needs review before it can be retried.');
                $invoiceIds[]=(int)$row['id'];$paymentIds[]=(int)$row['payment_id'];
            }
            $pdo->rollBack();
            return ['invoice_ids'=>$invoiceIds,'payment_ids'=>$paymentIds,'next_invoice_date'=>$service['next_invoice_date'],'idempotent'=>true];
        }
        $plan = pa_recurring_history_plan_locked($pdo,$contract,$service,$input,$appConfig);
        if (!hash_equals($plan['preview_hash'], (string)($posted['preview_hash'] ?? ''))) throw new DomainException('The agreement, payment details or billing history changed. Preview again before recording.');
        $invoiceIds=[]; $paymentIds=[]; $organizationId=$plan['organization_id'];
        foreach ($plan['periods'] as $index=>$period) {
            $pdo->prepare('INSERT INTO invoices (contract_id,quote_id,client_id,project_id,job_id,service_location_id,project_code,organization_id,show_contact_on_document,created_by,invoice_type,document_date,discount_type,discount_value,tax_percent,tax_amount,subtotal,total,amount_paid,balance_due,status,due_date,payment_terms_days,due_date_source,collection_mode,generation_key,scope) VALUES (?,?,?,?,?,?,?,?,?,?,"long_term",?,?,?,?,?,?,?,0,?,"draft",?,0,"terms","direct",?,?)')
                ->execute([$contractId,$contract['quote_id'] ?: null,$contract['client_id'],$contract['project_id'] ?: null,$contract['job_id'] ?: null,$contract['service_location_id'] ?: null,$contract['project_code'],$organizationId,(int)($contract['show_contact_on_document']??0),$actor,$period['start'],$contract['discount_type'],$contract['discount_value'],$contract['tax_percent'],$period['tax'],$period['subtotal'],$period['total'],$period['total'],$period['start'],pa_recurring_history_generation_key($contractId,$serviceId,$input['generation_key'],$index),$service['description'] ?? $service['name']]);
            $invoiceId=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([pa_next_invoice_doc_number($pdo,'long_term'),$invoiceId]);
            $description='Previously paid service period: '.$period['start'].' through '.(new DateTimeImmutable($period['end']))->modify('-1 day')->format('Y-m-d');
            if (($service['rate_basis']??'per_period')==='monthly') $description.=' | Monthly rate '.$service['monthly_rate'].' x 1 month';
            $pdo->prepare('INSERT INTO invoice_items (invoice_id,item,description,quantity,unit_price,line_total) VALUES (?,?,?,1,?,?)')->execute([$invoiceId,$service['name'],$description,$period['subtotal'],$period['subtotal']]);
            invoice_finalize($pdo,$invoiceId,$appConfig,'historical_recurring_payment',$actor);
            $snapshot=pa_recurring_service_snapshot($plan['historical_service']);$snapshot['next_invoice_date']=$period['start'];$snapshot['history_payload_hash']=$payloadHash;
            $pdo->prepare('INSERT INTO recurring_invoice_coverage (recurring_service_id,invoice_id,coverage_start,coverage_end,rate_basis,monthly_rate,amount,schedule_snapshot) VALUES (?,?,?,?,?,?,?,?)')->execute([$serviceId,$invoiceId,$period['start'],$period['end'],$service['rate_basis']??'per_period',$service['monthly_rate']??null,$period['subtotal'],json_encode($snapshot,JSON_THROW_ON_ERROR)]);
            $payment=invoice_record_locked_payment($pdo,$invoiceId,(float)$period['total'],'check',$input['reference_number'],'Historical recurring payment; one check allocated across '.$input['months'].' monthly invoices.', ['payment_date'=>$input['payment_date'],'complete_contract_when_paid'=>false,'source'=>'historical_recurring_payment']);
            $pdo->prepare('UPDATE invoices SET paid_at=? WHERE id=?')->execute([$input['payment_date'].' 00:00:00',$invoiceId]);
            DocumentRevisionService::snapshotAndSave($pdo,'invoice',$invoiceId,$actor,false,$appConfig);
            $invoiceIds[]=$invoiceId;$paymentIds[]=(int)$payment['payment_id'];
        }
        $lastPeriod=$plan['periods'][count($plan['periods'])-1];
        $pdo->prepare('UPDATE contract_recurring_services SET next_invoice_date=?,last_invoice_date=GREATEST(COALESCE(last_invoice_date,?),?) WHERE id=? AND contract_id=?')->execute([$plan['next_invoice_date'],$lastPeriod['start'],$lastPeriod['start'],$serviceId,$contractId]);
        $totalInvoiced=pa_recurring_minor_to_money(pricing_money_to_minor((string)($contract['total_invoiced']??'0'))+pricing_money_to_minor($plan['total']));
        $pdo->prepare('UPDATE contracts SET total_invoiced=?,invoices_generated=invoices_generated+?,last_invoice_date=GREATEST(COALESCE(last_invoice_date,?),?) WHERE id=?')->execute([$totalInvoiced,$input['months'],$lastPeriod['start'],$lastPeriod['start'],$contractId]);
        if (in_array($contract['status'],['active','paused'],true)) pa_recurring_service_sync_contract_next_date($pdo,$contractId);
        else {
            $next=$pdo->prepare('SELECT MIN(next_invoice_date) FROM contract_recurring_services WHERE contract_id=? AND status="pending" AND approval_status="approved"');$next->execute([$contractId]);
            $pdo->prepare('UPDATE contracts SET next_invoice_date=? WHERE id=?')->execute([$next->fetchColumn()?:$plan['next_invoice_date'],$contractId]);
        }
        audit_log($pdo,'contract.historical_payment','contract',$contractId,['service_id'=>$serviceId,'invoice_ids'=>$invoiceIds,'payment_ids'=>$paymentIds,'first_period_start'=>$input['first_period_start'],'coverage_end'=>$plan['next_invoice_date'],'total'=>$plan['total']],$actor);
        $pdo->commit();
        return ['invoice_ids'=>$invoiceIds,'payment_ids'=>$paymentIds,'next_invoice_date'=>$plan['next_invoice_date'],'idempotent'=>false];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
