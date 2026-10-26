<?php
declare(strict_types=1);
require_once __DIR__.'/autopay_beta.php';

function pa_autopay_management_context(PDO $pdo,string $token):array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new DomainException('This management link is unavailable.');
    $s=$pdo->prepare('SELECT a.contract_id,c.id,c.name,c.email FROM autopay_enrollments a JOIN clients c ON c.id=a.client_id WHERE a.management_token=? AND a.installation_id=? AND a.processor_account_id=?');$s->execute([$token,getenv('AUTOPAY_INSTALLATION_ID'),getenv('AUTOPAY_STRIPE_ACCOUNT_ID')]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row||!filter_var($row['email'],FILTER_VALIDATE_EMAIL))throw new DomainException('This management link is unavailable.');
    return ['client'=>['id'=>(int)$row['id'],'name'=>$row['name'],'email'=>$row['email']],'contract_id'=>(int)($row['contract_id']??0)];
}

function pa_autopay_context(PDO $pdo,string $token): array {
    $s=$pdo->prepare('SELECT * FROM public_links WHERE token=? AND revoked=0 AND (expires_at IS NULL OR expires_at>NOW())');$s->execute([$token]);$link=$s->fetch(PDO::FETCH_ASSOC);
    if (!$link || !in_array($link['document_type'],['invoice','contract','project_invoice'],true)) throw new DomainException('This document link is unavailable.');
    $table=['invoice'=>'invoices','contract'=>'contracts','project_invoice'=>'project_invoices'][$link['document_type']];
    $s=$pdo->prepare("SELECT * FROM $table WHERE id=?");$s->execute([$link['document_id']]);$doc=$s->fetch(PDO::FETCH_ASSOC);
    if (!$doc || in_array($doc['status'],['void','cancelled','canceled'],true) || (!empty($link['expire_when_paid']) && $doc['status']==='paid') || ($link['document_type']==='invoice' && ($doc['recipient_presentation_mode']??'named')==='general')) throw new DomainException('This document link is unavailable.');
    $s=$pdo->prepare('SELECT id,name,email FROM clients WHERE id=?');$s->execute([$doc['client_id']??$doc['primary_client_id']??0]);$client=$s->fetch(PDO::FETCH_ASSOC);
    if (!$client || !filter_var($client['email'],FILTER_VALIDATE_EMAIL)) throw new DomainException('A valid client email is required.');
    $contractId=$link['document_type']==='contract'?(int)$doc['id']:(int)($doc['contract_id']??0);
    $s=$pdo->prepare('SELECT id FROM contracts WHERE id=? AND client_id=? AND contract_type="long_term" AND status NOT IN ("cancelled","completed")');$s->execute([$contractId,$client['id']]);
    return ['client'=>$client,'contract_id'=>(int)($s->fetchColumn()?:0)];
}

function pa_autopay_authority(array $client,array $session): bool {
    $proof=$session['autopay_verified']??[];
    return (int)($proof['client_id']??0)===(int)$client['id'] && ($proof['email']??'')===$client['email'] && (int)($proof['until']??0)>time();
}

