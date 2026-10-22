<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/stripe_invoice_payment_amounts.php';
require_once __DIR__ . '/stripe_invoice_payment_state.php';
require_once __DIR__ . '/invoice_lifecycle.php';

/** Strictly scoped repair, not a payment capture or a historical import. */
function stripe_single_payment_evidence(array $pi, array $context, array $invoice, array $payment): array
{
    $reject = static function (string $reason): never { stripe_reconciliation_hold($reason); };
    $piId = (string)($payment['stripe_payment_intent_id'] ?? '');
    if (!preg_match('/\Api_[A-Za-z0-9_]+\z/D', $piId)
        || ($pi['object'] ?? '') !== 'payment_intent' || ($pi['id'] ?? '') !== $piId
        || ($pi['status'] ?? '') !== 'succeeded' || !is_bool($pi['livemode'] ?? null)
        || !is_bool($context['livemode'] ?? null) || $pi['livemode'] !== $context['livemode']
        || !preg_match('/\Aacct_[A-Za-z0-9]+\z/D', (string)($context['account_id'] ?? ''))
        || !empty($pi['on_behalf_of']) || !empty($pi['transfer_data'])
        || !empty($pi['application']) || !empty($pi['application_fee_amount'])) $reject('payment_evidence');
    $metadata = $pi['metadata'] ?? null;
    if (!is_array($metadata) || !empty($metadata['pa_project_invoice_id']) || !empty($metadata['project_invoice_id'])) $reject('invoice_reference');
    $identityFound = false;
    foreach (['pa_invoice_id', 'invoice_id'] as $key) {
        if (!array_key_exists($key, $metadata)) continue;
        if (!is_string($metadata[$key]) || $metadata[$key] !== (string)$invoice['id']) $reject('invoice_reference');
        $identityFound = true;
    }
    if (!$identityFound) $reject('invoice_reference');
    foreach (['pa_client_id', 'client_id'] as $key) {
        if (array_key_exists($key, $metadata) && $metadata[$key] !== (string)$invoice['client_id']) $reject('client_reference');
    }
    if ((int)$payment['invoice_id'] !== (int)$invoice['id'] || (int)$payment['client_id'] !== (int)$invoice['client_id']) $reject('client_reference');
    if (!empty($invoice['stripe_customer_id']) && ($pi['customer'] ?? null) !== $invoice['stripe_customer_id']) $reject('client_reference');
    $currency = strtoupper((string)($invoice['currency'] ?? ''));
    // This repair intentionally supports only currencies with two decimal minor units.
    if (!in_array($currency, ['USD', 'CAD', 'EUR', 'GBP', 'AUD', 'NZD', 'CHF'], true)
        || ($pi['currency'] ?? '') !== strtolower($currency)) $reject('currency');
    try {
        $amounts = stripe_invoice_payment_amounts($pi, 'payment_intent');
        $invoiceCents = stripe_invoice_payment_metadata_cents(['total' => (string)$invoice['total']], ['total']);
        $declaredPrincipal = stripe_invoice_payment_metadata_cents($metadata, ['pa_invoice_amount', 'original_amount']);
        $declaredSurcharge = stripe_invoice_payment_metadata_cents($metadata, ['pa_surcharge_amount', 'surcharge_amount']);
    } catch (Throwable) { $reject('amount'); }
    // Nonzero client fees require both explicit authoritative amounts, not a guessed difference.
    if ($amounts['payment_cents'] !== $invoiceCents || ($pi['amount'] ?? null) !== $amounts['gross_cents']
        || ($amounts['surcharge_cents'] > 0 && ($declaredPrincipal === null || $declaredSurcharge === null))) $reject('amount');
    $charge = $pi['latest_charge'] ?? null;
    if (!is_array($charge) || ($charge['object'] ?? '') !== 'charge'
        || !preg_match('/\Ach_[A-Za-z0-9_]+\z/D', (string)($charge['id'] ?? ''))
        || ($charge['payment_intent'] ?? '') !== $piId || ($charge['paid'] ?? null) !== true
        || ($charge['captured'] ?? null) !== true || ($charge['livemode'] ?? null) !== $context['livemode']
        || ($charge['amount'] ?? null) !== $amounts['gross_cents'] || ($charge['currency'] ?? '') !== strtolower($currency)
        || !is_int($charge['created'] ?? null) || $charge['created'] <= 0 || $charge['created'] > time() + 300
        || !empty($charge['failure_code']) || !empty($charge['on_behalf_of']) || !empty($charge['transfer_data'])
        || !empty($charge['application']) || !empty($charge['application_fee'])) $reject('charge');
    if (($charge['amount_refunded'] ?? null) !== 0 || ($charge['disputed'] ?? null) !== false
        || !empty($charge['refunds']['data']) || !empty($charge['refunds']['has_more'])) $reject('refund_dispute');
    $balance = $charge['balance_transaction'] ?? null;
    if (!is_array($balance) || ($balance['object'] ?? '') !== 'balance_transaction'
        || !preg_match('/\Atxn_[A-Za-z0-9_]+\z/D', (string)($balance['id'] ?? ''))
        || ($balance['source'] ?? '') !== $charge['id'] || ($balance['currency'] ?? '') !== strtolower($currency)
        || ($balance['amount'] ?? null) !== $amounts['gross_cents']
        || !is_int($balance['fee'] ?? null) || $balance['fee'] < 0 || $balance['fee'] > $balance['amount']
        || !is_int($balance['net'] ?? null) || $balance['net'] !== $balance['amount'] - $balance['fee']) $reject('processor_balance');
    if (!empty($payment['processor_payment_id']) && $payment['processor_payment_id'] !== $piId) $reject('payment_evidence');
    return $amounts;
}

