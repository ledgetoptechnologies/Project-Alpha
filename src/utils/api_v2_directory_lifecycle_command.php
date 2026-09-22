<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_capabilities.php';
require_once __DIR__ . '/api_v2_directory_revision.php';
require_once __DIR__ . '/portal_projection_hooks.php';

function api_v2_directory_lifecycle_command_parse(string $json): ?array
{
    if (strlen($json) > 32 * 1024) return null;
    try { $value=json_decode($json,true,8,JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    $fields=['commandId','expectedRevision','expectedAuthorizationGeneration'];
    if(!is_array($value)||count($value)!==3||array_diff(array_keys($value),$fields)!==[]||array_diff($fields,array_keys($value))!==[])return null;
    foreach($fields as$field)if(!is_string($value[$field]))return null;
    if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value['commandId'])!==1
        ||!api_v2_directory_lifecycle_positive($value['expectedRevision'])
        ||!api_v2_directory_lifecycle_generation($value['expectedAuthorizationGeneration']))return null;
    return array_intersect_key($value,array_flip($fields));
}

function api_v2_directory_lifecycle_positive(string$value):bool
{return preg_match('/^[1-9][0-9]{0,18}$/D',$value)===1&&!(strlen($value)===19&&strcmp($value,PA_API_V2_AUTHORIZATION_GENERATION_MAX)>0);}
function api_v2_directory_lifecycle_generation(string$value):bool
{return preg_match('/^(0|[1-9][0-9]{0,18})$/D',$value)===1&&!(strlen($value)===19&&strcmp($value,PA_API_V2_AUTHORIZATION_GENERATION_MAX)>0);}

function api_v2_directory_lifecycle_result(array$identity,string$type,string$publicId,string$action,string$revision,string$generation,string$requestId,bool$replayed):array
{
    return ['sourceInstanceId'=>(string)$identity['source_instance_id'],'applicationId'=>(string)$identity['application_id'],
        'historyEpoch'=>(string)$identity['history_epoch'],'requestId'=>$requestId,'replayed'=>$replayed,
        'result'=>['action'=>$action,'resource'=>['type'=>$type,'publicId'=>$publicId,'revision'=>$revision,'present'=>$action==='restore'],
            'authorizationGeneration'=>$generation]];
}

