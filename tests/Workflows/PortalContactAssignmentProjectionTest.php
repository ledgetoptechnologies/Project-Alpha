<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\PortalAuthorityService;
use App\Services\PortalProjectionService;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PortalContactAssignmentProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
    }

    public function testCapabilityDefaultsToV3AndV4PublishesOnlyExplicitAssignments(): void
    {
        $pdo = $this->database();
        $beforeAuth = $this->authCounts($pdo);

        $v3 = $this->snapshot($pdo);
        self::assertSame(3, $v3[0]['schemaVersion']);
        self::assertArrayNotHasKey('contactAssignments', $v3[0]);
        $projectionMethod=new \ReflectionMethod(PortalProjectionService::class,'workspaceProjection');
        $workspace=$pdo->query("SELECT * FROM portal_v2_workspaces WHERE public_id='workspace-org'")->fetch(PDO::FETCH_ASSOC);
        self::assertArrayNotHasKey('contactAssignments',$projectionMethod->invoke(new PortalProjectionService(),$pdo,$workspace,3),'Default-off v3 must retain its pre-v4 canonical hash shape.');

        $pdo->exec('DELETE FROM portal_projection_outbox; DELETE FROM portal_projection_resource_state; DELETE FROM portal_projection_state; UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1');
        $v4 = $this->snapshot($pdo);
        self::assertSame(4, $v4[0]['schemaVersion']);
        self::assertCount(3, $v4[0]['contactAssignments']);
        $assignments=[];foreach($v4[0]['contactAssignments']as$assignment)$assignments[$assignment['scopeType'].'|'.$assignment['clientPublicId']]=$assignment['role'];ksort($assignments);
        self::assertSame([
            'department|client-manager'=>'contact',
            'project|client-manager'=>'billing_contact',
            'project|client-technical'=>'technical_contact',
        ],$assignments);
        self::assertSame($beforeAuth, $this->authCounts($pdo), 'Directory projection must not create principals, bindings, or grants.');

        $firstHash = (string)$v4[0]['snapshotHash'];
        $firstAssignments = $v4[0]['contactAssignments'];
        $pdo->exec('DELETE FROM portal_projection_outbox');
        $again = $this->snapshot($pdo);
        self::assertSame($firstHash, $again[0]['snapshotHash']);
        self::assertSame($firstAssignments, $again[0]['contactAssignments']);
    }

    public function testSchemaTransitionQueuesACompleteReplacementGeneration(): void
    {
        $pdo = $this->database();
        $this->snapshot($pdo);
        $pdo->exec('DELETE FROM portal_projection_outbox');

        $this->saveProfile($pdo, true);
        self::assertSame(1, (int)$pdo->query('SELECT contact_assignment_projection_enabled FROM portal_integration_profiles')->fetchColumn());
        self::assertSame([4,4], array_map('intval', $pdo->query('SELECT schema_version FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(['snapshot.page','snapshot.activate'], $pdo->query('SELECT delivery_kind FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

        $pdo->exec('DELETE FROM portal_projection_outbox');
        $this->saveProfile($pdo, false);
        self::assertSame([3,3], array_map('intval', $pdo->query('SELECT schema_version FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testLastRemovalUsesACompleteReplacementGeneration(): void
    {
        $pdo = $this->database();
        $pdo->exec('UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1');
        $this->snapshot($pdo);
        $pdo->exec('DELETE FROM portal_projection_outbox; DELETE FROM organization_department_contacts; DELETE FROM project_clients');

        $pdo->beginTransaction();
        $result = (new PortalProjectionService())->queueWorkspaceChanges($pdo, ['id'=>1], 'workspace-org','tombstone');
        $pdo->commit();
        self::assertSame([], $result['events']);
        self::assertNotNull($result['snapshot']);
        self::assertSame(['snapshot.page','snapshot.activate'],$pdo->query('SELECT delivery_kind FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $page=json_decode((string)$pdo->query("SELECT payload_json FROM portal_projection_outbox WHERE delivery_kind='snapshot.page'")->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
        self::assertSame([], $page['contactAssignments']);
    }

    public function testFirstAssignmentUsesACompleteReplacementGeneration(): void
    {
        $pdo=$this->database(false);$pdo->exec('UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1');
        $this->snapshot($pdo);$pdo->exec("DELETE FROM portal_projection_outbox; INSERT INTO project_clients VALUES(20,20,10,10,'billing contact',1,0,1,0)");
        $pdo->beginTransaction();$removalPass=(new PortalProjectionService())->queueWorkspaceChanges($pdo,['id'=>1],'workspace-org','tombstone');$pdo->commit();
        self::assertNull($removalPass['snapshot']);self::assertSame([],$removalPass['events']);
        $pdo->beginTransaction();$result=(new PortalProjectionService())->queueWorkspaceChanges($pdo,['id'=>1],'workspace-org','upsert');$pdo->commit();
        self::assertSame([], $result['events']);self::assertNotNull($result['snapshot']);
        self::assertSame(['snapshot.page','snapshot.activate'],$pdo->query('SELECT delivery_kind FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $page=json_decode((string)$pdo->query("SELECT payload_json FROM portal_projection_outbox WHERE delivery_kind='snapshot.page'")->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
        self::assertCount(1,$page['contactAssignments']);
        self::assertTrue($page['contactAssignments'][0]['primaryBilling']);
        self::assertFalse($page['contactAssignments'][0]['sendProjectInvoices']);
    }

    public function testVisibleRoleChangeKeepsStableIdAndAdvancesSourceVersion(): void
    {
        $pdo=$this->database();$pdo->exec('UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1');
        $pages=$this->snapshot($pdo);$before=array_values(array_filter($pages[0]['contactAssignments'],static fn(array$row):bool=>$row['scopeType']==='project'&&$row['clientPublicId']==='client-technical'))[0];
        $pdo->exec("DELETE FROM portal_projection_outbox; UPDATE project_clients SET role='site liaison' WHERE id=21");
        $pdo->beginTransaction();$result=(new PortalProjectionService())->queueWorkspaceChanges($pdo,['id'=>1],'workspace-org');$pdo->commit();
        $events=array_values(array_filter($result['events'],static fn(array$delivery):bool=>($delivery['event']['resource']??null)==='contact_assignment'&&($delivery['event']['action']??null)==='upsert'));
        self::assertCount(1,$events);$after=$events[0]['event']['contactAssignment'];
        self::assertSame($before['publicId'],$after['publicId']);
        self::assertNotSame($before['sourceVersion'],$after['sourceVersion']);
        self::assertSame('site_liaison',$after['role']);
    }

    public function testPrimaryBillingDoesNotImplyInvoiceEmailDelivery(): void
    {
        $pdo=$this->database();$pdo->exec('UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1, relation_projection_enabled=1; UPDATE project_clients SET send_project_invoices=0 WHERE id=20');
        $pages=$this->snapshot($pdo);$assignment=array_values(array_filter($pages[0]['contactAssignments'],static fn(array$row):bool=>$row['clientPublicId']==='client-manager'&&$row['scopeType']==='project'))[0];
        self::assertTrue($assignment['primaryBilling']);
        self::assertFalse($assignment['sendProjectInvoices']);
        self::assertTrue($assignment['canViewInvoiceLinks']);
    }

    public function testRapidEnableDisableSupersedesOnlyUnclaimedNormalV4RowsAndQueuesV3Recovery(): void
    {
        $pdo=$this->database();$this->snapshot($pdo);$pdo->exec('DELETE FROM portal_projection_outbox');
        $this->saveProfile($pdo,true);
        $rows=$pdo->query('SELECT id,delivery_kind FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $claimedId=(int)$rows[0]['id'];$rejectedId=(int)$rows[1]['id'];
        $pdo->prepare("UPDATE portal_projection_outbox SET claimed_at='2026-09-02 20:00:00' WHERE id=?")->execute([$claimedId]);
        $pdo->prepare("UPDATE portal_projection_outbox SET dead_lettered_at='2026-09-02 20:01:00',last_error_code='receiver_schema_rejected' WHERE id=?")->execute([$rejectedId]);
        $pdo->exec("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json,claimed_at,delivered_at,dead_lettered_at,last_error_code) VALUES(1,'v4-pending','workspace-org',4,98,'event','portal',0,NULL,NULL,'{}',NULL,NULL,NULL,NULL)");
        $pdo->exec("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json,claimed_at,delivered_at,dead_lettered_at,last_error_code) VALUES(1,'v4-revocation','workspace-org',4,99,'event','portal',1,NULL,NULL,'{}',NULL,NULL,NULL,NULL)");

        $this->saveProfile($pdo,false);
        $claimed=$pdo->query('SELECT dead_lettered_at,last_error_code FROM portal_projection_outbox WHERE id='.$claimedId)->fetch(PDO::FETCH_ASSOC);
        self::assertNull($claimed['dead_lettered_at']);self::assertNull($claimed['last_error_code']);
        $rejected=$pdo->query('SELECT dead_lettered_at,last_error_code FROM portal_projection_outbox WHERE id='.$rejectedId)->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($rejected['dead_lettered_at']);self::assertSame('schema_transition_superseded',$rejected['last_error_code']);
        $pending=$pdo->query("SELECT dead_lettered_at,last_error_code FROM portal_projection_outbox WHERE delivery_id='v4-pending'")->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($pending['dead_lettered_at']);self::assertSame('schema_transition_superseded',$pending['last_error_code']);
        $revocation=$pdo->query("SELECT dead_lettered_at,last_error_code FROM portal_projection_outbox WHERE delivery_id='v4-revocation'")->fetch(PDO::FETCH_ASSOC);
        self::assertNull($revocation['dead_lettered_at']);self::assertNull($revocation['last_error_code']);
        self::assertSame([3,3],array_map('intval',$pdo->query("SELECT schema_version FROM portal_projection_outbox WHERE schema_version=3 AND is_revocation=0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(['snapshot.page','snapshot.activate'],$pdo->query("SELECT delivery_kind FROM portal_projection_outbox WHERE schema_version=3 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        self::assertLessThan((int)$pdo->query('SELECT MIN(id) FROM portal_projection_outbox WHERE schema_version=3')->fetchColumn(),(int)$pdo->query('SELECT MAX(id) FROM portal_projection_outbox WHERE schema_version=4')->fetchColumn());
    }

    public function testGoldenFixturePinsCompleteActivatableGenerationHash(): void
    {
        $fixture=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/project-alpha-portal-contact-assignments-v4.json'),true,64,JSON_THROW_ON_ERROR);
        $page=$fixture['valid']['snapshotPage'];$activation=$fixture['valid']['snapshotActivate'];
        $projection=[];foreach(['entities','principals','entitlements','relations','projectLifecycles','contactAssignments']as$family)$projection[$family]=$page[$family];
        $canonical=function(array$value):string{$sort=function(&$item)use(&$sort):void{if(!is_array($item))return;if(array_is_list($item)){foreach($item as&$child)$sort($child);unset($child);return;}ksort($item);foreach($item as&$child)$sort($child);unset($child);};$sort($value);return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);};
        $hash=hash('sha256',$canonical($projection));
        self::assertSame($fixture['expectedSnapshotHash'],$hash);
        self::assertSame($hash,$page['snapshotHash']);self::assertSame($hash,$activation['snapshotHash']);
        self::assertSame($page['recordCount'],array_sum(array_map('count',$projection)));
        self::assertSame($page['recordCount'],$activation['recordCount']);
        $entityIds=array_fill_keys(array_column($page['entities'],'publicId'),true);
        $receiverNegative=array_values(array_filter($fixture['invalid'],static fn(array$case):bool=>($case['validationLayer']??null)==='receiver-generation'))[0]['delivery'];
        self::assertArrayNotHasKey($receiverNegative['contactAssignments'][0]['contactPublicId'],array_fill_keys(array_column($receiverNegative['entities'],'publicId'),true));
        self::assertArrayHasKey($page['contactAssignments'][0]['contactPublicId'],$entityIds);
    }

    public function testCrossRootAssignmentFailsClosed(): void
    {
        $pdo = $this->database();
        $pdo->exec("UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1; UPDATE clients SET organization_id=2 WHERE id=11");
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('portal-contact-assignment-root-mismatch');
        $this->snapshot($pdo);
    }

    public function testStandaloneWorkspacePublishesProjectAssignmentsOnly(): void
    {
        $pdo = $this->database();
        $pdo->exec("UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1;
            INSERT INTO clients VALUES(30,'client-standalone','Solo Contact',NULL,0,NULL,'client-solo-v1');
            INSERT INTO projects VALUES(30,'project-standalone','Solo Project',NULL,NULL,30,'active','project-solo-v1',NULL);
            INSERT INTO project_clients VALUES(30,30,30,NULL,'owner',1,1,1,0);
            INSERT INTO portal_v2_contacts VALUES(30,'contact-standalone',30,'Solo Contact','contact-solo-v1',1);
            INSERT INTO portal_v2_workspaces VALUES(30,'workspace-standalone','standalone_client','client-standalone','Solo Workspace','workspace-solo-v1',1);
            INSERT INTO portal_integration_profile_workspaces VALUES(1,30,1);");
        $pages = $this->snapshot($pdo, 'workspace-standalone');
        self::assertCount(1, $pages[0]['contactAssignments']);
        self::assertSame('project', $pages[0]['contactAssignments'][0]['scopeType']);
        self::assertSame('project-standalone', $pages[0]['contactAssignments'][0]['scopePublicId']);
    }

    public function testPagingCountsEveryV4FamilyAndCapsPagesAtOneHundredRecords(): void
    {
        $pdo = $this->database(false);
        $pdo->exec('UPDATE portal_integration_profiles SET contact_assignment_projection_enabled=1');
        $insertClient = $pdo->prepare('INSERT INTO clients VALUES(?,?,?,?,0,NULL,?)');
        $insertAssignment = $pdo->prepare("INSERT INTO project_clients VALUES(?,?,?,?, 'contact',0,1,1,?)");
        $insertContact = $pdo->prepare('INSERT INTO portal_v2_contacts VALUES(?,?,?,?,?,1)');
        for ($i=1; $i<=55; $i++) {
            $id=100+$i;$client='client-bulk-'.$i;$contact='contact-bulk-'.$i;
            $insertClient->execute([$id,$client,'Bulk '.$i,1,'client-v'.$i]);
            $insertAssignment->execute([$id,20,$id,null,$i]);
            $insertContact->execute([$id,$contact,$id,'Bulk '.$i,'contact-v'.$i]);
        }
        $pages = $this->snapshot($pdo);
        self::assertGreaterThan(1, count($pages));
        $aggregate = 0;
        foreach ($pages as $page) {
            $pageCount = count($page['entities']) + count($page['principals']) + count($page['entitlements'])
                + count($page['relations']) + count($page['projectLifecycles']) + count($page['contactAssignments']);
            self::assertLessThanOrEqual(100, $pageCount);
            self::assertSame(count($pages), $page['pageCount']);
            $aggregate += $pageCount;
        }
        self::assertSame($aggregate, $pages[0]['recordCount']);
        self::assertSame(55, array_sum(array_map(static fn(array $page): int => count($page['contactAssignments']), $pages)));
    }

    public function testCapabilityIsDefaultOffAndExistingMutationHooksRemainTransactional(): void
    {
        $root=dirname(__DIR__,2);
        $migration=(string)file_get_contents($root.'/database/migrations/0082_portal_contact_assignment_projection.sql');
        self::assertStringContainsString('contact_assignment_projection_enabled TINYINT(1) NOT NULL DEFAULT 0',$migration);
        $view=(string)file_get_contents($root.'/src/views/pages/settings/external-ops.php');
        self::assertSame(1,substr_count($view,'name="contact_assignment_projection_enabled"'));

        $department=(string)file_get_contents($root.'/src/controllers/organization/organization_departments.php');
        self::assertLessThan(strpos($department,'$projection->afterMutation'),strpos($department,'$pdo->beginTransaction'));
        self::assertLessThan(strpos($department,'$pdo->commit()'),strpos($department,'$projection->afterMutation'));
        foreach(['projects_create.php','projects_update.php']as$file){
            $source=(string)file_get_contents($root.'/src/controllers/project/'.$file);
            self::assertStringContainsString('project_invoice_sync_clients(',$source);
            self::assertStringContainsString('PortalProjectionMutationService',$source);
            $hookPosition=max(strpos($source,'queueProject(')?:0,strpos($source,'afterMutation(')?:0);
            self::assertGreaterThan(strpos($source,'project_invoice_sync_clients('),$hookPosition,$file);
            self::assertLessThan(strpos($source,'$pdo->commit()'),$hookPosition,$file);
        }
    }

    /** @return list<array<string,mixed>> */
    private function snapshot(PDO $pdo, string $workspace='workspace-org'): array
    {
        $pdo->beginTransaction();
        (new PortalProjectionService())->queueWorkspaceSnapshot($pdo, ['id'=>1], $workspace);
        $pdo->commit();
        $statement=$pdo->prepare("SELECT payload_json FROM portal_projection_outbox WHERE workspace_public_id=? AND delivery_kind='snapshot.page' ORDER BY id DESC");
        $statement->execute([$workspace]);
        $pages=array_map(static fn(string$json):array=>json_decode($json,true,64,JSON_THROW_ON_ERROR),array_reverse($statement->fetchAll(PDO::FETCH_COLUMN)));
        $lastGeneration=(string)($pages[0]['sourceGeneration']??'');
        return array_values(array_filter($pages,static fn(array$page):bool=>(string)$page['sourceGeneration']===$lastGeneration));
    }

    private function saveProfile(PDO $pdo, bool $contacts): void
    {
        (new PortalAuthorityService())->saveProfile($pdo, [
            'profile_id'=>1,'application_key'=>'field_operations_portal','display_label'=>'Field Operations Portal',
            'enabled'=>1,'portal_projection_enabled'=>1,'relation_projection_enabled'=>1,
            'contact_assignment_projection_enabled'=>$contacts?1:0,'catalog_projection_enabled'=>0,
            'service_assignment_projection_enabled'=>0,'pricing_preview_enabled'=>0,'draft_quote_enabled'=>0,
            'portal_route'=>'https://receiver.example.test/api/internal/project-alpha/portal-v2','catalog_route'=>'',
        ], 7);
    }

    /** @return array{principals:int,bindings:int,entitlements:int} */
    private function authCounts(PDO $pdo): array
    {
        return [
            'principals'=>(int)$pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn(),
            'bindings'=>(int)$pdo->query('SELECT COUNT(*) FROM portal_identity_bindings')->fetchColumn(),
            'entitlements'=>(int)$pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn(),
        ];
    }

    private function database(bool $seedAssignments=true): PDO
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,source_version TEXT);
CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,organization_id INTEGER,public_id TEXT,name TEXT,source_version TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,organization_id INTEGER,archived INTEGER,deleted_at TEXT,source_version TEXT);
CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,organization_id INTEGER,department_id INTEGER,client_id INTEGER,status TEXT,source_version TEXT,completed_at TEXT);
CREATE TABLE organization_department_contacts(id INTEGER PRIMARY KEY,department_id INTEGER,client_id INTEGER,role TEXT,is_primary INTEGER);
CREATE TABLE project_clients(id INTEGER PRIMARY KEY,project_id INTEGER,client_id INTEGER,department_id INTEGER,role TEXT,is_primary_billing INTEGER,send_project_invoices INTEGER,can_view_invoice_links INTEGER,sort_order INTEGER);
CREATE TABLE portal_v2_contacts(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,client_id INTEGER UNIQUE,display_name TEXT,source_version TEXT,active INTEGER);
CREATE TABLE portal_v2_relations(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,relation_type TEXT,from_type TEXT,from_public_id TEXT,to_type TEXT,to_public_id TEXT,source_version TEXT,active INTEGER);
CREATE TABLE portal_principals(id INTEGER PRIMARY KEY,public_id TEXT,email_hint TEXT,display_name TEXT,source_version TEXT,enabled INTEGER,revoked_at TEXT);
CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER);
CREATE TABLE portal_identity_bindings(id INTEGER PRIMARY KEY,portal_principal_id INTEGER,issuer TEXT,subject_hash TEXT,enabled INTEGER);
CREATE TABLE portal_v2_entitlements(id INTEGER PRIMARY KEY,public_id TEXT,portal_principal_id INTEGER,capability TEXT,effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,valid_from TEXT,expires_at TEXT);
CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,display_label TEXT,enabled INTEGER,portal_projection_enabled INTEGER,relation_projection_enabled INTEGER,contact_assignment_projection_enabled INTEGER DEFAULT 0,catalog_projection_enabled INTEGER,service_assignment_projection_enabled INTEGER,pricing_preview_enabled INTEGER,draft_quote_enabled INTEGER,pricing_source TEXT,draft_source TEXT,portal_route TEXT,catalog_route TEXT,delivery_enabled INTEGER,delivery_key_id TEXT,delivery_max_attempts INTEGER DEFAULT 12,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,public_id TEXT,root_type TEXT,root_public_id TEXT,display_name TEXT,source_version TEXT,active INTEGER);
CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,PRIMARY KEY(profile_id,workspace_id));
CREATE TABLE portal_projection_state(integration_profile_id INTEGER,workspace_public_id TEXT,source_generation TEXT,source_sequence INTEGER,last_snapshot_hash TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id));
CREATE TABLE portal_projection_resource_state(integration_profile_id INTEGER,workspace_public_id TEXT,route_type TEXT,resource_type TEXT,resource_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id,route_type,resource_type,resource_public_id));
CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_error_code TEXT);
CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);
INSERT INTO organizations VALUES(1,'org-one','Organization One','org-v1'),(2,'org-two','Organization Two','org-v2');
INSERT INTO organization_departments VALUES(10,1,'department-field','Field','department-v1');
INSERT INTO clients VALUES(10,'client-manager','Manager',1,0,NULL,'client-v1'),(11,'client-technical','Technical',1,0,NULL,'client-v2');
INSERT INTO projects VALUES(20,'project-north','North Project',1,10,10,'active','project-v1',NULL);
INSERT INTO portal_v2_contacts VALUES(10,'contact-manager',10,'Manager','contact-v1',1),(11,'contact-technical',11,'Technical','contact-v2',1);
INSERT INTO portal_integration_profiles VALUES(1,'field_operations_portal','Field Operations Portal',1,1,1,0,0,0,0,0,NULL,NULL,'https://receiver.example.test/api/internal/project-alpha/portal-v2',NULL,0,NULL,12,7,7);
INSERT INTO portal_v2_workspaces VALUES(1,'workspace-org','organization','org-one','Organization One','workspace-v1',1);
INSERT INTO portal_integration_profile_workspaces VALUES(1,1,1);
SQL);
        if($seedAssignments)$pdo->exec("INSERT INTO organization_department_contacts VALUES(10,10,10,'contact',1);
            INSERT INTO project_clients VALUES(20,20,10,10,'billing contact',1,1,1,0);
            INSERT INTO project_clients VALUES(21,20,11,10,'technical contact',0,0,0,1);");
        return$pdo;
    }
}
