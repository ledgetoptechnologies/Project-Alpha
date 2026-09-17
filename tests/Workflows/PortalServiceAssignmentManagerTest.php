<?php

declare(strict_types=1);

use App\Services\PortalServiceAssignmentManager;
use App\Services\PortalProjectionMutationService;
use PHPUnit\Framework\TestCase;

final class PortalServiceAssignmentManagerTest extends TestCase
{
    public function testCreateResolvesInternalIdsAndQueuesOnlyTheContainedEnabledProfile(): void
    {
        $pdo = $this->database();
        $result = (new PortalServiceAssignmentManager())->create(
            $pdo, 'client', 11, 1,
            '2026-08-27T10:00:00-05:00', '2026-08-28T15:00:01Z', 9
        );

        self::assertSame([1], $result['projectionProfiles']);
        self::assertMatchesRegularExpression('/^a[a-f0-9]{31}$/', (string)$result['assignment']['public_id']);
        self::assertSame('client', $result['assignment']['subject_type']);
        self::assertSame('client-org-a', $result['assignment']['subject_public_id']);
        self::assertSame('service-map', $result['assignment']['service_public_id']);
        self::assertSame('2026-08-27 15:00:00.000', $result['assignment']['effective_from']);
        self::assertSame('2026-08-28 15:00:01.000', $result['assignment']['effective_until']);
        self::assertSame(9, (int)$result['assignment']['created_by']);
        self::assertSame(9, (int)$result['assignment']['updated_by']);
        self::assertNotSame('', (string)$result['assignment']['created_at']);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id=1')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id=2')->fetchColumn());
    }

    public function testUpdateAndLifecycleKeepPublicIdAndExactSubject(): void
    {
        $pdo = $this->database();
        $manager = new PortalServiceAssignmentManager();
        $created = $manager->create($pdo, 'organization', 1, 1, null, null, 7);
        $id = (int)$created['assignment']['id'];
        $publicId = (string)$created['assignment']['public_id'];

        $updated = $manager->update($pdo, $id, 2,
            '2026-09-01 00:00', null, 8);
        self::assertSame([1], $updated['projectionProfiles']);
        self::assertSame($publicId, $updated['assignment']['public_id']);
        self::assertSame('organization', $updated['assignment']['subject_type']);
        self::assertSame('org-a', $updated['assignment']['subject_public_id']);
        self::assertSame('service-photo', $updated['assignment']['service_public_id']);
        self::assertSame(8, (int)$updated['assignment']['updated_by']);

        $deactivated = $manager->deactivate($pdo, $id, 9);
        self::assertSame(0, (int)$deactivated['assignment']['active']);
        self::assertSame($publicId, $deactivated['assignment']['public_id']);
        self::assertSame([1], $deactivated['projectionProfiles']);

        $reactivated = $manager->reactivate($pdo, $id, 10);
        self::assertSame(1, (int)$reactivated['assignment']['active']);
        self::assertSame($publicId, $reactivated['assignment']['public_id']);

        $deleted = $manager->softDelete($pdo, $id, 11);
        self::assertSame(0, (int)$deleted['assignment']['active']);
        self::assertNotNull($deleted['assignment']['deleted_at']);
        self::assertSame($publicId, $deleted['assignment']['public_id']);
        self::assertSame([], $manager->listForSubject($pdo, 'organization', 1));
        self::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM portal_service_assignment_projection_records WHERE integration_profile_id=1")->fetchColumn());
    }

    public function testStandaloneClientAliasNormalizesBeforeDuplicateCheck(): void
    {
        $pdo = $this->database();
        $manager = new PortalServiceAssignmentManager();
        $created = $manager->create($pdo, 'client', 12, 1, null, null, 9);

        self::assertSame('standalone_client', $created['assignment']['subject_type']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('service-assignment-duplicate');
        $manager->create($pdo, 'standalone_client', 12, 1, null, null, 9);
    }

    /** @dataProvider invalidCreateProvider */
    public function testCreateRejectsInvalidSubjectServiceAndWindow(string $subjectType, int $subjectId,
        int $itemId, ?string $from, ?string $until, string $message): void
    {
        $pdo = $this->database();
        try {
            (new PortalServiceAssignmentManager())->create($pdo, $subjectType, $subjectId, $itemId, $from, $until, 9);
            self::fail('Expected create to fail.');
        } catch (DomainException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignments')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
    }

    /** @return iterable<string,array{string,int,int,?string,?string,string}> */
    public static function invalidCreateProvider(): iterable
    {
        yield 'unknown subject type' => ['workspace', 1, 1, null, null, 'service-assignment-subject-invalid'];
        yield 'organization client cannot masquerade as standalone' => ['standalone_client', 11, 1, null, null, 'service-assignment-subject-invalid'];
        yield 'inactive catalog item' => ['organization', 1, 3, null, null, 'service-assignment-service-unavailable'];
        yield 'non-requestable catalog item' => ['organization', 1, 4, null, null, 'service-assignment-service-unavailable'];
        yield 'non-service catalog item' => ['organization', 1, 5, null, null, 'service-assignment-service-unavailable'];
        yield 'reversed window' => ['organization', 1, 1, '2026-09-02 00:00', '2026-09-01 00:00', 'service-assignment-window-invalid'];
        yield 'permissive date text denied' => ['organization', 1, 1, 'tomorrow', null, 'service-assignment-window-invalid'];
    }

    public function testNonDeliverableProfileDoesNotBlockTheAuthoritativeAssignment(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE portal_integration_profiles SET delivery_enabled=0 WHERE id=1');
        $result = (new PortalServiceAssignmentManager())->create($pdo, 'organization', 1, 1, null, null, 7);
        self::assertSame([], $result['projectionProfiles']);
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignments')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_state')->fetchColumn());
    }

    public function testProjectionFailureRollsBackTheAuthoritativeAssignment(): void
    {
        $pdo = $this->database();
        // The route is now the generic External Operations event endpoint, so
        // it has no portal-specific suffix to validate. A malformed shared
        // endpoint must still fail inside the manager transaction and roll the
        // authoritative assignment back atomically.
        $pdo->exec("UPDATE portal_integration_profiles SET portal_route='not-a-valid-url' WHERE id=1");
        try {
            (new PortalServiceAssignmentManager())->create($pdo, 'organization', 1, 1, null, null, 7);
            self::fail('Expected the invalid deliverable projection contract to reject the atomic mutation.');
        } catch (DomainException $error) {
            self::assertSame('service-assignment-route-invalid', $error->getMessage());
        }
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignments')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
    }

    public function testDuplicateNonDeletedSubjectServiceIsRejectedAcrossCreateUpdateAndReactivate(): void
    {
        $pdo = $this->database();
        $manager = new PortalServiceAssignmentManager();
        $first = $manager->create($pdo, 'organization', 1, 1, null, null, 7);
        $second = $manager->create($pdo, 'organization', 1, 2, null, null, 7);
        foreach ([
            fn() => $manager->create($pdo, 'organization', 1, 1, null, null, 7),
            fn() => $manager->update($pdo, (int)$second['assignment']['id'], 1, null, null, 7),
        ] as $operation) {
            try { $operation(); self::fail('Expected duplicate assignment rejection.'); }
            catch (DomainException $error) { self::assertSame('service-assignment-duplicate', $error->getMessage()); }
        }
        $manager->deactivate($pdo, (int)$first['assignment']['id'], 7);
        $pdo->prepare('INSERT INTO portal_service_assignments(public_id,subject_type,subject_public_id,service_public_id,active,deleted_at,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,1,NULL,7,7,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')
            ->execute(['legacy-duplicate', 'organization', 'org-a', 'service-map']);
        try { $manager->reactivate($pdo, (int)$first['assignment']['id'], 7); self::fail('Expected duplicate reactivation rejection.'); }
        catch (DomainException $error) { self::assertSame('service-assignment-duplicate', $error->getMessage()); }
    }

    public function testCallerOwnedTransactionIsNotCommittedByManager(): void
    {
        $pdo = $this->database();
        $pdo->beginTransaction();
        (new PortalServiceAssignmentManager())->create($pdo, 'project', 21, 1, null, null, 7);
        self::assertTrue($pdo->inTransaction());
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignments')->fetchColumn());
        $pdo->rollBack();
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignments')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
    }

    public function testCatalogUnpublishTombstonesAssignmentAndDoesNotBlockLaterEdits(): void
    {
        $pdo = $this->database();
        $manager = new PortalServiceAssignmentManager();
        $unpublished = $manager->create($pdo, 'organization', 1, 1, null, null, 7);
        $editable = $manager->create($pdo, 'organization', 1, 2, null, null, 7);
        $unpublishedPublicId = (string)$unpublished['assignment']['public_id'];

        self::assertSame(1, (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_service_assignment_projection_records WHERE integration_profile_id=1 AND assignment_public_id="
            . $pdo->quote($unpublishedPublicId)
        )->fetchColumn());
        $pdo->exec('DELETE FROM portal_projection_outbox');

        $pdo->beginTransaction();
        $pdo->exec('UPDATE item_library SET portal_requestable=0 WHERE id=1');
        (new PortalProjectionMutationService())->queueCatalogChanges($pdo, 1);

        self::assertTrue($pdo->inTransaction(), 'Catalog and assignment reconciliation must share the caller transaction.');
        self::assertSame(0, (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_service_assignment_projection_records WHERE integration_profile_id=1 AND assignment_public_id="
            . $pdo->quote($unpublishedPublicId)
        )->fetchColumn());
        $assignmentPayload = (string)$pdo->query(
            "SELECT payload_json FROM portal_projection_outbox WHERE route_type='service_assignments' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNotSame('', $assignmentPayload);
        self::assertSame('tombstone', json_decode($assignmentPayload, true, 512, JSON_THROW_ON_ERROR)['event']['action'] ?? null);
        self::assertSame($unpublishedPublicId, json_decode($assignmentPayload, true, 512, JSON_THROW_ON_ERROR)['event']['assignmentPublicId'] ?? null);
        $pdo->commit();

        $updated = $manager->update($pdo, (int)$editable['assignment']['id'], 2, '2026-09-01 00:00', null, 8);
        self::assertSame([1], $updated['projectionProfiles']);
        self::assertSame('service-photo', $updated['assignment']['service_public_id']);

        $listed = $manager->listForSubject($pdo, 'organization', 1);
        $unavailable = array_values(array_filter(
            $listed,
            static fn(array $assignment): bool => (string)$assignment['public_id'] === $unpublishedPublicId
        ));
        self::assertCount(1, $unavailable, 'Unpublishing does not erase authoritative assignment history.');
        self::assertSame(0, (int)$unavailable[0]['service_available']);
    }

    public function testEverySupportedSubjectTypeUsesItsExactServerResolvedPublicId(): void
    {
        $cases = [
            ['organization', 1, 'org-a'],
            ['department', 31, 'department-a'],
            ['client', 11, 'client-org-a'],
            ['standalone_client', 12, 'client-standalone'],
            ['project', 21, 'project-org-a'],
            ['project', 22, 'project-standalone'],
        ];
        foreach ($cases as [$type, $id, $expected]) {
            $pdo = $this->database();
            $row = (new PortalServiceAssignmentManager())->create($pdo, $type, $id, 1, null, null, 7)['assignment'];
            self::assertSame($type, $row['subject_type']);
            self::assertSame($expected, $row['subject_public_id']);
        }
    }

    /** @dataProvider rootMoveProvider */
    public function testRootMoveQueuesOldProfileTombstoneAndNewProfileUpsert(
        string $destinationRootType,
        string $destinationRootPublicId,
        int $destinationOrganizationId,
        int $destinationProfileId
    ): void {
        $pdo = $this->database();
        if ($destinationRootType === 'standalone_client') {
            $pdo->exec("INSERT INTO portal_v2_workspaces VALUES(4,'workspace-client-a','standalone_client','client-org-a',1);
                INSERT INTO portal_integration_profiles VALUES(5,'alpha_client_a',1,1,1,1,'https://ops.example/api/internal/project-alpha/sources/source-client-a/portal-v2','https://ops.example/api/internal/project-alpha/catalog-v2',1,'key-client-a',15,12);
                INSERT INTO portal_integration_profile_workspaces VALUES(5,4,1)");
        }
        $manager = new PortalServiceAssignmentManager();
        $manager->create($pdo, 'client', 11, 1, null, null, 7);
        $pdo->prepare("INSERT INTO portal_service_assignment_projection_state VALUES(?,'assignments-empty',1,?)")
            ->execute([$destinationProfileId, str_repeat('a', 64)]);
        $pdo->exec('DELETE FROM portal_projection_outbox');

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE clients SET organization_id=? WHERE id=11')
            ->execute([$destinationOrganizationId > 0 ? $destinationOrganizationId : null]);
        $result = $manager->reconcileRoots($pdo, [
            ['root_type' => 'organization', 'root_public_id' => 'org-a'],
            ['root_type' => $destinationRootType, 'root_public_id' => $destinationRootPublicId],
        ]);
        $pdo->commit();

        self::assertSame([1, $destinationProfileId], $result['ids']);
        $events = $pdo->query("SELECT integration_profile_id,payload_json FROM portal_projection_outbox WHERE route_type='service_assignments' AND delivery_kind='event' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);
        $actions = [];
        foreach ($events as $event) {
            $payload = json_decode((string)$event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $actions[(int)$event['integration_profile_id']][] = (string)$payload['event']['action'];
        }
        self::assertSame(['tombstone'], $actions[1] ?? []);
        self::assertSame(['upsert'], $actions[$destinationProfileId] ?? []);
    }

    /** @return iterable<string,array{string,string,int,int}> */
    public static function rootMoveProvider(): iterable
    {
        yield 'organization to standalone' => ['standalone_client', 'client-org-a', 0, 5];
        yield 'organization A to B' => ['organization', 'org-b', 2, 3];
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT);
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT,organization_id INTEGER,archived INTEGER DEFAULT 0,deleted_at TEXT);
            CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT,organization_id INTEGER);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT,status TEXT,organization_id INTEGER,client_id INTEGER);
            CREATE TABLE item_library(id INTEGER PRIMARY KEY,portal_public_id TEXT UNIQUE,portal_source_version TEXT,item_name TEXT,portal_summary TEXT,portal_category TEXT,portal_display_order INTEGER,portal_geometry_requirement TEXT,portal_questions_json TEXT,portal_requestable INTEGER,is_active INTEGER,entry_type TEXT);
            CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,enabled INTEGER,portal_projection_enabled INTEGER,catalog_projection_enabled INTEGER,service_assignment_projection_enabled INTEGER,portal_route TEXT,catalog_route TEXT,delivery_enabled INTEGER,delivery_key_id TEXT,delivery_timeout_seconds INTEGER DEFAULT 15,delivery_max_attempts INTEGER DEFAULT 12);
            CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,root_type TEXT,root_public_id TEXT,active INTEGER);
            CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,PRIMARY KEY(profile_id,workspace_id));
            CREATE TABLE portal_projection_state(integration_profile_id INTEGER,workspace_public_id TEXT,source_generation TEXT,source_sequence INTEGER,last_snapshot_hash TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id));
            CREATE TABLE portal_projection_resource_state(integration_profile_id INTEGER,workspace_public_id TEXT,route_type TEXT,resource_type TEXT,resource_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id,route_type,resource_type,resource_public_id));
            CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT UNIQUE,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT DEFAULT '2000-01-01 00:00:00.000000',claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT);
            CREATE TABLE portal_service_assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,subject_type TEXT,subject_public_id TEXT,service_public_id TEXT,active INTEGER,effective_from TEXT,effective_until TEXT,deleted_at TEXT,created_by INTEGER,updated_by INTEGER,created_at TEXT,updated_at TEXT);
            CREATE TABLE portal_service_assignment_projection_state(integration_profile_id INTEGER PRIMARY KEY,source_generation TEXT,source_sequence INTEGER,snapshot_hash TEXT);
            CREATE TABLE portal_service_assignment_projection_records(integration_profile_id INTEGER,assignment_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,assignment_public_id));
            CREATE TABLE portal_service_assignment_projection_receipts(integration_profile_id INTEGER,idempotency_hash TEXT,payload_hash TEXT,result_json TEXT,PRIMARY KEY(integration_profile_id,idempotency_hash));
            CREATE TRIGGER service_assignment_receipt_no_update BEFORE UPDATE ON portal_service_assignment_projection_receipts BEGIN SELECT RAISE(ABORT,'service-assignment-receipt-immutable'); END;
            CREATE TRIGGER service_assignment_receipt_no_delete BEFORE DELETE ON portal_service_assignment_projection_receipts BEGIN SELECT RAISE(ABORT,'service-assignment-receipt-immutable'); END;
            INSERT INTO organizations VALUES(1,'org-a','Organization A'),(2,'org-b','Organization B');
            INSERT INTO clients VALUES(11,'client-org-a','Organization client',1,0,NULL),(12,'client-standalone','Standalone client',NULL,0,NULL);
            INSERT INTO organization_departments VALUES(31,'department-a','Department A',1);
            INSERT INTO projects VALUES(21,'project-org-a','Organization project','active',1,11),(22,'project-standalone','Standalone project','active',NULL,12);
            INSERT INTO item_library VALUES
              (1,'service-map','1','Mapping','Map the site','Mapping',10,'optional','[]',1,1,'service'),
              (2,'service-photo','1','Photography','Photograph the site','Photo',20,'none','[]',1,1,'service'),
              (3,'service-inactive','1','Inactive','Inactive','Other',30,'none','[]',1,0,'service'),
              (4,'service-private','1','Private','Private','Other',40,'none','[]',0,1,'service'),
              (5,'catalog-product','1','Product','Product','Other',50,'none','[]',1,1,'product');
            INSERT INTO portal_v2_workspaces VALUES
              (1,'workspace-org-a','organization','org-a',1),
              (2,'workspace-standalone','standalone_client','client-standalone',1),
              (3,'workspace-org-b','organization','org-b',1);
            INSERT INTO portal_integration_profiles VALUES
              (1,'alpha_org',1,1,1,1,'https://ops.example/api/internal/project-alpha/sources/source-org/portal-v2','https://ops.example/api/internal/project-alpha/catalog-v2',1,'key-org',15,12),
              (2,'alpha_standalone',1,1,1,1,'https://ops.example/api/internal/project-alpha/sources/source-client/portal-v2','https://ops.example/api/internal/project-alpha/catalog-v2',1,'key-client',15,12),
              (3,'alpha_other',1,1,1,1,'https://ops.example/api/internal/project-alpha/sources/source-other/portal-v2','https://ops.example/api/internal/project-alpha/catalog-v2',1,'key-other',15,12),
              (4,'alpha_disabled',1,1,1,0,'https://ops.example/api/internal/project-alpha/sources/source-disabled/portal-v2','https://ops.example/api/internal/project-alpha/catalog-v2',1,'key-disabled',15,12);
            INSERT INTO portal_integration_profile_workspaces VALUES(1,1,1),(2,2,1),(3,3,1),(4,1,1);");
        return $pdo;
    }
}
