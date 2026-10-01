<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiV2FinancialPortalSummaryTest extends TestCase
{
    private PDO $pdo;
    private array $headers;
    private string|false $previousAppHost = false;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('PDO SQLite is required.');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_financial_summary.php';
        $this->previousAppHost = getenv('APP_HOST');
        putenv('APP_HOST=https://project-alpha.example.test');
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id TEXT,public_id TEXT,status TEXT);CREATE TABLE api_v2_project_external_bindings(application_pk INTEGER,external_id TEXT,project_public_id TEXT);CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT);CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER);CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT);CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,client_id INTEGER,organization_id INTEGER);CREATE TABLE project_clients(project_id INTEGER,client_id INTEGER,can_view_invoice_links INTEGER);CREATE TABLE invoices(id INTEGER PRIMARY KEY,client_id INTEGER,organization_id INTEGER,project_id INTEGER,doc_number INTEGER,status TEXT,total TEXT,amount_paid TEXT,balance_due TEXT,due_date TEXT,document_date TEXT);CREATE TABLE public_links(id INTEGER PRIMARY KEY,token TEXT,document_type TEXT,document_id INTEGER,expires_at TEXT,expire_when_paid INTEGER,revoked INTEGER,created_at TEXT)");
        $source = '123e4567-e89b-42d3-a456-426614174000';
        $application = '223e4567-e89b-42d3-a456-426614174000';
        $epoch = '323e4567-e89b-42d3-a456-426614174000';
        $this->headers = ['source' => $source, 'application' => $application, 'epoch' => $epoch];
        $this->pdo->prepare('INSERT INTO api_v2_applications VALUES(?,?)')->execute([7, $application]);
        $this->pdo->prepare('INSERT INTO api_keys VALUES(?,?,NULL)')->execute([9, 7]);
        $this->pdo->prepare('INSERT INTO api_v2_history_identity VALUES(1,?,?)')->execute([$source, $epoch]);
        $this->pdo->exec("INSERT INTO app_config VALUES(0,'app_host','https://project-alpha.example.test');INSERT INTO organizations VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),(2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');INSERT INTO clients VALUES(10,'cccccccccccccccccccccccccccccccc',1),(20,'dddddddddddddddddddddddddddddddd',2);INSERT INTO projects VALUES(100,'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',10,1),(200,'ffffffffffffffffffffffffffffffff',20,2);INSERT INTO project_clients VALUES(100,10,1),(200,20,1);INSERT INTO api_v2_directory_external_bindings VALUES(7,'client','customer-a','cccccccccccccccccccccccccccccccc','active'),(7,'organization','org-a','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','active');INSERT INTO api_v2_project_external_bindings VALUES(7,'external-project-a','eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');INSERT INTO invoices VALUES(1,10,1,100,101,'sent','100.00','20.00','80.00','2026-10-01','2026-09-01'),(2,10,1,100,102,'partial','50.00','10.00','40.00','2026-10-02','2026-09-02'),(3,20,2,200,201,'sent','999.00','0.00','999.00','2026-10-03','2026-09-03'),(4,10,1,100,103,'void','777.00','0.00','777.00','2026-10-04','2026-09-04'),(5,10,1,100,104,'paid','50.00','50.00','0.00','2026-10-05','2026-09-05'),(6,10,1,100,105,'sent','40.00','0.00','40.00','2026-10-06','2026-09-06');INSERT INTO public_links VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','invoice',1,NULL,1,0,'2026-09-01'),(2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','invoice',2,NULL,1,1,'2026-09-02'),(3,'cccccccccccccccccccccccccccccccc','invoice',3,NULL,1,0,'2026-09-03'),(4,'dddddddddddddddddddddddddddddddd','invoice',5,NULL,1,0,'2026-09-04'),(5,'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','invoice',6,'2000-01-01',1,0,'2026-09-05')");
    }

    protected function tearDown(): void
    {
        $this->previousAppHost === false ? putenv('APP_HOST') : putenv('APP_HOST=' . $this->previousAppHost);
    }

    public function testControllerRequiresTheDedicatedScopeAndRejectsLegacyFullAccess(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/src/utils/api_scopes.php';
        $controller = (string) file_get_contents($root . '/src/controllers/api/financial_summary_v2.php');
        self::assertStringContainsString("api_require_key(['api.capabilities.read', 'financial.portal_summary.read'], false)", $controller);
        self::assertStringContainsString("in_array('full'", $controller);
        self::assertFalse(api_key_has_scope('full', 'financial.portal_summary.read', false));
        self::assertSame([], api_scope_catalog()['financial.portal_summary.read']['endpoints']);
    }

    public function testClientScopeIsBoundedPaginatedAndHasNoSourceWrites(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        $linksBefore = $this->pdo->query('SELECT id,revoked,expires_at,token FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $result = api_v2_financial_summary_read($this->pdo, 'client', 'customer-a', null, 1, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $result['status']);
        self::assertSame('80.00', $result['payload']['returnedPageTotals']['balanceDue']);
        self::assertSame('101', (string) $result['payload']['invoices'][0]['documentNumber']);
        self::assertSame('1', $result['payload']['nextCursor']);
        self::assertSame('https://project-alpha.example.test/?page=public-doc&type=invoice&token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $result['payload']['invoices'][0]['invoicePublicUrl']);
        self::assertSame('https://project-alpha.example.test/?page=stripe-checkout&token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $result['payload']['invoices'][0]['paymentPublicUrl']);
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame($linksBefore, $this->pdo->query('SELECT id,revoked,expires_at,token FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testOrganizationScopeExcludesOtherOrganizationsAndRejectsInactiveMappings(): void
    {
        $result = api_v2_financial_summary_read($this->pdo, 'organization', 'org-a', null, 100, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $result['status']);
        self::assertSame(['101', '102', '104', '105'], array_map(static fn(array $row): string => (string) $row['documentNumber'], $result['payload']['invoices']));
        $this->pdo->exec("UPDATE api_v2_directory_external_bindings SET status='tombstoned' WHERE resource_type='client'");
        self::assertSame(404, api_v2_financial_summary_read($this->pdo, 'client', 'customer-a', null, 100, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testWrongApplicationIdentityCannotReadMappedCustomer(): void
    {
        $headers = $this->headers;
        $headers['application'] = '423e4567-e89b-42d3-a456-426614174000';
        self::assertSame(409, api_v2_financial_summary_read($this->pdo, 'client', 'customer-a', null, 100, 9, $headers, '423e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testProjectBindingCannotSurfaceAnotherProjectsInvoiceOrActionUrls(): void
    {
        $first = api_v2_financial_summary_read($this->pdo, 'project_public', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', null, 1, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $first['status']);
        self::assertSame(['101'], array_map(static fn(array $row): string => (string)$row['documentNumber'], $first['payload']['invoices']));
        self::assertSame('1', $first['payload']['nextCursor']);
        self::assertStringNotContainsString('id=3', json_encode($first['payload'], JSON_THROW_ON_ERROR));
        self::assertSame('external-project-a', $first['payload']['resource']['externalId']);
        self::assertSame('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', $first['payload']['resource']['publicId']);
        $second = api_v2_financial_summary_read($this->pdo, 'project_public', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', '1', 100, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000');
        self::assertSame(['102', '104', '105'], array_map(static fn(array $row): string => (string)$row['documentNumber'], $second['payload']['invoices']));
        self::assertSame(404, api_v2_financial_summary_read($this->pdo, 'project_public', 'ffffffffffffffffffffffffffffffff', null, 100, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testRevokedExpiredAndTerminalInvoiceLinksAreNeverEmitted(): void
    {
        $result = api_v2_financial_summary_read($this->pdo, 'project_public', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', null, 100, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $result['status']);
        $byDocument = [];
        foreach ($result['payload']['invoices'] as $invoice) $byDocument[(string)$invoice['documentNumber']] = $invoice;
        self::assertNull($byDocument['102']['invoicePublicUrl'], 'Revoked link must not be exposed.');
        self::assertNull($byDocument['102']['paymentPublicUrl']);
        self::assertNull($byDocument['104']['invoicePublicUrl'], 'Paid terminal invoice must not expose its link.');
        self::assertNull($byDocument['104']['paymentPublicUrl']);
        self::assertNull($byDocument['105']['invoicePublicUrl'], 'Expired link must not be exposed.');
        self::assertNull($byDocument['105']['paymentPublicUrl']);
        self::assertArrayNotHasKey('201', $byDocument, 'A public link on project B cannot cross the project boundary.');
    }

    public function testProjectInvoiceVisibilityGrantFailsClosedForAmountsAndLinks(): void
    {
        $this->pdo->exec('UPDATE project_clients SET can_view_invoice_links=0 WHERE project_id=100 AND client_id=10');
        self::assertSame(404, api_v2_financial_summary_read($this->pdo, 'project_public', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', null, 100, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testCursorRejectsUnsignedOrOverflowingIdsBeforeAnyCast(): void
    {
        self::assertTrue(api_v2_financial_cursor_valid('2147483647'));
        self::assertFalse(api_v2_financial_cursor_valid('2147483648'));
        self::assertFalse(api_v2_financial_cursor_valid(str_repeat('9', 50)));
        $this->expectException(InvalidArgumentException::class);
        api_v2_financial_summary_read($this->pdo, 'client', 'customer-a', '2147483648', 100, 9, $this->headers, '423e4567-e89b-42d3-a456-426614174000');
    }

    public function testConfiguredPublicLinkOriginWinsOverEnvironmentAndMissingConfigFailsClosed(): void
    {
        putenv('APP_HOST=https://wrong-origin.example.test');
        self::assertSame('https://project-alpha.example.test', api_v2_financial_summary_base_url($this->pdo));
        $this->pdo->exec("DELETE FROM app_config WHERE organization_id=0 AND config_key='app_host'");
        $this->expectException(RuntimeException::class);
        api_v2_financial_summary_base_url($this->pdo);
    }
}
