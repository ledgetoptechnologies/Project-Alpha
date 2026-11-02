<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Security\DatabaseSessionHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailedCheckHttpMySqlTest extends TestCase
{
    private PDO $database;
    public function testClientVerificationPendingHoldAndStaffReceiptThroughRealHttp(): void
    {
        $dsn=(string)getenv('CHECK_HTTP_DSN');$database=(string)getenv('CHECK_HTTP_DATABASE');
        if($dsn==='')self::markTestSkipped('Use the disposable mailed-check HTTP runner.');
        if(getenv('CHECK_HTTP_ALLOW')!=='isolated-disposable-only'||!preg_match('/^pa_check_http_[a-f0-9]{32}$/D',$database)
            ||!preg_match('/^mysql:host=127\\.0\\.0\\.1;port=([0-9]+);dbname='.preg_quote($database,'/').';charset=utf8mb4$/D',$dsn,$match))throw new RuntimeException('Refusing non-disposable database.');
        $pdo=new PDO($dsn,'root',(string)getenv('CHECK_HTTP_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        self::assertSame([],$pdo->query('SHOW TABLES')->fetchAll());
        $root=dirname(__DIR__,2);require_once $root.'/src/migrations/migration_lib.php';require_once $root.'/src/utils/autopay_enrollment.php';require_once $root.'/src/utils/check_report_public.php';
        $pdo->exec('SET GLOBAL log_bin_trust_function_creators=1');
        $apply=static function(string $path)use($pdo):void{foreach(\migration_statements((string)file_get_contents($path))as$sql){$s=$pdo->query($sql);if($s!==false)$s->closeCursor();}};
        $apply($root.'/database/baseline.sql');
        foreach(\migration_files($root.'/database/migrations')as$file){\migration_preflight($pdo,(int)$file['version']);$apply($file['path']);$pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum)VALUES(?,?,?)')->execute([$file['version'],$file['filename'],$file['checksum']]);}
        \migration_schema_health($pdo);
        $pdo->exec("INSERT INTO users(id,username,email,password_hash,role)VALUES(92001,'Synthetic check admin','check-admin@example.invalid','unusable','admin');
            INSERT INTO clients(id,public_id,name,email,client_type)VALUES(92001,'cccccccccccccccccccccccccccccccc','Synthetic check client','check-client@example.invalid','consumer');
            INSERT INTO invoices(id,client_id,doc_number,status,total,amount_paid,balance_due,finalized_at)VALUES(92001,92001,92001,'unpaid',350,0,350,NOW());");
        $token=str_repeat('a',64);$pdo->prepare('INSERT INTO public_links(token,document_type,document_id,revoked)VALUES(?,"invoice",92001,0)')->execute([$token]);
        $this->database=$pdo;
        $context=\pa_check_report_public_context($pdo,$token,['payment_methods'=>['check']]);$client=$context['client'];$anchor=\pa_check_report_public_anchor($context,$token);
        $publicId=bin2hex(random_bytes(24));$code='';
        $challenge=\pa_autopay_send_code($pdo,$client,'mailed-check:'.$publicId.':'.$anchor,static function($email,$value)use(&$code):void{$code=$value;});
        $public=$this->savedSession(['mailed_check_challenge'=>$challenge,'mailed_check_challenge_token'=>hash('sha256',$token),'mailed_check_challenge_client_public_id'=>$client['public_id'],'mailed_check_challenge_anchor'=>$anchor],$publicId);
        $admin=$this->savedSession(['user'=>['id'=>92001,'email'=>'check-admin@example.invalid','role'=>'admin','auth_version'=>1],
            'authn'=>['method'=>'password','authenticated_at'=>time(),'absolute_expires_at'=>time()+3600],'last_activity'=>time()]);
        $this->withServer((int)$match[1],function(string $base)use($pdo,$token,$public,$admin,$code):void{
            $path=$base.'/?page=public-check-payment';
            $get=$this->request($path.'&token='.$token,$public['id']);self::assertSame(200,$get['status']);self::assertStringContainsString('Verify your email',$get['body']);
            $bad=$this->request($path,$public['id'],['token'=>$token,'csrf'=>'invalid','action'=>'report','check_number'=>'5321']);self::assertContains($bad['status'],[302,403]);
            $unverified=$this->request($path,$public['id'],['token'=>$token,'csrf'=>$public['csrf'],'action'=>'report','check_number'=>'5321']);self::assertSame(403,$unverified['status']);
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM mailed_check_reports')->fetchColumn());
            $verify=$this->request($path,$public['id'],['token'=>$token,'csrf'=>$public['csrf'],'action'=>'verify','code'=>$code]);self::assertSame(200,$verify['status'],$verify['body']);self::assertStringContainsString('Email verified',$verify['body']);
            $report=$this->request($path,$public['id'],['token'=>$token,'csrf'=>$public['csrf'],'action'=>'report','check_number'=>'CHECK-<5321>','document_id'=>999999,'client_id'=>999999]);
            self::assertSame(200,$report['status'],$report['body']);self::assertStringContainsString('awaiting receipt',$report['body']);self::assertStringContainsString('USD 350.00',$report['body']);self::assertStringContainsString('CHECK-&lt;5321&gt;',$report['body']);self::assertStringNotContainsString('CHECK-<5321>',$report['body']);
            $saved=$pdo->query('SELECT * FROM mailed_check_reports')->fetch();self::assertSame(92001,(int)$saved['document_id']);self::assertSame(35000,(int)$saved['amount_cents']);self::assertSame('pending',$saved['status']);
            self::assertSame(10,(int)$pdo->query('SELECT DATEDIFF(expected_by,reported_at) FROM mailed_check_reports')->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());self::assertSame('unpaid',$pdo->query('SELECT status FROM invoices WHERE id=92001')->fetchColumn());
            $this->request($path,$public['id'],['token'=>$token,'csrf'=>$public['csrf'],'action'=>'report','check_number'=>'CHECK-<5321>']);self::assertSame($saved,$pdo->query('SELECT * FROM mailed_check_reports')->fetch());
            $action=$base.'/?page=invoice/pending-check-action';$fields=['document_type'=>'invoice','document_id'=>92001,'action'=>'received','confirm_check_action'=>'1'];
            $bad=$this->request($action,$admin['id'],$fields+['csrf'=>'invalid']);self::assertContains($bad['status'],[302,403]);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
            $missing=$fields;unset($missing['confirm_check_action']);$missing['csrf']=$admin['csrf'];$this->request($action,$admin['id'],$missing);self::assertSame('pending',$pdo->query('SELECT status FROM mailed_check_reports')->fetchColumn());
            $confirmed=$this->request($action,$admin['id'],$fields+['csrf'=>$admin['csrf']]);self::assertSame(302,$confirmed['status'],$confirmed['body']);self::assertStringContainsString('check_action_saved=1',$confirmed['headers']['location']);
            self::assertSame('received',$pdo->query('SELECT status FROM mailed_check_reports')->fetchColumn());self::assertSame('paid',$pdo->query('SELECT status FROM invoices WHERE id=92001')->fetchColumn());self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
            $payment=$pdo->query('SELECT id,amount,reference_number,payment_method FROM payments')->fetch();self::assertSame('350.00',$payment['amount']);self::assertSame('CHECK-<5321>',$payment['reference_number']);self::assertSame('check',$payment['payment_method']);
            $this->request($action,$admin['id'],$fields+['csrf'=>$admin['csrf']]);self::assertSame($payment,$pdo->query('SELECT id,amount,reference_number,payment_method FROM payments')->fetch());self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        });
    }
    private function savedSession(array $values,?string $id=null):array
    {
        $id??=bin2hex(random_bytes(24));$csrf=bin2hex(random_bytes(32));$values['csrf']=$csrf;$payload='';
        foreach($values as$key=>$value)$payload.=$key.'|'.serialize($value);
        $handler=new DatabaseSessionHandler($this->database);
        self::assertTrue($handler->write($id,$payload));self::assertTrue($handler->close());
        return ['id'=>$id,'csrf'=>$csrf];
    }
    private function withServer(int $port, callable $test): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) throw new RuntimeException('Loopback port reservation failed');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $env = getenv();
        $env['DB_HOST'] = '127.0.0.1;port=' . $port;
        $env['MYSQL_DATABASE'] = (string)getenv('CHECK_HTTP_DATABASE');
        $env['MYSQL_USER'] = 'root';
        $env['MYSQL_PASSWORD'] = (string)getenv('CHECK_HTTP_PASSWORD');
        $env['APP_SKIP_DB_CONFIG'] = '1';
        $env['APP_ENV'] = 'production';
        foreach (['AUTH_DISABLED', 'APP_AUTH_DISABLED'] as $key) $env[$key] = 'false';
        foreach (['STRIPE_SECRET_KEY', 'stripe_secret_key', 'stripe_secret_key_enc', 'STRIPE_WEBHOOK_SECRET'] as $key) $env[$key] = '';
        $log = tmpfile();
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'], [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, dirname(__DIR__, 2), $env, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Loopback PHP server unavailable');
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($probe) { fclose($probe); $ready = true; break; }
                usleep(100000);
            }
            self::assertTrue($ready);
            $test('http://' . $address);
        } catch (\Throwable $error) {
            rewind($log);
            fwrite(STDERR, stream_get_contents($log));
            throw $error;
        } finally {
            proc_terminate($process);
            proc_close($process);
            fclose($log);
        }
    }

    private function request(string $url, ?string $session = null, ?array $post = null): array
    {
        $headers = ['X-Requested-With: XMLHttpRequest'];
        if ($session !== null) $headers[] = 'Cookie: PHPSESSID=' . $session;
        if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
        ]]));
        preg_match('/\s([0-9]{3})\s/', $http_response_header[0], $status);
        $parsed = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $parsed[strtolower($key)] = trim($value); }
        }
        return ['status' => (int)$status[1], 'headers' => $parsed, 'body' => (string)$body];
    }
}

