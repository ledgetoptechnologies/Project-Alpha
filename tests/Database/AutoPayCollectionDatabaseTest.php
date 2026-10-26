<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/utils/autopay_collection.php';

final class AutoPayCollectionDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $client=0;
    private int $authorization=0;
    private array $invoices=[];
    private array $projects=[];
    private array $projectInvoices=[];
    private array $otherClients=[];
    private array $users=[];
    private array $environment=[];
    private mixed $oldConfig;
    private array $config=['_stripe_secret_key'=>'sk_test_SYNTHETIC','workforce_currency'=>'USD'];
    protected function setUp():void
    {
        try{$this->pdo=migration_connection();$this->pdo->query('SELECT id FROM autopay_collections LIMIT 0');$this->pdo->query('SELECT effective_from FROM autopay_enrollments LIMIT 0');}
        catch(Throwable){self::markTestSkipped('MySQL migrations 112 and 113 required.');}
        foreach(['APP_ENV'=>'test','AUTOPAY_BETA_ENABLED'=>'true','AUTOPAY_INSTALLATION_ID'=>'synthetic-autopay-tests','AUTOPAY_STRIPE_ACCOUNT_ID'=>'acct_SYNTHETIC'] as $key=>$value){$this->environment[$key]=getenv($key);putenv($key.'='.$value);}
        $this->oldConfig=$GLOBALS['appConfig']??null;
        // Canonical accounting runs, but all processor lookups and mail are disabled.
        $GLOBALS['appConfig']=['notify_invoice_paid'=>false,'payment_receipts_enabled'=>false];
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic AutoPay','never@example.invalid')");$this->client=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO autopay_enrollments(client_id,scope,status,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,currency,consent_version,consent_text,verified_email,consent_at,consent_proof,verified_at,effective_from) VALUES (?,"business","active","acct_SYNTHETIC","synthetic-autopay-tests","cus_SYNTHETIC","pm_SYNTHETIC","us_bank_account","usd","test","Synthetic test consent","never@example.invalid",DATE_SUB(NOW(),INTERVAL 1 DAY),REPEAT("a",64),NOW(),DATE_SUB(NOW(),INTERVAL 1 DAY))')->execute([$this->client]);$this->authorization=(int)$this->pdo->lastInsertId();
    }
    protected function tearDown():void
    {
        if(isset($this->pdo)){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            foreach($this->projectInvoices as $id){$this->pdo->prepare('DELETE FROM autopay_collections WHERE document_type="project_invoice" AND document_id=?')->execute([$id]);$this->pdo->prepare('DELETE FROM project_invoices WHERE id=?')->execute([$id]);}
            foreach($this->invoices as $id){$this->pdo->prepare('DELETE FROM stripe_refunds WHERE payment_id IN (SELECT id FROM payments WHERE invoice_id=?)')->execute([$id]);$this->pdo->prepare('DELETE FROM autopay_collections WHERE document_type="invoice" AND document_id=?')->execute([$id]);$this->pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$id]);$this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);}
            foreach($this->projects as $id)$this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
            if($this->authorization)$this->pdo->prepare('DELETE FROM autopay_enrollments WHERE id=?')->execute([$this->authorization]);
            if($this->client){$this->pdo->prepare('DELETE FROM activity_log WHERE client_id=?')->execute([$this->client]);$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);}
            foreach($this->otherClients as $id)$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
            foreach($this->users as $id){$this->pdo->prepare('DELETE FROM system_audit WHERE user_id=?')->execute([$id]);$this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}
        }
        foreach($this->environment as $key=>$value)putenv($value===false?$key:$key.'='.$value);
        $GLOBALS['appConfig']=$this->oldConfig??null;
    }
    private function invoice(string $source='manual_finalize'):int
    {
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,balance_due,finalized_at,finalization_source) VALUES (?,"unpaid",100,100,NOW(),?)')->execute([$this->client,$source]);$id=(int)$this->pdo->lastInsertId();$this->invoices[]=$id;return $id;
    }
    private function enqueue(int $id,string $source='manual_finalize'):?int
    {
        $this->pdo->beginTransaction();try{$result=pa_autopay_enqueue_invoice($this->pdo,$id,$this->config,null,$source);$this->pdo->commit();return $result;}catch(Throwable $e){$this->pdo->rollBack();throw $e;}
    }
    private function row(int $id):array{$s=$this->pdo->prepare('SELECT * FROM autopay_collections WHERE id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC);}
    private function intent(array $row,string $status='processing'):array
    {
        return ['object'=>'payment_intent','id'=>'pi_SYNTHETIC'.$row['id'],'livemode'=>false,'amount'=>(int)$row['amount_cents'],'amount_received'=>$status==='succeeded'?(int)$row['amount_cents']:0,'currency'=>$row['currency'],'customer'=>$row['stripe_customer_id'],'payment_method'=>$row['payment_method_id'],'status'=>$status,'metadata'=>['pa_autopay_collection_id'=>(string)$row['id'],'pa_client_id'=>(string)$row['client_id'],'pa_autopay_installation_id'=>$row['installation_id'],'pa_autopay_account_id'=>$row['processor_account_id'],'pa_invoice_amount'=>'100.00','pa_surcharge_amount'=>'0.00',$row['document_type']==='invoice'?'pa_invoice_id':'pa_project_invoice_id'=>(string)$row['document_id']]];
    }
    private function event(array $intent,string $type,int $created=100):array{return ['id'=>'evt_SYNTHETIC'.$created,'type'=>$type,'livemode'=>false,'created'=>$created,'data'=>['object'=>$intent]];}
    private function projectDraft():array
    {
        require_once dirname(__DIR__,2).'/src/utils/project_invoice_billing.php';
        require_once dirname(__DIR__,2).'/src/utils/autopay_staff.php';
        $child=$this->invoice();$this->pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$child]);
        $this->pdo->prepare('INSERT INTO projects(name,client_id) VALUES ("Synthetic draft statement",?)')->execute([$this->client]);$project=(int)$this->pdo->lastInsertId();$this->projects[]=$project;
        $this->pdo->prepare('INSERT INTO project_invoices(project_id,primary_client_id,status,billing_period_start,billing_period_end,total,balance_due) VALUES (?,?,"draft",CURDATE(),CURDATE(),100,100)')->execute([$project,$this->client]);$id=(int)$this->pdo->lastInsertId();$this->projectInvoices[]=$id;
        $this->pdo->prepare('INSERT INTO project_invoice_items(project_invoice_id,invoice_id,invoice_total,amount_due_at_generation) VALUES (?,?,100,100)')->execute([$id,$child]);
        return $this->pdo->query('SELECT * FROM project_invoices WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
    }
    private function projectChoice(array $row,string $action):array
    {
        $secret='synthetic-project-preview-secret-long-enough';$state=pa_autopay_staff_state($this->pdo,$row,$this->config,'project_invoice');
        return pa_autopay_staff_request_config($this->config,['autopay_action'=>$action,'autopay_preview_hash'=>pa_autopay_staff_preview_hash($row,$state,'project_invoice',$secret)],$secret);
    }
    public function testProjectFirstFinalizationRequiresReviewedChoiceAndResendNeverEnqueues():void
    {
        $row=$this->projectDraft();$this->pdo->beginTransaction();
        try{project_invoice_finalize_locked($this->pdo,(int)$row['id'],$this->config);self::fail('Unreviewed collection');}catch(LogicException|DomainException $e){$this->pdo->rollBack();self::assertNotEmpty($e->getMessage());}
        self::assertSame('draft',$this->pdo->query('SELECT status FROM project_invoices WHERE id='.$row['id'])->fetchColumn());
        $config=$this->projectChoice($row,'hold');$this->pdo->beginTransaction();project_invoice_finalize_locked($this->pdo,(int)$row['id'],$config);$this->pdo->commit();
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE document_type="project_invoice" AND document_id='.$row['id'])->fetchColumn());
        $this->pdo->beginTransaction();project_invoice_finalize_locked($this->pdo,(int)$row['id'],$this->projectChoice($row,'collect'));$this->pdo->commit();
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE document_type="project_invoice" AND document_id='.$row['id'])->fetchColumn());
    }
    public function testProjectConfirmedFinalizationQueuesOnceAndBlocksManualPayment():void
    {
        $row=$this->projectDraft();$config=$this->projectChoice($row,'collect');$this->pdo->beginTransaction();project_invoice_finalize_locked($this->pdo,(int)$row['id'],$config);$this->pdo->commit();
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE document_type="project_invoice" AND document_id='.$row['id'])->fetchColumn());
        self::assertNull(project_invoice_record_manual_payment($this->pdo,(int)$row['id'],100,'cash'));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM project_invoice_payments WHERE project_invoice_id='.$row['id'])->fetchColumn());
        $child=(int)$this->pdo->query('SELECT invoice_id FROM project_invoice_items WHERE project_invoice_id='.$row['id'])->fetchColumn();
        $this->pdo->beginTransaction();try{pa_autopay_lock_parent_for_invoice_mutation($this->pdo,$child);self::fail('Child mutation while statement reserved');}catch(DomainException $e){self::assertStringContainsString('reserved',$e->getMessage());}finally{$this->pdo->rollBack();}
    }
    public function testProjectAutomaticFirstFinalizationQueuesButHistoricalChildPreventsAutoCollection():void
    {
        $row=$this->projectDraft();$this->pdo->beginTransaction();project_invoice_finalize_locked($this->pdo,(int)$row['id'],$this->config,true);$this->pdo->commit();
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE document_type="project_invoice" AND document_id='.$row['id'])->fetchColumn());
        $old=$this->projectDraft();$this->pdo->prepare('UPDATE project_invoice_items SET invoice_date=DATE_SUB(CURDATE(),INTERVAL 2 MONTH) WHERE project_invoice_id=?')->execute([$old['id']]);
        self::assertFalse(pa_autopay_staff_state($this->pdo,$old,$this->config,'project_invoice')['eligible']);
        $this->pdo->beginTransaction();project_invoice_finalize_locked($this->pdo,(int)$old['id'],$this->config,true);$this->pdo->commit();
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE document_type="project_invoice" AND document_id='.$old['id'])->fetchColumn());
    }
    public function testDisabledProductionLiveKeysAndHistoricalInvoicesNeverQueue():void
    {
        $id=$this->invoice();putenv('APP_ENV=production');self::assertNull($this->enqueue($id));putenv('APP_ENV=test');
        $this->config['_stripe_secret_key']='sk_live_SYNTHETIC';self::assertNull($this->enqueue($id));$this->config['_stripe_secret_key']='sk_test_SYNTHETIC';
        self::assertNull($this->enqueue($this->invoice('historical_recurring_payment')));
        self::assertNull($this->enqueue($id,'historical_recurring_payment'));
        $this->pdo->prepare('UPDATE invoices SET finalized_at=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE id=?')->execute([$id]);self::assertNull($this->enqueue($id));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE client_id='.$this->client)->fetchColumn());
    }
    public function testQueueIsUniqueAndReservesAgainstManualPaymentAndCheckout():void
    {
        $invoice=$this->invoice();$id=$this->enqueue($invoice);self::assertNotNull($id);self::assertSame($id,$this->enqueue($invoice));
        self::assertSame(10000,(int)$this->row($id)['amount_cents']);
        $this->expectException(DomainException::class);pa_autopay_assert_payment_available($this->pdo,$invoice);
    }
    public function testScopePendingVerificationAndExistingCheckoutExcludeCollection():void
    {
        $invoice=$this->invoice();
        $this->pdo->prepare('UPDATE autopay_enrollments SET scope="agreement",contract_id=999999 WHERE id=?')->execute([$this->authorization]);self::assertNull($this->enqueue($invoice));
        $this->pdo->prepare('UPDATE autopay_enrollments SET scope="business",contract_id=NULL,status="pending" WHERE id=?')->execute([$this->authorization]);self::assertNull($this->enqueue($invoice));
        $this->pdo->prepare('UPDATE autopay_enrollments SET status="active",verified_at=NULL WHERE id=?')->execute([$this->authorization]);self::assertNull($this->enqueue($invoice));
        $this->pdo->prepare('UPDATE autopay_enrollments SET verified_at=NOW() WHERE id=?')->execute([$this->authorization]);
        $this->pdo->prepare('UPDATE invoices SET stripe_session_id="cs_SYNTHETIC" WHERE id=?')->execute([$invoice]);self::assertNull($this->enqueue($invoice));
    }
    public function testNewlyGeneratedHistoricalRecurringPeriodDoesNotBecomeAutoPayBackfill():void
    {
        $id=$this->invoice('recurring_schedule');self::assertNull($this->enqueue($id,'recurring_auto'));
        $this->config['_autopay_period_start']=date('Y-m-d',strtotime('-2 months'));self::assertNull($this->enqueue($id,'recurring_auto'));
        $this->config['_autopay_period_start']=date('Y-m-d');self::assertNotNull($this->enqueue($id,'recurring_auto'));
    }
    public function testTimeoutRetriesSameIdentityWithoutTransactionAndAchWaitsForWebhook():void
    {
        $invoice=$this->invoice();$id=$this->enqueue($invoice);$key=$this->row($id)['idempotency_key'];
        $first=pa_autopay_run_one($this->pdo,$this->config,function($row)use($key){self::assertFalse($this->pdo->inTransaction());self::assertSame($key,$row['idempotency_key']);throw new RuntimeException('Synthetic timeout after request');});self::assertSame('retry',$first['status']);
        $this->pdo->prepare('UPDATE autopay_collections SET next_attempt_at=NOW() WHERE id=?')->execute([$id]);
        $second=pa_autopay_run_one($this->pdo,$this->config,function($row)use($key){self::assertSame($key,$row['idempotency_key']);self::assertFalse($this->pdo->inTransaction());return $this->intent($row);});self::assertSame('processing',$second['status']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id='.$invoice)->fetchColumn());self::assertSame('unpaid',$this->pdo->query('SELECT status FROM invoices WHERE id='.$invoice)->fetchColumn());
        self::assertSame('idle',pa_autopay_run_one($this->pdo,$this->config,fn()=>self::fail('Must not send again'))['status']);
    }
    public function testUnknownOutcomeExpiresBeforeStripeIdempotencyRetention():void
    {
        $id=$this->enqueue($this->invoice());$this->pdo->prepare('UPDATE autopay_collections SET state="processing",attempt_count=1,first_attempt_at=DATE_SUB(NOW(),INTERVAL 24 HOUR) WHERE id=?')->execute([$id]);
        self::assertSame('held',pa_autopay_run_one($this->pdo,$this->config,fn()=>self::fail('Unsafe retry'))['status']);
    }
    public function testRevocationAndInvoiceAmountChangeStopBeforeTransport():void
    {
        $invoice=$this->invoice();$id=$this->enqueue($invoice);$this->pdo->prepare('UPDATE autopay_enrollments SET status="revoked",revoked_at=NOW() WHERE id=?')->execute([$this->authorization]);
        self::assertSame('failed',pa_autopay_run_one($this->pdo,$this->config,fn()=>self::fail('Revoked mandate'))['status']);
        $this->pdo->prepare('UPDATE autopay_enrollments SET status="active",revoked_at=NULL WHERE id=?')->execute([$this->authorization]);
        $invoice=$this->invoice();$this->enqueue($invoice);$this->pdo->prepare('UPDATE invoices SET total=110,balance_due=110 WHERE id=?')->execute([$invoice]);
        self::assertSame('failed',pa_autopay_run_one($this->pdo,$this->config,fn()=>self::fail('Changed amount'))['status']);
    }
    public function testSettlementIsCanonicalDuplicateSafeAndOlderFailureCannotUndoSuccess():void
    {
        $invoice=$this->invoice();$id=$this->enqueue($invoice);$pi=$this->intent($this->row($id),'succeeded');$event=$this->event($pi,'payment_intent.succeeded',200);
        self::assertTrue(pa_autopay_handle_event($this->pdo,$event,$this->config,true));self::assertTrue(pa_autopay_handle_event($this->pdo,$event,$this->config,true));
        self::assertSame('paid',$this->pdo->query('SELECT status FROM invoices WHERE id='.$invoice)->fetchColumn());self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id='.$invoice)->fetchColumn());
        $pi['status']='requires_payment_method';pa_autopay_handle_event($this->pdo,$this->event($pi,'payment_intent.payment_failed',100),$this->config,true);self::assertSame('succeeded',$this->row($id)['state']);
        pa_autopay_hold_financial_event($this->pdo,['payment_intent'=>$pi['id']]);self::assertSame(1,(int)$this->row($id)['financial_hold']);
        $refund=['object'=>'refund','id'=>'re_SYNTHETIC'.$id,'payment_intent'=>$pi['id'],'amount'=>5000,'currency'=>'usd','status'=>'succeeded'];
        stripe_record_refund($this->pdo,$refund);stripe_record_refund($this->pdo,$refund);
        self::assertSame('50.00',$this->pdo->query('SELECT balance_due FROM invoices WHERE id='.$invoice)->fetchColumn());
        try{pa_autopay_assert_payment_available($this->pdo,$invoice);self::fail('Refund hold released to another payment');}catch(DomainException $e){self::assertStringContainsString('Resolve',$e->getMessage());}
        self::assertNull($this->enqueue($invoice));self::assertSame('idle',pa_autopay_run_one($this->pdo,$this->config,fn()=>self::fail('Refund re-debit'))['status']);
    }
    public function testWebhookRejectsUnverifiedAndMismatchedAccountEvidence():void
    {
        $id=$this->enqueue($this->invoice());$pi=$this->intent($this->row($id));
        try{pa_autopay_handle_event($this->pdo,$this->event($pi,'payment_intent.processing'),$this->config);self::fail('Missing signature');}catch(DomainException $e){self::assertStringContainsString('signature',$e->getMessage());}
        $pi['metadata']['pa_autopay_account_id']='acct_OTHER';$this->expectException(DomainException::class);pa_autopay_handle_event($this->pdo,$this->event($pi,'payment_intent.processing'),$this->config,true);
    }
    public function testDisablingNewDebitsStillReconcilesExistingTestCollectionExactlyOnce():void
    {
        $invoice=$this->invoice();$id=$this->enqueue($invoice);pa_autopay_run_one($this->pdo,$this->config,fn($row)=>$this->intent($row));
        putenv('AUTOPAY_BETA_ENABLED=false');
        self::assertNull($this->enqueue($this->invoice()));self::assertSame('disabled',pa_autopay_run_one($this->pdo,$this->config,fn()=>self::fail('Feature disabled'))['status']);
        $event=$this->event($this->intent($this->row($id),'succeeded'),'payment_intent.succeeded');
        self::assertTrue(pa_autopay_handle_event($this->pdo,$event,$this->config,true));self::assertTrue(pa_autopay_handle_event($this->pdo,$event,$this->config,true));
        self::assertSame('paid',$this->pdo->query('SELECT status FROM invoices WHERE id='.$invoice)->fetchColumn());self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id='.$invoice)->fetchColumn());
    }
    public function testKnownIntentWithRemovedCollectionMetadataCannotFallIntoLegacyHandler():void
    {
        $id=$this->enqueue($this->invoice());pa_autopay_run_one($this->pdo,$this->config,fn($row)=>$this->intent($row));
        $pi=$this->intent($this->row($id),'succeeded');unset($pi['metadata']['pa_autopay_collection_id']);
        $this->expectException(DomainException::class);pa_autopay_handle_event($this->pdo,$this->event($pi,'payment_intent.succeeded'),$this->config,true);
    }
    public function testConcurrentWorkerCannotClaimAnActiveLeaseAndStaleEventsDoNotRegress():void
    {
        $id=$this->enqueue($this->invoice());
        pa_autopay_run_one($this->pdo,$this->config,function($row){$other=migration_connection();self::assertSame('idle',pa_autopay_run_one($other,$this->config,fn()=>self::fail('Duplicate worker'))['status']);return $this->intent($row);});
        $pi=$this->intent($this->row($id),'requires_action');pa_autopay_handle_event($this->pdo,$this->event($pi,'payment_intent.requires_action',200),$this->config,true);
        $pi['status']='processing';pa_autopay_handle_event($this->pdo,$this->event($pi,'payment_intent.processing',100),$this->config,true);self::assertSame('requires_action',$this->row($id)['state']);
    }
    public function testProjectStatementOnlyCollectsSameClientChildrenAndNeverChildInvoices():void
    {
        $child=$this->invoice();$this->pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$child]);self::assertNull($this->enqueue($child));
        $this->pdo->prepare('INSERT INTO projects(name,client_id) VALUES ("Synthetic AutoPay project",?)')->execute([$this->client]);$project=(int)$this->pdo->lastInsertId();$this->projects[]=$project;
        $this->pdo->prepare('INSERT INTO project_invoices(project_id,primary_client_id,billing_period_start,billing_period_end,total,balance_due,finalized_at,finalization_source) VALUES (?,?,CURDATE(),CURDATE(),100,100,NOW(),"project_finalize")')->execute([$project,$this->client]);$id=(int)$this->pdo->lastInsertId();$this->projectInvoices[]=$id;
        $this->pdo->prepare('INSERT INTO project_invoice_items(project_invoice_id,invoice_id,invoice_total,amount_due_at_generation) VALUES (?,?,100,100)')->execute([$id,$child]);
        $this->pdo->beginTransaction();$collection=pa_autopay_enqueue_project_invoice($this->pdo,$id,$this->config,null,'project_finalize');$this->pdo->commit();self::assertNotNull($collection);
        $this->pdo->exec('INSERT INTO clients(name) VALUES ("Synthetic other payer")');$other=(int)$this->pdo->lastInsertId();$this->otherClients[]=$other;
        $this->pdo->prepare('UPDATE invoices SET client_id=? WHERE id=?')->execute([$other,$child]);
        self::assertSame('failed',pa_autopay_run_one($this->pdo,$this->config,fn()=>self::fail('Foreign client charge'))['status']);
    }

    public function testCancellationCannotReleaseUnknownOutcomeAndRequiresProcessorConfirmation():void
    {
        $invoice=$this->invoice();$id=$this->enqueue($invoice);
        pa_autopay_cancel_collection($this->pdo,$id,$this->config,fn()=>self::fail('Unsent reservation needs no network'));
        self::assertSame('failed',$this->row($id)['state']);pa_autopay_assert_payment_available($this->pdo,$invoice);
        $invoice=$this->invoice();$id=$this->enqueue($invoice);
        pa_autopay_run_one($this->pdo,$this->config,fn()=>throw new RuntimeException('Unknown network outcome'));
        try{pa_autopay_cancel_collection($this->pdo,$id,$this->config,fn()=>self::fail('Cannot cancel unknown request'));self::fail('Unknown outcome released');}catch(DomainException $e){self::assertStringContainsString('reconciled',$e->getMessage());}
        $pi=$this->intent($this->row($id),'requires_action');pa_autopay_handle_event($this->pdo,$this->event($pi,'payment_intent.requires_action'),$this->config,true);
        pa_autopay_cancel_collection($this->pdo,$id,$this->config,function($row){self::assertFalse($this->pdo->inTransaction());return $this->intent($row,'canceled');});
        self::assertSame('failed',$this->row($id)['state']);pa_autopay_assert_payment_available($this->pdo,$invoice);
    }
    public function testCancellationControllerEnforcesCsrfPermissionOwnershipAndDocumentLookup():void
    {
        $invoice=$this->invoice();$collection=$this->enqueue($invoice);
        $this->pdo->prepare('INSERT INTO users(username,email,password_hash,role,is_disabled) VALUES (?,?,"synthetic","member",1)')->execute(['Synthetic cancellation',bin2hex(random_bytes(8)).'@example.invalid']);$user=(int)$this->pdo->lastInsertId();$this->users[]=$user;
        $this->pdo->prepare('INSERT INTO user_permissions_overrides(user_id,permission,allowed) VALUES (?,"invoices.send",1)')->execute([$user]);
        $this->pdo->prepare('UPDATE invoices SET created_by=? WHERE id=?')->execute([$user,$invoice]);
        $run=function(array $post)use($user):int{
            $root=dirname(__DIR__,2);$file=tempnam(sys_get_temp_dir(),'pa-cancel-');
            $script='<?php require '.var_export($root.'/vendor/autoload.php',true).';session_start();$_SESSION=["csrf"=>str_repeat("x",64),"user"=>["id"=>'.$user.',"role"=>"member"]];$_SERVER["REQUEST_METHOD"]="POST";$_POST='.var_export($post,true).';require '.var_export($root.'/src/config/db.php',true).';require '.var_export($root.'/src/config/app.php',true).';$appConfig='.var_export($this->config,true).';register_shutdown_function(static function(){echo "STATUS=".(http_response_code()?:200);});require '.var_export($root.'/src/controllers/invoice/autopay_cancel.php',true).';';
            file_put_contents($file,$script);$process=proc_open([PHP_BINARY,$file],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);unlink($file);self::assertSame(0,$exit,$err);preg_match('/STATUS=(\d+)/',$out,$match);return (int)($match[1]??0);
        };
        $post=['document_type'=>'invoice','document_id'=>$invoice,'csrf'=>'bad','collection_id'=>999999];self::assertSame(403,$run($post));self::assertSame('pending',$this->row($collection)['state']);
        $post['csrf']=str_repeat('x',64);$this->pdo->prepare('UPDATE user_permissions_overrides SET allowed=0 WHERE user_id=?')->execute([$user]);self::assertSame(403,$run($post));
        $this->pdo->prepare('UPDATE user_permissions_overrides SET allowed=1 WHERE user_id=?')->execute([$user]);$this->pdo->prepare('UPDATE invoices SET created_by=NULL WHERE id=?')->execute([$invoice]);self::assertSame(403,$run($post));
        $this->pdo->prepare('UPDATE invoices SET created_by=? WHERE id=?')->execute([$user,$invoice]);self::assertSame(302,$run($post));self::assertSame('failed',$this->row($collection)['state']);
    }

    public function testCheckoutPreparationReservationPreventsReopeningOrNewCollection():void
    {
        $invoice=$this->invoice();$row=$this->pdo->query('SELECT * FROM invoices WHERE id='.$invoice)->fetch(PDO::FETCH_ASSOC);
        $this->pdo->beginTransaction();pa_autopay_reserve_checkout($this->pdo,$row,$this->config);$this->pdo->commit();
        self::assertNull($this->enqueue($invoice));
        $this->expectException(RuntimeException::class);invoice_reopen_draft($this->pdo,$invoice,$this->config);
    }

    public function testProjectSettlementAllocatesOnceToChildrenThroughCanonicalAccounting():void
    {
        $child=$this->invoice();$this->pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$child]);
        $this->pdo->prepare('INSERT INTO projects(name,client_id) VALUES ("Synthetic settlement project",?)')->execute([$this->client]);$project=(int)$this->pdo->lastInsertId();$this->projects[]=$project;
        $this->pdo->prepare('INSERT INTO project_invoices(project_id,primary_client_id,billing_period_start,billing_period_end,total,balance_due,finalized_at,finalization_source) VALUES (?,?,CURDATE(),CURDATE(),100,100,NOW(),"project_finalize")')->execute([$project,$this->client]);$id=(int)$this->pdo->lastInsertId();$this->projectInvoices[]=$id;
        $this->pdo->prepare('INSERT INTO project_invoice_items(project_invoice_id,invoice_id,invoice_total,amount_due_at_generation) VALUES (?,?,100,100)')->execute([$id,$child]);
        $this->pdo->beginTransaction();$collection=pa_autopay_enqueue_project_invoice($this->pdo,$id,$this->config,null,'project_finalize');$this->pdo->commit();
        $pi=$this->intent($this->row($collection),'succeeded');$event=$this->event($pi,'payment_intent.succeeded');
        self::assertTrue(pa_autopay_handle_event($this->pdo,$event,$this->config,true));self::assertTrue(pa_autopay_handle_event($this->pdo,$event,$this->config,true));
        self::assertSame('paid',$this->pdo->query('SELECT status FROM project_invoices WHERE id='.$id)->fetchColumn());
        self::assertSame('paid',$this->pdo->query('SELECT status FROM invoices WHERE id='.$child)->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id='.$child)->fetchColumn());
    }
}
