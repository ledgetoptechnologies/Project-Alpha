<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_lifecycle_command.php';
require_once __DIR__ . '/api_v2_directory_create_command.php';

function api_v2_directory_unit_contact_command_parse(string$action,string$json):?array
{
    if(!in_array($action,['assign','remove','set-primary'],true)||strlen($json)>32768)return null;
    try{$value=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
    $fields=['commandId','expectedUnitRevision','expectedAuthorizationGeneration','client'];if($action==='assign')$fields[]='role';
    if(!is_array($value)||count($value)!==count($fields)||array_diff(array_keys($value),$fields)!==[]||array_diff($fields,array_keys($value))!==[])return null;
    foreach(['commandId','expectedUnitRevision','expectedAuthorizationGeneration']as$field)if(!is_string($value[$field]??null))return null;
    if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value['commandId'])!==1||!api_v2_directory_lifecycle_positive($value['expectedUnitRevision'])||!api_v2_directory_lifecycle_generation($value['expectedAuthorizationGeneration']))return null;
    $client=$value['client']??null;$clientFields=['externalId','expectedPublicId','expectedRevision'];
    if(!is_array($client)||count($client)!==3||array_diff(array_keys($client),$clientFields)!==[]||array_diff($clientFields,array_keys($client))!==[])return null;
    foreach($clientFields as$field)if(!is_string($client[$field]))return null;
    if(!api_v2_directory_create_external_id_valid($client['externalId'])||preg_match('/^[0-9a-f]{32}$/D',$client['expectedPublicId'])!==1||!api_v2_directory_lifecycle_positive($client['expectedRevision']))return null;
    if($action==='assign'){$role=trim((string)($value['role']??''));if($role===''||mb_strlen($role)>50||preg_match('//u',$role)!==1||preg_match('/\p{C}/u',$role)===1)return null;$value['role']=$role;}
    return$value;
}

function api_v2_directory_unit_contact_result(array$identity,string$action,string$unitPublicId,string$clientPublicId,string$revision,string$generation,string$requestId,bool$replayed):array
{
    return['sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'replayed'=>$replayed,
        'result'=>['action'=>$action,'resource'=>['type'=>'unit','publicId'=>$unitPublicId,'revision'=>$revision],'clientPublicId'=>$clientPublicId,'authorizationGeneration'=>$generation]];
}

