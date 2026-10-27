<?php
declare(strict_types=1);
require_once __DIR__.'/autopay_collection.php';
require_once __DIR__.'/../services/StripeService.php';

/** Recognize reservations before generic imports; metadata alone never authorizes settlement. */
function pa_autopay_reconciliation_collection(PDO $pdo,array $intent): ?array
{
    $metadata=$intent['metadata']??[];$marked=array_key_exists('pa_autopay_collection_id',$metadata);
    $raw=$metadata['pa_autopay_collection_id']??null;
    if($marked && (!is_string($raw) || !preg_match('/^[1-9][0-9]*$/D',$raw) || filter_var($raw,FILTER_VALIDATE_INT)===false))throw new DomainException('Invalid AutoPay collection reference.');
    if(!invoice_table_has_column($pdo,'autopay_collections','state')){
        if($marked)throw new DomainException('AutoPay collection storage is unavailable.');
        return null;
    }
    $id=(int)($raw??0);
    $s=$pdo->prepare('SELECT * FROM autopay_collections WHERE stripe_payment_intent_id=? OR id=? ORDER BY id');
    $s->execute([(string)($intent['id']??''),$id]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1 || ($id && !$rows))throw new DomainException('AutoPay reconciliation identity is ambiguous or unknown.');
    return $rows[0]??null;
}

/** Fresh authenticated provider GET evidence, not a fabricated signed webhook. Never creates/captures a payment. */
function pa_autopay_reconcile_collection(PDO $pdo,StripeService $stripe,array $config,array $row,?string $discoveredId=null): bool
{
    if(!pa_autopay_test_context_enabled($config))throw new DomainException('AutoPay reconciliation requires its configured test context.');
    $context=$stripe->getReconciliationContext();
    if(($context['livemode']??null)!==false || ($context['account_id']??'')!==$row['processor_account_id']
        || $row['processor_account_id']!==(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID')
        || $row['installation_id']!==(string)getenv('AUTOPAY_INSTALLATION_ID'))throw new DomainException('AutoPay reconciliation account mismatch.');
    $piId=(string)($row['stripe_payment_intent_id']?:$discoveredId);
    if(!preg_match('/^pi_[A-Za-z0-9_]+$/D',$piId))throw new DomainException('AutoPay reconciliation needs a known PaymentIntent.');
    $intent=$stripe->getPaymentIntentWithBalanceTransaction($piId);
    if(($intent['id']??'')!==$piId)throw new DomainException('AutoPay reconciliation intent mismatch.');
    pa_autopay_validate_intent($row,$intent);
    $hold=$pdo->prepare('SELECT financial_hold FROM autopay_collections WHERE id=?');$hold->execute([$row['id']]);
    if($hold->fetchColumn())throw new DomainException('AutoPay financial hold requires review.');
    if(($intent['status']??'')!=='succeeded'){
        $state=match($intent['status']??''){'requires_action'=>'requires_action','requires_payment_method','canceled'=>'failed',default=>null};
        if($state===null)return false;
        // Compare the durable snapshot after the provider GET. Never regress a
        // newer webhook, settled payment, terminal failure or financial hold.
        $update=$pdo->prepare('UPDATE autopay_collections SET state=?,stripe_payment_intent_id=?,next_attempt_at=NULL,lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND state NOT IN ("succeeded","failed") AND financial_hold=0 AND state=? AND last_event_created=? AND updated_at=?');
        $update->execute([$state,$piId,$intent['status']==='canceled'?'Canceled by processor.':null,$row['id'],$row['state'],$row['last_event_created'],$row['updated_at']]);
        return $update->rowCount()>0;
    }
    if(($intent['amount_received']??null)!==(int)$row['amount_cents'])throw new DomainException('AutoPay settlement amount is incomplete.');
    $charge=$intent['latest_charge']??null;
    if(is_array($charge) && (!empty($charge['amount_refunded']) || !empty($charge['disputed']) || !empty($charge['refunds']['data']))) {
        // Preserve a review hold even when the refund/dispute webhook was also missed.
        $pdo->prepare('UPDATE autopay_collections SET financial_hold=1,next_attempt_at=NULL,last_error="Refund or dispute requires review." WHERE id=?')->execute([$row['id']]);
        throw new DomainException('AutoPay financial reversal requires review.');
    }
    // Preserve legacy accounting configuration; $config above gates collection identity.
    require_once __DIR__.'/stripe_payment_settlement.php';
    $accountingError=null;
    try{stripe_settle_payment_intent($pdo,$intent,$GLOBALS['appConfig'] ?? []);}catch(Throwable $error){$accountingError=$error;}
    $table=$row['document_type']==='invoice'?'payments':'project_invoice_payments';
    $field=$row['document_type']==='invoice'?'invoice_id':'project_invoice_id';
    $cents=(int)$row['amount_cents'];$amount=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    $payment=$pdo->prepare("SELECT id FROM $table WHERE $field=? AND stripe_payment_intent_id=? AND status=\"succeeded\" AND amount=?");$payment->execute([$row['document_id'],$piId,$amount]);
    if(!$payment->fetchColumn())throw $accountingError??new DomainException('AutoPay canonical settlement is not available.');
    // A post-commit mail failure must not prevent the durable receipt outbox
    // from observing settlement. Other accounting failures remain errors.
    if($accountingError && !str_starts_with($accountingError->getMessage(),'Payment email delivery failed:'))throw $accountingError;
    // Accounting is idempotent; a crash before this update is repaired on the next scan.
    // Never clear financial_hold or event ordering evidence while repairing settlement.
    $pdo->prepare('UPDATE autopay_collections SET state="succeeded",stripe_payment_intent_id=?,next_attempt_at=NULL,lease_token=NULL,lease_until=NULL WHERE id=?')->execute([$piId,$row['id']]);
    return true;
}

/** Durable IDs cover ACH settlement long after the provider's creation-time listing window. */
function pa_autopay_reconcile_known_collections(PDO $pdo,StripeService $stripe,array $config,int $limit=100): array
{
    $result=['checked'=>0,'reconciled'=>0,'errors'=>0];
    if(!pa_autopay_test_context_enabled($config) || !invoice_table_has_column($pdo,'autopay_collections','state'))return $result;
    $s=$pdo->prepare('SELECT * FROM autopay_collections WHERE stripe_payment_intent_id IS NOT NULL AND state<>"succeeded" AND financial_hold=0 AND installation_id=? AND processor_account_id=? ORDER BY updated_at,id LIMIT '.max(1,min(1000,$limit)));
    $s->execute([(string)getenv('AUTOPAY_INSTALLATION_ID'),(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID')]);
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
        $result['checked']++;
        try{if(pa_autopay_reconcile_collection($pdo,$stripe,$config,$row))$result['reconciled']++;}
        catch(Throwable){$result['errors']++;}
        $pdo->prepare('UPDATE autopay_collections SET updated_at=NOW() WHERE id=?')->execute([$row['id']]);
    }
    return $result;
}
