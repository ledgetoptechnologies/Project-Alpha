<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ProjectCloseGuardService;
use App\Services\ProjectContractEligibilityGuardService;
use App\Services\ProjectReceivablesSummaryService;
use DomainException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ProjectCloseGuardServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE app_config (organization_id INTEGER NOT NULL,config_key TEXT NOT NULL,config_value TEXT)');
        $this->pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,organization_id INTEGER,status TEXT NOT NULL,completed_at TEXT,source_version TEXT,updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE contracts (id INTEGER PRIMARY KEY,project_id INTEGER,job_id INTEGER,doc_number TEXT,status TEXT NOT NULL,contract_type TEXT NOT NULL DEFAULT \'regular\')');
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,balance_due TEXT,collection_mode TEXT,finalized_at TEXT)');
        $this->pdo->exec('CREATE TABLE project_invoices (id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,balance_due TEXT,finalized_at TEXT)');
        $this->pdo->exec('CREATE TABLE project_invoice_items (id INTEGER PRIMARY KEY,project_invoice_id INTEGER,invoice_id INTEGER UNIQUE)');
        $this->pdo->exec('CREATE TABLE system_audit (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,organization_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT,ip_address TEXT,user_agent TEXT)');
        $this->pdo->exec("INSERT INTO projects (id,organization_id,status,source_version) VALUES (1,7,'active','initial'),(2,7,'active','initial'),(3,7,'completed','initial')");
    }

    public function testCompletedAndCancelledBlockersAreStrictlyAuditedWithoutProjectMutation(): void
    {
        $this->enableGuard();
        $this->pdo->exec("INSERT INTO contracts (id,project_id,doc_number,status,contract_type) VALUES (10,1,'C-10','sent','long_term')");

        foreach (['completed', 'cancelled'] as $targetStatus) {
            $this->pdo->beginTransaction();
            $result = $this->service()->transition(1, $targetStatus, 42);
            $this->pdo->commit();

            self::assertFalse($result['transitioned']);
            self::assertSame([['id' => 10, 'doc_number' => 'C-10', 'status' => 'sent', 'contract_type' => 'long_term']], $result['blockers']);
            self::assertSame('active', $this->projectStatus(1));
            $audit = $this->pdo->query('SELECT action,details FROM system_audit ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            self::assertSame('project.closeout.blocked', $audit['action']);
            self::assertSame([
                'target_status' => $targetStatus,
                'blockers' => [['contract_id' => 10, 'status' => 'sent']],
            ], json_decode((string)$audit['details'], true));
        }
    }

    public function testCollectibleReceivablesNeverBlockAndAreRecordedExactlyInAudit(): void
    {
        $this->enableGuard();
        $this->pdo->exec("INSERT INTO contracts (id,project_id,doc_number,status) VALUES (10,1,'C-10','completed')");
        $this->pdo->exec("INSERT INTO invoices VALUES
            (20,1,'draft','100.00','direct','2026-08-01'),
            (21,1,'sent','100.00','direct',NULL),
            (22,1,'sent','10.25','direct','2026-08-01'),
            (23,1,'partial','3.75','direct','2026-08-01'),
            (24,1,'overdue','2.00','direct','2026-08-01'),
            (25,1,'sent','50.00','project_aggregate','2026-08-01'),
            (28,1,'draft','11.00','project_aggregate',NULL),
            (26,1,'credited','9.00','direct','2026-08-01'),
            (27,1,'unpaid','0.00','direct','2026-08-01')");
        $this->pdo->exec("INSERT INTO project_invoices VALUES
            (30,1,'unpaid','5.00','2026-08-01'),
            (31,1,'draft','8.00','2026-08-01'),
            (32,1,'sent','7.00',NULL)");

        $this->pdo->beginTransaction();
        $result = $this->service()->transition(1, 'completed', 42);
        $this->pdo->commit();

        self::assertTrue($result['transitioned']);
        self::assertSame('completed', $this->projectStatus(1));
        $details = json_decode((string)$this->pdo->query('SELECT details FROM system_audit ORDER BY id DESC LIMIT 1')->fetchColumn(), true);
        self::assertTrue($details['closed_with_outstanding_receivables']);
        self::assertSame(7100, $details['outstanding_receivables_minor']);
        self::assertSame(['count' => 3, 'amount_minor' => 1600], $details['outstanding_receivables_sources']['direct_invoices']);
        self::assertSame(['count' => 1, 'amount_minor' => 5000], $details['outstanding_receivables_sources']['pending_project_charges']);
        self::assertSame(['count' => 1, 'amount_minor' => 500], $details['outstanding_receivables_sources']['project_invoices']);
    }

    public function testBlockedAuditFailureRollsBackAndDoesNotChangeProject(): void
    {
        $this->enableGuard();
        $this->pdo->exec("INSERT INTO contracts (id,project_id,doc_number,status) VALUES (10,1,'C-10','draft')");
        $this->pdo->exec("CREATE TRIGGER fail_project_audit BEFORE INSERT ON system_audit BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");

        $this->pdo->beginTransaction();
        try {
            $this->service()->transition(1, 'completed', 42);
            self::fail('The strict blocked-attempt audit should have failed.');
        } catch (PDOException $error) {
            self::assertStringContainsString('audit unavailable', $error->getMessage());
            $this->pdo->rollBack();
        }
        self::assertSame('active', $this->projectStatus(1));
        self::assertSame(0, $this->auditCount());
    }

    public function testSuccessfulAuditFailureRollsBackTerminalTransition(): void
    {
        $this->enableGuard();
        $this->pdo->exec("CREATE TRIGGER fail_project_audit BEFORE INSERT ON system_audit BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->pdo->beginTransaction();
        try {
            $this->service()->transition(1, 'cancelled', 42);
            self::fail('The strict successful-transition audit should have failed.');
        } catch (PDOException) {
            $this->pdo->rollBack();
        }
        self::assertSame('active', $this->projectStatus(1));
    }

    public function testUnauthorizedAuthorizerCannotMutateOrAudit(): void
    {
        $this->enableGuard();
        $service = new ProjectCloseGuardService(
            $this->pdo,
            static function (): void { throw new DomainException('forbidden'); }
        );
        $this->pdo->beginTransaction();
        try {
            $service->transition(1, 'completed', 42);
            self::fail('Authorization must fail.');
        } catch (DomainException $error) {
            self::assertSame('forbidden', $error->getMessage());
            $this->pdo->rollBack();
        }
        self::assertSame('active', $this->projectStatus(1));
        self::assertSame(0, $this->auditCount());
    }

    public function testFeatureLookupFailureFailsClosed(): void
    {
        $this->pdo->exec('DROP TABLE app_config');
        $this->pdo->beginTransaction();
        try {
            $this->service()->transition(1, 'completed', 42);
            self::fail('A broken feature lookup must not bypass close-out enforcement.');
        } catch (PDOException) {
            $this->pdo->rollBack();
        }
        self::assertSame('active', $this->projectStatus(1));
    }

    public function testContractsFromAnotherProjectCannotBlockTheTransition(): void
    {
        $this->enableGuard();
        $this->pdo->exec("INSERT INTO contracts (id,project_id,doc_number,status) VALUES (10,1,'C-10','draft')");
        $this->pdo->beginTransaction();
        $result = $this->service()->transition(2, 'cancelled', 42);
        $this->pdo->commit();
        self::assertTrue($result['transitioned']);
        self::assertSame('active', $this->projectStatus(1));
        self::assertSame('cancelled', $this->projectStatus(2));
    }

    public function testFeatureOffPreservesOpenContractTransitionAndTruthfulAudit(): void
    {
        $this->pdo->exec("INSERT INTO app_config VALUES (0,'contract_settlement_enabled','0')");
        $this->pdo->exec("INSERT INTO contracts (id,project_id,doc_number,status) VALUES (10,1,'C-10','draft')");
        $this->pdo->beginTransaction();
        $result = $this->service()->transition(1, 'completed', 42);
        $this->pdo->commit();
        self::assertTrue($result['transitioned']);
        $details = json_decode((string)$this->pdo->query('SELECT details FROM system_audit')->fetchColumn(), true);
        self::assertFalse($details['contract_closeout_guarded']);
    }

    public function testEligibilityGuardRejectsProspectiveAndExistingContractsButAllowsEmptyJobs(): void
    {
        $this->enableGuard();
        $guard = new ProjectContractEligibilityGuardService($this->pdo);

        $prospectiveRejected = false;
        $this->pdo->beginTransaction();
        try {
            $guard->assertCanCreateOrAttach(3);
            self::fail('A prospective Contract cannot enter a terminal Project.');
        } catch (DomainException) {
            $prospectiveRejected = true;
            $this->pdo->rollBack();
        }
        self::assertTrue($prospectiveRejected);

        $this->pdo->beginTransaction();
        $guard->assertCanCreateOrAttach(3, [], 99);
        $this->pdo->commit();
        self::assertSame('completed', $this->projectStatus(3));

        $this->pdo->exec("INSERT INTO contracts (id,project_id,job_id,doc_number,status) VALUES (10,NULL,99,'C-10','draft')");
        $existingRejected = false;
        $this->pdo->beginTransaction();
        try {
            $guard->assertCanCreateOrAttach(3, [10], 99);
            self::fail('An existing Contract cannot attach to a terminal Project.');
        } catch (DomainException) {
            $existingRejected = true;
            $this->pdo->rollBack();
        }
        self::assertTrue($existingRejected);
    }

    public function testReceivablesBatchReturnsExactTotalsAndZeroRows(): void
    {
        $this->pdo->exec("INSERT INTO invoices VALUES
            (1,1,'sent','1.25','direct','2026-08-01'),
            (2,2,'partial','2.50','direct','2026-08-01'),
            (4,1,'unpaid','4.25','project_aggregate','2026-08-01'),
            (5,1,'draft','9.00','project_aggregate',NULL),
            (6,1,'unpaid','8.00','project_aggregate','2026-08-01')");
        $this->pdo->exec("INSERT INTO project_invoices VALUES (3,1,'overdue','3.75','2026-08-01')");
        $this->pdo->exec('INSERT INTO project_invoice_items VALUES (1,3,6)');
        $summaries = (new ProjectReceivablesSummaryService($this->pdo))->summarizeProjects([999, 2, 1]);
        self::assertSame(925, $summaries[1]['total_minor']);
        self::assertSame(['count' => 1, 'amount_minor' => 425], $summaries[1]['sources']['pending_project_charges']);
        self::assertSame(250, $summaries[2]['total_minor']);
        self::assertSame(0, $summaries[999]['total_minor']);
        self::assertFalse($summaries[999]['has_outstanding']);
    }

    private function service(): ProjectCloseGuardService
    {
        return new ProjectCloseGuardService($this->pdo, static function (PDO $pdo, int $projectId): void {});
    }

    private function enableGuard(): void
    {
        $this->pdo->exec("INSERT INTO app_config VALUES (0,'contract_settlement_enabled','1')");
    }

    private function projectStatus(int $projectId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM projects WHERE id=?');
        $statement->execute([$projectId]);
        return (string)$statement->fetchColumn();
    }

    private function auditCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit')->fetchColumn();
    }
}
