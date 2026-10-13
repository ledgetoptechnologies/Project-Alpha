<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinancialCountingPdo extends PDO
{
    public array $preparedSql=[];
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->preparedSql[]=$query;return parent::prepare($query,$options);
    }
}

final class ApiV2FinancialProjectionFoundationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('PDO SQLite is required.');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_financial_projection.php';
        $this->pdo = new FinancialCountingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE public_links(id INTEGER PRIMARY KEY,token TEXT,document_type TEXT,document_id INTEGER,expires_at TEXT,revoked INTEGER,created_at TEXT)');
        $this->pdo->exec("CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,name TEXT,email TEXT,phone TEXT,client_type TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,general_email TEXT,general_phone TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,client_id INTEGER,organization_id INTEGER,revision TEXT,name TEXT,description TEXT,status TEXT,completed_at TEXT,archived_at TEXT,estimated_start TEXT,estimated_end TEXT);
            CREATE TABLE project_changes(project_public_id TEXT,revision TEXT,projection_sha256 TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision TEXT,projection_sha256 TEXT,present INTEGER);
            CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id TEXT,public_id TEXT,status TEXT,resource_revision TEXT,resource_projection_sha256 TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER,authorization_generation TEXT);
            CREATE TABLE api_v2_project_authorization_state(application_pk INTEGER,authorization_generation TEXT);
            CREATE TABLE api_v2_project_external_bindings(application_pk INTEGER,external_id TEXT,project_public_id TEXT,project_revision TEXT,project_projection_sha256 TEXT);
            INSERT INTO organizations(id,public_id,name) VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Org A'),(2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Org B');
            INSERT INTO clients(id,public_id,organization_id,name) VALUES(10,'cccccccccccccccccccccccccccccccc',1,'Client A'),(20,'dddddddddddddddddddddddddddddddd',NULL,'Standalone');
            INSERT INTO projects VALUES(100,'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',10,1,'6','Project A',NULL,'active',NULL,NULL,NULL,NULL),(200,'ffffffffffffffffffffffffffffffff',20,NULL,'3','Standalone project',NULL,'active',NULL,NULL,NULL,NULL),(300,'11111111111111111111111111111111',NULL,1,'2','Org only',NULL,'active',NULL,NULL,NULL,NULL);
            INSERT INTO api_v2_directory_authorization_state VALUES(7,'9');INSERT INTO api_v2_project_authorization_state VALUES(7,'8');");
        foreach ([['organization',1,'org-a','4'],['client',10,'client-a','5']] as [$type,$id,$external,$revision]) {
            $table=$type==='client'?'clients':'organizations';$row=$this->pdo->query("SELECT * FROM {$table} WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);$hash=api_v2_directory_canonical_hash($this->pdo,$type,$row);
            $this->pdo->prepare('INSERT INTO api_v2_directory_resource_state VALUES(?,?,?,?,1)')->execute([$type,$row['public_id'],$revision,$hash]);
            $this->pdo->prepare("INSERT INTO api_v2_directory_external_bindings VALUES(?,?,?,?, 'active',?,?)")->execute([7,$type,$external,$row['public_id'],$revision,$hash]);
        }
        $orgB=$this->pdo->query('SELECT * FROM organizations WHERE id=2')->fetch(PDO::FETCH_ASSOC);$orgBHash=api_v2_directory_canonical_hash($this->pdo,'organization',$orgB);
        $this->pdo->prepare('INSERT INTO api_v2_directory_resource_state VALUES(?,?,?,?,1)')->execute(['organization',$orgB['public_id'],'1',$orgBHash]);
        $this->pdo->prepare("INSERT INTO api_v2_directory_external_bindings VALUES(?,?,?,?, 'active',?,?)")->execute([7,'organization','org-b',$orgB['public_id'],'1',$orgBHash]);
        foreach ([[100,'project-a'],[200,'standalone-project'],[300,'org-project']] as [$id,$external]) {
            $row=$this->pdo->query("SELECT * FROM projects WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);$row['client_public_id']=$row['client_id']?$this->pdo->query('SELECT public_id FROM clients WHERE id='.(int)$row['client_id'])->fetchColumn():null;$row['organization_public_id']=$row['organization_id']?$this->pdo->query('SELECT public_id FROM organizations WHERE id='.(int)$row['organization_id'])->fetchColumn():null;$hash=App\Services\ProjectRevisionService::projectionHash($row);
            $this->pdo->prepare('INSERT INTO project_changes VALUES(?,?,?)')->execute([$row['public_id'],$row['revision'],$hash]);$this->pdo->prepare('INSERT INTO api_v2_project_external_bindings VALUES(?,?,?,?,?)')->execute([7,$external,$row['public_id'],$row['revision'],$hash]);
        }
        $insert = $this->pdo->prepare('INSERT INTO public_links VALUES(?,?,?,?,?,?,?)');
        $insert->execute([1, 'invoice_active_token_1234', 'invoice', 10, null, 0, '2026-01-01']);
        $insert->execute([2, 'invoice_revoked_token_1', 'invoice', 11, null, 1, '2026-01-01']);
        $insert->execute([3, 'contract_active_token_1', 'contract', 20, null, 0, '2026-01-01']);
        $insert->execute([4, 'project_invoice_token_1', 'project_invoice', 30, null, 0, '2026-01-01']);
        $insert->execute([5, 'expired_invoice_token_1', 'invoice', 12, '2000-01-01 00:00:00', 0, '2026-01-01']);
        $insert->execute([6, 'org_only_aggregate_token', 'project_invoice', 31, null, 0, '2026-01-01']);
        $this->pdo->exec("CREATE TABLE invoices(id INTEGER PRIMARY KEY,public_id TEXT,client_id INTEGER,organization_id INTEGER,project_id INTEGER,status TEXT,currency TEXT,total TEXT,amount_paid TEXT,balance_due TEXT,finalized_at TEXT,collection_mode TEXT,recipient_presentation_mode TEXT);
            CREATE TABLE contracts(id INTEGER PRIMARY KEY,public_id TEXT,client_id INTEGER,organization_id INTEGER,project_id INTEGER,status TEXT,currency TEXT,total TEXT,signed_at TEXT);
            CREATE TABLE project_invoices(id INTEGER PRIMARY KEY,public_id TEXT,primary_client_id INTEGER,organization_id INTEGER,project_id INTEGER,status TEXT,currency TEXT,total TEXT,amount_paid TEXT,balance_due TEXT,finalized_at TEXT);
            CREATE TABLE project_invoice_items(project_invoice_id INTEGER,invoice_id INTEGER);
            INSERT INTO invoices VALUES(10,'12121212121212121212121212121212',10,1,100,'partial','USD','100.00','20.00','80.00','2026-01-01','direct','named'),(11,'13131313131313131313131313131313',10,1,100,'unpaid','USD','50.00','0.00','50.00','2026-01-01','project_aggregate','named'),(90,'90909090909090909090909090909090',20,2,NULL,'unpaid','USD','999.00','0.00','999.00','2026-01-01','direct','named'),(91,'91919191919191919191919191919191',10,2,NULL,'unpaid','USD','777.00','0.00','777.00','2026-01-01','direct','named');
            INSERT INTO contracts VALUES(20,'14141414141414141414141414141414',10,1,100,'pending','USD','200.00',NULL);
            INSERT INTO project_invoices VALUES(30,'15151515151515151515151515151515',10,1,100,'sent','USD','50.00','0.00','50.00','2026-01-01'),(31,'16161616161616161616161616161616',NULL,1,300,'overdue','USD','60.00','0.00','60.00','2026-01-01'),(32,'17171717171717171717171717171717',NULL,2,300,'overdue','USD','70.00','0.00','70.00','2026-01-01');
            INSERT INTO project_invoice_items VALUES(30,11);");
    }

    public function testStablePublicIdentityShapeIsStrict(): void
    {
        self::assertTrue(api_v2_financial_public_id_valid(str_repeat('a', 32)));
        self::assertFalse(api_v2_financial_public_id_valid(str_repeat('A', 32)));
        self::assertFalse(api_v2_financial_public_id_valid('12'));
        self::assertTrue(api_v2_financial_currency_valid('USD'));
        self::assertFalse(api_v2_financial_currency_valid('usd'));
        self::assertTrue(api_v2_financial_positive_money('0.01'));
        self::assertFalse(api_v2_financial_positive_money('1e3'));
        self::assertFalse(api_v2_financial_positive_money('NaN'));
    }

    public function testExistingInvoiceAndAggregateLinksAreReadWithoutMutation(): void
    {
        $before = $this->pdo->query('SELECT * FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $invoice = api_v2_financial_existing_action_urls($this->pdo, 'invoice', 10, 'partial', '20.00', 'https://pa.example.test');
        $aggregate = api_v2_financial_existing_action_urls($this->pdo, 'project_invoice', 30, 'sent', '40.00', 'https://pa.example.test');
        self::assertSame('https://pa.example.test/?page=public-doc&token=invoice_active_token_1234', $invoice['documentUrl']);
        self::assertSame('https://pa.example.test/?page=stripe-checkout&token=invoice_active_token_1234', $invoice['actionUrl']);
        self::assertStringContainsString('project_invoice_token_1', (string)$aggregate['actionUrl']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testExactApplicationBindingsResolveAllOwnershipShapesAndRejectDrift(): void
    {
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', api_v2_financial_resolve_scope($this->pdo, 7, 'organization', 'org-a')['publicId']);
        self::assertSame(10, api_v2_financial_resolve_scope($this->pdo, 7, 'client', 'client-a')['clientId']);
        self::assertSame(1, api_v2_financial_resolve_scope($this->pdo, 7, 'project', 'project-a')['organizationId']);
        self::assertNull(api_v2_financial_resolve_scope($this->pdo, 7, 'project', 'standalone-project')['organizationId']);
        self::assertNull(api_v2_financial_resolve_scope($this->pdo, 7, 'project', 'org-project')['clientId']);
        self::assertNull(api_v2_financial_resolve_scope($this->pdo, 8, 'client', 'client-a'));
        $this->pdo->exec("UPDATE api_v2_directory_external_bindings SET resource_revision='4' WHERE resource_type='client'");
        self::assertNull(api_v2_financial_resolve_scope($this->pdo, 7, 'client', 'client-a'));
        $this->pdo->exec("UPDATE api_v2_project_external_bindings SET project_projection_sha256='" . str_repeat('f',64) . "' WHERE external_id='project-a'");
        self::assertNull(api_v2_financial_resolve_scope($this->pdo, 7, 'project', 'project-a'));
    }

    public function testCursorIsOpaqueStrictAndCarriesTheSnapshotFence(): void
    {
        $state = ['snapshot' => str_repeat('a', 64), 'kind' => 'invoice', 'publicId' => str_repeat('b', 32)];
        $cursor = api_v2_financial_cursor_encode($state);
        self::assertSame($state, api_v2_financial_cursor_decode($cursor));
        self::assertNull(api_v2_financial_cursor_decode(null));
        foreach (['', '!', rtrim(strtr(base64_encode('{"snapshot":"x"}'), '+/', '-_'), '=')] as $invalid) {
            try { api_v2_financial_cursor_decode($invalid); self::fail('Expected malformed cursor rejection.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testCombinedFeedDeduplicatesAggregateInvoicesAndRejectsSnapshotDrift(): void
    {
        $scope=api_v2_financial_resolve_scope($this->pdo,7,'project','project-a');self::assertNotNull($scope);
        $identity=['application_pk'=>7,'source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000','application_id'=>'223e4567-e89b-42d3-a456-426614174000','history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        $beforeLinks=$this->pdo->query('SELECT * FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $first=api_v2_financial_feed_read($this->pdo,$identity,$scope,'project-a',null,2,'https://pa.example.test','423e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200,$first['status']);self::assertSame(3,$first['payload']['snapshot']['documentCount']);self::assertCount(2,$first['payload']['documents']);
        self::assertSame(['apiVersion','sourceInstanceId','applicationId','historyEpoch','requestId','selector','snapshot','documents','nextCursor'],array_keys($first['payload']));
        self::assertSame(['kind','externalId','publicId','revision','projectionSha256','authorizationGeneration'],array_keys($first['payload']['selector']));
        self::assertSame(['publicId','kind','status','currency','total','amountPaid','balanceDue','documentUrl','actionUrl'],array_keys($first['payload']['documents'][0]));
        self::assertNotContains('13131313131313131313131313131313',array_column($first['payload']['documents'],'publicId'));
        self::assertStringNotContainsString('999.00',json_encode($first['payload'],JSON_THROW_ON_ERROR));
        self::assertSame($beforeLinks,$this->pdo->query('SELECT * FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $cursor=$first['payload']['nextCursor'];self::assertIsString($cursor);
        $second=api_v2_financial_feed_read($this->pdo,$identity,$scope,'project-a',$cursor,2,'https://pa.example.test','523e4567-e89b-42d3-a456-426614174000');self::assertSame(200,$second['status']);self::assertCount(1,$second['payload']['documents']);
        $this->pdo->exec("UPDATE invoices SET balance_due='79.00' WHERE id=10");
        self::assertSame(409,api_v2_financial_feed_read($this->pdo,$identity,$scope,'project-a',$cursor,2,'https://pa.example.test','623e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testOrganizationProjectionRejectsCrossCustomerRowsEvenWhenDocumentOrganizationIsForged(): void
    {
        $scope=api_v2_financial_resolve_scope($this->pdo,7,'organization','org-b');self::assertNotNull($scope);
        $identity=['application_pk'=>7,'source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000','application_id'=>'223e4567-e89b-42d3-a456-426614174000','history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        $before=$this->pdo->query('SELECT * FROM invoices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $result=api_v2_financial_feed_read($this->pdo,$identity,$scope,'org-b',null,100,'https://pa.example.test','423e4567-e89b-42d3-a456-426614174001');
        self::assertSame(200,$result['status']);self::assertSame(0,$result['payload']['snapshot']['documentCount']);self::assertSame([],$result['payload']['documents']);
        self::assertStringNotContainsString('777.00',json_encode($result['payload'],JSON_THROW_ON_ERROR));
        self::assertSame($before,$this->pdo->query('SELECT * FROM invoices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testAuthorityIsReResolvedInsideTheReadTransaction(): void
    {
        $scope=api_v2_financial_resolve_scope($this->pdo,7,'project','project-a');self::assertNotNull($scope);
        $identity=['application_pk'=>7,'source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000','application_id'=>'223e4567-e89b-42d3-a456-426614174000','history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        $this->pdo->exec("UPDATE api_v2_project_authorization_state SET authorization_generation='9' WHERE application_pk=7");
        self::assertSame(409,api_v2_financial_feed_read($this->pdo,$identity,$scope,'project-a',null,10,'https://pa.example.test','423e4567-e89b-42d3-a456-426614174002')['status']);
        $this->pdo->exec("DELETE FROM api_v2_project_external_bindings WHERE application_pk=7 AND external_id='project-a'");
        self::assertSame(409,api_v2_financial_feed_read($this->pdo,$identity,$scope,'project-a',null,10,'https://pa.example.test','423e4567-e89b-42d3-a456-426614174003')['status']);
    }

    public function testCurrentScopesAreRevalidatedAfterInitialAdmissionBeforeFinancialRead(): void
    {
        $this->pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT,scopes TEXT);CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);CREATE TABLE api_v2_history_identity(singleton INTEGER,source_instance_id TEXT,history_epoch TEXT);INSERT INTO api_keys VALUES(1,7,NULL,'api.capabilities.read,financial.documents.read');INSERT INTO api_v2_applications VALUES(7,'223e4567-e89b-42d3-a456-426614174000');INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000')");
        $headers=['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        $admitted=$this->pdo->query('SELECT scopes FROM api_keys WHERE id=1')->fetchColumn();self::assertTrue(api_key_has_scope($admitted,'financial.documents.read',false));
        foreach(['api.capabilities.read','financial.documents.read','full','api.capabilities.read,financial.documents.read,full']as$changed){
            $this->pdo->prepare('UPDATE api_keys SET scopes=? WHERE id=1')->execute([$changed]);$this->pdo->beginTransaction();self::assertNull(api_v2_financial_locked_identity($this->pdo,1,$headers));$this->pdo->rollBack();
        }
        $this->pdo->exec("UPDATE api_keys SET scopes='api.capabilities.read,financial.documents.read' WHERE id=1");$this->pdo->beginTransaction();self::assertNotNull(api_v2_financial_locked_identity($this->pdo,1,$headers));$this->pdo->rollBack();
        $this->pdo->exec("UPDATE api_keys SET revoked_at='2026-01-01' WHERE id=1");$this->pdo->beginTransaction();self::assertNull(api_v2_financial_locked_identity($this->pdo,1,$headers));$this->pdo->rollBack();
    }

    public function testFullSnapshotUsesBoundedLinkChunksAndRejectsGlobalOverflowBeforeLinks(): void
    {
        $insert=$this->pdo->prepare("INSERT INTO invoices VALUES(?, ?,10,1,100,'unpaid','USD','1.00','0.00','1.00','2026-01-01','direct','named')");
        for($id=1000;$id<2001;$id++)$insert->execute([$id,str_pad(dechex($id),32,'0',STR_PAD_LEFT)]);
        $scope=api_v2_financial_resolve_scope($this->pdo,7,'project','project-a');
        $identity=['application_pk'=>7,'source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000','application_id'=>'223e4567-e89b-42d3-a456-426614174000','history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        $this->pdo->preparedSql=[];
        $result=api_v2_financial_feed_read($this->pdo,$identity,$scope,'project-a',null,100,'https://pa.example.test','423e4567-e89b-42d3-a456-426614174000');self::assertSame(200,$result['status']);self::assertSame(1004,$result['payload']['snapshot']['documentCount']);
        $queries=$this->pdo->preparedSql;$links=array_values(array_filter($queries,static fn(string$q):bool=>str_contains($q,'FROM public_links')));self::assertCount(5,$links);self::assertLessThanOrEqual(15,count($queries));foreach($links as $query)self::assertLessThanOrEqual(501,substr_count($query,'?'));
        for($id=2001;$id<11000;$id++)$insert->execute([$id,str_pad(dechex($id),32,'0',STR_PAD_LEFT)]);
        $this->pdo->preparedSql=[];$overflow=api_v2_financial_feed_read($this->pdo,$identity,$scope,'project-a',null,100,'https://pa.example.test','423e4567-e89b-42d3-a456-426614174000');self::assertSame(409,$overflow['status']);self::assertSame([],array_values(array_filter($this->pdo->preparedSql,static fn(string$q):bool=>str_contains($q,'FROM public_links'))));
    }

    public function testBulkLinkSelectionMatchesStandaloneLatestEligibilityRules(): void
    {
        $this->pdo->exec("INSERT INTO public_links VALUES(50,'new_invoice_token_12345','invoice',10,NULL,0,'2026-02-01'),(51,'tie_invoice_token_12345','invoice',10,NULL,0,'2026-02-01'),(52,'revoked_newer_token_123','invoice',10,NULL,1,'2026-03-01'),(53,'expired_newer_token_123','invoice',10,'2000-01-01',0,'2026-03-01')");
        $row=$this->pdo->query('SELECT * FROM invoices WHERE id=10')->fetch(PDO::FETCH_ASSOC);$row['_kind']='invoice';$bulk=api_v2_financial_snapshot_links($this->pdo,[$row],'https://pa.example.test');self::assertSame(api_v2_financial_document_action_urls($this->pdo,'invoice',10,'https://pa.example.test'),$bulk['invoice:10']);self::assertStringContainsString('tie_invoice_token_12345',$bulk['invoice:10']['actionUrl']);
        $this->pdo->exec("INSERT INTO public_links VALUES(54,'malformed!','invoice',10,NULL,0,'2026-04-01')");$bulk=api_v2_financial_snapshot_links($this->pdo,[$row],'https://pa.example.test');self::assertSame(['documentUrl'=>null,'actionUrl'=>null],$bulk['invoice:10']);
        $row['status']='paid';$row['balance_due']='0.00';self::assertSame(['documentUrl'=>null,'actionUrl'=>null],api_v2_financial_snapshot_links($this->pdo,[$row],'https://pa.example.test')['invoice:10']);
    }

    public function testOrganizationFeedIncludesCanonicalClientlessAggregateAndRejectsForgedOrganization(): void
    {
        $scope=api_v2_financial_resolve_scope($this->pdo,7,'organization','org-a');self::assertNotNull($scope);
        $identity=['application_pk'=>7,'source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000','application_id'=>'223e4567-e89b-42d3-a456-426614174000','history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        $result=api_v2_financial_feed_read($this->pdo,$identity,$scope,'org-a',null,100,'https://pa.example.test','423e4567-e89b-42d3-a456-426614174004');
        self::assertSame(200,$result['status']);$ids=array_column($result['payload']['documents'],'publicId');
        self::assertContains('16161616161616161616161616161616',$ids);self::assertNotContains('17171717171717171717171717171717',$ids);
        $aggregate=array_values(array_filter($result['payload']['documents'],static fn(array $row):bool=>$row['publicId']==='16161616161616161616161616161616'))[0];
        self::assertSame('overdue',$aggregate['status']);self::assertSame('https://pa.example.test/?page=stripe-checkout&token=org_only_aggregate_token',$aggregate['actionUrl']);
    }

    public function testEndpointAndCapabilityAreDefaultOffAndUseTheCanonicalContract(): void
    {
        $root=dirname(__DIR__,2);$index=(string)file_get_contents($root.'/public/index.php');$controller=(string)file_get_contents($root.'/src/controllers/api/financial_feed_v2.php');
        self::assertStringContainsString("/api/v2/financial-documents",$index);
        self::assertStringContainsString("APP_API_V2_FINANCIAL_DOCUMENTS_ENABLED",$index);
        self::assertStringContainsString("['selectorType','externalId','cursor','limit']",$controller);
        self::assertStringContainsString("'financial.documents.read'",$controller);
        self::assertStringContainsString('APP_API_V2_FINANCIAL_DOCUMENTS_ENABLED=false',(string)file_get_contents($root.'/config/.env.example'));
    }

    public function testContractSigningLinkRequiresPendingUnsignedLifecycle(): void
    {
        $eligible = api_v2_financial_existing_action_urls($this->pdo, 'contract', 20, 'pending', '0.00', 'https://pa.example.test');
        self::assertSame($eligible['documentUrl'], $eligible['actionUrl']);
        self::assertNull(api_v2_financial_existing_action_urls($this->pdo, 'contract', 20, 'active', '0.00', 'https://pa.example.test')['actionUrl']);
        self::assertNull(api_v2_financial_existing_action_urls($this->pdo, 'contract', 20, 'pending', '0.00', 'https://pa.example.test', '2026-01-01')['actionUrl']);
    }

    public function testTerminalRevokedExpiredAndMalformedLinksAreSuppressed(): void
    {
        foreach ([
            api_v2_financial_existing_action_urls($this->pdo, 'invoice', 10, 'paid', '0.00', 'https://pa.example.test'),
            api_v2_financial_existing_action_urls($this->pdo, 'invoice', 11, 'unpaid', '10.00', 'https://pa.example.test'),
            api_v2_financial_existing_action_urls($this->pdo, 'invoice', 12, 'overdue', '10.00', 'https://pa.example.test'),
            api_v2_financial_existing_action_urls($this->pdo, 'invoice', 10, 'unpaid', '10.00', 'http://pa.example.test'),
        ] as $result) {
            self::assertSame(['documentUrl' => null, 'actionUrl' => null], $result);
        }
    }

    public function testCiRunsTheStrictDisposableFinancialMysqlReleaseSuite(): void
    {
        $root = dirname(__DIR__, 2);
        $runner = (string)file_get_contents($root . '/scripts/run-api-v2-financial-http-mysql.mjs');
        $workflow = (string)file_get_contents($root . '/.github/workflows/ci.yml');
        foreach (['ApiV2FinancialHttpMySqlTest.php', 'ApiV2FinancialDocumentMigrationMySqlTest.php',
            'ApiV2FinancialReleaseMigrationMySqlTest.php', '--fail-on-skipped'] as $required)
            self::assertStringContainsString($required, $runner);
        self::assertStringContainsString('API_V2_FINANCIAL_TEST_IMAGE: ghcr.io/ledgetoptechnologies/project-alpha:latest', $workflow);
        self::assertStringContainsString('run: node scripts/run-api-v2-financial-http-mysql.mjs', $workflow);
    }

    public function testMigrationAndBaselineDeclareUniquePublicIdsForEveryFinancialDocument(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents($root . '/database/migrations/0107_financial_document_public_ids.sql');
        $baseline = (string)file_get_contents($root . '/database/baseline.sql');
        foreach (['invoices', 'contracts', 'project_invoices'] as $table) {
            self::assertStringContainsString("table_name='" . $table . "' AND column_name='public_id'", $migration);
            self::assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS ' . $table . ' \\([^;]+public_id CHAR\\(32\\)/s', $baseline);
        }
        self::assertSame(3, substr_count($migration, 'LOWER(HEX(RANDOM_BYTES(16))),updated_at=updated_at WHERE public_id IS NULL'));
        self::assertSame(2, substr_count($migration, 'document.updated_at=document.updated_at WHERE document.currency IS NULL'));
        self::assertStringContainsString("UPDATE project_invoices SET currency='USD',updated_at=updated_at WHERE currency IS NULL",$migration);
        self::assertSame(3, substr_count($migration, 'ADD UNIQUE KEY'));
        self::assertSame(3, substr_count($migration, 'public_id_immutable BEFORE UPDATE'));
        self::assertSame(6, substr_count($migration, 'ADD CONSTRAINT chk_'));
        foreach(['invoices','contracts','project_invoices'] as $table){self::assertStringContainsString("CONSTRAINT chk_{$table}_public_id_shape",$baseline);self::assertStringContainsString("CONSTRAINT chk_{$table}_currency_shape",$baseline);}
        self::assertSame(3, substr_count($migration, "MODIFY COLUMN currency CHAR(3)"));
        self::assertStringContainsString("snapshot.document_revision=document.revision_number", $migration);
        self::assertStringNotContainsString('DELIMITER', $migration);
        self::assertStringContainsString('invoice_currency_from_pricing_snapshot', $migration);
        self::assertStringContainsString('contract_currency_from_pricing_snapshot', $migration);
        self::assertStringContainsString('UPDATE invoices SET currency=NEW.currency', $migration);
        self::assertStringContainsString('UPDATE contracts SET currency=NEW.currency', $migration);
        self::assertStringContainsString("'usd', // TODO: Make currency configurable", (string)file_get_contents($root . '/src/controllers/stripe/stripe_checkout.php'));
    }
}
