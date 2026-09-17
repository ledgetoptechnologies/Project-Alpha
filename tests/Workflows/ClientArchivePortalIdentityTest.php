<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ClientArchivePortalStateService;
use App\Services\PortalClientProvisioningService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ClientArchivePortalIdentityTest extends TestCase
{
    private PDO $pdo;
    private ClientArchivePortalStateService $archive;
    private PortalClientProvisioningService $provisioning;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT);
CREATE TABLE clients(
  id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE DEFAULT(lower(hex(randomblob(16)))),name TEXT,email TEXT,phone TEXT,
  organization_id INTEGER,client_type TEXT DEFAULT 'unknown',notes TEXT,address_line1 TEXT,address_line2 TEXT,
  city TEXT,state TEXT,postal_code TEXT,country TEXT,source_version TEXT,created_at TEXT,
  archived INTEGER DEFAULT 0,deleted_at TEXT
);
CREATE TABLE portal_principals(
  id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT(lower(hex(randomblob(16)))),
  email_hint TEXT,display_name TEXT,source_version TEXT,enabled INTEGER,
  authorization_version INTEGER DEFAULT 1,activated_at TEXT,revoked_at TEXT,
  created_by INTEGER,updated_by INTEGER
);
CREATE TABLE archived_clients(
  id INTEGER PRIMARY KEY AUTOINCREMENT,client_id INTEGER,public_id TEXT UNIQUE,name TEXT,email TEXT,
  phone TEXT,organization_id INTEGER,client_type TEXT,notes TEXT,address_line1 TEXT,address_line2 TEXT,
  city TEXT,state TEXT,postal_code TEXT,country TEXT,created_at TEXT,portal_principal_id INTEGER,
  portal_manual_state TEXT,portal_canonical_email TEXT,
  portal_identity_binding_ids_json TEXT,
  portal_principal_authorization_version INTEGER,
  portal_principal_disabled_for_archive INTEGER,
  portal_principal_was_present INTEGER DEFAULT 0,
  portal_entitlement_ids_json TEXT,
  portal_affected_workspace_ids_json TEXT,
  FOREIGN KEY(portal_principal_id) REFERENCES portal_principals(id) ON DELETE SET NULL
);
CREATE TABLE portal_client_login_eligibility(
  client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,manual_state TEXT,
  eligibility_status TEXT,review_reason TEXT,canonical_email TEXT,source_version TEXT,
  last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER,
  FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE
);
CREATE TABLE portal_principal_clients(
  portal_principal_id INTEGER,client_id INTEGER,created_by INTEGER,
  PRIMARY KEY(portal_principal_id,client_id),
  FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE
);
CREATE TABLE portal_identity_bindings(
  id INTEGER PRIMARY KEY AUTOINCREMENT,portal_principal_id INTEGER,issuer TEXT,subject_hash TEXT,
  enabled INTEGER,bound_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER
);
CREATE TABLE portal_v2_entitlements(
  id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,portal_principal_id INTEGER,capability TEXT,
  effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,
  valid_from TEXT,expires_at TEXT,created_by INTEGER,updated_by INTEGER
);
CREATE TABLE portal_client_access_roots(
  root_type TEXT,root_public_id TEXT,access_state TEXT,state_reason TEXT,last_reconciled_at TEXT,
  created_by INTEGER,updated_by INTEGER,PRIMARY KEY(root_type,root_public_id)
);
CREATE TABLE portal_v2_workspaces(
  id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,root_type TEXT,root_public_id TEXT,
  display_name TEXT,source_version TEXT,active INTEGER,created_by INTEGER,updated_by INTEGER,
  UNIQUE(root_type,root_public_id)
);
CREATE TABLE portal_integration_profile_workspaces(
  profile_id INTEGER,workspace_id INTEGER,active INTEGER,created_by INTEGER,updated_by INTEGER,
  PRIMARY KEY(profile_id,workspace_id)
);
CREATE TABLE portal_projection_resource_state(
  workspace_public_id TEXT,resource_type TEXT,resource_public_id TEXT
);
SQL);
        $this->archive = new ClientArchivePortalStateService();
        $this->provisioning = new PortalClientProvisioningService();
    }

    public function testStandaloneConsumerRestoresTheSameSourceWorkspaceAndPrincipalIdentity(): void
    {
        $publicId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $this->insertClient(20, $publicId, 'Solo Client', 'solo@example.test', null, 'consumer');
        $scope = [['root_type'=>'standalone_client','root_public_id'=>$publicId]];
        $this->reconcile($scope);
        $principalId = (int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id=20')->fetchColumn();
        $initialAuthorizationVersion = (int)$this->pdo->query("SELECT authorization_version FROM portal_principals WHERE id={$principalId}")->fetchColumn();
        $workspaceId = (string)$this->pdo->query('SELECT public_id FROM portal_v2_workspaces')->fetchColumn();
        $this->pdo->exec("INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at) VALUES({$principalId},'https://idp.example.test','subject-hash',1,CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at,revoked_at) VALUES({$principalId},'https://old-idp.example.test','old-subject-hash',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO portal_v2_entitlements(public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active) VALUES('custom-entitlement',{$principalId},'billing.admin','allow','client','{$publicId}','custom-v1',1)");
        $this->pdo->exec("INSERT INTO portal_v2_entitlements(public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active) VALUES('custom-deny',{$principalId},'delivery.view','deny','client','{$publicId}','custom-deny-v1',1)");
        $principalPublicId = (string)$this->pdo->query("SELECT public_id FROM portal_principals WHERE id={$principalId}")->fetchColumn();
        $this->pdo->prepare("INSERT INTO portal_projection_resource_state VALUES(?,'principal',?)")->execute([$workspaceId, $principalPublicId]);

        $affected = $this->archiveClient(20);

        $archived = $this->pdo->query('SELECT * FROM archived_clients')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($publicId, $archived['public_id']);
        self::assertSame('consumer', $archived['client_type']);
        self::assertSame($principalId, (int)$archived['portal_principal_id']);
        self::assertSame('automatic', $archived['portal_manual_state']);
        self::assertSame(0, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND active=1")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT enabled FROM portal_identity_bindings WHERE portal_principal_id={$principalId}")->fetchColumn());
        self::assertSame('[1]', $archived['portal_identity_binding_ids_json']);
        self::assertSame([$workspaceId], $affected);
        self::assertSame(json_encode([$workspaceId], JSON_THROW_ON_ERROR), $archived['portal_affected_workspace_ids_json']);
        self::assertSame(1, (int)$archived['portal_principal_disabled_for_archive']);
        self::assertSame($initialAuthorizationVersion + 1, (int)$archived['portal_principal_authorization_version']);

        $restoredId = $this->restoreClient((int)$archived['id'], $scope);

        $client = $this->pdo->query("SELECT public_id,client_type FROM clients WHERE id={$restoredId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame($publicId, $client['public_id']);
        self::assertSame('consumer', $client['client_type']);
        $eligibility = $this->pdo->query("SELECT * FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame($principalId, (int)$eligibility['portal_principal_id']);
        self::assertSame('automatic', $eligibility['manual_state']);
        self::assertSame('eligible', $eligibility['eligibility_status']);
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn());
        self::assertSame(5, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND active=1")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_identity_bindings WHERE id=1")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT enabled FROM portal_identity_bindings WHERE id=2")->fetchColumn(), 'A binding revoked before archive is never broadened back into access.');
        self::assertSame(1, (int)$this->pdo->query("SELECT active FROM portal_v2_entitlements WHERE capability='billing.admin'")->fetchColumn(), 'A previously granted scoped capability is restored without affecting another client.');
        self::assertSame(1, (int)$this->pdo->query("SELECT active FROM portal_v2_entitlements WHERE public_id='custom-deny' AND effect='deny'")->fetchColumn(), 'A pre-existing deny remains effective; restore does not broaden access.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_principals WHERE id={$principalId}")->fetchColumn(), 'Restore reuses the principal rather than creating an orphan-conflict replacement.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_principal_clients WHERE portal_principal_id={$principalId} AND client_id={$restoredId}")->fetchColumn());
        self::assertSame($workspaceId, (string)$this->pdo->query('SELECT public_id FROM portal_v2_workspaces')->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query('SELECT active FROM portal_v2_workspaces')->fetchColumn());
    }

    public function testOrganizationContactRestoreKeepsExplicitLoginRevocation(): void
    {
        $this->pdo->exec("INSERT INTO organizations VALUES(10,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Example Org')");
        $publicId = 'cccccccccccccccccccccccccccccccc';
        $this->insertClient(30, $publicId, 'Organization Contact', 'contact@example.test', 10, 'business');
        $scope = [['root_type'=>'organization','root_public_id'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']];
        $this->reconcile($scope);
        $principalId = (int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id=30')->fetchColumn();
        $this->pdo->exec("INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at) VALUES({$principalId},'https://idp.example.test','org-subject-hash',1,CURRENT_TIMESTAMP)");
        $this->pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='revoked' WHERE client_id=30");
        $this->reconcile($scope);
        self::assertSame('revoked', (string)$this->pdo->query('SELECT eligibility_status FROM portal_client_login_eligibility WHERE client_id=30')->fetchColumn());

        $this->archiveClient(30);
        $archived = $this->pdo->query('SELECT * FROM archived_clients')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('revoked', $archived['portal_manual_state']);
        self::assertSame('[]', $archived['portal_identity_binding_ids_json']);

        $restoredId = $this->restoreClient((int)$archived['id'], $scope);

        $eligibility = $this->pdo->query("SELECT * FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame($publicId, (string)$this->pdo->query("SELECT public_id FROM clients WHERE id={$restoredId}")->fetchColumn());
        self::assertSame('business', (string)$this->pdo->query("SELECT client_type FROM clients WHERE id={$restoredId}")->fetchColumn());
        self::assertSame($principalId, (int)$eligibility['portal_principal_id']);
        self::assertSame('revoked', $eligibility['manual_state']);
        self::assertSame('revoked', $eligibility['eligibility_status']);
        self::assertSame(0, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND active=1")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT enabled FROM portal_identity_bindings WHERE portal_principal_id={$principalId}")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query('SELECT active FROM portal_v2_workspaces')->fetchColumn(), 'The organization workspace remains active for the organization.');
    }

    public function testSharedPrincipalArchiveOnlyRemovesTheTargetClientAccess(): void
    {
        $first = '11111111111111111111111111111111';
        $second = '22222222222222222222222222222222';
        $this->insertClient(50, $first, 'First Person', 'first@example.test', null, 'consumer');
        $this->insertClient(51, $second, 'Second Person', 'second@example.test', null, 'consumer');
        $scopes = [
            ['root_type'=>'standalone_client','root_public_id'=>$first],
            ['root_type'=>'standalone_client','root_public_id'=>$second],
        ];
        $this->reconcile($scopes);
        $principalId = $this->sharePrincipal(50, 51, $second);
        $this->pdo->exec("INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at) VALUES({$principalId},'https://idp.example.test','shared-subject',1,CURRENT_TIMESTAMP)");

        self::assertSame([], $this->archiveClient(50));
        $archived = $this->pdo->query('SELECT * FROM archived_clients')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int)$archived['portal_principal_disabled_for_archive']);
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_identity_bindings WHERE portal_principal_id={$principalId}")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_principal_clients WHERE portal_principal_id={$principalId} AND client_id=50")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_principal_clients WHERE portal_principal_id={$principalId} AND client_id=51")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$first}' AND active=1")->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$second}' AND active=1")->fetchColumn());

        $restoredId = $this->restoreClient((int)$archived['id'], [['root_type'=>'standalone_client','root_public_id'=>$first]]);
        self::assertSame($principalId, (int)$this->pdo->query("SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$first}' AND active=1")->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$second}' AND active=1")->fetchColumn());
    }

    public function testSecurityChangeDuringArchiveFencesRestoreWithoutRevokingSharedClient(): void
    {
        $first = '33333333333333333333333333333333';
        $second = '44444444444444444444444444444444';
        $this->insertClient(60, $first, 'Archived Person', 'archived@example.test', null, 'consumer');
        $this->insertClient(61, $second, 'Remaining Person', 'remaining@example.test', null, 'consumer');
        $this->reconcile([
            ['root_type'=>'standalone_client','root_public_id'=>$first],
            ['root_type'=>'standalone_client','root_public_id'=>$second],
        ]);
        $principalId = $this->sharePrincipal(60, 61, $second);
        $this->pdo->exec("INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at) VALUES({$principalId},'https://idp.example.test','shared-fenced-subject',1,CURRENT_TIMESTAMP)");
        $this->archiveClient(60);
        $archiveId = (int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn();

        // Simulate a security-sensitive principal update made after archival.
        $this->pdo->exec("UPDATE portal_principals SET authorization_version=authorization_version+1 WHERE id={$principalId}");
        $restoredId = $this->restoreClient($archiveId, [['root_type'=>'standalone_client','root_public_id'=>$first]]);

        $eligibility = $this->pdo->query("SELECT manual_state,eligibility_status FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('revoked', $eligibility['manual_state']);
        self::assertSame('revoked', $eligibility['eligibility_status']);
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn(), 'The other active client keeps its shared principal.');
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_identity_bindings WHERE portal_principal_id={$principalId}")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$first}' AND active=1")->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$second}' AND active=1")->fetchColumn());
    }

    public function testSameOrganizationReconciliationDoesNotInvalidateSharedPrincipalFence(): void
    {
        $organizationPublicId = '66666666666666666666666666666666';
        $first = '77777777777777777777777777777777';
        $second = '88888888888888888888888888888888';
        $this->pdo->prepare('INSERT INTO organizations VALUES(?,?,?)')->execute([90,$organizationPublicId,'Shared Org']);
        $this->insertClient(80, $first, 'First Contact', 'first-contact@example.test', 90, 'business');
        $this->insertClient(81, $second, 'Second Contact', 'second-contact@example.test', 90, 'business');
        $scope = [['root_type'=>'organization','root_public_id'=>$organizationPublicId]];
        $this->reconcile($scope);
        $principalId = $this->sharePrincipal(80, 81, $second);
        $versionBefore = (int)$this->pdo->query("SELECT authorization_version FROM portal_principals WHERE id={$principalId}")->fetchColumn();

        $this->archiveClient(80, $scope);
        $archive = $this->pdo->query('SELECT id,portal_principal_authorization_version FROM archived_clients')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($versionBefore, (int)$archive['portal_principal_authorization_version'], 'Routine sibling reconciliation must not look like a security change.');
        self::assertSame($versionBefore, (int)$this->pdo->query("SELECT authorization_version FROM portal_principals WHERE id={$principalId}")->fetchColumn());

        $restoredId = $this->restoreClient((int)$archive['id'], $scope);
        self::assertSame('automatic', (string)$this->pdo->query("SELECT manual_state FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetchColumn());
        self::assertSame('eligible', (string)$this->pdo->query("SELECT eligibility_status FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn());
    }

    public function testEntitlementSecurityChangeDuringArchiveFencesRestore(): void
    {
        $publicId = '99999999999999999999999999999999';
        $this->insertClient(82, $publicId, 'Entitlement Fence Client', 'entitlement-fence@example.test', null, 'consumer');
        $scope = [['root_type'=>'standalone_client','root_public_id'=>$publicId]];
        $this->reconcile($scope);
        $principalId = (int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id=82')->fetchColumn();
        $this->pdo->exec("INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at) VALUES({$principalId},'https://idp.example.test','entitlement-fence-subject',1,CURRENT_TIMESTAMP)");
        $this->archiveClient(82);
        $archiveId = (int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn();

        // Simulate an administrator changing one archived grant before restore.
        $this->pdo->exec("UPDATE portal_v2_entitlements SET source_version='security-change' WHERE portal_principal_id={$principalId} AND scope_public_id='{$publicId}' AND id=(SELECT id FROM (SELECT id FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$publicId}' ORDER BY id LIMIT 1) changed)");
        $restoredId = $this->restoreClient($archiveId, $scope);

        $eligibility = $this->pdo->query("SELECT manual_state,eligibility_status FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('revoked', $eligibility['manual_state']);
        self::assertSame('revoked', $eligibility['eligibility_status']);
        self::assertSame(0, (int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principalId}")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id={$principalId} AND scope_public_id='{$publicId}' AND active=1")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT enabled FROM portal_identity_bindings WHERE portal_principal_id={$principalId}")->fetchColumn());
    }

    public function testPrincipalDeletedDuringArchiveCannotBeSilentlyRecreated(): void
    {
        $publicId = '55555555555555555555555555555555';
        $this->insertClient(70, $publicId, 'Deleted Principal Client', 'deleted-principal@example.test', null, 'consumer');
        $scope = [['root_type'=>'standalone_client','root_public_id'=>$publicId]];
        $this->reconcile($scope);
        $principalId = (int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id=70')->fetchColumn();
        $this->archiveClient(70);
        $archiveId = (int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn();
        self::assertSame(1, (int)$this->pdo->query('SELECT portal_principal_was_present FROM archived_clients')->fetchColumn());

        $this->pdo->exec("DELETE FROM portal_identity_bindings WHERE portal_principal_id={$principalId}; DELETE FROM portal_v2_entitlements WHERE portal_principal_id={$principalId}; DELETE FROM portal_principal_clients WHERE portal_principal_id={$principalId}; DELETE FROM portal_principals WHERE id={$principalId}");
        $archivedPrincipal = $this->pdo->query('SELECT portal_principal_id FROM archived_clients')->fetch(PDO::FETCH_ASSOC);
        self::assertNull($archivedPrincipal['portal_principal_id']);
        $restoredId = $this->restoreClient($archiveId, $scope);

        $eligibility = $this->pdo->query("SELECT portal_principal_id,manual_state,eligibility_status FROM portal_client_login_eligibility WHERE client_id={$restoredId}")->fetch(PDO::FETCH_ASSOC);
        self::assertNull($eligibility['portal_principal_id']);
        self::assertSame('revoked', $eligibility['manual_state']);
        self::assertSame('revoked', $eligibility['eligibility_status']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
    }

    public function testLegacyArchiveRestoresTheRecordButDoesNotInferPortalAccess(): void
    {
        $this->pdo->exec("INSERT INTO archived_clients(client_id,name,email,country,created_at) VALUES(40,'Legacy Client','legacy@example.test','US','2025-01-01 00:00:00')");
        $this->pdo->beginTransaction();
        $restored = $this->archive->consumeAndRestore($this->pdo, 1, 7);
        $this->pdo->commit();
        $clientId = (int)$restored['client_id'];
        $client = $this->pdo->query("SELECT public_id,client_type FROM clients WHERE id={$clientId}")->fetch(PDO::FETCH_ASSOC);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string)$client['public_id']);
        self::assertSame('unknown', $client['client_type']);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_client_login_eligibility WHERE client_id={$clientId}")->fetchColumn());

        $scope = [['root_type'=>'standalone_client','root_public_id'=>(string)$client['public_id']]];
        $this->reconcile($scope);
        $eligibility = $this->pdo->query("SELECT eligibility_status,review_reason FROM portal_client_login_eligibility WHERE client_id={$clientId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('review_required', $eligibility['eligibility_status']);
        self::assertSame('non_human_record', $eligibility['review_reason']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());

        $this->pdo->beginTransaction();
        try {
            $this->archive->consumeAndRestore($this->pdo, 1, 7);
            self::fail('A consumed archive row must not be replayable.');
        } catch (\DomainException $exception) {
            self::assertSame('Archived client not found.', $exception->getMessage());
            $this->pdo->rollBack();
        }
    }

    public function testControllersLockConsumeAndQueueEveryAffectedWorkspaceBeforeCommit(): void
    {
        $root = dirname(__DIR__, 2);
        $archive = (string)file_get_contents($root . '/src/controllers/client/clients_delete.php');
        $restore = (string)file_get_contents($root . '/src/controllers/client/clients_restore.php');

        self::assertLessThan(strpos($archive, 'ClientArchivePortalStateService'), strpos($archive, 'beginTransaction'));
        self::assertLessThan(strpos($archive, 'queueWorkspaceIds'), strpos($archive, 'ClientArchivePortalStateService'));
        self::assertLessThan(strpos($archive, 'commit()'), strpos($archive, 'queueWorkspaceIds'));
        self::assertStringNotContainsString('SELECT * FROM archived_clients', $restore);
        self::assertLessThan(strpos($restore, 'consumeAndRestore'), strpos($restore, 'beginTransaction'));
        self::assertLessThan(strpos($restore, 'queueWorkspaceIds'), strpos($restore, 'consumeAndRestore'));
        self::assertLessThan(strpos($restore, 'commit()'), strpos($restore, 'queueWorkspaceIds'));
    }

    private function insertClient(int $id, string $publicId, string $name, string $email, ?int $organizationId, string $type): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO clients
             (id,public_id,name,email,organization_id,client_type,country,source_version,created_at)
             VALUES (?,?,?,?,?,?,?, ?,?)'
        );
        $statement->execute([$id, $publicId, $name, $email, $organizationId, $type, 'US', 'v1', '2026-01-01 00:00:00']);
    }

    private function sharePrincipal(int $sourceClientId, int $targetClientId, string $targetPublicId): int
    {
        $sourcePrincipal = (int)$this->pdo->query("SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id={$sourceClientId}")->fetchColumn();
        $targetPrincipal = (int)$this->pdo->query("SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id={$targetClientId}")->fetchColumn();
        $this->pdo->exec("DELETE FROM portal_principal_clients WHERE portal_principal_id={$targetPrincipal} AND client_id={$targetClientId}");
        $this->pdo->exec("DELETE FROM portal_v2_entitlements WHERE portal_principal_id={$targetPrincipal}");
        $this->pdo->exec("DELETE FROM portal_principals WHERE id={$targetPrincipal}");
        $this->pdo->exec("UPDATE portal_client_login_eligibility SET portal_principal_id={$sourcePrincipal} WHERE client_id={$targetClientId}");
        $this->pdo->exec("INSERT INTO portal_principal_clients(portal_principal_id,client_id,created_by) VALUES({$sourcePrincipal},{$targetClientId},7)");
        foreach (['workspace.view','directory.read','delivery.view'] as $index => $capability) {
            $statement = $this->pdo->prepare("INSERT INTO portal_v2_entitlements(public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active) VALUES(?,? ,?,'allow','client',?,'shared-v1',1)");
            $statement->execute(['shared-entitlement-' . $index, $sourcePrincipal, $capability, $targetPublicId]);
        }
        return $sourcePrincipal;
    }

    /** @param list<array{root_type:string,root_public_id:string}> $scope */
    private function reconcile(array $scope): void
    {
        $this->pdo->beginTransaction();
        $this->provisioning->ensureScopes($this->pdo, $scope, 7, ['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1]);
        $this->pdo->commit();
    }

    /** @return list<string> */
    private function archiveClient(int $clientId, array $reconcileScopes = []): array
    {
        $this->pdo->beginTransaction();
        $statement = $this->pdo->prepare('SELECT * FROM clients WHERE id=?');
        $statement->execute([$clientId]);
        $affected = $this->archive->archive($this->pdo, $statement->fetch(PDO::FETCH_ASSOC), 7);
        $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$clientId]);
        if ($reconcileScopes !== []) {
            $this->provisioning->ensureScopes($this->pdo, $reconcileScopes, 7, ['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1]);
        }
        $this->pdo->commit();
        return $affected;
    }

    /** @param list<array{root_type:string,root_public_id:string}> $scope */
    private function restoreClient(int $archiveId, array $scope): int
    {
        $this->pdo->beginTransaction();
        $restored = $this->archive->consumeAndRestore($this->pdo, $archiveId, 7);
        $clientId = (int)$restored['client_id'];
        $this->provisioning->ensureScopes($this->pdo, $scope, 7, ['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1]);
        $this->pdo->commit();
        return $clientId;
    }
}
