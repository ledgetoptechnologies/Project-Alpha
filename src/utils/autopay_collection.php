<?php
declare(strict_types=1);

require_once __DIR__ . '/autopay_beta.php';
require_once __DIR__ . '/stripe_invoice_payment_amounts.php';

function pa_autopay_table(string $type): string
{
    return match ($type) {'invoice'=>'invoices','project_invoice'=>'project_invoices',default=>throw new InvalidArgumentException('Unsupported collection document.')};
}

/** Call while holding the document lock, before manual payment/Checkout/void/edit. */
function pa_autopay_assert_payment_available(PDO $pdo, int $id, string $type='invoice'): void
{
    pa_autopay_table($type);
    // Upgrades without migration 112 remain usable while this disabled feature is absent.
    require_once __DIR__ . '/invoice_lifecycle.php';
    if (!invoice_table_has_column($pdo,'autopay_collections','state')) return;
    $s=$pdo->prepare('SELECT state,financial_hold FROM autopay_collections WHERE document_type=? AND document_id=?');$s->execute([$type,$id]);$collection=$s->fetch(PDO::FETCH_ASSOC);
    if ($collection && (!empty($collection['financial_hold']) || in_array($collection['state'],['pending','processing','requires_action','held'],true))) {
        throw new DomainException('AutoPay has reserved this invoice. Resolve or cancel the collection before another payment or invoice change.');
    }
}

/** Reserve before Checkout I/O so reopening/finalizing cannot race session creation. */
function pa_autopay_reserve_checkout(PDO $pdo,array $document,array $config,string $type='invoice'): void
{
    if(!pa_autopay_enabled($config))return;
    if(!$pdo->inTransaction())throw new LogicException('Checkout reservation requires its document lock.');
    if(empty($document['finalized_at']) || !in_array($document['status'],['unpaid','sent','partial','overdue'],true))throw new DomainException('Finalize the invoice before payment.');
    $existing=(string)($document['stripe_session_id']??'');
    if(str_starts_with($existing,'pa_checkout_pending_'))throw new DomainException('A payment session is being prepared or needs review. Please retry after it is resolved.');
    if($existing!=='')return;
    $table=pa_autopay_table($type);
    // An ambiguous timeout retains this marker. It must never open a second charge path.
    $pdo->prepare("UPDATE {$table} SET stripe_session_id=?,stripe_checkout_expires_at=DATE_ADD(NOW(),INTERVAL 25 HOUR) WHERE id=?")
        ->execute(['pa_checkout_pending_'.bin2hex(random_bytes(16)),(int)$document['id']]);
}

/** Lock the statement before its child, matching project payment allocation order. */
function pa_autopay_lock_parent_for_invoice_mutation(PDO $pdo,int $invoiceId): void
{
    if(!$pdo->inTransaction())throw new LogicException('Invoice mutation requires a transaction.');
    $s=$pdo->prepare('SELECT project_invoice_id FROM project_invoice_items WHERE invoice_id=?');$s->execute([$invoiceId]);
    $parent=(int)($s->fetchColumn()?:0);if(!$parent)return;
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $s=$pdo->prepare('SELECT id FROM project_invoices WHERE id=?'.$suffix);$s->execute([$parent]);
    pa_autopay_assert_payment_available($pdo,$parent,'project_invoice');
}

