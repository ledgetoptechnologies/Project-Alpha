<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/organization_link_strategy.php';
require_once dirname(__DIR__, 2) . '/src/services/LinkResolverService.php';

final class OrganizationLinkStrategyPolicyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE app_config (organization_id INTEGER, config_key TEXT, config_value TEXT)');
        $this->pdo->exec("INSERT INTO app_config VALUES (0,'link_resolver_enabled','0')");
        $this->pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, link_strategy TEXT, source_version TEXT, updated_at TEXT)');
        $this->pdo->exec("INSERT INTO organizations VALUES (1,'shared_folder','original',NULL)");
        $this->pdo->exec('CREATE TABLE link_resolver_config (id INTEGER PRIMARY KEY, provider TEXT, is_enabled INTEGER, credentials TEXT)');
        $this->pdo->exec('CREATE TABLE entity_links (id INTEGER PRIMARY KEY, entity_type TEXT, entity_id INTEGER, link_source TEXT, link_type TEXT, expiration_date TEXT)');
        $this->pdo->exec("INSERT INTO entity_links VALUES
            (1,'organization',1,'resolver','auto_dropbox','2030-01-01'),
            (2,'organization',1,'manual','manual','2030-01-02'),
            (3,'organization',2,'resolver','auto_dropbox','2030-01-03')");
    }

    public function testDisabledFirstDepartmentPreservesStrategyAndExistingLinks(): void
    {
        $this->pdo->beginTransaction();
        self::assertFalse(pa_organization_first_department_link_strategy($this->pdo, 1, 'new'));
        $this->pdo->commit();
        $service = new LinkResolverService($this->pdo);
        $service->removeResolverOrganizationLinks(1);

        self::assertSame('shared_folder', $this->pdo->query('SELECT link_strategy FROM organizations WHERE id=1')->fetchColumn());
        self::assertSame('original', $this->pdo->query('SELECT source_version FROM organizations WHERE id=1')->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM entity_links')->fetchColumn());
        self::assertSame('2030-01-01', $this->pdo->query('SELECT expiration_date FROM entity_links WHERE id=1')->fetchColumn());
        self::assertFalse($service->autoGenerateForOrganization(1)['success']);
        self::assertFalse($service->autoGenerateForDepartment(1)['success']);
    }

    public function testDisabledStrategySaveRejectsWithoutMutation(): void
    {
        $this->pdo->beginTransaction();
        try {
            pa_organization_link_strategy_save($this->pdo, 1, 'overall_folder', 'new');
            self::fail('A crafted strategy POST must not bypass the disabled resolver.');
        } catch (DomainException $error) {
            self::assertStringContainsString('disabled', $error->getMessage());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame('shared_folder', $this->pdo->query('SELECT link_strategy FROM organizations WHERE id=1')->fetchColumn());
    }

    public function testEnabledFirstDepartmentChangesStrategyInsideCallerTransaction(): void
    {
        $this->enable();
        $this->pdo->beginTransaction();
        self::assertTrue(pa_organization_first_department_link_strategy($this->pdo, 1, 'department-v1'));
        self::assertSame('department_links_only', $this->pdo->query('SELECT link_strategy FROM organizations WHERE id=1')->fetchColumn());
        $this->pdo->rollBack();
        self::assertSame('shared_folder', $this->pdo->query('SELECT link_strategy FROM organizations WHERE id=1')->fetchColumn(), 'Rollback must cover automatic strategy changes too.');
    }

    public function testEnabledCleanupRemovesOnlyTargetResolverLinks(): void
    {
        $this->enable();
        $service = new LinkResolverService($this->pdo);
        $service->removeResolverOrganizationLinks(1);
        self::assertSame([2, 3], array_map('intval', $this->pdo->query('SELECT id FROM entity_links ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame('2030-01-02', $this->pdo->query('SELECT expiration_date FROM entity_links WHERE id=2')->fetchColumn(), 'Manual link settings must remain untouched.');
    }

    public function testDisablingAfterServiceConstructionPreventsCleanup(): void
    {
        $this->enable();
        $service = new LinkResolverService($this->pdo);
        $this->pdo->exec("UPDATE app_config SET config_value='0'");
        $service->removeResolverOrganizationLinks(1);
        self::assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM entity_links')->fetchColumn());
        self::assertSame('Link resolver is disabled', $service->autoGenerateForClient(1)['message']);
        self::assertSame('Link resolver is disabled', $service->autoGenerateForOrganization(1)['message']);
        self::assertSame('Link resolver is disabled', $service->autoGenerateForDepartment(1)['message']);
        self::assertSame('Link resolver is disabled', $service->runProviderScan('dropbox')['message']);
    }

    public function testStrategyMutationCannotEscapeCallerTransaction(): void
    {
        $this->enable();
        $this->expectException(LogicException::class);
        pa_organization_link_strategy_save($this->pdo, 1, 'overall_folder', 'new');
    }

    public function testNonGlobalSwitchDoesNotEnableResolver(): void
    {
        $this->pdo->exec("INSERT INTO app_config VALUES (99,'link_resolver_enabled','1')");
        self::assertFalse(pa_link_resolver_enabled($this->pdo));
        $this->pdo->exec("DELETE FROM app_config WHERE organization_id=0");
        self::assertFalse(pa_link_resolver_enabled($this->pdo));
    }

    private function enable(): void
    {
        $this->pdo->exec("UPDATE app_config SET config_value='1' WHERE organization_id=0");
    }
}
