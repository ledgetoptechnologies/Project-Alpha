<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/src/utils/check_report_public.php';
require_once dirname(__DIR__, 2) . '/src/utils/check_reports.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_collection.php';

final class MailedCheckPublicContextTest extends TestCase
{
    private PDO $pdo;
    private string $token;
    private array $config = ['payment_methods'=>['check']];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE public_links(token TEXT,document_type TEXT,document_id INTEGER,revoked INTEGER,expires_at TEXT);
            CREATE TABLE clients(id INTEGER,name TEXT,email TEXT,archived INTEGER,public_id TEXT);
            CREATE TABLE invoices(id INTEGER,client_id INTEGER,status TEXT,finalized_at TEXT,recipient_presentation_mode TEXT,collection_mode TEXT,total REAL,amount_paid REAL,public_id TEXT);
            CREATE TABLE project_invoices(id INTEGER,primary_client_id INTEGER,status TEXT,finalized_at TEXT,total REAL,amount_paid REAL,public_id TEXT);
            INSERT INTO clients VALUES(7,"Synthetic","check@example.invalid",0,"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
            INSERT INTO invoices VALUES(10,7,"unpaid","2026-10-01","named","direct",350,0,"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb");
            INSERT INTO project_invoices VALUES(20,7,"unpaid","2026-10-01",350,0,"cccccccccccccccccccccccccccccccc")');
        $this->token = str_repeat('a', 64);
        $this->pdo->prepare('INSERT INTO public_links VALUES(?,"invoice",10,0,NULL)')->execute([$this->token]);
    }

    private function context(): array { return pa_check_report_public_context($this->pdo, $this->token, $this->config); }

    private function unavailable(): void
    {
        try { $this->context(); self::fail('Invalid public context accepted.'); }
        catch (DomainException $error) { self::assertNotSame('', $error->getMessage()); }
    }

    public function testPublicTokenDeterminesInvoiceAndProjectClientWithoutAcceptingRequestIds(): void
    {
        $context = $this->context();
        self::assertSame('invoice', $context['type']); self::assertSame(10, $context['document_id']); self::assertSame(7, (int)$context['client']['id']);
        $this->pdo->exec('UPDATE public_links SET document_type="project_invoice",document_id=20');
        $context = $this->context();
        self::assertSame('project_invoice', $context['type']); self::assertSame(20, $context['document_id']); self::assertSame(7, (int)$context['client']['id']);
    }

    public function testRevokedExpiredMissingUnsupportedAndMalformedTokensAreRejected(): void
    {
        $this->pdo->exec('UPDATE public_links SET revoked=1'); $this->unavailable();
        $this->pdo->exec('UPDATE public_links SET revoked=0,expires_at="2000-01-01 00:00:00"'); $this->unavailable();
        $this->pdo->exec('UPDATE public_links SET expires_at="malformed"'); $this->unavailable();
        $this->pdo->exec('UPDATE public_links SET expires_at=NULL,document_type="contract"'); $this->unavailable();
        $this->token = str_repeat('b', 64); $this->unavailable();
        $this->token = '../10'; $this->unavailable();
    }

    public function testDraftPaidVoidGeneralAggregateAndArchivedClientCannotReport(): void
    {
        foreach (['draft','paid','void','cancelled'] as $status) {
            $this->pdo->prepare('UPDATE invoices SET status=?')->execute([$status]); $this->unavailable();
        }
        $this->pdo->exec('UPDATE invoices SET status="unpaid",finalized_at=NULL'); $this->unavailable();
        $this->pdo->exec('UPDATE invoices SET finalized_at="2026-10-01",recipient_presentation_mode="general"'); $this->unavailable();
        $this->pdo->exec('UPDATE invoices SET recipient_presentation_mode="named",collection_mode="project_aggregate"'); $this->unavailable();
        $this->pdo->exec('UPDATE invoices SET collection_mode="direct",amount_paid=350'); $this->unavailable();
        $this->pdo->exec('UPDATE invoices SET amount_paid=0; UPDATE clients SET archived=1'); $this->unavailable();
        $this->pdo->exec('UPDATE clients SET archived=0,email="invalid"'); $this->unavailable();
        $this->pdo->exec('UPDATE clients SET email="check@example.invalid"');
        $this->config = ['payment_methods'=>['cash']]; $this->unavailable();
    }

    public function testVerificationIsBoundToTokenClientEmailExpiryAndSeparateFromAutoPay(): void
    {
        $context = $this->context();
        $proof = ['client_id'=>7,'email'=>'check@example.invalid','until'=>time()+600,'token_hash'=>hash('sha256',$this->token),'client_public_id'=>$context['client']['public_id'],'document_public_id'=>$context['document']['public_id']];
        self::assertTrue(pa_check_report_public_authority($context,$this->token,['mailed_check_verified'=>$proof]));
        self::assertFalse(pa_check_report_public_authority($context,$this->token,['autopay_verified'=>$proof]));
        self::assertFalse(pa_check_report_public_authority($context,str_repeat('b',64),['mailed_check_verified'=>$proof]));
        foreach (['client_id'=>8,'email'=>'other@example.invalid','until'=>time()-1,'token_hash'=>'','client_public_id'=>str_repeat('d',32),'document_public_id'=>str_repeat('e',32)] as $field=>$value) {
            $changed = $proof; $changed[$field]=$value;
            self::assertFalse(pa_check_report_public_authority($context,$this->token,['mailed_check_verified'=>$changed]),$field);
        }
    }

    public function testStableIdentityAnchorAndDisclosureSurviveOnlyTheSameClientAndDocument(): void
    {
        $context=$this->context();$anchor=pa_check_report_public_anchor($context,$this->token);
        self::assertNotSame('', $anchor);
        $changed=$context;$changed['client']['public_id']=str_repeat('d',32);
        self::assertNotSame($anchor,pa_check_report_public_anchor($changed,$this->token));
        $changed=$context;$changed['document']['public_id']=str_repeat('e',32);
        self::assertNotSame($anchor,pa_check_report_public_anchor($changed,$this->token));
        self::assertTrue(pa_check_report_same_client(['client_public_id'=>$context['client']['public_id']],$context['client']));
        self::assertFalse(pa_check_report_same_client(['client_public_id'=>str_repeat('f',32)],$context['client']));
        self::assertFalse(pa_check_report_same_client([], $context['client']));
        self::assertSame('EUR 350.00',pa_check_report_money(35000,'eur'));
    }

    public function testMissingMigrationDisablesClaimsWithoutBreakingLegacyPaymentAvailability(): void
    {
        self::assertFalse(pa_check_report_available($this->pdo));
        self::assertNull(pa_check_report_lookup($this->pdo,'invoice',10));
        pa_autopay_assert_payment_available($this->pdo,10);
        try { pa_check_report_submit($this->pdo,'invoice',10,7,'5866',$this->config); self::fail('Claim accepted without its durable schema.'); }
        catch (DomainException $error) { self::assertStringContainsString('upgrade',$error->getMessage()); }
        self::assertSame(0,(int)$this->pdo->query('SELECT amount_paid FROM invoices WHERE id=10')->fetchColumn());
    }
}
