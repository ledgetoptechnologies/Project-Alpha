<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/utils/autopay_notifications.php';
require_once dirname(__DIR__,2).'/src/utils/autopay_enrollment.php';
require_once dirname(__DIR__,2).'/src/utils/invoice_notifications.php';

final class AutoPayNotificationsDatabaseTest extends TestCase {
    private PDO $pdo;private int $client=0;private int $invoice=0;private int $auth=0;private int $collection=0;private int $payment=0;private array $env=[];
    private array $config=['app_host'=>'https://example.invalid','payment_receipts_enabled'=>false,'invoice_auto_send_due_7days'=>true];
    protected function setUp():void {
        try{$this->pdo=migration_connection();$this->pdo->query('SELECT id FROM autopay_notifications LIMIT 0');}catch(Throwable){self::markTestSkipped('Migration114 required.');}
        foreach(['AUTOPAY_INSTALLATION_ID'=>'synthetic-notices','AUTOPAY_STRIPE_ACCOUNT_ID'=>'acct_SYNTHETIC','AUTOPAY_BETA_ENABLED'=>'false'] as $key=>$value){$this->env[$key]=getenv($key);putenv($key.'='.$value);}
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic payer','payer@example.invalid')");$this->client=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO autopay_enrollments(client_id,scope,status,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,consent_version,consent_text,verified_email,consent_at,consent_proof) VALUES (?,"business","active","acct_SYNTHETIC","synthetic-notices","cus_SYNTHETIC","pm_SYNTHETIC","card","test","Synthetic","payer@example.invalid",NOW(),REPEAT("a",64))')->execute([$this->client]);$this->auth=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,balance_due,finalized_at,due_date) VALUES (?,"unpaid",100,100,NOW(),DATE_ADD(CURDATE(),INTERVAL 3 DAY))')->execute([$this->client]);$this->invoice=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO autopay_collections(document_type,document_id,authorization_id,client_id,amount_cents,currency,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,idempotency_key,stripe_payment_intent_id,state,source) VALUES ("invoice",?,?,?,10000,"usd","acct_SYNTHETIC","synthetic-notices","cus_SYNTHETIC","pm_SYNTHETIC","card",?,?,"processing","synthetic")')->execute([$this->invoice,$this->auth,$this->client,'notice-'.bin2hex(random_bytes(8)),'pi_NOTICE'.$this->invoice]);$this->collection=(int)$this->pdo->lastInsertId();
    }
    protected function tearDown():void {
        if(isset($this->pdo)&&$this->pdo->inTransaction())$this->pdo->rollBack();
        if($this->collection){$this->pdo->prepare('DELETE FROM autopay_notifications WHERE collection_id=?')->execute([$this->collection]);$this->pdo->prepare('DELETE FROM autopay_collections WHERE id=?')->execute([$this->collection]);}
        if($this->payment){$this->pdo->prepare('DELETE FROM payment_receipts WHERE payment_id=?')->execute([$this->payment]);$this->pdo->prepare('DELETE FROM payments WHERE id=?')->execute([$this->payment]);}
        if($this->invoice){$this->pdo->prepare('DELETE FROM invoice_notifications WHERE invoice_id=?')->execute([$this->invoice]);$this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$this->invoice]);}
        if($this->auth)$this->pdo->prepare('DELETE FROM autopay_enrollments WHERE id=?')->execute([$this->auth]);
        if($this->client)$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
        foreach($this->env as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    }
    private function state(string $state):void {$this->pdo->prepare('UPDATE autopay_collections SET state=? WHERE id=?')->execute([$state,$this->collection]);}
    private function succeeded():void {
        $this->pdo->prepare('INSERT INTO payments(client_id,invoice_id,amount,payment_method,payment_date,status,stripe_payment_intent_id) VALUES (?,?,100,"stripe",CURDATE(),"succeeded",?)')->execute([$this->client,$this->invoice,'pi_NOTICE'.$this->invoice]);$this->payment=(int)$this->pdo->lastInsertId();$this->state('succeeded');
    }
    public function testProcessingSuppressesReminderAndNeverCreatesReceipt():void {
        $sent=[];$sender=static function(...$args)use(&$sent){$sent[]=$args;return [true,''];};
        $this->state('pending');self::assertStringContainsString('queued',pa_autopay_pending_notice($this->pdo,$this->invoice));$this->state('processing');
        self::assertStringContainsString('processing',pa_autopay_pending_notice($this->pdo,$this->invoice));
        self::assertSame(0,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);
        $due=$this->pdo->query('SELECT due_date FROM invoices WHERE id='.$this->invoice)->fetchColumn();invoice_notification_enqueue($this->pdo,$this->invoice,'due_7','due:'.$due,'payer@example.invalid');
        $stats=invoice_notification_process($this->pdo,$this->config,$sender,null,10,$this->invoice);self::assertSame(1,$stats['suppressed']);self::assertSame([],$sent);
    }
    public function testFailureAndActionNoticesAreDurablyDeduplicated():void {
        $sent=[];$sender=static function(...$args)use(&$sent){$sent[]=$args;return [true,''];};
        $this->state('requires_action');self::assertSame(1,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);self::assertSame(0,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);
        $this->state('failed');self::assertSame(1,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);self::assertCount(2,$sent);self::assertNotSame($sent[0][3]['message_key'],$sent[1][3]['message_key']);self::assertStringContainsString('manage=',$sent[1][2]);
    }
    public function testSuccessReceiptOverridesDisabledPreferenceAndRetriesWithoutDuplicateReceipt():void {
        $this->succeeded();$keys=[];$attempt=0;
        $sender=static function($to,$subject,$body,$options)use(&$keys,&$attempt){$keys[]=$options['message_key'];self::assertSame('payer@example.invalid',$to);self::assertStringContainsString('manage=',$body);return [++$attempt>1,'Synthetic mail failure'];};
        self::assertSame(1,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['retried']);
        $this->pdo->prepare('UPDATE autopay_notifications SET next_attempt_at=NOW() WHERE collection_id=?')->execute([$this->collection]);
        self::assertSame(1,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);self::assertSame(0,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);self::assertSame($keys[0],$keys[1]);
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM payment_receipts WHERE payment_id=?');$s->execute([$this->payment]);self::assertSame(1,(int)$s->fetchColumn());
    }
    public function testCanonicalReceiptAlreadySentIsNotSentAgainAndManagementSurvivesDocumentExpiry():void {
        $this->succeeded();$sent=0;$sender=static function($to,$subject,$body,$opts)use(&$sent){$sent++;self::assertStringContainsString('manage=',$body);return [true,''];};
        $config=$this->config;$config['payment_receipts_enabled']=true;payment_receipt_issue($this->pdo,$this->payment,$config,true,$sender,true);
        pa_autopay_deliver_notifications($this->pdo,$this->config,$sender);self::assertSame(1,$sent);
        $s=$this->pdo->prepare('SELECT management_token FROM autopay_enrollments WHERE id=?');$s->execute([$this->auth]);$token=$s->fetchColumn();$context=pa_autopay_management_context($this->pdo,$token);self::assertSame($this->client,$context['client']['id']);self::assertFalse(pa_autopay_authority($context['client'],[]));
        $this->expectException(DomainException::class);pa_autopay_context($this->pdo,$token);
    }
    public function testCanceledCollectionAndMissingBindingsDoNotSendFailureNotice():void {
        $this->state('failed');$this->pdo->prepare('UPDATE autopay_collections SET last_error="Canceled before submission." WHERE id=?')->execute([$this->collection]);
        $sender=static function(){self::fail('Canceled collection notified as failed');};self::assertSame(0,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);
        $this->pdo->prepare('UPDATE autopay_collections SET last_error=NULL WHERE id=?')->execute([$this->collection]);putenv('AUTOPAY_INSTALLATION_ID');self::assertSame(0,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);
    }
    public function testProjectReceiptManagementCapabilityOnlyGoesToPayer():void {
        $project=0;$statement=0;$other=0;
        try{
            $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic stakeholder','stakeholder@example.invalid')");$other=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO projects(name,client_id) VALUES ("Synthetic receipt project",?)')->execute([$this->client]);$project=(int)$this->pdo->lastInsertId();
            foreach([$this->client,$other] as $client)$this->pdo->prepare('INSERT INTO project_invoice_recipients(project_id,client_id,recipient_key) VALUES (?,?,?)')->execute([$project,$client,'client:'.$client]);
            $this->pdo->prepare('INSERT INTO project_invoices(project_id,primary_client_id,status,total,balance_due,billing_period_start,billing_period_end) VALUES (?,?,"paid",100,0,CURDATE(),CURDATE())')->execute([$project,$this->client]);$statement=(int)$this->pdo->lastInsertId();
            $pi='pi_NOTICE'.$this->invoice;
            $this->pdo->prepare('UPDATE autopay_collections SET document_type="project_invoice",document_id=?,state="succeeded" WHERE id=?')->execute([$statement,$this->collection]);
            $this->pdo->prepare('INSERT INTO project_invoice_payments(project_invoice_id,amount,payment_method,payment_date,status,stripe_payment_intent_id) VALUES (?,100,"stripe",CURDATE(),"succeeded",?)')->execute([$statement,$pi]);$payment=(int)$this->pdo->lastInsertId();
            $messages=[];$sender=static function($to,$subject,$body,$options)use(&$messages){$messages[$to]=$body;return [true,''];};$config=$this->config;$config['payment_receipts_enabled']=true;
            self::assertSame(2,project_payment_receipt_email_issue($this->pdo,$payment,$config,$sender,true));
            self::assertStringContainsString('manage=',$messages['payer@example.invalid']);self::assertStringNotContainsString('manage=',$messages['stakeholder@example.invalid']);
        }finally{
            if($statement){$this->pdo->prepare('DELETE FROM project_invoice_payments WHERE project_invoice_id=?')->execute([$statement]);$this->pdo->prepare('DELETE FROM project_invoices WHERE id=?')->execute([$statement]);}
            if($project)$this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$project]);if($other)$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$other]);
        }
    }
}
