<?php
declare(strict_types=1);
namespace Tests\Workflows;

use App\Services\PortalClientRequestAccessService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PortalClientRequestAccessTest extends TestCase
{
    private PDO $pdo;
    private PortalClientRequestAccessService $service;

    protected function setUp(): void
    {
        $this->pdo=new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,archived INTEGER DEFAULT 0,deleted_at TEXT);
CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,enabled INTEGER,portal_projection_enabled INTEGER,application_key TEXT,portal_route TEXT);
CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
CREATE TABLE portal_principals(id INTEGER PRIMARY KEY,public_id TEXT,enabled INTEGER,revoked_at TEXT);
CREATE TABLE portal_client_login_eligibility(client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,eligibility_status TEXT,manual_state TEXT,canonical_email TEXT);
CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER);
CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,public_id TEXT,root_type TEXT,root_public_id TEXT,active INTEGER);
CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER);
CREATE TABLE portal_client_access_roots(root_type TEXT,root_public_id TEXT,access_state TEXT);
CREATE TABLE portal_v2_entitlements(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,portal_principal_id INTEGER,capability TEXT,effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,valid_from TEXT,expires_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);
INSERT INTO portal_integration_profiles VALUES(1,1,1,'source-a','https://receiver.test/events'),(2,1,1,'source-b','https://other.test/events');
INSERT INTO organizations VALUES(10,'org-a'),(11,'org-b');
INSERT INTO clients VALUES(20,'client-a',10,0,NULL),(21,'client-b',11,0,NULL),(22,'client-c',NULL,0,NULL);
INSERT INTO portal_principals VALUES(1,'principal-a',1,NULL),(2,'principal-b',1,NULL),(3,'principal-c',1,NULL);
INSERT INTO portal_client_login_eligibility VALUES(20,1,'eligible','automatic','a@example.test'),(21,2,'eligible','automatic','b@example.test'),(22,3,'eligible','automatic','c@example.test');
INSERT INTO portal_principal_clients VALUES(1,20),(2,21),(3,22);
INSERT INTO portal_v2_workspaces VALUES(1,'workspace-a','organization','org-a',1),(2,'workspace-b','organization','org-b',1),(3,'workspace-c','standalone_client','client-c',1);
INSERT INTO portal_integration_profile_workspaces VALUES(1,1,1),(1,2,1),(1,3,1);
SQL);
        $this->service=new PortalClientRequestAccessService();
        $this->pdo->beginTransaction();
        \App\Services\PortalClientRequestPolicy::saveAcknowledgement($this->pdo,
            $this->pdo->query('SELECT * FROM portal_integration_profiles WHERE id=1')->fetch(PDO::FETCH_ASSOC),
            'native-readable-project-v1',hash('sha256','https://receiver.test/events'),str_repeat('a',64),7);
    }

    public function testExplicitApprovedCohortGetsOnlyRequestCapabilityAndIsIdempotent(): void
    {
        self::assertSame([], $this->service->reconcileClient($this->pdo,1,20,7));
        self::assertSame(['workspace-a'],$this->service->reconcileClient($this->pdo,1,20,7,true));
        self::assertSame(['workspace-c'],$this->service->reconcileClient($this->pdo,1,22,7,true));
        self::assertSame([], $this->service->reconcileClient($this->pdo,1,20,7,true));
        self::assertSame([['request.create','organization','org-a'],['request.create','client','client-c']],$this->pdo->query('SELECT capability,scope_type,scope_public_id FROM portal_v2_entitlements ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_audit')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements WHERE portal_principal_id=2')->fetchColumn());
    }

    public function testScopeMoveRetiresOnlyOwnedOldGrantAndPublishesBothRoots(): void
    {
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        $this->pdo->exec('UPDATE clients SET organization_id=11 WHERE id=20');
        self::assertSame(['workspace-a'],$this->service->reconcileClient($this->pdo,1,20,7));
        self::assertSame([['org-a',0]],$this->pdo->query('SELECT scope_public_id,active FROM portal_v2_entitlements ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        self::assertSame(['workspace-b'],$this->service->reconcileClient($this->pdo,1,20,7,true));
        self::assertSame([['org-a',0],['org-b',1]],$this->pdo->query('SELECT scope_public_id,active FROM portal_v2_entitlements ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }

    public function testManualEditRelinquishesOwnershipWithoutClobberOrDuplicate(): void
    {
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        $this->pdo->exec("UPDATE portal_v2_entitlements SET source_version='manual-change',active=0,expires_at='2030-01-01' WHERE id=1");
        $before=$this->pdo->query('SELECT * FROM portal_v2_entitlements')->fetchAll(PDO::FETCH_ASSOC);
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        $this->pdo->exec('UPDATE clients SET organization_id=11 WHERE id=20');
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        self::assertSame($before[0],$this->pdo->query('SELECT * FROM portal_v2_entitlements WHERE id=1')->fetch(PDO::FETCH_ASSOC));
    }

    public function testManualAllowAndContactDenyArePreserved(): void
    {
        $this->pdo->exec("INSERT INTO portal_v2_entitlements VALUES(1,'manual',1,'request.create','allow','organization','org-a','manual-version',0,NULL,'2030-01-01',7,7)");
        self::assertSame([],$this->service->reconcileClient($this->pdo,1,20,7,true));
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn());
        $this->service->reconcileClient($this->pdo,1,22,7,true);
        $this->pdo->exec("INSERT INTO portal_v2_entitlements VALUES(3,'deny',3,'request.create','deny','client','client-c','manual-deny',1,NULL,NULL,7,7)");
        self::assertSame(['workspace-c'],$this->service->reconcileClient($this->pdo,1,22,7,true));
        self::assertSame(0,(int)$this->pdo->query('SELECT active FROM portal_v2_entitlements WHERE id=2')->fetchColumn());
        self::assertSame('manual-deny',$this->pdo->query('SELECT source_version FROM portal_v2_entitlements WHERE id=3')->fetchColumn());
    }

    public function testRevocationDeactivationAndProfilePauseRetainTombstones(): void
    {
        foreach (['UPDATE portal_client_login_eligibility SET manual_state=\'revoked\' WHERE client_id=20','UPDATE clients SET archived=1 WHERE id=20','UPDATE portal_integration_profiles SET enabled=0 WHERE id=1'] as $change) {
            $this->pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='automatic'; UPDATE clients SET archived=0; UPDATE portal_integration_profiles SET enabled=1");
            $this->service->reconcileClient($this->pdo,1,20,7,true);
            $this->pdo->exec($change);
            self::assertSame(['workspace-a'],$this->service->reconcileClient($this->pdo,1,20,7,true));
            self::assertSame(0,(int)$this->pdo->query('SELECT active FROM portal_v2_entitlements WHERE id=1')->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn());
        }
    }

    public function testUnreviewedSiblingCannotRetainReviewedContactsRootGrant(): void
    {
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        $this->pdo->exec("INSERT INTO clients VALUES(23,'client-d',10,0,NULL); INSERT INTO portal_client_login_eligibility VALUES(23,1,'eligible','automatic','a@example.test'); INSERT INTO portal_principal_clients VALUES(1,23); DELETE FROM portal_principal_clients WHERE client_id=20");
        self::assertSame(['workspace-a'],$this->service->reconcileClient($this->pdo,1,20,7));
        self::assertSame(0,(int)$this->pdo->query('SELECT active FROM portal_v2_entitlements WHERE id=1')->fetchColumn());
    }

    public function testDifferentProfileCannotCreateGrantAndTransactionsAreRequired(): void
    {
        self::assertSame([],$this->service->reconcileClient($this->pdo,2,20,7,true));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn());
        $this->pdo->rollBack();
        $this->expectException(\DomainException::class);
        $this->service->reconcileClient($this->pdo,1,20,7,true);
    }

    public function testPrincipalRetirementPreservesOwnedTombstoneForLaterApprovedRestore(): void
    {
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        self::assertSame(['workspace-a'],$this->service->retirePrincipal($this->pdo,1,1,7));
        $this->pdo->exec("UPDATE portal_v2_entitlements SET source_version='principal-revoked',active=0 WHERE active=1");
        self::assertSame([],$this->service->reconcileClient($this->pdo,1,20,7));
        self::assertSame(0,(int)$this->pdo->query('SELECT active FROM portal_v2_entitlements')->fetchColumn());
        self::assertSame(['workspace-a'],$this->service->reconcileClient($this->pdo,1,20,7,true));
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT active FROM portal_v2_entitlements')->fetchColumn());
    }

    public function testMissingOldOrDriftedReceiverAcknowledgementNeverCreatesRootGrant(): void
    {
        foreach(["DELETE FROM app_config","UPDATE app_config SET config_value='{}'","UPDATE portal_integration_profiles SET portal_route='https://changed.test/events' WHERE id=1"] as $change) {
            $this->setUp();$this->pdo->exec($change);
            self::assertSame([],$this->service->reconcileClient($this->pdo,1,20,7,true));
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn());
        }
    }

    public function testArchiveRetiresOnlyOwnedRemovedRootWhileAnotherApprovedRootSurvives(): void
    {
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        $this->pdo->exec('UPDATE portal_client_login_eligibility SET portal_principal_id=1 WHERE client_id=21; UPDATE portal_principal_clients SET portal_principal_id=1 WHERE client_id=21');
        $this->service->reconcileClient($this->pdo,1,21,7,true);
        self::assertSame(['workspace-a'],$this->service->beforeArchive($this->pdo,20,7));
        self::assertSame([['org-a',0],['org-b',1]],$this->pdo->query('SELECT scope_public_id,active FROM portal_v2_entitlements ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }

    public function testSameEmailDoesNotSelectAnotherSourceOrPrincipal(): void
    {
        $this->pdo->exec("ALTER TABLE clients ADD COLUMN email TEXT; UPDATE clients SET email='same@example.test'; UPDATE portal_integration_profile_workspaces SET profile_id=2 WHERE workspace_id=2");
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        self::assertSame([],$this->service->reconcileClient($this->pdo,1,21,7,true));
        self::assertSame([1],$this->pdo->query('SELECT portal_principal_id FROM portal_v2_entitlements')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testProtectedNoneAndMismatchedIdentityNeverGainRequestAuthority(): void
    {
        $this->pdo->exec('CREATE TABLE portal_client_contact_identity_policy(client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,login_email TEXT)');
        foreach([[null,null],[2,'b@example.test'],[1,'wrong@example.test']]as[$principal,$email]){
            $this->pdo->exec('DELETE FROM portal_client_contact_identity_policy');
            $this->pdo->prepare('INSERT INTO portal_client_contact_identity_policy VALUES(20,?,?)')->execute([$principal,$email]);
            self::assertSame([],$this->service->reconcileClient($this->pdo,1,20,7,true));
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn());
        }
    }

    public function testReviewedClientCannotActivateUnreviewedRootOfSamePrincipalDuringMaintenance(): void
    {
        $this->pdo->exec('UPDATE portal_client_login_eligibility SET portal_principal_id=1 WHERE client_id=21; UPDATE portal_principal_clients SET portal_principal_id=1 WHERE client_id=21');
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        self::assertSame([],$this->service->reconcileClient($this->pdo,1,21,7,false));
        self::assertSame([['org-a',1]],$this->pdo->query('SELECT scope_public_id,active FROM portal_v2_entitlements')->fetchAll(PDO::FETCH_NUM));
    }

    public function testSeparatelyReviewedSiblingRetainsOnlyItsOwnApprovalLineage(): void
    {
        $this->pdo->exec('UPDATE clients SET organization_id=10 WHERE id=21; UPDATE portal_client_login_eligibility SET portal_principal_id=1 WHERE client_id=21; UPDATE portal_principal_clients SET portal_principal_id=1 WHERE client_id=21');
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        $this->service->reconcileClient($this->pdo,1,21,7,true);
        self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(DISTINCT public_id) FROM portal_v2_entitlements WHERE scope_public_id='org-a' AND active=1")->fetchColumn());
        $this->pdo->exec('DELETE FROM portal_principal_clients WHERE client_id=20');
        self::assertSame(['workspace-a'],$this->service->reconcileClient($this->pdo,1,20,7,false,true));
        self::assertSame([0,1],$this->pdo->query('SELECT active FROM portal_v2_entitlements ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAnotherProfileCannotAdoptOrRetireOwnedClientLineage(): void
    {
        $this->service->reconcileClient($this->pdo,1,20,7,true);
        $before=$this->pdo->query('SELECT * FROM portal_v2_entitlements')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([],$this->service->reconcileClient($this->pdo,2,20,7,false));
        self::assertSame($before,$this->pdo->query('SELECT * FROM portal_v2_entitlements')->fetchAll(PDO::FETCH_ASSOC));
    }
}
