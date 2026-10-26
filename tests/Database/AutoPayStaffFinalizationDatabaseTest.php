<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/utils/autopay_staff.php';

final class AutoPayStaffFinalizationDatabaseTest extends TestCase {
    private PDO $pdo;private int $client=0;private int $invoice=0;private array $environment=[];
    private array $config=['_stripe_secret_key'=>'sk_test_SYNTHETIC','workforce_currency'=>'USD'];
    protected function setUp():void {
        try{$this->pdo=migration_connection();$this->pdo->query('SELECT effective_from FROM autopay_enrollments LIMIT 0');}catch(Throwable){self::markTestSkipped('MySQL migrations112/113 required.');}
        foreach(['APP_ENV'=>'test','AUTOPAY_BETA_ENABLED'=>'true','AUTOPAY_INSTALLATION_ID'=>'synthetic-staff-finalize','AUTOPAY_STRIPE_ACCOUNT_ID'=>'acct_SYNTHETIC'] as $key=>$value){$this->environment[$key]=getenv($key);putenv($key.'='.$value);}
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic staff finalization','never@example.invalid')");$this->client=(int)$this->pdo->lastInsertId();$this->authorization();
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,subtotal,total,balance_due) VALUES (?,"draft",100,100,100)')->execute([$this->client]);$this->invoice=(int)$this->pdo->lastInsertId();
    }
    protected function tearDown():void {
        if(isset($this->pdo)){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($this->invoice){$this->pdo->prepare('DELETE FROM autopay_collections WHERE document_type="invoice" AND document_id=?')->execute([$this->invoice]);$this->pdo->prepare('DELETE FROM payments WHERE invoice_id=?')->execute([$this->invoice]);$this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$this->invoice]);$this->pdo->prepare('DELETE FROM system_audit WHERE entity_type="invoice" AND entity_id=?')->execute([$this->invoice]);}
            if($this->client){$this->pdo->prepare('DELETE FROM autopay_enrollments WHERE client_id=?')->execute([$this->client]);$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);}
        }
        foreach($this->environment as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    }
    private function authorization():void {
        $this->pdo->prepare('INSERT INTO autopay_enrollments(client_id,scope,status,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,last4,currency,consent_version,consent_text,verified_email,consent_at,consent_proof,verified_at,effective_from) VALUES (?,"business","active","acct_SYNTHETIC","synthetic-staff-finalize","cus_SYNTHETIC","pm_SYNTHETIC","card","4242","usd","test","Synthetic consent","never@example.invalid",DATE_SUB(NOW(),INTERVAL 1 DAY),REPEAT("a",64),NOW(),DATE_SUB(NOW(),INTERVAL 1 DAY))')->execute([$this->client]);
    }
    private function row():array {$s=$this->pdo->prepare('SELECT * FROM invoices WHERE id=?');$s->execute([$this->invoice]);return $s->fetch(PDO::FETCH_ASSOC);}
    private function collectionCount():int {$s=$this->pdo->prepare('SELECT COUNT(*) FROM autopay_collections WHERE document_type="invoice" AND document_id=?');$s->execute([$this->invoice]);return (int)$s->fetchColumn();}
    private function choice(string $action):array {
        $row=$this->row();$secret='synthetic-staff-finalization-preview-secret';$state=pa_autopay_staff_state($this->pdo,$row,$this->config);
        return pa_autopay_staff_request_config($this->config,['autopay_action'=>$action,'autopay_preview_hash'=>pa_autopay_staff_preview_hash($row,$state,'invoice',$secret)],$secret);
    }
    public function testDraftRequiresReviewBeforeFinalizing():void {
        try{invoice_finalize($this->pdo,$this->invoice,$this->config,'manual_finalize');self::fail('Unreviewed finalization accepted');}catch(DomainException|LogicException $e){self::assertNotEmpty($e->getMessage());}
        self::assertSame('draft',$this->row()['status']);self::assertSame(0,$this->collectionCount());
    }
    public function testChangedAmountAndChangedAuthorizationInvalidateReview():void {
        $config=$this->choice('collect');$this->pdo->prepare('UPDATE invoices SET total=120,balance_due=120 WHERE id=?')->execute([$this->invoice]);
        try{invoice_finalize($this->pdo,$this->invoice,$config,'manual_finalize');self::fail('Changed amount accepted');}catch(DomainException){self::assertSame('draft',$this->row()['status']);}
        $config=$this->choice('collect');$this->pdo->prepare('UPDATE autopay_enrollments SET status="revoked" WHERE client_id=?')->execute([$this->client]);$this->authorization();
        try{invoice_finalize($this->pdo,$this->invoice,$config,'manual_finalize');self::fail('Changed authorization accepted');}catch(DomainException){self::assertSame(0,$this->collectionCount());}
    }
    public function testHoldAndRepeatFinalizationNeverQueue():void {
        invoice_finalize($this->pdo,$this->invoice,$this->choice('hold'),'manual_finalize');self::assertSame('unpaid',$this->row()['status']);self::assertSame(0,$this->collectionCount());
        invoice_finalize($this->pdo,$this->invoice,$this->choice('collect'),'manual_finalize');self::assertSame(0,$this->collectionCount());
    }
    public function testReviewedCollectQueuesExactlyOnceAndGuardsOtherPaymentPaths():void {
        $config=$this->choice('collect');invoice_finalize($this->pdo,$this->invoice,$config,'manual_finalize');invoice_finalize($this->pdo,$this->invoice,$config,'manual_finalize');self::assertSame(1,$this->collectionCount());
        foreach(['payment','void','reopen'] as $action){
            try{
                if($action==='payment')invoice_record_locked_payment($this->pdo,$this->invoice,100,'check','SYNTHETIC',null);
                elseif($action==='void')invoice_void($this->pdo,$this->invoice,$this->config,'Synthetic');
                else invoice_reopen_draft($this->pdo,$this->invoice);
                self::fail('Reserved invoice allowed '.$action);
            }catch(DomainException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();self::assertStringContainsString('AutoPay',$e->getMessage());}
        }
        self::assertSame('unpaid',$this->row()['status']);self::assertSame('0.00',$this->row()['amount_paid']);
    }
}
