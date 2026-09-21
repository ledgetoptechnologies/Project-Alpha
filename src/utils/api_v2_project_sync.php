<?php
declare(strict_types=1);

use App\Services\ProjectRevisionService;

require_once __DIR__ . '/api_v2_project_lifecycle.php';
require_once __DIR__ . '/api_v2_directory_revision.php';
require_once __DIR__ . '/../services/ScheduleService.php';

const PA_API_V2_PROJECT_GENERATION_MAX = '9223372036854775807';

function api_v2_project_source_version():string{return'v-'.bin2hex(random_bytes(16));}

function api_v2_project_external_id_valid(string $value): bool
{
    if ($value === '' || strlen($value) > 764 || preg_match('//u', $value) !== 1 || preg_match('/\p{C}/u', $value) === 1) return false;
    $count = preg_match_all('/./us', $value);
    return $count !== false && $count >= 1 && $count <= 191;
}

function api_v2_project_generation_valid(string $value): bool
{
    return preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1
        && (strlen($value) < 19 || strcmp($value, PA_API_V2_PROJECT_GENERATION_MAX) <= 0);
}

function api_v2_project_hash_valid(string $value): bool
{
    return preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
}

function api_v2_project_profile_parse(mixed $value): ?array
{
    $fields = ['name','description','estimatedStart','estimatedEnd'];
    if (!is_array($value) || array_keys($value) !== $fields) return null;
    foreach ($fields as $field) {
        if ($value[$field] !== null && !is_string($value[$field])) return null;
        if (is_string($value[$field]) && (preg_match('//u', $value[$field]) !== 1 || preg_match('/\p{C}/u', $value[$field]) === 1)) return null;
    }
    $name = trim((string)$value['name']);
    $description = $value['description'] === null ? null : trim($value['description']);
    $start = $value['estimatedStart'] === null ? null : trim($value['estimatedStart']);
    $end = $value['estimatedEnd'] === null ? null : trim($value['estimatedEnd']);
    if ($name === '' || mb_strlen($name) > 150 || ($description !== null && mb_strlen($description) > 10000)) return null;
    foreach ([$start,$end] as $date) {
        if ($date !== null && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) return null;
        if ($date !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
            if (!$parsed || $parsed->format('Y-m-d') !== $date) return null;
        }
    }
    if ($start !== null && $end !== null && $start > $end) return null;
    return ['name'=>$name,'description'=>$description === '' ? null : $description,'estimatedStart'=>$start,'estimatedEnd'=>$end];
}

function api_v2_project_relation_parse(mixed $value,bool $required): ?array
{
    if($value===null)return$required?null:[];
    $fields=['externalId','expectedPublicId','expectedRevision','expectedProjectionSha256'];
    if(!is_array($value)||array_keys($value)!==$fields)return null;
    foreach($fields as$field)if(!is_string($value[$field]))return null;
    return api_v2_project_external_id_valid($value['externalId'])
        &&preg_match('/^[0-9a-f]{32}$/D',$value['expectedPublicId'])===1
        &&ProjectRevisionService::positiveInteger($value['expectedRevision'])
        &&api_v2_project_hash_valid($value['expectedProjectionSha256'])?$value:null;
}

function api_v2_project_sync_command_parse(string $type, string $json): ?array
{
    if (!in_array($type, ['bind','create','update','refresh'], true) || strlen($json) > 32 * 1024) return null;
    try { $value=json_decode($json,true,8,JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    $fields = match($type) {
        'bind'=>['commandId','externalId','expectedPublicId','expectedRevision','expectedProjectionSha256','expectedAuthorizationGeneration'],
        'create'=>['commandId','externalId','expectedAuthorizationGeneration','project','organization','client'],
        'update'=>['commandId','externalId','expectedRevision','expectedProjectionSha256','expectedAuthorizationGeneration','project'],
        'refresh'=>['commandId','externalId','expectedPublicId','expectedPriorRevision','expectedRevision','expectedProjectionSha256','expectedAuthorizationGeneration'],
    };
    if (!is_array($value) || array_keys($value) !== $fields) return null;
    foreach (array_diff($fields,['project','organization','client']) as $field) if (!is_string($value[$field])) return null;
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value['commandId'])!==1
        || !api_v2_project_external_id_valid($value['externalId'])
        || !api_v2_project_generation_valid($value['expectedAuthorizationGeneration'])) return null;
    if ($type !== 'create' && (!ProjectRevisionService::positiveInteger($value['expectedRevision']) || !api_v2_project_hash_valid($value['expectedProjectionSha256']))) return null;
    if (in_array($type,['bind','refresh'],true) && preg_match('/^[0-9a-f]{32}$/D',$value['expectedPublicId'])!==1) return null;
    if ($type==='refresh'&&!ProjectRevisionService::positiveInteger($value['expectedPriorRevision']))return null;
    if (!in_array($type,['bind','refresh'],true)) {
        $profile=api_v2_project_profile_parse($value['project']);
        if ($profile===null) return null;
        $value['project']=$profile;
    }
    if($type==='create'){
        $organization=api_v2_project_relation_parse($value['organization'],true);$client=api_v2_project_relation_parse($value['client'],false);
        if($organization===null||$client===null)return null;$value['organization']=$organization;$value['client']=$client===[]?null:$client;
    }
    return $value;
}

