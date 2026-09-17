<?php

declare(strict_types=1);

use App\Services\PortalServiceAssignmentProjectionService;
use PHPUnit\Framework\TestCase;

final class PortalServiceAssignmentProjectionTest extends TestCase
{
    public function testPublicQueueMethodsRequireCallerOwnedTransaction(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $service = new PortalServiceAssignmentProjectionService();

        foreach ([
            static fn() => $service->queueSnapshot($pdo, ['id' => 1], 'outside-snapshot'),
            static fn() => $service->queueRevocationSnapshot($pdo, ['id' => 1], 'outside-revocation'),
            static fn() => $service->queueEvent($pdo, ['id' => 1], [
                'action' => 'tombstone',
                'assignmentPublicId' => 'assignment-outside',
                'sourceVersion' => 'version-1',
            ], 'outside-event'),
            static fn() => $service->queueChanges($pdo, ['id' => 1]),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected the projection operation to require a transaction.');
            } catch (DomainException $error) {
                self::assertSame('service-assignment-transaction-required', $error->getMessage());
            }
        }

        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_state')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_receipts')->fetchColumn());
    }

    public function testExactSnapshotContractUsesOrderedDigestAndBoundedPages(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        for ($index = 1; $index <= 101; $index++) {
            $this->insertAssignment($pdo, sprintf('assignment-%03d', $index));
        }
        $result = $this->transaction($pdo, fn() => (new PortalServiceAssignmentProjectionService())
            ->queueSnapshot($pdo, ['id' => 1], 'snapshot-a'));

        self::assertSame(2, $result['pageCount']);
        self::assertSame(101, $result['itemCount']);
        $rows = $pdo->query("SELECT payload_json FROM portal_projection_outbox WHERE integration_profile_id=1 ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(3, $rows);
        $pageOne = json_decode((string)$rows[0], true, 32, JSON_THROW_ON_ERROR);
        $pageTwo = json_decode((string)$rows[1], true, 32, JSON_THROW_ON_ERROR);
        $activation = json_decode((string)$rows[2], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([
            'schemaVersion','applicationKey','deliveryId','occurredAt','sourceGeneration','sourceSequence',
            'kind','snapshotHash','pageNumber','pageCount','itemCount','items',
        ], array_keys($pageOne));
        self::assertSame([
            'assignmentPublicId','sourceVersion','subjectType','subjectPublicId','servicePublicId',
            'serviceSourceVersion','active','effectiveFrom','effectiveUntil',
        ], array_keys($pageOne['items'][0]));
        self::assertSame('assignment-001', $pageOne['items'][0]['assignmentPublicId']);
        self::assertSame('assignment-101', $pageTwo['items'][0]['assignmentPublicId']);
        self::assertLessThanOrEqual(256 * 1024, strlen((string)$rows[0]));
        $pages = [];
        foreach ([$pageOne, $pageTwo] as $page) {
            $pages[] = [
                'pageNumber' => $page['pageNumber'],
                'itemCount' => count($page['items']),
                'pageHash' => hash('sha256', $this->json(['schemaVersion' => 1, 'items' => $page['items']])),
            ];
        }
        $expected = hash('sha256', $this->json([
            'schemaVersion' => 1, 'pageCount' => 2, 'itemCount' => 101, 'pages' => $pages,
        ]));
        self::assertSame($expected, $activation['snapshotHash']);
        self::assertSame($expected, $pageOne['snapshotHash']);
        self::assertSame(PortalServiceAssignmentProjectionService::PUBLISH_SCOPE, 'portal.service-assignments.publish');
    }

    public function testPublishedCompatibilityFixtureHasTheCanonicalPageAndRootDigests(): void
    {
        $fixture = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/project-alpha-service-assignments-v1.json'), true, 32, JSON_THROW_ON_ERROR);
        $page = $fixture['snapshotPage'];
        $pageHash = hash('sha256', $this->json(['schemaVersion' => 1, 'items' => $page['items']]));
        $rootHash = hash('sha256', $this->json([
            'schemaVersion' => 1,
            'pageCount' => 1,
            'itemCount' => 1,
            'pages' => [['pageNumber' => 1, 'itemCount' => 1, 'pageHash' => $pageHash]],
        ]));
        self::assertSame('portal.service-assignments.publish', $fixture['requiredCapability']);
        self::assertSame($fixture['pageHash'], $pageHash);
        self::assertSame($fixture['snapshotHash'], $rootHash);
        self::assertSame($rootHash, $page['snapshotHash']);
        self::assertSame($rootHash, $fixture['snapshotActivate']['snapshotHash']);
        self::assertSame($page['items'][0]['sourceVersion'], $fixture['tombstoneEvent']['event']['sourceVersion']);
    }

    public function testTwoProfilesCanPublishCollidingPublicIdsWithoutStateOrReceiptCollision(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'project-alpha%3Asource-a', true);
        $this->insertProfile($pdo, 2, 'alpha_b', 'project-alpha%3Asource-b', true);
        $this->insertAssignment($pdo, 'assignment-shared');
        $service = new PortalServiceAssignmentProjectionService();
        $a = $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'same-request'));
        $b = $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 2], 'same-request'));

        self::assertSame(1, $a['sourceSequence']);
        self::assertSame(1, $b['sourceSequence']);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_state')->fetchColumn());
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_receipts')->fetchColumn());
        self::assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM portal_service_assignment_projection_records WHERE assignment_public_id='assignment-shared'")->fetchColumn());
        $rows = $pdo->query("SELECT integration_profile_id,destination_url,payload_json FROM portal_projection_outbox WHERE delivery_kind='snapshot.page' ORDER BY integration_profile_id")
            ->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('https://ops.example/v1/project-alpha/events', $rows[0]['destination_url']);
        self::assertSame('https://ops.example/v1/project-alpha/events', $rows[1]['destination_url']);
        self::assertSame('alpha_a', json_decode((string)$rows[0]['payload_json'], true)['applicationKey']);
        self::assertSame('alpha_b', json_decode((string)$rows[1]['payload_json'], true)['applicationKey']);
    }

    public function testProfilesPublishOnlySubjectsInsideTheirActiveWorkspaceRoots(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO organizations VALUES(2,'org-b','Organization B');
            INSERT INTO clients VALUES(10,'client-a','Client A',1,0,NULL),(20,'client-b','Client B',2,0,NULL),(30,'client-standalone','Standalone',NULL,0,NULL);
            INSERT INTO organization_departments VALUES(10,'department-a','Department A',1),(20,'department-b','Department B',2);
            INSERT INTO projects VALUES(10,'project-a','Project A','active',1,10),(20,'project-b','Project B','active',2,20),(30,'project-standalone','Standalone Project','active',NULL,30);
            INSERT INTO portal_v2_workspaces VALUES(2,'workspace-b','organization','org-b',1),(3,'workspace-standalone','standalone_client','client-standalone',1);");
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertProfile($pdo, 2, 'alpha_b', 'source-b', true);
        $this->insertProfile($pdo, 3, 'alpha_c', 'source-c', true);
        $pdo->exec("DELETE FROM portal_integration_profile_workspaces WHERE profile_id IN(2,3);
            INSERT INTO portal_integration_profile_workspaces VALUES(2,2,1),(3,3,1);");
        foreach ([
            ['org-a','organization','org-a'], ['department-a','department','department-a'],
            ['client-a','client','client-a'], ['project-a','project','project-a'],
            ['org-b','organization','org-b'], ['department-b','department','department-b'],
            ['client-b','client','client-b'], ['project-b','project','project-b'],
            ['standalone-root','standalone_client','client-standalone'],
            ['standalone-client-alias','client','client-standalone'],
            ['standalone-project','project','project-standalone'],
        ] as [$publicId, $subjectType, $subjectPublicId]) {
            $this->insertAssignmentForSubject($pdo, 'assignment-' . $publicId, $subjectType, $subjectPublicId);
        }
        $service = new PortalServiceAssignmentProjectionService();
        foreach ([1 => ['assignment-client-a','assignment-department-a','assignment-org-a','assignment-project-a'],
                     2 => ['assignment-client-b','assignment-department-b','assignment-org-b','assignment-project-b'],
                     3 => ['assignment-standalone-client-alias','assignment-standalone-project','assignment-standalone-root']] as $profileId => $expected) {
            $result = $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => $profileId], 'scoped-' . $profileId));
            self::assertSame(count($expected), $result['itemCount']);
            $statement = $pdo->prepare("SELECT payload_json FROM portal_projection_outbox WHERE integration_profile_id=? AND delivery_kind='snapshot.page'");
            $statement->execute([$profileId]);
            $payload = json_decode((string)$statement->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame($expected, array_column($payload['items'], 'assignmentPublicId'));
        }
    }

    public function testDirectEventsCannotEscapeTheProfileWorkspaceOrInventATombstone(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO organizations VALUES(2,'org-b','Organization B');
            INSERT INTO portal_v2_workspaces VALUES(2,'workspace-b','organization','org-b',1);");
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertProfile($pdo, 2, 'alpha_b', 'source-b', true);
        $pdo->exec("DELETE FROM portal_integration_profile_workspaces WHERE profile_id=2;
            INSERT INTO portal_integration_profile_workspaces VALUES(2,2,1);");
        $this->insertAssignmentForSubject($pdo, 'assignment-b', 'organization', 'org-b');
        $service = new PortalServiceAssignmentProjectionService();
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'profile-a'));
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 2], 'profile-b'));
        $item = json_decode((string)$pdo->query("SELECT record_json FROM portal_service_assignment_projection_records WHERE integration_profile_id=2 AND assignment_public_id='assignment-b'")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);

        foreach ([
            ['action' => 'upsert', 'item' => $item],
            ['action' => 'tombstone', 'assignmentPublicId' => 'assignment-never-published', 'sourceVersion' => str_repeat('a', 64)],
        ] as $index => $event) {
            try {
                $this->transaction($pdo, fn() => $service->queueEvent($pdo, ['id' => 1], $event, 'outside-' . $index));
                self::fail('Expected the profile workspace boundary to reject the event.');
            } catch (DomainException $error) {
                self::assertSame('service-assignment-subject-outside-profile', $error->getMessage());
            }
        }
    }

    public function testExactEventReplayRemainsImmutableAfterWorkspaceRemoval(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertAssignment($pdo, 'assignment-replay-after-unlink');
        $service = new PortalServiceAssignmentProjectionService();
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'snapshot-before-unlink'));
        $item = json_decode((string)$pdo->query("SELECT record_json FROM portal_service_assignment_projection_records WHERE integration_profile_id=1 AND assignment_public_id='assignment-replay-after-unlink'")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        $first = $this->transaction($pdo, fn() => $service->queueEvent($pdo, ['id' => 1], ['action' => 'upsert', 'item' => $item], 'event-before-unlink'));
        $pdo->exec('UPDATE portal_integration_profile_workspaces SET active=0 WHERE profile_id=1');

        $replay = $this->transaction($pdo, fn() => $service->queueEvent($pdo, ['id' => 1], ['action' => 'upsert', 'item' => $item], 'event-before-unlink'));

        self::assertSame('replayed', $replay['status']);
        self::assertSame($first['deliveryId'], $replay['deliveryId']);
        self::assertSame(3, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
    }

    public function testMissingActiveWorkspaceRootFailsClosed(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $pdo->exec("INSERT INTO portal_v2_workspaces VALUES(2,'workspace-missing','organization','org-missing',1);
            DELETE FROM portal_integration_profile_workspaces WHERE profile_id=1;
            INSERT INTO portal_integration_profile_workspaces VALUES(1,2,1);");
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('portal-workspace-root-missing');
        $this->transaction($pdo, fn() => (new PortalServiceAssignmentProjectionService())
            ->queueSnapshot($pdo, ['id' => 1], 'missing-root'));
    }

    public function testMalformedOutOfScopeAssignmentCannotBlockAnotherProfile(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertAssignment($pdo, 'assignment-valid');
        $pdo->exec("INSERT INTO portal_service_assignments(public_id,subject_type,subject_public_id,service_public_id,active,deleted_at) VALUES('', 'unknown', '', '', 1, NULL)");

        $result = $this->transaction($pdo, fn() => (new PortalServiceAssignmentProjectionService())
            ->queueSnapshot($pdo, ['id' => 1], 'ignore-outside-corruption'));

        self::assertSame(1, $result['itemCount']);
        $payload = json_decode((string)$pdo->query("SELECT payload_json FROM portal_projection_outbox WHERE delivery_kind='snapshot.page'")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['assignment-valid'], array_column($payload['items'], 'assignmentPublicId'));
    }

    public function testSnapshotAndEventReplayAreImmutableAndChangedBodiesConflict(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertAssignment($pdo, 'assignment-replay');
        $service = new PortalServiceAssignmentProjectionService();
        $first = $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'snapshot-replay'));
        $replay = $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'snapshot-replay'));
        self::assertSame('replayed', $replay['status']);
        self::assertSame($first['deliveryIds'], $replay['deliveryIds']);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());

        $pdo->exec("UPDATE portal_service_assignments SET active=0 WHERE public_id='assignment-replay'");
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('service-assignment-idempotency-conflict');
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'snapshot-replay'));
    }

    public function testTombstoneUsesLastPublishedVersionAndAdvancesOnlyItsProfile(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertProfile($pdo, 2, 'alpha_b', 'source-b', true);
        $this->insertAssignment($pdo, 'assignment-delete');
        $service = new PortalServiceAssignmentProjectionService();
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'snapshot-a'));
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 2], 'snapshot-b'));
        $version = (string)$pdo->query("SELECT source_version FROM portal_service_assignment_projection_records WHERE integration_profile_id=1 AND assignment_public_id='assignment-delete'")->fetchColumn();
        $pdo->exec("UPDATE portal_service_assignments SET deleted_at='2026-08-27 12:00:00.000000' WHERE public_id='assignment-delete'");
        $changes = $this->transaction($pdo, fn() => $service->queueChanges($pdo, ['id' => 1]));

        self::assertCount(1, $changes['events']);
        self::assertSame('tombstone', $changes['events'][0]['event']['action']);
        self::assertSame($version, $changes['events'][0]['event']['sourceVersion']);
        self::assertSame(2, $changes['events'][0]['sourceSequence']);
        self::assertSame(1, (int)$pdo->query('SELECT source_sequence FROM portal_service_assignment_projection_state WHERE integration_profile_id=2')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_records WHERE integration_profile_id=1')->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_records WHERE integration_profile_id=2')->fetchColumn());
        $wire = json_decode((string)$pdo->query("SELECT payload_json FROM portal_projection_outbox WHERE integration_profile_id=1 AND delivery_kind='event'")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['schemaVersion','applicationKey','deliveryId','occurredAt','sourceGeneration','sourceSequence','kind','event'], array_keys($wire));
        self::assertSame(['action','assignmentPublicId','sourceVersion'], array_keys($wire['event']));
    }

    public function testChangeReturningToPriorValueGetsANewSequenceQualifiedReceipt(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertAssignment($pdo, 'assignment-toggle');
        $service = new PortalServiceAssignmentProjectionService();
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'snapshot-toggle'));

        $pdo->exec("UPDATE portal_service_assignments SET active=0 WHERE public_id='assignment-toggle'");
        $disabled = $this->transaction($pdo, fn() => $service->queueChanges($pdo, ['id' => 1]));
        $pdo->exec("UPDATE portal_service_assignments SET active=1 WHERE public_id='assignment-toggle'");
        $restored = $this->transaction($pdo, fn() => $service->queueChanges($pdo, ['id' => 1]));

        self::assertSame(2, $disabled['events'][0]['sourceSequence']);
        self::assertSame(3, $restored['events'][0]['sourceSequence']);
        self::assertFalse($disabled['events'][0]['event']['item']['active']);
        self::assertTrue($restored['events'][0]['event']['item']['active']);
        self::assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE delivery_kind='event'")->fetchColumn());
        self::assertSame(3, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_receipts')->fetchColumn());
    }

    public function testEventReplayReturnsOriginalDeliveryAndChangedIntentConflicts(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertAssignment($pdo, 'assignment-event');
        $service = new PortalServiceAssignmentProjectionService();
        $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => 1], 'snapshot-event'));
        $item = json_decode((string)$pdo->query("SELECT record_json FROM portal_service_assignment_projection_records WHERE integration_profile_id=1 AND assignment_public_id='assignment-event'")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        $first = $this->transaction($pdo, fn() => $service->queueEvent($pdo, ['id' => 1], ['action' => 'upsert', 'item' => $item], 'event-replay'));
        $replay = $this->transaction($pdo, fn() => $service->queueEvent($pdo, ['id' => 1], ['action' => 'upsert', 'item' => $item], 'event-replay'));
        self::assertSame('replayed', $replay['status']);
        self::assertSame($first['deliveryId'], $replay['deliveryId']);
        self::assertSame(3, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
        $item['active'] = false;
        try {
            $this->transaction($pdo, fn() => $service->queueEvent($pdo, ['id' => 1], ['action' => 'upsert', 'item' => $item], 'event-replay'));
            self::fail('Expected a changed event replay to conflict.');
        } catch (DomainException $error) {
            self::assertSame('service-assignment-idempotency-conflict', $error->getMessage());
        }
    }

    public function testDisabledAndUnconfiguredProfilesFailBeforeWriting(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', false);
        $this->insertProfile($pdo, 2, 'alpha_b', 'source-b', true, null);
        $this->insertAssignment($pdo, 'assignment-disabled');
        $service = new PortalServiceAssignmentProjectionService();
        foreach ([[1, 'service-assignment-profile-disabled'], [2, 'service-assignment-route-invalid']] as [$profileId, $message]) {
            try {
                $this->transaction($pdo, fn() => $service->queueSnapshot($pdo, ['id' => $profileId], 'disabled-' . $profileId));
                self::fail('Expected fail-closed configuration error.');
            } catch (DomainException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_receipts')->fetchColumn());
    }

    public function testSnapshotItemLimitFailsBeforeAnyOutboxOrReceiptWrite(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $pdo->beginTransaction();
        for ($index = 1; $index <= 5001; $index++) $this->insertAssignment($pdo, sprintf('assignment-limit-%04d', $index));
        $pdo->commit();
        try {
            $this->transaction($pdo, fn() => (new PortalServiceAssignmentProjectionService())
                ->queueSnapshot($pdo, ['id' => 1], 'oversized-snapshot'));
            self::fail('Expected the bounded item limit.');
        } catch (DomainException $error) {
            self::assertSame('service-assignment-item-limit', $error->getMessage());
        }
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_service_assignment_projection_receipts')->fetchColumn());
    }

    public function testStoredProducerReceiptsAreImmutable(): void
    {
        $pdo = $this->database();
        $this->insertProfile($pdo, 1, 'alpha_a', 'source-a', true);
        $this->insertAssignment($pdo, 'assignment-receipt');
        $this->transaction($pdo, fn() => (new PortalServiceAssignmentProjectionService())
            ->queueSnapshot($pdo, ['id' => 1], 'immutable-receipt'));
        try {
            $pdo->exec("UPDATE portal_service_assignment_projection_receipts SET payload_hash='changed'");
            self::fail('Expected immutable receipt update to fail.');
        } catch (PDOException $error) {
            self::assertStringContainsString('service-assignment-receipt-immutable', $error->getMessage());
        }
        try {
            $pdo->exec('DELETE FROM portal_service_assignment_projection_receipts');
            self::fail('Expected immutable receipt delete to fail.');
        } catch (PDOException $error) {
            self::assertStringContainsString('service-assignment-receipt-immutable', $error->getMessage());
        }
    }

    public function testMigrationAndSenderKeepProducerDefaultOffOnTheExistingConnection(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents($root . '/database/migrations/0080_portal_service_assignment_projection.sql');
        $sender = (string)file_get_contents($root . '/src/services/PortalProjectionOutboxSender.php');
        self::assertStringContainsString('service_assignment_projection_enabled TINYINT(1) NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString("ENUM('portal','catalog','service_assignments')", $migration);
        self::assertStringContainsString('portal_service_assignment_projection_receipts', $migration);
        self::assertStringContainsString('service-assignment-receipt-immutable', $migration);
        self::assertStringContainsString("o.route_type='service_assignments' AND p.service_assignment_projection_enabled=1", $sender);
        self::assertStringContainsString("'service_assignments'=>!empty(\$profile['service_assignment_projection_enabled'])", $sender);
        self::assertStringNotContainsString('service_assignment_route', $migration, 'The existing portal route is the sole connection authority.');
    }

    public function testSenderAllowsOnlyTheExactEnabledServiceAssignmentRouteType(): void
    {
        $method = new ReflectionMethod(App\Services\PortalProjectionOutboxSender::class, 'profileAllows');
        $sender = new App\Services\PortalProjectionOutboxSender();
        $profile = ['enabled' => 1, 'delivery_enabled' => 1, 'service_assignment_projection_enabled' => 0];

        self::assertFalse($method->invoke($sender, $profile, ['route_type' => 'service_assignments', 'is_revocation' => 0]));
        $profile['service_assignment_projection_enabled'] = 1;
        self::assertTrue($method->invoke($sender, $profile, ['route_type' => 'service_assignments', 'is_revocation' => 0]));
        self::assertFalse($method->invoke($sender, $profile, ['route_type' => 'unknown', 'is_revocation' => 0]));
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
            CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT UNIQUE,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT DEFAULT '2000-01-01 00:00:00.000000',claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT);
            CREATE TABLE portal_service_assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,subject_type TEXT,subject_public_id TEXT,service_public_id TEXT,active INTEGER,effective_from TEXT,effective_until TEXT,deleted_at TEXT);
            CREATE TABLE portal_service_assignment_projection_state(integration_profile_id INTEGER PRIMARY KEY,source_generation TEXT,source_sequence INTEGER,snapshot_hash TEXT);
            CREATE TABLE portal_service_assignment_projection_records(integration_profile_id INTEGER,assignment_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,assignment_public_id));
            CREATE TABLE portal_service_assignment_projection_receipts(integration_profile_id INTEGER,idempotency_hash TEXT,payload_hash TEXT,result_json TEXT,PRIMARY KEY(integration_profile_id,idempotency_hash));
            CREATE TRIGGER service_assignment_receipt_no_update BEFORE UPDATE ON portal_service_assignment_projection_receipts BEGIN SELECT RAISE(ABORT,'service-assignment-receipt-immutable'); END;
            CREATE TRIGGER service_assignment_receipt_no_delete BEFORE DELETE ON portal_service_assignment_projection_receipts BEGIN SELECT RAISE(ABORT,'service-assignment-receipt-immutable'); END;
            INSERT INTO organizations VALUES(1,'org-a','Organization A');
            INSERT INTO portal_v2_workspaces VALUES(1,'workspace-a','organization','org-a',1);
            INSERT INTO item_library VALUES(1,'service-map','1','Mapping','Map the site','Mapping',10,'optional','[]',1,1,'service');");
        return $pdo;
    }

    private function insertProfile(PDO $pdo, int $id, string $key, string $source, bool $assignmentEnabled, ?string $route = 'default'): void
    {
        // Every projection kind shares the one configured External Operations
        // webhook. The source identifier scopes data, not the transport URL.
        $portalRoute = $route === null ? 'not-a-valid-url' : 'https://ops.example/v1/project-alpha/events';
        $statement = $pdo->prepare('INSERT INTO portal_integration_profiles VALUES(?,?,?,?,?,?,?,?,?,?,15,12)');
        $statement->execute([$id, $key, 1, 1, 1, $assignmentEnabled ? 1 : 0, $portalRoute,
            'https://ops.example/api/internal/project-alpha/catalog-v2', 1, 'key-current']);
        $pdo->prepare('INSERT INTO portal_integration_profile_workspaces VALUES(?,1,1)')->execute([$id]);
    }

    private function insertAssignment(PDO $pdo, string $publicId): void
    {
        $this->insertAssignmentForSubject($pdo, $publicId, 'organization', 'org-a');
    }

    private function insertAssignmentForSubject(PDO $pdo, string $publicId, string $subjectType, string $subjectPublicId): void
    {
        $statement = $pdo->prepare('INSERT INTO portal_service_assignments(public_id,subject_type,subject_public_id,service_public_id,active,effective_from,effective_until,deleted_at) VALUES(?,?,?,?,1,NULL,NULL,NULL)');
        $statement->execute([$publicId, $subjectType, $subjectPublicId, 'service-map']);
    }

    private function transaction(PDO $pdo, callable $callback): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
