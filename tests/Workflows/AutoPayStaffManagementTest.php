<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/autopay_staff_management.php';
require_once dirname(__DIR__, 2) . '/src/utils/escaper.php';
require_once dirname(__DIR__, 2) . '/src/utils/csrf.php';

use PHPUnit\Framework\TestCase;

final class AutoPayStaffManagementTest extends TestCase
{
    private PDO $pdo;
    private array $environment = [];
    private mixed $session;
    private array $config = ['_stripe_secret_key' => 'sk_test_SYNTHETIC'];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE clients(id INTEGER,organization_id INTEGER,archived INTEGER,email TEXT,created_by INTEGER);
            CREATE TABLE users(id INTEGER,role TEXT);
            CREATE TABLE user_permissions_overrides(user_id INTEGER,organization_id INTEGER,permission TEXT,allowed INTEGER);
            CREATE TABLE autopay_enrollments(id INTEGER,client_id INTEGER,scope TEXT,contract_id INTEGER,status TEXT,method_type TEXT,installation_id TEXT,processor_account_id TEXT,livemode INTEGER);
            INSERT INTO clients VALUES(1,20,0,"client@example.test",71001),(2,20,1,"archived@example.test",71001);
            INSERT INTO users VALUES(71001,"admin"),(71002,"staff"),(71003,"staff"),(71004,"member");
            INSERT INTO user_permissions_overrides VALUES(71002,NULL,"clients.edit",1),(71003,NULL,"clients.edit",1),(71003,NULL,"invoices.send",1),(71004,NULL,"clients.edit",1),(71004,NULL,"invoices.send",1);');
        $this->session = $_SESSION ?? null;
        $_SESSION = ['user' => ['id' => 71001, 'role' => 'admin'], 'csrf' => 'synthetic'];
        foreach (['APP_ENV' => 'test', 'AUTOPAY_BETA_ENABLED' => 'false', 'AUTOPAY_INSTALLATION_ID' => 'synthetic-management', 'AUTOPAY_STRIPE_ACCOUNT_ID' => 'acct_SYNTHETIC'] as $key => $value) {
            $this->environment[$key] = getenv($key); putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        foreach ($this->environment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    public function testStaffMustHaveBothPermissionsAndAccessToExactClient(): void
    {
        self::assertTrue(pa_autopay_staff_can_manage_client($this->pdo, 1, 71001));
        $_SESSION['user'] = ['id' => 71002, 'role' => 'staff'];
        self::assertFalse(pa_autopay_staff_can_manage_client($this->pdo, 1, 71002));
        $_SESSION['user'] = ['id' => 71003, 'role' => 'staff'];
        self::assertTrue(pa_autopay_staff_can_manage_client($this->pdo, 1, 71003));
        $_SESSION['user'] = ['id' => 71004, 'role' => 'member'];
        self::assertFalse(pa_autopay_staff_can_manage_client($this->pdo, 1, 71004));
        $_SESSION['user'] = ['id' => 71001, 'role' => 'admin'];
        self::assertFalse(pa_autopay_staff_can_manage_client($this->pdo, 2, 71001));
        self::assertFalse(pa_autopay_staff_can_manage_client($this->pdo, 999, 71001));
        self::assertFalse(pa_autopay_staff_can_manage_client($this->pdo, 1, 0));
    }

    public function testManagementListsOnlyBoundPendingAndActiveTestAuthorizationsWhileEnrollmentPaused(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO autopay_enrollments VALUES(?,?,?,?,?,?,?,?,?)');
        foreach ([[1,1,'business',null,'active','card','synthetic-management','acct_SYNTHETIC',0],
            [2,1,'business',null,'pending','card','synthetic-management','acct_OTHER',0],
            [3,1,'business',null,'pending','card','other-installation','acct_SYNTHETIC',0],
            [4,1,'business',null,'pending','card','synthetic-management','acct_SYNTHETIC',1],
            [5,2,'business',null,'active','card','synthetic-management','acct_SYNTHETIC',0],
            [6,1,'business',null,'revoked','card','synthetic-management','acct_SYNTHETIC',0]] as $row) $insert->execute($row);
        self::assertFalse(pa_autopay_enabled($this->config));
        $rows = pa_autopay_staff_client_authorizations($this->pdo, 1, $this->config);
        self::assertCount(1, $rows);
        self::assertSame(1, (int)$rows[0]['id']);
        self::assertArrayNotHasKey('processor_account_id', $rows[0]);
        self::assertArrayNotHasKey('management_token', $rows[0]);
        putenv('APP_ENV=production');
        self::assertSame([], pa_autopay_staff_client_authorizations($this->pdo, 1, $this->config));
    }

    public function testProductionCannotDisableAuthorizationsThroughStaffHelper(): void
    {
        putenv('APP_ENV=production');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('AutoPay management is unavailable.');
        pa_autopay_staff_disable_client($this->pdo, 1, 71001, $this->config);
    }

    public function testClientCardExplainsAllScopeDisableAndPreservesProcessingWarning(): void
    {
        $this->pdo->exec('INSERT INTO autopay_enrollments VALUES(1,1,"business",NULL,"active","card","synthetic-management","acct_SYNTHETIC",0)');
        $pdo = $this->pdo; $appConfig = $this->config; $client = ['id' => 1];
        ob_start();
        include dirname(__DIR__, 2) . '/src/views/components/client_autopay_management.php';
        $html = (string)ob_get_clean();
        self::assertStringContainsString('Turn off AutoPay for this client', $html);
        self::assertStringContainsString('Payments already processing may still complete.', $html);
        self::assertStringContainsString('every agreement and invoice scope', $html);
        self::assertStringContainsString('name="confirm_disable_autopay"', $html);
        self::assertStringContainsString('name="csrf"', $html);
        self::assertStringNotContainsString('acct_SYNTHETIC', $html);
    }
}
