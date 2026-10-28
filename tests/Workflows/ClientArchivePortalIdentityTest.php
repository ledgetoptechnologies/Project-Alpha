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
CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,enabled INTEGER,portal_projection_enabled INTEGER,application_key TEXT,portal_route TEXT);
INSERT INTO portal_integration_profiles VALUES(1,1,1,'archive-test','https://receiver.test/events');
CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);
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

    public function testManagedRequestRootIsRetiredBeforeArchiveAndRestoredWithoutDuplicatingHistory(): void
    {
        foreach([null,10]as$organizationId){
            $this->setUp();
            if($organizationId!==null)$this->pdo->exec("INSERT INTO organizations VALUES(10,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Example Org')");
            $this->pdo->beginTransaction();
            \App\Services\PortalClientRequestPolicy::saveAcknowledgement($this->pdo,
                $this->pdo->query('SELECT * FROM portal_integration_profiles')->fetch(PDO::FETCH_ASSOC),
                'native-readable-project-v1',hash('sha256','https://receiver.test/events'),str_repeat('a',64),7);
            $this->pdo->commit();
            $publicId=str_repeat('a',32);
            $this->insertClient(20,$publicId,'Approved Person','approved@example.test',$organizationId,'consumer');
            $scope=[['root_type'=>$organizationId===null?'standalone_client':'organization','root_public_id'=>$organizationId===null?$publicId:str_repeat('b',32)]];
            $this->reconcile($scope);
            $grant=$this->pdo->query("SELECT public_id FROM portal_v2_entitlements WHERE capability='request.create'")->fetchColumn();
            self::assertNotFalse($grant);
            $affected=$this->archiveClient(20);
            self::assertNotEmpty($affected);
            self::assertSame(0,(int)$this->pdo->query("SELECT active FROM portal_v2_entitlements WHERE capability='request.create'")->fetchColumn());
            $this->restoreClient((int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn(),$scope);
            self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_entitlements WHERE capability='request.create'")->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query("SELECT active FROM portal_v2_entitlements WHERE capability='request.create'")->fetchColumn(),'Neutral restore cannot reactivate request authority.');
            $this->pdo->beginTransaction();
            (new \App\Services\PortalClientRequestAccessService())->reconcileClient($this->pdo,1,(int)$this->pdo->query('SELECT id FROM clients')->fetchColumn(),7,true);
            $this->pdo->commit();
            self::assertSame(1,(int)$this->pdo->query("SELECT active FROM portal_v2_entitlements WHERE capability='request.create'")->fetchColumn(),'An explicit reviewed cohort may restore the same owned row.');
            self::assertSame($grant,$this->pdo->query("SELECT public_id FROM portal_v2_entitlements WHERE capability='request.create'")->fetchColumn());
        }
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

    public function testProtectedNonePolicyArchivesAcrossRestrictiveForeignKeyAndRestoresWithoutEnrollment(): void
    {
        $this->createContactPolicyFixture();
        $publicId=str_repeat('c',32);
        $this->insertClient(90,$publicId,'Contact Only','contact-only@example.test',null,'consumer');
        $before=$this->insertContactPolicy(90,null,null);
        try {
            $this->pdo->exec('DELETE FROM clients WHERE id=90');
            self::fail('The real restrictive contact-policy foreign key must reject direct client deletion.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('FOREIGN KEY',$error->getMessage());
        }
        $this->archiveClient(90);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_client_contact_identity_policy')->fetchColumn());
        $snapshot=$this->pdo->query("SELECT * FROM archived_entities WHERE entity_type='portal_contact_identity_policy'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(90,(int)$snapshot['client_id']);
        self::assertSame((int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn(),(int)$snapshot['entity_id']);
        self::assertSame($before,json_decode($snapshot['payload'],true,512,JSON_THROW_ON_ERROR));
        $restored=$this->restoreClient((int)$snapshot['entity_id'],[['root_type'=>'standalone_client','root_public_id'=>$publicId]]);
        self::assertSame(90,$restored);
        self::assertSame($before,$this->contactPolicy($restored));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principal_clients')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM archived_entities')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM archived_clients')->fetchColumn());
    }

    public function testBoundPolicyRestoresOriginalLoginRatherThanChangedContactEmail(): void
    {
        $this->createContactPolicyFixture();
        $publicId=str_repeat('d',32);
        $scope=[['root_type'=>'standalone_client','root_public_id'=>$publicId]];
        $this->insertClient(91,$publicId,'Bound Contact','original-login@example.test',null,'consumer');
        $this->reconcile($scope);
        $principal=(int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id=91')->fetchColumn();
        $this->pdo->prepare('INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at) VALUES (?,"https://idp.example.test","bound-policy-subject",1,CURRENT_TIMESTAMP)')->execute([$principal]);
        $before=$this->insertContactPolicy(91,$principal,'original-login@example.test');
        $this->pdo->exec("UPDATE clients SET email='new-contact-address@example.test' WHERE id=91");
        $this->archiveClient(91);
        self::assertSame(0,(int)$this->pdo->query('SELECT enabled FROM portal_principals')->fetchColumn());
        $snapshot=$this->pdo->query('SELECT payload FROM archived_entities')->fetchColumn();
        self::assertSame($before,json_decode($snapshot,true,512,JSON_THROW_ON_ERROR),'The original policy and immutable command/hash metadata must be retained.');
        $restored=$this->restoreClient((int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn(),$scope);
        self::assertSame($before,$this->contactPolicy($restored));
        $eligibility=$this->pdo->query('SELECT * FROM portal_client_login_eligibility WHERE client_id=91')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($principal,(int)$eligibility['portal_principal_id']);
        self::assertSame('original-login@example.test',$eligibility['canonical_email']);
        self::assertSame('eligible',$eligibility['eligibility_status']);
        self::assertSame('new-contact-address@example.test',$this->pdo->query('SELECT email FROM clients WHERE id=91')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT enabled FROM portal_principals')->fetchColumn());
        self::assertSame('original-login@example.test',$this->pdo->query('SELECT email_hint FROM portal_principals')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT enabled FROM portal_identity_bindings')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principal_clients WHERE client_id=91')->fetchColumn());
    }

    public function testArchiveRollbackRestoresLivePolicyAndDoesNotLeaveArchivePayload(): void
    {
        $this->createContactPolicyFixture();
        $this->insertClient(92,str_repeat('e',32),'Rollback Contact','rollback@example.test',null,'consumer');
        $before=$this->insertContactPolicy(92,null,null);
        $client=$this->pdo->query('SELECT * FROM clients WHERE id=92')->fetch(PDO::FETCH_ASSOC);
        $this->pdo->beginTransaction();
        $this->archive->archive($this->pdo,$client,7);
        $this->pdo->exec('DELETE FROM clients WHERE id=92');
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM archived_entities')->fetchColumn());
        $this->pdo->rollBack();
        self::assertSame($before,$this->contactPolicy(92));
        self::assertSame($client,$this->pdo->query('SELECT * FROM clients WHERE id=92')->fetch(PDO::FETCH_ASSOC));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM archived_entities')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM archived_clients')->fetchColumn());
    }

    public function testProtectedBoundPolicyDoesNotBypassSecurityRevocationDuringArchive(): void
    {
        $this->createContactPolicyFixture();
        $publicId=str_repeat('3',32);
        $scope=[['root_type'=>'standalone_client','root_public_id'=>$publicId]];
        $this->insertClient(95,$publicId,'Protected Revoked Contact','protected-login@example.test',null,'consumer');
        $this->reconcile($scope);
        $principal=(int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility WHERE client_id=95')->fetchColumn();
        $this->pdo->prepare('INSERT INTO portal_identity_bindings(portal_principal_id,issuer,subject_hash,enabled,bound_at) VALUES (?,"https://idp.example.test","revoked-policy-subject",1,CURRENT_TIMESTAMP)')->execute([$principal]);
        $before=$this->insertContactPolicy(95,$principal,'protected-login@example.test');
        $this->archiveClient(95);
        $this->pdo->prepare('UPDATE portal_principals SET authorization_version=authorization_version+1 WHERE id=?')->execute([$principal]);
        $restored=$this->restoreClient((int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn(),$scope);
        self::assertSame($before,$this->contactPolicy($restored));
        $eligibility=$this->pdo->query('SELECT * FROM portal_client_login_eligibility WHERE client_id=95')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('revoked',$eligibility['manual_state']);
        self::assertSame('revoked',$eligibility['eligibility_status']);
        self::assertSame('protected-login@example.test',$eligibility['canonical_email']);
        self::assertSame(0,(int)$this->pdo->query('SELECT enabled FROM portal_principals')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT enabled FROM portal_identity_bindings')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principal_clients')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements WHERE active=1')->fetchColumn());
    }

    public function testRestoreRollbackRetainsExactPolicySnapshotAndArchiveRow(): void
    {
        $this->createContactPolicyFixture();
        $this->insertClient(93,str_repeat('f',32),'Restore Rollback','restore-rollback@example.test',null,'consumer');
        $this->insertContactPolicy(93,null,null);
        $this->archiveClient(93);
        $before=$this->pdo->query('SELECT * FROM archived_entities')->fetch(PDO::FETCH_ASSOC);
        $archive=$this->pdo->query('SELECT * FROM archived_clients')->fetch(PDO::FETCH_ASSOC);
        $this->pdo->beginTransaction();
        $this->archive->consumeAndRestore($this->pdo,(int)$archive['id'],7);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM archived_entities')->fetchColumn());
        $this->pdo->rollBack();
        self::assertSame($before,$this->pdo->query('SELECT * FROM archived_entities')->fetch(PDO::FETCH_ASSOC));
        self::assertSame($archive,$this->pdo->query('SELECT * FROM archived_clients')->fetch(PDO::FETCH_ASSOC));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_client_contact_identity_policy')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
    }

    public function testReusedNumericClientIdDoesNotStealRestoredPolicy(): void
    {
        $this->createContactPolicyFixture();
        $originalPublicId=str_repeat('1',32);
        $this->insertClient(94,$originalPublicId,'Original Contact','original@example.test',null,'consumer');
        $original=$this->insertContactPolicy(94,null,null);
        $this->archiveClient(94);
        $archiveId=(int)$this->pdo->query('SELECT id FROM archived_clients')->fetchColumn();
        $this->insertClient(94,str_repeat('2',32),'New Unrelated Contact','unrelated@example.test',null,'consumer');
        $unrelated=$this->insertContactPolicy(94,null,null,'22222222-2222-4222-8222-222222222222');
        $restored=$this->restoreClient($archiveId,[['root_type'=>'standalone_client','root_public_id'=>$originalPublicId]]);
        self::assertNotSame(94,$restored);
        self::assertSame($unrelated,$this->contactPolicy(94));
        $original['client_id']=$restored;
        self::assertSame($original,$this->contactPolicy($restored));
        self::assertSame($originalPublicId,$this->pdo->query('SELECT public_id FROM clients WHERE id=' . $restored)->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM archived_entities')->fetchColumn());
        self::assertSame([], $this->pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testPolicySnapshotCannotRestoreWithoutPolicySchema(): void
    {
        $this->createContactPolicyFixture();
        $this->insertClient(96,str_repeat('4',32),'Schema Loss','schema-loss@example.test',null,'consumer');
        $this->insertContactPolicy(96,null,null);
        $this->archiveClient(96);
        $this->pdo->exec('DROP TABLE portal_client_contact_identity_policy');
        $this->assertPolicyRestoreRefusedWithoutConsumingArchive();
    }

    public function testMalformedPolicySnapshotCannotRestoreOrConsumeArchive(): void
    {
        $this->createContactPolicyFixture();
        $this->insertClient(97,str_repeat('5',32),'Invalid Policy','invalid-policy@example.test',null,'consumer');
        $this->insertContactPolicy(97,null,null);
        $this->archiveClient(97);
        $this->pdo->exec("UPDATE archived_entities SET payload='{}'");
        $this->assertPolicyRestoreRefusedWithoutConsumingArchive();
    }

    public function testDuplicatePolicySnapshotsCannotRestoreOrConsumeArchive(): void
    {
        $this->createContactPolicyFixture();
        $this->insertClient(98,str_repeat('6',32),'Ambiguous Policy','ambiguous-policy@example.test',null,'consumer');
        $this->insertContactPolicy(98,null,null);
        $this->archiveClient(98);
        $this->pdo->exec('INSERT INTO archived_entities(client_id,entity_type,entity_id,payload) SELECT client_id,entity_type,entity_id,payload FROM archived_entities');
        $this->assertPolicyRestoreRefusedWithoutConsumingArchive();
    }

    private function assertPolicyRestoreRefusedWithoutConsumingArchive(): void
    {
        $archive=$this->pdo->query('SELECT * FROM archived_clients')->fetch(PDO::FETCH_ASSOC);
        $snapshots=$this->pdo->query('SELECT * FROM archived_entities ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->pdo->beginTransaction();
        try {
            $this->archive->consumeAndRestore($this->pdo,(int)$archive['id'],7);
            self::fail('An unavailable or ambiguous protected policy must not restore a client without its protection.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('policy',$error->getMessage());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame($archive,$this->pdo->query('SELECT * FROM archived_clients')->fetch(PDO::FETCH_ASSOC));
        self::assertSame($snapshots,$this->pdo->query('SELECT * FROM archived_entities ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
    }

    private function createContactPolicyFixture(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY);
INSERT INTO api_v2_applications VALUES(1);
CREATE TABLE portal_client_contact_identity_policy(
 client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER NULL,login_email TEXT NULL,
 application_pk INTEGER NOT NULL,history_epoch TEXT NOT NULL,command_id TEXT NOT NULL,
 request_sha256 TEXT NOT NULL,created_at TEXT NOT NULL,
 FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE RESTRICT,
 FOREIGN KEY(portal_principal_id) REFERENCES portal_principals(id) ON DELETE RESTRICT,
 FOREIGN KEY(application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT
);
CREATE TABLE archived_entities(
 id INTEGER PRIMARY KEY AUTOINCREMENT,client_id INTEGER NULL,organization_id INTEGER NULL,
 entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,payload TEXT NOT NULL CHECK(json_valid(payload)),
 archived_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
    }

    private function insertContactPolicy(int $clientId,?int $principalId,?string $loginEmail,string $commandId='11111111-1111-4111-8111-111111111111'): array
    {
        $this->pdo->prepare('INSERT INTO portal_client_contact_identity_policy(client_id,portal_principal_id,login_email,application_pk,history_epoch,command_id,request_sha256,created_at) VALUES (?,?,?,1,?,?,?,?)')
            ->execute([$clientId,$principalId,$loginEmail,'33333333-3333-4333-8333-333333333333',$commandId,hash('sha256','synthetic-contact-policy-command'),'2026-10-27 12:34:56.123456']);
        return $this->contactPolicy($clientId);
    }

    private function contactPolicy(int $clientId): array
    {
        $s=$this->pdo->prepare('SELECT * FROM portal_client_contact_identity_policy WHERE client_id=?');$s->execute([$clientId]);
        return $s->fetch(PDO::FETCH_ASSOC);
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
