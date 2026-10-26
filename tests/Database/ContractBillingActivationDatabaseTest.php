<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/contract_billing_start.php';
require_once dirname(__DIR__, 2) . '/src/utils/recurring_services.php';

/** Runs real upload and activation controllers through a disposable local HTTP server. */
final class ContractBillingActivationDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $client = 0;
    private int $user = 0;
    private array $contracts = [];
    private mixed $server = null;
    private string $temporary = '';
    private string $baseUrl = '';
    private int $responseStatus = 0;
    private string $responseBody = '';

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $this->pdo->query('SELECT billing_anchor_date FROM contract_recurring_services LIMIT 0');
        } catch (Throwable) {
            self::markTestSkipped('Migrated MySQL is required for activation tests.');
        }
        $this->pdo->prepare('INSERT INTO users (username,email,password_hash,role,is_disabled) VALUES (?,?,?,"admin",1)')
            ->execute(['Synthetic activation test',bin2hex(random_bytes(8)).'@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
        $this->user = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO clients (name,email) VALUES ('Synthetic activation client','never-send@example.invalid')");
        $this->client = (int)$this->pdo->lastInsertId();
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) { proc_terminate($this->server);proc_close($this->server); }
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($this->contracts as $id) {
                $row = $this->contract($id);
                $path = (string)($row['signed_pdf_path'] ?? '');
                if (str_starts_with($path,'/?page=serve-upload&file=')) {
                    $relative=rawurldecode(substr($path,strlen('/?page=serve-upload&file=')));
                    if (preg_match('~^signed_contracts/[0-9]+_[a-f0-9]+\.pdf$~D',$relative)) {
                        $file=dirname(__DIR__,2).'/src/uploads/'.$relative;
                        if (is_file($file)) unlink($file);
                    }
                }
                $this->pdo->prepare('DELETE FROM payments WHERE contract_id=?')->execute([$id]);
                $this->pdo->prepare("DELETE FROM public_links WHERE document_type='invoice' AND document_id IN (SELECT id FROM invoices WHERE contract_id=?)")->execute([$id]);
                $this->pdo->prepare('DELETE FROM invoices WHERE contract_id=?')->execute([$id]);
                $this->pdo->prepare("DELETE FROM public_links WHERE document_type='contract' AND document_id=?")->execute([$id]);
                $this->pdo->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);
            }
            if ($this->client) {
                $this->pdo->prepare('DELETE FROM jobs WHERE client_id=?')->execute([$this->client]);
                $this->pdo->prepare('DELETE FROM activity_log WHERE client_id=?')->execute([$this->client]);
                $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
            }
            if ($this->user) $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->user]);
        }
        if ($this->temporary !== '' && is_dir($this->temporary)) {
            foreach (glob($this->temporary.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
            rmdir($this->temporary);
        }
    }

    private function startServer(): void
    {
        $root=dirname(__DIR__,2);
        $this->temporary=sys_get_temp_dir().'/pa-activation-'.bin2hex(random_bytes(10));
        mkdir($this->temporary,0700);
        $routes=['history'=>'contract/long_term_recurring_history.php','activate'=>'contract/long_term_contract_activate.php','start'=>'contract/long_term_contract_start_billing.php','staff-upload'=>'contract/contract_sign.php','public-pdf'=>'public_view/public_contract_action.php','public-upload'=>'public_view/public_contract_sign.php'];
        $script='<?php require '.var_export($root.'/vendor/autoload.php',true).';session_start();'
            .'$_SESSION=["user"=>["id"=>'.$this->user.',"role"=>"admin"]];'
            .'require '.var_export($root.'/src/config/db.php',true).';require '.var_export($root.'/src/config/app.php',true).';require '.var_export($root.'/src/utils/acl.php',true).';'
            .'$appConfig["invoice_auto_email_on_generate"]=0;$appConfig["notify_signed_contract_uploaded"]=false;$appConfig["smtp_host"]="";'
            .'$_SESSION["csrf"]="synthetic-history-csrf-token-for-http-tests";$_SESSION["user"]["role"]=$pdo->query("SELECT role FROM users WHERE id='.$this->user.'")->fetchColumn();'
            .'$routes='.var_export($routes,true).';$route=$_GET["route"]??"";if(!isset($routes[$route])){echo "ready";return;}'
            .'if(str_starts_with($route,"public")){require '.var_export($root.'/src/utils/csrf_sf.php',true).';$_POST["csrf"]=csrf_sf_token($route==="public-pdf"?"public_contract_action":"public_contract_sign");}'
            .'require '.var_export($root.'/src/controllers/',true).'.$routes[$route];';
        file_put_contents($this->temporary.'/router.php',$script);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);self::assertIsResource($socket,$error);
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $this->baseUrl='http://'.$address;
        $this->server=proc_open([PHP_BINARY,'-d','session.save_path='.$this->temporary,'-d','log_errors=1','-d','error_log='.$this->temporary.'/php.log','-S',$address,$this->temporary.'/router.php'],[0=>['pipe','r'],1=>['file',$this->temporary.'/server.log','a'],2=>['file',$this->temporary.'/server.log','a']],$pipes,$root);
        self::assertIsResource($this->server);fclose($pipes[0]);
        for($i=0;$i<60;$i++) {
            $probe=@file_get_contents($this->baseUrl, false, stream_context_create(['http'=>['timeout'=>0.2]]));
            if($probe==='ready')return;
            usleep(50000);
        }
        self::fail('Disposable HTTP fixture failed to start: '.file_get_contents($this->temporary.'/server.log'));
    }

    private function fixture(?string $date, string $mode='on_upload', bool $signed=true, string $status='pending'): int
    {
        $this->pdo->prepare('INSERT INTO contracts (client_id,created_by,status,contract_type,pricing_type,price_per_invoice,subtotal,total,start_date,next_invoice_date,billing_interval_count,billing_interval_unit,billing_start_mode,signed_pdf_path,rate_basis,monthly_rate) VALUES (?,?,?,"long_term","per_invoice",100,100,100,?,?,1,"month",?,?,"monthly",100)')
            ->execute([$this->client,$this->user,$status,date('Y-m-d',strtotime('-1 month')),$date,$mode,$signed?'synthetic-existing.pdf':null]);
        $id=(int)$this->pdo->lastInsertId();$this->contracts[]=$id;
        pa_recurring_service_ensure_base($this->pdo,$id);
        return $id;
    }

    private function contract(int $id): array
    {
        $s=$this->pdo->prepare('SELECT * FROM contracts WHERE id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function service(int $id): array
    {
        $s=$this->pdo->prepare('SELECT * FROM contract_recurring_services WHERE contract_id=? AND is_base=1');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function token(int $id): string
    {
        $token=bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO public_links (token,document_type,document_id,expires_at,revoked) VALUES (?,"contract",?,DATE_ADD(NOW(),INTERVAL 1 DAY),0)')->execute([$token,$id]);
        return $token;
    }

    private function post(string $route,array $fields,bool $upload=false): string
    {
        $headers='Content-Type: application/x-www-form-urlencoded';$body=http_build_query($fields);
        if($upload){
            $boundary='pa'.bin2hex(random_bytes(12));$headers='Content-Type: multipart/form-data; boundary='.$boundary;$body='';
            foreach($fields as $name=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".$value."\r\n";
            $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"signed_pdf\"; filename=\"synthetic.pdf\"\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n\r\n--".$boundary."--\r\n";
        }
        $response=file_get_contents($this->baseUrl.'/?route='.$route,false,stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]));
        self::assertNotFalse($response);
        self::assertStringNotContainsString('Fatal error',$response);
        $this->responseBody=$response;
        preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'', $status);
        $this->responseStatus=(int)($status[1]??0);
        $location='';foreach($http_response_header??[] as $header)if(str_starts_with(strtolower($header),'location:'))$location=trim(substr($header,9));
        return $location;
    }

    private function assertNoInvoiceOrOutbox(int $id): void
    {
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE contract_id=?');$s->execute([$id]);self::assertSame(0,(int)$s->fetchColumn());
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM invoice_notifications n JOIN invoices i ON i.id=n.invoice_id WHERE i.contract_id=?');$s->execute([$id]);self::assertSame(0,(int)$s->fetchColumn());
    }

    public function testActivatePreservesFutureDateAndRepeatCannotAdvanceIt(): void
    {
        $future=date('Y-m-d',strtotime('+1 month'));$id=$this->fixture($future);
        self::assertStringContainsString('activated=1',$this->post('activate',['id'=>$id]));
        self::assertSame('active',$this->contract($id)['status']);self::assertSame($future,$this->contract($id)['next_invoice_date']);
        self::assertSame($future,$this->service($id)['next_invoice_date']);$this->assertNoInvoiceOrOutbox($id);
        self::assertStringContainsString('error=',$this->post('activate',['id'=>$id]));
        self::assertStringContainsString('error=',$this->post('start',['id'=>$id]));$this->assertNoInvoiceOrOutbox($id);
    }

    public function testExplicitStartPreservesHistoricalServiceCursorDespiteExistingInvoiceCount(): void
    {
        $future=date('Y-m-d',strtotime('+1 month'));$id=$this->fixture(date('Y-m-d'),'manual');
        $this->pdo->prepare('UPDATE contracts SET invoices_generated=2,total_invoiced=200,last_invoice_date=? WHERE id=?')->execute([date('Y-m-d',strtotime('-1 month')),$id]);
        $this->pdo->prepare('UPDATE contract_recurring_services SET next_invoice_date=?,last_invoice_date=? WHERE contract_id=?')->execute([$future,date('Y-m-d',strtotime('-1 month')),$id]);
        self::assertStringContainsString('billing_started=1',$this->post('start',['id'=>$id]));
        self::assertSame($future,$this->contract($id)['next_invoice_date']);self::assertSame('active',$this->service($id)['status']);$this->assertNoInvoiceOrOutbox($id);
    }

    public function testLegacyNoDateActivationBillsOnceImmediatelyAndFutureServiceStartWaits(): void
    {
        $id=$this->fixture(null);$this->post('activate',['id'=>$id]);
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE contract_id=?');$s->execute([$id]);self::assertSame(1,(int)$s->fetchColumn());
        $this->post('activate',['id'=>$id]);$s->execute([$id]);self::assertSame(1,(int)$s->fetchColumn());
        $future=date('Y-m-d',strtotime('+2 months'));$other=$this->fixture(null);
        $this->pdo->prepare('UPDATE contracts SET start_date=? WHERE id=?')->execute([$future,$other]);
        $this->post('activate',['id'=>$other]);self::assertSame($future,$this->contract($other)['next_invoice_date']);$this->assertNoInvoiceOrOutbox($other);
    }

    public function testStaffUploadPreservesSelectedDateAndManualModeDoesNotStartBilling(): void
    {
        $future=date('Y-m-d',strtotime('+1 month'));
        foreach(['on_upload','manual'] as $mode){
            $id=$this->fixture($future,$mode,false,'draft');
            self::assertStringContainsString('signed=1',$this->post('staff-upload',['id'=>$id],true));
            $contract=$this->contract($id);self::assertNotEmpty($contract['signed_pdf_path']);self::assertSame('pending',$contract['status']);
            self::assertSame($future,$contract['next_invoice_date']);self::assertSame('pending',$this->service($id)['status']);$this->assertNoInvoiceOrOutbox($id);
        }
    }

    public function testStaffUploadWithoutSelectedDateLeavesImplicitStartForActivation(): void
    {
        $id=$this->fixture(null,'on_upload',false,'draft');
        self::assertStringContainsString('signed=1',$this->post('staff-upload',['id'=>$id],true));
        self::assertNull($this->contract($id)['next_invoice_date']);
        self::assertNull($this->service($id)['next_invoice_date']);
        $this->assertNoInvoiceOrOutbox($id);
        $this->post('activate',['id'=>$id]);
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE contract_id=?');$s->execute([$id]);self::assertSame(1,(int)$s->fetchColumn());
        self::assertSame(date('Y-m-d'),$this->service($id)['billing_anchor_date']);
    }

    public function testBothPublicUploadPathsRespectFutureDateAndManualModeAndRejectReplay(): void
    {
        $future=date('Y-m-d',strtotime('+1 month'));
        foreach(['public-pdf','public-upload'] as $route)foreach(['on_upload','manual'] as $mode){
            $id=$this->fixture($future,$mode,false);$token=$this->token($id);
            $location=$this->post($route,['token'=>$token],true);self::assertStringNotContainsString('error=',$location,$location);
            $before=$this->contract($id);self::assertNotEmpty($before['signed_pdf_path']);self::assertSame($mode==='manual'?'pending':'active',$before['status']);
            self::assertSame($future,$before['next_invoice_date']);self::assertSame($mode==='manual'?'pending':'active',$this->service($id)['status']);$this->assertNoInvoiceOrOutbox($id);
            $this->post($route,['token'=>$token],true);self::assertSame($before,$this->contract($id));$this->assertNoInvoiceOrOutbox($id);
        }
    }

    public function testPublicSigningRechecksRevocationAndLatestScheduleUnderTransactionLock(): void
    {
        $id=$this->fixture(date('Y-m-d'),'on_upload',false);$token=$this->token($id);
        $future=date('Y-m-d',strtotime('+1 month'));
        $this->pdo->prepare('UPDATE contracts SET next_invoice_date=? WHERE id=?')->execute([$future,$id]);
        $this->pdo->prepare('UPDATE contract_recurring_services SET next_invoice_date=? WHERE contract_id=?')->execute([$future,$id]);
        $this->pdo->beginTransaction();$fresh=pa_contract_lock_public_signing($this->pdo,$id,$token);
        self::assertSame($future,pa_long_term_billing_start_date($this->pdo,$fresh));$this->pdo->rollBack();
        $this->pdo->prepare('UPDATE public_links SET revoked=1 WHERE token=?')->execute([$token]);
        $this->pdo->beginTransaction();$this->expectException(DomainException::class);pa_contract_lock_public_signing($this->pdo,$id,$token);
    }

    public function testHistoryHttpRejectsCsrfMissingPaymentPermissionAndForeignOwnership(): void
    {
        $id=$this->fixture(date('Y-m-d',strtotime('+1 month')),'manual',false,'draft');
        $fields=['contract_id'=>$id,'service_id'=>$this->service($id)['id'],'history_action'=>'preview','csrf'=>'wrong'];
        $this->post('history',$fields);self::assertSame(403,$this->responseStatus);
        $fields['csrf']='synthetic-history-csrf-token-for-http-tests';
        $this->pdo->prepare('UPDATE users SET role="member" WHERE id=?')->execute([$this->user]);
        $this->pdo->prepare('INSERT INTO user_permissions_overrides(user_id,permission,allowed) VALUES (?,"contracts.edit",1),(?,"invoices.mark_paid",0)')->execute([$this->user,$this->user]);
        $this->post('history',$fields);self::assertSame(403,$this->responseStatus);self::assertStringContainsString('both required',$this->responseBody);
        $this->pdo->prepare('UPDATE user_permissions_overrides SET allowed=1 WHERE user_id=?')->execute([$this->user]);
        $this->pdo->prepare('UPDATE contracts SET created_by=NULL WHERE id=?')->execute([$id]);
        $this->post('history',$fields);self::assertSame(403,$this->responseStatus);self::assertStringContainsString('not available',$this->responseBody);
        $this->assertNoInvoiceOrOutbox($id);
    }

    public function testHistoryHttpPreviewIsReadOnlyAndRejectsServiceFromAnotherAgreement(): void
    {
        $future=(new DateTimeImmutable('first day of next month'))->format('Y-m-d');
        $id=$this->fixture($future,'manual',false,'draft');
        $other=$this->fixture($future,'manual',false,'draft');
        $this->pdo->prepare('UPDATE contracts SET start_date=? WHERE id=?')->execute([$future,$id]);
        $this->pdo->prepare('UPDATE contract_recurring_services SET effective_from=?,billing_anchor_date=? WHERE contract_id=?')->execute([$future,$future,$id]);
        $fields=['contract_id'=>$id,'service_id'=>$this->service($id)['id'],'history_action'=>'preview','csrf'=>'synthetic-history-csrf-token-for-http-tests','first_period_start'=>(new DateTimeImmutable('first day of last month'))->format('Y-m-d'),'months'=>2,'payment_date'=>date('Y-m-d'),'reference_number'=>'SYNTHETIC-HTTP','paid_amount'=>'200.00','generation_key'=>bin2hex(random_bytes(16))];
        $before=$this->contract($id);$service=$this->service($id);
        $this->post('history',$fields);self::assertSame(200,$this->responseStatus,$this->responseBody.' '.(is_file($this->temporary.'/php.log')?file_get_contents($this->temporary.'/php.log'):''));
        $json=json_decode($this->responseBody,true,512,JSON_THROW_ON_ERROR);self::assertTrue($json['success']);self::assertSame('200.00',$json['total']);self::assertNotEmpty($json['preview_hash']);
        self::assertSame($before,$this->contract($id));self::assertSame($service,$this->service($id));$this->assertNoInvoiceOrOutbox($id);
        $fields['service_id']=$this->service($other)['id'];
        $this->post('history',$fields);self::assertSame(409,$this->responseStatus);self::assertFalse(json_decode($this->responseBody,true)['success']);
        $this->assertNoInvoiceOrOutbox($id);$this->assertNoInvoiceOrOutbox($other);
    }
}
