<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/utils/autopay_enrollment.php';

final class AutoPayEnrollmentDatabaseTest extends TestCase {
    private PDO $pdo;private int $clientId=0;private array $client;private array $session;private array $env=[];private array $calls=[];
    private array $config=['_stripe_secret_key'=>'sk_test_synthetic','app_host'=>'https://example.invalid'];
    protected function setUp():void {
        foreach(['APP_ENV'=>'test','AUTOPAY_BETA_ENABLED'=>'true','AUTOPAY_INSTALLATION_ID'=>'synthetic-install','AUTOPAY_STRIPE_ACCOUNT_ID'=>'acct_synthetic'] as $key=>$value){$this->env[$key]=getenv($key);putenv($key.'='.$value);}
        try{$this->pdo=migration_connection();$this->pdo->query('SELECT id FROM autopay_enrollments LIMIT 0');}catch(Throwable){self::markTestSkipped('Migrated database required.');}
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic AutoPay','autopay@example.invalid')");$this->clientId=(int)$this->pdo->lastInsertId();
        $this->client=['id'=>$this->clientId,'name'=>'Synthetic AutoPay','email'=>'autopay@example.invalid'];
        $this->session=['autopay_verified'=>['client_id'=>$this->clientId,'email'=>$this->client['email'],'until'=>time()+600,'proof'=>'synthetic']];
    }
    protected function tearDown():void {
        if(isset($this->pdo)&&$this->pdo->inTransaction())$this->pdo->rollBack();
        if($this->clientId){$this->pdo->prepare('DELETE FROM public_links WHERE document_type="invoice" AND document_id IN (SELECT id FROM invoices WHERE client_id=?)')->execute([$this->clientId]);$this->pdo->prepare('DELETE FROM invoices WHERE client_id=?')->execute([$this->clientId]);$this->pdo->prepare('DELETE FROM autopay_enrollments WHERE client_id=?')->execute([$this->clientId]);$this->pdo->prepare('DELETE FROM autopay_email_challenges WHERE client_id=?')->execute([$this->clientId]);$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->clientId]);}
        foreach($this->env as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    }
    public function transport(string $method,string $endpoint,array $data=[],?string $key=null):array {
        $this->calls[]=[$method,$endpoint,$data,$key];
        if($endpoint==='customers')return ['id'=>'cus_synthetic'.count($this->calls),'livemode'=>false];
        if($endpoint==='checkout/sessions')return ['id'=>'cs_test_synthetic'.count($this->calls),'url'=>'https://checkout.stripe.com/c/pay/synthetic','livemode'=>false];
        throw new RuntimeException('Unexpected endpoint');
    }
    private function enroll(array $input=[],?array $session=null):array {
        pa_autopay_enroll($this->pdo,['client'=>$this->client,'contract_id'=>0],$session??$this->session,$input+['scope'=>'business','consent'=>1,'business_consent'=>1,'token'=>'synthetic','method_type'=>'us_bank_account'],$this->config,[$this,'transport']);
        $s=$this->pdo->prepare('SELECT * FROM autopay_enrollments WHERE client_id=? ORDER BY id DESC LIMIT 1');$s->execute([$this->clientId]);return $s->fetch(PDO::FETCH_ASSOC);
    }
    private function event(array $auth,array $override=[]):array {
        return ['type'=>'setup_intent.succeeded','livemode'=>false,'data'=>['object'=>['id'=>'seti_synthetic'.$auth['id'],'metadata'=>['pa_autopay_authorization_id'=>(string)$auth['id']]]]];
    }
    private function verifiedTransport(array $auth,array $override=[]):callable {
        return static function($method,$endpoint)use($auth,$override){
            if(str_starts_with($endpoint,'payment_methods/'))return ['id'=>'pm_synthetic','customer'=>$auth['stripe_customer_id'],'type'=>'us_bank_account','livemode'=>false];
            return array_replace(['id'=>'seti_synthetic'.$auth['id'],'status'=>'succeeded','usage'=>'off_session','livemode'=>false,'customer'=>$auth['stripe_customer_id'],'payment_method'=>'pm_synthetic','mandate'=>'mandate_synthetic','metadata'=>['pa_autopay_authorization_id'=>(string)$auth['id'],'pa_client_id'=>(string)$auth['client_id'],'pa_autopay_installation_id'=>$auth['installation_id'],'pa_autopay_account_id'=>$auth['processor_account_id']]],$override);
        };
    }
    public function testScopeConsentAndVerifiedAuthorityRequired():void {
        foreach([['session'=>[]],['session'=>['autopay_verified'=>['client_id'=>$this->clientId+1,'email'=>$this->client['email'],'until'=>time()+600]]],['input'=>['business_consent'=>0]],['input'=>['scope'=>'agreement']]] as $case){
            try{$this->enroll($case['input']??[],$case['session']??null);self::fail('Unauthorized setup accepted');}catch(DomainException){self::assertSame([],$this->calls);}
        }
    }
    public function testHostedSetupRemainsPendingUntilVerifiedWebhook():void {
        $auth=$this->enroll();self::assertSame('pending',$auth['status']);self::assertNull($auth['effective_from']);
        self::assertSame('setup',$this->calls[1][2]['mode']);self::assertArrayNotHasKey('line_items',$this->calls[1][2]);
        self::assertTrue(pa_autopay_setup_event($this->pdo,$this->event($auth),$this->config,$this->verifiedTransport($auth)));
        $s=$this->pdo->prepare('SELECT * FROM autopay_enrollments WHERE id=?');$s->execute([$auth['id']]);$active=$s->fetch(PDO::FETCH_ASSOC);
        self::assertSame('active',$active['status']);self::assertSame('pm_synthetic',$active['payment_method_id']);self::assertNotNull($active['effective_from']);
        self::assertFalse(pa_autopay_setup_event($this->pdo,$this->event($auth),$this->config,$this->verifiedTransport($auth)));
    }
    public function testCrossCustomerSetupAndPendingVerificationCannotActivate():void {
        $auth=$this->enroll();
        foreach([['customer'=>'cus_other'],['status'=>'processing'],['livemode'=>true],['mandate'=>null],['usage'=>'on_session']] as $override){
            try{pa_autopay_setup_event($this->pdo,$this->event($auth),$this->config,$this->verifiedTransport($auth,$override));self::fail('Invalid binding accepted');}catch(DomainException){self::assertFalse($this->pdo->inTransaction());}
        }
    }
    public function testRevocationAndNewerSetupPreventLateReactivation():void {
        $old=$this->enroll();$new=$this->enroll();
        self::assertFalse(pa_autopay_setup_event($this->pdo,$this->event($old),$this->config,$this->verifiedTransport($old)));
        pa_autopay_revoke($this->pdo,$this->client,$this->session,(int)$new['id']);
        self::assertFalse(pa_autopay_setup_event($this->pdo,$this->event($new),$this->config,$this->verifiedTransport($new)));
    }
    public function testEmailCodeIsSessionClientBoundSingleUseAndRateLimited():void {
        $code='';$sender=static function($email,$value)use(&$code){self::assertSame('autopay@example.invalid',$email);$code=$value;};
        $id=pa_autopay_send_code($this->pdo,$this->client,'session-one',$sender);
        self::assertFalse(pa_autopay_verify_code($this->pdo,$this->client,$id,$code,'forwarded-session'));
        self::assertFalse(pa_autopay_verify_code($this->pdo,array_replace($this->client,['id'=>$this->clientId+1]),$id,$code,'session-one'));
        self::assertTrue(pa_autopay_verify_code($this->pdo,$this->client,$id,$code,'session-one'));
        self::assertFalse(pa_autopay_verify_code($this->pdo,$this->client,$id,$code,'session-one'));
        pa_autopay_send_code($this->pdo,$this->client,'session-one',$sender);pa_autopay_send_code($this->pdo,$this->client,'session-one',$sender);
        $this->expectException(DomainException::class);pa_autopay_send_code($this->pdo,$this->client,'session-one',$sender);
    }
    public function testGateRejectsProductionLiveKeyAndMissingInstallation():void {
        self::assertTrue(pa_autopay_enabled($this->config));self::assertFalse(pa_autopay_enabled(['_stripe_secret_key'=>'sk_live_synthetic']));
        putenv('APP_ENV=production');self::assertFalse(pa_autopay_enabled($this->config));putenv('APP_ENV=test');putenv('AUTOPAY_INSTALLATION_ID');self::assertFalse(pa_autopay_enabled($this->config));
    }
    public function testRealControllerRequiresCsrfAndEmailStepUpAndGetDoesNotMutate():void {
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,balance_due) VALUES (?,"unpaid",10,10)')->execute([$this->clientId]);$id=(int)$this->pdo->lastInsertId();$token=bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO public_links(document_type,document_id,token,expires_at,expire_when_paid) VALUES ("invoice",?,?,DATE_ADD(NOW(),INTERVAL 1 DAY),0)')->execute([$id,$token]);
        foreach([['method'=>'GET','post'=>[],'expected'=>'Verify access'],['method'=>'POST','post'=>['action'=>'setup','csrf'=>'wrong'],'expected'=>'Invalid request'],['method'=>'POST','post'=>['action'=>'setup','csrf'=>'synthetic-csrf','scope'=>'business','consent'=>1,'business_consent'=>1],'expected'=>'Verify your email']] as $case){
            $root=dirname(__DIR__,2);$payload=base64_encode(json_encode([$case,$token,$this->config],JSON_THROW_ON_ERROR));
            $code='require '.var_export($root.'/vendor/autoload.php',true).';require '.var_export($root.'/src/config/app.php',true).';[$case,$token,$config]=json_decode(base64_decode('.var_export($payload,true).'),true);$appConfig=array_replace($appConfig,$config);session_start();$_SESSION=["csrf"=>"synthetic-csrf"];$_SERVER["REQUEST_METHOD"]=$case["method"];$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_GET=["token"=>$token];$_POST=$case["post"]+["token"=>$token]; require '.var_export($root.'/src/controllers/public_view/public_autopay.php',true).';';
            $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);self::assertIsResource($process);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err);self::assertStringContainsString($case['expected'],$output);
        }
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM autopay_enrollments WHERE client_id=?');$s->execute([$this->clientId]);self::assertSame(0,(int)$s->fetchColumn());
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM autopay_email_challenges WHERE client_id=?');$s->execute([$this->clientId]);self::assertSame(0,(int)$s->fetchColumn());
    }
    public function testStaffPreviewRequiresPermissionAndCsrfAndOnlyReturnsMaskedProjection():void {
        $auth=$this->enroll();pa_autopay_setup_event($this->pdo,$this->event($auth),$this->config,$this->verifiedTransport($auth));
        $this->pdo->prepare('INSERT INTO users(username,email,password_hash,role) VALUES (?,?,?,"admin")')->execute(['synthetic-preview-'.bin2hex(random_bytes(5)),'preview@example.invalid','disabled-synthetic-password']);$user=(int)$this->pdo->lastInsertId();
        try {
            foreach([['actor'=>0,'csrf'=>'synthetic','success'=>false],['actor'=>$user,'csrf'=>'wrong','success'=>false],['actor'=>$user,'csrf'=>'synthetic','success'=>true],['actor'=>$user,'csrf'=>'synthetic','success'=>true,'general'=>true]] as $case){
                $root=dirname(__DIR__,2);$payload=base64_encode(json_encode([$case,$this->clientId,$this->config],JSON_THROW_ON_ERROR));
                $code='require '.var_export($root.'/vendor/autoload.php',true).';require '.var_export($root.'/src/config/app.php',true).';[$case,$client,$config]=json_decode(base64_decode('.var_export($payload,true).'),true);$appConfig=array_replace($appConfig,$config);session_start();$_SESSION=["csrf"=>"synthetic","user"=>["id"=>$case["actor"],"role"=>"admin"]];$_SERVER["REQUEST_METHOD"]="POST";$_POST=["csrf"=>$case["csrf"],"client_id"=>$client,"recipient_presentation_mode"=>empty($case["general"])?"named":"general"];require '.var_export($root.'/src/controllers/invoice/autopay_preview.php',true).';';
                $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err);$json=json_decode($output,true,32,JSON_THROW_ON_ERROR);self::assertSame($case['success'],$json['success']);
                if($case['success']){self::assertSame(empty($case['general']),$json['eligible']);self::assertArrayNotHasKey('authorization_id',$json);self::assertStringNotContainsString('cus_',$output);self::assertStringNotContainsString('pm_',$output);}
            }
        }finally{$this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}
    }
}