/** Stable operator-facing codes; never return provider exception text or metadata. */
final class StripeReconciliationHold extends RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct(stripe_reconciliation_reason($reason)); }
}
function stripe_reconciliation_reason(string $code): string
{
    return [
        'preview_required'=>'Open a fresh settlement preview before confirming reconciliation.',
        'row_type'=>'This is not an existing failed or settled Stripe invoice payment.',
        'provider_unavailable'=>'Fresh Stripe account or payment evidence could not be retrieved.',
        'payment_evidence'=>'PaymentIntent identity, success, account mode or connected-account evidence is not valid.',
        'invoice_reference'=>'Stripe invoice metadata does not identify this invoice exactly.',
        'client_reference'=>'Stripe or the existing payment identifies a different client.',
        'currency'=>'Stripe and invoice currencies do not match a supported two-decimal currency.',
        'amount'=>'Captured gross, declared principal and customer surcharge do not reconcile to the full invoice.',
        'charge'=>'The captured charge identity, amount, currency or settlement date is not verified.',
        'refund_dispute'=>'Refund or dispute evidence requires manual review.',
        'processor_balance'=>'Actual processor fee and net evidence does not reconcile to the captured charge.',
        'record_changed'=>'The payment or invoice changed while Stripe was being checked.',
        'invoice_state'=>'The invoice is not a collectible direct named-recipient invoice.',
        'invoice_not_finalized'=>'The invoice has not been finalized.',
        'payment_state'=>'The payment has an unsupported method, assignment, reversal or prior adjustment.',
        'checkout'=>'The current Checkout session is incomplete, unrelated or cannot be verified.',
        'project_assignment'=>'This invoice belongs to a project statement and needs its project-payment workflow.',
        'other_payment'=>'Another settled payment exists for this invoice.',
        'settlement_differs'=>'Existing settled principal, surcharge, processor amounts or invoice balance differs.',
        'already_paid'=>'The invoice is already paid without this settlement.',
        'guard_failed'=>'A reconciliation safety check failed. No payment changes were committed.',
    ][$code] ?? 'The payment could not be safely reconciled.';
}
function stripe_reconciliation_hold(string $reason): never { throw new StripeReconciliationHold($reason); }

