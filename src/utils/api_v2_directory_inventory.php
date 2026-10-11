<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_lifecycle_command.php';

/** Snapshot-consistent inventory includes live resources and retained tombstones. */
function api_v2_directory_inventory_read(PDO$pdo,string$type,?string$cursor,int$limit,int$apiKeyId,array$headers,string$requestId):?array
{
    if(!in_array($type,['all','client','organization','unit'],true)||$limit<1||$limit>200||$apiKeyId<1||$pdo->inTransaction())throw new InvalidArgumentException('Invalid directory inventory request');
    $afterType='';$afterPublicId='';
    if($cursor!==null){if(preg_match('/^(client|organization|unit):([0-9a-f]{32})$/D',$cursor,$match)!==1||($type!=='all'&&$type!==$match[1]))return null;$afterType=$match[1];$afterPublicId=$match[2];}
    $pdo->beginTransaction();
    try{
        // A repeatable-read snapshot is sufficient for reconciliation and
        // avoids inverting writer order by locking authorization before state.
        $lock='';
        $identityStatement=$pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk,CAST(authorization.authorization_generation AS CHAR) authorization_generation FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 JOIN api_v2_directory_authorization_state authorization ON authorization.application_pk=app.id WHERE api_key.id=? AND api_key.revoked_at IS NULL'.$lock);
        $identityStatement->execute([$apiKeyId]);$identity=$identityStatement->fetch(PDO::FETCH_ASSOC);
        if(!$identity||!api_v2_identity_is_valid($identity)||!hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))||!hash_equals((string)$identity['application_id'],(string)($headers['application']??''))||!hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))){$pdo->rollBack();return null;}
        $where=[];$params=[(int)$identity['application_pk']];
        if($type!=='all'){$where[]='state.resource_type=?';$params[]=$type;}
        if($cursor!==null){$where[]='(state.resource_type>? OR (state.resource_type=? AND state.public_id>?))';array_push($params,$afterType,$afterType,$afterPublicId);}
        $sql='SELECT state.resource_type,state.public_id,CAST(state.revision AS CHAR) revision,state.projection_sha256,state.present,
                changes.action last_action,binding.external_id,binding.status binding_status,CAST(binding.resource_revision AS CHAR) binding_revision
              FROM api_v2_directory_resource_state state
              JOIN api_v2_directory_resource_changes changes ON changes.resource_type=state.resource_type AND changes.public_id=state.public_id AND changes.revision=state.revision
              LEFT JOIN api_v2_directory_external_bindings binding ON binding.application_pk=? AND binding.resource_type=state.resource_type AND binding.public_id=state.public_id'
            .($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY state.resource_type,state.public_id LIMIT '.($limit+1).$lock;
        $statement=$pdo->prepare($sql);$statement->execute($params);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);$resources=[];
        foreach($rows as$row){
            if(!in_array((string)$row['resource_type'],['client','organization','unit'],true)||preg_match('/^[0-9a-f]{32}$/D',(string)$row['public_id'])!==1||!api_v2_directory_lifecycle_positive((string)$row['revision'])||!in_array((string)$row['last_action'],['upsert','delete'],true)){throw new RuntimeException('Directory inventory state is invalid');}
            $binding=null;if($row['external_id']!==null){$binding=['externalId'=>(string)$row['external_id'],'status'=>(string)$row['binding_status'],'resourceRevision'=>(string)$row['binding_revision']];}
            $resources[]=['type'=>(string)$row['resource_type'],'publicId'=>(string)$row['public_id'],'revision'=>(string)$row['revision'],'present'=>(int)$row['present']===1,
                'lastAction'=>(string)$row['last_action'],'projectionSha256'=>(string)$row['projection_sha256'],'binding'=>$binding];
        }
        $nextCursor=null;if($hasMore&&$resources!==[]){$last=$resources[count($resources)-1];$nextCursor=$last['type'].':'.$last['publicId'];}
        $result=['sourceInstanceId'=>(string)$identity['source_instance_id'],'applicationId'=>(string)$identity['application_id'],'historyEpoch'=>(string)$identity['history_epoch'],
            'requestId'=>$requestId,'authorizationGeneration'=>(string)$identity['authorization_generation'],'resources'=>$resources,'nextCursor'=>$nextCursor];
        $pdo->commit();return$result;
    }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
}
