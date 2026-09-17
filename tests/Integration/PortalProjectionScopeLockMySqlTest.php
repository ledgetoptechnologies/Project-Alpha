<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\PortalProjectionMutationService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PortalProjectionScopeLockMySqlTest extends TestCase
{
    private PDO $first;
    private PDO $second;
    private PortalProjectionMutationService $projection;

    protected function setUp(): void
    {
        $dsn = getenv('PORTAL_SCOPE_MYSQL_DSN');
        $user = getenv('PORTAL_SCOPE_MYSQL_USER');
        $password = getenv('PORTAL_SCOPE_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) {
            self::markTestSkipped('Run tools/run-portal-scope-mysql-integration.ps1 for the isolated MySQL concurrency tests.');
        }
        $expectedDatabase = trim((string)(getenv('PORTAL_SCOPE_MYSQL_DATABASE') ?: ''));
        if (getenv('PORTAL_SCOPE_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only'
            || preg_match('/^portal_scope_test_[a-f0-9]{32}$/D', $expectedDatabase) !== 1) {
            throw new \RuntimeException('Portal scope MySQL tests require the disposable runner and its destructive-test sentinel.');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        $actualDatabase = (string)$this->first->query('SELECT DATABASE()')->fetchColumn();
        if (!hash_equals($expectedDatabase, $actualDatabase)) {
            throw new \RuntimeException('Refusing to reset a MySQL database that was not created by the disposable test runner.');
        }
        $this->first->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $this->second->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; SET SESSION innodb_lock_wait_timeout=1");
        $this->projection = new PortalProjectionMutationService();
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $pdo) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testMultiClientOnboardingLocksAllDependentProjectsBeforeClientsAndUsesCurrentReads(): void
    {
        $this->first->beginTransaction();
        self::assertSame(
            ['organization|org-a', 'organization|org-b'],
            $this->scopeKeys($this->projection->lockedClientScopesForIds($this->first, [20, 10]))
        );
        $this->first->exec('UPDATE clients SET organization_id=3 WHERE id=10');

        $this->second->beginTransaction();
        self::assertSame(
            [['root_type'=>'organization','root_public_id'=>'org-a']],
            $this->projection->clientScopes($this->second, 10),
            'The ordinary read intentionally establishes the old REPEATABLE READ snapshot.'
        );
        $this->assertLockWaits(fn() => $this->projection->lockedProjectScopes($this->second, 100));

        $this->first->commit();
        self::assertSame(
            ['organization|org-b', 'organization|org-c'],
            $this->scopeKeys($this->projection->lockedClientScopesForIds($this->second, [10, 20])),
            'A current locking read must not reuse the transaction snapshot created before the wait.'
        );
        $this->second->rollBack();
    }

    public function testProjectReparentLocksDestinationClientAndRefreshesAfterConcurrentCommit(): void
    {
        $this->first->beginTransaction();
        self::assertSame(
            [['root_type'=>'standalone_client','root_public_id'=>'client-c']],
            $this->projection->lockedProjectScopes($this->first, 300, 20, 2)
        );
        $this->first->exec('UPDATE projects SET client_id=20,organization_id=2 WHERE id=300');

        $this->second->beginTransaction();
        self::assertSame(
            [['root_type'=>'standalone_client','root_public_id'=>'client-c']],
            $this->projection->projectScopes($this->second, 300),
            'The ordinary read intentionally establishes the old REPEATABLE READ snapshot.'
        );
        $this->assertLockWaits(fn() => $this->projection->lockedClientScopes($this->second, 20, 3));

        $this->first->commit();
        self::assertSame(
            [['root_type'=>'organization','root_public_id'=>'org-b']],
            $this->projection->lockedProjectScopes($this->second, 300),
            'The retried locking read must observe the committed project parent rather than the old snapshot.'
        );
        $this->second->rollBack();
    }

    public function testArchivedClientRestoreLockSerializesCompetingConsumers(): void
    {
        $this->first->exec("INSERT INTO archived_clients(id,client_id,name) VALUES(1,10,'Archived client')");
        $this->first->beginTransaction();
        $lock = $this->first->prepare('SELECT * FROM archived_clients WHERE id=? FOR UPDATE');
        $lock->execute([1]);
        self::assertSame(1, (int)$lock->fetchColumn());

        $this->second->beginTransaction();
        $this->assertLockWaits(function (): void {
            $competing = $this->second->prepare('SELECT * FROM archived_clients WHERE id=? FOR UPDATE');
            $competing->execute([1]);
        });
        $this->first->rollBack();
        $this->second->rollBack();

        $service = (string)file_get_contents(dirname(__DIR__, 2) . '/src/services/ClientArchivePortalStateService.php');
        self::assertStringContainsString("SELECT * FROM archived_clients WHERE id=?' . \$this->lockSuffix(\$pdo)", $service);
        self::assertStringContainsString("'DELETE FROM archived_clients WHERE id=?'", $service);
        self::assertStringContainsString("if (\$delete->rowCount() !== 1)", $service);
    }

    private function assertLockWaits(callable $operation): void
    {
        try {
            $operation();
            self::fail('The competing connection unexpectedly crossed an authoritative scope lock.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        }
    }

    /** @param list<array{root_type:string,root_public_id:string}> $scopes @return list<string> */
    private function scopeKeys(array $scopes): array
    {
        $keys = array_map(static fn(array $scope): string => $scope['root_type'].'|'.$scope['root_public_id'], $scopes);
        sort($keys);
        return $keys;
    }

    private function resetSchema(): void
    {
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['archived_clients','organization_department_contacts','projects','organization_departments','clients','organizations'] as $table) {
            $this->first->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec("CREATE TABLE organizations(
                id INT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB;
            CREATE TABLE clients(
                id INT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE, organization_id INT NULL,
                KEY idx_clients_organization(organization_id)
            ) ENGINE=InnoDB;
            CREATE TABLE organization_departments(
                id INT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE, organization_id INT NOT NULL,
                KEY idx_departments_organization(organization_id)
            ) ENGINE=InnoDB;
            CREATE TABLE projects(
                id INT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE, organization_id INT NULL,
                department_id INT NULL, client_id INT NULL,
                KEY idx_projects_client(client_id), KEY idx_projects_organization(organization_id)
            ) ENGINE=InnoDB;
            CREATE TABLE organization_department_contacts(
                department_id INT NOT NULL, client_id INT NOT NULL,
                PRIMARY KEY(department_id,client_id), KEY idx_department_contacts_client(client_id)
            ) ENGINE=InnoDB;
            CREATE TABLE archived_clients(
                id INT PRIMARY KEY, client_id INT NULL, name VARCHAR(150) NOT NULL
            ) ENGINE=InnoDB;
            INSERT INTO organizations VALUES
                (1,'org-a','Organization A'),(2,'org-b','Organization B'),(3,'org-c','Organization C');
            INSERT INTO clients VALUES
                (10,'client-a',1),(20,'client-b',2),(30,'client-c',NULL);
            INSERT INTO organization_departments VALUES
                (11,'department-a',1),(22,'department-b',2);
            INSERT INTO projects VALUES
                (100,'project-a',1,11,10),(200,'project-b',2,22,20),(300,'project-c',NULL,NULL,30);
            INSERT INTO organization_department_contacts VALUES (11,10),(22,20)");
    }
}
