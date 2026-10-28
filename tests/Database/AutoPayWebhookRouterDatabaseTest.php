<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';

/** Actual signed HTTP router and enrollment code; Stripe I/O is a local recording fixture. */
final class AutoPayWebhookRouterDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $client=0;
    private int $authorization=0;
    private string $temporary='';
    private string $url='';
    private mixed $server=null;
    private array $events=[];
    protected function setUp():void
    {
        try{$this->pdo=migration_connection();$this->pdo->query('SELECT id FROM autopay_enrollments LIMIT 0');}catch(Throwable){self::markTestSkipped('Migrated isolated MySQL required.');}
        $this->pdo->exec('INSERT INTO clients(name,email) VALUES ("Synthetic signed router","router@example.invalid")');$this->client=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO autopay_enrollments(client_id,scope,status,processor_account_id,installation_id,stripe_customer_id,method_type,currency,consent_version,consent_text,verified_email,consent_at,consent_proof) VALUES (?,"business","pending","acct_ROUTER","synthetic-router-fixture","cus_ROUTER","card","usd","test","Synthetic consent","router@example.invalid",NOW(),REPEAT("a",64))')->execute([$this->client]);$this->authorization=(int)$this->pdo->lastInsertId();
        $this->temporary=sys_get_temp_dir().'/pa-webhook-router-'.bin2hex(random_bytes(12));
        foreach(['controllers/webhook','utils','config','services'] as $dir)mkdir($this->temporary.'/'.$dir,0700,true);
        $source=dirname(__DIR__,2).'/src/';
        foreach(['controllers/webhook/stripe_webhooks.php','utils/webhook_logger.php','utils/stripe_webhook_signature.php','utils/client_ip.php','utils/rate_limiter.php','utils/autopay_enrollment.php','utils/autopay_beta.php','config/db.php'] as $file)copy($source.$file,$this->temporary.'/'.$file);
        // Setup-only fixture: unrelated money handlers must not execute here.
        file_put_contents($this->temporary.'/utils/stripe_financial_events.php','<?php');
        file_put_contents($this->temporary.'/config/app.php','<?php $appConfig=["_stripe_secret_key"=>"sk_test_SYNTHETIC","_stripe_webhook_secret"=>"whsec_synthetic_router"];');
        file_put_contents($this->temporary.'/services/StripeService.php', <<<'PHP'
<?php
class StripeService {
 public static function fromAppConfig(array $config):self{return new self();}
 public static function webhookSecret(array $config):string{return $config['_stripe_webhook_secret'];}
 public static function hasAutoPayTestKey(array $config):bool{return $config['_stripe_secret_key']==='sk_test_SYNTHETIC';}
 public function autoPayRequest(string $method,string $endpoint,array $data=[],?string $key=null):array {
  $directory=dirname(__DIR__);file_put_contents($directory.'/calls.log',$method.' '.$endpoint."\n",FILE_APPEND);
  $fixtures=json_decode(file_get_contents($directory.'/responses.json'),true,512,JSON_THROW_ON_ERROR);
  if($method!=='GET'||!isset($fixtures[$endpoint]))throw new RuntimeException('Unexpected fixture transport request.');
  return $fixtures[$endpoint];
 }
}
PHP);
        file_put_contents($this->temporary.'/flag','false');
        file_put_contents($this->temporary.'/router.php','<?php if($_SERVER["REQUEST_METHOD"]==="GET"){echo "ready";return;}putenv("APP_ENV=staging");putenv("AUTOPAY_BETA_ENABLED=".trim(file_get_contents(__DIR__."/flag")));putenv("AUTOPAY_INSTALLATION_ID=synthetic-router-fixture");putenv("AUTOPAY_STRIPE_ACCOUNT_ID=acct_ROUTER");require __DIR__."/controllers/webhook/stripe_webhooks.php";');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);self::assertIsResource($socket,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$this->url='http://'.$address;
        $this->server=proc_open([PHP_BINARY,'-d','log_errors=1','-d','error_log='.$this->temporary.'/php.log','-S',$address,$this->temporary.'/router.php'],[0=>['pipe','r'],1=>['file',$this->temporary.'/server.log','a'],2=>['file',$this->temporary.'/server.log','a']],$pipes);self::assertIsResource($this->server);fclose($pipes[0]);
        for($i=0;$i<60;$i++){if(@file_get_contents($this->url,false,stream_context_create(['http'=>['timeout'=>0.2]]))==='ready')return;usleep(50000);}self::fail('HTTP fixture did not start.');
    }
    protected function tearDown():void
    {
        if(is_resource($this->server)){proc_terminate($this->server);proc_close($this->server);}
        if(isset($this->pdo)){
            foreach($this->events as $id){$this->pdo->prepare('DELETE FROM stripe_events WHERE stripe_event_id=?')->execute([$id]);$this->pdo->prepare('DELETE FROM webhook_event_log WHERE event_id=?')->execute([$id]);}
            if($this->authorization)$this->pdo->prepare('DELETE FROM autopay_enrollments WHERE id=?')->execute([$this->authorization]);
            if($this->client)$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
        }
        if($this->temporary!==''&&is_dir($this->temporary)){
            $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($this->temporary);
        }
    }
    private function send(array $event,bool $valid=true):array
    {
        $body=json_encode($event,JSON_THROW_ON_ERROR);$time=time();$signature=hash_hmac('sha256',$time.'.'.$body,$valid?'whsec_synthetic_router':'whsec_wrong');
        $reply=file_get_contents($this->url,false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nStripe-Signature: t={$time},v1={$signature}",'content'=>$body,'ignore_errors'=>true,'timeout'=>10]]));
        self::assertNotFalse($reply);preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$status);return [(int)($status[1]??0),json_decode($reply,true,512,JSON_THROW_ON_ERROR)];
    }
    private function enrollmentStatus():string{return (string)$this->pdo->query('SELECT status FROM autopay_enrollments WHERE id='.$this->authorization)->fetchColumn();}
    public function testSignedDisabledSetupRemainsRetryableAndReplayVerifiesProviderBeforeActivation():void
    {
        $eventId='evt_router_'.bin2hex(random_bytes(12));$this->events[]=$eventId;
        $intent=['id'=>'seti_ROUTER'.$this->authorization,'object'=>'setup_intent','status'=>'succeeded','usage'=>'off_session','livemode'=>false,'customer'=>'cus_ROUTER','payment_method'=>'pm_ROUTER','metadata'=>['pa_autopay_authorization_id'=>(string)$this->authorization,'pa_client_id'=>(string)$this->client,'pa_autopay_installation_id'=>'synthetic-router-fixture','pa_autopay_account_id'=>'acct_ROUTER']];
        $event=['id'=>$eventId,'type'=>'setup_intent.succeeded','livemode'=>false,'created'=>time(),'data'=>['object'=>$intent]];
        file_put_contents($this->temporary.'/responses.json',json_encode(['setup_intents/'.$intent['id']=>$intent,'payment_methods/pm_ROUTER'=>['id'=>'pm_ROUTER','customer'=>'cus_ROUTER','type'=>'card','livemode'=>false,'card'=>['last4'=>'4242']]],JSON_THROW_ON_ERROR));
        [$status]=$this->send($event,false);self::assertSame(400,$status);self::assertSame('pending',$this->enrollmentStatus());
        $query=$this->pdo->prepare('SELECT status FROM stripe_events WHERE stripe_event_id=?');$query->execute([$eventId]);self::assertFalse($query->fetchColumn());self::assertFileDoesNotExist($this->temporary.'/calls.log');
        [$status]=$this->send($event);self::assertSame(500,$status);$query->execute([$eventId]);self::assertSame('failed',$query->fetchColumn());self::assertSame('pending',$this->enrollmentStatus());self::assertFileDoesNotExist($this->temporary.'/calls.log');
        file_put_contents($this->temporary.'/flag','true');[$status,$reply]=$this->send($event);self::assertSame(200,$status);self::assertTrue($reply['received']);self::assertSame('active',$this->enrollmentStatus());
        $query->execute([$eventId]);self::assertSame('processed',$query->fetchColumn());$calls=file($this->temporary.'/calls.log',FILE_IGNORE_NEW_LINES);self::assertSame(['GET setup_intents/'.$intent['id'],'GET payment_methods/pm_ROUTER'],$calls);
        [$status,$reply]=$this->send($event);self::assertSame(200,$status);self::assertTrue($reply['duplicate']);self::assertSame($calls,file($this->temporary.'/calls.log',FILE_IGNORE_NEW_LINES));
    }
}