function pa_autopay_send_code(PDO $pdo,array $client,string $sessionId,callable $sender): int {
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT id FROM clients WHERE id=? FOR UPDATE');$s->execute([$client['id']]);
        $s=$pdo->prepare('SELECT COUNT(*) FROM autopay_email_challenges WHERE client_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$s->execute([$client['id']]);
        if ((int)$s->fetchColumn()>=3) throw new DomainException('Please wait before requesting another verification code.');
        $code=(string)random_int(10000000,99999999);
        $pdo->prepare('INSERT INTO autopay_email_challenges(client_id,email,session_hash,code_hash,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')->execute([$client['id'],$client['email'],hash('sha256',$sessionId),password_hash($code,PASSWORD_DEFAULT)]);
        $id=(int)$pdo->lastInsertId();$pdo->commit();
        $sender($client['email'],$code);
        return $id;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function pa_autopay_verify_code(PDO $pdo,array $client,int $id,string $code,string $sessionId): bool {
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM autopay_email_challenges WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['client_id']!==(int)$client['id'] || $row['email']!==$client['email'] || !hash_equals($row['session_hash'],hash('sha256',$sessionId)) || $row['consumed_at'] || strtotime($row['expires_at'])<=time() || (int)$row['attempts']>=5){$pdo->rollBack();return false;}
        $valid=password_verify($code,$row['code_hash']);
        $pdo->prepare('UPDATE autopay_email_challenges SET attempts=attempts+1,consumed_at=IF(?,NOW(),NULL) WHERE id=?')->execute([(int)$valid,$id]);$pdo->commit();return $valid;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function pa_autopay_enroll(PDO $pdo,array $context,array $session,array $input,array $config,callable $transport): array {
    if (!pa_autopay_enabled($config) || !pa_autopay_authority($context['client'],$session)) throw new DomainException('Verify your email before managing AutoPay.');
    $scope=(string)($input['scope']??'agreement');$contractId=$scope==='agreement'?(int)$context['contract_id']:null;
    if (!in_array($scope,['agreement','business'],true) || ($scope==='agreement'&&!$contractId) || empty($input['consent']) || ($scope==='business'&&empty($input['business_consent']))) throw new DomainException('Choose the billing scope and explicitly authorize future automatic payments.');
    $method=(string)($input['method_type']??'us_bank_account');if(!in_array($method,['card','us_bank_account'],true))throw new DomainException('Choose card or US bank account.');
    $client=$context['client'];$installation=(string)getenv('AUTOPAY_INSTALLATION_ID');$account=(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID');
    $request=bin2hex(random_bytes(16));
    $customer=$transport('POST','customers',['name'=>$client['name'],'email'=>$client['email'],'metadata'=>['pa_client_id'=>(string)$client['id'],'pa_autopay_installation_id'=>$installation]],'autopay-customer-'.$request);
    if(empty($customer['id']) || !empty($customer['livemode']))throw new DomainException('Stripe test customer could not be verified.');
    $consent=$scope==='agreement'?'I authorize off-session automatic payment of future finalized invoices for this recurring agreement using the payment method saved with Stripe.':'I authorize off-session automatic payment of all future finalized invoices for my client account with this business using the payment method saved with Stripe.';
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT email FROM clients WHERE id=? FOR UPDATE');$s->execute([$client['id']]);
        if($s->fetchColumn()!==$client['email'])throw new DomainException('The client email changed. Verify the current email again.');
        $pdo->prepare('UPDATE autopay_enrollments SET status="revoked",revoked_at=NOW() WHERE client_id=? AND scope=? AND contract_id <=> ? AND installation_id=? AND processor_account_id=? AND status="pending"')->execute([$client['id'],$scope,$contractId,$installation,$account]);
        $pdo->prepare('INSERT INTO autopay_enrollments(client_id,contract_id,scope,processor_account_id,installation_id,stripe_customer_id,method_type,consent_version,consent_text,verified_email,consent_at,consent_proof) VALUES (?,?,?,?,?,?,?,"2026-10-v1",?,?,NOW(),?)')->execute([$client['id'],$contractId,$scope,$account,$installation,$customer['id'],$method,$consent,$client['email'],hash('sha256',json_encode($session['autopay_verified']))]);
        $id=(int)$pdo->lastInsertId();$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $metadata=['pa_autopay_authorization_id'=>(string)$id,'pa_client_id'=>(string)$client['id'],'pa_autopay_installation_id'=>$installation,'pa_autopay_account_id'=>$account];
    $base=rtrim((string)($config['app_host']??$config['base_url']??''),'/');
    if($base!==''&&!str_contains($base,'://'))$base='https://'.$base;
    if(!preg_match('#^https?://#',$base))throw new DomainException('Application URL is not configured.');
    $return=$base.'/?page=public-autopay&'.(!empty($input['manage'])?'manage='.rawurlencode((string)$input['manage']):'token='.rawurlencode((string)$input['token']));
    $checkout=$transport('POST','checkout/sessions',['mode'=>'setup','currency'=>'usd','customer'=>$customer['id'],'payment_method_types'=>[$method],'success_url'=>$return.'&setup_return=1','cancel_url'=>$return,'metadata'=>$metadata,'setup_intent_data'=>['metadata'=>$metadata]],'autopay-setup-'.$id);
    if(empty($checkout['id']) || !empty($checkout['livemode']) || !preg_match('#^https://checkout\.stripe\.com/#',(string)($checkout['url']??'')))throw new DomainException('Stripe setup could not be verified.');
    $pdo->prepare('UPDATE autopay_enrollments SET checkout_session_id=? WHERE id=?')->execute([$checkout['id'],$id]);
    return $checkout;
}

/** Called only after signature validation; independently retrieve Stripe objects. */
function pa_autopay_setup_event(PDO $pdo,array $event,array $config,callable $transport): bool {
    if(!pa_autopay_enabled($config) || ($event['type']??'')!=='setup_intent.succeeded' || !array_key_exists('livemode',$event) || $event['livemode']!==false)return false;
    $object=$event['data']['object']??[];$id=(int)($object['metadata']['pa_autopay_authorization_id']??0);if(!$id)return false;
    $intent=$transport('GET','setup_intents/'.rawurlencode((string)$object['id']),[],null);
    $method=$transport('GET','payment_methods/'.rawurlencode((string)($intent['payment_method']??'')),[],null);
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT client_id FROM autopay_enrollments WHERE id=?');$s->execute([$id]);$clientId=(int)$s->fetchColumn();
        $s=$pdo->prepare('SELECT email FROM clients WHERE id=? FOR UPDATE');$s->execute([$clientId]);$currentEmail=$s->fetchColumn();
        $s=$pdo->prepare('SELECT * FROM autopay_enrollments WHERE id=? FOR UPDATE');$s->execute([$id]);$auth=$s->fetch(PDO::FETCH_ASSOC);
        if(!$auth || $auth['status']!=='pending'){$pdo->rollBack();return false;}
        if($currentEmail!==$auth['verified_email'] || ($intent['id']??'')!==($object['id']??'') || ($method['id']??'')!==($intent['payment_method']??'') || ($auth['method_type']==='us_bank_account'&&empty($intent['mandate'])))throw new DomainException('AutoPay setup verification mismatch.');
        $m=$intent['metadata']??[];
        if(($intent['status']??'')!=='succeeded' || ($intent['usage']??'')!=='off_session' || ($intent['livemode']??true)!==false || ($method['livemode']??true)!==false || ($intent['customer']??'')!==$auth['stripe_customer_id'] || ($method['customer']??'')!==$auth['stripe_customer_id'] || ($method['type']??'')!==$auth['method_type'] || (string)($m['pa_autopay_authorization_id']??'')!==(string)$id || (string)($m['pa_client_id']??'')!==(string)$auth['client_id'] || ($m['pa_autopay_installation_id']??'')!==$auth['installation_id'] || ($m['pa_autopay_account_id']??'')!==$auth['processor_account_id'] || $auth['installation_id']!==(string)getenv('AUTOPAY_INSTALLATION_ID') || $auth['processor_account_id']!==(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID'))throw new DomainException('AutoPay setup binding mismatch.');
        $pdo->prepare('UPDATE autopay_enrollments SET status="revoked",revoked_at=NOW() WHERE client_id=? AND scope=? AND contract_id <=> ? AND installation_id=? AND processor_account_id=? AND id<>? AND status IN ("active","pending")')->execute([$auth['client_id'],$auth['scope'],$auth['contract_id'],$auth['installation_id'],$auth['processor_account_id'],$id]);
        $last4=(string)($method[$method['type']]['last4']??'');if(!preg_match('/^[0-9]{4}$/D',$last4))$last4=null;
        $pdo->prepare('UPDATE autopay_enrollments SET status="active",payment_method_id=?,setup_intent_id=?,last4=?,verified_at=NOW(),effective_from=NOW() WHERE id=?')->execute([$method['id'],$intent['id'],$last4,$id]);$pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function pa_autopay_revoke(PDO $pdo,array $client,array $session,int $id): void {
    if(!pa_autopay_authority($client,$session))throw new DomainException('Verify your email before managing AutoPay.');
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT email FROM clients WHERE id=? FOR UPDATE');$s->execute([$client['id']]);if($s->fetchColumn()!==$client['email'])throw new DomainException('Verify the current client email again.');
        $s=$pdo->prepare('SELECT * FROM autopay_enrollments WHERE id=? AND client_id=? AND installation_id=? AND processor_account_id=? FOR UPDATE');$s->execute([$id,$client['id'],getenv('AUTOPAY_INSTALLATION_ID'),getenv('AUTOPAY_STRIPE_ACCOUNT_ID')]);$auth=$s->fetch(PDO::FETCH_ASSOC);
        if($auth)$pdo->prepare('UPDATE autopay_enrollments SET status="revoked",revoked_at=NOW() WHERE client_id=? AND scope=? AND contract_id <=> ? AND installation_id=? AND processor_account_id=? AND status IN ("pending","active")')->execute([$client['id'],$auth['scope'],$auth['contract_id'],$auth['installation_id'],$auth['processor_account_id']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
