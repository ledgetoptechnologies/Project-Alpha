<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/utils/autopay_enrollment.php';
require_once dirname(__DIR__,2).'/src/utils/autopay_notifications.php';

final class AutoPayEnrollmentConfirmationDatabaseTest extends TestCase {
    private PDO $pdo;private int $client=0;private int $auth=0;private int $invoice=0;private array $env=[];
    private array $config=['_stripe_secret_key'=>'sk_test_SYNTHETIC','app_host'=>'https://example.invalid'];
    protected function setUp():void {
        try{$this->pdo=migration_connection();$this->pdo->query('SELECT enrollment_id FROM autopay_enrollment_notifications LIMIT 0');}catch(Throwable){self::markTestSkipped('Migration115 required.');}
        foreach(['APP_ENV'=>'test','AUTOPAY_BETA_ENABLED'=>'true','AUTOPAY_INSTALLATION_ID'=>'synthetic-enrollment-mail','AUTOPAY_STRIPE_ACCOUNT_ID'=>'acct_SYNTHETIC'] as $k=>$v){$this->env[$k]=getenv($k);putenv($k.'='.$v);}
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic payer','payer@example.invalid')");$this->client=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO autopay_enrollments(client_id,scope,status,processor_account_id,installation_id,stripe_customer_id,method_type,consent_version,consent_text,verified_email,consent_at,consent_proof) VALUES (?,"business","pending","acct_SYNTHETIC","synthetic-enrollment-mail","cus_SYNTHETIC","card","test","Synthetic consent","payer@example.invalid",NOW(),REPEAT("a",64))')->execute([$this->client]);$this->auth=(int)$this->pdo->lastInsertId();
    }
    protected function tearDown():void {
        if(isset($this->pdo)&&$this->pdo->inTransaction())$this->pdo->rollBack();
        if($this->invoice){$this->pdo->prepare('DELETE FROM public_links WHERE document_type="invoice" AND document_id=?')->execute([$this->invoice]);$this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$this->invoice]);}
        if($this->auth)$this->pdo->prepare('DELETE FROM autopay_enrollments WHERE id=?')->execute([$this->auth]);if($this->client)$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
        foreach($this->env as $k=>$v)putenv($v===false?$k:$k.'='.$v);
    }
    private function activate():void {
        $intent=['id'=>'seti_SYNTHETIC'.$this->auth,'status'=>'succeeded','usage'=>'off_session','livemode'=>false,'customer'=>'cus_SYNTHETIC','payment_method'=>'pm_SYNTHETIC','metadata'=>['pa_autopay_authorization_id'=>(string)$this->auth,'pa_client_id'=>(string)$this->client,'pa_autopay_installation_id'=>'synthetic-enrollment-mail','pa_autopay_account_id'=>'acct_SYNTHETIC']];
        $event=['type'=>'setup_intent.succeeded','livemode'=>false,'data'=>['object'=>$intent]];
        $transport=static fn($method,$endpoint)=>str_starts_with($endpoint,'setup_intents/')?$intent:['id'=>'pm_SYNTHETIC','customer'=>'cus_SYNTHETIC','type'=>'card','livemode'=>false];
        self::assertTrue(pa_autopay_setup_event($this->pdo,$event,$this->config,$transport));self::assertFalse(pa_autopay_setup_event($this->pdo,$event,$this->config,$transport));
    }
    private function token():string {$s=$this->pdo->prepare('SELECT management_token FROM autopay_enrollments WHERE id=?');$s->execute([$this->auth]);return (string)$s->fetchColumn();}
    public function testVerifiedEnrollmentIssuesManagementLinkEvenAfterOriginInvoiceIsPaidManually():void {
        $this->activate();self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$this->token());
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,amount_paid,balance_due) VALUES (?,"paid",50,50,0)')->execute([$this->client]);$this->invoice=(int)$this->pdo->lastInsertId();$origin=bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO public_links(document_type,document_id,token,expire_when_paid,revoked) VALUES ("invoice",?,?,1,1)')->execute([$this->invoice,$origin]);
        try{pa_autopay_context($this->pdo,$origin);self::fail('Paid origin reopened');}catch(DomainException){self::assertTrue(true);}
        $sent=[];$sender=static function($to,$subject,$body,$options)use(&$sent){$sent[]=[$to,$body,$options];return [true,''];};
        putenv('AUTOPAY_BETA_ENABLED=false');self::assertSame(1,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);self::assertSame(0,pa_autopay_deliver_notifications($this->pdo,$this->config,$sender)['sent']);
        self::assertSame('payer@example.invalid',$sent[0][0]);self::assertStringContainsString('manage='.$this->token(),$sent[0][1]);self::assertStringContainsString('has not charged any existing invoice',$sent[0][1]);
        $context=pa_autopay_management_context($this->pdo,$this->token());self::assertSame($this->client,$context['client']['id']);self::assertFalse(pa_autopay_authority($context['client'],[]));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM autopay_collections WHERE client_id='.$this->client)->fetchColumn());
    }
    public function testRetryAndUpgradeRecoveryKeepStableConfirmationIdentity():void {
        $this->activate();$this->pdo->prepare('DELETE FROM autopay_enrollment_notifications WHERE enrollment_id=?')->execute([$this->auth]);$this->pdo->prepare('UPDATE autopay_enrollments SET management_token=NULL WHERE id=?')->execute([$this->auth]);
        $keys=[];$attempt=0;$sender=static function($to,$subject,$body,$options)use(&$keys,&$attempt){$keys[]=$options['message_key'];return [++$attempt>1,'Synthetic failure'];};
        self::assertSame(1,pa_autopay_deliver_enrollment_confirmations($this->pdo,$this->config,$sender)['retried']);$token=$this->token();
        $this->pdo->prepare('UPDATE autopay_enrollment_notifications SET next_attempt_at=NOW() WHERE enrollment_id=?')->execute([$this->auth]);self::assertSame(1,pa_autopay_deliver_enrollment_confirmations($this->pdo,$this->config,$sender)['sent']);self::assertSame($token,$this->token());self::assertSame($keys[0],$keys[1]);
        self::assertSame(0,pa_autopay_deliver_enrollment_confirmations($this->pdo,$this->config,$sender)['sent']);
    }
    public function testRevokedAndWrongEnvironmentCannotSendConfirmation():void {
        $this->activate();$sender=static function(){self::fail('Ineligible confirmation delivered');};putenv('APP_ENV=production');self::assertSame(0,pa_autopay_deliver_enrollment_confirmations($this->pdo,$this->config,$sender)['sent']);
        putenv('APP_ENV=test');$this->pdo->prepare('UPDATE autopay_enrollments SET status="revoked",revoked_at=NOW() WHERE id=?')->execute([$this->auth]);self::assertSame(1,pa_autopay_deliver_enrollment_confirmations($this->pdo,$this->config,$sender)['suppressed']);
    }
}
