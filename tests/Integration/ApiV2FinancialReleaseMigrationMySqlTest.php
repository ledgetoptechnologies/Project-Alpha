<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

/** Synthetic structural rehearsal only; this is not exact production-data proof. */
final class ApiV2FinancialReleaseMigrationMySqlTest extends TestCase
{
    private const PARENT_BASELINE_BLOB = '27976ee0724ac4c02c5551e120f105ad7822fdaa';
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = (string)getenv('API_V2_FINANCIAL_MYSQL_DSN');
        $user = (string)getenv('API_V2_FINANCIAL_MYSQL_USER');
        $password = getenv('API_V2_FINANCIAL_MYSQL_PASSWORD');
        $database = (string)getenv('API_V2_FINANCIAL_MYSQL_DATABASE');
        if ($dsn === '' || $user === '' || $password === false) {
            self::markTestSkipped('Use the strict disposable financial MySQL runner.');
        }
        if (getenv('API_V2_FINANCIAL_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only'
            || preg_match('/^api_v2_financial_test_[a-f0-9]{32}$/D', $database) !== 1) {
            throw new RuntimeException('Financial release rehearsal requires the disposable runner sentinel.');
        }
        $this->pdo = new PDO($dsn, $user, (string)$password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (!hash_equals($database, (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn())) {
            throw new RuntimeException('Refusing an unrecognized MySQL database.');
        }
        $this->resetDatabase();
    }

    public function testFreshBootstrapRunsTheRealBaselineAndCompleteMigrationChain(): void
    {
        $this->applySqlFile(dirname(__DIR__, 2) . '/database/baseline.sql', 'baseline.sql');
        $this->applyMigrations(1, 107);
        migration_schema_health($this->pdo, 107);
        $this->assertExactLedger(107);
        $this->assertFinancialSchema();
    }

    public function testParentUpgradePreservesDocumentsAndExistingPublicLinks(): void
    {
        $fixture = dirname(__DIR__) . '/fixtures/financial-release/parent-baseline.sql';
        $bytes = (string)file_get_contents($fixture);
        self::assertSame(self::PARENT_BASELINE_BLOB,
            sha1('blob ' . strlen($bytes) . "\0" . $bytes), 'Historical baseline fixture drifted.');
        $this->applySql($bytes, 'parent-baseline.sql');
        $this->applyMigrations(1, 106);
        migration_schema_health($this->pdo, 106);

        $this->pdo->exec("INSERT INTO organizations(id,name) VALUES(90001,'Synthetic financial organization');
          INSERT INTO clients(id,name,organization_id) VALUES(91001,'Synthetic financial client',90001);
          INSERT INTO projects(id,client_id,organization_id,name) VALUES(92001,91001,90001,'Synthetic financial project');
          INSERT INTO invoices(id,client_id,organization_id,status,total,amount_paid,balance_due,revision_number,updated_at)
            VALUES(93001,91001,90001,'unpaid',125.50,25.00,100.50,3,'2026-10-01 01:02:03');
          INSERT INTO contracts(id,client_id,organization_id,status,total,revision_number,updated_at)
            VALUES(94001,91001,90001,'active',250.75,2,'2026-10-02 02:03:04');
          INSERT INTO project_invoices(id,project_id,primary_client_id,billing_period_start,billing_period_end,total,
            amount_paid,balance_due,updated_at) VALUES(95001,92001,91001,'2026-09-01','2026-09-30',300.25,50.00,250.25,
            '2026-10-03 03:04:05');
          INSERT INTO document_pricing_adjustment_snapshots(organization_id,document_type,document_id,document_revision,
            source_type,currency,basis_minor,adjustment_minor,adjusted_minor,calculation_version)
            VALUES(90001,'invoice',93001,3,'none','EUR',12550,0,12550,'percentage-v1'),
              (90001,'contract',94001,2,'none','GBP',25075,0,25075,'percentage-v1');
          INSERT INTO public_links(id,token,document_type,document_id,revoked,expires_at,created_at)
            VALUES(96001,'synthetic-existing-invoice-link','invoice',93001,0,NULL,'2026-10-04 04:05:06'),
              (96002,'synthetic-existing-contract-link','contract',94001,1,'2026-12-01 00:00:00','2026-10-05 05:06:07')");
        $before = $this->preservedRows();

        $this->applyMigrations(107, 107);
        migration_schema_health($this->pdo, 107);
        self::assertSame($before, $this->preservedRows());
        $this->assertExactLedger(107);
        $this->assertFinancialSchema();
        self::assertSame(['EUR', 'GBP', 'USD'], [
            $this->pdo->query('SELECT currency FROM invoices WHERE id=93001')->fetchColumn(),
            $this->pdo->query('SELECT currency FROM contracts WHERE id=94001')->fetchColumn(),
            $this->pdo->query('SELECT currency FROM project_invoices WHERE id=95001')->fetchColumn(),
        ]);
        foreach (['invoices', 'contracts', 'project_invoices'] as $table) {
            $id = ['invoices' => 93001, 'contracts' => 94001, 'project_invoices' => 95001][$table];
            $publicId = (string)$this->pdo->query("SELECT public_id FROM {$table} WHERE id={$id}")->fetchColumn();
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $publicId);
            try {
                $this->pdo->exec("UPDATE {$table} SET public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE id={$id}");
                self::fail("{$table} public identity was mutable.");
            } catch (PDOException) {
                self::assertSame($publicId,
                    $this->pdo->query("SELECT public_id FROM {$table} WHERE id={$id}")->fetchColumn());
            }
        }
    }

    private function preservedRows(): array
    {
        $result = [];
        foreach (['invoices' => 93001, 'contracts' => 94001, 'project_invoices' => 95001] as $table => $id) {
            $row = $this->pdo->query("SELECT * FROM {$table} WHERE id={$id}")->fetch();
            unset($row['public_id'], $row['currency']);
            $result[$table] = $row;
        }
        $result['public_links'] = $this->pdo->query('SELECT * FROM public_links WHERE id IN (96001,96002) ORDER BY id')->fetchAll();
        return $result;
    }

    private function applyMigrations(int $from, int $through): void
    {
        foreach (migration_files(dirname(__DIR__, 2) . '/database/migrations') as $file) {
            $version = (int)$file['version'];
            if ($version < $from || $version > $through) continue;
            migration_preflight($this->pdo, $version);
            $this->applySqlFile($file['path'], $file['filename']);
            $this->pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(?,?,?)')
                ->execute([$version, $file['filename'], $file['checksum']]);
        }
    }

    private function applySqlFile(string $path, string $name): void
    {
        $sql = file_get_contents($path);
        self::assertNotFalse($sql, $name);
        $this->applySql((string)$sql, $name);
    }

    private function applySql(string $sql, string $name): void
    {
        foreach (migration_statements($sql) as $statement) {
            try {
                $result = $this->pdo->query($statement);
                if ($result !== false) $result->closeCursor();
            } catch (PDOException $error) {
                throw new RuntimeException($name . ' failed at: '
                    . substr((string)preg_replace('/\s+/', ' ', $statement), 0, 240), 0, $error);
            }
        }
    }

    private function assertExactLedger(int $through): void
    {
        $files = array_values(array_filter(migration_files(dirname(__DIR__, 2) . '/database/migrations'),
            static fn(array $file): bool => (int)$file['version'] <= $through));
        $expected = [['version' => 0, 'filename' => 'baseline.sql', 'checksum' => null]];
        foreach ($files as $file) $expected[] = ['version' => (int)$file['version'],
            'filename' => $file['filename'], 'checksum' => $file['checksum']];
        $actual = $this->pdo->query('SELECT version,filename,checksum FROM schema_migrations ORDER BY version')->fetchAll();
        foreach ($actual as &$row) $row['version'] = (int)$row['version'];
        self::assertSame($expected, $actual);
    }

    private function assertFinancialSchema(): void
    {
        foreach (['invoices', 'contracts', 'project_invoices'] as $table) {
            foreach (['public_id', 'currency'] as $column) {
                $query = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
                $query->execute([$table, $column]);
                self::assertSame(1, (int)$query->fetchColumn());
            }
        }
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN
          ('invoices_public_id_immutable','contracts_public_id_immutable','project_invoices_public_id_immutable')")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM information_schema.innodb_trx')->fetchColumn());
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='VIEW'")->fetchAll(PDO::FETCH_COLUMN) as $view)
            $this->pdo->exec('DROP VIEW IF EXISTS `' . str_replace('`', '``', (string)$view) . '`');
        foreach ($this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table)
            $this->pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string)$table) . '`');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