function api_v2_project_resolve_directory_binding(PDO $pdo,int $appPk,string $type,array $proof,string $lock):?array
{
    $table=$type==='organization'?'organizations':'clients';
    $source=$pdo->prepare('SELECT * FROM '.$table.' WHERE public_id=?'.$lock);$source->execute([$proof['expectedPublicId']]);$row=$source->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
    $state=$pdo->prepare('SELECT CAST(revision AS CHAR) revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?'.$lock);$state->execute([$type,$proof['expectedPublicId']]);$state=$state->fetch(PDO::FETCH_ASSOC);
    $binding=$pdo->prepare("SELECT public_id,CAST(resource_revision AS CHAR) resource_revision,resource_projection_sha256,status FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND external_id=?".$lock);$binding->execute([$appPk,$type,$proof['externalId']]);$binding=$binding->fetch(PDO::FETCH_ASSOC);
    if(!$state||!$binding||(int)$state['present']!==1||(string)$binding['status']!=='active'||(string)$binding['public_id']!==$proof['expectedPublicId']||(string)$binding['resource_revision']!==$proof['expectedRevision']||(string)$state['revision']!==$proof['expectedRevision']||!hash_equals((string)$binding['resource_projection_sha256'],$proof['expectedProjectionSha256'])||!hash_equals((string)$state['projection_sha256'],$proof['expectedProjectionSha256'])||!hash_equals(api_v2_directory_projection_hash($type,$row),$proof['expectedProjectionSha256']))return null;
    return$row;
}

function api_v2_project_sync_identity(PDO $pdo,int $apiKeyId,array $headers,bool $lock):?array
{
    $identity=api_v2_project_identity($pdo,$apiKeyId,$headers,$lock);
    if (!$identity) return null;
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $statement=$pdo->prepare('SELECT CAST(authorization_generation AS CHAR) FROM api_v2_project_authorization_state WHERE application_pk=?'.$suffix);
    $statement->execute([$identity['application_pk']]);$generation=$statement->fetchColumn();
    if ($generation===false||!api_v2_project_generation_valid((string)$generation)) return null;
    $identity['authorization_generation']=(string)$generation;
    return $identity;
}

function api_v2_project_sync_result(array $identity,string $externalId,string $publicId,string $revision,string $hash,string $generation,string $requestId,bool $replayed,bool $portalPublished,bool $publicLinkEnabled):array
{
    return ['apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],
        'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'replayed'=>$replayed,
        'result'=>['resource'=>['type'=>'project','id'=>$externalId,'publicId'=>$publicId,'revision'=>$revision,'projectionSha256'=>$hash],
            'authorizationGeneration'=>$generation,'presentation'=>['portalPublished'=>$portalPublished,'publicLinkEnabled'=>$publicLinkEnabled]]];
}

