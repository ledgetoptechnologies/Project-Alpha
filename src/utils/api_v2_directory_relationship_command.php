<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_lifecycle_command.php';
require_once __DIR__ . '/portal_projection_hooks.php';

function api_v2_directory_relationship_command_parse(string$action,string$json):?array
{
    if(!in_array($action,['assign','remove','move'],true)||strlen($json)>32*1024)return null;
    try{$value=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
    $fields=['commandId','expectedClientRevision','expectedAuthorizationGeneration','expectedCurrentOrganizationPublicId','organization'];
    if(!is_array($value)||count($value)!==5||array_diff(array_keys($value),$fields)!==[]||array_diff($fields,array_keys($value))!==[])return null;
    foreach(['commandId','expectedClientRevision','expectedAuthorizationGeneration']as$field)if(!is_string($value[$field]))return null;
    if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value['commandId'])!==1
        ||!api_v2_directory_lifecycle_positive($value['expectedClientRevision'])||!api_v2_directory_lifecycle_generation($value['expectedAuthorizationGeneration']))return null;
    $current=$value['expectedCurrentOrganizationPublicId'];
    if($current!==null&&(!is_string($current)||preg_match('/^[0-9a-f]{32}$/D',$current)!==1))return null;
    if(($action==='assign'&&$current!==null)||($action!=='assign'&&$current===null))return null;
    $organization=$value['organization'];
    if($action==='remove'){
        if($organization!==null)return null;
    }else{
        $orgFields=['externalId','publicId','expectedRevision'];
        if(!is_array($organization)||count($organization)!==3||array_diff(array_keys($organization),$orgFields)!==[]||array_diff($orgFields,array_keys($organization))!==[])return null;
        foreach($orgFields as$field)if(!is_string($organization[$field]))return null;
        if(!api_v2_directory_relationship_external_id_valid($organization['externalId'])||preg_match('/^[0-9a-f]{32}$/D',$organization['publicId'])!==1
            ||!api_v2_directory_lifecycle_positive($organization['expectedRevision'])||($action==='move'&&hash_equals($current,$organization['publicId'])))return null;
    }
    return ['commandId'=>$value['commandId'],'expectedClientRevision'=>$value['expectedClientRevision'],'expectedAuthorizationGeneration'=>$value['expectedAuthorizationGeneration'],
        'expectedCurrentOrganizationPublicId'=>$current,'organization'=>$organization];
}

function api_v2_directory_relationship_external_id_valid(string$value):bool
{
    if($value===''||strlen($value)>764||preg_match('//u',$value)!==1||preg_match('/\p{C}/u',$value)===1)return false;
    $characters=preg_match_all('/./us',$value);return$characters!==false&&$characters>=1&&$characters<=191;
}

function api_v2_directory_relationship_result(array$identity,string$clientPublicId,string$action,?string$organizationPublicId,string$revision,string$generation,string$requestId,bool$replayed):array
{
    return['sourceInstanceId'=>(string)$identity['source_instance_id'],'applicationId'=>(string)$identity['application_id'],'historyEpoch'=>(string)$identity['history_epoch'],
        'requestId'=>$requestId,'replayed'=>$replayed,'result'=>['action'=>$action,'client'=>['publicId'=>$clientPublicId,'revision'=>$revision],
            'organizationPublicId'=>$organizationPublicId,'authorizationGeneration'=>$generation]];
}

