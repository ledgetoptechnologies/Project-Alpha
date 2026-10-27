<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/utils/billing_recipients.php';

final class OrganizationBillingRecipientsTest extends TestCase
{
    private PDO $pdo;
    protected function setUp():void
    {
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        foreach([
            'organizations(id INTEGER PRIMARY KEY,name TEXT,general_email TEXT)',
            'clients(id INTEGER PRIMARY KEY,name TEXT,email TEXT,organization_id INTEGER,archived INTEGER DEFAULT 0)',
            'projects(id INTEGER PRIMARY KEY,client_id INTEGER,organization_id INTEGER)',
            'invoices(id INTEGER PRIMARY KEY,client_id INTEGER,organization_id INTEGER,project_id INTEGER,status TEXT,finalized_at TEXT,recipient_presentation_mode TEXT)',
            'project_invoices(id INTEGER PRIMARY KEY,primary_client_id INTEGER,organization_id INTEGER,project_id INTEGER)',
            'project_invoice_recipients(id INTEGER PRIMARY KEY,project_id INTEGER,client_id INTEGER,organization_id INTEGER,sort_order INTEGER)',
            'organization_billing_preferences(organization_id INTEGER PRIMARY KEY,copy_working_contact INTEGER)',
            'organization_billing_recipients(organization_id INTEGER,client_id INTEGER)',
            'invoice_billing_preferences(invoice_id INTEGER PRIMARY KEY,copy_working_contact INTEGER)',
            'invoice_billing_recipients(invoice_id INTEGER,client_id INTEGER)',
        ] as $definition)$this->pdo->exec('CREATE TABLE '.$definition);
        $this->pdo->exec("INSERT INTO organizations VALUES(1,'First','general@example.test'),(2,'Other','other@example.test');
            INSERT INTO clients VALUES(1,'Working','work@example.test',1,0),(2,'Accounting A','ap@example.test',1,0),
            (3,'Accounting B','second@example.test',1,0),(4,'Outside','outside@example.test',2,0),
            (5,'Archived','archived@example.test',1,1),(6,'No email','',1,0),(7,'Same email','AP@example.test',1,0);
            INSERT INTO projects VALUES(10,1,1);
            INSERT INTO invoices VALUES(100,1,1,NULL,'draft',NULL,'named'),(101,1,1,10,'draft',NULL,'named');
            INSERT INTO project_invoices VALUES(200,1,1,10)");
    }
    private function emails(int $invoiceId=100):array {return array_column(pa_billing_resolve($this->pdo,'invoice',$invoiceId)['recipients'],'email');}

    public function testUnconfiguredOrganizationPreservesLegacyRoutingEvenWithProjectRecipients():void
    {
        $this->pdo->exec('INSERT INTO project_invoice_recipients VALUES(1,10,2,NULL,0)');
        self::assertSame(['work@example.test'],$this->emails());self::assertSame(['work@example.test'],$this->emails(101));
        self::assertFalse(pa_billing_resolve($this->pdo,'invoice',100)['configured']);
    }
    public function testMultipleAccountingContactsAndExplicitWorkingCopyDeduplicate():void
    {
        pa_billing_organization_save($this->pdo,1,[2,3,7],false);
        self::assertSame(['ap@example.test','second@example.test'],$this->emails());
        pa_billing_organization_save($this->pdo,1,[2,3],true);
        self::assertSame(['ap@example.test','second@example.test','work@example.test'],$this->emails());
        self::assertSame(1,(int)$this->pdo->query('SELECT client_id FROM invoices WHERE id=100')->fetchColumn());
    }
    public function testInvoiceOverrideAndResetHaveExplicitPrecedence():void
    {
        pa_billing_organization_save($this->pdo,1,[2],false);
        pa_billing_invoice_save($this->pdo,100,'custom',[3],false);
        self::assertSame(['second@example.test'],$this->emails());
        self::assertSame('invoice',pa_billing_resolve($this->pdo,'invoice',100)['source']);
        pa_billing_invoice_save($this->pdo,100,'inherit',[],false);
        self::assertSame(['ap@example.test'],$this->emails());
        pa_billing_organization_reset($this->pdo,1);self::assertSame(['work@example.test'],$this->emails());
    }
    public function testExplicitProjectRecipientsRemainOverrideAfterOrganizationOptIn():void
    {
        pa_billing_organization_save($this->pdo,1,[2],false);
        $this->pdo->exec('INSERT INTO project_invoice_recipients VALUES(1,10,3,NULL,0)');
        self::assertSame(['second@example.test'],$this->emails(101));
        self::assertSame('project',pa_billing_resolve($this->pdo,'project_invoice',200)['source']);
    }
    public function testMovedOrArchivedAccountingContactDoesNotFallBackToSigner():void
    {
        pa_billing_organization_save($this->pdo,1,[2],false);
        $this->pdo->exec('UPDATE clients SET organization_id=2 WHERE id=2');
        self::assertSame([],$this->emails());self::assertNotEmpty(pa_billing_resolve($this->pdo,'invoice',100)['reason']);
        $this->pdo->exec('UPDATE clients SET organization_id=1,archived=1 WHERE id=2');
        self::assertSame([],$this->emails());
    }
    public function testSavedDocumentAndProjectOrganizationBeatMovedWorkingContact():void
    {
        pa_billing_organization_save($this->pdo,1,[2],false);pa_billing_organization_save($this->pdo,2,[4],false);
        $this->pdo->exec('UPDATE clients SET organization_id=2 WHERE id=1; UPDATE invoices SET organization_id=2 WHERE id=101');
        self::assertSame(['ap@example.test'],$this->emails());self::assertSame(['ap@example.test'],$this->emails(101));
    }
    public function testInvalidSelectionCannotPartiallyOverwriteSavedPolicy():void
    {
        pa_billing_organization_save($this->pdo,1,[2],false);
        foreach([[4],[5],[6],[]] as $ids){
            try{pa_billing_organization_save($this->pdo,1,$ids,false);self::fail('Invalid policy accepted.');}
            catch(InvalidArgumentException $e){self::assertSame(['ap@example.test'],$this->emails());}
        }
    }
    public function testFinalizedInvoiceCannotChangeRecipients():void
    {
        $this->pdo->exec("UPDATE invoices SET status='unpaid',finalized_at='2026-10-27' WHERE id=100");
        $this->expectException(DomainException::class);pa_billing_invoice_save($this->pdo,100,'custom',[2],false);
    }
    public function testGeneralRecipientCannotAcquireBillingEmails():void
    {
        pa_billing_organization_save($this->pdo,1,[2],false);
        $this->pdo->exec("UPDATE invoices SET recipient_presentation_mode='general' WHERE id=100");
        self::assertSame([],$this->emails());
        $this->expectException(DomainException::class);pa_billing_invoice_save($this->pdo,100,'custom',[2],false);
    }
    public function testPreviewCanExplainEmptyCustomSelectionWithoutSaving():void
    {
        $result=pa_billing_preview($this->pdo,1,null,1,['mode'=>'custom','recipient_ids'=>[],'copy_working_contact'=>false]);
        self::assertSame([],$result['recipients']);self::assertNotEmpty($result['reason']);
        self::assertNull(pa_billing_invoice_preferences($this->pdo,100));
    }
}
