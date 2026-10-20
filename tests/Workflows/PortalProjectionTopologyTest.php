<?php

declare(strict_types=1);
namespace Tests\Workflows;
use App\Services\PortalAuthorityService;
use App\Services\PortalProjectionMutationService;
use App\Services\PortalProjectionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PortalProjectionTopologyTest extends TestCase
{
    private function db(): PDO
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec((string)file_get_contents(dirname(__DIR__).'/fixtures/manual-portal-workspace.sql'));
        return $db;
    }
    private function workspace(PDO $db): string
    {
        return(new PortalAuthorityService())->saveWorkspace($db,1,'organization','org-one','Synthetic Workspace',7);
    }
    private function call(PDO $db,string $workspace,bool $neutral=false,int $profile=1,?string $onlyAction=null): array
    {
        $db->beginTransaction();
        try{
            (new PortalProjectionMutationService())->materializeWorkspaceRelations($db,$profile,$workspace);
            $service=new PortalProjectionService();
            $result=$neutral?$service->queueWorkspaceNeutralChanges($db,['id'=>$profile],$workspace,$onlyAction):$service->queueWorkspaceChanges($db,['id'=>$profile],$workspace,$onlyAction);
            $db->commit();return $result;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private function snapshot(PDO $db,string $workspace,int $profile=1): void
    {
        $db->beginTransaction();(new PortalProjectionService())->queueWorkspaceSnapshot($db,['id'=>$profile],$workspace);$db->commit();
    }
    private function addChild(PDO $db): void
    {
        $db->exec("INSERT INTO clients VALUES(11,'client-new','New synthetic child',1,0,NULL,'new-v1')");
    }
    private function state(PDO $db): array
    {
        return $db->query('SELECT * FROM portal_projection_resource_state ORDER BY integration_profile_id,resource_type,resource_public_id')->fetchAll(PDO::FETCH_ASSOC);
    }
    private function authority(PDO $db): array
    {
        $result=[];foreach(['portal_principals','portal_principal_clients','portal_identity_bindings','portal_v2_entitlements','portal_client_access_roots','portal_client_login_eligibility']as$t)$result[$t]=$db->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);return $result;
    }
    private function payloads(PDO $db,int $after): array
    {
        return array_map(static fn(string$v):array=>json_decode($v,true,64,JSON_THROW_ON_ERROR),$db->query('SELECT payload_json FROM portal_projection_outbox WHERE id>'.$after.' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
    private function last(PDO $db): int{return(int)$db->query('SELECT COALESCE(MAX(id),0) FROM portal_projection_outbox')->fetchColumn();}
    /** Receiver schema3 requires every active entity to reach the root through
     * explicit active relations at each visible transition, not a later event. */
    private function assertRootedGraph(array $page): void
    {
        $root=$page['workspace']['rootType'].':'.$page['workspace']['rootPublicId'];$edges=[];
        foreach($page['relations']as$r)if($r['active'])$edges[$r['to']['type'].':'.$r['to']['publicId']][]=$r['from']['type'].':'.$r['from']['publicId'];
        foreach($page['entities']as$entity){if(!$entity['active'])continue;$pending=[$entity['type'].':'.$entity['publicId']];$seen=[];$found=false;
            while($pending){$node=array_pop($pending);if($node===$root){$found=true;break;}if(isset($seen[$node]))continue;$seen[$node]=true;array_push($pending,...($edges[$node]??[]));}
            self::assertTrue($found,'No visible orphan entity is accepted by the strict schema3 receiver.');
        }
    }
    public function testNewChildAndRelationArePublishedAsOneCompleteGenerationWithoutAuthorityMutation(): void
    {
        $db=$this->db();$w=$this->workspace($db);$this->snapshot($db,$w);$old=$db->query('SELECT * FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$this->addChild($db);$before=$this->authority($db);$last=$this->last($db);
        $result=$this->call($db,$w);$rows=$this->payloads($db,$last);
        self::assertSame([],$result['events']);self::assertNotNull($result['snapshot']);
        self::assertSame(['snapshot.page','snapshot.activate'],array_column($rows,'kind'));
        self::assertContains('client-new',array_column($rows[0]['entities'],'publicId'));$this->assertRootedGraph($rows[0]);
        self::assertSame($rows[0]['sourceGeneration'],$rows[1]['sourceGeneration']);self::assertSame($rows[0]['snapshotHash'],$rows[1]['snapshotHash']);
        self::assertSame($before,$this->authority($db));self::assertSame($old,$db->query('SELECT * FROM portal_projection_outbox WHERE id<='.$last.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $count=$this->last($db);self::assertSame(['snapshot'=>null,'events'=>[]],$this->call($db,$w));self::assertSame($count,$this->last($db),'A repeated stable mutation does not enqueue another snapshot.');
    }
    public function testNeutralNewChildDefersTopologyAndDoesNotAdvancePublishedStateOrAuthority(): void
    {
        $db=$this->db();$w=$this->workspace($db);$this->snapshot($db,$w);$beforeState=$this->state($db);$last=$this->last($db);$this->addChild($db);
        $db->exec("INSERT INTO portal_principals VALUES(7,'principal-new','synthetic@example.test','Synthetic', 'p-v1',1,NULL);INSERT INTO portal_principal_clients VALUES(7,11);INSERT INTO portal_v2_entitlements VALUES(8,'grant-new',7,'directory.read','allow','client','client-new','g-v1',1,'2000-01-01 00:00:00',NULL)");$beforeAuthority=$this->authority($db);
        self::assertSame(['snapshot'=>null,'events'=>[]],$this->call($db,$w,true));self::assertSame($beforeState,$this->state($db));self::assertSame($last,$this->last($db));self::assertSame($beforeAuthority,$this->authority($db));
        // Only the subsequent already-governed publisher can publish the full
        // current canonical graph and its existing separately granted authority.
        $result=$this->call($db,$w);self::assertNotNull($result['snapshot']);$page=$this->payloads($db,$last)[0];$this->assertRootedGraph($page);self::assertCount(1,$page['principals']);self::assertCount(1,$page['entitlements']);self::assertSame($beforeAuthority,$this->authority($db));
    }
    public function testExistingNeutralNameChangeStaysAnEvent(): void
    {
        $db=$this->db();$w=$this->workspace($db);$this->snapshot($db,$w);$db->exec("UPDATE clients SET name='Updated name' WHERE id=10");$last=$this->last($db);$authority=$this->authority($db);
        $result=$this->call($db,$w,true);self::assertNull($result['snapshot']);self::assertCount(1,$result['events']);$rows=$this->payloads($db,$last);self::assertSame('event',$rows[0]['kind']);self::assertSame('entity',$rows[0]['event']['resource']);self::assertSame('Updated name',$rows[0]['event']['entity']['displayName']);self::assertSame($authority,$this->authority($db));
    }
    public function testEachReviewedDestinationHasItsOwnCompleteGeneration(): void
    {
        $db=$this->db();$w=$this->workspace($db);$db->exec("INSERT INTO portal_integration_profiles SELECT 2,'second_portal',display_label,enabled,portal_projection_enabled,relation_projection_enabled,contact_assignment_projection_enabled,catalog_projection_enabled,service_assignment_projection_enabled,pricing_preview_enabled,draft_quote_enabled,pricing_source,draft_source,portal_route,catalog_route,delivery_enabled,delivery_key_id,delivery_max_attempts,created_by,updated_by FROM portal_integration_profiles WHERE id=1;INSERT INTO portal_integration_profile_workspaces SELECT 2,workspace_id,active,created_by,updated_by,updated_at FROM portal_integration_profile_workspaces WHERE profile_id=1");
        $this->snapshot($db,$w);$this->snapshot($db,$w,2);$last=$this->last($db);$this->addChild($db);$a=$this->call($db,$w,false,1);$b=$this->call($db,$w,false,2);
        self::assertNotNull($a['snapshot']);self::assertNotNull($b['snapshot']);self::assertNotSame($a['snapshot']['sourceGeneration'],$b['snapshot']['sourceGeneration']);$rows=$this->payloads($db,$last);self::assertCount(4,$rows);$this->assertRootedGraph($rows[0]);$this->assertRootedGraph($rows[2]);
    }
    public function testNewProjectPublishesEntityRelationAndLifecycleTogether(): void
    {
        $db=$this->db();$w=$this->workspace($db);$this->snapshot($db,$w);$last=$this->last($db);
        $db->exec("INSERT INTO projects VALUES(40,'project-new','New project',1,NULL,10,'active','project-v1',NULL)");
        $authority=$this->authority($db);$result=$this->call($db,$w);$page=$this->payloads($db,$last)[0];
        self::assertNotNull($result['snapshot']);self::assertSame([],$result['events']);$this->assertRootedGraph($page);
        self::assertSame(['project-new'],array_column($page['projectLifecycles'],'projectPublicId'));
        self::assertSame($authority,$this->authority($db));
    }
    public function testLegacySchemaTwoNeutralCreateRemainsAnEntityEvent(): void
    {
        $db=$this->db();$db->exec('UPDATE portal_integration_profiles SET relation_projection_enabled=0');
        $w=$this->workspace($db);$this->snapshot($db,$w);$this->addChild($db);
        $result=$this->call($db,$w,true);self::assertNull($result['snapshot']);self::assertCount(1,$result['events']);
        self::assertSame(2,$result['events'][0]['schemaVersion']);self::assertSame('entity',$result['events'][0]['event']['resource']);
    }
    public function testNeutralArchiveAndMembershipRemovalPublishTombstonesEvenWithDeferredAddition(): void
    {
        foreach(['archive','membership','mixed']as$case){
            $db=$this->db();$w=$this->workspace($db);$this->snapshot($db,$w);$last=$this->last($db);
            $db->exec($case==='membership'?"UPDATE clients SET organization_id=NULL WHERE id=10":"UPDATE clients SET archived=1 WHERE id=10");
            if($case==='mixed')$this->addChild($db);
            $authority=$this->authority($db);
            // The normal mutation path removes old-root access before additions.
            $result=$this->call($db,$w,true,1,$case==='mixed'?null:'tombstone');
            self::assertNull($result['snapshot']);$rows=$this->payloads($db,$last);
            self::assertNotEmpty($rows,$case);
            self::assertSame(['tombstone'],array_values(array_unique(array_column(array_column($rows,'event'),'action'))),$case);
            self::assertContains('relation',array_column(array_column($rows,'event'),'resource'),$case);
            self::assertContains('entity',array_column(array_column($rows,'event'),'resource'),$case);
            self::assertSame(0,(int)$db->query("SELECT COUNT(*) FROM portal_projection_resource_state WHERE resource_type='entity' AND resource_public_id='client-one'")->fetchColumn());
            self::assertSame(0,(int)$db->query("SELECT COUNT(*) FROM portal_projection_resource_state WHERE resource_type='entity' AND resource_public_id='client-new'")->fetchColumn());
            self::assertSame($authority,$this->authority($db),$case);
        }
    }
}