function api_v2_directory_relationship_command_write(PDO$pdo,string$clientPublicId,string$action,array$command,int$apiKeyId,array$headers,string$requestId):array
{
    if(preg_match('/^[0-9a-f]{32}$/D',$clientPublicId)!==1||!in_array($action,['assign','remove','move'],true)||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid directory relationship command');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $identityStatement=$pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL'.$lock);
        $identityStatement->execute([$apiKeyId]);$identity=$identityStatement->fetch(PDO::FETCH_ASSOC);
        if(!$identity||!api_v2_identity_is_valid($identity)||!hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))||!hash_equals((string)$identity['application_id'],(string)($headers['application']??''))||!hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))){$pdo->rollBack();return['status'=>409];}
        $appPk=(int)$identity['application_pk'];$requestHash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $receiptStatement=$pdo->prepare('SELECT request_sha256,action_name,client_public_id,CAST(expected_client_revision AS CHAR) expected_client_revision,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_client_revision AS CHAR) result_client_revision,CAST(result_authorization_generation AS CHAR) result_authorization_generation FROM api_v2_directory_relationship_command_receipts WHERE application_pk=? AND history_epoch=? AND command_id=?'.$lock);
        $receiptStatement->execute([$appPk,$identity['history_epoch'],$command['commandId']]);$receipt=$receiptStatement->fetch(PDO::FETCH_ASSOC);
        if($receipt&&(!hash_equals((string)$receipt['request_sha256'],$requestHash)||(string)$receipt['action_name']!==$action||!hash_equals((string)$receipt['client_public_id'],$clientPublicId)||(string)$receipt['expected_client_revision']!==$command['expectedClientRevision']||(string)$receipt['expected_authorization_generation']!==$command['expectedAuthorizationGeneration'])){$pdo->rollBack();return['status'=>409];}
        $targetPublicId=$command['organization']['publicId']??null;
        if($receipt){$pdo->commit();return['status'=>200,'payload'=>api_v2_directory_relationship_result($identity,$clientPublicId,$action,$targetPublicId,(string)$receipt['result_client_revision'],(string)$receipt['result_authorization_generation'],$requestId,true)];}

        $candidate=$pdo->prepare('SELECT id FROM clients WHERE public_id=?');$candidate->execute([$clientPublicId]);$clientId=(int)($candidate->fetchColumn()?:0);if($clientId<1){$pdo->rollBack();return['status'=>409];}
        $targetOrganizationId=null;
        if($targetPublicId!==null){$targetLookup=$pdo->prepare('SELECT id FROM organizations WHERE public_id=?');$targetLookup->execute([$targetPublicId]);$targetOrganizationId=(int)($targetLookup->fetchColumn()?:0);if($targetOrganizationId<1){$pdo->rollBack();return['status'=>409];}}
        $projection=new App\Services\PortalProjectionMutationService();$before=$projection->lockedClientScopes($pdo,$clientId,$targetOrganizationId);
        $clientStatement=$pdo->prepare('SELECT * FROM clients WHERE id=? AND public_id=?'.$lock);$clientStatement->execute([$clientId,$clientPublicId]);$client=$clientStatement->fetch(PDO::FETCH_ASSOC);
        if(!$client||(int)($client['archived']??0)!==0||($client['deleted_at']??null)!==null){$pdo->rollBack();return['status'=>409];}
        $currentPublicId=null;
        if($client['organization_id']!==null){$currentStatement=$pdo->prepare('SELECT public_id FROM organizations WHERE id=?'.$lock);$currentStatement->execute([(int)$client['organization_id']]);$currentPublicId=$currentStatement->fetchColumn();if(!is_string($currentPublicId)){$pdo->rollBack();return['status'=>409];}}
        if($currentPublicId!==$command['expectedCurrentOrganizationPublicId']){$pdo->rollBack();return['status'=>409];}
        $clientStateStatement=$pdo->prepare("SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type='client' AND public_id=?".$lock);
        $clientStateStatement->execute([$clientPublicId]);$clientState=$clientStateStatement->fetch(PDO::FETCH_ASSOC);
        if(!$clientState||(int)$clientState['present']!==1||(string)$clientState['revision']!==$command['expectedClientRevision']||!hash_equals((string)$clientState['projection_sha256'],api_v2_directory_projection_hash('client',$client))){$pdo->rollBack();return['status'=>409];}
        if($targetOrganizationId!==null){
            $organizationStatement=$pdo->prepare('SELECT * FROM organizations WHERE id=? AND public_id=?'.$lock);$organizationStatement->execute([$targetOrganizationId,$targetPublicId]);$organization=$organizationStatement->fetch(PDO::FETCH_ASSOC);
            if(!$organization||(int)($organization['archived']??0)!==0||($organization['deleted_at']??null)!==null){$pdo->rollBack();return['status'=>409];}
            $organizationStateStatement=$pdo->prepare("SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type='organization' AND public_id=?".$lock);
            $organizationStateStatement->execute([$targetPublicId]);$organizationState=$organizationStateStatement->fetch(PDO::FETCH_ASSOC);
            $bindingStatement=$pdo->prepare("SELECT public_id,CAST(resource_revision AS CHAR) resource_revision,resource_projection_sha256,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type='organization' AND external_id=?".$lock);
            $bindingStatement->execute([$appPk,$command['organization']['externalId']]);$binding=$bindingStatement->fetch(PDO::FETCH_ASSOC);
            if(!$organizationState||!$binding||(int)$organizationState['present']!==1||(string)$binding['status']!=='active'||!hash_equals((string)$binding['public_id'],$targetPublicId)
                ||(string)$organizationState['revision']!==$command['organization']['expectedRevision']||(string)$binding['resource_revision']!==$command['organization']['expectedRevision']
                ||!hash_equals((string)$binding['resource_projection_sha256'],(string)$organizationState['projection_sha256'])||!hash_equals((string)$organizationState['projection_sha256'],api_v2_directory_projection_hash('organization',$organization))){$pdo->rollBack();return['status'=>409];}
        }
        $authorization=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?'.$lock);$authorization->execute([$appPk]);$generation=$authorization->fetchColumn();
        if($generation===false||(string)$generation!==$command['expectedAuthorizationGeneration']||(string)$generation===PA_API_V2_AUTHORIZATION_GENERATION_MAX){$pdo->rollBack();return['status'=>409];}
        $update=$pdo->prepare('UPDATE clients SET organization_id=?,source_version=? WHERE id=? AND '.($client['organization_id']===null?'organization_id IS NULL':'organization_id=?'));
        $params=[$targetOrganizationId,portal_projection_source_version(),$clientId];if($client['organization_id']!==null)$params[]=(int)$client['organization_id'];$update->execute($params);
        if($update->rowCount()!==1)throw new DomainException('Client organization relationship changed.');
        if(!api_v2_directory_record($pdo,'client',$clientId))throw new RuntimeException('Client relationship revision unavailable');
        $after=$projection->clientScopes($pdo,$clientId);$projection->afterMutationProjectionOnly($pdo,array_merge($before,$after));
        api_v2_advance_authorization_generation($pdo,$appPk);
        $resultState=$pdo->prepare("SELECT CAST(revision AS CHAR) FROM api_v2_directory_resource_state WHERE resource_type='client' AND public_id=?");$resultState->execute([$clientPublicId]);$resultRevision=$resultState->fetchColumn();
        $resultGenerationStatement=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?');$resultGenerationStatement->execute([$appPk]);$resultGeneration=$resultGenerationStatement->fetchColumn();
        if($resultRevision===false||$resultGeneration===false)throw new RuntimeException('Directory relationship result unavailable');
        $pdo->prepare('INSERT INTO api_v2_directory_relationship_command_receipts(application_pk,history_epoch,command_id,request_sha256,action_name,client_public_id,expected_client_revision,expected_authorization_generation,result_client_revision,result_authorization_generation) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$appPk,$identity['history_epoch'],$command['commandId'],$requestHash,$action,$clientPublicId,$command['expectedClientRevision'],$command['expectedAuthorizationGeneration'],$resultRevision,$resultGeneration]);
        $pdo->commit();return['status'=>200,'payload'=>api_v2_directory_relationship_result($identity,$clientPublicId,$action,$targetPublicId,(string)$resultRevision,(string)$resultGeneration,$requestId,false)];
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')return['status'=>409];throw$error;}
}