/** Safe directory lifecycle. It never physically deletes a source or dependent row. */
function api_v2_directory_lifecycle_command_write(PDO$pdo,string$type,string$publicId,string$action,array$command,int$apiKeyId,array$headers,string$requestId):array
{
    if(!in_array($type,['client','organization'],true)||!in_array($action,['archive','restore'],true)
        ||preg_match('/^[0-9a-f]{32}$/D',$publicId)!==1||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid directory lifecycle command');
    $pdo->beginTransaction();
    try{
        api_v2_directory_management_acquire_shared_gate($pdo,false);
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $identityStatement=$pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL'.$lock);
        $identityStatement->execute([$apiKeyId]);$identity=$identityStatement->fetch(PDO::FETCH_ASSOC);
        if(!$identity||!api_v2_identity_is_valid($identity)||!hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))
            ||!hash_equals((string)$identity['application_id'],(string)($headers['application']??''))||!hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))){$pdo->rollBack();return['status'=>409];}
        $appPk=(int)$identity['application_pk'];$requestHash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $receiptStatement=$pdo->prepare('SELECT request_sha256,action_name,public_id,CAST(expected_revision AS CHAR) expected_revision,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_revision AS CHAR) result_revision,CAST(result_authorization_generation AS CHAR) result_authorization_generation FROM api_v2_directory_lifecycle_command_receipts WHERE application_pk=? AND resource_type=? AND history_epoch=? AND command_id=?'.$lock);
        $receiptStatement->execute([$appPk,$type,$identity['history_epoch'],$command['commandId']]);$receipt=$receiptStatement->fetch(PDO::FETCH_ASSOC);
        if($receipt&&(!hash_equals((string)$receipt['request_sha256'],$requestHash)||(string)$receipt['action_name']!==$action
            ||!hash_equals((string)$receipt['public_id'],$publicId)||(string)$receipt['expected_revision']!==$command['expectedRevision']
            ||(string)$receipt['expected_authorization_generation']!==$command['expectedAuthorizationGeneration'])){$pdo->rollBack();return['status'=>409];}
        if($receipt){$pdo->commit();return['status'=>200,'payload'=>api_v2_directory_lifecycle_result($identity,$type,$publicId,$action,(string)$receipt['result_revision'],(string)$receipt['result_authorization_generation'],$requestId,true)];}

        $table=$type==='client'?'clients':'organizations';
        $candidate=$pdo->prepare("SELECT id FROM {$table} WHERE public_id=?");$candidate->execute([$publicId]);$localId=(int)($candidate->fetchColumn()?:0);
        if($localId<1){$pdo->rollBack();return['status'=>409];}
        $projection=new App\Services\PortalProjectionMutationService();
        $before=$type==='client'?$projection->lockedClientScopes($pdo,$localId):$projection->organizationScopes($pdo,$localId);
        $source=$pdo->prepare("SELECT * FROM {$table} WHERE id=? AND public_id=?".$lock);$source->execute([$localId,$publicId]);$row=$source->fetch(PDO::FETCH_ASSOC);
        if(!$row){$pdo->rollBack();return['status'=>409];}
        $stateStatement=$pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?'.$lock);
        $stateStatement->execute([$type,$publicId]);$state=$stateStatement->fetch(PDO::FETCH_ASSOC);
        $expectedPresent=$action==='archive'?1:0;
        if(!$state||(int)$state['present']!==$expectedPresent||(string)$state['revision']!==$command['expectedRevision']){$pdo->rollBack();return['status'=>409];}
        if($expectedPresent===1&&!hash_equals((string)$state['projection_sha256'],api_v2_directory_projection_hash($type,$row))){$pdo->rollBack();return['status'=>409];}
        $active=(int)($row['archived']??0)===0&&($row['deleted_at']??null)===null;
        if(($action==='archive'&&!$active)||($action==='restore'&&$active)){$pdo->rollBack();return['status'=>409];}
        if($action==='archive'&&!api_v2_directory_lifecycle_neutral_archive_safe($pdo,$type,$localId,$publicId)){$pdo->rollBack();return['status'=>409];}

        // Authorization is intentionally locked after the source and revision rows.
        // Archive's tombstone helper then takes binding rows before application watermarks.
        if($action==='archive'){
            api_v2_directory_preflight_tombstone_bindings($pdo,$type,$publicId);
        }
        $authorization=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?'.$lock);
        $authorization->execute([$appPk]);$generation=$authorization->fetchColumn();
        if($generation===false||(string)$generation!==$command['expectedAuthorizationGeneration']){$pdo->rollBack();return['status'=>409];}

        if($action==='archive'){
            $update=$pdo->prepare("UPDATE {$table} SET archived=1,deleted_at=CURRENT_TIMESTAMP WHERE id=? AND archived=0 AND deleted_at IS NULL");
            $update->execute([$localId]);if($update->rowCount()!==1)throw new DomainException('Directory resource changed while archiving.');
            api_v2_directory_record_delete($pdo,$type,$publicId,false);
        }else{
            $update=$pdo->prepare("UPDATE {$table} SET archived=0,deleted_at=NULL WHERE id=? AND archived=1 AND deleted_at IS NOT NULL");
            $update->execute([$localId]);if($update->rowCount()!==1)throw new DomainException('Directory resource changed while restoring.');
            if(!api_v2_directory_record($pdo,$type,$localId,false))throw new RuntimeException('Directory restore revision unavailable');
        }
        $after=$type==='client'?$projection->clientScopes($pdo,$localId):$projection->organizationScopes($pdo,$localId);
        $projection->afterMutationProjectionOnly($pdo,array_merge($before,$after));
        $resultState=$pdo->prepare('SELECT CAST(revision AS CHAR) FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?');$resultState->execute([$type,$publicId]);$resultRevision=$resultState->fetchColumn();
        $resultGeneration=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?');$resultGeneration->execute([$appPk]);$resultGeneration=$resultGeneration->fetchColumn();
        if($resultRevision===false||$resultGeneration===false)throw new RuntimeException('Directory lifecycle result unavailable');
        $pdo->prepare('INSERT INTO api_v2_directory_lifecycle_command_receipts(application_pk,resource_type,history_epoch,command_id,request_sha256,action_name,public_id,expected_revision,expected_authorization_generation,result_revision,result_authorization_generation) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$appPk,$type,$identity['history_epoch'],$command['commandId'],$requestHash,$action,$publicId,$command['expectedRevision'],$command['expectedAuthorizationGeneration'],$resultRevision,$resultGeneration]);
        $pdo->commit();return['status'=>200,'payload'=>api_v2_directory_lifecycle_result($identity,$type,$publicId,$action,(string)$resultRevision,(string)$resultGeneration,$requestId,false)];
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')return['status'=>409];throw$error;}
}

/**
 * Neutral directory ownership must never silently leave portal authority live.
 * Until a shared soft-archive portal revocation service exists, reject such an
 * archive rather than partially changing one authority plane.
 */
function api_v2_directory_lifecycle_neutral_archive_safe(PDO$pdo,string$type,int$localId,string$publicId):bool
{
    if(api_v2_directory_lifecycle_table_exists($pdo,'portal_v2_workspaces')){
        $rootType=$type==='client'?'standalone_client':'organization';$workspace=$pdo->prepare('SELECT 1 FROM portal_v2_workspaces WHERE root_type=? AND root_public_id=? AND active=1 LIMIT 1');$workspace->execute([$rootType,$publicId]);if($workspace->fetchColumn()!==false)return false;
    }
    if(api_v2_directory_lifecycle_table_exists($pdo,'portal_v2_entitlements')){
        $scopeTypes=$type==='client'?['client','standalone_client']:['organization'];$marks=implode(',',array_fill(0,count($scopeTypes),'?'));
        $entitlement=$pdo->prepare("SELECT 1 FROM portal_v2_entitlements WHERE scope_type IN ({$marks}) AND scope_public_id=? AND active=1 LIMIT 1");$entitlement->execute(array_merge($scopeTypes,[$publicId]));if($entitlement->fetchColumn()!==false)return false;
    }
    if($type==='client'&&api_v2_directory_lifecycle_table_exists($pdo,'portal_principal_clients')&&api_v2_directory_lifecycle_table_exists($pdo,'portal_principals')){
        $principal=$pdo->prepare('SELECT 1 FROM portal_principal_clients link JOIN portal_principals principal ON principal.id=link.portal_principal_id WHERE link.client_id=? AND principal.enabled=1 AND principal.revoked_at IS NULL LIMIT 1');$principal->execute([$localId]);if($principal->fetchColumn()!==false)return false;
    }
    return true;
}

function api_v2_directory_lifecycle_table_exists(PDO$pdo,string$table):bool
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$statement=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$statement->execute([$table]);return$statement->fetchColumn()!==false;}
    $statement=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$statement->execute([$table]);return$statement->fetchColumn()!==false;
}
