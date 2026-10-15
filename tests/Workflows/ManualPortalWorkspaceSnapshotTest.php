<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\PortalAuthorityService;
use App\Services\PortalProjectionMutationService;
use App\Services\PortalProjectionService;
use DomainException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ManualPortalWorkspaceSnapshotTest extends TestCase
{
    private const AUTHORITY = ['portal_principals', 'portal_identity_bindings', 'portal_principal_clients',
        'portal_v2_entitlements', 'portal_organization_entitlements', 'portal_project_entitlements',
        'portal_manager_scope_state', 'portal_client_access_roots', 'portal_client_login_eligibility',
        'portal_client_provisioning_backfill'];

    public function testManualOrganizationSnapshotBuildsGraphWithoutProvisioningOrOrdinaryEvents(): void
    {
        $pdo = $this->database();
        $workspace = $this->workspace($pdo, 'organization', 'org-one');
        self::assertSame(0, $this->rowCount($pdo, 'portal_v2_relations'));
        $beforeBusiness = $this->business($pdo);

        $rows = $this->snapshot($pdo, $workspace);

        self::assertSame(['snapshot.page', 'snapshot.activate'], array_column($rows, 'kind'));
        self::assertSame(3, $rows[0]['schemaVersion']);
        self::assertCount(2, $rows[0]['entities']);
        self::assertCount(1, $rows[0]['relations']);
        self::assertSame(3, $rows[0]['recordCount']);
        self::assertSame(['type'=>'organization', 'publicId'=>'org-one'], $rows[0]['relations'][0]['from']);
        self::assertSame(['type'=>'client', 'publicId'=>'client-one'], $rows[0]['relations'][0]['to']);
        self::assertSame('contains', $rows[0]['relations'][0]['relationType']);
        self::assertSame($rows[0]['snapshotHash'], $rows[1]['snapshotHash']);
        self::assertSame($rows[0]['sourceGeneration'], $rows[1]['sourceGeneration']);
        self::assertSame([], $rows[0]['principals']);
        self::assertSame([], $rows[0]['entitlements']);
        self::assertSame($beforeBusiness, $this->business($pdo));
        $this->assertNoAuthority($pdo);
        self::assertSame('0', $pdo->query("SELECT config_value FROM app_config WHERE config_key='portal_authoritative_hooks_enabled'")->fetchColumn());
    }

    public function testRepeatedSnapshotKeepsEdgeIdentityAndOtherWorkspaceUnchanged(): void
    {
        $pdo = $this->database();
        $workspace = $this->workspace($pdo, 'organization', 'org-one');
        $otherWorkspace = $this->workspace($pdo, 'organization', 'org-two');
        $pdo->exec("INSERT INTO portal_v2_relations(public_id,relation_type,from_type,from_public_id,to_type,to_public_id,source_version,active)
            VALUES('other-edge','contains','organization','org-two','client','client-two','other-version',1),
                  ('stale-cross-root','contains','organization','org-two','client','client-one','old-version',1)");
        $beforeOther = $pdo->query("SELECT * FROM portal_v2_relations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $first = $this->snapshot($pdo, $workspace);
        $second = $this->snapshot($pdo, $workspace);

        self::assertSame($first[0]['relations'], $second[0]['relations']);
        self::assertSame($first[0]['snapshotHash'], $second[0]['snapshotHash']);
        self::assertNotSame($first[0]['sourceGeneration'], $second[0]['sourceGeneration']);
        self::assertSame(2, $second[0]['sourceSequence']);
        self::assertSame($beforeOther, $pdo->query("SELECT * FROM portal_v2_relations WHERE public_id IN ('other-edge','stale-cross-root') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM portal_projection_state WHERE workspace_public_id=".$pdo->quote($otherWorkspace))->fetchColumn());
        self::assertSame(['org-one', 'client-one'], array_column($first[0]['entities'], 'publicId'));
        self::assertSame(4, $this->rowCount($pdo, 'portal_projection_outbox'));
        $this->assertNoAuthority($pdo);
    }

    public function testLateQueueFailureRollsBackGraphContactsGenerationAndPageTogether(): void
    {
        $pdo = $this->database();
        $workspace = $this->workspace($pdo, 'organization', 'org-one');
        $beforeBusiness = $this->business($pdo);
        $pdo->exec("CREATE TRIGGER reject_activation BEFORE INSERT ON portal_projection_outbox
            WHEN NEW.delivery_kind='snapshot.activate' BEGIN SELECT RAISE(ABORT,'synthetic late queue failure'); END");

        try {
            $this->snapshot($pdo, $workspace);
            self::fail('The late activation insert must fail.');
        } catch (PDOException $error) {
            self::assertStringContainsString('synthetic late queue failure', $error->getMessage());
        }
        foreach (['portal_v2_relations', 'portal_v2_contacts', 'portal_projection_state',
            'portal_projection_resource_state', 'portal_projection_outbox'] as $table) {
            self::assertSame(0, $this->rowCount($pdo, $table), $table);
        }
        self::assertSame($beforeBusiness, $this->business($pdo));
        self::assertSame(1, $this->rowCount($pdo, 'portal_v2_workspaces'));
        $this->assertNoAuthority($pdo);
    }

    public function testStandaloneSnapshotBuildsProjectEdgeAndLifecycle(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO projects VALUES(40,'project-alone','Synthetic Project',NULL,NULL,30,'active','project-v1',NULL)");
        $workspace = $this->workspace($pdo, 'standalone_client', 'client-alone');
        $rows = $this->snapshot($pdo, $workspace);
        self::assertSame(4, $rows[0]['recordCount']);
        self::assertCount(1, $rows[0]['relations']);
        self::assertSame(['type'=>'standalone_client', 'publicId'=>'client-alone'], $rows[0]['relations'][0]['from']);
        self::assertSame(['type'=>'project', 'publicId'=>'project-alone'], $rows[0]['relations'][0]['to']);
        self::assertSame('project-alone', $rows[0]['projectLifecycles'][0]['projectPublicId']);
        $this->assertNoAuthority($pdo);
    }

    public function testReplacementGenerationPreservesDeliveredPageAndDeadLetteredActivation(): void
    {
        $pdo = $this->database();
        $workspace = $this->workspace($pdo, 'organization', 'org-one');
        $pdo->prepare('INSERT INTO portal_projection_state VALUES(1,?,\'original-generation\',1,?)')
            ->execute([$workspace, str_repeat('0', 64)]);
        $insert = $pdo->prepare("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,payload_json,delivered_at,dead_lettered_at,last_error_code)
            VALUES(1,?,?,3,1,?,'portal',0,?,?,?,?)");
        $insert->execute(['original-page', $workspace, 'snapshot.page', '{"immutable":"original page"}', '2026-10-14', null, null]);
        $insert->execute(['original-activation', $workspace, 'snapshot.activate', '{"immutable":"original activation"}', null, '2026-10-14', 'http_4xx']);
        $before = $pdo->query('SELECT * FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        $rows = $this->snapshot($pdo, $workspace);

        self::assertSame(2, $rows[0]['sourceSequence']);
        self::assertNotSame('original-generation', $rows[0]['sourceGeneration']);
        self::assertSame($before, $pdo->query('SELECT * FROM portal_projection_outbox WHERE id<=2 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(4, $this->rowCount($pdo, 'portal_projection_outbox'));
        self::assertSame(['snapshot.page', 'snapshot.activate'], array_column($rows, 'kind'));
    }

    public function testNarrowGraphWriterRequiresCallerTransaction(): void
    {
        $pdo = $this->database();
        $workspace = $this->workspace($pdo, 'organization', 'org-one');
        try {
            (new PortalProjectionMutationService())->materializeWorkspaceRelations($pdo, 1, $workspace);
            self::fail('Graph writes must share the snapshot transaction.');
        } catch (\LogicException $error) {
            self::assertSame('portal-projection-authoritative-lock-requires-transaction', $error->getMessage());
        }
        self::assertSame(0, $this->rowCount($pdo, 'portal_v2_relations'));
    }

    public function testUnlinkedWorkspaceCannotMaterializeGraph(): void
    {
        $pdo = $this->database();
        $workspace = $this->workspace($pdo, 'organization', 'org-one');
        $pdo->exec('UPDATE portal_integration_profile_workspaces SET active=0');
        try {
            $this->snapshot($pdo, $workspace);
            self::fail('The profile/workspace allowlist must be checked first.');
        } catch (DomainException $error) {
            self::assertSame('portal-profile-workspace-denied', $error->getMessage());
        }
        self::assertSame(0, $this->rowCount($pdo, 'portal_v2_relations'));
        self::assertSame(0, $this->rowCount($pdo, 'portal_projection_outbox'));
    }

    public function testSchemaTwoDoesNotMaterializeRelationGraph(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE portal_integration_profiles SET relation_projection_enabled=0');
        $workspace = $this->workspace($pdo, 'organization', 'org-one');
        $rows = $this->snapshot($pdo, $workspace);
        self::assertSame(2, $rows[0]['schemaVersion']);
        self::assertArrayNotHasKey('relations', $rows[0]);
        self::assertSame(0, $this->rowCount($pdo, 'portal_v2_relations'));
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string)file_get_contents(dirname(__DIR__).'/fixtures/manual-portal-workspace.sql'));
        return $pdo;
    }

    private function workspace(PDO $pdo, string $type, string $root): string
    {
        return (new PortalAuthorityService())->saveWorkspace($pdo, 1, $type, $root, 'Synthetic Workspace', 7);
    }

    private function snapshot(PDO $pdo, string $workspace): array
    {
        $previous = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM portal_projection_outbox')->fetchColumn();
        $pdo->beginTransaction();
        try {
            (new PortalProjectionService())->queueWorkspaceSnapshot($pdo, ['id'=>1], $workspace);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return array_map(static fn(string $body): array => json_decode($body, true, 64, JSON_THROW_ON_ERROR),
            $pdo->query('SELECT payload_json FROM portal_projection_outbox WHERE id>'.$previous.' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function rowCount(PDO $pdo, string $table): int
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }

    private function assertNoAuthority(PDO $pdo): void
    {
        foreach (self::AUTHORITY as $table) self::assertSame(0, $this->rowCount($pdo, $table), $table);
    }

    private function business(PDO $pdo): array
    {
        $result = [];
        foreach (['organizations', 'clients', 'projects', 'organization_departments'] as $table) {
            $result[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