/** @return array{status:int,payload?:array} */
function api_v2_project_sync_write(PDO $pdo,string $type,array $command,int $apiKeyId,array $headers,string $requestId):array
{
    if(!in_array($type,['bind','create','update','refresh'],true)||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid Project synchronization command.');
    $pdo->beginTransaction();
    try {
        // Universal writer order: application -> Project authorization ->
        // source Project -> binding. The application lock serializes all keys.
        $identity=api_v2_project_sync_identity($pdo,$apiKeyId,$headers,true);
        if(!$identity){$pdo->rollBack();return['status'=>409];}
        $appPk=(int)$identity['application_pk'];$generation=(string)$identity['authorization_generation'];
        $requestHash=hash('sha256',json_encode(['type'=>$type,'command'=>$command],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $receipt=$pdo->prepare('SELECT command_type,request_sha256,external_id,project_public_id,CAST(result_revision AS CHAR) result_revision,result_projection_sha256,CAST(result_authorization_generation AS CHAR) result_authorization_generation,result_portal_publish_enabled,result_public_project_enabled FROM api_v2_project_command_receipts WHERE application_pk=? AND history_epoch=? AND command_id=?');
        $receipt->execute([$appPk,$identity['history_epoch'],$command['commandId']]);$stored=$receipt->fetch(PDO::FETCH_ASSOC);
        if($stored){
            if((string)$stored['command_type']!==$type||!hash_equals((string)$stored['request_sha256'],$requestHash)||!hash_equals((string)$stored['external_id'],$command['externalId'])){$pdo->rollBack();return['status'=>409];}
            $payload=api_v2_project_sync_result($identity,$command['externalId'],(string)$stored['project_public_id'],(string)$stored['result_revision'],(string)$stored['result_projection_sha256'],(string)$stored['result_authorization_generation'],$requestId,true,(bool)$stored['result_portal_publish_enabled'],(bool)$stored['result_public_project_enabled']);
            $pdo->commit();return['status'=>$type==='create'?201:200,'payload'=>$payload];
        }
        if($generation!==$command['expectedAuthorizationGeneration']||$generation===PA_API_V2_PROJECT_GENERATION_MAX){$pdo->rollBack();return['status'=>409];}
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $project=null;$publicId='';
        if(in_array($type,['bind','refresh'],true))$publicId=$command['expectedPublicId'];
        elseif($type==='update'){
            $lookup=$pdo->prepare('SELECT project_public_id FROM api_v2_project_external_bindings WHERE application_pk=? AND external_id=?');
            $lookup->execute([$appPk,$command['externalId']]);$publicId=(string)($lookup->fetchColumn()?:'');
            if($publicId===''){$pdo->rollBack();return['status'=>409];}
        }
        if($type!=='create'){
            $statement=$pdo->prepare('SELECT * FROM projects WHERE public_id=?'.$lock);$statement->execute([$publicId]);$project=$statement->fetch(PDO::FETCH_ASSOC);
            if($project)$project=api_v2_project_hydrate_relations($pdo,$project);
            if(!$project||($type==='update'&&$project['archived_at']!==null)||(string)$project['revision']!==$command['expectedRevision']||!hash_equals(ProjectRevisionService::projectionHash($project),$command['expectedProjectionSha256'])||!api_v2_project_revision_matches($pdo,$project,$lock)){$pdo->rollBack();return['status'=>409];}
        }
        $bindings=$pdo->prepare('SELECT external_id,project_public_id FROM api_v2_project_external_bindings WHERE application_pk=? AND (external_id=? OR project_public_id=?)'.$lock);
        $bindings->execute([$appPk,$command['externalId'],$publicId?:str_repeat('0',32)]);$bindingRows=$bindings->fetchAll(PDO::FETCH_ASSOC);
        if($type==='bind'&&$bindingRows!==[]){$pdo->rollBack();return['status'=>409];}
        if(in_array($type,['update','refresh'],true)&&(count($bindingRows)!==1||!hash_equals((string)$bindingRows[0]['external_id'],$command['externalId'])||(string)$bindingRows[0]['project_public_id']!==$publicId)){$pdo->rollBack();return['status'=>409];}
        if($type==='refresh'){$currentBinding=$pdo->prepare('SELECT CAST(project_revision AS CHAR) project_revision FROM api_v2_project_external_bindings WHERE application_pk=? AND external_id=? AND project_public_id=?'.$lock);$currentBinding->execute([$appPk,$command['externalId'],$publicId]);if((string)$currentBinding->fetchColumn()!==$command['expectedPriorRevision']){$pdo->rollBack();return['status'=>409];}}
        if($type==='create'){
            $existing=$pdo->prepare('SELECT 1 FROM api_v2_project_external_bindings WHERE application_pk=? AND external_id=?'.$lock);$existing->execute([$appPk,$command['externalId']]);
            if($existing->fetchColumn()!==false){$pdo->rollBack();return['status'=>409];}
            $organization=api_v2_project_resolve_directory_binding($pdo,$appPk,'organization',$command['organization'],$lock);$client=$command['client']===null?null:api_v2_project_resolve_directory_binding($pdo,$appPk,'client',$command['client'],$lock);
            if(!$organization||($command['client']!==null&&!$client)||($client&&((int)($client['organization_id']??0)!==(int)$organization['id']))){$pdo->rollBack();return['status'=>409];}
            $publicId=bin2hex(random_bytes(16));$profile=$command['project'];
            $insert=$pdo->prepare("INSERT INTO projects(public_id,name,description,status,organization_id,client_id,invoice_billing_period,project_invoice_auto_email,portal_publish_enabled,public_project_enabled,estimated_start,estimated_end,source_version,created_by) VALUES(?,?,?,'not_started',?,?,'per_invoice',0,0,0,?,?,?,NULL)");
            $insert->execute([$publicId,$profile['name'],$profile['description'],$organization['id'],$client['id']??null,$profile['estimatedStart'],$profile['estimatedEnd'],api_v2_project_source_version()]);
            $projectId=(int)$pdo->lastInsertId();if($projectId<1)throw new RuntimeException('Project identity unavailable.');
            $project=(new ProjectRevisionService($pdo))->initialize($projectId,'create',$appPk,$command['commandId']);
        } elseif($type==='update') {
            $profile=$command['project'];
            $changed=(string)$project['name']!==$profile['name']||($project['description']??null)!==$profile['description']||($project['estimated_start']??null)!==$profile['estimatedStart']||($project['estimated_end']??null)!==$profile['estimatedEnd'];
            if($changed){
                $pdo->prepare('UPDATE projects SET name=?,description=?,estimated_start=?,estimated_end=?,source_version=? WHERE id=?')
                    ->execute([$profile['name'],$profile['description'],$profile['estimatedStart'],$profile['estimatedEnd'],api_v2_project_source_version(),$project['id']]);
                $project=(new ProjectRevisionService($pdo))->advance((int)$project['id'],'update',$appPk,$command['commandId']);
            }
        }
        if(in_array($type,['create','update'],true)){ScheduleService::syncProject($pdo,(int)$project['id'],getenv('APP_TIMEZONE')?:'UTC',null);$project=api_v2_project_hydrate_relations($pdo,$project);}
        $revision=(string)$project['revision'];$hash=ProjectRevisionService::projectionHash($project);
        if($type==='bind'||$type==='create'){
            $pdo->prepare('INSERT INTO api_v2_project_external_bindings(application_pk,external_id,project_public_id,project_revision,project_projection_sha256) VALUES(?,?,?,?,?)')
                ->execute([$appPk,$command['externalId'],$publicId,$revision,$hash]);
            $advance=$pdo->prepare('UPDATE api_v2_project_authorization_state SET authorization_generation=authorization_generation+1 WHERE application_pk=? AND authorization_generation=?');$advance->execute([$appPk,$generation]);if($advance->rowCount()!==1)throw new RuntimeException('Project authorization generation changed.');
            $generation=(string)((int)$generation+1);
        } else {
            $pdo->prepare('UPDATE api_v2_project_external_bindings SET project_revision=?,project_projection_sha256=? WHERE application_pk=? AND external_id=? AND project_public_id=?')
                ->execute([$revision,$hash,$appPk,$command['externalId'],$publicId]);
            if($type==='refresh'){$advance=$pdo->prepare('UPDATE api_v2_project_authorization_state SET authorization_generation=authorization_generation+1 WHERE application_pk=? AND authorization_generation=?');$advance->execute([$appPk,$generation]);if($advance->rowCount()!==1)throw new RuntimeException('Project authorization generation changed.');$generation=(string)((int)$generation+1);}
        }
        $portalPublished=!empty($project['portal_publish_enabled']);$publicLinkEnabled=!empty($project['public_project_enabled']);
        $pdo->prepare('INSERT INTO api_v2_project_command_receipts(application_pk,history_epoch,command_id,command_type,request_sha256,external_id,project_public_id,expected_revision,expected_prior_revision,expected_projection_sha256,expected_authorization_generation,result_revision,result_projection_sha256,result_authorization_generation,result_portal_publish_enabled,result_public_project_enabled) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$appPk,$identity['history_epoch'],$command['commandId'],$type,$requestHash,$command['externalId'],$publicId,$command['expectedRevision']??null,$command['expectedPriorRevision']??null,$command['expectedProjectionSha256']??null,$command['expectedAuthorizationGeneration'],$revision,$hash,$generation,$portalPublished?1:0,$publicLinkEnabled?1:0]);
        $payload=api_v2_project_sync_result($identity,$command['externalId'],$publicId,$revision,$hash,$generation,$requestId,false,$portalPublished,$publicLinkEnabled);
        $pdo->commit();return['status'=>$type==='create'?201:200,'payload'=>$payload];
    } catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')return['status'=>409];throw$error;}
}

function api_v2_project_binding_evidence_payload(PDO $pdo,array $identity,string $externalId,string $publicId,array $binding,array $project,string $requestId,string $lock=''):?array
{
    $bindingRevision=(string)($binding['project_revision']??'');$bindingHash=(string)($binding['project_projection_sha256']??'');$liveRevision=(string)($project['revision']??'');$liveHash=ProjectRevisionService::projectionHash($project);
    if(!ProjectRevisionService::positiveInteger($bindingRevision)||!api_v2_project_hash_valid($bindingHash)||!ProjectRevisionService::positiveInteger($liveRevision)||!api_v2_project_hash_valid($liveHash))return null;
    // Refresh only fences the pinned prior revision. Hash-only drift at the same
    // revision, or a binding ahead of canonical history, is corruption rather
    // than a recoverable stale binding and must remain a bare conflict.
    $bindingIsOlder=strlen($bindingRevision)<strlen($liveRevision)||(strlen($bindingRevision)===strlen($liveRevision)&&strcmp($bindingRevision,$liveRevision)<0);
    if(!$bindingIsOlder)return null;
    // The old pin must itself be authentic immutable history. Refresh does not
    // fence the old hash, so accepting a merely well-formed tampered hash here
    // would let refresh silently overwrite evidence of binding corruption.
    $history=$pdo->prepare('SELECT projection_sha256 FROM project_changes WHERE project_public_id=? AND revision=?'.$lock);$history->execute([$publicId,$bindingRevision]);$historyHash=$history->fetchColumn();
    if(!is_string($historyHash)||!api_v2_project_hash_valid($historyHash)||!hash_equals($historyHash,$bindingHash))return null;
    return['apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'error'=>['code'=>'binding_stale'],'authorizationGeneration'=>$identity['authorization_generation'],'binding'=>['externalId'=>$externalId,'publicId'=>$publicId,'revision'=>$bindingRevision],'resource'=>['revision'=>$liveRevision,'projectionSha256'=>$liveHash]];
}

/** Application-scoped inventory: unbound PA/browser Projects are not listed. */
function api_v2_project_inventory_read(PDO $pdo,?string $cursor,int $limit,int $apiKeyId,array $headers,string $requestId):array
{
    if($limit<1||$limit>200||($cursor!==null&&!api_v2_project_external_id_valid($cursor))||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid Project inventory request.');
    $pdo->beginTransaction();
    try{$identity=api_v2_project_sync_identity($pdo,$apiKeyId,$headers,false);if(!$identity){$pdo->rollBack();return['status'=>409];}
        $params=[(int)$identity['application_pk']];$where=' WHERE binding.application_pk=?';if($cursor!==null){$where.=' AND binding.external_id>?';$params[]=$cursor;}
        $statement=$pdo->prepare('SELECT binding.external_id,binding.project_public_id,CAST(binding.project_revision AS CHAR) project_revision,binding.project_projection_sha256,project.archived_at,project.status FROM api_v2_project_external_bindings binding JOIN projects project ON project.public_id=binding.project_public_id'.$where.' ORDER BY binding.external_id LIMIT '.($limit+1));
        $statement->execute($params);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>$limit;if($more)array_pop($rows);$resources=[];
        foreach($rows as$row){$live=$pdo->prepare('SELECT * FROM projects WHERE public_id=?');$live->execute([$row['project_public_id']]);$project=$live->fetch(PDO::FETCH_ASSOC);if($project)$project=api_v2_project_hydrate_relations($pdo,$project);if(!$project||!api_v2_project_revision_matches($pdo,$project)){$pdo->rollBack();return['status'=>409];}$bindingRevision=(string)$row['project_revision'];$bindingHash=(string)$row['project_projection_sha256'];$liveRevision=(string)$project['revision'];$liveHash=ProjectRevisionService::projectionHash($project);if($bindingRevision!==$liveRevision||!hash_equals($bindingHash,$liveHash)){$evidence=api_v2_project_binding_evidence_payload($pdo,$identity,(string)$row['external_id'],(string)$row['project_public_id'],$row,$project,$requestId);if($evidence===null){$pdo->rollBack();return['status'=>409];}$payload=['apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'error'=>['code'=>'binding_stale','externalId'=>(string)$row['external_id']]];$pdo->commit();return['status'=>409,'payload'=>$payload];}$resources[]=['externalId'=>(string)$row['external_id'],'publicId'=>(string)$row['project_public_id'],'revision'=>$bindingRevision,'projectionSha256'=>$bindingHash,'status'=>(string)$row['status'],'archived'=>$row['archived_at']!==null];}
        $next=$more&&$rows!==[]?(string)$rows[count($rows)-1]['external_id']:null;
        $payload=['apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'authorizationGeneration'=>$identity['authorization_generation'],'projects'=>$resources,'nextCursor'=>$next];
        $pdo->commit();return['status'=>200,'payload'=>$payload];
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function api_v2_project_binding_status_read(PDO $pdo,string $externalId,int $apiKeyId,array $headers,string $requestId):array
{
    if(!api_v2_project_external_id_valid($externalId)||$apiKeyId<1||$pdo->inTransaction())return['status'=>404];
    $pdo->beginTransaction();
    try{$identity=api_v2_project_sync_identity($pdo,$apiKeyId,$headers,true);if(!$identity){$pdo->rollBack();return['status'=>409];}$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $lookup=$pdo->prepare('SELECT project_public_id FROM api_v2_project_external_bindings WHERE application_pk=? AND external_id=?');$lookup->execute([$identity['application_pk'],$externalId]);$publicId=$lookup->fetchColumn();if($publicId===false){$pdo->commit();return['status'=>404];}
        $projectStatement=$pdo->prepare('SELECT * FROM projects WHERE public_id=?'.$lock);$projectStatement->execute([$publicId]);$project=$projectStatement->fetch(PDO::FETCH_ASSOC);if($project)$project=api_v2_project_hydrate_relations($pdo,$project);
        $binding=$pdo->prepare('SELECT CAST(project_revision AS CHAR) project_revision,project_projection_sha256,created_at,updated_at FROM api_v2_project_external_bindings WHERE application_pk=? AND external_id=? AND project_public_id=?'.$lock);$binding->execute([$identity['application_pk'],$externalId,$publicId]);$row=$binding->fetch(PDO::FETCH_ASSOC);
        // Only a fully verified live Project may be disclosed. This keeps a
        // missing/corrupt canonical row as a bare conflict rather than turning
        // binding status into a resource-discovery or repair oracle.
        if(!$project||!$row||!api_v2_project_revision_matches($pdo,$project,$lock)){$pdo->rollBack();return['status'=>409];}
        $liveRevision=(string)$project['revision'];$liveHash=ProjectRevisionService::projectionHash($project);$bindingRevision=(string)$row['project_revision'];$bindingHash=(string)$row['project_projection_sha256'];
        if($bindingRevision!==$liveRevision||!hash_equals($bindingHash,$liveHash)){
            // The recovery evidence uses the exact field names required by the
            // refresh command and is returned only for strictly older pins.
            $payload=api_v2_project_binding_evidence_payload($pdo,$identity,$externalId,(string)$publicId,$row,$project,$requestId,$lock);if($payload===null){$pdo->rollBack();return['status'=>409];}$pdo->commit();return['status'=>409,'payload'=>$payload];
        }
        // Preserve the established synchronized response contract exactly.
        $payload=['apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'authorizationGeneration'=>$identity['authorization_generation'],'binding'=>['externalId'=>$externalId,'publicId'=>(string)$publicId,'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at']],'resource'=>['revision'=>$liveRevision,'projectionSha256'=>$liveHash,'status'=>$project['status'],'archived'=>$project['archived_at']!==null]];
        $pdo->commit();return['status'=>200,'payload'=>$payload];
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
}