function pa_autopay_authorization_for_invoice(PDO $pdo,array $invoice,array $config): ?array
{
    if(!pa_autopay_enabled($config))return null;
    if(array_key_exists('primary_client_id',$invoice) && !pa_autopay_project_scope_valid($pdo,$invoice))return null;
    $invoice['client_id']=$invoice['client_id']??$invoice['primary_client_id']??0;
    $s=$pdo->prepare('SELECT * FROM autopay_enrollments WHERE client_id=? AND status="active" AND revoked_at IS NULL AND verified_at IS NOT NULL AND livemode=0 AND installation_id=? AND processor_account_id=? AND currency=? AND ((scope="agreement" AND contract_id=?) OR (scope="business" AND contract_id IS NULL)) ORDER BY (scope="agreement") DESC,id DESC LIMIT 1 FOR UPDATE');
    $s->execute([(int)$invoice['client_id'],(string)getenv('AUTOPAY_INSTALLATION_ID'),(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID'),strtolower((string)($invoice['currency']??$config['workforce_currency']??'usd')),(int)($invoice['contract_id']??0)]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    if($row && array_key_exists('primary_client_id',$invoice)){
        $effective=substr((string)$row['effective_from'],0,10);
        if((string)($invoice['billing_period_start']??'')<$effective || !pa_autopay_project_scope_valid($pdo,$invoice,$effective))return null;
    }
    return $row && !empty($row['payment_method_id']) && in_array($row['method_type'],['card','us_bank_account'],true) ? $row : null;
}

function pa_autopay_project_scope_valid(PDO $pdo,array $invoice,?string $effectiveDate=null): bool
{
    $s=$pdo->prepare('SELECT COUNT(*) AS included,SUM(CASE WHEN i.client_id IS NULL OR i.client_id<>? THEN 1 ELSE 0 END) AS foreign_clients,MIN(DATE(COALESCE(p.invoice_date,i.document_date,i.created_at))) AS earliest_work FROM project_invoice_items p JOIN invoices i ON i.id=p.invoice_id WHERE p.project_invoice_id=?');
    $s->execute([(int)($invoice['primary_client_id']??0),(int)$invoice['id']]);$scope=$s->fetch(PDO::FETCH_ASSOC);
    return (int)$scope['included']>0 && (int)$scope['foreign_clients']===0 && ($effectiveDate===null || (string)$scope['earliest_work']>=$effectiveDate);
}

function pa_autopay_enqueue_invoice(PDO $pdo,int $id,array $config,?int $actor=null,string $source=''): ?int
{
    return pa_autopay_enqueue_document($pdo,'invoice',$id,$config,$actor,$source);
}
function pa_autopay_enqueue_project_invoice(PDO $pdo,int $id,array $config,?int $actor=null,string $source=''): ?int
{
    return pa_autopay_enqueue_document($pdo,'project_invoice',$id,$config,$actor,$source);
}

/** Only called at the original issuance boundary, never a scan of old receivables. */
function pa_autopay_enqueue_document(PDO $pdo,string $type,int $id,array $config,?int $actor,string $source): ?int
{
    if (!pa_autopay_enabled($config)) return null;
    if (!$pdo->inTransaction()) throw new LogicException('AutoPay reservation requires the issuance transaction.');
    if (!in_array($source,['manual_create','manual_finalize','on_demand_send','contract_complete','recurring_auto','project_finalize'],true)) return null;
    $table=pa_autopay_table($type);
    $s=$pdo->prepare("SELECT * FROM {$table} WHERE id=? FOR UPDATE");$s->execute([$id]);$invoice=$s->fetch(PDO::FETCH_ASSOC);
    if($invoice && $type==='project_invoice')$invoice['client_id']=$invoice['primary_client_id'];
    if (!$invoice || empty($invoice['client_id']) || empty($invoice['finalized_at']) || !in_array($invoice['status'],['unpaid','sent','overdue','partial'],true)
        || ($invoice['collection_mode']??'direct')!=='direct' || !empty($invoice['stripe_session_id'])
        || ($invoice['finalization_source']??$source)==='historical_recurring_payment') return null;
    // No previous payment, refund or settlement can be used to create a fresh debit.
    $payments=$type==='invoice'?'payments':'project_invoice_payments';$column=$type==='invoice'?'invoice_id':'project_invoice_id';
    $s=$pdo->prepare("SELECT id FROM {$payments} WHERE {$column}=? LIMIT 1");$s->execute([$id]);if($s->fetchColumn())return null;
    $s=$pdo->prepare('SELECT id FROM autopay_collections WHERE document_type=? AND document_id=?');$s->execute([$type,$id]);if($existing=$s->fetchColumn())return (int)$existing;
    $auth=pa_autopay_authorization_for_invoice($pdo,$invoice,$config);if(!$auth)return null;
    // Enrollment applies prospectively; the caller owns the original issuance boundary.
    if (strtotime((string)$invoice['finalized_at']) < strtotime((string)$auth['effective_from'])) return null;
    $effectiveDate=substr((string)$auth['effective_from'],0,10);
    if($source==='recurring_auto'){
        $s=$pdo->prepare('SELECT MIN(coverage_start) FROM recurring_invoice_coverage WHERE invoice_id=?');$s->execute([$id]);
        $period=(string)($s->fetchColumn()?:($config['_autopay_period_start']??''));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$period) || $period<$effectiveDate)return null;
    }
    if($type==='project_invoice' && (string)$invoice['billing_period_start']<$effectiveDate)return null;
    $amount=stripe_invoice_payment_metadata_cents(['amount'=>(string)$invoice['balance_due']],['amount']);
    if(!$amount || $amount<=0)return null;
    $currency=strtolower((string)($invoice['currency']??$config['workforce_currency']??'usd'));
    if(!in_array($currency,['usd','cad','eur','gbp','aud','nzd','chf'],true) || ($auth['method_type']==='us_bank_account' && $currency!=='usd'))return null;
    $key='pa-autopay-'.hash('sha256',$auth['installation_id'].':'.$auth['processor_account_id'].':'.$type.':'.$id);
    $s=$pdo->prepare('INSERT INTO autopay_collections(document_type,document_id,authorization_id,client_id,amount_cents,currency,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,idempotency_key,source,created_by,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $s->execute([$type,$id,$auth['id'],$invoice['client_id'],$amount,$currency,$auth['processor_account_id'],$auth['installation_id'],$auth['stripe_customer_id'],$auth['payment_method_id'],$auth['method_type'],$key,$source,$actor]);
    return (int)$pdo->lastInsertId();
}

/** One short reservation transaction; the network request always happens after commit. */
function pa_autopay_run_one(PDO $pdo,array $config,?callable $transport=null): array
{
    if(!pa_autopay_enabled($config))return ['status'=>'disabled'];
    if($pdo->inTransaction())throw new LogicException('Collection worker cannot run in another transaction.');
    // Read candidate without locking; document then collection is the shared lock order.
    $row=$pdo->query('SELECT * FROM autopay_collections WHERE state IN ("pending","processing") AND financial_hold=0 AND next_attempt_at<=NOW() AND (lease_until IS NULL OR lease_until<NOW()) ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if(!$row)return ['status'=>'idle'];
    $pdo->beginTransaction();
    try {
        $table=pa_autopay_table($row['document_type']);$s=$pdo->prepare("SELECT * FROM {$table} WHERE id=? FOR UPDATE");$s->execute([$row['document_id']]);$invoice=$s->fetch(PDO::FETCH_ASSOC);
        if($invoice && $row['document_type']==='project_invoice')$invoice['client_id']=$invoice['primary_client_id'];
        $s=$pdo->prepare('SELECT * FROM autopay_collections WHERE id=? FOR UPDATE');$s->execute([$row['id']]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!in_array($row['state'],['pending','processing'],true) || $row['financial_hold'] || (!empty($row['lease_until']) && strtotime($row['lease_until'])>=time()) || (!empty($row['next_attempt_at']) && strtotime($row['next_attempt_at'])>time())){$pdo->commit();return ['status'=>'busy'];}
        $s=$pdo->prepare('SELECT * FROM autopay_enrollments WHERE id=? FOR UPDATE');$s->execute([$row['authorization_id']]);$auth=$s->fetch(PDO::FETCH_ASSOC);
        $valid=$invoice && $auth && $auth['status']==='active' && empty($auth['revoked_at']) && !empty($auth['verified_at']) && !$auth['livemode']
            && (int)$auth['client_id']===(int)$invoice['client_id'] && (int)$row['client_id']===(int)$invoice['client_id']
            && $auth['installation_id']===(string)getenv('AUTOPAY_INSTALLATION_ID') && $auth['processor_account_id']===(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID')
            && $row['installation_id']===$auth['installation_id'] && $row['processor_account_id']===$auth['processor_account_id']
            && $row['stripe_customer_id']===$auth['stripe_customer_id'] && $row['payment_method_id']===$auth['payment_method_id']
            && $row['currency']===$auth['currency'] && ($auth['scope']==='business' || (int)$auth['contract_id']===(int)($invoice['contract_id']??0))
            && !empty($invoice['finalized_at']) && in_array($invoice['status'],['unpaid','sent','overdue','partial'],true) && empty($invoice['stripe_session_id'])
            && ($invoice['collection_mode']??'direct')==='direct' && stripe_invoice_payment_metadata_cents(['amount'=>(string)$invoice['balance_due']],['amount'])===(int)$row['amount_cents'];
        if($valid && $row['document_type']==='project_invoice')$valid=pa_autopay_project_scope_valid($pdo,$invoice,substr((string)$auth['effective_from'],0,10));
        // Stripe may prune idempotency keys after 24 hours. Unknown requests stop early, never recreate days later.
        $expired=empty($row['stripe_payment_intent_id']) && !empty($row['first_attempt_at']) && strtotime($row['first_attempt_at'])<time()-23*3600;
        if(!$valid || $expired){$state=(int)$row['attempt_count']>0?'held':'failed';$pdo->prepare('UPDATE autopay_collections SET state=?,last_error=?,next_attempt_at=NULL WHERE id=?')->execute([$state,$expired?'Unknown outcome exceeded safe retry window.':'Authorization or invoice changed before collection.',$row['id']]);$pdo->commit();return ['status'=>$state,'id'=>(int)$row['id']];}
        $token=bin2hex(random_bytes(16));$pdo->prepare('UPDATE autopay_collections SET state="processing",attempt_count=attempt_count+1,first_attempt_at=COALESCE(first_attempt_at,NOW()),lease_token=?,lease_until=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?')->execute([$token,$row['id']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    try {
        if($transport===null){require_once __DIR__.'/../services/StripeService.php';$stripe=StripeService::fromAppConfig($config);if(!$stripe)throw new RuntimeException('Test processor unavailable.');$transport=[$stripe,'collectAutoPay'];}
        $intent=$transport($row);
        pa_autopay_validate_intent($row,$intent);
        // Even a synchronous success waits for signed webhook accounting. Never mark ACH paid here.
        $state=match($intent['status']??''){'requires_action'=>'requires_action','requires_payment_method','canceled'=>'failed',default=>'processing'};
        $pdo->prepare('UPDATE autopay_collections SET stripe_payment_intent_id=?,state=?,lease_token=NULL,lease_until=NULL,next_attempt_at=NULL,last_error=NULL WHERE id=? AND lease_token=? AND state="processing"')->execute([$intent['id'],$state,$row['id'],$token]);
        return ['status'=>$state,'id'=>(int)$row['id']];
    }catch(Throwable $e){
        // Ambiguous network errors retain the reservation and the exact original request key.
        $pdo->prepare('UPDATE autopay_collections SET lease_token=NULL,lease_until=NULL,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),last_error="Processor response unavailable; retry uses original request identity." WHERE id=? AND lease_token=? AND state="processing"')->execute([$row['id'],$token]);
        return ['status'=>'retry','id'=>(int)$row['id']];
    }
}

function pa_autopay_validate_intent(array $row,array $intent): void
{
    $meta=$intent['metadata']??[];$doc=$row['document_type']==='invoice'?'pa_invoice_id':'pa_project_invoice_id';
    if(($intent['object']??'')!=='payment_intent' || !preg_match('/^pi_[A-Za-z0-9_]+$/',(string)($intent['id']??'')) || ($intent['livemode']??null)!==false
        || (!empty($row['stripe_payment_intent_id']) && $row['stripe_payment_intent_id']!==$intent['id'])
        || ($intent['amount']??null)!==(int)$row['amount_cents'] || ($intent['currency']??'')!==$row['currency']
        || ($intent['customer']??'')!==$row['stripe_customer_id'] || ($intent['payment_method']??'')!==$row['payment_method_id']
        || (string)($meta['pa_autopay_collection_id']??'')!==(string)$row['id'] || (string)($meta[$doc]??'')!==(string)$row['document_id']
        || (string)($meta['pa_client_id']??'')!==(string)$row['client_id']
        || !empty($meta[$row['document_type']==='invoice'?'pa_project_invoice_id':'pa_invoice_id'])
        || stripe_invoice_payment_metadata_cents($meta,['pa_invoice_amount'])!==(int)$row['amount_cents']
        || stripe_invoice_payment_metadata_cents($meta,['pa_surcharge_amount'])!==0
        || ($meta['pa_autopay_installation_id']??'')!==$row['installation_id'] || ($meta['pa_autopay_account_id']??'')!==$row['processor_account_id']
        || !empty($intent['on_behalf_of']) || !empty($intent['transfer_data']))throw new DomainException('Collection evidence does not match its reservation.');
}

/** Called only by the verified webhook router. Returns true when handled. */
function pa_autopay_handle_event(PDO $pdo,array $event,array $config,bool $signatureVerified=false): bool
{
    $intent=$event['data']['object']??[];$meta=$intent['metadata']??[];
    $collectionId=(int)($meta['pa_autopay_collection_id']??0);
    if(!$collectionId){
        require_once __DIR__.'/invoice_lifecycle.php';
        if(!invoice_table_has_column($pdo,'autopay_collections','state'))return false;
        $s=$pdo->prepare('SELECT id FROM autopay_collections WHERE stripe_payment_intent_id=?');$s->execute([(string)($intent['id']??'')]);$collectionId=(int)($s->fetchColumn()?:0);
        if(!$collectionId)return false;
    }
    if(!$signatureVerified)throw new DomainException('AutoPay requires a verified webhook signature.');
    if(!pa_autopay_test_context_enabled($config) || ($event['livemode']??null)!==false || !str_starts_with((string)$event['type'],'payment_intent.'))throw new DomainException('AutoPay event is outside the test-mode boundary.');
    $s=$pdo->prepare('SELECT * FROM autopay_collections WHERE id=?');$s->execute([$collectionId]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new DomainException('Unknown collection.');
    pa_autopay_validate_intent($row,$intent);
    if($row['installation_id']!==(string)getenv('AUTOPAY_INSTALLATION_ID') || $row['processor_account_id']!==(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID') || (!empty($event['account']) && $event['account']!==$row['processor_account_id']))throw new DomainException('Collection account mismatch.');
    $type=$event['type'];
    if($type==='payment_intent.succeeded'){
        if(($intent['status']??'')!=='succeeded' || ($intent['amount_received']??null)!==(int)$row['amount_cents'])throw new DomainException('Collection settlement evidence incomplete.');
        // Reuse the canonical accounting and receipt paths, including pending refunds/disputes.
        require_once __DIR__.'/../controllers/webhook/stripe_payment_succeeded.php';
        handlePaymentIntentSucceeded($pdo,$intent);
        $pdo->prepare('UPDATE autopay_collections SET state="succeeded",stripe_payment_intent_id=?,next_attempt_at=NULL,lease_token=NULL,lease_until=NULL,last_event_created=GREATEST(last_event_created,?) WHERE id=?')->execute([$intent['id'],(int)($event['created']??0),$row['id']]);
    }else{
        $state=match($type){'payment_intent.payment_failed','payment_intent.canceled'=>'failed','payment_intent.requires_action'=>'requires_action','payment_intent.processing'=>'processing',default=>null};
        // Failed attempts are not retried by this workflow. Delayed intermediate
        // events (including ties at Stripe's second precision) must not reserve
        // them again; verified settlement above remains authoritative.
        if($state!==null)$pdo->prepare('UPDATE autopay_collections SET state=?,stripe_payment_intent_id=?,next_attempt_at=NULL,lease_token=NULL,lease_until=NULL,last_event_created=? WHERE id=? AND state NOT IN ("succeeded","failed") AND last_event_created<=?')->execute([$state,$intent['id'],(int)($event['created']??0),$row['id'],(int)($event['created']??0)]);
    }
    return true;
}

/** Financial reversals never authorize a new charge, including events received before settlement. */
function pa_autopay_hold_financial_event(PDO $pdo,array $object): void
{
    $pi=$object['payment_intent']??null;if(is_array($pi))$pi=$pi['id']??null;
    if(!is_string($pi)||$pi==='')return;
    require_once __DIR__.'/invoice_lifecycle.php';if(!invoice_table_has_column($pdo,'autopay_collections','state'))return;
    $pdo->prepare('UPDATE autopay_collections SET financial_hold=1,next_attempt_at=NULL,last_error="Refund or dispute requires review." WHERE stripe_payment_intent_id=?')->execute([$pi]);
}

/** Release only an unsent reservation or a processor-confirmed canceled intent. */
function pa_autopay_cancel_collection(PDO $pdo,int $id,array $config,?callable $transport=null): void
{
    if(!pa_autopay_enabled($config))throw new DomainException('AutoPay test mode is disabled.');
    if($pdo->inTransaction())throw new LogicException('Cancellation owns its transaction.');
    $s=$pdo->prepare('SELECT * FROM autopay_collections WHERE id=?');$s->execute([$id]);$candidate=$s->fetch(PDO::FETCH_ASSOC);if(!$candidate)throw new DomainException('Collection not found.');
    $pdo->beginTransaction();
    try{
        $table=pa_autopay_table($candidate['document_type']);$s=$pdo->prepare("SELECT id FROM {$table} WHERE id=? FOR UPDATE");$s->execute([$candidate['document_id']]);
        $s=$pdo->prepare('SELECT * FROM autopay_collections WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!empty($row['financial_hold']))throw new DomainException('A refund or dispute must be reconciled before this collection can be released.');
        if($row['state']==='pending' && (int)$row['attempt_count']===0){$pdo->prepare('UPDATE autopay_collections SET state="failed",next_attempt_at=NULL,last_error="Canceled before submission." WHERE id=?')->execute([$id]);$pdo->commit();return;}
        if($row['state']!=='requires_action' || empty($row['stripe_payment_intent_id']))throw new DomainException('This collection must be reconciled with Stripe before another payment can be accepted.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($transport===null){require_once __DIR__.'/../services/StripeService.php';$stripe=StripeService::fromAppConfig($config);if(!$stripe)throw new DomainException('Stripe test connection unavailable.');$transport=static fn(array $r)=>$stripe->autoPayRequest('POST','payment_intents/'.rawurlencode($r['stripe_payment_intent_id']).'/cancel',[],$r['idempotency_key'].'-cancel');}
    $intent=$transport($row);pa_autopay_validate_intent($row,$intent);
    if(($intent['status']??'')!=='canceled')throw new DomainException('Stripe has not confirmed cancellation.');
    $pdo->prepare('UPDATE autopay_collections SET state="failed",next_attempt_at=NULL,last_error="Canceled by processor." WHERE id=? AND state="requires_action"')->execute([$id]);
}
