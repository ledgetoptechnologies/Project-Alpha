<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/** Owns only policy-stamped request.create rows; it never provisions identities.
 * Deploy only with the Client readable-project authorization fence. Root request
 * permission does not grant project visibility, management or content access. */
final class PortalClientRequestAccessService
{
    private const POLICY = 'approved-client-root-request-v1';

    /** Caller owns the transaction and projection publication. Returns changed workspace IDs. */
    public function reconcileClient(PDO $pdo, int $profileId, int $clientId, int $actorId, bool $allowCreate = false, bool $excludeCurrent = false): array
    {
        if (!$pdo->inTransaction()) throw new DomainException('Request access reconciliation requires a transaction.');
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $client=$pdo->prepare('SELECT id FROM clients WHERE id=?'.$lock);$client->execute([$clientId]);
        if($client->fetchColumn()===false)return [];
        $protected=PortalContactIdentityPolicy::forClient($pdo,$clientId);
        $principal = $pdo->prepare('SELECT p.*,e.canonical_email eligibility_email FROM portal_client_login_eligibility e JOIN portal_principals p ON p.id=e.portal_principal_id WHERE e.client_id=?'.$lock);
        $principal->execute([$clientId]);
        $principal = $principal->fetch(PDO::FETCH_ASSOC);
        if (!$principal) return [];
        if(!$excludeCurrent && $protected && ($protected['portal_principal_id']===null
            ||(int)$protected['portal_principal_id']!==(int)$principal['id']
            ||!hash_equals((string)$protected['login_email'],(string)$principal['eligibility_email'])))return [];
        $principalId = (int)$principal['id'];
        $roots = $pdo->prepare("SELECT c.id,c.public_id,c.organization_id,o.public_id organization_public_id,w.public_id workspace_public_id,w.root_type,w.root_public_id
            FROM portal_principal_clients pc JOIN clients c ON c.id=pc.client_id
            JOIN portal_client_login_eligibility e ON e.client_id=c.id AND e.portal_principal_id=pc.portal_principal_id
            LEFT JOIN organizations o ON o.id=c.organization_id
            JOIN portal_v2_workspaces w ON w.root_type=CASE WHEN c.organization_id IS NULL THEN 'standalone_client' ELSE 'organization' END
                AND w.root_public_id=CASE WHEN c.organization_id IS NULL THEN c.public_id ELSE o.public_id END AND w.active=1
            JOIN portal_integration_profile_workspaces pw ON pw.workspace_id=w.id AND pw.profile_id=? AND pw.active=1
            JOIN portal_integration_profiles p ON p.id=pw.profile_id AND p.enabled=1 AND p.portal_projection_enabled=1
            LEFT JOIN portal_client_access_roots r ON r.root_type=w.root_type AND r.root_public_id=w.root_public_id
            WHERE pc.portal_principal_id=? AND pc.client_id<>? AND c.archived=0 AND c.deleted_at IS NULL
                AND e.eligibility_status='eligible' AND e.manual_state<>'revoked' AND COALESCE(r.access_state,'active')='active' LIMIT 201".$lock);
        $roots->execute([$profileId,$principalId,$excludeCurrent?$clientId:0]);
        $rootRows=$roots->fetchAll(PDO::FETCH_ASSOC);
        if(count($rootRows)>200)throw new DomainException('Request access relationship capacity exceeded.');
        $desired = []; $target = null;
        if (!empty($principal['enabled']) && empty($principal['revoked_at'])) {
            foreach ($rootRows as $root) {
                $type = $root['root_type']==='standalone_client' ? 'client' : 'organization';
                $key = $type.':'.$root['root_public_id'];
                $desired[$key] = $root;
                if ((int)$root['id']===$clientId) $target = [$type,(string)$root['root_public_id'],$root];
            }
        }
        $rows = $pdo->prepare("SELECT * FROM portal_v2_entitlements WHERE portal_principal_id=? AND capability='request.create' ORDER BY id LIMIT 201".$lock);
        $rows->execute([$principalId]); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>200)throw new DomainException('Request access entitlement capacity exceeded.');
        $changed = []; $ownedTarget = null; $manualTarget = false; $hasOwned = false;
        foreach ($rows as $row) {
            $type=(string)$row['scope_type']; $id=(string)$row['scope_public_id'];
            $owned=$this->owned($row,(string)$principal['public_id']);
            $hasOwned=$hasOwned || $owned;
            if ($target && $type===$target[0] && $id===$target[1]) {
                if ($owned) $ownedTarget=$row;
                else $manualTarget=true; // Includes inactive/manual deny rows: never override intent.
            }
            if (!$owned || isset($desired[$type.':'.$id]) || !(int)$row['active']) continue;
            $this->setActive($pdo,$row,(string)$principal['public_id'],false,$actorId);
            $changed=array_merge($changed,$this->workspaceIds($pdo,$type,$id));
            $this->audit($pdo,$profileId,$principalId,$type,$id,false,$actorId);
        }
        if (!$target) return array_values(array_unique($changed));
        [$type,$id,$root]=$target;
        // An explicit scoped/workspace/client deny must not be bypassed by a new root allow.
        foreach ($rows as $row) {
            if ($row['effect']==='deny' && (int)$row['active']===1
                && (($row['scope_type']===$type && $row['scope_public_id']===$id)
                    || ($row['scope_type']==='client' && $row['scope_public_id']===$root['public_id'])
                    || ($row['scope_type']==='workspace' && $row['scope_public_id']===$root['workspace_public_id']))) $manualTarget=true;
        }
        if ($manualTarget) {
            if ($ownedTarget && (int)$ownedTarget['active']===1) {
                $this->setActive($pdo,$ownedTarget,(string)$principal['public_id'],false,$actorId);
                $this->audit($pdo,$profileId,$principalId,$type,$id,false,$actorId);
                $changed[]=(string)$root['workspace_public_id'];
            }
            return array_values(array_unique($changed));
        }
        if (!PortalClientRequestPolicy::enabled($pdo,$profileId)
            || (!$allowCreate && !$ownedTarget && !$hasOwned)) return array_values(array_unique($changed));
        if ($ownedTarget && (int)$ownedTarget['active']===1) return array_values(array_unique($changed));
        if ($ownedTarget) $this->setActive($pdo,$ownedTarget,(string)$principal['public_id'],true,$actorId);
        else $pdo->prepare("INSERT INTO portal_v2_entitlements(public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active,valid_from,created_by,updated_by) VALUES (?,?,'request.create','allow',?,?,?,1,CURRENT_TIMESTAMP,?,?)")
            ->execute([$this->publicId((string)$principal['public_id'],$type,$id),$principalId,$type,$id,$this->version((string)$principal['public_id'],$type,$id,true),$actorId?:null,$actorId?:null]);
        $this->audit($pdo,$profileId,$principalId,$type,$id,true,$actorId);
        $changed[]=(string)$root['workspace_public_id'];
        return array_values(array_unique($changed));
    }

