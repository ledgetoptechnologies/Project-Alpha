<?php
declare(strict_types=1);
namespace Tests\Integration;
use PDO;
use PHPUnit\Framework\TestCase;

/** Real loopback front-controller/auth test; destructive only in a disposable database. */
final class ApiV2FinancialHttpMySqlTest extends TestCase
{
    public function testRealControllerFailsClosedAndReturnsOnlyEligibleCurrentDocuments(): void
    {
        $db=(string)getenv('API_V2_FINANCIAL_MYSQL_DATABASE');$dsn=(string)getenv('API_V2_FINANCIAL_MYSQL_DSN');
        if($dsn==='')self::markTestSkipped('Use scripts/run-api-v2-financial-http-mysql.mjs with disposable MySQL8.4.');
        if(getenv('API_V2_FINANCIAL_MYSQL_ALLOW_DESTRUCTIVE')!=='isolated-disposable-only'||!preg_match('/^api_v2_financial_test_[a-f0-9]{32}$/D',$db)||!str_starts_with($dsn,'mysql:host=127.0.0.1;port='))throw new \RuntimeException('Disposable loopback database required.');
        $pdo=new PDO($dsn,'root',(string)getenv('API_V2_FINANCIAL_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        self::assertSame($db,$pdo->query('SELECT DATABASE()')->fetchColumn());self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll());
        $root=dirname(__DIR__,2);require_once $root.'/src/utils/api_keys_schema.php';require_once $root.'/src/utils/api_v2_financial_projection.php';
        \pa_ensure_api_keys_schema($pdo);
        $pdo->exec("ALTER TABLE api_keys ADD COLUMN api_v2_application_id BIGINT NULL;
CREATE TABLE api_v2_applications(id BIGINT PRIMARY KEY,application_id CHAR(36));
CREATE TABLE api_v2_history_identity(singleton TINYINT PRIMARY KEY,source_instance_id CHAR(36),history_epoch CHAR(36));
CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT PRIMARY KEY,authorization_generation BIGINT);
CREATE TABLE api_v2_directory_resource_state(resource_type VARCHAR(32),public_id CHAR(32),revision BIGINT,projection_sha256 CHAR(64),present TINYINT);
CREATE TABLE api_v2_directory_external_bindings(application_pk BIGINT,resource_type VARCHAR(32),external_id VARBINARY(764),public_id CHAR(32),status VARCHAR(32),resource_revision BIGINT,resource_projection_sha256 CHAR(64));
CREATE TABLE clients(id BIGINT PRIMARY KEY,public_id CHAR(32),organization_id BIGINT NULL,name VARCHAR(191),email VARCHAR(191),phone VARCHAR(64),client_type VARCHAR(32),address_line1 VARCHAR(191),address_line2 VARCHAR(191),city VARCHAR(100),state VARCHAR(100),postal_code VARCHAR(32),country VARCHAR(100));
CREATE TABLE app_config(organization_id INT,config_key VARCHAR(64),config_value TEXT);
CREATE TABLE public_links(id INT PRIMARY KEY,token VARCHAR(64),document_type VARCHAR(32),document_id INT,expires_at DATETIME NULL,revoked TINYINT,created_at DATETIME);
CREATE TABLE invoices(id INT PRIMARY KEY,public_id CHAR(32),client_id INT,organization_id INT NULL,project_id INT NULL,status VARCHAR(32),currency CHAR(3),total DECIMAL(20,2),amount_paid DECIMAL(20,2),balance_due DECIMAL(20,2),finalized_at DATETIME NULL,collection_mode VARCHAR(32),recipient_presentation_mode VARCHAR(32));
CREATE TABLE contracts(id INT PRIMARY KEY,public_id CHAR(32),client_id INT,organization_id INT NULL,project_id INT NULL,status VARCHAR(32),currency CHAR(3),total DECIMAL(20,2),signed_at DATETIME NULL);
CREATE TABLE project_invoices(id INT PRIMARY KEY,public_id CHAR(32),primary_client_id INT,organization_id INT NULL,project_id INT NULL,status VARCHAR(32),currency CHAR(3),total DECIMAL(20,2),amount_paid DECIMAL(20,2),balance_due DECIMAL(20,2),finalized_at DATETIME NULL);
CREATE TABLE project_invoice_items(project_invoice_id INT,invoice_id INT);
INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');
INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');
INSERT INTO api_v2_directory_authorization_state VALUES(3,9);
INSERT INTO clients(id,public_id,name) VALUES(10,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Synthetic client');
INSERT INTO app_config VALUES(0,'app_host','https://pa.example.test');
INSERT INTO invoices VALUES(10,'11111111111111111111111111111111',10,NULL,NULL,'unpaid','USD',1,0,1,'2026-01-01','direct','named'),(11,'22222222222222222222222222222222',10,NULL,NULL,'draft','USD',1,0,1,NULL,'direct','named');
INSERT INTO contracts VALUES(20,'33333333333333333333333333333333',10,NULL,NULL,'pending','USD',2,NULL);
INSERT INTO public_links VALUES(1,'synthetic_invoice_token_123','invoice',10,NULL,0,'2026-01-01'),(2,'synthetic_contract_token_123','contract',20,NULL,0,'2026-01-01');");
        $client=$pdo->query('SELECT * FROM clients WHERE id=10')->fetch();$hash=\api_v2_directory_canonical_hash($pdo,'client',$client);
        $pdo->prepare("INSERT INTO api_v2_directory_resource_state VALUES('client',?,3,?,1)")->execute([$client['public_id'],$hash]);
        $pdo->prepare("INSERT INTO api_v2_directory_external_bindings VALUES(3,'client','client/exact',?,'active',3,?)")->execute([$client['public_id'],$hash]);
        foreach([['correct','api.capabilities.read,financial.documents.read',3],['wrong','api.capabilities.read',3],['unbound','api.capabilities.read,financial.documents.read',null],['legacy','full',3]]as[$token,$scopes,$app])$pdo->prepare("INSERT INTO api_keys(name,key_prefix,key_hash,scopes,api_v2_application_id)VALUES('synthetic',?,?,?,?)")->execute([$token,hash('sha256',$token),$scopes,$app]);
        $before=$pdo->query('SELECT * FROM public_links ORDER BY id')->fetchAll();
        $this->withServer(false,function(string $base):void{self::assertSame(404,$this->get($base,null)['status']);});
        $this->withServer(true,function(string $base)use($pdo,$before):void{
            self::assertSame(401,$this->get($base,null)['status']);self::assertSame(403,$this->get($base,'wrong')['status']);self::assertSame(403,$this->get($base,'legacy')['status']);self::assertSame(409,$this->get($base,'unbound')['status']);
            self::assertSame(409,$this->get($base,'correct','selectorType=client&externalId=client%2Fexact',true)['status']);
            foreach(['selectorType=foreign&externalId=client%2Fexact','selectorType=client&externalId=client%2Fexact&cursor=invalid!','selectorType=client&externalId=client%2Fexact&cursor[]=x','selectorType=client&externalId=client%2Fexact&limit[]=1','selectorType=client&externalId=client%2Fexact&extra=1']as$query)self::assertSame(400,$this->get($base,'correct',$query)['status']);
            $first=$this->get($base,'correct','selectorType=client&externalId=client%2Fexact&limit=1');self::assertSame(200,$first['status']);
            self::assertStringContainsString('application/json',$first['headers']['content-type']);self::assertStringContainsString('no-store',$first['headers']['cache-control']);self::assertArrayNotHasKey('set-cookie',$first['headers']);
            $page=json_decode($first['body'],true,512,JSON_THROW_ON_ERROR);self::assertSame($first['headers']['x-request-id'],$page['requestId']);self::assertSame(2,$page['snapshot']['documentCount']);self::assertSame('pending',$page['documents'][0]['status']);self::assertNotNull($page['documents'][0]['actionUrl']);
            $next=$this->get($base,'correct','selectorType=client&externalId=client%2Fexact&limit=1&cursor='.rawurlencode($page['nextCursor']));self::assertSame(200,$next['status']);$second=json_decode($next['body'],true,512,JSON_THROW_ON_ERROR);self::assertSame('unpaid',$second['documents'][0]['status']);self::assertNotNull($second['documents'][0]['actionUrl']);self::assertNull($second['nextCursor']);self::assertSame($page['snapshot']['sha256'],$second['snapshot']['sha256']);
            self::assertSame($before,$pdo->query('SELECT * FROM public_links ORDER BY id')->fetchAll());
            $pdo->exec("UPDATE api_keys SET revoked_at=NOW() WHERE key_prefix='correct'");self::assertSame(401,$this->get($base,'correct')['status']);
        });
    }
    private function withServer(bool $enabled,callable $test):void
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new \RuntimeException('Loopback reservation failed');$address=stream_socket_get_name($socket,false);fclose($socket);
        preg_match('/port=([0-9]+)/',(string)getenv('API_V2_FINANCIAL_MYSQL_DSN'),$port);
        $env=getenv();$env['DB_HOST']='127.0.0.1;port='.$port[1];$env['MYSQL_DATABASE']=(string)getenv('API_V2_FINANCIAL_MYSQL_DATABASE');$env['MYSQL_USER']='root';$env['MYSQL_PASSWORD']=(string)getenv('API_V2_FINANCIAL_MYSQL_PASSWORD');$env['APP_API_V2_FINANCIAL_DOCUMENTS_ENABLED']=$enabled?'true':'false';$env['APP_SKIP_DB_CONFIG']='1';
        $log=tmpfile();$process=proc_open([PHP_BINARY,'-S',$address,'-t','public','public/index.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,dirname(__DIR__,2),$env,['bypass_shell'=>true]);if(!is_resource($process))throw new \RuntimeException('Local PHP server unavailable');fclose($pipes[0]);
        try{$ready=false;for($i=0;$i<50;$i++){if(!proc_get_status($process)['running'])break;$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(100000);}self::assertTrue($ready,'Loopback server did not start');$test('http://'.$address);}
        finally{proc_terminate($process);proc_close($process);fclose($log);}
    }
    private function get(string $base,?string $token,string $query='selectorType=client&externalId=client%2Fexact',bool $wrongIdentity=false):array
    {
        $headers=['X-PA-Source-Instance-ID: '.($wrongIdentity?'wrong':'123e4567-e89b-42d3-a456-426614174000'),'X-PA-Application-ID: 223e4567-e89b-42d3-a456-426614174000','X-PA-History-Epoch: 323e4567-e89b-42d3-a456-426614174000'];if($token!==null)$headers[]='Authorization: Bearer '.$token;
        $body=file_get_contents($base.'/api/v2/financial-documents?'.$query,false,stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]));$raw=$http_response_header;preg_match('/\s([0-9]{3})\s/',$raw[0],$status);$parsed=[];foreach(array_slice($raw,1)as$line){if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$parsed[strtolower($key)]=trim($value);}}return['status'=>(int)$status[1],'headers'=>$parsed,'body'=>$body];
    }
}
