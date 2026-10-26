<?php
declare(strict_types=1);
require_once __DIR__.'/autopay_status.php';
require_once __DIR__.'/payment_receipts.php';

/** Recover delivery work from durable processor outcomes, including webhook retries. */
function pa_autopay_deliver_notifications(PDO $pdo,array $config,?callable $sender=null,int $limit=25):array {
    $stats=['sent'=>0,'retried'=>0,'suppressed'=>0];
    if(!invoice_table_has_column($pdo,'autopay_notifications','kind'))return $stats;
    $installation=(string)getenv('AUTOPAY_INSTALLATION_ID');$account=(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID');
    if(!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D',$installation)||!preg_match('/^acct_[a-zA-Z0-9]+$/D',$account))return $stats;
    $scan=$pdo->prepare('INSERT IGNORE INTO autopay_notifications(collection_id,kind) SELECT id,CASE state WHEN "succeeded" THEN "receipt" ELSE state END FROM autopay_collections WHERE state IN ("succeeded","failed","requires_action") AND COALESCE(last_error,"") NOT LIKE "Canceled%" AND (?="" OR installation_id=?) AND (?="" OR processor_account_id=?)');$scan->execute([$installation,$installation,$account,$account]);
    $sender??=[EmailService::class,'sendEmail'];
    for($i=0;$i<max(0,min(100,$limit));$i++){
        $pdo->beginTransaction();
        try{
            $claim=$pdo->prepare('SELECT n.*,c.document_type,c.document_id,c.authorization_id,c.client_id,c.stripe_payment_intent_id,c.state,c.installation_id,c.processor_account_id FROM autopay_notifications n JOIN autopay_collections c ON c.id=n.collection_id WHERE n.status IN ("pending","sending") AND n.next_attempt_at<=NOW() AND (n.lease_until IS NULL OR n.lease_until<NOW()) AND (?="" OR c.installation_id=?) AND (?="" OR c.processor_account_id=?) ORDER BY n.id LIMIT 1 FOR UPDATE');$claim->execute([$installation,$installation,$account,$account]);$row=$claim->fetch(PDO::FETCH_ASSOC);
            if(!$row){$pdo->commit();break;}
            $expected=$row['kind']==='receipt'?'succeeded':$row['kind'];
            $cancel=$pdo->prepare('SELECT last_error FROM autopay_collections WHERE id=?');$cancel->execute([$row['collection_id']]);$canceled=str_starts_with((string)$cancel->fetchColumn(),'Canceled');
            if($row['state']!==$expected||($row['kind']!=='receipt'&&$canceled)){$pdo->prepare('UPDATE autopay_notifications SET status="suppressed",last_error="Collection outcome changed before delivery." WHERE id=?')->execute([$row['id']]);$pdo->commit();$stats['suppressed']++;continue;}
            $lease=bin2hex(random_bytes(16));$pdo->prepare('UPDATE autopay_notifications SET status="sending",attempts=attempts+1,lease_token=?,lease_until=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?')->execute([$lease,$row['id']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        try{
            $q=$pdo->prepare('SELECT a.management_token,c.email,c.name FROM autopay_enrollments a JOIN clients c ON c.id=a.client_id WHERE a.id=? AND a.client_id=?');$q->execute([$row['authorization_id'],$row['client_id']]);$recipient=$q->fetch(PDO::FETCH_ASSOC);
            if(!$recipient||!filter_var($recipient['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Client email unavailable.');
            $token=(string)($recipient['management_token']??'');
            if($token===''){
                $token=bin2hex(random_bytes(32));$pdo->prepare('UPDATE autopay_enrollments SET management_token=? WHERE id=? AND management_token IS NULL')->execute([$token,$row['authorization_id']]);
                $q->execute([$row['authorization_id'],$row['client_id']]);$recipient=$q->fetch(PDO::FETCH_ASSOC);$token=(string)$recipient['management_token'];
            }
            require_once __DIR__.'/invoice_notifications.php';
            $url=invoice_notification_public_base($config).'/?page=public-autopay&manage='.rawurlencode($token);
            if($row['kind']==='receipt'){
                $receiptConfig=$config;$receiptConfig['payment_receipts_enabled']=true;$receiptConfig['_autopay_manage_url']=$url;$receiptConfig['_autopay_manage_email']=$recipient['email'];
                $table=$row['document_type']==='project_invoice'?'project_invoice_payments':'payments';
                $field=$row['document_type']==='project_invoice'?'project_invoice_id':'invoice_id';
                $q=$pdo->prepare("SELECT id FROM $table WHERE $field=? AND stripe_payment_intent_id=? AND status=\"succeeded\" ORDER BY id LIMIT 1");$q->execute([$row['document_id'],$row['stripe_payment_intent_id']]);$paymentId=(int)$q->fetchColumn();
                if(!$paymentId)throw new RuntimeException('Canonical successful payment is not available yet.');
                if($row['document_type']==='project_invoice'){
                    if(project_payment_receipt_email_issue($pdo,$paymentId,$receiptConfig,$sender,true)<1)throw new RuntimeException('Receipt recipient unavailable.');
                }else{
                    $receipt=payment_receipt_issue($pdo,$paymentId,$receiptConfig,true,$sender,true);
                    if(!$receipt||empty($receipt['emailed_at']))throw new RuntimeException('Receipt delivery is incomplete.');
                }
            }else{
                $subject=$row['kind']==='requires_action'?'Your automatic payment needs attention':'Your automatic payment did not complete';
                $message=$row['kind']==='requires_action'?'Your automatic payment requires additional attention. Please contact us before making another payment.':'Your automatic payment did not complete. Please contact us to arrange payment or update your saved payment method.';
                $body='<p>Hello '.htmlspecialchars((string)$recipient['name'],ENT_QUOTES,'UTF-8').',</p><p>'.htmlspecialchars($message).'</p><p><a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">Manage AutoPay securely</a> (email verification required).</p>';
                [$ok,$error]=$sender($recipient['email'],$subject,$body,['document_type'=>'notification','document_id'=>(int)$row['document_id'],'message_key'=>'autopay-notice:'.$row['collection_id'].':'.$row['kind'].':'.hash('sha256',strtolower($recipient['email']))]);
                if(!$ok)throw new RuntimeException('AutoPay notification delivery failed.');
            }
            $pdo->prepare('UPDATE autopay_notifications SET status="sent",sent_at=NOW(),lease_until=NULL,lease_token=NULL,last_error=NULL WHERE id=? AND lease_token=?')->execute([$row['id'],$lease]);$stats['sent']++;
        }catch(Throwable $e){
            // Keep processor details, recipient addresses and management capabilities out of logs.
            $pdo->prepare('UPDATE autopay_notifications SET status="pending",next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),lease_until=NULL,lease_token=NULL,last_error="Delivery incomplete; retry scheduled." WHERE id=? AND lease_token=?')->execute([$row['id'],$lease]);$stats['retried']++;
        }
    }
    return $stats;
}
