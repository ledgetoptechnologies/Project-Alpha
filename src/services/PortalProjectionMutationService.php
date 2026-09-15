<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Transactional fan-out used by authoritative PA mutations. Caller owns the transaction. */
final class PortalProjectionMutationService
{
    public function queueProject(PDO $pdo,int $projectId):void
    {
        $this->afterMutation($pdo,$this->projectScopes($pdo,$projectId));
    }

    public function queueOrganization(PDO $pdo,int $organizationId):void
    {
        $this->afterMutation($pdo,$this->organizationScopes($pdo,$organizationId));
    }

    public function queueClient(PDO $pdo,int $clientId):void
    {
        $this->afterMutation($pdo,$this->clientScopes($pdo,$clientId));
    }

    /** @param list<string> $workspaceIds */
    public function queueWorkspaceIds(PDO $pdo, array $workspaceIds): void
    {
        $this->requireTransaction($pdo);
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): string => trim((string)$id),
            $workspaceIds
        ))));
        $this->queueWorkspaces($pdo, $ids);
    }

    /** @return list<array{root_type:string,root_public_id:string}> */
    public function organizationScopes(PDO$pdo,int$id):array{$s=$pdo->prepare('SELECT public_id FROM organizations WHERE id=?');$s->execute([$id]);$public=(string)($s->fetchColumn()?:'');return$public!==''?[['root_type'=>'organization','root_public_id'=>$public]]:[];}
    /** @return list<array{root_type:string,root_public_id:string}> */
    public function clientScopes(PDO$pdo,int$id):array{$s=$pdo->prepare('SELECT c.public_id,c.organization_id,o.public_id organization_public_id FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id WHERE c.id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)return[];return!empty($row['organization_id'])&&$row['organization_public_id']?[['root_type'=>'organization','root_public_id'=>(string)$row['organization_public_id']]]:[['root_type'=>'standalone_client','root_public_id'=>(string)$row['public_id']]];}
    /**
     * Capture client roots using current locking reads. Projects and department
     * assignments are locked first because their effective workspace changes
     * when a client is reparented. All callers use the same dependency order.
     *
     * @return list<array{root_type:string,root_public_id:string}>
     */
    public function lockedClientScopes(PDO $pdo, int $id, ?int $targetOrganizationId = null): array
    {
        return $this->lockedClientScopesForIds(
            $pdo,
            [$id],
            $targetOrganizationId === null ? [] : [$targetOrganizationId]
        );
    }

    /**
     * Batch variant for workflows such as onboarding that can mutate either of
     * several clients. Locking all dependent projects before any client avoids
     * the project-B/client-A inversion possible with repeated single calls.
     *
     * @param list<int> $ids
     * @param list<int> $targetOrganizationIds
     * @return list<array{root_type:string,root_public_id:string}>
     */
    public function lockedClientScopesForIds(PDO $pdo, array $ids, array $targetOrganizationIds = []): array
    {
        $this->requireTransaction($pdo);
        $ids = $this->positiveSortedIds($ids);
        if ($ids === []) return [];

        $this->lockRows(
            $pdo,
            'SELECT id FROM projects WHERE client_id IN (%s) ORDER BY id',
            $ids
        );
        $this->lockRows(
            $pdo,
            'SELECT department_id,client_id FROM organization_department_contacts WHERE client_id IN (%s) ORDER BY department_id,client_id',
            $ids
        );
        $clients = $this->lockRows(
            $pdo,
            'SELECT id,public_id,organization_id FROM clients WHERE id IN (%s) ORDER BY id',
            $ids
        );

        $organizationIds = $this->positiveSortedIds(array_merge(
            $targetOrganizationIds,
            array_column($clients, 'organization_id')
        ));
        $organizations = $this->lockEntitiesById($pdo, 'organizations', $organizationIds);
        $organizationPublicIds = [];
        foreach ($organizations as $organization) {
            $organizationPublicIds[(int)$organization['id']] = (string)$organization['public_id'];
        }

        $scopes = [];
        foreach ($clients as $client) {
            $organizationId = (int)($client['organization_id'] ?? 0);
            if ($organizationId > 0 && isset($organizationPublicIds[$organizationId])) {
                $scopes[] = ['root_type'=>'organization','root_public_id'=>$organizationPublicIds[$organizationId]];
            } else {
                $scopes[] = ['root_type'=>'standalone_client','root_public_id'=>(string)$client['public_id']];
            }
        }
        return $this->uniqueScopes($scopes);
    }
    /** @return list<array{root_type:string,root_public_id:string}> */
    public function projectScopes(PDO$pdo,int$id):array{$s=$pdo->prepare('SELECT o.public_id organization_public_id,c.public_id client_public_id,c.organization_id client_organization_id FROM projects p LEFT JOIN organizations o ON o.id=p.organization_id LEFT JOIN clients c ON c.id=p.client_id WHERE p.id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)return[];if($row['organization_public_id'])return[['root_type'=>'organization','root_public_id'=>(string)$row['organization_public_id']]];if($row['client_public_id']&&!$row['client_organization_id'])return[['root_type'=>'standalone_client','root_public_id'=>(string)$row['client_public_id']]];return[];}
    /**
     * Capture a project's current root and lock both its current and intended
     * parents. The optional targets let update handlers close the window in
     * which a destination client could be reparented concurrently.
     *
     * @return list<array{root_type:string,root_public_id:string}>
     */
    public function lockedProjectScopes(
        PDO $pdo,
        int $id,
        ?int $targetClientId = null,
        ?int $targetOrganizationId = null,
        ?int $targetDepartmentId = null
    ): array {
        $this->requireTransaction($pdo);
        $projects = $this->lockRows(
            $pdo,
            'SELECT id,public_id,organization_id,department_id,client_id FROM projects WHERE id IN (%s) ORDER BY id',
            [$id]
        );
        if ($projects === []) return [];
        $project = $projects[0];

        $clientIds = $this->positiveSortedIds([$project['client_id'] ?? null, $targetClientId]);
        $clients = $this->lockEntitiesById($pdo, 'clients', $clientIds, 'id,public_id,organization_id');
        $clientsById = [];
        foreach ($clients as $client) $clientsById[(int)$client['id']] = $client;

        $departmentIds = $this->positiveSortedIds([$project['department_id'] ?? null, $targetDepartmentId]);
        $departments = $this->lockEntitiesById($pdo, 'organization_departments', $departmentIds, 'id,organization_id');

        $organizationIds = [$project['organization_id'] ?? null, $targetOrganizationId];
        foreach ($clients as $client) $organizationIds[] = $client['organization_id'] ?? null;
        foreach ($departments as $department) $organizationIds[] = $department['organization_id'] ?? null;
        $organizations = $this->lockEntitiesById($pdo, 'organizations', $this->positiveSortedIds($organizationIds));
        $organizationPublicIds = [];
        foreach ($organizations as $organization) {
            $organizationPublicIds[(int)$organization['id']] = (string)$organization['public_id'];
        }

        $organizationId = (int)($project['organization_id'] ?? 0);
        if ($organizationId > 0 && isset($organizationPublicIds[$organizationId])) {
            return [['root_type'=>'organization','root_public_id'=>$organizationPublicIds[$organizationId]]];
        }
        $clientId = (int)($project['client_id'] ?? 0);
        $client = $clientsById[$clientId] ?? null;
        if ($client && empty($client['organization_id'])) {
            return [['root_type'=>'standalone_client','root_public_id'=>(string)$client['public_id']]];
        }
        return [];
    }

    /**
     * Reconcile only the supplied roots. Projection and outbox failures bubble
     * to the transaction owner so authoritative mutations cannot commit alone.
     *
     * @param list<array{root_type:string,root_public_id:string}> $scopes
     */
    public function afterMutation(PDO$pdo,array$scopes,bool$force=false):bool
    {
        return $this->reconcileAfterMutation($pdo,$scopes,$force,true);
    }

    /**
     * Reconcile neutral projections without enrolling portal users or roots.
     *
     * This is for externally-created directory records whose authority must be
     * established by a later governed action. It may update relation/contact
     * projections and already-existing workspace outbox state, but it never
     * invokes client provisioning, creates workspaces, principals, memberships,
     * eligibility, access roots, or default entitlements.
     *
     * @param list<array{root_type:string,root_public_id:string}> $scopes
     */
    public function afterMutationProjectionOnly(PDO$pdo,array$scopes,bool$force=false):bool
    {
        return $this->reconcileAfterMutation($pdo,$scopes,$force,false);
    }

    /** @param list<array{root_type:string,root_public_id:string}> $scopes */
    private function reconcileAfterMutation(PDO$pdo,array$scopes,bool$force,bool$provisionPortalAuthority):bool
    {
        if(!$pdo->inTransaction()||$scopes===[])return true;
        if(!$force&&!$this->hooksEnabled($pdo))return true;
        $scopes=$this->uniqueScopes($scopes);
        // Provisioning is an invitation/eligibility projection only.  It must
        // run before the relationship graph and the resulting outbox changes,
        // but it never binds an identity or grants a delivery folder.
        if($provisionPortalAuthority)(new PortalClientProvisioningService())->ensureScopes($pdo,$scopes);
        $this->reconcileRelations($pdo,$scopes);

        // Reparenting can affect more than one workspace. Publish every removal
        // before any addition so a receiver never observes simultaneous access
        // through both the old and new root. These remain ordinary ordered
        // events; control-plane revocations alone may bypass normal retry delay.
        foreach($scopes as$scope)$this->reconcileWorkspace($pdo,$scope,'tombstone',$provisionPortalAuthority);
        foreach($scopes as$scope)$this->reconcileWorkspace($pdo,$scope,'upsert',$provisionPortalAuthority);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function queueCatalog(PDO $pdo,?int $onlyProfileId=null):array
    {
        if(!$pdo->inTransaction())throw new \DomainException('catalog-transaction-required');$sql='SELECT id FROM portal_integration_profiles WHERE enabled=1 AND catalog_projection_enabled=1 AND catalog_route IS NOT NULL';$params=[];if($onlyProfileId!==null){$sql.=' AND id=?';$params[]=$onlyProfileId;}$sql.=' ORDER BY id';$statement=$pdo->prepare($sql);$statement->execute($params);$profileIds=$statement->fetchAll(PDO::FETCH_COLUMN);$summaries=[];$projection=new PortalProjectionService();
        foreach($profileIds as$profileId)$summaries[]=$projection->queueCatalogSnapshot($pdo,['id'=>(int)$profileId]);return$summaries;
    }

    /** @return list<array<string,mixed>> */
    public function queueCatalogChanges(PDO$pdo,?int$onlyProfileId=null):array
    {
        if(!$pdo->inTransaction())throw new \DomainException('catalog-transaction-required');$sql='SELECT id FROM portal_integration_profiles WHERE enabled=1 AND catalog_projection_enabled=1 AND catalog_route IS NOT NULL';$params=[];if($onlyProfileId!==null){$sql.=' AND id=?';$params[]=$onlyProfileId;}$sql.=' ORDER BY id';$statement=$pdo->prepare($sql);$statement->execute($params);$summaries=[];$projection=new PortalProjectionService();foreach($statement->fetchAll(PDO::FETCH_COLUMN)as$profileId)$summaries[]=$projection->queueCatalogChanges($pdo,['id'=>(int)$profileId]);
        // Catalog visibility and assignment visibility are one receiver-facing
        // contract. Reconcile already-published assignment streams before the
        // catalog mutation commits, so unpublished services become tombstones.
        $this->queuePublishedServiceAssignmentChanges($pdo,$onlyProfileId);
        return$summaries;
    }

    private function queuePublishedServiceAssignmentChanges(PDO $pdo, ?int $onlyProfileId): void
    {
        if (!$this->serviceAssignmentProjectionSchemaAvailable($pdo)) return;
        $sql = 'SELECT profile.id FROM portal_integration_profiles profile
                JOIN portal_service_assignment_projection_state state ON state.integration_profile_id=profile.id
                WHERE profile.enabled=1 AND profile.service_assignment_projection_enabled=1
                  AND profile.delivery_enabled=1 AND profile.portal_route IS NOT NULL';
        $parameters = [];
        if ($onlyProfileId !== null) { $sql .= ' AND profile.id=?'; $parameters[] = $onlyProfileId; }
        $sql .= ' ORDER BY profile.id';
        $statement = $pdo->prepare($sql);$statement->execute($parameters);
        $projection = new PortalServiceAssignmentProjectionService();
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $profileId) {
            $projection->queueChanges($pdo,['id'=>(int)$profileId]);
        }
    }

    /** Rolling migrations may briefly have catalog projection without the
     * additive assignment stream. Only schema absence is optional; projection
     * errors after this probe must roll back the caller. */
    private function serviceAssignmentProjectionSchemaAvailable(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT service_assignment_projection_enabled,delivery_enabled,portal_route FROM portal_integration_profiles WHERE 1=0');
            $pdo->query('SELECT public_id,subject_type,subject_public_id,service_public_id FROM portal_service_assignments WHERE 1=0');
            $pdo->query('SELECT integration_profile_id,source_generation,source_sequence,snapshot_hash FROM portal_service_assignment_projection_state WHERE 1=0');
            $pdo->query('SELECT integration_profile_id,assignment_public_id,source_version,payload_hash,record_json FROM portal_service_assignment_projection_records WHERE 1=0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function queueWorkspaces(PDO $pdo,array $workspaceIds,?string$onlyAction=null,bool$includePortalAuthority=true):void
    {
        if($workspaceIds===[])return;
        $profiles=$pdo->prepare('SELECT p.id FROM portal_integration_profiles p JOIN portal_integration_profile_workspaces pw ON pw.profile_id=p.id AND pw.active=1 JOIN portal_v2_workspaces w ON w.id=pw.workspace_id AND w.active=1 WHERE p.enabled=1 AND p.portal_projection_enabled=1 AND w.public_id=? ORDER BY p.id');
        $projection=new PortalProjectionService();
        foreach(array_unique(array_map('strval',$workspaceIds))as$workspaceId){$profiles->execute([$workspaceId]);foreach($profiles->fetchAll(PDO::FETCH_COLUMN)as$profileId){
            if($includePortalAuthority)$projection->queueWorkspaceChanges($pdo,['id'=>(int)$profileId],$workspaceId,$onlyAction);
            else$projection->queueWorkspaceNeutralChanges($pdo,['id'=>(int)$profileId],$workspaceId,$onlyAction);
        }}
    }

    /** @param array{root_type:string,root_public_id:string} $scope */
    private function reconcileWorkspace(PDO$pdo,array$scope,?string$onlyAction=null,bool$includePortalAuthority=true):void
    {
        $workspace=$pdo->prepare('SELECT * FROM portal_v2_workspaces WHERE root_type=? AND root_public_id=?');$workspace->execute([$scope['root_type'],$scope['root_public_id']]);$row=$workspace->fetch(PDO::FETCH_ASSOC);if(!$row)return;
        if($scope['root_type']==='organization')$root=$pdo->prepare('SELECT o.name FROM organizations o WHERE o.public_id=? AND EXISTS(SELECT 1 FROM clients c WHERE c.organization_id=o.id AND c.archived=0 AND c.deleted_at IS NULL)');
        else$root=$pdo->prepare('SELECT name FROM clients WHERE public_id=? AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL');
        $root->execute([$scope['root_public_id']]);$name=$root->fetchColumn();
        $control=$pdo->prepare('SELECT access_state FROM portal_client_access_roots WHERE root_type=? AND root_public_id=?');$control->execute([$scope['root_type'],$scope['root_public_id']]);$accessState=$control->fetchColumn();if($accessState==='revoked')$name=false;
        // A neutral directory create can update an already-established
        // projection, but it must never activate, deactivate, or revoke a
        // workspace. Those authority transitions belong to governed portal
        // enrollment and access-management actions.
        if(!$includePortalAuthority){
            if($name===false||empty($row['active']))return;
            $pdo->prepare('UPDATE portal_v2_workspaces SET display_name=?,source_version=? WHERE id=?')->execute([(string)$name,PortalSourceVersion::from(['publicId'=>(string)$row['public_id'],'rootType'=>$scope['root_type'],'rootPublicId'=>$scope['root_public_id'],'displayName'=>(string)$name,'active'=>true]),(int)$row['id']]);
            $this->queueWorkspaces($pdo,[(string)$row['public_id']],$onlyAction,false);return;
        }
        if($name===false){
            if($onlyAction==='upsert')return;
            $profiles=$pdo->prepare('SELECT p.* FROM portal_integration_profiles p JOIN portal_integration_profile_workspaces pw ON pw.profile_id=p.id AND pw.active=1 WHERE pw.workspace_id=? AND p.enabled=1 AND p.portal_projection_enabled=1 ORDER BY p.id');$profiles->execute([(int)$row['id']]);$projection=new PortalProjectionService();if(!empty($row['active']))foreach($profiles->fetchAll(PDO::FETCH_ASSOC)as$profile)$projection->queueWorkspaceRevocation($pdo,$profile,(string)$row['public_id']);$pdo->prepare('UPDATE portal_v2_workspaces SET active=0,source_version=? WHERE id=?')->execute([PortalSourceVersion::from(['publicId'=>(string)$row['public_id'],'active'=>false]),(int)$row['id']]);$pdo->prepare('UPDATE portal_integration_profile_workspaces SET active=0 WHERE workspace_id=?')->execute([(int)$row['id']]);return;
        }
        $pdo->prepare('UPDATE portal_v2_workspaces SET display_name=?,source_version=?,active=1 WHERE id=?')->execute([(string)$name,PortalSourceVersion::from(['publicId'=>(string)$row['public_id'],'rootType'=>$scope['root_type'],'rootPublicId'=>$scope['root_public_id'],'displayName'=>(string)$name,'active'=>true]),(int)$row['id']]);$this->queueWorkspaces($pdo,[(string)$row['public_id']],$onlyAction);
    }

    /** @param list<array{root_type:string,root_public_id:string}> $scopes */
    private function reconcileRelations(PDO$pdo,array$scopes):void
    {
        $desired=[];$seed=[];$allowedRoots=[];foreach($scopes as$scope){$seed[]=$scope['root_public_id'];$allowedRoots[$scope['root_type'].'|'.$scope['root_public_id']]=true;if($scope['root_type']==='organization'){$this->organizationGraph($pdo,$scope['root_public_id'],$desired,$seed);}else{$this->standaloneGraph($pdo,$scope['root_public_id'],$desired,$seed);}}
        $existing=$this->relationClosure($pdo,array_values(array_unique($seed)),$allowedRoots);foreach($desired as$edge)$this->upsertRelation($pdo,$edge,true);foreach($existing as$row){$key=$this->edgeKey($row);if(!isset($desired[$key]))$this->upsertRelation($pdo,$row,false);}
    }

    /** @param array<string,array<string,string>> $desired @param list<string> $seed */
    private function organizationGraph(PDO$pdo,string$root,array&$desired,array&$seed):void
    {
        $departments=$this->all($pdo,'SELECT d.public_id FROM organization_departments d JOIN organizations o ON o.id=d.organization_id WHERE o.public_id=?',[$root]);$clients=$this->all($pdo,'SELECT c.id,c.public_id,c.name FROM clients c JOIN organizations o ON o.id=c.organization_id WHERE o.public_id=? AND c.archived=0 AND c.deleted_at IS NULL',[$root]);$projects=$this->all($pdo,"SELECT p.public_id,d.public_id department_public_id,c.public_id client_public_id FROM projects p JOIN organizations o ON o.id=p.organization_id LEFT JOIN organization_departments d ON d.id=p.department_id LEFT JOIN clients c ON c.id=p.client_id WHERE o.public_id=? AND p.status<>'cancelled'",[$root]);
        foreach($departments as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','organization',$root,'department',(string)$row['public_id']);}foreach($clients as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','organization',$root,'client',(string)$row['public_id']);$this->syncContact($pdo,(int)$row['id'],(string)$row['name'],false);}
        foreach($projects as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','organization',$root,'project',(string)$row['public_id']);if($row['department_public_id'])$this->addEdge($desired,'contains','department',(string)$row['department_public_id'],'project',(string)$row['public_id']);if($row['client_public_id'])$this->addEdge($desired,'contains','client',(string)$row['client_public_id'],'project',(string)$row['public_id']);}
        $contacts=$this->all($pdo,'SELECT dc.department_id,dc.client_id,d.public_id department_public_id,c.name,pc.public_id contact_public_id FROM organization_department_contacts dc JOIN organization_departments d ON d.id=dc.department_id JOIN organizations o ON o.id=d.organization_id JOIN clients c ON c.id=dc.client_id LEFT JOIN portal_v2_contacts pc ON pc.client_id=c.id WHERE o.public_id=?',[$root]);foreach($contacts as$row){$contact=$this->syncContact($pdo,(int)$row['client_id'],(string)$row['name'],true);$seed[]=$contact;$this->addEdge($desired,'contact_assignment','department',(string)$row['department_public_id'],'contact',$contact);}
    }
    /** @param array<string,array<string,string>> $desired @param list<string> $seed */
    private function standaloneGraph(PDO$pdo,string$root,array&$desired,array&$seed):void{$projects=$this->all($pdo,"SELECT p.public_id FROM projects p JOIN clients c ON c.id=p.client_id WHERE c.public_id=? AND c.organization_id IS NULL AND c.archived=0 AND c.deleted_at IS NULL AND p.status<>'cancelled'",[$root]);foreach($projects as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','standalone_client',$root,'project',(string)$row['public_id']);}}

    private function syncContact(PDO$pdo,int$clientId,string$name,bool$active):string
    {
        $s=$pdo->prepare('SELECT public_id FROM portal_v2_contacts WHERE client_id=?');$s->execute([$clientId]);$public=(string)($s->fetchColumn()?:'');$version=PortalSourceVersion::from(['clientId'=>$clientId,'displayName'=>$name,'active'=>$active]);if($public!==''){$pdo->prepare('UPDATE portal_v2_contacts SET display_name=?,source_version=?,active=? WHERE client_id=?')->execute([$name,$version,$active?1:0,$clientId]);return$public;}$public=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO portal_v2_contacts(public_id,client_id,display_name,source_version,active)VALUES(?,?,?,?,?)')->execute([$public,$clientId,$name,$version,$active?1:0]);return$public;
    }
    /** @param array<string,array<string,string>> $desired */
    private function addEdge(array&$desired,string$type,string$fromType,string$from,string$toType,string$to):void{$edge=['relation_type'=>$type,'from_type'=>$fromType,'from_public_id'=>$from,'to_type'=>$toType,'to_public_id'=>$to];$desired[$this->edgeKey($edge)]=$edge;}
    /** @param array<string,mixed> $edge */
    private function upsertRelation(PDO$pdo,array$edge,bool$active):void{$version=PortalSourceVersion::from(['relationType'=>(string)$edge['relation_type'],'from'=>['type'=>(string)$edge['from_type'],'publicId'=>(string)$edge['from_public_id']],'to'=>['type'=>(string)$edge['to_type'],'publicId'=>(string)$edge['to_public_id']],'active'=>$active]);$sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO portal_v2_relations(relation_type,from_type,from_public_id,to_type,to_public_id,source_version,active)VALUES(?,?,?,?,?,?,?) ON CONFLICT(relation_type,from_type,from_public_id,to_type,to_public_id)DO UPDATE SET source_version=excluded.source_version,active=excluded.active':'INSERT INTO portal_v2_relations(relation_type,from_type,from_public_id,to_type,to_public_id,source_version,active)VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),active=VALUES(active)';$pdo->prepare($sql)->execute([(string)$edge['relation_type'],(string)$edge['from_type'],(string)$edge['from_public_id'],(string)$edge['to_type'],(string)$edge['to_public_id'],$version,$active?1:0]);}
    /** @param array<string,bool> $allowedRoots @return array<string,array<string,mixed>> */
    private function relationClosure(PDO$pdo,array$seed,array$allowedRoots):array
    {
        $seen=array_fill_keys($seed,true);$rows=[];$frontier=$seed;$rootCache=[];
        for($depth=0;$depth<6&&$frontier!==[];$depth++){
            $marks=implode(',',array_fill(0,count($frontier),'?'));$s=$pdo->prepare("SELECT * FROM portal_v2_relations WHERE from_public_id IN ({$marks}) OR to_public_id IN ({$marks}) ORDER BY id LIMIT 5001");$s->execute(array_merge($frontier,$frontier));$found=$s->fetchAll(PDO::FETCH_ASSOC);if(count($found)>5000)throw new \RuntimeException('portal-relation-scope-limit');$next=[];
            foreach($found as$row){$fromType=(string)$row['from_type'];$fromId=(string)$row['from_public_id'];$toType=(string)$row['to_type'];$toId=(string)$row['to_public_id'];if(($this->isRootNode($fromType)&&!isset($allowedRoots[$fromType.'|'.$fromId]))||($this->isRootNode($toType)&&!isset($allowedRoots[$toType.'|'.$toId])))continue;$rows[$this->edgeKey($row)]=$row;
                foreach([[$fromType,$fromId],[$toType,$toId]]as[$type,$id])if(!isset($seen[$id])){$owner=$this->entityRoot($pdo,$type,$id,$rootCache);if($owner!==null&&!isset($allowedRoots[$owner]))continue;$seen[$id]=true;$next[]=$id;}
            }$frontier=array_values(array_unique($next));
        }return$rows;
    }
    /** @param array<string,string|null> $cache */
    private function entityRoot(PDO$pdo,string$type,string$id,array&$cache):?string
    {
        $cacheKey=$type.'|'.$id;if(array_key_exists($cacheKey,$cache))return$cache[$cacheKey];$sql=match($type){
            'organization'=>"SELECT 'organization' root_type,public_id root_public_id FROM organizations WHERE public_id=?",
            'standalone_client'=>"SELECT 'standalone_client' root_type,public_id root_public_id FROM clients WHERE public_id=? AND organization_id IS NULL",
            'department'=>"SELECT 'organization' root_type,o.public_id root_public_id FROM organization_departments d JOIN organizations o ON o.id=d.organization_id WHERE d.public_id=?",
            'client'=>"SELECT CASE WHEN o.public_id IS NULL THEN 'standalone_client' ELSE 'organization' END root_type,COALESCE(o.public_id,c.public_id) root_public_id FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id WHERE c.public_id=?",
            'project'=>"SELECT CASE WHEN o.public_id IS NOT NULL THEN 'organization' WHEN c.organization_id IS NULL THEN 'standalone_client' ELSE NULL END root_type,COALESCE(o.public_id,c.public_id) root_public_id FROM projects p LEFT JOIN organizations o ON o.id=p.organization_id LEFT JOIN clients c ON c.id=p.client_id WHERE p.public_id=?",
            'contact'=>"SELECT CASE WHEN o.public_id IS NULL THEN 'standalone_client' ELSE 'organization' END root_type,COALESCE(o.public_id,c.public_id) root_public_id FROM portal_v2_contacts pc JOIN clients c ON c.id=pc.client_id LEFT JOIN organizations o ON o.id=c.organization_id WHERE pc.public_id=?",
            default=>null};if($sql===null)return$cache[$cacheKey]=null;$s=$pdo->prepare($sql);$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return$cache[$cacheKey]=!$row||empty($row['root_type'])||empty($row['root_public_id'])?null:(string)$row['root_type'].'|'.(string)$row['root_public_id'];
    }
    private function isRootNode(string$type):bool{return in_array($type,['organization','standalone_client'],true);}
    /** @param array<string,mixed> $edge */
    private function edgeKey(array$edge):string{return implode('|',[(string)$edge['relation_type'],(string)$edge['from_type'],(string)$edge['from_public_id'],(string)$edge['to_type'],(string)$edge['to_public_id']]);}
    private function hooksEnabled(PDO$pdo):bool{$s=$pdo->prepare("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='portal_authoritative_hooks_enabled'");$s->execute();return filter_var($s->fetchColumn()?:'0',FILTER_VALIDATE_BOOLEAN);}
    /** @param list<array{root_type:string,root_public_id:string}> $scopes @return list<array{root_type:string,root_public_id:string}> */
    private function uniqueScopes(array$scopes):array{$out=[];foreach($scopes as$scope){$type=(string)($scope['root_type']??'');$id=(string)($scope['root_public_id']??'');if(!in_array($type,['organization','standalone_client'],true)||$id==='')continue;$out[$type.'|'.$id]=['root_type'=>$type,'root_public_id'=>$id];}ksort($out);return array_values($out);}
    private function all(PDO$pdo,string$sql,array$params):array{$s=$pdo->prepare($sql);$s->execute($params);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function requireTransaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new \LogicException('portal-projection-authoritative-lock-requires-transaction');
    }

    /** @param array<int,mixed> $ids @return list<int> */
    private function positiveSortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function lockEntitiesById(PDO $pdo, string $table, array $ids, string $columns = 'id,public_id'): array
    {
        if ($ids === []) return [];
        if (!in_array($table, ['clients','organizations','organization_departments'], true)) {
            throw new \LogicException('portal-projection-invalid-lock-table');
        }
        return $this->lockRows($pdo, "SELECT {$columns} FROM {$table} WHERE id IN (%s) ORDER BY id", $ids);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function lockRows(PDO $pdo, string $sqlTemplate, array $ids): array
    {
        $this->requireTransaction($pdo);
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $statement = $pdo->prepare(sprintf($sqlTemplate, $marks) . $suffix);
        $statement->execute($ids);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}