/** Financially read-only previews still write their one-time session confirmation state. */
function stripe_reconciliation_preview_token(array &$session, int $paymentId, array $report): ?string
{
    if ($paymentId <= 0) throw new InvalidArgumentException('An existing payment is required.');
    if (($report['eligible'] ?? false) !== true || ($report['reason'] ?? '') !== 'ready') {
        unset($session['stripe_reconciliation_preview'][$paymentId]);
        return null;
    }
    $token = bin2hex(random_bytes(32));
    $session['stripe_reconciliation_preview'][$paymentId] = ['token' => $token, 'at' => time()];
    return $token;
}

/** Provider GETs only. Never reads a different payment inferred from descriptions or user-supplied PI IDs. */
function stripe_single_payment_fetch(PDO $pdo, StripeService $stripe, int $paymentId): array
{
    if ($paymentId<=0) stripe_reconciliation_hold('row_type');
    $stmt=$pdo->prepare('SELECT p.*,i.stripe_session_id AS invoice_checkout_session,i.doc_number AS invoice_doc_number,i.invoice_type,i.total AS invoice_total,i.currency AS invoice_currency,c.name AS client_name FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN clients c ON c.id=i.client_id WHERE p.id=?');
    $stmt->execute([$paymentId]); $before=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before || ($before['payment_method']??'')!=='stripe' || !in_array($before['status']??'', ['failed','succeeded'],true)
        || !preg_match('/\Api_[A-Za-z0-9_]+\z/D',(string)($before['stripe_payment_intent_id']??''))) stripe_reconciliation_hold('row_type');
    try {
        $session=!empty($before['invoice_checkout_session'])?$stripe->getCheckoutSession($before['invoice_checkout_session']):null;
        $context=$stripe->getReconciliationContext();
        $pi=$stripe->getPaymentIntentWithBalanceTransaction($before['stripe_payment_intent_id']);
    } catch (Throwable) { stripe_reconciliation_hold('provider_unavailable'); }
    return compact('before','session','context','pi');
}

