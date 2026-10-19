<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_lifecycle.php';
require_once dirname(__DIR__, 2) . '/src/services/DocumentRevisionService.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InvoiceEditorIssuerTransactionDatabaseTest extends TestCase
{
    private PDO $pdo;
    private array $users = [];
    private int $client = 0;
    private int $organization = 0;
    private int $invoice = 0;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                self::markTestSkipped('Real MySQL is required for implicit-commit coverage.');
            }
            $this->pdo->query('SELECT issuer_snapshot FROM invoices LIMIT 0');
        } catch (Throwable $error) {
            self::markTestSkipped('Migrated MySQL is unavailable.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->invoice) {
            $this->pdo->prepare("DELETE FROM document_revisions WHERE document_type='invoice' AND document_id=?")->execute([$this->invoice]);
            $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$this->invoice]);
        }
        if ($this->client) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
        if ($this->organization) $this->pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$this->organization]);
        foreach ($this->users as $id) $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    }

    public function testCurrentSchemaPaidTotalReadPreservesRealMySqlTransactionAndRollback(): void
    {
        $this->fixture();
        // Do not preinitialize compatibility: the first call is inside MySQL's
        // transaction, reproducing the original controller's implicit-commit risk.
        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE invoices SET total=9 WHERE id=?')->execute([$this->invoice]);
        self::assertSame(0.0, invoice_effective_paid_total($this->pdo, $this->invoice));
        self::assertTrue($this->pdo->inTransaction(), 'Current-schema reads must not issue DDL.');
        $this->pdo->rollBack();
        self::assertSame('1.00', $this->row()['total']);
    }

    public function testActualInvoiceUpdateControllerSavesIssuerRevisionAndRollsBackRejectedEdit(): void
    {
        $this->fixture();
        $before = $this->row();
        $initialRevision = $this->revisions()[0];
        $result = $this->post('creator');
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringNotContainsString('There is no active transaction', $result['stderr']);
        $row = $this->row();
        $snapshot = json_decode((string)$row['issuer_snapshot'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('creator', $row['issuer_mode']);
        self::assertSame('creator', $snapshot['mode']);
        self::assertSame($this->users[0], $snapshot['original_creator_id']);
        self::assertSame('Synthetic Original Creator', $snapshot['sender']['name']);
        self::assertSame($before['created_by'], $row['created_by']);
        $revisions = $this->revisions();
        self::assertCount(2, $revisions);
        self::assertSame($this->users[1], (int)$revisions[1]['created_by']);
        // MySQL stores snapshot as native JSON and can normalize its serialization.
        // Preserve the complete stored revision/hash rather than rehashing its text.
        self::assertSame($initialRevision, $revisions[0]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $revisions[1]['content_hash']);
        self::assertNotSame($initialRevision['content_hash'], $revisions[1]['content_hash']);
        $creatorRevision = $revisions[1];
        $first = json_decode($revisions[0]['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('company', json_decode($first['document']['issuer_snapshot'], true)['mode']);

        self::assertSame(0, $this->post('company')['exit']);
        $company = $this->row();
        self::assertSame('company', json_decode($company['issuer_snapshot'], true)['mode']);
        $companyRevisions = $this->revisions();
        self::assertCount(3, $companyRevisions);
        self::assertSame($initialRevision, $companyRevisions[0]);
        self::assertSame($creatorRevision, $companyRevisions[1]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $companyRevisions[2]['content_hash']);
        $beforeRejected = $company;
        $revisionsBeforeRejected = $this->revisions();
        $rejected = $this->post('invalid-mode', '9');
        self::assertSame(0, $rejected['exit'], $rejected['stderr']);
        self::assertStringContainsString('DomainException', $rejected['stderr']);
        self::assertStringNotContainsString('Invalid document issuer choice.', $rejected['stderr']);
        self::assertSame($beforeRejected, $this->row(), 'Rejected issuer must roll back the entire invoice edit.');
        self::assertSame($revisionsBeforeRejected, $this->revisions());
        foreach (['email_delivery_log', 'public_links'] as $table) {
            $s=$this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE document_type='invoice' AND document_id=?");
            $s->execute([$this->invoice]);self::assertSame(0, (int)$s->fetchColumn());
        }
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM invoice_notifications WHERE invoice_id=?');
        $s->execute([$this->invoice]);self::assertSame(0, (int)$s->fetchColumn());
        self::assertNull($company['sent_at']);self::assertNull($company['finalized_at']);
    }

    public function testIsolatedSecondConnectionRepairFailsClosedInsideTransactionAndRepairsOutside(): void
    {
        if (getenv('PA_DISPOSABLE_SCHEMA_PROOF') !== '1') {
            self::markTestSkipped('Auxiliary schema mutations require the disposable MySQL proof runner.');
        }
        invoice_ensure_payments_schema($this->pdo);
        self::assertTrue(invoice_table_has_column($this->pdo,'payments','processor_payment_id'));
        $name='pa_invoice_schema_probe_'.bin2hex(random_bytes(8));
        $this->pdo->exec('CREATE DATABASE `'.$name.'`');
        $other=null;
        try {
            $other=new PDO('mysql:host='.(getenv('DB_HOST')?:'db').';dbname='.$name.';charset=utf8mb4',getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:getenv('MYSQL_ROOT_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $other->exec('CREATE TABLE payments(id INT PRIMARY KEY,invoice_id INT,client_id INT NOT NULL,payment_method VARCHAR(20) NULL,amount DECIMAL(12,2),payment_date DATE) ENGINE=InnoDB');
            $other->exec("INSERT INTO payments VALUES(1,1,1,'cash',0,'2026-10-19')");
            self::assertFalse(invoice_table_has_column($other,'payments','processor_payment_id'),'Column cache must belong to its PDO.');
            $other->beginTransaction();
            $other->exec('UPDATE payments SET amount=9 WHERE id=1');
            try {
                invoice_ensure_payments_schema($other);
                self::fail('Missing schema was repaired inside a transaction or skipped due to another PDO.');
            } catch (LogicException $expected) {
                self::assertStringContainsString('before starting a document transaction',$expected->getMessage());
            }
            self::assertTrue($other->inTransaction());
            self::assertFalse(invoice_table_has_column($other,'payments','processor_payment_id'));
            $other->rollBack();
            self::assertSame('0.00',$other->query('SELECT amount FROM payments WHERE id=1')->fetchColumn());
            invoice_ensure_payments_schema($other);
            self::assertTrue(invoice_table_has_column($other,'payments','processor_payment_id'),'An earlier absent-column result must not hide a successful repair.');
            $other->beginTransaction();
            self::assertSame(0.0,invoice_effective_paid_total($other,1));
            self::assertTrue($other->inTransaction());
            $other->rollBack();
        } finally {
            if ($other && $other->inTransaction()) $other->rollBack();
            $other=null;
            $this->pdo->exec('DROP DATABASE `'.$name.'`');
        }
    }

    private function fixture(): void
    {
        foreach (['Synthetic Original Creator', 'Synthetic Editing Admin'] as $name) {
            $s=$this->pdo->prepare("INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,'admin')");
            $s->execute([$name, bin2hex(random_bytes(8)).'@example.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $this->users[]=(int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare('INSERT INTO organizations (name) VALUES (?)')->execute(['PA isolated issuer transaction fixture']);
        $this->organization=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO clients (name,email,organization_id) VALUES (?,?,?)')->execute(['PA isolated issuer fixture','never-send@example.invalid',$this->organization]);
        $this->client=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO invoices (client_id,organization_id,created_by,doc_number,invoice_type,status,subtotal,total,amount_paid,balance_due,due_date,due_date_source,collection_mode,issuer_mode) VALUES (?,?,?,?,'regular','draft',1,1,0,1,'2026-11-19','manual','direct','default')")
            ->execute([$this->client,$this->organization,$this->users[0],random_int(1000000,9999999)]);
        $this->invoice=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO invoice_items (invoice_id,item,description,quantity,unit_price,line_total,billing_unit,is_extra_charge) VALUES (?,'Synthetic issuer item','No delivery',1,1,1,'each',0)")->execute([$this->invoice]);
        $this->pdo->beginTransaction();
        DocumentRevisionService::snapshotAndSave($this->pdo,'invoice',$this->invoice,$this->users[0],false,['brand_name'=>'Synthetic Company','from_company'=>'Synthetic Company','document_issuer_mode'=>'company']);
        $this->pdo->commit();
    }

    private function post(string $mode, string $discount = '0'): array
    {
        $root=dirname(__DIR__,2);
        $post=['id'=>$this->invoice,'client_id'=>$this->client,'document_issuer_mode'=>$mode,'discount_type'=>'fixed','discount_value'=>$discount,'tax_percent'=>'0','due_date'=>'2026-11-19','billing_mode'=>'fixed'];
        // This subprocess is a disposable authenticated controller fixture, not a
        // login/session mechanism for a running PA installation. Browser CSRF and
        // sign-in acceptance are separate; this executes the actual save controller.
        $script='require '.var_export($root.'/vendor/autoload.php',true).';'
            .'$_SESSION=["user"=>["id"=>'.(int)$this->users[1].',"role"=>"admin"]];'
            .'$_POST='.var_export($post,true).';$_SERVER["REQUEST_METHOD"]="POST";'
            .'require '.var_export($root.'/src/controllers/invoice/invoices_update.php',true).';';
        $process=proc_open([PHP_BINARY,'-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        return ['exit'=>proc_close($process),'stdout'=>$stdout,'stderr'=>$stderr];
    }

    private function row(): array
    {
        $s=$this->pdo->prepare('SELECT * FROM invoices WHERE id=?');$s->execute([$this->invoice]);return $s->fetch(PDO::FETCH_ASSOC);
    }
    private function revisions(): array
    {
        $s=$this->pdo->prepare("SELECT * FROM document_revisions WHERE document_type='invoice' AND document_id=? ORDER BY revision_number");$s->execute([$this->invoice]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
