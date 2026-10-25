<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

/** Actual controller execution with synthetic authenticated sessions and real MySQL. */
final class DocumentRecurringRateControllerTest extends TestCase
{
    private PDO $pdo;
    private int $client = 0;
    private array $users = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $this->pdo->query('SELECT monthly_rate FROM contracts LIMIT 0');
        } catch (Throwable) {
            self::markTestSkipped('Migrated MySQL is required for controller integration tests.');
        }
        foreach (['admin','user'] as $role) {
            $this->pdo->prepare('INSERT INTO users (username,email,password_hash,role,is_disabled) VALUES (?,?,?,?,1)')
                ->execute(['Synthetic rate controller ' . bin2hex(random_bytes(4)),bin2hex(random_bytes(8)).'@example.invalid',password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),$role]);
            $this->users[] = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->exec("INSERT INTO clients (name,email) VALUES ('Synthetic rate controller client','never-send@example.invalid')");
        $this->client = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->client) {
            foreach (['invoice'=>'invoices','contract'=>'contracts','quote'=>'quotes'] as $type=>$table) {
                foreach ($this->rows($table) as $row) {
                    $this->pdo->prepare('DELETE FROM document_revisions WHERE document_type=? AND document_id=?')->execute([$type,$row['id']]);
                    $this->pdo->prepare('DELETE FROM public_links WHERE document_type=? AND document_id=?')->execute([$type,$row['id']]);
                }
                $this->pdo->prepare("DELETE FROM $table WHERE client_id=?")->execute([$this->client]);
            }
            $this->pdo->prepare('DELETE FROM jobs WHERE client_id=?')->execute([$this->client]);
            $this->pdo->prepare('DELETE FROM activity_log WHERE client_id=?')->execute([$this->client]);
            $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
        }
        foreach ($this->users as $id) $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    }

    private function rows(string $table): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM $table WHERE client_id=? ORDER BY id");
        $statement->execute([$this->client]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function basePost(): array
    {
        return ['client_id'=>$this->client,'doc_type'=>'long_term','scope'=>'Signed service terms remain separate',
            'rate_basis'=>'monthly','monthly_rate'=>'100.01','price_per_invoice'=>'1','lt_price_per_invoice'=>'1',
            'start_date'=>'2026-01-01','lt_start_date'=>'2026-01-01','billing_interval_count'=>1,'lt_billing_interval_count'=>1,
            'billing_interval_unit'=>'year','lt_billing_interval_unit'=>'year','pricing_type'=>'per_invoice','lt_pricing_type'=>'per_invoice',
            'billing_start_mode'=>'manual','deposit_type'=>'none','deposit_value'=>'0','discount_type'=>'none','tax_percent'=>'0','document_issuer_mode'=>'company'];
    }

    private function post(string $controller, array $post, bool $publicCsrf = false, bool $unauthorized = false): array
    {
        $root = dirname(__DIR__, 2);
        $log = tempnam(sys_get_temp_dir(), 'pa-rate-controller-');
        $response = tempnam(sys_get_temp_dir(), 'pa-rate-response-');
        self::assertNotFalse($log);self::assertNotFalse($response);
        $user = $unauthorized ? $this->users[1] : $this->users[0];
        $script = 'require '.var_export($root.'/vendor/autoload.php',true).';'
            .'ini_set("log_errors","1");ini_set("error_log",'.var_export($log,true).');'
            .'session_start();$_SESSION=["user"=>["id"=>'.$user.',"role"=>'.var_export($unauthorized?'user':'admin',true).']];'
            .'$_POST='.var_export($post,true).';$_SERVER["REQUEST_METHOD"]="POST";$_SERVER["REMOTE_ADDR"]="192.0.2.90";$_SERVER["HTTP_X_REQUESTED_WITH"]="XMLHttpRequest";'
            .'require '.var_export($root.'/src/config/db.php',true).';require '.var_export($root.'/src/config/app.php',true).';require '.var_export($root.'/src/utils/acl.php',true).';'
            .'$appConfig["invoice_auto_email_on_generate"]=0;$appConfig["quote_auto_create_contract"]=1;$appConfig["smtp_host"]="";$appConfig["email_provider"]="disabled";'
            .'register_shutdown_function(static function(){file_put_contents('.var_export($response,true).',json_encode(["status"=>http_response_code()]));});';
        if ($publicCsrf) $script .= 'require '.var_export($root.'/src/utils/csrf_sf.php',true).';$_POST["csrf"]=csrf_sf_token("public_quote_action");';
        $script .= 'require '.var_export($root.'/src/controllers/'.$controller,true).';';
        try {
            chmod($log, 0600);chmod($response, 0600);
            $process = proc_open([PHP_BINARY,'-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
            self::assertIsResource($process);fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);$stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$exit = proc_close($process);
            $result = ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr,'log'=>file_get_contents($log),'response'=>json_decode((string)file_get_contents($response),true)];
            self::assertSame(0, $exit, $stderr . $result['log']);
            return $result;
        } finally {
            unlink($log);unlink($response);
        }
    }

    private function service(int $contractId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM contract_recurring_services WHERE contract_id=? AND is_base=1');
        $statement->execute([$contractId]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function testContractCreationRecalculatesForgedAnnualAmountAndDraftEditsPreserveBasis(): void
    {
        $result = $this->post('contract/long_term_contracts_create.php', $this->basePost());
        $rows = $this->rows('contracts');self::assertCount(1, $rows, $result['log']);$contract = $rows[0];
        self::assertSame('1200.12', $contract['price_per_invoice']);
        self::assertSame('1200.12', $contract['total']);
        self::assertSame('monthly', $contract['rate_basis']);
        self::assertSame('100.01', $this->service((int)$contract['id'])['monthly_rate']);

        $edit = array_replace($this->basePost(), ['id'=>$contract['id'],'billing_interval_unit'=>'month','monthly_rate'=>'125.03','next_invoice_date'=>'2026-01-01']);
        $result = $this->post('contract/contracts_update.php', $edit);
        $edited = $this->rows('contracts')[0];
        self::assertSame('125.03', $edited['price_per_invoice'], $result['log']);
        self::assertSame('125.03', $this->service((int)$contract['id'])['amount']);
        unset($edit['rate_basis'],$edit['monthly_rate']);$edit['price_per_invoice']='0.01';
        $this->post('contract/contracts_update.php', $edit);
        self::assertSame('125.03', $this->rows('contracts')[0]['price_per_invoice']);
        self::assertSame('monthly', $this->rows('contracts')[0]['rate_basis']);
        $beforeInvalid = $this->rows('contracts')[0];
        $beforeService = $this->service((int)$contract['id']);
        $this->post('contract/contracts_update.php', array_replace($edit,['billing_interval_unit'=>'week']));
        self::assertSame($beforeInvalid,$this->rows('contracts')[0]);
        self::assertSame($beforeService,$this->service((int)$contract['id']));
        $this->post('contract/contracts_update.php', array_replace($edit,['rate_basis'=>'per_period','price_per_invoice'=>'450.67']));
        self::assertSame('per_period',$this->rows('contracts')[0]['rate_basis']);
        self::assertSame('450.67',$this->rows('contracts')[0]['price_per_invoice']);
        self::assertNull($this->rows('contracts')[0]['monthly_rate']);
        self::assertNull($this->service((int)$contract['id'])['monthly_rate']);
    }

    public function testLegacyContractWithoutRateFieldsKeepsPerInvoiceAmount(): void
    {
        $post = $this->basePost();unset($post['rate_basis'],$post['monthly_rate']);$post['price_per_invoice']='650.23';
        $result = $this->post('contract/long_term_contracts_create.php', $post);
        $rows = $this->rows('contracts');self::assertCount(1,$rows,$result['log']);
        self::assertSame('per_period',$rows[0]['rate_basis']);self::assertNull($rows[0]['monthly_rate']);
        self::assertSame('650.23',$rows[0]['price_per_invoice']);
        self::assertSame('650.23',$this->service((int)$rows[0]['id'])['amount']);
    }

    public function testQuoteCreationEditAndAuthenticatedConversionCarryMonthlyRate(): void
    {
        $result = $this->post('quote/quotes_create.php',$this->basePost());
        $quotes = $this->rows('quotes');self::assertCount(1,$quotes,$result['log']);$quote = $quotes[0];
        self::assertSame('1200.12',$quote['price_per_invoice']);self::assertSame('monthly',$quote['rate_basis']);
        $result = $this->post('quote/quotes_update.php',array_replace($this->basePost(),['id'=>$quote['id'],'monthly_rate'=>'125.03']));
        $quote = $this->rows('quotes')[0];self::assertSame('1500.36',$quote['price_per_invoice'],$result['log']);
        $this->post('quote/quotes_update.php',array_replace($this->basePost(),['id'=>$quote['id'],'billing_interval_unit'=>'week']));
        self::assertSame($quote,$this->rows('quotes')[0], 'Invalid cadence must not partially mutate a draft quote.');
        $this->pdo->prepare('UPDATE quotes SET status="pending" WHERE id=?')->execute([$quote['id']]);
        $result = $this->post('quote/quote_approve.php',['id'=>$quote['id']]);
        $contracts = $this->rows('contracts');self::assertCount(1,$contracts,$result['log']);
        self::assertSame('monthly',$contracts[0]['rate_basis']);self::assertSame('125.03',$contracts[0]['monthly_rate']);
        self::assertSame('1500.36',$contracts[0]['price_per_invoice']);
        self::assertSame('125.03',$this->service((int)$contracts[0]['id'])['monthly_rate']);
        self::assertCount(0,$this->rows('invoices'),'Quote acceptance must not bill an unsigned recurring agreement.');
    }

    public function testPublicQuoteApprovalCarriesRateThroughRealTokenAndCsrfValidation(): void
    {
        $result = $this->post('quote/quotes_create.php',$this->basePost());
        $quotes = $this->rows('quotes');self::assertCount(1,$quotes,$result['log']);$quote = $quotes[0];
        $this->pdo->prepare('UPDATE quotes SET status="pending" WHERE id=?')->execute([$quote['id']]);
        $token = bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO public_links (token,document_type,document_id,expires_at,revoked) VALUES (?,"quote",?,DATE_ADD(NOW(),INTERVAL 1 DAY),0)')->execute([$token,$quote['id']]);
        $result = $this->post('public_view/public_quote_action.php',['token'=>$token,'action'=>'approve'],true);
        $contracts = $this->rows('contracts');self::assertCount(1,$contracts,$result['log'] . $result['stdout']);
        self::assertSame('monthly',$contracts[0]['rate_basis']);self::assertSame('100.01',$contracts[0]['monthly_rate']);
        self::assertSame('1200.12',$this->service((int)$contracts[0]['id'])['amount']);
        self::assertCount(0,$this->rows('invoices'));
    }

    public function testInvalidWeeklyMonthlyRateCreatesNoDocuments(): void
    {
        $post = array_replace($this->basePost(),['billing_interval_unit'=>'week','lt_billing_interval_unit'=>'week']);
        $this->post('contract/long_term_contracts_create.php',$post);
        $this->post('quote/quotes_create.php',$post);
        self::assertCount(0,$this->rows('contracts'));self::assertCount(0,$this->rows('quotes'));self::assertCount(0,$this->rows('invoices'));
    }

    public function testServiceSaveRejectsStaleBoundaryAndUnauthorizedUserBeforeQueuingApprovedChange(): void
    {
        $result = $this->post('contract/long_term_contracts_create.php',$this->basePost());
        $contracts = $this->rows('contracts');self::assertCount(1,$contracts,$result['log']);$id = (int)$contracts[0]['id'];
        $renewal = date('Y-m-d',strtotime('+1 year'));
        $this->pdo->prepare('UPDATE contracts SET status="active",signed_pdf_path="synthetic.pdf",next_invoice_date=? WHERE id=?')->execute([$renewal,$id]);
        $this->pdo->prepare('UPDATE contract_recurring_services SET status="active",next_invoice_date=? WHERE contract_id=?')->execute([$renewal,$id]);
        $before = $this->service($id);
        $post = ['contract_id'=>$id,'service_id'=>$before['id'],'name'=>$before['name'],'amount'=>'1','rate_basis'=>'monthly','monthly_rate'=>'100.01','billing_interval_count'=>1,'billing_interval_unit'=>'month','effective_from'=>date('Y-m-d'),'next_invoice_date'=>date('Y-m-d'),'client_approved'=>1];
        $this->post('contract/long_term_recurring_service_save.php',$post);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM contract_recurring_service_changes WHERE contract_id=?');$count->execute([$id]);self::assertSame(0,(int)$count->fetchColumn());
        self::assertSame($before,$this->service($id));
        $post['next_invoice_date']=$renewal;$post['effective_from']=$renewal;
        $denied=$this->post('contract/long_term_recurring_service_save.php',$post,false,true);
        self::assertSame(403,$denied['response']['status']);$count->execute([$id]);self::assertSame(0,(int)$count->fetchColumn());
        $this->post('contract/long_term_recurring_service_save.php',$post);
        $count->execute([$id]);self::assertSame(1,(int)$count->fetchColumn());self::assertSame($before,$this->service($id));
        $stmt=$this->pdo->prepare('SELECT * FROM contract_recurring_service_changes WHERE contract_id=?');$stmt->execute([$id]);$change=$stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame($renewal,$change['effective_date']);self::assertSame('approved',$change['state']);
        self::assertSame('100.01',json_decode($change['new_values'],true)['amount']);
    }
}
