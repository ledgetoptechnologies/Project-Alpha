<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Security\DatabaseSessionHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Actual front-controller requests on a freshly created disposable database only. */
final class OrganizationBillingHttpMySqlTest extends TestCase
{
    public function testUpgradePreservesHistoryAndHttpEnforcesBillingPermissions():void
    {
        $dsn=(string)getenv('BILLING_HTTP_DSN');$database=(string)getenv('BILLING_HTTP_DATABASE');
        if($dsn==='')self::markTestSkipped('Use scripts/run-billing-recipients-http-mysql.mjs.');
        if(getenv('BILLING_HTTP_ALLOW')!=='isolated-disposable-only'||!preg_match('/^pa_billing_http_[a-f0-9]{32}$/D',$database)
            ||!preg_match('/^mysql:host=127\\.0\\.0\\.1;port=([0-9]+);dbname='.preg_quote($database,'/').';charset=utf8mb4$/D',$dsn,$match))throw new RuntimeException('Refusing non-disposable database.');
        $pdo=new PDO($dsn,'root',(string)getenv('BILLING_HTTP_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        self::assertSame([],$pdo->query('SHOW TABLES')->fetchAll());
        $root=dirname(__DIR__,2);require_once $root.'/src/migrations/migration_lib.php';
        $pdo->exec('SET GLOBAL log_bin_trust_function_creators=1');
        $apply=static function(string $path)use($pdo):void{foreach(\migration_statements((string)file_get_contents($path))as$sql){$s=$pdo->query($sql);if($s!==false)$s->closeCursor();}};
        $apply($root.'/database/baseline.sql');
        foreach(\migration_files($root.'/database/migrations') as $file){if((int)$file['version']>=116)continue;\migration_preflight($pdo,(int)$file['version']);$apply($file['path']);$pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum)VALUES(?,?,?)')->execute([$file['version'],$file['filename'],$file['checksum']]);}
        $pdo->exec("INSERT INTO users(id,email,password_hash,role)VALUES(91001,'admin@example.invalid','unusable','admin'),(91002,'member@example.invalid','unusable','member');
            INSERT INTO organizations(id,name,general_email)VALUES(91001,'Synthetic billing organization','general@example.invalid'),(91002,'Other organization','other@example.invalid');
            INSERT INTO clients(id,name,email,organization_id)VALUES(91001,'Working','working@example.invalid',91001),(91002,'Accounting A','ap@example.invalid',91001),(91003,'Accounting B','second@example.invalid',91001),(91004,'Other contact','outside@example.invalid',91002),(91005,'Creator contact','creator@example.invalid',91001);
            UPDATE clients SET created_by=91002 WHERE id=91005;
            INSERT INTO user_permissions_overrides(user_id,organization_id,permission,allowed)VALUES(91002,NULL,'invoices.create',1),(91002,NULL,'invoices.edit',1);
            INSERT INTO projects(id,client_id,organization_id,name,status)VALUES(91001,91002,91001,'Same-company project','active');
            INSERT INTO invoices(id,client_id,organization_id,status,total,amount_paid,balance_due)VALUES(91001,91001,91001,'draft',350,0,350)");
        $before=$pdo->query('SELECT * FROM invoices WHERE id=91001')->fetch();
        foreach(\migration_files($root.'/database/migrations') as$file){if((int)$file['version']<116)continue;\migration_preflight($pdo,(int)$file['version']);$apply($file['path']);$pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum)VALUES(?,?,?)')->execute([$file['version'],$file['filename'],$file['checksum']]);}
        \migration_schema_health($pdo);
        self::assertSame($before,$pdo->query('SELECT * FROM invoices WHERE id=91001')->fetch());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM organization_billing_preferences')->fetchColumn());
        $admin=$this->session($pdo,91001,'admin');$member=$this->session($pdo,91002,'member');
        $this->withServer((int)$match[1],function(string $base)use($pdo,$admin,$member):void{
            $preview='/?page=invoice-billing-preview';$save='/?page=organization/organization-billing-save';
            self::assertSame(302,$this->request($base.$preview)['status']);
            self::assertSame(403,$this->request($base.$preview,$member['id'],['csrf'=>$member['csrf'],'client_id'=>91001])['status']);
            $creatorPreview=$this->request($base.$preview,$member['id'],['csrf'=>$member['csrf'],'client_id'=>91005]);
            self::assertSame(403,$creatorPreview['status']);
            self::assertStringNotContainsString('ap@example.invalid',$creatorPreview['body']);
            $missingCsrf=$this->request($base.$preview,$admin['id'],['client_id'=>91001]);
            self::assertContains($missingCsrf['status'],[302,403]);
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM organization_billing_preferences')->fetchColumn());
            $post=['csrf'=>$admin['csrf'],'organization_id'=>91001,'billing_policy_mode'=>'accounting','billing_recipient_ids'=>[91002,91003]];
            $memberPost=$post;$memberPost['csrf']=$member['csrf'];
            self::assertSame(403,$this->request($base.$save,$member['id'],$memberPost)['status']);
            $response=$this->request($base.$save,$admin['id'],$post);self::assertSame(302,$response['status'],$response['body']);
            self::assertStringContainsString('billing_saved=1',$response['headers']['location']);
            $response=$this->request($base.$preview,$admin['id'],['csrf'=>$admin['csrf'],'client_id'=>91001,'invoice_id'=>91001]);
            self::assertSame(200,$response['status'],$response['body']);$body=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);
            self::assertSame(['ap@example.invalid','second@example.invalid'],array_column($body['recipients'],'email'));
            self::assertNotContains('outside@example.invalid',array_column($body['candidates'],'email'));
            self::assertSame('organization',$body['source']);
            $projectPreview=$this->request($base.$preview,$admin['id'],['csrf'=>$admin['csrf'],'client_id'=>91001,'project_id'=>91001]);
            self::assertSame(200,$projectPreview['status'],$projectPreview['body']);
            self::assertSame(['ap@example.invalid','second@example.invalid'],array_column(json_decode($projectPreview['body'],true,512,JSON_THROW_ON_ERROR)['recipients'],'email'));
            $bad=$post;$bad['billing_recipient_ids']=[91004];$response=$this->request($base.$save,$admin['id'],$bad);
            self::assertStringContainsString('error=',$response['headers']['location']);
            self::assertSame([91002,91003],array_map('intval',$pdo->query('SELECT client_id FROM organization_billing_recipients ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN)));
            require_once dirname(__DIR__,2).'/src/utils/billing_recipients.php';
            \pa_billing_invoice_save($pdo,91001,'custom',[91003],false);
            self::assertSame(['second@example.invalid'],array_column(\pa_billing_resolve($pdo,'invoice',91001)['recipients'],'email'));
            // Shared-profile updates cannot reset financial-only recipient state.
            $pdo->exec("UPDATE organizations SET name='Renamed organization',general_email='changed@example.invalid' WHERE id=91001");
            self::assertSame([91002,91003],\pa_billing_organization_preferences($pdo,91001)['recipient_ids']);
            self::assertSame('draft',$pdo->query('SELECT status FROM invoices WHERE id=91001')->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM invoice_notifications')->fetchColumn());
        });
    }

    private function session(PDO $pdo,int $userId,string $role):array
    {
        $id=bin2hex(random_bytes(24));$csrf=bin2hex(random_bytes(32));
        $values=['user'=>['id'=>$userId,'email'=>$role.'@example.invalid','role'=>$role,'auth_version'=>1],'csrf'=>$csrf,'last_activity'=>time(),
            'authn'=>['method'=>'password','authenticated_at'=>time(),'absolute_expires_at'=>time()+3600]];
        $payload='';foreach($values as$key=>$value)$payload.=$key.'|'.serialize($value);
        $handler=new DatabaseSessionHandler($pdo);self::assertTrue($handler->write($id,$payload));self::assertTrue($handler->close());
        return ['id'=>$id,'csrf'=>$csrf];
    }
    private function withServer(int $port,callable $test):void
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Loopback unavailable');
        $address=stream_socket_get_name($socket,false);fclose($socket);$env=getenv();
        $env['DB_HOST']='127.0.0.1;port='.$port;$env['MYSQL_DATABASE']=(string)getenv('BILLING_HTTP_DATABASE');$env['MYSQL_USER']='root';$env['MYSQL_PASSWORD']=(string)getenv('BILLING_HTTP_PASSWORD');
        $env['APP_SKIP_DB_CONFIG']='1';$env['APP_ENV']='production';$env['APP_AUTH_DISABLED']='false';$env['AUTH_DISABLED']='false';
        $log=tmpfile();$process=proc_open([PHP_BINARY,'-S',$address,'-t','public','public/index.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,dirname(__DIR__,2),$env,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('HTTP server unavailable');fclose($pipes[0]);
        try{$ready=false;for($i=0;$i<50;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(100000);}self::assertTrue($ready);$test('http://'.$address);}
        catch(\Throwable $error){rewind($log);fwrite(STDERR,stream_get_contents($log));throw $error;}
        finally{proc_terminate($process);proc_close($process);fclose($log);}
    }
    private function request(string $url,?string $session=null,?array $post=null):array
    {
        $headers=['X-Requested-With: XMLHttpRequest'];if($session!==null)$headers[]='Cookie: PHPSESSID='.$session;
        if($post!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
        $body=file_get_contents($url,false,stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]));
        preg_match('/\s([0-9]{3})\s/',$http_response_header[0],$status);$parsed=[];
        foreach(array_slice($http_response_header,1)as$line){if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$parsed[strtolower($key)]=trim($value);}}
        return ['status'=>(int)$status[1],'headers'=>$parsed,'body'=>(string)$body];
    }
}
