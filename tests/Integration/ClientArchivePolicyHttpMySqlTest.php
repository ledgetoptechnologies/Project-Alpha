<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Security\DatabaseSessionHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClientArchivePolicyHttpMySqlTest extends TestCase
{
    public function testProtectedNonePolicyArchivesAndRestoresThroughRealHttp():void
    {
        $dsn=(string)getenv('ARCHIVE_HTTP_DSN');$database=(string)getenv('ARCHIVE_HTTP_DATABASE');
        if($dsn==='')self::markTestSkipped('Use the disposable client archive HTTP runner.');
        if(getenv('ARCHIVE_HTTP_ALLOW')!=='isolated-disposable-only'||!preg_match('/^pa_archive_http_[a-f0-9]{32}$/D',$database)
            ||!preg_match('/^mysql:host=127\\.0\\.0\\.1;port=([0-9]+);dbname='.preg_quote($database,'/').';charset=utf8mb4$/D',$dsn,$match))throw new RuntimeException('Refusing non-disposable database.');
        $pdo=new PDO($dsn,'root',(string)getenv('ARCHIVE_HTTP_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        self::assertSame([],$pdo->query('SHOW TABLES')->fetchAll());
        $root=dirname(__DIR__,2);require_once $root.'/src/migrations/migration_lib.php';require_once $root.'/src/utils/api_v2_directory_revision.php';
        $pdo->exec('SET GLOBAL log_bin_trust_function_creators=1');
        $apply=static function(string $path)use($pdo):void{foreach(\migration_statements((string)file_get_contents($path))as$sql){$s=$pdo->query($sql);if($s!==false)$s->closeCursor();}};
        $apply($root.'/database/baseline.sql');
        foreach(\migration_files($root.'/database/migrations')as$file){\migration_preflight($pdo,(int)$file['version']);$apply($file['path']);$pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum)VALUES(?,?,?)')->execute([$file['version'],$file['filename'],$file['checksum']]);}
        \migration_schema_health($pdo);
        $pdo->exec("INSERT INTO users(id,email,password_hash,role)VALUES(91001,'archive-admin@example.invalid','unusable','admin');
            INSERT INTO clients(id,public_id,name,email,client_type)VALUES(91001,'11111111111111111111111111111111','Synthetic archive fixture','contact@example.invalid','consumer');
            INSERT INTO api_v2_applications(application_id,name)VALUES('11111111-1111-4111-8111-111111111111','Synthetic archive test');
            INSERT INTO portal_client_contact_identity_policy(client_id,portal_principal_id,login_email,application_pk,history_epoch,command_id,request_sha256)
            SELECT 91001,NULL,NULL,id,'22222222-2222-4222-8222-222222222222','33333333-3333-4333-8333-333333333333',REPEAT('a',64) FROM api_v2_applications WHERE application_id='11111111-1111-4111-8111-111111111111'");
        $before=$pdo->query('SELECT * FROM portal_client_contact_identity_policy WHERE client_id=91001')->fetch();
        $pdo->beginTransaction();\api_v2_directory_record($pdo,'client',91001);$pdo->commit();
        // Prove the production failure's RESTRICT constraint is present, not disabled.
        try{$pdo->exec('DELETE FROM clients WHERE id=91001');self::fail('RESTRICT missing');}catch(\PDOException $e){self::assertSame('23000',$e->getCode());}
        $admin=$this->session($pdo,91001,'admin');
        $this->withServer((int)$match[1],function(string $base)use($pdo,$admin,$before):void{
            $archive=$this->request($base.'/?page=client/clients-delete',$admin['id'],['csrf'=>$admin['csrf'],'id'=>91001]);
            self::assertSame(302,$archive['status'],$archive['body']);self::assertStringContainsString('archived=1',$archive['headers']['location']);
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM clients WHERE id=91001')->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_client_contact_identity_policy WHERE client_id=91001')->fetchColumn());
            $saved=$pdo->query("SELECT * FROM archived_entities WHERE entity_type='portal_contact_identity_policy'")->fetch();
            $savedPolicy=json_decode($saved['payload'],true,512,JSON_THROW_ON_ERROR);$expectedPolicy=$before;
            ksort($savedPolicy);ksort($expectedPolicy);self::assertSame($expectedPolicy,$savedPolicy);
            self::assertSame(0,(int)$pdo->query("SELECT present FROM api_v2_directory_resource_state WHERE public_id='11111111111111111111111111111111'")->fetchColumn());
            $pdo->exec("INSERT INTO clients(id,public_id,name,email,client_type)VALUES(91001,'22222222222222222222222222222222','Unrelated replacement','untouched@example.invalid','consumer')");
            $unrelated=$pdo->query('SELECT * FROM clients WHERE id=91001')->fetch();
            $restore=$this->request($base.'/?page=client/clients-restore',$admin['id'],['csrf'=>$admin['csrf'],'id'=>$saved['entity_id']]);
            self::assertSame(302,$restore['status'],$restore['body']);self::assertStringContainsString('restored=1',$restore['headers']['location']);
            self::assertSame($unrelated,$pdo->query('SELECT * FROM clients WHERE id=91001')->fetch());
            $restored=$pdo->query("SELECT id,public_id FROM clients WHERE public_id='11111111111111111111111111111111'")->fetch();
            self::assertNotSame(91001,(int)$restored['id']);$before['client_id']=$restored['id'];
            self::assertSame($before,$pdo->query('SELECT * FROM portal_client_contact_identity_policy WHERE client_id='.(int)$restored['id'])->fetch());
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM archived_entities WHERE entity_type='portal_contact_identity_policy'")->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM archived_clients')->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
            self::assertSame(1,(int)$pdo->query("SELECT present FROM api_v2_directory_resource_state WHERE public_id='11111111111111111111111111111111'")->fetchColumn());
            // A lost snapshot must not fall through to ordinary email-based enrollment.
            $archive=$this->request($base.'/?page=client/clients-delete',$admin['id'],['csrf'=>$admin['csrf'],'id'=>$restored['id']]);
            self::assertStringContainsString('archived=1',$archive['headers']['location']);
            $archiveRow=$pdo->query('SELECT * FROM archived_clients')->fetch();
            self::assertTrue(json_decode($archiveRow['portal_identity_binding_ids_json'],true,512,JSON_THROW_ON_ERROR)['contact_policy_expected']);
            $pdo->prepare("DELETE FROM archived_entities WHERE entity_type='portal_contact_identity_policy' AND entity_id=? AND client_id=?")
                ->execute([$archiveRow['id'],$restored['id']]);
            $authorityTables=['portal_principals','portal_principal_clients','portal_v2_entitlements','portal_identity_bindings'];
            $authority=[];
            foreach($authorityTables as$table)$authority[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
            $restore=$this->request($base.'/?page=client/clients-restore',$admin['id'],['csrf'=>$admin['csrf'],'id'=>$archiveRow['id']]);
            self::assertSame(302,$restore['status']);
            self::assertStringNotContainsString('restored=1',$restore['headers']['location']);
            self::assertSame($archiveRow,$pdo->query('SELECT * FROM archived_clients')->fetch());
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM clients WHERE public_id='11111111111111111111111111111111'")->fetchColumn());
            foreach($authorityTables as$table)self::assertSame($authority[$table],$pdo->query('SELECT * FROM '.$table)->fetchAll(),$table);
            self::assertSame($unrelated,$pdo->query('SELECT * FROM clients WHERE id=91001')->fetch());
            self::assertSame(0,(int)$pdo->query("SELECT present FROM api_v2_directory_resource_state WHERE public_id='11111111111111111111111111111111'")->fetchColumn());
        });
    }
    private function session(PDO $pdo, int $userId, string $role, array $previews = []): array
    {
        $id = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $_SESSION = ['user' => ['id' => $userId, 'email' => $role . '@example.invalid', 'role' => $role, 'auth_version' => 1], 'csrf' => $csrf,
            'last_activity' => time(), 'authn' => ['method' => 'password', 'authenticated_at' => time(), 'absolute_expires_at' => time() + 3600]];
        if ($previews !== []) $_SESSION['stripe_reconciliation_preview'] = $previews;
        $payload = '';
        foreach ($_SESSION as $key => $value) $payload .= $key . '|' . serialize($value);
        $handler = new DatabaseSessionHandler($pdo);
        self::assertTrue($handler->write($id, $payload));
        self::assertTrue($handler->close());
        $_SESSION = [];
        return ['id' => $id, 'csrf' => $csrf];
    }

    private function assertCanonicalFailure(array $response): void
    {
        self::assertSame(302, $response['status'], $response['body']);
        $location = parse_url($response['headers']['location']);
        self::assertArrayNotHasKey('host', $location);
        self::assertSame('/', $location['path']);
        parse_str($location['query'] ?? '', $query);
        self::assertSame('payments/payments-list', $query['page'] ?? null);
        self::assertNotEmpty($query['error'] ?? null);
    }

    private function withServer(int $port, callable $test): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) throw new RuntimeException('Loopback port reservation failed');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $env = getenv();
        $env['DB_HOST'] = '127.0.0.1;port=' . $port;
        $env['MYSQL_DATABASE'] = (string)getenv('ARCHIVE_HTTP_DATABASE');
        $env['MYSQL_USER'] = 'root';
        $env['MYSQL_PASSWORD'] = (string)getenv('ARCHIVE_HTTP_PASSWORD');
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

