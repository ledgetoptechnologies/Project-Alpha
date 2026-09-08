<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

final class PortalProjectionService
{
    /** Queue a complete bounded generation. Caller owns the surrounding transaction. */
    public function queueWorkspaceSnapshot(PDO $pdo, array $profile, string $workspacePublicId): array
    {
        $profileId = (int)($profile['id'] ?? 0);
        if ($profileId < 1) throw new DomainException('portal-profile-workspace-denied');
        $profile = self::lockProfileContract($pdo, $profileId);
        if (empty($profile['enabled']) || empty($profile['portal_projection_enabled'])) throw new DomainException('portal-profile-disabled');
        $workspace = (new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo, $profileId, $workspacePublicId);
        $schemaVersion = $this->portalSchemaVersion($profile);
        $generation = self::uuid();
        $sequence = $this->nextSequence($pdo, $profileId, $workspacePublicId, $generation);
        $projection = $this->workspaceProjection($pdo, $workspace, $schemaVersion);
        $records = [];
        foreach (['entities','principals','entitlements','relations','projectLifecycles','contactAssignments'] as $family) {
            foreach ($projection[$family] ?? [] as $record) $records[] = [$family, $record];
        }
        if (count($records) > 2000) throw new DomainException('portal-workspace-too-large');
        $pages = array_chunk($records, 100);
        if ($pages === []) $pages = [[]];
        if (count($pages) > 100) throw new DomainException('portal-workspace-page-limit');
        $snapshotHash = hash('sha256', self::canonicalJson($projection));
        $now = self::now();
        foreach ($pages as $index => $pageRecords) {
            $pageFamilies = ['entities'=>[],'principals'=>[],'entitlements'=>[],'relations'=>[],'projectLifecycles'=>[],'contactAssignments'=>[]];
            foreach ($pageRecords as [$family,$record]) $pageFamilies[$family][] = $record;
            $payload = [
                'schemaVersion'=>$schemaVersion, 'applicationKey'=>(string)$profile['application_key'],
                'deliveryId'=>self::uuid(), 'occurredAt'=>$now, 'sourceGeneration'=>$generation,
                'sourceSequence'=>$sequence, 'workspaceId'=>$workspacePublicId, 'kind'=>'snapshot.page',
                'snapshotHash'=>$snapshotHash, 'pageNumber'=>$index+1, 'pageCount'=>count($pages),
                'recordCount'=>count($records), 'workspace'=>[
                    'publicId'=>$workspacePublicId, 'rootType'=>(string)$workspace['root_type'],
                    'rootPublicId'=>(string)$workspace['root_public_id'], 'displayName'=>(string)$workspace['display_name'],
                    'sourceVersion'=>PortalSourceVersion::from(['publicId'=>$workspacePublicId,'rootType'=>(string)$workspace['root_type'],'rootPublicId'=>(string)$workspace['root_public_id'],'displayName'=>(string)$workspace['display_name'],'active'=>true]), 'active'=>true,
                ],
                'entities'=>$pageFamilies['entities'], 'principals'=>$pageFamilies['principals'], 'entitlements'=>$pageFamilies['entitlements'],
            ];
            if ($schemaVersion >= 3) {
                $payload['relations']=$pageFamilies['relations'];
                $payload['projectLifecycles']=$pageFamilies['projectLifecycles'];
            }
            if ($schemaVersion === 4) $payload['contactAssignments']=$pageFamilies['contactAssignments'];
            PortalIntegrationContract::validatePortalDelivery($payload,$schemaVersion>=3,$schemaVersion===4);
            $this->enqueue($pdo, $profile, $workspacePublicId, $schemaVersion, $sequence, 'snapshot.page', 'portal', $payload);
        }
        $activationDeliveryId = self::uuid();
        $activation = [
            'schemaVersion'=>$schemaVersion, 'applicationKey'=>(string)$profile['application_key'],
            'deliveryId'=>$activationDeliveryId, 'occurredAt'=>$now, 'sourceGeneration'=>$generation,
            'sourceSequence'=>$sequence, 'workspaceId'=>$workspacePublicId, 'kind'=>'snapshot.activate',
            'snapshotHash'=>$snapshotHash, 'pageCount'=>count($pages), 'recordCount'=>count($records),
        ];
        PortalIntegrationContract::validatePortalDelivery($activation,$schemaVersion>=3,$schemaVersion===4);
        $this->enqueue($pdo, $profile, $workspacePublicId, $schemaVersion, $sequence, 'snapshot.activate', 'portal', $activation);
        $pdo->prepare('UPDATE portal_projection_state SET last_snapshot_hash=? WHERE integration_profile_id=? AND workspace_public_id=?')
            ->execute([$snapshotHash,(int)$profile['id'],$workspacePublicId]);
        $this->replaceResourceState($pdo,$profileId,$workspacePublicId,'portal',$this->portalResourceRecords($workspace,$projection,$schemaVersion));
        return ['sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'snapshotHash'=>$snapshotHash,'pageCount'=>count($pages),'recordCount'=>count($records),'activationDeliveryId'=>$activationDeliveryId];
    }

    /** Queue a complete, bounded Service Library generation. Caller owns the transaction. */
    public function queueCatalogSnapshot(PDO $pdo,array $profile):array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-disabled');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['catalog_projection_enabled'])||empty($profile['catalog_route']))throw new DomainException('catalog-profile-disabled');
        $catalog=(new PortalIntegrationService())->catalog($pdo);$items=$catalog['items'];if(count($items)>500)throw new DomainException('catalog-item-limit');
        $generation='catalog-'.self::uuid();$sequence=$this->nextSequence($pdo,$profileId,'catalog',$generation);$pages=array_chunk($items,50);if($pages===[])$pages=[[]];
        $snapshotHash=hash('sha256',self::canonicalJson(['items'=>$items]));$now=self::now();$pageCount=count($pages);$itemCount=count($items);
        foreach($pages as$index=>$pageItems){$payload=[
            'schemaVersion'=>2,'applicationKey'=>(string)$profile['application_key'],'deliveryId'=>self::uuid(),'occurredAt'=>$now,
            'sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'kind'=>'snapshot.page','snapshotHash'=>$snapshotHash,
            'pageNumber'=>$index+1,'pageCount'=>$pageCount,'itemCount'=>$itemCount,'items'=>$pageItems,
        ];PortalIntegrationContract::validateCatalogDelivery($payload);$this->enqueue($pdo,$profile,'catalog',2,$sequence,'snapshot.page','catalog',$payload);}
        $activation=[
            'schemaVersion'=>2,'applicationKey'=>(string)$profile['application_key'],'deliveryId'=>self::uuid(),'occurredAt'=>$now,
            'sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'kind'=>'snapshot.activate','snapshotHash'=>$snapshotHash,
            'pageCount'=>$pageCount,'itemCount'=>$itemCount,
        ];PortalIntegrationContract::validateCatalogDelivery($activation);$this->enqueue($pdo,$profile,'catalog',2,$sequence,'snapshot.activate','catalog',$activation);
        $pdo->prepare('UPDATE portal_projection_state SET last_snapshot_hash=? WHERE integration_profile_id=? AND workspace_public_id=?')->execute([$snapshotHash,$profileId,'catalog']);
        $this->replaceResourceState($pdo,$profileId,'catalog','catalog',$this->catalogResourceRecords($items));
        return['sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'snapshotHash'=>$snapshotHash,'pageCount'=>$pageCount,'itemCount'=>$itemCount];
    }

    /** Queue only changed portal resources after a complete generation has established the checkpoint. */
    public function queueWorkspaceChanges(PDO$pdo,array$profile,string$workspacePublicId,?string$onlyAction=null):array
    {
        if($onlyAction!==null&&!in_array($onlyAction,['upsert','tombstone'],true))throw new DomainException('portal-change-action-invalid');
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-workspace-denied');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['portal_projection_enabled']))throw new DomainException('portal-profile-disabled');
        $workspace=(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspacePublicId);$state=$this->stateForUpdate($pdo,$profileId,$workspacePublicId);
        if(!$state||empty($state['last_snapshot_hash']))return$onlyAction==='tombstone'?['snapshot'=>null,'events'=>[]]:['snapshot'=>$this->queueWorkspaceSnapshot($pdo,$profile,$workspacePublicId),'events'=>[]];
        $schemaVersion=$this->portalSchemaVersion($profile);$projection=$this->workspaceProjection($pdo,$workspace,$schemaVersion);$current=$this->portalResourceRecords($workspace,$projection,$schemaVersion);$events=[];
        $changes=$this->resourceChanges($pdo,$profileId,$workspacePublicId,'portal',$current);
        // Adding or removing an assignment changes receiver topology (contact
        // endpoint plus its relation). Publish that topology atomically as a
        // replacement generation. A visible update to an already-established
        // assignment, such as a role or billing flag, remains a strict event.
        if($schemaVersion===4&&$this->contactTopologyChanged($changes,$onlyAction))return['snapshot'=>$this->queueWorkspaceSnapshot($pdo,$profile,$workspacePublicId),'events'=>[]];
        foreach($changes as$change){
            if($onlyAction!==null&&$change['action']!==$onlyAction)continue;
            if($change['action']==='upsert')$event=$this->portalUpsertEvent($change['resource'],$change['record']);
            elseif($change['resource']==='project_lifecycle'){ $this->deleteResourceState($pdo,$profileId,$workspacePublicId,'portal',$change['resource'],$change['publicId']);continue; }
            else $event=['resource'=>$change['resource'],'action'=>'tombstone','publicId'=>$change['publicId'],'sourceVersion'=>$change['sourceVersion']];
            $events[]=$this->queueEvent($pdo,$profile,$workspacePublicId,$event,false);
            if($change['action']==='upsert')$this->saveResourceState($pdo,$profileId,$workspacePublicId,'portal',$change['resource'],$change['publicId'],$change['sourceVersion'],$change['record']);
            else $this->deleteResourceState($pdo,$profileId,$workspacePublicId,'portal',$change['resource'],$change['publicId']);
        }
        return['snapshot'=>null,'events'=>$events];
    }

    /** Queue strict Service Library upsert/tombstone events after a complete snapshot. */
    public function queueCatalogChanges(PDO$pdo,array$profile):array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-disabled');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['catalog_projection_enabled'])||empty($profile['catalog_route']))throw new DomainException('catalog-profile-disabled');
        $state=$this->stateForUpdate($pdo,$profileId,'catalog');if(!$state||empty($state['last_snapshot_hash']))return['snapshot'=>$this->queueCatalogSnapshot($pdo,$profile),'events'=>[]];
        $items=(new PortalIntegrationService())->catalog($pdo)['items'];$current=$this->catalogResourceRecords($items);$events=[];
        foreach($this->resourceChanges($pdo,$profileId,'catalog','catalog',$current)as$change){
            $event=$change['action']==='upsert'?['action'=>'upsert','item'=>$change['record']]:['action'=>'tombstone','publicId'=>$change['publicId'],'sourceVersion'=>$change['sourceVersion']];
            $events[]=$this->queueCatalogEvent($pdo,$profile,$event,false);
            if($change['action']==='upsert')$this->saveResourceState($pdo,$profileId,'catalog','catalog','catalog_item',$change['publicId'],$change['sourceVersion'],$change['record']);
            else $this->deleteResourceState($pdo,$profileId,'catalog','catalog','catalog_item',$change['publicId']);
        }
        return['snapshot'=>null,'events'=>$events];
    }