/** Same checks for preview and commit; only the latter takes authoritative invoice→payment row locks. */
function stripe_single_payment_assess(PDO $pdo,array $bundle,bool $lock): array
{
    extract($bundle, EXTR_SKIP);
    $invoiceId=(int)$before['invoice_id']; $paymentId=(int)$before['id'];
    $prepare=static fn(string $sql):PDOStatement=>$pdo->prepare($lock?$sql:str_replace(' FOR UPDATE','',$sql));
    $stmt=$prepare('SELECT i.*,c.stripe_customer_id FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=? FOR UPDATE');
    $stmt->execute([$invoiceId]);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
    $stmt=$prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$stmt->execute([$paymentId]);$payment=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice||!$payment||(int)$payment['invoice_id']!==$invoiceId||$payment['stripe_payment_intent_id']!==$before['stripe_payment_intent_id']
        ||(int)$payment['client_id']!==(int)$before['client_id']) stripe_reconciliation_hold('record_changed');
    if(!in_array($invoice['status'],['sent','unpaid','partial','overdue','paid'],true)
        ||!in_array($invoice['collection_mode']??'',['','direct'],true)||pa_invoice_is_general_recipient($invoice)) stripe_reconciliation_hold('invoice_state');
    if(empty($invoice['finalized_at'])) stripe_reconciliation_hold('invoice_not_finalized');
    if(!empty($payment['job_id'])||!empty($payment['project_invoice_payment_id'])||($payment['payment_method']??'')!=='stripe'
        ||!in_array((string)($payment['processor_provider']??''),['','stripe'],true)||!empty($payment['reversed_at'])
        ||!empty($payment['surcharge_refunded'])||(float)($payment['surcharge_refund_amount']??0)!==0.0) stripe_reconciliation_hold('payment_state');
    if((float)$payment['refunded_amount']!==0.0||(float)$payment['disputed_amount']!==0.0) stripe_reconciliation_hold('refund_dispute');
    $amounts=stripe_single_payment_evidence($pi,$context,$invoice,$payment);
    if(!empty($invoice['stripe_session_id'])&&($invoice['stripe_session_id']!==($before['invoice_checkout_session']??null)
        ||!is_array($session)||($session['id']??'')!==$invoice['stripe_session_id']||($session['payment_intent']??'')!==$pi['id']
        ||($session['status']??'')!=='complete'||($session['payment_status']??'')!=='paid')) stripe_reconciliation_hold('checkout');
    foreach(['stripe_refunds','stripe_disputes'] as $table){
        $events=$prepare("SELECT id FROM {$table} WHERE stripe_payment_intent_id=? OR payment_id=? FOR UPDATE");$events->execute([$pi['id'],$paymentId]);
        if($events->fetchColumn()!==false) stripe_reconciliation_hold('refund_dispute');
    }
    $assigned=$prepare('SELECT id FROM project_invoice_items WHERE invoice_id=? FOR UPDATE');$assigned->execute([$invoiceId]);
    if($assigned->fetchColumn()!==false) stripe_reconciliation_hold('project_assignment');
    $others=$prepare('SELECT id FROM payments WHERE invoice_id=? AND id<>? AND status="succeeded" AND amount>0 FOR UPDATE');$others->execute([$invoiceId,$paymentId]);
    if($others->fetchColumn()!==false) stripe_reconciliation_hold('other_payment');
    $transition=($payment['status']==='failed'&&(float)$payment['amount']===0.0&&(float)$payment['surcharge_paid']===0.0);
    if(!$transition){
        $balance=$pi['latest_charge']['balance_transaction'];
        if($payment['status']!=='succeeded'||(float)$payment['amount']!==$amounts['payment_amount']
            ||(float)$payment['surcharge_paid']!==$amounts['surcharge_amount']||$invoice['status']!=='paid'
            ||(float)$invoice['amount_paid']!==$amounts['payment_amount']||(float)$invoice['balance_due']!==0.0||empty($invoice['paid_at'])
            ||$payment['processor_gross_amount']===null||(float)$payment['processor_gross_amount']!==$amounts['gross_amount']
            ||$payment['processor_fee_amount']===null||(float)$payment['processor_fee_amount']!==$balance['fee']/100.0
            ||$payment['processor_net_amount']===null||(float)$payment['processor_net_amount']!==$balance['net']/100.0) stripe_reconciliation_hold('settlement_differs');
    }elseif($invoice['status']==='paid')stripe_reconciliation_hold('already_paid');
    return compact('invoice','payment','amounts','transition');
}

/** Read-only preview. Financial observations are labelled as Stripe evidence, never a committed local payment. */
function stripe_preview_existing_payment(PDO $pdo,StripeService $stripe,int $paymentId):array
{
    $report=['payment_id'=>$paymentId,'eligible'=>false,'reason'=>'guard_failed','amounts'=>null,'gross_cents'=>null,
        'processor_fee_cents'=>null,'processor_net_cents'=>null,'refunded_cents'=>null,'disputed'=>null,'currency'=>null];
    try{
        $bundle=stripe_single_payment_fetch($pdo,$stripe,$paymentId);
        $report['client_name']=(string)$bundle['before']['client_name'];
        $report['invoice_label']=pa_invoice_label_from_row(['id'=>(int)$bundle['before']['invoice_id'],'doc_number'=>$bundle['before']['invoice_doc_number'],'invoice_type'=>$bundle['before']['invoice_type']]);
        $report['invoice_total']=(string)$bundle['before']['invoice_total'];$report['invoice_currency']=(string)$bundle['before']['invoice_currency'];
        $pi=$bundle['pi'];$charge=$pi['latest_charge']??[];$balance=$charge['balance_transaction']??[];
        foreach(['gross_cents'=>$pi['amount_received']??null,'processor_fee_cents'=>$balance['fee']??null,
            'processor_net_cents'=>$balance['net']??null,'refunded_cents'=>$charge['amount_refunded']??null] as $key=>$value)
            $report[$key]=is_int($value)?$value:null;
        $report['disputed']=is_bool($charge['disputed']??null)?$charge['disputed']:null;
        $report['currency']=preg_match('/\A[a-z]{3}\z/D',(string)($pi['currency']??''))?strtoupper($pi['currency']):null;
        try{$report['amounts']=stripe_invoice_payment_amounts($pi,'payment_intent');}catch(Throwable){}
        $assessment=stripe_single_payment_assess($pdo,$bundle,false);
        $report['eligible']=true;$report['reason']=$assessment['transition']?'ready':'unchanged';
    }catch(StripeReconciliationHold $error){$report['reason']=$error->reason;}
    catch(Throwable){$report['reason']='guard_failed';}
    $report['message']=$report['reason']==='ready'?'Verified settlement is ready to reconcile.':($report['reason']==='unchanged'?'This payment already matches the verified settlement.':stripe_reconciliation_reason($report['reason']));
    return $report;
}