    /** Retire owned intent before the existing principal revocation changes its versions. */
    public function retirePrincipal(PDO $pdo,int $profileId,int $principalId,int $actorId): array
    {
        if (!$pdo->inTransaction()) throw new DomainException('Request access reconciliation requires a transaction.');
        $principal=$pdo->prepare('SELECT public_id FROM portal_principals WHERE id=?');
        $principal->execute([$principalId]); $publicId=$principal->fetchColumn();
        if ($publicId===false) return [];
        $rows=$pdo->prepare("SELECT * FROM portal_v2_entitlements WHERE portal_principal_id=? AND capability='request.create' AND active=1 ORDER BY id LIMIT 201");
        $rows->execute([$principalId]); $rows=$rows->fetchAll(PDO::FETCH_ASSOC); $changed=[];
        if(count($rows)>200)throw new DomainException('Request access entitlement capacity exceeded.');
        foreach ($rows as $row) {
            if (!$this->owned($row,(string)$publicId)) continue;
            $this->setActive($pdo,$row,(string)$publicId,false,$actorId);
            $this->audit($pdo,$profileId,$principalId,(string)$row['scope_type'],(string)$row['scope_public_id'],false,$actorId);
            $changed=array_merge($changed,$this->workspaceIds($pdo,(string)$row['scope_type'],(string)$row['scope_public_id']));
        }
        return array_values(array_unique($changed));
    }

