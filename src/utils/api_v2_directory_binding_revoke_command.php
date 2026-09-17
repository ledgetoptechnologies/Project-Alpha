<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_lifecycle_command.php';
require_once __DIR__ . '/api_v2_directory_relationship_command.php';

function api_v2_directory_binding_revoke_command_parse(string$json):?array
{
    if(strlen($json)>32*1024)return null;
    try{$value=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
    $fields=['commandId','externalId','expectedPublicId','expectedRevision','expectedAuthorizationGeneration'];
    if(!is_array($value)||count($value)!==5||array_diff(array_keys($value),$fields)!==[]||array_diff($fields,array_keys($value))!==[])return null;
    foreach($fields as$field)if(!is_string($value[$field]))return null;
    if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value['commandId'])!==1
        ||!api_v2_directory_relationship_external_id_valid($value['externalId'])||preg_match('/^[0-9a-f]{32}$/D',$value['expectedPublicId'])!==1
        ||!api_v2_directory_lifecycle_positive($value['expectedRevision'])||!api_v2_directory_lifecycle_generation($value['expectedAuthorizationGeneration']))return null;
    return array_intersect_key($value,array_flip($fields));
}

function api_v2_directory_binding_revoke_result(array$identity,string$type,array$command,string$generation,string$requestId,bool$replayed):array
{
    return['sourceInstanceId'=>(string)$identity['source_instance_id'],'applicationId'=>(string)$identity['application_id'],'historyEpoch'=>(string)$identity['history_epoch'],
        'requestId'=>$requestId,'replayed'=>$replayed,'result'=>['action'=>'revoke','binding'=>['resourceType'=>$type,'externalId'=>$command['externalId'],
            'publicId'=>$command['expectedPublicId'],'resourceRevision'=>$command['expectedRevision'],'status'=>'tombstoned'],'authorizationGeneration'=>$generation]];
}

function api_v2_directory_binding_revoke_command_write(PDO$pdo,string$type,array$command,int$apiKeyId,array$headers,string$requestId):array
{
    if(!in_array($type,['client','organization'],true)||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid directory binding revoke command');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $identityStatement=$pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL'.$lock);
        $identityStatement->execute([$apiKeyId]);$identity=$identityStatement->fetch(PDO::FETCH_ASSOC);
        if(!$identity||!api_v2_identity_is_valid($identity)||!hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))||!hash_equals((string)$identity['application_id'],(string)($headers['application']??''))||!hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))){$pdo->rollBack();return['status'=>409];}
        $appPk=(int)$identity['application_pk'];$requestHash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $receiptStatement=$pdo->prepare('SELECT request_sha256,external_id,public_id,CAST(expected_resource_revision AS CHAR) expected_resource_revision,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_authorization_generation AS CHAR) result_authorization_generation FROM api_v2_directory_binding_revoke_command_receipts WHERE application_pk=? AND resource_type=? AND history_epoch=? AND command_id=?'.$lock);
        $receiptStatement->execute([$appPk,$type,$identity['history_epoch'],$command['commandId']]);$receipt=$receiptStatement->fetch(PDO::FETCH_ASSOC);
        if($receipt&&(!hash_equals((string)$receipt['request_sha256'],$requestHash)||!hash_equals((string)$receipt['external_id'],$command['externalId'])||!hash_equals((string)$receipt['public_id'],$command['expectedPublicId'])||(string)$receipt['expected_resource_revision']!==$command['expectedRevision']||(string)$receipt['expected_authorization_generation']!==$command['expectedAuthorizationGeneration'])){$pdo->rollBack();return['status'=>409];}
        if($receipt){$pdo->commit();return['status'=>200,'payload'=>api_v2_directory_binding_revoke_result($identity,$type,$command,(string)$receipt['result_authorization_generation'],$requestId,true)];}
        $stateStatement=$pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?'.$lock);
        $stateStatement->execute([$type,$command['expectedPublicId']]);$state=$stateStatement->fetch(PDO::FETCH_ASSOC);
        $bindingStatement=$pdo->prepare('SELECT public_id,CAST(resource_revision AS CHAR) resource_revision,resource_projection_sha256,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND external_id=?'.$lock);
        $bindingStatement->execute([$appPk,$type,$command['externalId']]);$binding=$bindingStatement->fetch(PDO::FETCH_ASSOC);
        if(!$state||!$binding||(int)$state['present']!==1||(string)$binding['status']!=='active'||!hash_equals((string)$binding['public_id'],$command['expectedPublicId'])
            ||(string)$state['revision']!==$command['expectedRevision']||(string)$binding['resource_revision']!==$command['expectedRevision']
            ||!hash_equals((string)$binding['resource_projection_sha256'],(string)$state['projection_sha256'])){$pdo->rollBack();return['status'=>409];}
        $authorization=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?'.$lock);$authorization->execute([$appPk]);$generation=$authorization->fetchColumn();
        if($generation===false||(string)$generation!==$command['expectedAuthorizationGeneration']||(string)$generation===PA_API_V2_AUTHORIZATION_GENERATION_MAX){$pdo->rollBack();return['status'=>409];}
        $update=$pdo->prepare("UPDATE api_v2_directory_external_bindings SET status='tombstoned',tombstoned_at=CURRENT_TIMESTAMP WHERE application_pk=? AND resource_type=? AND external_id=? AND public_id=? AND status='active'");
        $update->execute([$appPk,$type,$command['externalId'],$command['expectedPublicId']]);if($update->rowCount()!==1)throw new RuntimeException('Directory binding revocation was incomplete');
        api_v2_advance_authorization_generation($pdo,$appPk);
        $resultGenerationStatement=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?');$resultGenerationStatement->execute([$appPk]);$resultGeneration=$resultGenerationStatement->fetchColumn();if($resultGeneration===false)throw new RuntimeException('Binding revocation generation unavailable');
        $pdo->prepare('INSERT INTO api_v2_directory_binding_revoke_command_receipts(application_pk,resource_type,history_epoch,command_id,request_sha256,external_id,public_id,expected_resource_revision,expected_authorization_generation,result_authorization_generation) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$appPk,$type,$identity['history_epoch'],$command['commandId'],$requestHash,$command['externalId'],$command['expectedPublicId'],$command['expectedRevision'],$command['expectedAuthorizationGeneration'],$resultGeneration]);
        $pdo->commit();return['status'=>200,'payload'=>api_v2_directory_binding_revoke_result($identity,$type,$command,(string)$resultGeneration,$requestId,false)];
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')return['status'=>409];throw$error;}
}