/** Fresh provider evidence and transactional validation on every commit; never reuses a browser preview. */
function stripe_reconcile_existing_payment(PDO $pdo,StripeService $stripe,array $appConfig,int $paymentId,int $actorId):array
{
    if($actorId<=0||$pdo->inTransaction())stripe_reconciliation_hold('guard_failed');
    $bundle=stripe_single_payment_fetch($pdo,$stripe,$paymentId);$pi=$bundle['pi'];$context=$bundle['context'];
    $pdo->beginTransaction();
    try{
        $assessment=stripe_single_payment_assess($pdo,$bundle,true);extract($assessment,EXTR_SKIP);$invoiceId=(int)$invoice['id'];
        if(!$transition){$pdo->commit();return['payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'status'=>'unchanged'];}
        // Preserve the existing guarded failed-attempt transition and lock order.
        stripe_invoice_existing_payment($pdo,$paymentId,$invoiceId,$pi['id']);
        $transaction=$stripe->normalizePaymentIntentForImport($pi);
        stripe_invoice_promote_failed_payment($pdo,$paymentId,$invoiceId,$pi['id'],null,$amounts['payment_amount'],$amounts['surcharge_amount'],$transaction,$appConfig);
        $settledAt=gmdate('Y-m-d H:i:s',$pi['latest_charge']['created']);
        $pdo->prepare('UPDATE payments SET payment_date=? WHERE id=?')->execute([substr($settledAt,0,10),$paymentId]);
        $pdo->prepare('UPDATE invoices SET paid_at=COALESCE(paid_at,?) WHERE id=?')->execute([$settledAt,$invoiceId]);
        $state=invoice_refresh_payment_totals($pdo,$invoiceId,false);
        if($state['status']!=='paid')stripe_reconciliation_hold('settlement_differs');
        $pdo->prepare('UPDATE invoices SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')->execute([$invoiceId]);
        pa_public_link_terminalize($pdo,'invoice',$invoiceId,'paid');
        $links=$pdo->prepare('SELECT id FROM public_links WHERE document_type="invoice" AND document_id=? AND revoked=0 FOR UPDATE');$links->execute([$invoiceId]);
        if($links->fetchColumn()!==false)stripe_reconciliation_hold('guard_failed');
        invoice_complete_linked_contract_if_eligible($pdo,$invoiceId);
        $balance=$pi['latest_charge']['balance_transaction'];
        $pdo->prepare('INSERT INTO system_audit(user_id,action,entity_type,entity_id,details) VALUES (?,"payment.stripe_reconciled","payment",?,?)')->execute([
            $actorId,$paymentId,json_encode(['invoice_id'=>$invoiceId,'payment_intent_id'=>$pi['id'],'stripe_account_id'=>$context['account_id'],
                'livemode'=>$context['livemode'],'principal_cents'=>$amounts['payment_cents'],'surcharge_cents'=>$amounts['surcharge_cents'],
                'gross_cents'=>$amounts['gross_cents'],'processor_fee_cents'=>$balance['fee'],'processor_net_cents'=>$balance['net'],
                'currency'=>$invoice['currency'],'charge_id'=>$pi['latest_charge']['id'],'balance_transaction_id'=>$balance['id'],
                'processor_action'=>'read_only','emails_sent'=>false],JSON_THROW_ON_ERROR)]);
        $pdo->commit();return['payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'status'=>'reconciled'];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
