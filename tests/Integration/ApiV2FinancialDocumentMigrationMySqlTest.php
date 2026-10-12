<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

/** Disposable MySQL 8 coverage for migration 0107 and its immutable identities. */
final class ApiV2FinancialDocumentMigrationMySqlTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn=getenv('API_V2_FINANCIAL_MYSQL_DSN');$user=getenv('API_V2_FINANCIAL_MYSQL_USER');$password=getenv('API_V2_FINANCIAL_MYSQL_PASSWORD');
        if(!$dsn||!$user||$password===false)self::markTestSkipped('Run against an isolated disposable MySQL 8 database using the API_V2_FINANCIAL_MYSQL_* variables.');
        $database=trim((string)(getenv('API_V2_FINANCIAL_MYSQL_DATABASE')?:''));
        if(getenv('API_V2_FINANCIAL_MYSQL_ALLOW_DESTRUCTIVE')!=='isolated-disposable-only'||preg_match('/^api_v2_financial_test_[a-f0-9]{32}$/D',$database)!==1)throw new \RuntimeException('Financial migration tests require the disposable runner sentinel.');
        $this->pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        if(!hash_equals($database,(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()))throw new \RuntimeException('Refusing an unrecognized MySQL database.');
        $this->resetSchema();
    }

    public function testMigrationBackfillsStableIdentityCurrencyAndIsIdempotent(): void
    {
        $this->pdo->exec("INSERT INTO invoices(id,revision_number,total) VALUES(1,2,10.00);INSERT INTO contracts(id,revision_number,total) VALUES(2,1,20.00);INSERT INTO project_invoices(id,total) VALUES(3,30.00);INSERT INTO document_pricing_adjustment_snapshots(document_type,document_id,document_revision,currency) VALUES('invoice',1,2,'EUR')");
        $this->runMigration();
        $rows=$this->pdo->query("SELECT 'invoice' kind,public_id,currency FROM invoices UNION ALL SELECT 'contract',public_id,currency FROM contracts UNION ALL SELECT 'project_invoice',public_id,currency FROM project_invoices ORDER BY kind")->fetchAll();
        self::assertSame(['USD','EUR','USD'],array_column($rows,'currency'));
        foreach($rows as $row)self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D',(string)$row['public_id']);
        $ids=array_column($rows,'public_id','kind');

        try{$this->pdo->exec("UPDATE invoices SET public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE id=1");self::fail('Expected immutable public identity rejection.');}catch(PDOException){self::assertSame($ids['invoice'],$this->pdo->query('SELECT public_id FROM invoices WHERE id=1')->fetchColumn());}
        try{$this->pdo->exec("UPDATE invoices SET currency='usd' WHERE id=1");self::fail('Expected currency shape rejection.');}catch(PDOException){self::assertSame('EUR',$this->pdo->query('SELECT currency FROM invoices WHERE id=1')->fetchColumn());}
        $this->pdo->exec("INSERT INTO invoices(id,revision_number,total) VALUES(4,1,40.00);INSERT INTO document_pricing_adjustment_snapshots(document_type,document_id,document_revision,currency) VALUES('invoice',4,1,'GBP')");
        $inserted=$this->pdo->query('SELECT public_id,currency,total FROM invoices WHERE id=4')->fetch();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D',(string)$inserted['public_id']);self::assertSame('GBP',$inserted['currency']);self::assertSame('40.00',$inserted['total']);

        $this->runMigration();
        self::assertSame($ids['invoice'],$this->pdo->query('SELECT public_id FROM invoices WHERE id=1')->fetchColumn());
        self::assertSame(3,(int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN ('invoices_public_id_immutable','contracts_public_id_immutable','project_invoices_public_id_immutable')")->fetchColumn());
        self::assertSame(6,(int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_type='CHECK' AND constraint_name LIKE 'chk_%_shape'")->fetchColumn());
    }

    public function testLockedFinancialAuthoritySnapshotBlocksConcurrentKeyRevocation(): void
    {
        require_once dirname(__DIR__,2).'/src/utils/api_v2_financial_projection.php';
        $this->pdo->exec("CREATE TABLE api_v2_applications(id BIGINT PRIMARY KEY,application_id CHAR(36));CREATE TABLE api_keys(id BIGINT PRIMARY KEY,api_v2_application_id BIGINT,revoked_at DATETIME NULL);CREATE TABLE api_v2_history_identity(singleton TINYINT PRIMARY KEY,source_instance_id CHAR(36),history_epoch CHAR(36));CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT PRIMARY KEY,authorization_generation BIGINT);CREATE TABLE api_v2_directory_resource_state(resource_type VARCHAR(32),public_id CHAR(32),revision BIGINT,projection_sha256 CHAR(64),present TINYINT,PRIMARY KEY(resource_type,public_id));CREATE TABLE api_v2_directory_external_bindings(application_pk BIGINT,resource_type VARCHAR(32),external_id VARBINARY(764),public_id CHAR(32),status VARCHAR(32),resource_revision BIGINT,resource_projection_sha256 CHAR(64),PRIMARY KEY(application_pk,resource_type,external_id));CREATE TABLE clients(id BIGINT PRIMARY KEY,public_id CHAR(32),organization_id BIGINT NULL,name VARCHAR(191),email VARCHAR(191),phone VARCHAR(64),client_type VARCHAR(32),address_line1 VARCHAR(191),address_line2 VARCHAR(191),city VARCHAR(100),state VARCHAR(100),postal_code VARCHAR(32),country VARCHAR(100));INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');INSERT INTO api_keys VALUES(7,3,NULL);INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');INSERT INTO api_v2_directory_authorization_state VALUES(3,9);INSERT INTO clients(id,public_id,name) VALUES(10,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Client')");
        $client=$this->pdo->query('SELECT * FROM clients WHERE id=10')->fetch();$hash=\api_v2_directory_canonical_hash($this->pdo,'client',$client);$this->pdo->prepare("INSERT INTO api_v2_directory_resource_state VALUES('client',?,3,?,1)")->execute([$client['public_id'],$hash]);$this->pdo->prepare("INSERT INTO api_v2_directory_external_bindings VALUES(3,'client','client/exact',?,'active',3,?)")->execute([$client['public_id'],$hash]);
        $headers=['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        $this->pdo->exec("ALTER TABLE api_keys ADD COLUMN scopes VARCHAR(1024);UPDATE api_keys SET scopes='api.capabilities.read,financial.documents.read' WHERE id=7");
        $admitted=$this->pdo->query('SELECT scopes FROM api_keys WHERE id=7')->fetchColumn();self::assertTrue(\api_key_has_scope($admitted,'financial.documents.read',false));
        $this->pdo->exec("UPDATE api_keys SET scopes='api.capabilities.read' WHERE id=7");$this->pdo->beginTransaction();self::assertNull(\api_v2_financial_locked_identity($this->pdo,7,$headers));$this->pdo->rollBack();
        $this->pdo->exec("UPDATE api_keys SET scopes='api.capabilities.read,financial.documents.read' WHERE id=7");
        $second=new PDO((string)getenv('API_V2_FINANCIAL_MYSQL_DSN'),(string)getenv('API_V2_FINANCIAL_MYSQL_USER'),(string)getenv('API_V2_FINANCIAL_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$second->exec('SET SESSION innodb_lock_wait_timeout=1');
        $this->pdo->beginTransaction();self::assertNotNull(\api_v2_financial_locked_identity($this->pdo,7,$headers));self::assertNotNull(\api_v2_financial_resolve_scope($this->pdo,3,'client','client/exact',true));
        try{$second->exec("UPDATE api_keys SET scopes='api.capabilities.read' WHERE id=7");self::fail('Concurrent scope removal crossed the locked financial authority snapshot.');}catch(PDOException$error){self::assertSame(1205,(int)($error->errorInfo[1]??0),$error->getMessage());}
        try{$second->exec('UPDATE api_keys SET revoked_at=NOW() WHERE id=7');self::fail('Concurrent revocation crossed the locked financial authority snapshot.');}catch(PDOException$error){self::assertSame(1205,(int)($error->errorInfo[1]??0),$error->getMessage());}
        $this->pdo->rollBack();$second->exec('UPDATE api_keys SET revoked_at=NOW() WHERE id=7');self::assertNull(\api_v2_project_identity($this->pdo,7,$headers,true));
    }

    public function testBackfillPreservesEveryExistingDocumentFieldAndPublicLink(): void
    {
        foreach(['invoices','contracts','project_invoices'] as $table){
            $this->pdo->exec("INSERT INTO {$table}(id,total".($table==='project_invoices'?'':',revision_number').") VALUES(1,42.50".($table==='project_invoices'?'':',1').")");
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN amount_paid DECIMAL(20,2) NOT NULL DEFAULT 2.50,ADD COLUMN balance_due DECIMAL(20,2) NOT NULL DEFAULT 40.00,ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'draft',ADD COLUMN document_date DATE NOT NULL DEFAULT '2026-01-02',ADD COLUMN due_date DATE NOT NULL DEFAULT '2026-02-03',ADD COLUMN finalized_at DATETIME NULL,ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT '2026-01-01 00:00:00'");
            $this->pdo->exec("UPDATE {$table} SET updated_at='2026-01-03 04:05:06'");
        }
        $this->pdo->exec("INSERT INTO public_links VALUES(1,'invoice',1,'synthetic_existing_link_123',0,NULL,'2026-01-01')");
        $snapshot=function():array{$rows=[];foreach(['invoices','contracts','project_invoices','public_links'] as $table){$rows[$table]=$this->pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);foreach($rows[$table] as &$row){unset($row['public_id'],$row['currency']);}unset($row);}return $rows;};
        $before=$snapshot();$this->runMigration();self::assertSame($before,$snapshot());$this->runMigration();self::assertSame($before,$snapshot());
    }

    private function runMigration(): void
    {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0107_financial_document_public_ids.sql');
        foreach(\migration_statements($sql) as $statement){$result=$this->pdo->query($statement);if($result!==false)$result->closeCursor();}
    }

    private function resetSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS public_links');
        $this->pdo->exec('CREATE TABLE public_links(id INT PRIMARY KEY,document_type VARCHAR(32),document_id INT,token VARCHAR(64),revoked TINYINT,expires_at DATETIME NULL,created_at DATETIME)');
        foreach(['invoice_currency_from_pricing_snapshot','contract_currency_from_pricing_snapshot','invoices_public_id_immutable','contracts_public_id_immutable','project_invoices_public_id_immutable'] as $trigger)$this->pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
        foreach(['api_v2_directory_external_bindings','api_v2_directory_resource_state','api_v2_directory_authorization_state','api_keys','api_v2_applications','api_v2_history_identity','clients','document_pricing_adjustment_snapshots','project_invoices','contracts','invoices'] as $table)$this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        $this->pdo->exec('CREATE TABLE invoices(id BIGINT PRIMARY KEY,revision_number INT NOT NULL,total DECIMAL(20,2) NOT NULL);CREATE TABLE contracts(id BIGINT PRIMARY KEY,revision_number INT NOT NULL,total DECIMAL(20,2) NOT NULL);CREATE TABLE project_invoices(id BIGINT PRIMARY KEY,total DECIMAL(20,2) NOT NULL);CREATE TABLE document_pricing_adjustment_snapshots(id BIGINT AUTO_INCREMENT PRIMARY KEY,document_type VARCHAR(32) NOT NULL,document_id BIGINT NOT NULL,document_revision INT NOT NULL,currency CHAR(3) NOT NULL)');
        foreach(['invoices','contracts','project_invoices'] as $table)$this->pdo->exec("ALTER TABLE {$table} ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
}
