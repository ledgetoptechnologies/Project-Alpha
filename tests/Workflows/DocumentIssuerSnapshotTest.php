<?php

declare(strict_types=1);

namespace Tests\Workflows;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/utils/document_sender.php';

final class DocumentIssuerSnapshotTest extends TestCase
{
    private PDO $pdo;
    private array $config = ['from_company'=>'Drone Services','from_name'=>'Beau','from_email'=>'billing@example.test','document_issuer_mode'=>'creator'];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, created_by INTEGER, status TEXT, last_sent_revision INTEGER, issuer_mode TEXT DEFAULT 'default', issuer_snapshot TEXT)");
        $columns = ['id INTEGER PRIMARY KEY','username TEXT','email TEXT','document_sender_enabled INTEGER'];
        foreach (['name','company','address_line1','address_line2','city','state','postal','country','phone','email'] as $key) $columns[]='document_sender_'.$key.' TEXT';
        $this->pdo->exec('CREATE TABLE users ('.implode(',',$columns).')');
        $this->pdo->exec("INSERT INTO users (id,username,email,document_sender_enabled) VALUES (2,'Colin','colin@example.test',0),(3,'Editor','editor@example.test',0)");
    }

    public function testSnapshotRetainsOriginalCreatorAndIdentityAfterConfigurationAndUserChange(): void
    {
        $this->pdo->exec("INSERT INTO invoices(id,created_by,status) VALUES(1,2,'draft')");
        $this->pdo->beginTransaction();
        $snapshot = document_issuer_capture($this->pdo,$this->config,'invoice',1);
        $this->pdo->commit();
        self::assertSame(2,$snapshot['original_creator_id']);
        self::assertSame('Colin',$snapshot['sender']['name']);
        $this->pdo->exec("UPDATE users SET username='Changed' WHERE id=2");
        $row=$this->pdo->query('SELECT * FROM invoices WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('Colin',document_sender_for_document($this->pdo,['from_company'=>'New Company'],$row)['name']);
        self::assertSame(2,(int)$row['created_by']);
        $this->pdo->beginTransaction();
        self::assertSame($snapshot,document_issuer_capture($this->pdo,$this->config,'invoice',1));
        $this->pdo->rollBack();
    }

    public function testLegacyIssuedDocumentWithoutSnapshotKeepsPreviousEffectiveIssuer(): void
    {
        $this->pdo->exec("INSERT INTO invoices(id,created_by,status,last_sent_revision,issuer_mode) VALUES(1,2,'paid',1,'legacy')");
        $row=$this->pdo->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
        // Old disabled personal profile fell back to configured from_name.
        self::assertSame('Beau',document_sender_for_document($this->pdo,$this->config,$row)['name']);
        self::assertNull(document_issuer_capture($this->pdo,$this->config,'invoice',1));
        self::assertNull($this->pdo->query('SELECT issuer_snapshot FROM invoices')->fetchColumn());
    }

    public function testDraftOverrideCapturesNewRevisionAndRetainsCreator(): void
    {
        $this->pdo->exec("INSERT INTO invoices(id,created_by,status) VALUES(1,2,'draft')");
        $this->pdo->beginTransaction();
        document_issuer_capture($this->pdo,$this->config,'invoice',1);
        document_issuer_apply_post($this->pdo,$this->config,'invoice',1,['document_issuer_mode'=>'company']);
        $snapshot=document_issuer_capture($this->pdo,$this->config,'invoice',1);
        self::assertSame('Drone Services',$snapshot['sender']['name']);
        self::assertSame(2,$snapshot['original_creator_id']);
        $this->pdo->rollBack();
    }

    public function testDocumentRevisionKeepsEachIssuerSnapshotAndEditorSeparateFromCreator(): void
    {
        require_once __DIR__ . '/../../src/services/DocumentRevisionService.php';
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN revision_number INTEGER DEFAULT 1');
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN revision_updated_at TEXT');
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN updated_at TEXT');
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN project_id INTEGER');
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN client_id INTEGER');
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN organization_id INTEGER');
        $this->pdo->exec('CREATE TABLE projects(id INTEGER PRIMARY KEY,organization_id INTEGER)');
        $this->pdo->exec('CREATE TABLE clients(id INTEGER PRIMARY KEY,organization_id INTEGER)');
        $this->pdo->exec('CREATE TABLE invoice_items(id INTEGER PRIMARY KEY,invoice_id INTEGER)');
        $this->pdo->exec('CREATE TABLE invoice_adjustments(id INTEGER PRIMARY KEY,invoice_id INTEGER,superseded_at TEXT)');
        $this->pdo->exec('CREATE TABLE document_revisions(document_type TEXT,document_id INTEGER,revision_number INTEGER,snapshot TEXT,content_hash TEXT,created_by INTEGER,UNIQUE(document_type,document_id,revision_number))');
        $this->pdo->exec("INSERT INTO invoices(id,created_by,status) VALUES(1,2,'draft')");
        $this->pdo->beginTransaction();
        \DocumentRevisionService::snapshotAndSave($this->pdo,'invoice',1,2,false,$this->config);
        document_issuer_apply_post($this->pdo,$this->config,'invoice',1,['document_issuer_mode'=>'company']);
        \DocumentRevisionService::snapshotAndSave($this->pdo,'invoice',1,3,true,$this->config);
        $this->pdo->commit();
        $revisions=$this->pdo->query('SELECT * FROM document_revisions ORDER BY revision_number')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2,$revisions);
        $first=json_decode($revisions[0]['snapshot'],true);
        $second=json_decode($revisions[1]['snapshot'],true);
        self::assertSame('Colin',json_decode($first['document']['issuer_snapshot'],true)['sender']['name']);
        self::assertSame('Drone Services',json_decode($second['document']['issuer_snapshot'],true)['sender']['name']);
        self::assertSame(2,(int)$second['document']['created_by']);
        self::assertSame(3,(int)$revisions[1]['created_by']);
        self::assertSame(hash('sha256',$revisions[0]['snapshot']),$revisions[0]['content_hash']);
    }

    public function testIssuedDocumentCannotChangeIssuer(): void
    {
        $this->pdo->exec("INSERT INTO invoices(id,created_by,status,last_sent_revision,issuer_mode) VALUES(1,2,'unpaid',1,'company')");
        $this->pdo->beginTransaction();
        $this->expectException(DomainException::class);
        document_issuer_apply_post($this->pdo,$this->config,'invoice',1,['document_issuer_mode'=>'creator']);
    }

    public function testPartialAutomationSettingsLoadInstallationIssuerConfiguration(): void
    {
        $resolved = document_issuer_company_configuration($this->pdo, ['net_terms_days'=>14]);
        self::assertArrayHasKey('document_issuer_mode',$resolved);
        self::assertArrayHasKey('from_company',$resolved);
        self::assertNotSame('',trim((string)$resolved['brand_name']));
        self::assertSame(14,$resolved['net_terms_days']);
        $this->pdo->exec("INSERT INTO invoices(id,created_by,status) VALUES(1,2,'draft')");
        $this->pdo->beginTransaction();
        $issuer=document_issuer_capture($this->pdo,$resolved,'invoice',1);
        self::assertSame('company',$issuer['mode']);
        self::assertSame(trim((string)($resolved['from_company']??'')) ?: $resolved['brand_name'],$issuer['sender']['name']);
        $this->pdo->rollBack();
    }

    public function testMissingConfigurationCannotFreezeGenericCompany(): void
    {
        $this->pdo->exec("INSERT INTO invoices(id,created_by,status) VALUES(1,2,'draft')");
        $this->pdo->beginTransaction();
        $this->expectException(\LogicException::class);
        document_issuer_capture($this->pdo,[],'invoice',1);
    }
}
