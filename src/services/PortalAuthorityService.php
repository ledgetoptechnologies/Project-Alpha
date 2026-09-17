<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

final class PortalAuthorityService
{
    public const MANAGER_CAPABILITIES=['workspace.view','directory.read','request.create','delivery.view','member.manage','delegated_share.create'];

    /** @param array<string,mixed> $input */
    public function saveProfile(PDO $pdo,array $input,int $actorId):int
    {
        $id=max(0,(int)($input['profile_id']??0));$key=ExternalOpsIntegrationService::normalizeApplicationKey((string)($input['application_key']??''));
        $label=trim((string)($input['display_label']??''));if($label===''||mb_strlen($label)>100)throw new DomainException('Integration display label is required and must be at most 100 characters.');
        $pricingSource=$this->nullableAscii($input['pricing_source']??null,100);$draftSource=$this->nullableAscii($input['draft_source']??null,100);
        $serviceAssignmentsRequested=array_key_exists('service_assignment_projection_enabled',$input)?!empty($input['service_assignment_projection_enabled']):null;
        $contactAssignmentsRequested=array_key_exists('contact_assignment_projection_enabled',$input)?!empty($input['contact_assignment_projection_enabled']):null;
        // PDO serializes false in execute() parameter arrays as an empty string.
        // These fields are MySQL integer columns, so normalize every persisted
        // flag to 0/1 before either the INSERT or UPDATE parameter list is built.
        $enabled=(int)!empty($input['enabled']);$portal=(int)!empty($input['portal_projection_enabled']);$relations=(int)!empty($input['relation_projection_enabled']);$contactAssignments=(int)($contactAssignmentsRequested??false);$catalog=(int)!empty($input['catalog_projection_enabled']);$serviceAssignments=(int)($serviceAssignmentsRequested??false);$pricing=(int)!empty($input['pricing_preview_enabled']);$draft=(int)!empty($input['draft_quote_enabled']);
        // A disabled profile may retain its capability intent while portal
        // revocations drain. The dependency must hold whenever the producer is
        // active, but clearing it during retirement would silently lose the
        // contract that must be restored after rotation.
        if($enabled&&$relations&&!$portal)throw new DomainException('Relation projection requires portal projection.');
        if($enabled&&$contactAssignments&&(!$portal||!$relations))throw new DomainException('Contact assignment projection requires portal relation projection.');
        if($enabled&&$serviceAssignments&&!$portal)throw new DomainException('Service assignment projection requires portal projection.');
        if($pricing&&$pricingSource===null)throw new DomainException('Pricing source is required before pricing preview can be enabled.');
        if($draft&&$draftSource===null)throw new DomainException('Draft source is required before draft creation can be enabled.');
        $portalRoute=$this->httpsRoute($input['portal_route']??null);$catalogRoute=$this->httpsRoute($input['catalog_route']??null);
        if($id>0){
            $owns=!$pdo->inTransaction();
            try {
                if($owns)$pdo->beginTransaction();
                $current=PortalProjectionService::lockProfileContract($pdo,$id);
                if($serviceAssignmentsRequested===null)$serviceAssignments=(int)!empty($current['service_assignment_projection_enabled']);
                if($contactAssignmentsRequested===null)$contactAssignments=(int)!empty($current['contact_assignment_projection_enabled']);
                if($enabled&&$serviceAssignments&&!$portal)throw new DomainException('Service assignment projection requires portal projection.');
                if($enabled&&$contactAssignments&&(!$portal||!$relations))throw new DomainException('Contact assignment projection requires portal relation projection.');
                if($enabled&&$portal)$this->assertOnlyPortalProducer($pdo,$id);
                $revoking=!empty($current['enabled'])&&!empty($current['portal_projection_enabled'])&&(!$enabled||!$portal);
                $catalogStopping=!empty($current['enabled'])&&!empty($current['catalog_projection_enabled'])&&(!$enabled||!$catalog);
                $serviceAssignmentsStopping=!empty($current['enabled'])&&!empty($current['service_assignment_projection_enabled'])&&(!$enabled||!$serviceAssignments);
                $routingChanged=!hash_equals((string)$current['application_key'],$key)||($current['portal_route']??null)!==$portalRoute||($current['catalog_route']??null)!==$catalogRoute;
                if(!empty($current['enabled'])&&$routingChanged)throw new DomainException('Disable the integration before changing its application key or receiver routes.');
                if($revoking&&$routingChanged)throw new DomainException('Disable the profile without changing its key or routes so queued revocations retain their delivery contract.');
                if($routingChanged){
                    $pending=$pdo->prepare('SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id=? AND delivered_at IS NULL');
                    $pending->execute([$id]);
                    if((int)$pending->fetchColumn()>0)throw new DomainException('Deliver or administratively resolve pending projection records before changing this profile key or routes.');
                }
                if($revoking)$this->supersedePendingNormalRows($pdo,$id,'portal');
                if($catalogStopping)$this->supersedePendingNormalRows($pdo,$id,'catalog');
                if($serviceAssignmentsStopping){
                    $this->supersedePendingNormalRows($pdo,$id,'service_assignments');
                    if(!empty($current['delivery_enabled'])&&trim((string)($current['portal_route']??''))!==''){
                        (new PortalServiceAssignmentProjectionService())->queueRevocationSnapshot($pdo,$current,'disable-'.bin2hex(random_bytes(16)));
                    }
                }
                $revoked=$revoking?$this->queueProfileRevocations($pdo,$current):0;
                $oldSchema=!empty($current['contact_assignment_projection_enabled'])?4:(!empty($current['relation_projection_enabled'])?3:2);$newSchema=$contactAssignments?4:($relations?3:2);
                if($oldSchema===4&&$newSchema<4)$this->supersedeUnclaimedPortalSchemaRows($pdo,$id,4);
                $pdo->prepare('UPDATE portal_integration_profiles SET application_key=?,display_label=?,enabled=?,portal_projection_enabled=?,relation_projection_enabled=?,contact_assignment_projection_enabled=?,catalog_projection_enabled=?,service_assignment_projection_enabled=?,pricing_preview_enabled=?,draft_quote_enabled=?,pricing_source=?,draft_source=?,portal_route=?,catalog_route=?,updated_by=? WHERE id=?')
                    ->execute([$key,$label,$enabled,$portal,$relations,$contactAssignments,$catalog,$serviceAssignments,$pricing,$draft,$pricingSource,$draftSource,$portalRoute,$catalogRoute,$actorId>0?$actorId:null,$id]);
                if(!empty($current['enabled'])&&!empty($current['portal_projection_enabled'])&&$enabled&&$portal&&$oldSchema!==$newSchema)$this->queueReplacementWorkspaceSnapshots($pdo,$id);
                if($revoking)$this->audit($pdo,$id,null,'portal.profile.revocation_queued','profile',(string)$id,['workspace_count'=>$revoked,'actor_id'=>$actorId]);
                if($owns)$pdo->commit();
                return$id;
            } catch(Throwable$e) {
                if($owns&&$pdo->inTransaction())$pdo->rollBack();
                throw$e;
            }
        }
        $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();if($enabled&&$portal)$this->assertOnlyPortalProducer($pdo,null);$systemActor=$actorId>0?$actorId:null;$pdo->prepare('INSERT INTO portal_integration_profiles (application_key,display_label,enabled,portal_projection_enabled,relation_projection_enabled,contact_assignment_projection_enabled,catalog_projection_enabled,service_assignment_projection_enabled,pricing_preview_enabled,draft_quote_enabled,pricing_source,draft_source,portal_route,catalog_route,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$key,$label,$enabled,$portal,$relations,$contactAssignments,$catalog,$serviceAssignments,$pricing,$draft,$pricingSource,$draftSource,$portalRoute,$catalogRoute,$systemActor,$systemActor]);$created=(int)$pdo->lastInsertId();if($owns)$pdo->commit();return$created;}catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function saveWorkspace(PDO $pdo,int $profileId,string $rootType,string $rootPublicId,string $displayName,int $actorId):string
    {
        $this->profileRequired($pdo,$profileId);if(!in_array($rootType,['organization','standalone_client'],true))throw new DomainException('Workspace root type is invalid.');
        $table=$rootType==='organization'?'organizations':'clients';$sql="SELECT public_id,name FROM {$table} WHERE public_id=?".($rootType==='standalone_client'?' AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL':'');$s=$pdo->prepare($sql);$s->execute([$rootPublicId]);$root=$s->fetch(PDO::FETCH_ASSOC);if(!$root)throw new DomainException('Workspace root not found.');
        $displayName=trim($displayName)?:((string)$root['name']);if(mb_strlen($displayName)>150)throw new DomainException('Workspace label is too long.');
        $owns=!$pdo->inTransaction();
        try{
            if($owns)$pdo->beginTransaction();
            $existing=$pdo->prepare('SELECT id,public_id FROM portal_v2_workspaces WHERE root_type=? AND root_public_id=?');$existing->execute([$rootType,$rootPublicId]);$workspace=$existing->fetch(PDO::FETCH_ASSOC);
            if($workspace){$publicId=(string)$workspace['public_id'];$workspaceId=(int)$workspace['id'];$pdo->prepare('UPDATE portal_v2_workspaces SET display_name=?,source_version=?,active=1,updated_by=? WHERE id=?')->execute([$displayName,self::version(),$actorId,$workspaceId]);}
            else{$publicId=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO portal_v2_workspaces (public_id,root_type,root_public_id,display_name,source_version,active,created_by,updated_by) VALUES (?,?,?,?,?,1,?,?)')->execute([$publicId,$rootType,$rootPublicId,$displayName,self::version(),$actorId,$actorId]);$workspaceId=(int)$pdo->lastInsertId();}
            $this->upsertWorkspaceLink($pdo,$profileId,$workspaceId,true,$actorId);
            $this->audit($pdo,$profileId,null,'portal.workspace.linked','workspace',$publicId,['actor_id'=>$actorId]);
            if($owns)$pdo->commit();
            return$publicId;
        }catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function setWorkspaceLink(PDO $pdo,int $profileId,string $workspacePublicId,bool $active,int $actorId):void
    {
        $profile=$this->profileRequired($pdo,$profileId);
        $workspace=$pdo->prepare('SELECT id FROM portal_v2_workspaces WHERE public_id=? AND active=1');$workspace->execute([$workspacePublicId]);$workspaceId=$workspace->fetchColumn();if(!$workspaceId)throw new DomainException('Workspace not found.');
        $owns=!$pdo->inTransaction();
        try{if($owns)$pdo->beginTransaction();$revocation=null;if(!$active){$linked=$pdo->prepare('SELECT active FROM portal_integration_profile_workspaces WHERE profile_id=? AND workspace_id=?');$linked->execute([$profileId,(int)$workspaceId]);if((int)$linked->fetchColumn()===1)$revocation=(new PortalProjectionService())->queueWorkspaceRevocation($pdo,$profile,$workspacePublicId);}$this->upsertWorkspaceLink($pdo,$profileId,(int)$workspaceId,$active,$actorId);$this->audit($pdo,$profileId,null,$active?'portal.workspace.linked':'portal.workspace.unlinked','workspace',$workspacePublicId,['actor_id'=>$actorId,'revocation_queued'=>$revocation!==null]);if($owns)$pdo->commit();}catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function createPrincipal(PDO $pdo,string $email,string $displayName,int $actorId):int
    {
        $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)throw new DomainException('A valid portal email is required.');$displayName=trim($displayName);if($displayName===''||mb_strlen($displayName)>150)throw new DomainException('A manager display name is required.');
        $pdo->prepare('INSERT INTO portal_principals (email_hint,display_name,source_version,enabled,activated_at,created_by,updated_by) VALUES (?,?,?,1,CURRENT_TIMESTAMP,?,?)')->execute([$email,$displayName,self::version(),$actorId,$actorId]);return(int)$pdo->lastInsertId();
    }

    /**
     * Save the provider-neutral client identity intent and queue its authoritative
     * workspace projection in the same transaction. Email is a notification hint,
     * never an identity key or an implicit grant.
     *
     * @param list<int> $clientIds
     */
    public function savePrincipalAccess(PDO $pdo,int $profileId,string $workspaceId,?int $principalId,string $email,string $displayName,array $clientIds,int $actorId):int
    {
        $this->profileRequired($pdo,$profileId);
        $workspace=(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);
        $clientIds=array_values(array_unique(array_filter(array_map('intval',$clientIds),static fn(int$id):bool=>$id>0)));
        $owns=!$pdo->inTransaction();
        try{
            if($owns)$pdo->beginTransaction();
            $affected=$principalId!==null?$this->affectedPrincipalWorkspaces($pdo,$principalId,$workspaceId):[$workspaceId];
            if($principalId!==null){
                $this->principalRequired($pdo,$principalId);
                $email=strtolower(trim($email));
                if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)throw new DomainException('A valid portal email hint is required.');
                $displayName=trim($displayName);
                if($displayName===''||mb_strlen($displayName)>150)throw new DomainException('A portal display name is required.');
                $pdo->prepare('UPDATE portal_principals SET email_hint=?,display_name=?,source_version=?,authorization_version=authorization_version+1,enabled=1,revoked_at=NULL,updated_by=? WHERE id=?')->execute([$email,$displayName,self::version(),$actorId,$principalId]);
            }else{$principalId=$this->createPrincipal($pdo,$email,$displayName,$actorId);}
            $this->replacePrincipalClientsForWorkspace($pdo,$principalId,$clientIds,$workspace,$actorId);
            $affected=array_values(array_unique(array_merge($affected,$this->affectedPrincipalWorkspaces($pdo,$principalId,$workspaceId))));
            $this->audit($pdo,$profileId,null,'portal.principal.saved','principal',$this->principalPublicId($pdo,$principalId),['actor_id'=>$actorId,'client_count'=>count($clientIds),'identity_binding'=>'receiver_verified']);
            foreach($affected as$affectedWorkspace)$this->queueEveryEnabledProfile($pdo,$affectedWorkspace);
            if($owns)$pdo->commit();
            return$principalId;
        }catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function revokePrincipalAccess(PDO $pdo,int $profileId,string $workspaceId,int $principalId,int $actorId):void
    {
        $this->profileRequired($pdo,$profileId);(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);$this->principalRequired($pdo,$principalId);
        $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();$affected=$this->affectedPrincipalWorkspaces($pdo,$principalId,$workspaceId);
            $pdo->prepare('UPDATE portal_principals SET enabled=0,revoked_at=CURRENT_TIMESTAMP,source_version=?,authorization_version=authorization_version+1,updated_by=? WHERE id=?')->execute([self::version(),$actorId,$principalId]);
            $pdo->prepare('UPDATE portal_identity_bindings SET enabled=0,revoked_at=CURRENT_TIMESTAMP,updated_by=? WHERE portal_principal_id=?')->execute([$actorId,$principalId]);
            $pdo->prepare('UPDATE portal_v2_entitlements SET active=0,source_version=?,updated_by=? WHERE portal_principal_id=?')->execute([self::version(),$actorId,$principalId]);
            $this->audit($pdo,$profileId,null,'portal.principal.revoked','principal',$this->principalPublicId($pdo,$principalId),['actor_id'=>$actorId]);foreach($affected as$affectedWorkspace)$this->queueEveryEnabledProfile($pdo,$affectedWorkspace);if($owns)$pdo->commit();
        }catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function saveScopedEntitlement(PDO $pdo,int $profileId,string $workspaceId,int $principalId,string $capability,string $scopeType,string $scopePublicId,string $effect,bool $active,int $actorId):void
    {
        $capabilities=['workspace.view','directory.read','request.create','delivery.view','delegated_share.create'];if(!in_array($capability,$capabilities,true))throw new DomainException('Client portal capability is invalid.');if(!in_array($effect,['allow','deny'],true))throw new DomainException('Entitlement effect is invalid.');
        $this->profileRequired($pdo,$profileId);(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);$this->assertScopeInWorkspace($pdo,$workspaceId,$scopeType,$scopePublicId);$this->principalRequired($pdo,$principalId);
        $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();$existing=$pdo->prepare('SELECT id FROM portal_v2_entitlements WHERE portal_principal_id=? AND capability=? AND effect=? AND scope_type=? AND scope_public_id=?');$existing->execute([$principalId,$capability,$effect,$scopeType,$scopePublicId]);$id=$existing->fetchColumn();if($id)$pdo->prepare('UPDATE portal_v2_entitlements SET active=?,source_version=?,valid_from=CURRENT_TIMESTAMP,expires_at=NULL,updated_by=? WHERE id=?')->execute([$active?1:0,self::version(),$actorId,(int)$id]);elseif($active)$pdo->prepare('INSERT INTO portal_v2_entitlements (public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active,valid_from,created_by,updated_by) VALUES (?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,?,?)')->execute([bin2hex(random_bytes(16)),$principalId,$capability,$effect,$scopeType,$scopePublicId,self::version(),$actorId,$actorId]);$this->audit($pdo,$profileId,null,'portal.entitlement.saved',$scopeType,$scopePublicId,['principal_id'=>$principalId,'capability'=>$capability,'effect'=>$effect,'active'=>$active,'actor_id'=>$actorId]);$this->queueEveryEnabledProfile($pdo,$workspaceId);if($owns)$pdo->commit();}catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function appointManager(PDO $pdo,int $profileId,string $workspaceId,int $principalId,string $scopeType,string $scopePublicId,?int $replacePrincipalId,int $actorId,bool$viewerShareCreate=false):void
    {
        if(!in_array($scopeType,['workspace','organization','department','project'],true))throw new DomainException('Manager scope is invalid.');
        $this->profileRequired($pdo,$profileId);$workspace=(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);$this->assertScopeInWorkspace($pdo,$workspaceId,$scopeType,$scopePublicId);$this->principalRequired($pdo,$principalId);
        $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();
            if($replacePrincipalId&&$replacePrincipalId!==$principalId)$pdo->prepare("UPDATE portal_v2_entitlements SET active=0,source_version=?,updated_by=? WHERE portal_principal_id=? AND scope_type=? AND scope_public_id=? AND effect='allow' AND capability IN ('workspace.view','directory.read','request.create','delivery.view','member.manage','delegated_share.create','viewer.share.create')")->execute([self::version(),$actorId,$replacePrincipalId,$scopeType,$scopePublicId]);
            foreach(self::MANAGER_CAPABILITIES as $capability){$existing=$pdo->prepare("SELECT id FROM portal_v2_entitlements WHERE portal_principal_id=? AND scope_type=? AND scope_public_id=? AND capability=? AND effect='allow'");$existing->execute([$principalId,$scopeType,$scopePublicId,$capability]);$id=$existing->fetchColumn();if($id)$pdo->prepare('UPDATE portal_v2_entitlements SET active=1,source_version=?,valid_from=CURRENT_TIMESTAMP,expires_at=NULL,updated_by=? WHERE id=?')->execute([self::version(),$actorId,$id]);else$pdo->prepare('INSERT INTO portal_v2_entitlements (portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active,valid_from,created_by,updated_by) VALUES (?,?,\'allow\',?,?,?,1,CURRENT_TIMESTAMP,?,?)')->execute([$principalId,$capability,$scopeType,$scopePublicId,self::version(),$actorId,$actorId]);}
            if($viewerShareCreate)$this->upsertViewerShareEffect($pdo,$principalId,$scopeType,$scopePublicId,'allow',true,$actorId);
            $this->setManagerScopeState($pdo,$profileId,(int)$workspace['id'],$scopeType,$scopePublicId,'active',$actorId);
            $this->audit($pdo,$profileId,null,'portal.manager.appointed',$scopeType,$scopePublicId,['principal_id'=>$principalId,'replaced_principal_id'=>$replacePrincipalId,'viewer_share_create'=>$viewerShareCreate,'actor_id'=>$actorId]);
            $this->queueEveryEnabledProfile($pdo,$workspaceId);
            if($owns)$pdo->commit();
        }catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function saveViewerShareEntitlement(PDO$pdo,int$profileId,string$workspaceId,int$principalId,string$scopeType,string$scopePublicId,string$effect,bool$active,int$actorId):void
    {
        if(!in_array($effect,['allow','deny'],true))throw new DomainException('Viewer share entitlement effect is invalid.');
        $this->profileRequired($pdo,$profileId);(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);$this->assertScopeInWorkspace($pdo,$workspaceId,$scopeType,$scopePublicId);$this->principalRequired($pdo,$principalId);
        $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();
            $this->upsertViewerShareEffect($pdo,$principalId,$scopeType,$scopePublicId,$effect,$active,$actorId);
            $this->audit($pdo,$profileId,null,'portal.viewer_share.entitlement_saved',$scopeType,$scopePublicId,['principal_id'=>$principalId,'effect'=>$effect,'active'=>$active,'actor_id'=>$actorId]);$this->queueEveryEnabledProfile($pdo,$workspaceId);if($owns)$pdo->commit();
        }catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function offboardManager(PDO $pdo,int $profileId,string $workspaceId,int $principalId,string $scopeType,string $scopePublicId,int $actorId):void
    {
        $this->profileRequired($pdo,$profileId);$workspace=(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);$this->assertScopeInWorkspace($pdo,$workspaceId,$scopeType,$scopePublicId);$owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();$pdo->prepare("UPDATE portal_v2_entitlements SET active=0,source_version=?,updated_by=? WHERE portal_principal_id=? AND scope_type=? AND scope_public_id=? AND effect='allow' AND capability IN ('workspace.view','directory.read','request.create','delivery.view','member.manage','delegated_share.create','viewer.share.create')")->execute([self::version(),$actorId,$principalId,$scopeType,$scopePublicId]);$remaining=$pdo->prepare("SELECT COUNT(*) FROM portal_v2_entitlements WHERE scope_type=? AND scope_public_id=? AND capability='member.manage' AND effect='allow' AND active=1");$remaining->execute([$scopeType,$scopePublicId]);$recovery=(int)$remaining->fetchColumn()===0;$this->setManagerScopeState($pdo,$profileId,(int)$workspace['id'],$scopeType,$scopePublicId,$recovery?'recovery_required':'active',$actorId);$this->audit($pdo,$profileId,null,'portal.manager.offboarded',$scopeType,$scopePublicId,['principal_id'=>$principalId,'actor_id'=>$actorId,'recovery_required'=>$recovery]);$this->queueEveryEnabledProfile($pdo,$workspaceId);if($owns)$pdo->commit();}catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private function assertScopeInWorkspace(PDO $pdo,string $workspaceId,string $scopeType,string $scopeId):void
    {
        $w=$pdo->prepare('SELECT * FROM portal_v2_workspaces WHERE public_id=? AND active=1');$w->execute([$workspaceId]);$workspace=$w->fetch(PDO::FETCH_ASSOC);if(!$workspace)throw new DomainException('Workspace not found.');
        if($scopeType==='workspace'){if(!hash_equals($workspaceId,$scopeId))throw new DomainException('Scope is outside the workspace.');return;}
        $rootType=(string)$workspace['root_type'];$root=(string)$workspace['root_public_id'];
        $projectVisibility=ProjectLifecycleSchema::visibility($pdo,'p',true);
        $sql=match($scopeType){'organization'=>'SELECT COUNT(*) FROM organizations WHERE public_id=? AND ?=\'organization\' AND public_id=?','department'=>'SELECT COUNT(*) FROM organization_departments d JOIN organizations o ON o.id=d.organization_id WHERE d.public_id=? AND ?=\'organization\' AND o.public_id=?','client'=>$rootType==='organization'?'SELECT COUNT(*) FROM clients c JOIN organizations o ON o.id=c.organization_id WHERE c.public_id=? AND ?=\'organization\' AND o.public_id=? AND c.archived=0 AND c.deleted_at IS NULL':'SELECT COUNT(*) FROM clients c WHERE c.public_id=? AND ?=\'standalone_client\' AND c.public_id=? AND c.organization_id IS NULL AND c.archived=0 AND c.deleted_at IS NULL','project'=>$rootType==='organization'?"SELECT COUNT(*) FROM projects p JOIN organizations o ON o.id=p.organization_id WHERE p.public_id=? AND ?='organization' AND o.public_id=? AND {$projectVisibility}":"SELECT COUNT(*) FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.public_id=? AND ?='standalone_client' AND c.public_id=? AND {$projectVisibility}",default=>throw new DomainException('Scope is invalid.')};$s=$pdo->prepare($sql);$s->execute([$scopeId,$rootType,$root]);if((int)$s->fetchColumn()!==1)throw new DomainException('Scope is outside the workspace.');
    }
    private function profileRequired(PDO $pdo,int$id):array{$p=$this->profile($pdo,$id);if(!$p)throw new DomainException('Integration profile not found.');return$p;}
    private function profile(PDO$pdo,int$id):array|false{$s=$pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=?');$s->execute([$id]);return$s->fetch(PDO::FETCH_ASSOC);}
    private function assertOnlyPortalProducer(PDO$pdo,?int$profileId):void{$sql='SELECT id FROM portal_integration_profiles WHERE enabled=1 AND portal_projection_enabled=1'.($profileId!==null?' AND id<>?':'').' ORDER BY id'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'');$s=$pdo->prepare($sql);$s->execute($profileId!==null?[$profileId]:[]);if($s->fetchColumn()!==false)throw new DomainException('Another workspace publisher is active. Retire and drain it before activating this profile.');}
    private function principalRequired(PDO$pdo,int$id):void{$s=$pdo->prepare('SELECT COUNT(*) FROM portal_principals WHERE id=? AND enabled=1 AND revoked_at IS NULL');$s->execute([$id]);if((int)$s->fetchColumn()!==1)throw new DomainException('Active portal principal not found.');}
    private function principalPublicId(PDO$pdo,int$id):string{$s=$pdo->prepare('SELECT public_id FROM portal_principals WHERE id=?');$s->execute([$id]);$publicId=$s->fetchColumn();if(!$publicId)throw new DomainException('Portal principal not found.');return(string)$publicId;}
    /** @param list<int> $clientIds @param array<string,mixed> $workspace */
    private function replacePrincipalClientsForWorkspace(PDO$pdo,int$principalId,array$clientIds,array$workspace,int$actorId):void
    {
        $rootType=(string)$workspace['root_type'];$rootId=(string)$workspace['root_public_id'];
        $predicate=$rootType==='organization'?'c.organization_id=(SELECT id FROM organizations WHERE public_id=?)':'c.organization_id IS NULL AND c.public_id=?';
        $check=$pdo->prepare("SELECT COUNT(*) FROM clients c WHERE c.id=? AND {$predicate} AND c.archived=0 AND c.deleted_at IS NULL");
        foreach($clientIds as$clientId){$check->execute([$clientId,$rootId]);if((int)$check->fetchColumn()!==1)throw new DomainException('A selected client record is outside this workspace.');}
        $delete=$pdo->prepare("DELETE FROM portal_principal_clients WHERE portal_principal_id=? AND client_id IN (SELECT c.id FROM clients c WHERE {$predicate})");$delete->execute([$principalId,$rootId]);
        $insert=$pdo->prepare('INSERT INTO portal_principal_clients (portal_principal_id,client_id,created_by) VALUES (?,?,?)');foreach($clientIds as$clientId)$insert->execute([$principalId,$clientId,$actorId]);
    }
    private function upsertViewerShareEffect(PDO$pdo,int$principalId,string$scopeType,string$scopePublicId,string$effect,bool$active,int$actorId):void{$existing=$pdo->prepare("SELECT id FROM portal_v2_entitlements WHERE portal_principal_id=? AND capability='viewer.share.create' AND effect=? AND scope_type=? AND scope_public_id=?");$existing->execute([$principalId,$effect,$scopeType,$scopePublicId]);$id=$existing->fetchColumn();if($id)$pdo->prepare('UPDATE portal_v2_entitlements SET active=?,source_version=?,valid_from=CURRENT_TIMESTAMP,expires_at=NULL,updated_by=? WHERE id=?')->execute([$active?1:0,self::version(),$actorId,(int)$id]);elseif($active)$pdo->prepare("INSERT INTO portal_v2_entitlements (public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active,valid_from,created_by,updated_by) VALUES (?,?,'viewer.share.create',?,?,?,?,1,CURRENT_TIMESTAMP,?,?)")->execute([bin2hex(random_bytes(16)),$principalId,$effect,$scopeType,$scopePublicId,self::version(),$actorId,$actorId]);}
    private function queueReplacementWorkspaceSnapshots(PDO$pdo,int$profileId):void{$profile=$this->profileRequired($pdo,$profileId);$workspaces=$pdo->prepare('SELECT w.public_id FROM portal_integration_profile_workspaces pw JOIN portal_v2_workspaces w ON w.id=pw.workspace_id AND w.active=1 WHERE pw.profile_id=? AND pw.active=1 ORDER BY w.public_id');$workspaces->execute([$profileId]);$projection=new PortalProjectionService();foreach($workspaces->fetchAll(PDO::FETCH_COLUMN)as$workspaceId)$projection->queueWorkspaceSnapshot($pdo,$profile,(string)$workspaceId);}
    private function queueEveryEnabledProfile(PDO$pdo,string$workspaceId):void{$profiles=$pdo->prepare('SELECT p.* FROM portal_integration_profiles p JOIN portal_integration_profile_workspaces pw ON pw.profile_id=p.id AND pw.active=1 JOIN portal_v2_workspaces w ON w.id=pw.workspace_id WHERE p.enabled=1 AND p.portal_projection_enabled=1 AND w.public_id=? ORDER BY p.id');$profiles->execute([$workspaceId]);$projection=new PortalProjectionService();foreach($profiles->fetchAll(PDO::FETCH_ASSOC)as$profile)$projection->queueWorkspaceChanges($pdo,$profile,$workspaceId);}
    /** @return list<string> */
    private function affectedPrincipalWorkspaces(PDO$pdo,int$principalId,string$selectedWorkspace):array
    {
        $publicId=$this->principalPublicId($pdo,$principalId);$ids=[$selectedWorkspace=>true];
        $projected=$pdo->prepare("SELECT DISTINCT workspace_public_id FROM portal_projection_resource_state WHERE resource_type='principal' AND resource_public_id=?");$projected->execute([$publicId]);foreach($projected->fetchAll(PDO::FETCH_COLUMN)as$id)$ids[(string)$id]=true;
        $linked=$pdo->prepare("SELECT DISTINCT w.public_id FROM portal_principal_clients pc JOIN clients c ON c.id=pc.client_id JOIN portal_v2_workspaces w ON (w.root_type='organization' AND c.organization_id=(SELECT o.id FROM organizations o WHERE o.public_id=w.root_public_id)) OR (w.root_type='standalone_client' AND c.organization_id IS NULL AND c.public_id=w.root_public_id) WHERE pc.portal_principal_id=? AND w.active=1");$linked->execute([$principalId]);foreach($linked->fetchAll(PDO::FETCH_COLUMN)as$id)$ids[(string)$id]=true;
        return array_keys($ids);
    }
    private function setManagerScopeState(PDO$pdo,int$profileId,int$workspaceId,string$scopeType,string$scopePublicId,string$state,int$actorId):void{$removed=$state==='recovery_required'?'CURRENT_TIMESTAMP':'NULL';$update=$pdo->prepare("UPDATE portal_manager_scope_state SET state=?,last_manager_removed_at={$removed},updated_by=? WHERE integration_profile_id=? AND workspace_id=? AND scope_type=? AND scope_public_id=?");$update->execute([$state,$actorId,$profileId,$workspaceId,$scopeType,$scopePublicId]);if($update->rowCount()===0){$exists=$pdo->prepare('SELECT COUNT(*) FROM portal_manager_scope_state WHERE integration_profile_id=? AND workspace_id=? AND scope_type=? AND scope_public_id=?');$exists->execute([$profileId,$workspaceId,$scopeType,$scopePublicId]);if((int)$exists->fetchColumn()===0)$pdo->prepare("INSERT INTO portal_manager_scope_state (integration_profile_id,workspace_id,scope_type,scope_public_id,state,last_manager_removed_at,updated_by) VALUES (?,?,?,?,?,{$removed},?)")->execute([$profileId,$workspaceId,$scopeType,$scopePublicId,$state,$actorId]);}}
    private function queueProfileRevocations(PDO$pdo,array$profile):int{$workspaces=$pdo->prepare('SELECT w.public_id FROM portal_integration_profile_workspaces pw JOIN portal_v2_workspaces w ON w.id=pw.workspace_id AND w.active=1 WHERE pw.profile_id=? AND pw.active=1 ORDER BY w.public_id');$workspaces->execute([(int)$profile['id']]);$count=0;$projection=new PortalProjectionService();foreach($workspaces->fetchAll(PDO::FETCH_COLUMN)as$workspaceId)if($projection->queueWorkspaceRevocation($pdo,$profile,(string)$workspaceId)!==null)$count++;return$count;}
    private function supersedePendingNormalRows(PDO$pdo,int$profileId,string$routeType):void{$now=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'CURRENT_TIMESTAMP':'UTC_TIMESTAMP(6)';$pdo->prepare("UPDATE portal_projection_outbox SET dead_lettered_at={$now},last_error_code='profile_disabled_superseded' WHERE integration_profile_id=? AND route_type=? AND is_revocation=0 AND delivered_at IS NULL AND dead_lettered_at IS NULL AND claimed_at IS NULL")->execute([$profileId,$routeType]);}
    private function supersedeUnclaimedPortalSchemaRows(PDO$pdo,int$profileId,int$schemaVersion):void{$now=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'CURRENT_TIMESTAMP':'UTC_TIMESTAMP(6)';$pdo->prepare("UPDATE portal_projection_outbox SET dead_lettered_at=COALESCE(dead_lettered_at,{$now}),last_error_code='schema_transition_superseded' WHERE integration_profile_id=? AND route_type='portal' AND schema_version=? AND is_revocation=0 AND delivered_at IS NULL AND claimed_at IS NULL")->execute([$profileId,$schemaVersion]);}
    private function upsertWorkspaceLink(PDO$pdo,int$profileId,int$workspaceId,bool$active,int$actorId):void{$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);if($driver==='sqlite')$sql='INSERT INTO portal_integration_profile_workspaces (profile_id,workspace_id,active,created_by,updated_by) VALUES (?,?,?,?,?) ON CONFLICT(profile_id,workspace_id) DO UPDATE SET active=excluded.active,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP';else$sql='INSERT INTO portal_integration_profile_workspaces (profile_id,workspace_id,active,created_by,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active),updated_by=VALUES(updated_by)';$pdo->prepare($sql)->execute([$profileId,$workspaceId,$active?1:0,$actorId,$actorId]);}
    private function audit(PDO$pdo,int$profileId,?int$keyId,string$action,?string$type,?string$id,array$meta):void{$pdo->prepare('INSERT INTO portal_integration_audit (integration_profile_id,api_key_id,action,target_type,target_public_id,metadata_json) VALUES (?,?,?,?,?,?)')->execute([$profileId,$keyId,$action,$type,$id,json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private function nullableAscii(mixed$v,int$max):?string{$v=trim((string)$v);if($v==='')return null;if(strlen($v)>$max||preg_match('/^[A-Za-z0-9._:-]+$/D',$v)!==1)throw new DomainException('Source identifier is invalid.');return$v;}
    private function httpsRoute(mixed$v):?string{$v=trim((string)$v);if($v==='')return null;PortalProjectionDeliveryConfigService::validateDestination($v);return$v;}
    private static function version():string{return'v-'.bin2hex(random_bytes(16));}
}
