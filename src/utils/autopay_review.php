<?php
declare(strict_types=1);

require_once __DIR__ . '/invoice_lifecycle.php';
require_once __DIR__ . '/stripe_invoice_payment_amounts.php';
require_once __DIR__ . '/autopay_collection.php';

function pa_autopay_review_available(PDO $pdo): bool
{
    return invoice_table_has_column($pdo, 'autopay_invoice_reviews', 'generation');
}

/** Trusted configuration seam for deterministic calendar-boundary tests. */
function pa_autopay_review_now(array $config): DateTimeImmutable
{
    $now=$config['_autopay_review_now']??null;
    return ($now instanceof DateTimeImmutable ? $now : new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
}

/** The caller already holds the financial document lock. */
function pa_autopay_review_basis(PDO $pdo, string $type, array $document, array $config): array
{
    if(empty($document['finalized_at']) || !in_array($document['status']??'', ['unpaid','sent','partial','overdue'],true) || ($document['collection_mode']??'direct')!=='direct' || ($document['recipient_presentation_mode']??'named')==='general')throw new DomainException('Invoice is not eligible for an AutoPay review.');
    $client = (int)($document[$type === 'invoice' ? 'client_id' : 'primary_client_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT public_id,email,archived FROM clients WHERE id=? FOR UPDATE');
    $stmt->execute([$client]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$recipient || $recipient['archived'] || !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) throw new DomainException('AutoPay review needs the current client email.');
    foreach ([$document['public_id'] ?? '', $recipient['public_id'] ?? ''] as $identity) {
        if (!preg_match('/^[a-f0-9]{32}$/D', (string)$identity)) throw new DomainException('AutoPay review needs stable document and client identities.');
    }
    $currency = strtolower((string)($document['currency'] ?? $config['workforce_currency'] ?? 'usd'));
    if (!in_array($currency, ['usd','cad','eur','gbp','aud','nzd','chf'], true)) throw new DomainException('Unsupported AutoPay review currency.');
    $timezone = (string)($config['timezone'] ?? 'UTC');
    new DateTimeZone($timezone);
    $amount = stripe_invoice_payment_metadata_cents(['amount'=>(string)($document['balance_due'] ?? '0')], ['amount']) ?? 0;
    if ($amount <= 0) throw new DomainException('AutoPay review requires an outstanding balance.');
    $basis = [
        'document_type'=>$type, 'document_public_id'=>$document['public_id'], 'client_public_id'=>$recipient['public_id'],
        'revision_number'=>(int)($document['revision_number'] ?? 1), 'amount_cents'=>$amount, 'currency'=>$currency,
        'due_date'=>($document['due_date'] ?? null) ?: null, 'timezone'=>$timezone, 'recipient'=>strtolower(trim($recipient['email'])),
    ];
    $basis['basis_hash'] = hash('sha256', json_encode([$basis, (string)($document['total'] ?? ''), (string)($document['finalized_at'] ?? ''), (string)($document['contract_id'] ?? '')], JSON_THROW_ON_ERROR));
    return $basis;
}

function pa_autopay_review_latest(PDO $pdo, int $collectionId, bool $lock = false): ?array
{
    if (!pa_autopay_review_available($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM autopay_invoice_reviews WHERE collection_id=? ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$collectionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Never substitute a newer authorization for the consent captured at issuance. */
function pa_autopay_review_authorized(PDO $pdo, array $collection, array $document, array $basis, array $config): bool
{
    require_once __DIR__.'/autopay_beta.php';
    if(!pa_autopay_test_context_enabled($config))return false;
    $stmt=$pdo->prepare('SELECT * FROM autopay_enrollments WHERE id=? FOR UPDATE');
    $stmt->execute([$collection['authorization_id']]);$auth=$stmt->fetch(PDO::FETCH_ASSOC);
    $client=(int)($document[$collection['document_type']==='invoice'?'client_id':'primary_client_id']??0);
    return $auth && $auth['status']==='active' && !$auth['revoked_at'] && $auth['verified_at'] && !$auth['livemode']
        && (int)$auth['client_id']===$client && (int)$collection['client_id']===$client
        && strtolower(trim((string)$auth['verified_email']))===$basis['recipient']
        && $auth['installation_id']===(string)getenv('AUTOPAY_INSTALLATION_ID') && $auth['processor_account_id']===(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID')
        && $auth['installation_id']===$collection['installation_id'] && $auth['processor_account_id']===$collection['processor_account_id']
        && $auth['payment_method_id']===$collection['payment_method_id'] && $auth['stripe_customer_id']===$collection['stripe_customer_id']
        && $auth['method_type']===$collection['method_type'] && in_array($auth['method_type'],['card','us_bank_account'],true)
        && strtolower($auth['currency'])===$basis['currency'] && strtolower($collection['currency'])===$basis['currency']
        && (($auth['scope']==='business' && $auth['contract_id']===null) || ($auth['scope']==='agreement' && (int)$auth['contract_id']>0 && (int)$auth['contract_id']===(int)($document['contract_id']??0)))
        && ($collection['document_type']!=='project_invoice' || pa_autopay_project_scope_valid($pdo,$document,substr((string)$auth['effective_from'],0,10)));
}

function pa_autopay_review_cancel_invalid_authorization(PDO $pdo, array $collection): void
{
    if($collection['state']==='pending' && (int)$collection['attempt_count']===0 && !$collection['stripe_payment_intent_id'] && !$collection['lease_token'] && !$collection['financial_hold']) {
        $pdo->prepare('UPDATE autopay_collections SET state="failed",next_attempt_at=NULL,last_error="Canceled: AutoPay authorization is no longer active." WHERE id=?')->execute([$collection['id']]);
    }
}

/** Used only by new issuance; never manufacture reviews for legacy queued rows. */
function pa_autopay_review_create(PDO $pdo, int $collectionId, string $type, array $document, array $config): array
{
    if (!pa_autopay_review_available($pdo)) throw new DomainException('AutoPay invoice review requires the database upgrade.');
    $basis = pa_autopay_review_basis($pdo, $type, $document, $config);
    $generation = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO autopay_invoice_reviews(collection_id,document_type,document_public_id,client_public_id,generation,management_token,basis_hash,revision_number,amount_cents,currency,due_date,timezone,recipient) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$collectionId,$type,$basis['document_public_id'],$basis['client_public_id'],$generation,bin2hex(random_bytes(32)),$basis['basis_hash'],$basis['revision_number'],$basis['amount_cents'],$basis['currency'],$basis['due_date'],$basis['timezone'],$basis['recipient']]);
    $pdo->prepare('UPDATE autopay_collections SET next_attempt_at=NULL WHERE id=?')->execute([$collectionId]);
    return pa_autopay_review_latest($pdo, $collectionId, true);
}

/** No network while these locks are held. Public callers must verify link/OTP first. */
function pa_autopay_review_lock(PDO $pdo, string $type, int $id): array
{
    require_once __DIR__ . '/autopay_collection.php';
    $table = pa_autopay_table($type);
    if ($type === 'invoice') pa_autopay_lock_parent_for_invoice_mutation($pdo, $id, false);
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? FOR UPDATE");
    $stmt->execute([$id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) throw new DomainException('Invoice not found.');
    $stmt = $pdo->prepare('SELECT * FROM autopay_collections WHERE document_type=? AND document_id=? FOR UPDATE');
    $stmt->execute([$type,$id]);
    $collection = $stmt->fetch(PDO::FETCH_ASSOC);
    return [$document,$collection ?: null];
}

/** Explicit invoice-notice transport only; do not call for payment receipts. */
function pa_autopay_prepare_invoice_notice(PDO $pdo, string $type, int $id, array $config, string $recipient): ?array
{
    require_once __DIR__ . '/autopay_beta.php';
    if (!pa_autopay_enabled($config)) return null;
    if ($pdo->inTransaction()) throw new LogicException('Prepare invoice notice outside the financial transaction.');
    $pdo->beginTransaction();
    try {
        [$document,$collection] = pa_autopay_review_lock($pdo,$type,$id);
        if (!$collection || $collection['state'] !== 'pending' || (int)$collection['attempt_count'] !== 0 || $collection['financial_hold']) { $pdo->commit(); return null; }
        $review = pa_autopay_review_latest($pdo,(int)$collection['id'],true);
        if (!$review || !in_array($review['status'],['awaiting_delivery','scheduled'],true)) { $pdo->commit(); return null; }
        $basis = pa_autopay_review_basis($pdo,$type,$document,$config);
        if(!pa_autopay_review_authorized($pdo,$collection,$document,$basis,$config)){pa_autopay_review_cancel_invalid_authorization($pdo,$collection);$pdo->commit();return null;}
        if ($review['document_public_id'] !== $basis['document_public_id'] || $review['client_public_id'] !== $basis['client_public_id']) throw new DomainException('AutoPay review identity changed. Staff must review this invoice.');
        if ($basis['recipient'] !== strtolower(trim($recipient))) { $pdo->commit(); return null; }
        if (!hash_equals($review['basis_hash'],$basis['basis_hash'])) {
            $pdo->prepare('UPDATE autopay_invoice_reviews SET status="superseded" WHERE id=?')->execute([$review['id']]);
            $review = pa_autopay_review_create($pdo,(int)$collection['id'],$type,$document,$config);
            $pdo->prepare('UPDATE autopay_collections SET amount_cents=?,currency=? WHERE id=?')->execute([$basis['amount_cents'],$basis['currency'],$collection['id']]);
        }
        $amount = number_format((int)$review['amount_cents']/100,2,'.',',') . ' ' . strtoupper($review['currency']);
        $text = 'AutoPay review: ' . $amount . ' is scheduled no earlier than seven calendar days after this invoice notice is successfully delivered, or the invoice due date if later. You may skip AutoPay for this invoice or ask a billing question before collection starts. This invoice is not paid yet.';
        if ($review['scheduled_at']) $text = 'AutoPay review: ' . $amount . ' is scheduled on or after ' . (new DateTimeImmutable($review['scheduled_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($review['timezone']))->format('M j, Y g:i A T') . '. You may skip AutoPay for this invoice or ask a billing question before collection starts. This invoice is not paid yet.';
        if (!$review['scheduled_at']) {
            $earliest=pa_autopay_review_now($config)->setTimezone(new DateTimeZone($review['timezone']))->modify('+7 days');
            if($review['due_date']){$due=new DateTimeImmutable($review['due_date'].' 00:00:00',new DateTimeZone($review['timezone']));if($due>$earliest)$earliest=$due;}
            $text='AutoPay review: '.$amount.' is scheduled on or after '.$earliest->format('M j, Y').' and at least seven calendar days after successful delivery of this invoice notice. No action is needed to use AutoPay. You can skip AutoPay for this invoice or ask a billing question before collection starts. This invoice is not paid yet.';
        }
        $method=$collection['method_type']==='us_bank_account'?'bank account':'card';
        $auth=$pdo->prepare('SELECT last4 FROM autopay_enrollments WHERE id=?');$auth->execute([$collection['authorization_id']]);$last4=(string)$auth->fetchColumn();
        $text.=' Payment method: '.$method.(preg_match('/^[0-9]{4}$/D',$last4)?' ending '.$last4:' saved with Stripe').'.';
        $host=rtrim((string)($config['app_host']??''),'/');
        if(!preg_match('~^https?://~i',$host))throw new DomainException('Invoice review requires a configured public app URL.');
        $url=$host.'/?page=public-autopay&review='.$review['management_token'];
        $text.=' If everything looks correct, no action is needed. Your saved payment method will be charged automatically.';
        $html='<div style="border:2px solid #2463a6;border-radius:8px;padding:18px;margin:16px 0;background:#f2f7fc"><h2 style="margin-top:0">You are set up with AutoPay</h2><p>'.htmlspecialchars($text,ENT_QUOTES,'UTF-8').'</p><p><a href="'.htmlspecialchars($url.'#invoice-review',ENT_QUOTES,'UTF-8').'">Don’t automatically pay this invoice</a> · <a href="'.htmlspecialchars($url.'#invoice-review',ENT_QUOTES,'UTF-8').'">Question this invoice</a> · <a href="'.htmlspecialchars($url.'#autopay-account',ENT_QUOTES,'UTF-8').'">Turn off all AutoPay</a></p></div>';
        $pdo->commit();
        return ['review_id'=>(int)$review['id'],'generation'=>$review['generation'],'basis_hash'=>$review['basis_hash'],'document_type'=>$type,'document_id'=>$id,'action_url'=>$url,'text'=>$text.' Review this invoice or turn off AutoPay: '.$url,'html'=>$html];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** SMTP acceptance is evidence only for the exact generation actually sent. */
function pa_autopay_mark_invoice_notice_sent(PDO $pdo, array $notice, string $recipient, ?int $deliveryLogId = null, array $config = []): bool
{
    if ($pdo->inTransaction()) throw new LogicException('Record delivery outside the financial transaction.');
    $pdo->beginTransaction();
    try {
        [$document,$collection] = pa_autopay_review_lock($pdo,(string)$notice['document_type'],(int)$notice['document_id']);
        $review = $collection ? pa_autopay_review_latest($pdo,(int)$collection['id'],true) : null;
        if (!$review || $collection['state'] !== 'pending' || (int)$collection['attempt_count'] !== 0 || $collection['financial_hold'] || !in_array($review['status'],['awaiting_delivery','scheduled'],true)
            || (int)$notice['review_id'] !== (int)$review['id'] || !hash_equals($review['generation'],(string)$notice['generation']) || $review['recipient'] !== strtolower(trim($recipient))) { $pdo->commit(); return false; }
        $basis = pa_autopay_review_basis($pdo,(string)$notice['document_type'],$document,$config);
        if(!pa_autopay_review_authorized($pdo,$collection,$document,$basis,$config)){pa_autopay_review_cancel_invalid_authorization($pdo,$collection);$pdo->commit();return false;}
        if (!hash_equals($review['basis_hash'],$basis['basis_hash'])) {
            $pdo->prepare('UPDATE autopay_collections SET state="held",next_attempt_at=NULL,last_error="Invoice changed during delivery; a new review notice is required." WHERE id=?')->execute([$collection['id']]);
            $pdo->prepare('UPDATE autopay_invoice_reviews SET status="held" WHERE id=?')->execute([$review['id']]);
            $pdo->commit(); return false;
        }
        if (!$review['delivered_at']) {
            $now = pa_autopay_review_now($config);
            if($deliveryLogId!==null){
                $stmt=$pdo->prepare('SELECT status,recipient,message_key,document_type,document_id,sent_at FROM email_delivery_log WHERE id=?');
                $stmt->execute([$deliveryLogId]);$log=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$log || $log['status']!=='sent' || !$log['sent_at'] || strtolower(trim($log['recipient']))!==$review['recipient'] || $log['document_type']!==$notice['document_type'] || (int)$log['document_id']!==(int)$notice['document_id'] || !str_ends_with($log['message_key'],':autopay-review:'.$review['id']))throw new DomainException('Invoice delivery evidence does not match this review.');
                $now=new DateTimeImmutable($log['sent_at'],new DateTimeZone($review['timezone']));
                $now=$now->setTimezone(new DateTimeZone('UTC'));
                if($now>pa_autopay_review_now($config))throw new DomainException('Invoice delivery timestamp is in the future.');
            }
            $local = $now->setTimezone(new DateTimeZone($review['timezone']));
            $scheduled = $local->modify('+7 days');
            if ($review['due_date']) { $due = new DateTimeImmutable($review['due_date'].' 00:00:00',new DateTimeZone($review['timezone'])); if ($due > $scheduled) $scheduled = $due; }
            $utc = $scheduled->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE autopay_invoice_reviews SET status="scheduled",delivered_at=?,scheduled_at=?,delivery_log_id=? WHERE id=?')->execute([$now->format('Y-m-d H:i:s'),$utc,$deliveryLogId,$review['id']]);
            $pdo->prepare('UPDATE autopay_collections SET next_attempt_at=? WHERE id=?')->execute([$utc,$collection['id']]);
        }
        $pdo->commit(); return true;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Called under document/collection locks before any first provider submission. */
function pa_autopay_review_worker_ready(PDO $pdo, array $collection, array $document, array $config): bool
{
    $review = pa_autopay_review_latest($pdo,(int)$collection['id'],true);
    try { $basis = pa_autopay_review_basis($pdo,$collection['document_type'],$document,$config); } catch (Throwable $error) { $basis = null; }
    if($basis && !pa_autopay_review_authorized($pdo,$collection,$document,$basis,$config)){
        pa_autopay_review_cancel_invalid_authorization($pdo,$collection);
        if((int)$collection['attempt_count']>0)$pdo->prepare('UPDATE autopay_collections SET state="held",next_attempt_at=NULL,last_error="Authorization changed after submission; reconcile the existing payment." WHERE id=?')->execute([$collection['id']]);
        return false;
    }
    if($review && $basis && hash_equals($review['basis_hash'],$basis['basis_hash']) && $review['status']==='awaiting_delivery' && (int)$collection['attempt_count']===0){
        $pdo->prepare('UPDATE autopay_collections SET next_attempt_at=NULL WHERE id=?')->execute([$collection['id']]);
        return false;
    }
    $valid = $review && $basis && hash_equals($review['basis_hash'],$basis['basis_hash']) && $review['status'] === 'scheduled' && $review['delivered_at'] && $review['scheduled_at']
        && (int)$review['amount_cents'] === (int)$collection['amount_cents'] && $review['currency'] === $collection['currency'];
    if (!$valid) {
        $pdo->prepare('UPDATE autopay_collections SET state="held",next_attempt_at=NULL,last_error="Invoice review or current delivery evidence is missing; staff review required." WHERE id=?')->execute([$collection['id']]);
        if($review && !in_array($review['status'],['skipped','question'],true))$pdo->prepare('UPDATE autopay_invoice_reviews SET status="held" WHERE id=?')->execute([$review['id']]);
        return false;
    }
    if (new DateTimeImmutable($review['scheduled_at'],new DateTimeZone('UTC')) > pa_autopay_review_now($config)) {
        $pdo->prepare('UPDATE autopay_collections SET next_attempt_at=? WHERE id=?')->execute([$review['scheduled_at'],$collection['id']]);
        return false;
    }
    $table = $collection['document_type'] === 'invoice' ? 'payments' : 'project_invoice_payments';
    $column = $collection['document_type'] === 'invoice' ? 'invoice_id' : 'project_invoice_id';
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$column}=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$collection['document_id']]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare('UPDATE autopay_collections SET state="held",next_attempt_at=NULL,last_error="Payment activity requires invoice review." WHERE id=?')->execute([$collection['id']]);
        if(!in_array($review['status'],['skipped','question'],true))$pdo->prepare('UPDATE autopay_invoice_reviews SET status="held" WHERE id=?')->execute([$review['id']]);
        return false;
    }
    return true;
}

/** Bearer capability locates the record only; callers must still require fresh client OTP. */
function pa_autopay_invoice_review_context(PDO $pdo, string $token, array $config): array
{
    require_once __DIR__.'/autopay_beta.php';
    if(!pa_autopay_test_context_enabled($config) || !preg_match('/^[a-f0-9]{64}$/D',$token) || !pa_autopay_review_available($pdo))throw new DomainException('Invoice review is unavailable.');
    $stmt=$pdo->prepare('SELECT r.*,c.document_id,c.authorization_id,c.client_id,c.processor_account_id,c.installation_id,c.state AS collection_state,c.attempt_count,c.financial_hold FROM autopay_invoice_reviews r JOIN autopay_collections c ON c.id=r.collection_id WHERE r.management_token=?');
    $stmt->execute([$token]);$review=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$review || $review['processor_account_id']!==(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID') || $review['installation_id']!==(string)getenv('AUTOPAY_INSTALLATION_ID'))throw new DomainException('Invoice review is unavailable.');
    $latest=pa_autopay_review_latest($pdo,(int)$review['collection_id']);
    if(!$latest || (int)$latest['id']!==(int)$review['id'] || $review['status']==='superseded')throw new DomainException('This invoice review was replaced. Use the latest invoice notice.');
    $table=$review['document_type']==='invoice'?'invoices':'project_invoices';
    $column=$review['document_type']==='invoice'?'client_id':'primary_client_id';
    $stmt=$pdo->prepare("SELECT d.id,d.{$column} AS client_id FROM {$table} d WHERE d.public_id=? AND d.id=?");
    $stmt->execute([$review['document_public_id'],$review['document_id']]);$doc=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$doc || (int)$doc['client_id']!==(int)$review['client_id'])throw new DomainException('Invoice review requires staff assistance.');
    $stmt=$pdo->prepare('SELECT c.id,c.name,c.email FROM clients c JOIN autopay_enrollments a ON a.client_id=c.id WHERE c.id=? AND c.public_id=? AND c.archived=0 AND a.id=? AND a.livemode=0 AND a.installation_id=? AND a.processor_account_id=?');
    $stmt->execute([$doc['client_id'],$review['client_public_id'],$review['authorization_id'],$review['installation_id'],$review['processor_account_id']]);$client=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$client || !filter_var($client['email'],FILTER_VALIDATE_EMAIL))throw new DomainException('Invoice review is unavailable.');
    return ['client'=>$client,'contract_id'=>0,'review'=>['id'=>(int)$review['id'],'document_type'=>$review['document_type'],'document_id'=>(int)$doc['id'],'status'=>$review['status'],'state'=>$review['collection_state'],'amount_cents'=>(int)$review['amount_cents'],'currency'=>$review['currency'],'scheduled_at'=>$review['scheduled_at'],'timezone'=>$review['timezone'],'question_text'=>$review['question_text'],'can_act'=>(int)$review['attempt_count']===0 && !$review['financial_hold'] && in_array($review['collection_state'],['pending','held'],true)]];
}

/** Masked projection for authenticated staff and the public invoice banner. */
function pa_autopay_invoice_review_state(PDO $pdo, string $type, int $id): ?array
{
    if(!pa_autopay_review_available($pdo))return null;
    require_once __DIR__.'/autopay_collection.php';$table=pa_autopay_table($type);
    $stmt=$pdo->prepare("SELECT r.* FROM {$table} d JOIN autopay_collections c ON c.document_id=d.id AND c.document_type=? JOIN autopay_invoice_reviews r ON r.collection_id=c.id AND r.document_public_id=d.public_id WHERE d.id=? ORDER BY r.id DESC LIMIT 1");
    $stmt->execute([$type,$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    return ['review_id'=>(int)$row['id'],'review_status'=>$row['status'],'scheduled_at'=>$row['scheduled_at'],'timezone'=>$row['timezone'],'delivered_at'=>$row['delivered_at'],'question_text'=>$row['question_text'],'review_amount_cents'=>(int)$row['amount_cents'],'review_currency'=>$row['currency']];
}

/** Explicit staff resolution creates a fresh delivery window; it cannot override a client skip. */
function pa_autopay_invoice_review_staff_restart(PDO $pdo, string $type, int $id, int $actorId, array $config, string $note): array
{
    if($actorId<=0 || trim($note)==='')throw new DomainException('A staff resolution note is required.');
    if($pdo->inTransaction())throw new LogicException('Review resolution needs its own transaction.');
    $pdo->beginTransaction();
    try{
        [$document,$collection]=pa_autopay_review_lock($pdo,$type,$id);
        $review=$collection?pa_autopay_review_latest($pdo,(int)$collection['id'],true):null;
        if(!$review || $review['status']==='skipped' || $collection['state']!=='held' || $collection['financial_hold'] || (int)$collection['attempt_count']!==0 || $collection['stripe_payment_intent_id'] || $collection['lease_token'])throw new DomainException('This collection cannot be restarted. A client skip or submitted payment must not be overridden.');
        $basis=pa_autopay_review_basis($pdo,$type,$document,$config);
        if(!pa_autopay_enabled($config) || !pa_autopay_review_authorized($pdo,$collection,$document,$basis,$config))throw new DomainException('Original AutoPay authorization is no longer active.');
        if($basis['document_public_id']!==$review['document_public_id'] || $basis['client_public_id']!==$review['client_public_id'])throw new DomainException('Invoice identity changed.');
        require_once __DIR__.'/check_reports.php';
        if(pa_check_report_lookup($pdo,$type,$id))throw new DomainException('A check report prevents restarting AutoPay.');
        pa_check_report_assert_available($pdo,$type,$id);
        $payments=$type==='invoice'?'payments':'project_invoice_payments';
        $column=$type==='invoice'?'invoice_id':'project_invoice_id';
        $history=$pdo->prepare("SELECT id FROM {$payments} WHERE {$column}=? LIMIT 1 FOR UPDATE");
        $history->execute([$id]);
        if($history->fetchColumn())throw new DomainException('Payment activity prevents restarting AutoPay for this invoice.');
        $pdo->prepare('UPDATE autopay_invoice_reviews SET status="superseded",resolution_text=?,resolved_by=?,resolved_at=UTC_TIMESTAMP() WHERE id=?')->execute([mb_substr(trim($note),0,2000),$actorId,$review['id']]);
        $next=pa_autopay_review_create($pdo,(int)$collection['id'],$type,$document,$config);
        $pdo->prepare('UPDATE autopay_collections SET state="pending",amount_cents=?,currency=?,last_error=NULL,next_attempt_at=NULL WHERE id=?')->execute([$basis['amount_cents'],$basis['currency'],$collection['id']]);
        $pdo->commit();return $next;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function pa_autopay_invoice_review_action(PDO $pdo, string $type, int $id, int $clientId, string $action, array $config, string $note = ''): array
{
    if (!in_array($action,['skip','question'],true)) throw new DomainException('Invalid invoice review action.');
    if ($pdo->inTransaction()) throw new LogicException('Invoice review action needs its own transaction.');
    $pdo->beginTransaction();
    try {
        [$document,$collection] = pa_autopay_review_lock($pdo,$type,$id);
        $review = $collection ? pa_autopay_review_latest($pdo,(int)$collection['id'],true) : null;
        $basis = pa_autopay_review_basis($pdo,$type,$document,$config);
        if (!$review || $clientId <= 0 || $clientId !== (int)($document[$type === 'invoice' ? 'client_id' : 'primary_client_id'] ?? 0) || $review['client_public_id'] !== $basis['client_public_id'] || $review['document_public_id'] !== $basis['document_public_id']) throw new DomainException('Invoice review is unavailable.');
        if($review['status']==='skipped' && $action==='skip' && $collection['state']==='failed' && (int)$collection['attempt_count']===0 && !$collection['stripe_payment_intent_id'] && !$collection['lease_token'] && !$collection['financial_hold']){$pdo->commit();return $review;}
        if ((int)$collection['attempt_count'] !== 0 || !empty($collection['stripe_payment_intent_id']) || !empty($collection['lease_token']) || $collection['financial_hold'] || !in_array($collection['state'],['pending','held'],true)) throw new DomainException('Payment submission has started or needs staff review. Contact the business.');
        $status = $action === 'skip' ? 'skipped' : 'question';
        if ($review['status'] !== 'skipped' && ($review['status'] !== 'question' || $action === 'skip')) {
            $pdo->prepare('UPDATE autopay_invoice_reviews SET status=?,question_text=? WHERE id=?')->execute([$status,mb_substr(trim($note),0,2000),$review['id']]);
            $pdo->prepare('UPDATE autopay_collections SET state=?,next_attempt_at=NULL,last_error=? WHERE id=?')->execute([$action === 'skip' ? 'failed' : 'held',$action === 'skip' ? 'Client opted out of automatic payment.' : 'Client raised an invoice billing question.',$collection['id']]);
        }
        $result = pa_autopay_review_latest($pdo,(int)$collection['id'],true);
        $pdo->commit(); return $result;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
