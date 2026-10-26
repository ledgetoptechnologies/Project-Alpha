<?php
declare(strict_types=1);
require_once __DIR__.'/autopay_beta.php';
require_once __DIR__.'/invoice_lifecycle.php';
require_once __DIR__.'/invoice_notifications.php';

function pa_autopay_deliver_enrollment_confirmations(PDO $pdo,array $config,?callable $sender=null,int $limit=25):array {
    $stats=['sent'=>0,'retried'=>0,'suppressed'=>0];
    if(!pa_autopay_test_context_enabled($config)||!invoice_table_has_column($pdo,'autopay_enrollment_notifications','enrollment_id'))return $stats;
    $installation=(string)getenv('AUTOPAY_INSTALLATION_ID');$account=(string)getenv('AUTOPAY_STRIPE_ACCOUNT_ID');$limit=max(0,min(100,$limit));
    // Bounded upgrade recovery for real, verified test enrollments only. Legacy
    // payment flags and unverified pending enrollments cannot create mail here.
    $scan=$pdo->prepare('INSERT IGNORE INTO autopay_enrollment_notifications(enrollment_id) SELECT a.id FROM autopay_enrollments a JOIN clients c ON c.id=a.client_id LEFT JOIN autopay_enrollment_notifications n ON n.enrollment_id=a.id WHERE n.id IS NULL AND a.status="active" AND a.revoked_at IS NULL AND a.verified_at IS NOT NULL AND a.effective_from IS NOT NULL AND a.setup_intent_id IS NOT NULL AND a.payment_method_id IS NOT NULL AND a.livemode=0 AND BINARY a.verified_email=BINARY c.email AND a.installation_id=? AND a.processor_account_id=? ORDER BY a.id LIMIT '.$limit);$scan->execute([$installation,$account]);
    $sender??=[EmailService::class,'sendEmail'];
    for($i=0;$i<$limit;$i++){
        $pdo->beginTransaction();
        try{
            $s=$pdo->prepare('SELECT n.id,n.enrollment_id,a.client_id,a.status AS enrollment_status,a.revoked_at,a.verified_email,a.verified_at,a.setup_intent_id,a.management_token,a.scope,a.contract_id FROM autopay_enrollment_notifications n JOIN autopay_enrollments a ON a.id=n.enrollment_id WHERE n.status IN ("pending","sending") AND n.next_attempt_at<=NOW() AND (n.lease_until IS NULL OR n.lease_until<NOW()) AND a.installation_id=? AND a.processor_account_id=? AND a.livemode=0 ORDER BY n.id LIMIT 1 FOR UPDATE');$s->execute([$installation,$account]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row){$pdo->commit();break;}
            $q=$pdo->prepare('SELECT email FROM clients WHERE id=?');$q->execute([$row['client_id']]);$email=(string)$q->fetchColumn();
            if($row['enrollment_status']!=='active'||$row['revoked_at']||!$row['verified_at']||!$row['setup_intent_id']||$row['verified_email']!==$email||!filter_var($email,FILTER_VALIDATE_EMAIL)){
                $pdo->prepare('UPDATE autopay_enrollment_notifications SET status="suppressed",last_error="Enrollment or recipient changed before delivery." WHERE id=?')->execute([$row['id']]);$pdo->commit();$stats['suppressed']++;continue;
            }
            if(!$row['management_token']){$row['management_token']=bin2hex(random_bytes(32));$pdo->prepare('UPDATE autopay_enrollments SET management_token=? WHERE id=?')->execute([$row['management_token'],$row['enrollment_id']]);}
            $lease=bin2hex(random_bytes(16));$pdo->prepare('UPDATE autopay_enrollment_notifications SET status="sending",attempts=attempts+1,lease_token=?,lease_until=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?')->execute([$lease,$row['id']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        try{
            $url=invoice_notification_public_base($config).'/?page=public-autopay&manage='.rawurlencode($row['management_token']);
            $scope=$row['scope']==='agreement'?'future invoices for recurring agreement #'.(int)$row['contract_id']:'all future invoices for your client account with this business';
            $body='<p>Your AutoPay setup is complete for '.htmlspecialchars($scope,ENT_QUOTES,'UTF-8').'.</p><p>Setup has not charged any existing invoice. Future eligible invoices can be collected automatically.</p><p><a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">Manage or revoke AutoPay</a>. Save this link; it remains available after an invoice is paid. Email verification is required before making changes.</p>';
            [$ok,$error]=$sender($email,'Your AutoPay setup is complete',$body,['document_type'=>'notification','document_id'=>(int)$row['enrollment_id'],'message_key'=>'autopay-enrollment:'.$row['enrollment_id'].':'.hash('sha256',strtolower($email))]);
            if(!$ok)throw new RuntimeException('Confirmation delivery failed.');
            $pdo->prepare('UPDATE autopay_enrollment_notifications SET status="sent",sent_at=NOW(),lease_until=NULL,lease_token=NULL,last_error=NULL WHERE id=? AND lease_token=?')->execute([$row['id'],$lease]);$stats['sent']++;
        }catch(Throwable $e){$pdo->prepare('UPDATE autopay_enrollment_notifications SET status="pending",next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),lease_until=NULL,lease_token=NULL,last_error="Delivery incomplete; retry scheduled." WHERE id=? AND lease_token=?')->execute([$row['id'],$lease]);$stats['retried']++;}
    }
    return $stats;
}