/** Contacts change the unit projection and authorization topology atomically. */
function api_v2_directory_unit_contact_command_write(PDO$pdo,string$unitPublicId,string$action,array$command,int$apiKeyId,array$headers,string$requestId):array
{
    if(preg_match('/^[0-9a-f]{32}$/D',$unitPublicId)!==1||!in_array($action,['assign','remove','set-primary'],true)||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid unit contact command');
    $pdo->beginTransaction();
    try{
        api_v2_directory_management_acquire_shared_gate($pdo,false);$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $identityStatement=$pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL'.$lock);$identityStatement->execute([$apiKeyId]);$identity=$identityStatement->fetch(PDO::FETCH_ASSOC);
        if(!$identity||!api_v2_identity_is_valid($identity)||!hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))||!hash_equals((string)$identity['application_id'],(string)($headers['application']??''))||!hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))){$pdo->rollBack();return['status'=>409];}
        $appPk=(int)$identity['application_pk'];$requestHash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$storedAction=str_replace('-','_',$action);
        $receiptStatement=$pdo->prepare('SELECT request_sha256,action_name,unit_public_id,client_public_id,CAST(expected_unit_revision AS CHAR) expected_unit_revision,CAST(expected_authorization_generation AS CHAR) expected_authorization_generation,CAST(result_unit_revision AS CHAR) result_unit_revision,CAST(result_authorization_generation AS CHAR) result_authorization_generation FROM api_v2_directory_unit_contact_command_receipts WHERE application_pk=? AND history_epoch=? AND command_id=?'.$lock);$receiptStatement->execute([$appPk,$identity['history_epoch'],$command['commandId']]);$receipt=$receiptStatement->fetch(PDO::FETCH_ASSOC);
        if($receipt&&(!hash_equals((string)$receipt['request_sha256'],$requestHash)||(string)$receipt['action_name']!==$storedAction||!hash_equals((string)$receipt['unit_public_id'],$unitPublicId)||!hash_equals((string)$receipt['client_public_id'],$command['client']['expectedPublicId'])||(string)$receipt['expected_unit_revision']!==$command['expectedUnitRevision']||(string)$receipt['expected_authorization_generation']!==$command['expectedAuthorizationGeneration'])){$pdo->rollBack();return['status'=>409];}
        if($receipt){$pdo->commit();return['status'=>200,'payload'=>api_v2_directory_unit_contact_result($identity,$action,$unitPublicId,(string)$receipt['client_public_id'],(string)$receipt['result_unit_revision'],(string)$receipt['result_authorization_generation'],$requestId,true)];}

        // Global source order is organization -> client -> unit -> contacts,
        // followed by revision state -> bindings -> authorization generation.
        $candidate=$pdo->prepare('SELECT organization_id FROM organization_departments WHERE public_id=?');$candidate->execute([$unitPublicId]);$organizationId=(int)($candidate->fetchColumn()?:0);
        $organizationStatement=$pdo->prepare('SELECT * FROM organizations WHERE id=?'.$lock);$organizationStatement->execute([$organizationId]);$organization=$organizationStatement->fetch(PDO::FETCH_ASSOC);
        $clientStatement=$pdo->prepare('SELECT * FROM clients WHERE public_id=?'.$lock);$clientStatement->execute([$command['client']['expectedPublicId']]);$client=$clientStatement->fetch(PDO::FETCH_ASSOC);
        $unitStatement=$pdo->prepare('SELECT * FROM organization_departments WHERE public_id=?'.$lock);$unitStatement->execute([$unitPublicId]);$unit=$unitStatement->fetch(PDO::FETCH_ASSOC);
        if(!$organization||!$unit||!$client||(int)$unit['organization_id']!==$organizationId||(int)($unit['archived']??0)!==0||($unit['deleted_at']??null)!==null||(int)($client['archived']??0)!==0||($client['deleted_at']??null)!==null||(int)$unit['organization_id']!==(int)$client['organization_id']){$pdo->rollBack();return['status'=>409];}
        $contactLocks=$pdo->prepare('SELECT client_id FROM organization_department_contacts WHERE department_id=? ORDER BY client_id'.$lock);$contactLocks->execute([$unit['id']]);$contactLocks->fetchAll(PDO::FETCH_COLUMN);
        $states=$pdo->prepare('SELECT resource_type,public_id,CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE (resource_type=? AND public_id=?) OR (resource_type=? AND public_id=?) OR (resource_type=? AND public_id=(SELECT public_id FROM organizations WHERE id=?)) ORDER BY resource_type,public_id'.$lock);
        $states->execute(['unit',$unitPublicId,'client',$client['public_id'],'organization',$unit['organization_id']]);$stateByType=[];foreach($states->fetchAll(PDO::FETCH_ASSOC)as$row)$stateByType[$row['resource_type']]=$row;
        $unitState=$stateByType['unit']??null;$clientState=$stateByType['client']??null;$organizationState=$stateByType['organization']??null;
        if(!$unitState||!$clientState||!$organizationState||(int)$unitState['present']!==1||(int)$clientState['present']!==1||(int)$organizationState['present']!==1||(string)$unitState['revision']!==$command['expectedUnitRevision']||(string)$clientState['revision']!==$command['client']['expectedRevision']||!hash_equals((string)$unitState['projection_sha256'],api_v2_directory_canonical_hash($pdo,'unit',$unit))||!hash_equals((string)$clientState['projection_sha256'],api_v2_directory_canonical_hash($pdo,'client',$client))||!hash_equals((string)$organizationState['projection_sha256'],api_v2_directory_canonical_hash($pdo,'organization',$organization))){$pdo->rollBack();return['status'=>409];}
        $bindings=$pdo->prepare("SELECT resource_type,external_id,public_id,CAST(resource_revision AS CHAR) resource_revision,resource_projection_sha256,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND status='active' AND ((resource_type='unit' AND public_id=?) OR (resource_type='client' AND external_id=?) OR (resource_type='organization' AND public_id=(SELECT public_id FROM organizations WHERE id=?))) ORDER BY resource_type,public_id".$lock);
        $bindings->execute([$appPk,$unitPublicId,$command['client']['externalId'],$unit['organization_id']]);$bindingByType=[];foreach($bindings->fetchAll(PDO::FETCH_ASSOC)as$row)$bindingByType[$row['resource_type']]=$row;
        foreach(['unit'=>$unitState,'client'=>$clientState,'organization'=>$organizationState]as$type=>$state){$binding=$bindingByType[$type]??null;if(!$binding||!hash_equals((string)$binding['public_id'],(string)$state['public_id'])||(string)$binding['resource_revision']!==(string)$state['revision']||!hash_equals((string)$binding['resource_projection_sha256'],(string)$state['projection_sha256'])){$pdo->rollBack();return['status'=>409];}}
        if(!hash_equals((string)$bindingByType['client']['public_id'],$command['client']['expectedPublicId'])||!hash_equals((string)$bindingByType['client']['external_id'],$command['client']['externalId'])){$pdo->rollBack();return['status'=>409];}
        $auth=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?'.$lock);$auth->execute([$appPk]);$generation=$auth->fetchColumn();if($generation===false||(string)$generation!==$command['expectedAuthorizationGeneration']||(string)$generation===PA_API_V2_AUTHORIZATION_GENERATION_MAX){$pdo->rollBack();return['status'=>409];}
        $assignment=$pdo->prepare('SELECT role,is_primary FROM organization_department_contacts WHERE department_id=? AND client_id=?'.$lock);$assignment->execute([$unit['id'],$client['id']]);$existing=$assignment->fetch(PDO::FETCH_ASSOC);
        if($action==='assign'){
            if($existing){$pdo->prepare('UPDATE organization_department_contacts SET role=? WHERE department_id=? AND client_id=?')->execute([$command['role'],$unit['id'],$client['id']]);}
            else{$pdo->prepare('INSERT INTO organization_department_contacts(department_id,client_id,role,is_primary) VALUES(?,?,?,0)')->execute([$unit['id'],$client['id'],$command['role']]);}
        }elseif(!$existing){$pdo->rollBack();return['status'=>409];}
        elseif($action==='remove'){$pdo->prepare('DELETE FROM organization_department_contacts WHERE department_id=? AND client_id=?')->execute([$unit['id'],$client['id']]);}
        else{$pdo->prepare('UPDATE organization_department_contacts SET is_primary=0 WHERE department_id=?')->execute([$unit['id']]);$pdo->prepare('UPDATE organization_department_contacts SET is_primary=1 WHERE department_id=? AND client_id=?')->execute([$unit['id'],$client['id']]);}
        $pdo->prepare('UPDATE organization_departments SET source_version=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([portal_projection_source_version(),$unit['id']]);api_v2_directory_record($pdo,'unit',(int)$unit['id'],false);
        $projection=new App\Services\PortalProjectionMutationService();$projection->afterMutationProjectionOnly($pdo,$projection->organizationScopes($pdo,(int)$unit['organization_id']));api_v2_advance_authorization_generation($pdo,$appPk);
        $resultState=$pdo->prepare("SELECT CAST(revision AS CHAR) revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type='unit' AND public_id=?");$resultState->execute([$unitPublicId]);$result=$resultState->fetch(PDO::FETCH_ASSOC);$resultGeneration=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_directory_authorization_state WHERE application_pk=?');$resultGeneration->execute([$appPk]);$resultGeneration=$resultGeneration->fetchColumn();if(!$result||$resultGeneration===false)throw new RuntimeException('Unit contact result unavailable');
        $pdo->prepare('INSERT INTO api_v2_directory_unit_contact_command_receipts(application_pk,history_epoch,command_id,request_sha256,action_name,unit_public_id,client_public_id,expected_unit_revision,expected_authorization_generation,result_unit_revision,result_projection_sha256,result_authorization_generation) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$appPk,$identity['history_epoch'],$command['commandId'],$requestHash,$storedAction,$unitPublicId,$client['public_id'],$command['expectedUnitRevision'],$command['expectedAuthorizationGeneration'],$result['revision'],$result['projection_sha256'],$resultGeneration]);
        $pdo->commit();return['status'=>200,'payload'=>api_v2_directory_unit_contact_result($identity,$action,$unitPublicId,(string)$client['public_id'],(string)$result['revision'],(string)$resultGeneration,$requestId,false)];
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')return['status'=>409];throw$error;}
}