    /** Archive already owns its identity/restore fence. Only retire this policy's
     * root intent before its ordinary client-scoped version writes run. */
    public function beforeArchive(PDO $pdo,int $clientId,int $actorId): array
    {
        $profiles=$pdo->prepare("SELECT DISTINCT p.id FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id
            JOIN portal_v2_workspaces w ON w.root_type=CASE WHEN c.organization_id IS NULL THEN 'standalone_client' ELSE 'organization' END
                AND w.root_public_id=CASE WHEN c.organization_id IS NULL THEN c.public_id ELSE o.public_id END
            JOIN portal_integration_profile_workspaces pw ON pw.workspace_id=w.id AND pw.active=1
            JOIN portal_integration_profiles p ON p.id=pw.profile_id AND p.enabled=1 AND p.portal_projection_enabled=1 WHERE c.id=? LIMIT 2");
        $profiles->execute([$clientId]);$profiles=$profiles->fetchAll(PDO::FETCH_COLUMN);
        if(count($profiles)>1)throw new DomainException('Request access requires one exact portal producer.');
        return $profiles?$this->reconcileClient($pdo,(int)$profiles[0],$clientId,$actorId,false,true):[];
    }

    private function publicId(string $principal,string $type,string $id): string
    { return substr(hash('sha256',self::POLICY.'|'.$principal.'|'.$type.'|'.$id),0,32); }

    private function version(string $principal,string $type,string $id,bool $active): string
    { return PortalSourceVersion::from(['policy'=>self::POLICY,'principalPublicId'=>$principal,'scopeType'=>$type,'scopePublicId'=>$id,'active'=>$active]); }

    private function owned(array $row,string $principal): bool
    {
        return $row['effect']==='allow' && hash_equals($this->publicId($principal,(string)$row['scope_type'],(string)$row['scope_public_id']),(string)$row['public_id'])
            && hash_equals($this->version($principal,(string)$row['scope_type'],(string)$row['scope_public_id'],(int)$row['active']===1),(string)$row['source_version']);
    }

    private function setActive(PDO $pdo,array $row,string $principal,bool $active,int $actorId): void
    {
        $statement=$pdo->prepare('UPDATE portal_v2_entitlements SET active=?,source_version=?,updated_by=? WHERE id=? AND source_version=?');
        $statement->execute([$active?1:0,$this->version($principal,(string)$row['scope_type'],(string)$row['scope_public_id'],$active),$actorId?:null,$row['id'],$row['source_version']]);
        if ($statement->rowCount()!==1) throw new DomainException('Request access changed during reconciliation.');
    }

    private function workspaceIds(PDO $pdo,string $type,string $id): array
    {
        $statement=$pdo->prepare('SELECT public_id FROM portal_v2_workspaces WHERE root_type=? AND root_public_id=?');
        $statement->execute([$type==='client'?'standalone_client':$type,$id]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function audit(PDO $pdo,int $profileId,int $principalId,string $type,string $id,bool $active,int $actorId): void
    {
        $pdo->prepare("INSERT INTO portal_integration_audit(integration_profile_id,api_key_id,action,target_type,target_public_id,metadata_json) VALUES (?,NULL,'portal.client_request_access.reconciled',?,?,?)")
            ->execute([$profileId,$type,$id,json_encode(['policy'=>self::POLICY,'principal_id'=>$principalId,'capability'=>'request.create','active'=>$active,'actor_id'=>$actorId],JSON_THROW_ON_ERROR)]);
    }
}
