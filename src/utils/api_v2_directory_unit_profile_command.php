<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_revision.php';
require_once __DIR__ . '/api_v2_directory_lifecycle_command.php';
require_once __DIR__ . '/portal_projection_hooks.php';

function api_v2_directory_unit_profile_command_parse(string $json): ?array
{
    if (strlen($json)>32768) return null;
    try {$value=json_decode($json,true,8,JSON_THROW_ON_ERROR);} catch(Throwable){return null;}
    $fields=['commandId','expectedRevision','expectedAuthorizationGeneration','profile'];
    if(!is_array($value)||count($value)!==4||array_diff(array_keys($value),$fields)!==[]||array_diff($fields,array_keys($value))!==[]||!is_array($value['profile'])||array_keys($value['profile'])!==['name'])return null;
    if(!is_string($value['commandId'])||!is_string($value['expectedRevision'])||!is_string($value['expectedAuthorizationGeneration'])||!is_string($value['profile']['name'])
        ||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value['commandId'])!==1
        ||!api_v2_directory_lifecycle_positive($value['expectedRevision'])||!api_v2_directory_lifecycle_generation($value['expectedAuthorizationGeneration']))return null;
    $name=trim($value['profile']['name']);
    if($name===''||mb_strlen($name)>150||preg_match('//u',$name)!==1||preg_match('/\p{C}/u',$name)===1)return null;
    $value['profile']['name']=$name;return$value;
}

function api_v2_directory_unit_result(array$identity,string$publicId,string$revision,string$generation,string$requestId,bool$replayed):array
{
    return['sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'replayed'=>$replayed,
        'result'=>['resource'=>['type'=>'unit','publicId'=>$publicId,'revision'=>$revision],'authorizationGeneration'=>$generation]];
}

function api_v2_directory_unit_profile_command_write(PDO$pdo,string$publicId,array$command,int$apiKeyId,array$headers,string$requestId):array
{
    if(preg_match('/^[0-9a-f]{32}$/D',$publicId)!==1||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid unit profile command');
    $pdo->beginTransaction();
    try{
        api_v2_directory_management_acquire_shared_gate($pdo,false);$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $identityStatement=$pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL'.$lock);
        $identityStatement->execute([$apiKeyId]);$identity=$identityStatement->fetch(PDO::FETCH_ASSOC);
        if(!$identity||!api_v2_identity_is_valid($identity)||!hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))||!hash_equals((string)$identity['application_id'],(string)($headers['application']??''))||!hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))){$pdo->rollBack();return['status'=>409];}
        $appPk=(int)$identity['application_pk'];$requestHash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $receiptStatement=$pdo->prepare('SELECT request_sha256,public_id,CAST(expected_revision AS CHAR) expected_revision,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_revision AS CHAR) result_revision,CAST(result_authorization_generation AS CHAR) result_authorization_generation FROM api_v2_directory_unit_profile_command_receipts WHERE application_pk=? AND history_epoch=? AND command_id=?'.$lock);
        $receiptStatement->execute([$appPk,$identity['history_epoch'],$command['commandId']]);$receipt=$receiptStatement->fetch(PDO::FETCH_ASSOC);
        if($receipt&&(!hash_equals((string)$receipt['request_sha256'],$requestHash)||!hash_equals((string)$receipt['public_id'],$publicId)||(string)$receipt['expected_revision']!==$command['expectedRevision']||(string)$receipt['expected_authorization_generation']!==$command['expectedAuthorizationGeneration'])){$pdo->rollBack();return['status'=>409];}
        if($receipt){$pdo->commit();return['status'=>200,'payload'=>api_v2_directory_unit_result($identity,$publicId,(string)$receipt['result_revision'],(string)$receipt['result_authorization_generation'],$requestId,true)];}
        $source=$pdo->prepare('SELECT * FROM organization_departments WHERE public_id=?'.$lock);$source->execute([$publicId]);$unit=$source->fetch(PDO::FETCH_ASSOC);if(!$unit||(int)($unit['archived']??0)!==0||($unit['deleted_at']??null)!==null){$pdo->rollBack();return['status'=>409];}
        $stateStatement=$pdo->prepare("SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type='unit' AND public_id=?".$lock);$stateStatement->execute([$publicId]);$state=$stateStatement->fetch(PDO::FETCH_ASSOC);
        if(!$state||(int)$state['present']!==1||(string)$state['revision']!==$command['expectedRevision']||!hash_equals((string)$state['projection_sha256'],api_v2_directory_canonical_hash($pdo,'unit',$unit))){$pdo->rollBack();return['status'=>409];}
        $auth=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?'.$lock);$auth->execute([$appPk]);$generation=$auth->fetchColumn();if($generation===false||(string)$generation!==$command['expectedAuthorizationGeneration']){$pdo->rollBack();return['status'=>409];}
        if((string)$unit['name']!==$command['profile']['name']){$pdo->prepare('UPDATE organization_departments SET name=?,source_version=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$command['profile']['name'],portal_projection_source_version(),$unit['id']]);api_v2_directory_record($pdo,'unit',(int)$unit['id'],false);$projection=new App\Services\PortalProjectionMutationService();$projection->afterMutationProjectionOnly($pdo,$projection->organizationScopes($pdo,(int)$unit['organization_id']));}
        $resultStatement=$pdo->prepare("SELECT CAST(revision AS CHAR) revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type='unit' AND public_id=?");$resultStatement->execute([$publicId]);$result=$resultStatement->fetch(PDO::FETCH_ASSOC);if(!$result)throw new RuntimeException('Unit profile result unavailable');
        $pdo->prepare('INSERT INTO api_v2_directory_unit_profile_command_receipts(application_pk,history_epoch,command_id,request_sha256,public_id,expected_revision,expected_authorization_generation,result_revision,result_projection_sha256,result_authorization_generation) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$appPk,$identity['history_epoch'],$command['commandId'],$requestHash,$publicId,$command['expectedRevision'],$command['expectedAuthorizationGeneration'],$result['revision'],$result['projection_sha256'],$generation]);
        $pdo->commit();return['status'=>200,'payload'=>api_v2_directory_unit_result($identity,$publicId,(string)$result['revision'],(string)$generation,$requestId,false)];
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')return['status'=>409];throw$error;}
}
