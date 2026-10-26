<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class AutoPayResponseHeadersTest extends TestCase {
    public function testHostedSetupRedirectHasNarrowCspAndKeepsSensitiveHeaders():void {
        $directory=sys_get_temp_dir().'/autopay-headers-'.bin2hex(random_bytes(8));
        foreach(['controllers/public_view','utils','config'] as $part)mkdir($directory.'/'.$part,0700,true);
        $root=dirname(__DIR__,2).'/src/';
        foreach(['controllers/public_view/public_autopay.php','utils/security_headers.php','utils/request_security.php','utils/client_ip.php','utils/csrf.php'] as $file)copy($root.$file,$directory.'/'.$file);
        file_put_contents($directory.'/config/db.php','<?php $pdo=new PDO("sqlite::memory:");');
        file_put_contents($directory.'/config/app.php','<?php $appConfig=[];');
        file_put_contents($directory.'/utils/rate_limiter.php','<?php function rate_limit_check(...$args){return true;}');
        file_put_contents($directory.'/utils/autopay_enrollment.php',<<<'PHP'
<?php
function pa_autopay_test_context_enabled(array $config):bool{return true;}
function pa_autopay_enabled(array $config):bool{return true;}
function pa_autopay_context(PDO $pdo,string $token):array{return ['client'=>['id'=>1,'name'=>'Synthetic','email'=>'never@example.invalid'],'contract_id'=>0];}
function pa_autopay_authority(array $client,array $session):bool{return false;}
function pa_autopay_enroll(...$args):array{return ['url'=>'https://checkout.stripe.com/c/pay/synthetic'];}
class StripeService {public static function fromAppConfig(array $config):self{return new self();}}
PHP);
        file_put_contents($directory.'/router.php',<<<'PHP'
<?php
session_start();$_SESSION['csrf']='synthetic-csrf';
require __DIR__.'/utils/security_headers.php';send_security_headers();
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/default'){echo 'default';return;}
require __DIR__.'/controllers/public_view/public_autopay.php';
PHP);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);self::assertIsResource($socket);$address=stream_socket_get_name($socket,false);fclose($socket);
        $log=$directory.'/server.log';$server=proc_open([PHP_BINARY,'-S',$address,$directory.'/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$directory);self::assertIsResource($server);fclose($pipes[0]);
        try{
            for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,.05);if($connection){fclose($connection);break;}usleep(10000);}
            $request=static function(string $path,?string $post=null)use($address):array{
                $ch=curl_init('http://'.$address.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>5]);
                if($post!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
                $raw=curl_exec($ch);self::assertIsString($raw);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$ch=null;$headers=explode("\r\n\r\n",$raw,2)[0];return [$status,$headers];
            };
            [$status,$default]=$request('/default');self::assertSame(200,$status);self::assertStringContainsString("form-action 'self';",$default);self::assertStringNotContainsString('https://checkout.stripe.com',$default);
            foreach([null,'action=setup&token=synthetic&csrf=synthetic-csrf&scope=business&consent=1&business_consent=1'] as $post){
                [$status,$headers]=$request('/autopay',$post);self::assertSame($post===null?200:303,$status);
                preg_match('/form-action ([^;]+);/',$headers,$match);self::assertSame("'self' https://checkout.stripe.com",$match[1]??'');
                self::assertStringContainsString('Cache-Control: no-store',$headers);self::assertStringContainsString('Referrer-Policy: no-referrer',$headers);
                if($post!==null)self::assertStringContainsString('Location: https://checkout.stripe.com/c/pay/synthetic',$headers);
            }
        }finally{
            proc_terminate($server);proc_close($server);
            $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($iterator as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($directory);
        }
    }
}