    /** Queue one ordered incremental delivery after a complete generation exists. */
    public function queueEvent(PDO $pdo, array $profile, string $workspacePublicId, array $event, bool $isRevocation=false): array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-workspace-denied');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['portal_projection_enabled']))throw new DomainException('portal-profile-disabled');
        (new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspacePublicId);
        $state = $this->stateForUpdate($pdo, (int)$profile['id'], $workspacePublicId);
        if (!$state || empty($state['last_snapshot_hash'])) throw new DomainException('portal-snapshot-required');
        $sequence = (int)$state['source_sequence'] + 1;
        $pdo->prepare('UPDATE portal_projection_state SET source_sequence=? WHERE integration_profile_id=? AND workspace_public_id=?')
            ->execute([$sequence,(int)$profile['id'],$workspacePublicId]);
        $payload = [
            'schemaVersion'=>$this->portalSchemaVersion($profile),
            'applicationKey'=>(string)$profile['application_key'], 'deliveryId'=>self::uuid(), 'occurredAt'=>self::now(),
            'sourceGeneration'=>(string)$state['source_generation'], 'sourceSequence'=>$sequence,
            'workspaceId'=>$workspacePublicId, 'kind'=>'event', 'event'=>$event,
        ];
        PortalIntegrationContract::validatePortalDelivery($payload,(int)$payload['schemaVersion']>=3,(int)$payload['schemaVersion']===4);
        $this->enqueue($pdo,$profile,$workspacePublicId,(int)$payload['schemaVersion'],$sequence,'event','portal',$payload,$isRevocation);
        return $payload;
    }

    /** Queue one catalog event against the currently active catalog generation. */
    public function queueCatalogEvent(PDO$pdo,array$profile,array$event,bool$isRevocation=false):array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-disabled');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['catalog_projection_enabled'])||empty($profile['catalog_route']))throw new DomainException('catalog-profile-disabled');
        $state=$this->stateForUpdate($pdo,$profileId,'catalog');if(!$state||empty($state['last_snapshot_hash']))throw new DomainException('catalog-snapshot-required');$sequence=(int)$state['source_sequence']+1;
        $pdo->prepare('UPDATE portal_projection_state SET source_sequence=? WHERE integration_profile_id=? AND workspace_public_id=?')->execute([$sequence,$profileId,'catalog']);
        $payload=['schemaVersion'=>2,'applicationKey'=>(string)$profile['application_key'],'deliveryId'=>self::uuid(),'occurredAt'=>self::now(),'sourceGeneration'=>(string)$state['source_generation'],'sourceSequence'=>$sequence,'kind'=>'event','event'=>$event];
        PortalIntegrationContract::validateCatalogDelivery($payload);$this->enqueue($pdo,$profile,'catalog',2,$sequence,'event','catalog',$payload,$isRevocation);return$payload;
    }

    /**
     * Queue a final profile-scoped revocation for a workspace that was previously
     * activated. A never-published workspace has nothing downstream to revoke.
     * Caller owns the transaction and must deactivate the link/profile afterward.
     *
     * @return array<string,mixed>|null
     */
    public function queueWorkspaceRevocation(PDO $pdo,array $profile,string $workspacePublicId):?array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-workspace-denied');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['portal_projection_enabled']))throw new DomainException('portal-profile-disabled');
        (new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspacePublicId);
        $state=$this->stateForUpdate($pdo,$profileId,$workspacePublicId);
        if(!$state||empty($state['last_snapshot_hash']))return null;
        $payload=$this->queueEvent($pdo,$profile,$workspacePublicId,[
            'resource'=>'workspace','action'=>'tombstone','publicId'=>$workspacePublicId,
            'sourceVersion'=>PortalSourceVersion::from(['publicId'=>$workspacePublicId,'active'=>false]),
        ],true);
        $pdo->prepare('UPDATE portal_projection_state SET last_snapshot_hash=NULL WHERE integration_profile_id=? AND workspace_public_id=?')->execute([$profileId,$workspacePublicId]);
        $pdo->prepare("DELETE FROM portal_projection_resource_state WHERE integration_profile_id=? AND workspace_public_id=? AND route_type='portal'")->execute([$profileId,$workspacePublicId]);
        return$payload;
    }

    /**
     * Every outbox producer and profile contract mutation takes this same row
     * lock. The caller must own a transaction so the lock spans the enqueue or
     * key/route update rather than being released after this statement.
     *
     * @return array<string,mixed>
     */
    public static function lockProfileContract(PDO $pdo,int $profileId):array
    {
        if(!$pdo->inTransaction())throw new DomainException('portal-outbox-transaction-required');
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $statement=$pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=?'.$suffix);$statement->execute([$profileId]);$profile=$statement->fetch(PDO::FETCH_ASSOC);
        if(!$profile)throw new DomainException('Integration profile not found.');
        return$profile;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function workspaceProjection(PDO $pdo, array $workspace, int $schemaVersion): array
    {
        $rootType=(string)$workspace['root_type']; $rootId=(string)$workspace['root_public_id'];
        $entities=[]; $relations=[]; $lifecycles=[]; $contactAssignments=[]; $scopeIds=['workspace'=>[(string)$workspace['public_id']]];
        if ($rootType==='organization') {
            $org=$this->one($pdo,'SELECT public_id,name,source_version FROM organizations WHERE public_id=?',[$rootId]);
            if(!$org) throw new DomainException('portal-workspace-root-missing');
            $entities[]=$this->entity('organization',$org,null,false);
            $scopeIds['organization']=[$rootId];
            $departments=$this->all($pdo,'SELECT public_id,name,source_version FROM organization_departments WHERE organization_id=(SELECT id FROM organizations WHERE public_id=?) ORDER BY public_id',[$rootId]);
            foreach($departments as $row){$entities[]=$this->entity('department',$row,$rootId,false);$scopeIds['department'][]=(string)$row['public_id'];}
            $clients=$this->all($pdo,'SELECT public_id,name,source_version,id FROM clients WHERE organization_id=(SELECT id FROM organizations WHERE public_id=?) AND archived=0 AND deleted_at IS NULL ORDER BY public_id',[$rootId]);
            foreach($clients as $row){$entities[]=$this->entity('client',$row,$rootId,false);$scopeIds['client'][]=(string)$row['public_id'];}
            if($schemaVersion<4){
                $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
                $pdo->prepare($insert.' INTO portal_v2_contacts (client_id,display_name) SELECT DISTINCT c.id,c.name FROM organization_department_contacts dc JOIN clients c ON c.id=dc.client_id JOIN organization_departments d ON d.id=dc.department_id JOIN organizations o ON o.id=d.organization_id WHERE o.public_id=?')->execute([$rootId]);
                if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE portal_v2_contacts SET display_name=(SELECT c.name FROM clients c WHERE c.id=portal_v2_contacts.client_id),active=1 WHERE client_id IN (SELECT c.id FROM clients c WHERE c.organization_id=(SELECT id FROM organizations WHERE public_id=?))')->execute([$rootId]);
                else$pdo->prepare('UPDATE portal_v2_contacts pc JOIN clients c ON c.id=pc.client_id SET pc.display_name=c.name,pc.active=1 WHERE c.organization_id=(SELECT id FROM organizations WHERE public_id=?)')->execute([$rootId]);
                $contacts=$this->all($pdo,'SELECT pc.public_id,pc.display_name,pc.source_version,pc.active,MIN(d.public_id) parent_public_id,MAX(dc.is_primary) primary_contact FROM portal_v2_contacts pc JOIN organization_department_contacts dc ON dc.client_id=pc.client_id JOIN organization_departments d ON d.id=dc.department_id JOIN organizations o ON o.id=d.organization_id WHERE o.public_id=? AND pc.active=1 GROUP BY pc.public_id,pc.display_name,pc.source_version,pc.active ORDER BY pc.public_id',[$rootId]);
                foreach($contacts as$row){$contact=['type'=>'contact','publicId'=>(string)$row['public_id'],'parentPublicId'=>(string)$row['parent_public_id'],'displayName'=>(string)$row['display_name'],'active'=>(bool)$row['active'],'primaryContact'=>(bool)$row['primary_contact']];$entities[]=['type'=>$contact['type'],'publicId'=>$contact['publicId'],'parentPublicId'=>$contact['parentPublicId'],'displayName'=>$contact['displayName'],'sourceVersion'=>PortalSourceVersion::from($contact),'active'=>$contact['active'],'primaryContact'=>$contact['primaryContact']];}
            }
            $projects=$this->all($pdo,"SELECT p.public_id,p.name,p.source_version,p.status,p.completed_at,p.department_id,p.client_id,d.public_id department_public_id,c.public_id client_public_id FROM projects p LEFT JOIN organization_departments d ON d.id=p.department_id LEFT JOIN clients c ON c.id=p.client_id WHERE p.organization_id=(SELECT id FROM organizations WHERE public_id=?) AND p.status<>'cancelled' ORDER BY p.public_id",[$rootId]);
        } else {
            $client=$this->one($pdo,'SELECT public_id,name,source_version,id FROM clients WHERE public_id=? AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL',[$rootId]);
            if(!$client) throw new DomainException('portal-workspace-root-missing');
            $entities[]=$this->entity('standalone_client',$client,null,false);$scopeIds['standalone_client']=[$rootId];$scopeIds['client']=[$rootId];
            $clients=[$client];$projects=$this->all($pdo,"SELECT p.public_id,p.name,p.source_version,p.status,p.completed_at,p.department_id,p.client_id,NULL department_public_id,? client_public_id FROM projects p WHERE p.client_id=? AND p.status<>'cancelled' ORDER BY p.public_id",[$rootId,(int)$client['id']]);
        }
        foreach($projects as $row){
            $parent=(string)($row['department_public_id']?:($row['client_public_id']?:$rootId));
            $entities[]=$this->entity('project',$row,$parent,false);$scopeIds['project'][]=(string)$row['public_id'];
            $status=(string)$row['status']==='completed'?'completed':'active';if($status==='completed'&&empty($row['completed_at']))throw new DomainException('portal-project-completed-at-missing');
            $lifecycle=['projectPublicId'=>(string)$row['public_id'],'status'=>$status,'completedAt'=>$status==='completed'?$this->utc($row['completed_at']):null];$lifecycles[]=['projectPublicId'=>$lifecycle['projectPublicId'],'status'=>$lifecycle['status'],'completedAt'=>$lifecycle['completedAt'],'sourceVersion'=>PortalSourceVersion::from($lifecycle)];
        }
        if($schemaVersion>=3){$entityMap=[];foreach($entities as$entity)$entityMap[(string)$entity['type'].'|'.(string)$entity['publicId']]=true;foreach($this->all($pdo,'SELECT * FROM portal_v2_relations WHERE active=1 ORDER BY public_id',[])as$row){if(!isset($entityMap[(string)$row['from_type'].'|'.(string)$row['from_public_id']],$entityMap[(string)$row['to_type'].'|'.(string)$row['to_public_id']]))continue;$relation=['publicId'=>(string)$row['public_id'],'relationType'=>(string)$row['relation_type'],'from'=>['type'=>(string)$row['from_type'],'publicId'=>(string)$row['from_public_id']],'to'=>['type'=>(string)$row['to_type'],'publicId'=>(string)$row['to_public_id']],'active'=>true];$relations[]=['publicId'=>$relation['publicId'],'relationType'=>$relation['relationType'],'from'=>$relation['from'],'to'=>$relation['to'],'sourceVersion'=>PortalSourceVersion::from($relation),'active'=>$relation['active']];}}
        if($schemaVersion===4){$contactProjection=$this->contactAssignmentProjection($pdo,$rootType,$rootId);foreach($contactProjection['entities']as$record)$entities[]=$record;foreach($contactProjection['relations']as$record)$relations[]=$record;$contactAssignments=$contactProjection['contactAssignments'];}
        $entitlements=[];$principalIds=[];
        foreach($scopeIds as $scopeType=>$ids){foreach(array_unique($ids??[]) as $scopeId){
            $rows=$this->all($pdo,'SELECT e.*,p.public_id principal_public_id FROM portal_v2_entitlements e JOIN portal_principals p ON p.id=e.portal_principal_id WHERE e.scope_type=? AND e.scope_public_id=? AND e.active=1 AND (e.valid_from IS NULL OR e.valid_from<=CURRENT_TIMESTAMP) AND (e.expires_at IS NULL OR e.expires_at>CURRENT_TIMESTAMP) AND p.enabled=1 AND p.revoked_at IS NULL',[$scopeType,$scopeId]);
            foreach($rows as $row){$principalIds[(int)$row['portal_principal_id']]=true;$visible=['publicId'=>(string)$row['public_id'],'principalPublicId'=>(string)$row['principal_public_id'],'capability'=>(string)$row['capability'],'effect'=>(string)$row['effect'],'scopeType'=>(string)$row['scope_type'],'scopePublicId'=>(string)$row['scope_public_id'],'active'=>(bool)$row['active'],'validFrom'=>$this->utc($row['valid_from']),'expiresAt'=>$this->utc($row['expires_at'])];$entitlements[]=['publicId'=>$visible['publicId'],'principalPublicId'=>$visible['principalPublicId'],'capability'=>$visible['capability'],'effect'=>$visible['effect'],'scopeType'=>$visible['scopeType'],'scopePublicId'=>$visible['scopePublicId'],'sourceVersion'=>PortalSourceVersion::from($visible),'active'=>$visible['active'],'validFrom'=>$visible['validFrom'],'expiresAt'=>$visible['expiresAt']];}
        }}
        // Client associations publish invitation/eligibility intent only. They
        // deliberately do not create an entitlement or bind an external identity.
        foreach($clients as$client){$linked=$this->all($pdo,'SELECT p.id FROM portal_principal_clients pc JOIN portal_principals p ON p.id=pc.portal_principal_id WHERE pc.client_id=? AND p.enabled=1 AND p.revoked_at IS NULL',[(int)$client['id']]);foreach($linked as$row)$principalIds[(int)$row['id']]=true;}
        $principals=[];foreach(array_keys($principalIds) as $id){$row=$this->one($pdo,'SELECT * FROM portal_principals WHERE id=?',[$id]);if($row){$visible=['publicId'=>(string)$row['public_id'],'emailHint'=>(string)($row['email_hint']??''),'displayName'=>(string)($row['display_name']??''),'active'=>(bool)$row['enabled']&&empty($row['revoked_at'])];$principals[]=['publicId'=>$visible['publicId'],'emailHint'=>$visible['emailHint'],'displayName'=>$visible['displayName'],'sourceVersion'=>PortalSourceVersion::from($visible),'active'=>$visible['active']];}}
        $projection=['entities'=>$entities,'principals'=>$principals,'entitlements'=>$entitlements,'relations'=>$schemaVersion>=3?$relations:[],'projectLifecycles'=>$schemaVersion>=3?$lifecycles:[]];
        if($schemaVersion===4)$projection['contactAssignments']=$contactAssignments;
        return$projection;
    }

    /**
     * Build the schema-v4 informational contact directory from explicit Alpha
     * assignments only. No row produced here is an identity, membership,
     * entitlement, notification recipient, or grant.
     *
     * @return array{entities:list<array<string,mixed>>,relations:list<array<string,mixed>>,contactAssignments:list<array<string,mixed>>}
     */
    private function contactAssignmentProjection(PDO $pdo,string $rootType,string $rootId):array
    {
        $rows=[];
        if($rootType==='organization'){
            $organization=$this->one($pdo,'SELECT id FROM organizations WHERE public_id=?',[$rootId]);
            if(!$organization)throw new DomainException('portal-workspace-root-missing');
            $organizationId=(int)$organization['id'];
            $invalidDepartment=$this->one($pdo,'SELECT COUNT(*) total FROM organization_department_contacts dc JOIN organization_departments d ON d.id=dc.department_id JOIN clients c ON c.id=dc.client_id WHERE d.organization_id=? AND (c.organization_id IS NULL OR c.organization_id<>?)',[$organizationId,$organizationId]);
            $invalidProject=$this->one($pdo,"SELECT COUNT(*) total FROM project_clients pc JOIN projects p ON p.id=pc.project_id JOIN clients c ON c.id=pc.client_id WHERE p.organization_id=? AND p.status<>'cancelled' AND (c.organization_id IS NULL OR c.organization_id<>?)",[$organizationId,$organizationId]);
            if((int)($invalidDepartment['total']??0)>0||(int)($invalidProject['total']??0)>0)throw new DomainException('portal-contact-assignment-root-mismatch');
            $rows=array_merge(
                $this->all($pdo,"SELECT 'department' scope_type,d.public_id scope_public_id,dc.role,dc.is_primary primary_contact,0 primary_billing,0 send_project_invoices,0 can_view_invoice_links,c.id client_id,c.public_id client_public_id,c.name display_name FROM organization_department_contacts dc JOIN organization_departments d ON d.id=dc.department_id JOIN clients c ON c.id=dc.client_id WHERE d.organization_id=? AND c.organization_id=? AND c.archived=0 AND c.deleted_at IS NULL ORDER BY d.public_id,c.public_id",[$organizationId,$organizationId]),
                $this->all($pdo,"SELECT 'project' scope_type,p.public_id scope_public_id,pc.role,0 primary_contact,pc.is_primary_billing primary_billing,pc.send_project_invoices,pc.can_view_invoice_links,c.id client_id,c.public_id client_public_id,c.name display_name FROM project_clients pc JOIN projects p ON p.id=pc.project_id JOIN clients c ON c.id=pc.client_id WHERE p.organization_id=? AND p.status<>'cancelled' AND c.organization_id=? AND c.archived=0 AND c.deleted_at IS NULL ORDER BY p.public_id,c.public_id",[$organizationId,$organizationId])
            );
        }else{
            $client=$this->one($pdo,'SELECT id FROM clients WHERE public_id=? AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL',[$rootId]);
            if(!$client)throw new DomainException('portal-workspace-root-missing');
            $clientId=(int)$client['id'];
            $invalid=$this->one($pdo,"SELECT COUNT(*) total FROM project_clients pc JOIN projects p ON p.id=pc.project_id WHERE p.client_id=? AND p.status<>'cancelled' AND (p.organization_id IS NOT NULL OR pc.client_id<>?)",[$clientId,$clientId]);
            if((int)($invalid['total']??0)>0)throw new DomainException('portal-contact-assignment-root-mismatch');
            $rows=$this->all($pdo,"SELECT 'project' scope_type,p.public_id scope_public_id,pc.role,0 primary_contact,pc.is_primary_billing primary_billing,pc.send_project_invoices,pc.can_view_invoice_links,c.id client_id,c.public_id client_public_id,c.name display_name FROM project_clients pc JOIN projects p ON p.id=pc.project_id JOIN clients c ON c.id=pc.client_id WHERE p.client_id=? AND p.organization_id IS NULL AND p.status<>'cancelled' AND pc.client_id=? AND c.organization_id IS NULL AND c.archived=0 AND c.deleted_at IS NULL ORDER BY p.public_id,c.public_id",[$clientId,$clientId]);
        }

        usort($rows,static fn(array$a,array$b):int=>[(string)$a['scope_type'],(string)$a['scope_public_id'],(string)$a['client_public_id']]<=>[(string)$b['scope_type'],(string)$b['scope_public_id'],(string)$b['client_public_id']]);
        $contacts=[];$relations=[];$assignments=[];
        foreach($rows as$row){
            $contactPublicId=$this->ensurePortalContact($pdo,(int)$row['client_id'],(string)$row['client_public_id'],(string)$row['display_name']);
            $scopeType=(string)$row['scope_type'];$scopePublicId=(string)$row['scope_public_id'];$clientPublicId=(string)$row['client_public_id'];
            $primary=(bool)$row['primary_contact'];$primaryBilling=(bool)$row['primary_billing'];$sendInvoices=(bool)$row['send_project_invoices'];$viewInvoices=(bool)$row['can_view_invoice_links'];
            $assignment=[
                'publicId'=>$this->stablePublicId('caa',$scopeType,$scopePublicId,$contactPublicId),
                'contactPublicId'=>$contactPublicId,'clientPublicId'=>$clientPublicId,
                'scopeType'=>$scopeType,'scopePublicId'=>$scopePublicId,'role'=>$this->contactRole((string)$row['role']),
                'primary'=>$primary,'primaryBilling'=>$primaryBilling,'sendProjectInvoices'=>$sendInvoices,
                'canViewInvoiceLinks'=>$viewInvoices,'active'=>true,
            ];
            $assignment['sourceVersion']=PortalSourceVersion::from($assignment);
            $assignments[]=$assignment;
            $contact=$contacts[$contactPublicId]??[
                'type'=>'contact','publicId'=>$contactPublicId,'parentPublicId'=>$clientPublicId,
                'displayName'=>(string)$row['display_name'],'active'=>true,'primaryContact'=>false,
            ];
            $contact['primaryContact']=$contact['primaryContact']||$primary;
            $contacts[$contactPublicId]=$contact;
            $relation=[
                'publicId'=>$this->stablePublicId('car',$scopeType,$scopePublicId,$contactPublicId),
                'relationType'=>'contact_assignment','from'=>['type'=>$scopeType,'publicId'=>$scopePublicId],
                'to'=>['type'=>'contact','publicId'=>$contactPublicId],'active'=>true,
            ];
            $relation['sourceVersion']=PortalSourceVersion::from($relation);$relations[]=$relation;
        }
        ksort($contacts);$entities=[];
        foreach($contacts as$contact){$contact['sourceVersion']=PortalSourceVersion::from($contact);$sourceVersion=$contact['sourceVersion'];unset($contact['sourceVersion']);$entities[]=['type'=>$contact['type'],'publicId'=>$contact['publicId'],'parentPublicId'=>$contact['parentPublicId'],'displayName'=>$contact['displayName'],'sourceVersion'=>$sourceVersion,'active'=>$contact['active'],'primaryContact'=>$contact['primaryContact']];}
        usort($relations,static fn(array$a,array$b):int=>strcmp((string)$a['publicId'],(string)$b['publicId']));
        usort($assignments,static fn(array$a,array$b):int=>strcmp((string)$a['publicId'],(string)$b['publicId']));
        return['entities'=>$entities,'relations'=>$relations,'contactAssignments'=>$assignments];
    }

    private function ensurePortalContact(PDO$pdo,int$clientId,string$clientPublicId,string$displayName):string
    {
        $row=$this->one($pdo,'SELECT public_id FROM portal_v2_contacts WHERE client_id=?',[$clientId]);$publicId=(string)($row['public_id']??'');
        if($publicId===''){$publicId=bin2hex(random_bytes(16));$insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';$pdo->prepare($insert.' INTO portal_v2_contacts(public_id,client_id,display_name,source_version,active)VALUES(?,?,?,?,1)')->execute([$publicId,$clientId,$displayName,PortalSourceVersion::from(['clientPublicId'=>$clientPublicId,'displayName'=>$displayName,'active'=>true])]);$row=$this->one($pdo,'SELECT public_id FROM portal_v2_contacts WHERE client_id=?',[$clientId]);$publicId=(string)($row['public_id']??$publicId);}
        $pdo->prepare('UPDATE portal_v2_contacts SET display_name=?,source_version=?,active=1 WHERE client_id=?')->execute([$displayName,PortalSourceVersion::from(['clientPublicId'=>$clientPublicId,'displayName'=>$displayName,'active'=>true]),$clientId]);return$publicId;
    }

    private function stablePublicId(string$prefix,string$scopeType,string$scopePublicId,string$contactPublicId):string{return$prefix.'_'.hash('sha256',$scopeType."\0".$scopePublicId."\0".$contactPublicId);}
    private function contactRole(string$role):string{$role=strtolower(trim($role));$role=(string)preg_replace('/[^a-z0-9_.:-]+/','_',$role);$role=trim($role,'_.:-');if($role===''||preg_match('/^[a-z]/D',$role)!==1)$role='contact'.($role!==''?'_'.$role:'');return substr($role,0,50);}
    /** @param array<string,mixed> $profile */
    private function portalSchemaVersion(array$profile):int{return!empty($profile['contact_assignment_projection_enabled'])?4:(!empty($profile['relation_projection_enabled'])?3:2);}

    /** @return array<string,array{resource:string,publicId:string,sourceVersion:string,record:array<string,mixed>}> */
    private function portalResourceRecords(array$workspace,array$projection,int$schemaVersion):array
    {
        $workspaceRecord=['publicId'=>(string)$workspace['public_id'],'rootType'=>(string)$workspace['root_type'],'rootPublicId'=>(string)$workspace['root_public_id'],'displayName'=>(string)$workspace['display_name'],'sourceVersion'=>PortalSourceVersion::from(['publicId'=>(string)$workspace['public_id'],'rootType'=>(string)$workspace['root_type'],'rootPublicId'=>(string)$workspace['root_public_id'],'displayName'=>(string)$workspace['display_name'],'active'=>true]),'active'=>true];
        $records=[];$this->addResourceRecord($records,'workspace',(string)$workspaceRecord['publicId'],(string)$workspaceRecord['sourceVersion'],$workspaceRecord);
        foreach($projection['entities']as$record)$this->addResourceRecord($records,'entity',(string)$record['publicId'],(string)$record['sourceVersion'],$record);
        foreach($projection['principals']as$record)$this->addResourceRecord($records,'principal',(string)$record['publicId'],(string)$record['sourceVersion'],$record);
        foreach($projection['entitlements']as$record)$this->addResourceRecord($records,'entitlement',(string)$record['publicId'],(string)$record['sourceVersion'],$record);
        if($schemaVersion>=3){foreach($projection['relations']as$record)$this->addResourceRecord($records,'relation',(string)$record['publicId'],(string)$record['sourceVersion'],$record);foreach($projection['projectLifecycles']as$record)$this->addResourceRecord($records,'project_lifecycle',(string)$record['projectPublicId'],(string)$record['sourceVersion'],$record);}
        if($schemaVersion===4)foreach($projection['contactAssignments']as$record)$this->addResourceRecord($records,'contact_assignment',(string)$record['publicId'],(string)$record['sourceVersion'],$record);
        return$records;
    }

    /** @param list<array<string,mixed>> $items @return array<string,array{resource:string,publicId:string,sourceVersion:string,record:array<string,mixed>}> */
    private function catalogResourceRecords(array$items):array{$records=[];foreach($items as$record)$this->addResourceRecord($records,'catalog_item',(string)$record['publicId'],(string)$record['sourceVersion'],$record);return$records;}

    /** @param array<string,array{resource:string,publicId:string,sourceVersion:string,record:array<string,mixed>}> $records @param array<string,mixed> $record */
    private function addResourceRecord(array&$records,string$resource,string$publicId,string$sourceVersion,array$record):void{$records[$resource.'|'.$publicId]=['resource'=>$resource,'publicId'=>$publicId,'sourceVersion'=>$sourceVersion,'record'=>$record];}

    /**
     * @param array<string,array{resource:string,publicId:string,sourceVersion:string,record:array<string,mixed>}> $current
     * @return list<array{action:string,resource:string,publicId:string,sourceVersion:string,record:array<string,mixed>,existed:bool}>
     */
    private function resourceChanges(PDO$pdo,int$profileId,string$workspaceId,string$route,array$current):array
    {
        $statement=$pdo->prepare('SELECT resource_type,resource_public_id,source_version,payload_hash,record_json FROM portal_projection_resource_state WHERE integration_profile_id=? AND workspace_public_id=? AND route_type=?');$statement->execute([$profileId,$workspaceId,$route]);$existing=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)as$row)$existing[(string)$row['resource_type'].'|'.(string)$row['resource_public_id']]=$row;
        $changes=[];foreach($current as$key=>$entry){$hash=hash('sha256',self::canonicalJson($entry['record']));$prior=$existing[$key]??null;if($prior&&hash_equals((string)$prior['payload_hash'],$hash)){unset($existing[$key]);continue;}if($prior&&hash_equals((string)$prior['source_version'],$entry['sourceVersion']))throw new DomainException('portal-source-version-reuse');$changes[]=['action'=>'upsert','existed'=>$prior!==null]+$entry;unset($existing[$key]);}
        foreach($existing as$row){$resource=(string)$row['resource_type'];$publicId=(string)$row['resource_public_id'];$version=PortalSourceVersion::from(['resource'=>$resource,'publicId'=>$publicId,'active'=>false,'previousSourceVersion'=>(string)$row['source_version']]);$changes[]=['action'=>'tombstone','resource'=>$resource,'publicId'=>$publicId,'sourceVersion'=>$version,'record'=>[],'existed'=>true];}
        $upsertOrder=['workspace'=>0,'entity'=>1,'principal'=>2,'entitlement'=>3,'relation'=>4,'project_lifecycle'=>5,'contact_assignment'=>6,'catalog_item'=>0];$tombstoneOrder=['contact_assignment'=>0,'relation'=>1,'entitlement'=>2,'project_lifecycle'=>3,'principal'=>4,'entity'=>5,'workspace'=>6,'catalog_item'=>0];
        usort($changes,static function(array$a,array$b)use($upsertOrder,$tombstoneOrder):int{$aOrder=($a['action']==='upsert'?$upsertOrder:$tombstoneOrder)[$a['resource']]??99;$bOrder=($b['action']==='upsert'?$upsertOrder:$tombstoneOrder)[$b['resource']]??99;return[$a['action']==='tombstone'?0:1,$aOrder,$a['resource'],$a['publicId']]<=>[$b['action']==='tombstone'?0:1,$bOrder,$b['resource'],$b['publicId']];});return$changes;
    }

    /** @param list<array<string,mixed>> $changes */
    private function contactTopologyChanged(array$changes,?string$onlyAction):bool
    {
        foreach($changes as$change){
            if(($change['resource']??null)!=='contact_assignment')continue;
            if($onlyAction!==null&&($change['action']??null)!==$onlyAction)continue;
            if(($change['action']??null)==='tombstone'||empty($change['existed']))return true;
        }
        return false;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function portalUpsertEvent(string$resource,array$record):array{$field=match($resource){'workspace'=>'workspace','entity'=>'entity','principal'=>'principal','entitlement'=>'entitlement','relation'=>'relation','project_lifecycle'=>'projectLifecycle','contact_assignment'=>'contactAssignment',default=>throw new DomainException('portal-event-resource-invalid')};return['resource'=>$resource,'action'=>'upsert',$field=>$record];}

    /** @param array<string,array{resource:string,publicId:string,sourceVersion:string,record:array<string,mixed>}> $records */
    private function replaceResourceState(PDO$pdo,int$profileId,string$workspaceId,string$route,array$records):void{$pdo->prepare('DELETE FROM portal_projection_resource_state WHERE integration_profile_id=? AND workspace_public_id=? AND route_type=?')->execute([$profileId,$workspaceId,$route]);foreach($records as$entry)$this->saveResourceState($pdo,$profileId,$workspaceId,$route,$entry['resource'],$entry['publicId'],$entry['sourceVersion'],$entry['record']);}

    /** @param array<string,mixed> $record */
    private function saveResourceState(PDO$pdo,int$profileId,string$workspaceId,string$route,string$resource,string$publicId,string$sourceVersion,array$record):void
    {
        $json=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hash=hash('sha256',self::canonicalJson($record));$update=$pdo->prepare('UPDATE portal_projection_resource_state SET source_version=?,payload_hash=?,record_json=? WHERE integration_profile_id=? AND workspace_public_id=? AND route_type=? AND resource_type=? AND resource_public_id=?');$update->execute([$sourceVersion,$hash,$json,$profileId,$workspaceId,$route,$resource,$publicId]);
        if($update->rowCount()===0)$pdo->prepare('INSERT INTO portal_projection_resource_state (integration_profile_id,workspace_public_id,route_type,resource_type,resource_public_id,source_version,payload_hash,record_json) VALUES (?,?,?,?,?,?,?,?)')->execute([$profileId,$workspaceId,$route,$resource,$publicId,$sourceVersion,$hash,$json]);
    }

    private function deleteResourceState(PDO$pdo,int$profileId,string$workspaceId,string$route,string$resource,string$publicId):void{$pdo->prepare('DELETE FROM portal_projection_resource_state WHERE integration_profile_id=? AND workspace_public_id=? AND route_type=? AND resource_type=? AND resource_public_id=?')->execute([$profileId,$workspaceId,$route,$resource,$publicId]);}

    private function nextSequence(PDO $pdo,int $profileId,string $workspaceId,string $generation):int
    {
        $state=$this->stateForUpdate($pdo,$profileId,$workspaceId);$sequence=$state?(int)$state['source_sequence']+1:1;
        if($state)$pdo->prepare('UPDATE portal_projection_state SET source_generation=?,source_sequence=?,last_snapshot_hash=NULL WHERE integration_profile_id=? AND workspace_public_id=?')->execute([$generation,$sequence,$profileId,$workspaceId]);
        else $pdo->prepare('INSERT INTO portal_projection_state (integration_profile_id,workspace_public_id,source_generation,source_sequence) VALUES (?,?,?,?)')->execute([$profileId,$workspaceId,$generation,$sequence]);
        return $sequence;
    }
    private function stateForUpdate(PDO $pdo,int $profileId,string $workspaceId):array|false{$suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT * FROM portal_projection_state WHERE integration_profile_id=? AND workspace_public_id=?'.$suffix);$s->execute([$profileId,$workspaceId]);return $s->fetch(PDO::FETCH_ASSOC);}
    private function enqueue(PDO $pdo,array $profile,string $workspaceId,int $schema,int $sequence,string $kind,string $route,array $payload,bool$isRevocation=false):void{$destination=$route==='catalog'?($profile['catalog_route']??null):($profile['portal_route']??null);$pdo->prepare('INSERT INTO portal_projection_outbox (integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([(int)$profile['id'],$payload['deliveryId'],$workspaceId,$schema,$sequence,$kind,$route,$isRevocation?1:0,$destination?:null,$profile['delivery_key_id']??null,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private function entity(string $type,array $row,?string $parent,bool $primary):array{$visible=['type'=>$type,'publicId'=>(string)$row['public_id'],'parentPublicId'=>$parent,'displayName'=>(string)$row['name'],'active'=>true,'primaryContact'=>$primary];return['type'=>$visible['type'],'publicId'=>$visible['publicId'],'parentPublicId'=>$visible['parentPublicId'],'displayName'=>$visible['displayName'],'sourceVersion'=>PortalSourceVersion::from($visible),'active'=>$visible['active'],'primaryContact'=>$visible['primaryContact']];}
    private function one(PDO $pdo,string $sql,array $params):array|false{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetch(PDO::FETCH_ASSOC);}
    private function all(PDO $pdo,string $sql,array $params):array{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function utc(mixed $value):?string{if($value===null||$value==='')return null;return(new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');}
    private static function canonicalJson(array $value):string{$sort=function(&$v)use(&$sort){if(!is_array($v))return;if(array_is_list($v)){foreach($v as &$x)$sort($x);unset($x);return;}ksort($v);foreach($v as &$x)$sort($x);unset($x);};$sort($value);return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
    private static function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');}
}
