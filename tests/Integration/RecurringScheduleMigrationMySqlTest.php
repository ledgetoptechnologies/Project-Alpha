<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

/** Opt-in synthetic upgrade proof; never uses or resets the configured application database. */
final class RecurringScheduleMigrationMySqlTest extends TestCase
{
    private ?PDO $server = null;
    private ?PDO $pdo = null;
    private ?string $ownedDatabase = null;
    private const TABLES = ['clients', 'quotes', 'quote_items', 'contracts', 'contract_items',
        'contract_signatures', 'contract_recurring_services', 'invoices', 'invoice_items', 'payments', 'public_links'];

    protected function setUp(): void
    {
        if (getenv('RECURRING_MIGRATION_TESTS') !== 'isolated-disposable-only') {
            self::markTestSkipped('Requires explicit isolated MySQL server permission (RECURRING_MIGRATION_TESTS).');
        }
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        $user = getenv('MYSQL_USER') ?: 'root';
        $password = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '';
        $this->server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $database = 'pa_recurring_migration_test_' . bin2hex(random_bytes(16));
        // No IF NOT EXISTS: ownership is acquired only after successful creation.
        $this->server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->ownedDatabase = $database;
        $this->pdo = $this->server;
        $this->pdo->exec("USE `{$database}`");
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->ownedDatabase !== null) {
            if (!preg_match('/^pa_recurring_migration_test_[a-f0-9]{32}$/D', $this->ownedDatabase)) {
                throw new RuntimeException('Refusing cleanup of an unrecognized database.');
            }
            $this->server->exec('DROP DATABASE `' . $this->ownedDatabase . '`');
        }
        $this->pdo = $this->server = null;
    }

    public function testFreshInstallationHasBackwardCompatibleRateDefaults(): void
    {
        $this->applySql(dirname(__DIR__, 2) . '/database/baseline.sql');
        $this->applyMigrations(1, 111);
        migration_schema_health($this->pdo, 111);
        self::assertSame(111, (int)$this->pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn());
        foreach (['quotes', 'contracts', 'contract_recurring_services'] as $table) {
            $column = $this->pdo->query("SHOW COLUMNS FROM {$table} LIKE 'rate_basis'")->fetch();
            self::assertSame('per_period', $column['Default']);
            $column = $this->pdo->query("SHOW COLUMNS FROM {$table} LIKE 'monthly_rate'")->fetch();
            self::assertNull($column['Default']);
        }
    }

    public function testUpgradePreservesAllThreeDocumentFamiliesAndFinancialHistory(): void
    {
        $this->applySql(dirname(__DIR__, 2) . '/database/baseline.sql');
        $this->applyMigrations(1, 110);
        $this->seedMixedHistory();
        $before = $this->rows();
        $this->applyMigrations(111, 111);
        migration_schema_health($this->pdo, 111);
        $after = $this->rows();
        foreach ($before as $table => $rows) {
            self::assertCount(count($rows), $after[$table], $table);
            foreach ($rows as $index => $row) {
                self::assertSame($row, array_intersect_key($after[$table][$index], $row),
                    "Every existing column, including timestamps and financial identity, must survive: {$table}");
            }
        }
        foreach (['quotes', 'contracts', 'contract_recurring_services'] as $table) {
            self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE rate_basis<>'per_period' OR monthly_rate IS NOT NULL")->fetchColumn());
        }
        foreach (['contract_recurring_service_changes', 'recurring_invoice_coverage'] as $table) {
            self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), 'No invented historical schedule/coverage.');
        }
        migration_validate_history(migration_files(dirname(__DIR__, 2) . '/database/migrations'), migration_ledger($this->pdo));
    }

    private function seedMixedHistory(): void
    {
        $p = $this->pdo;
        $p->exec("INSERT INTO clients(name,email) VALUES('Synthetic upgrade client','upgrade@example.invalid')");
        $client = (int)$p->lastInsertId();
        $issuer = json_encode(['sender' => ['company' => 'Synthetic Co']], JSON_THROW_ON_ERROR);
        foreach (['regular', 'long_term', 'on_demand'] as $i => $type) {
            $p->prepare("INSERT INTO quotes(client_id,quote_type,status,total,scope,terms,issuer_mode,issuer_snapshot) VALUES(?,?,'approved',1200,'Preserve scope','Preserve terms','company',?)")->execute([$client,$type,$issuer]);
            $quote = (int)$p->lastInsertId();
            $p->prepare("INSERT INTO quote_items(quote_id,item,quantity,unit_price,line_total) VALUES(?,'Original line',2,600,1200)")->execute([$quote]);
            $p->prepare("INSERT INTO contracts(client_id,quote_id,contract_type,status,pricing_type,price_per_invoice,total,scope,terms,signed_pdf_path,signed_at,billing_interval_unit,start_date,next_invoice_date,last_invoice_date,total_invoiced,invoices_generated,issuer_mode,issuer_snapshot) VALUES(?,?,?,'active',?,1200,1200,'Preserve scope','Preserve terms','synthetic/signed.pdf','2026-01-01','year','2026-01-01','2027-01-01','2026-01-01',1200,1,'company',?)")
                ->execute([$client,$quote,$type,$type==='long_term'?'per_invoice':($type==='on_demand'?'on_demand':'fixed_total'),$issuer]);
            $contract = (int)$p->lastInsertId();
            $p->prepare("INSERT INTO contract_items(contract_id,item,quantity,unit_price,line_total) VALUES(?,'Original line',2,600,1200)")->execute([$contract]);
            $p->prepare("INSERT INTO contract_signatures(contract_id,signature_data,signed_at) VALUES(?,'synthetic-signature','2026-01-01')")->execute([$contract]);
            if ($type === 'long_term') {
                $p->prepare("INSERT INTO contract_recurring_services(contract_id,name,amount,billing_interval_unit,effective_from,next_invoice_date,last_invoice_date,status,approval_status,is_base) VALUES(?,'Original yearly service',1200,'year','2026-01-01','2027-01-01','2026-01-01','active','approved',1)")->execute([$contract]);
            }
            $p->prepare("INSERT INTO invoices(client_id,quote_id,contract_id,invoice_type,status,total,amount_paid,balance_due,finalized_at,issuer_mode,issuer_snapshot) VALUES(?,?,?,?,?,1200,?,?,'2026-01-01','company',?)")
                ->execute([$client,$quote,$contract,$type,['unpaid','paid','partial'][$i],[0,1200,600][$i],[1200,0,600][$i],$issuer]);
            $invoice = (int)$p->lastInsertId();
            $p->prepare("INSERT INTO invoice_items(invoice_id,item,description,quantity,unit_price,line_total) VALUES(?,'Original line','Historical description',2,600,1200)")->execute([$invoice]);
            if ($i > 0) $p->prepare("INSERT INTO payments(invoice_id,amount,payment_date) VALUES(?,?,'2026-01-01')")->execute([$invoice,$i===1?1200:600]);
            $p->prepare("INSERT INTO public_links(document_type,document_id,token) VALUES('invoice',?,?)")->execute([$invoice,str_repeat((string)($i+1),64)]);
        }
    }

    private function rows(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) $rows[$table] = $this->pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
        return $rows;
    }

    private function applyMigrations(int $from, int $through): void
    {
        foreach (migration_files(dirname(__DIR__, 2) . '/database/migrations') as $file) {
            if ($file['version'] < $from || $file['version'] > $through) continue;
            migration_preflight($this->pdo, $file['version']);
            $this->applySql($file['path']);
            $this->pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(?,?,?)')
                ->execute([$file['version'],$file['filename'],$file['checksum']]);
        }
    }

    private function applySql(string $path): void
    {
        foreach (migration_statements((string)file_get_contents($path)) as $statement) {
            $result = $this->pdo->query($statement);
            if ($result !== false) $result->closeCursor();
        }
    }
}
