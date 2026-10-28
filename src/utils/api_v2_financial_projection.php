<?php
declare(strict_types=1);

use App\Services\ProjectRevisionService;

require_once __DIR__ . '/api_v2_directory_revision.php';
require_once __DIR__ . '/api_v2_project_lifecycle.php';
require_once __DIR__ . '/api_scopes.php';

/** Financial-specific current-key policy, checked under the same read transaction
 * and key lock as the identity. Canonical API key lifetime is revoked_at (there
 * is no separate expiry field/policy in api_require_key). */
function api_v2_financial_locked_identity(PDO $pdo,int $apiKeyId,array $headers): ?array
{
    if(!$pdo->inTransaction()||$apiKeyId<1)throw new InvalidArgumentException('Financial identity requires an active read transaction.');
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $statement=$pdo->prepare('SELECT app.id application_pk,app.application_id,history.source_instance_id,history.history_epoch,api_key.scopes FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL'.$lock);
    $statement->execute([$apiKeyId]);$identity=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$identity||!api_v2_identity_is_valid($identity)
        ||!hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))
        ||!hash_equals((string)$identity['application_id'],(string)($headers['application']??''))
        ||!hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))
        ||!api_key_has_scope($identity['scopes']??'','api.capabilities.read',false)
        ||!api_key_has_scope($identity['scopes']??'','financial.documents.read',false)
        ||in_array('full',api_normalize_scopes($identity['scopes']??''),true))return null;
    unset($identity['scopes']);return $identity;
}

/** Validate the stable public identity exposed by the financial read model. */
function api_v2_financial_public_id_valid(string $value): bool
{
    return preg_match('/^[0-9a-f]{32}$/D', $value) === 1;
}

function api_v2_financial_currency_valid(string $value): bool
{
    return preg_match('/^[A-Z]{3}$/D', $value) === 1;
}

function api_v2_financial_positive_money(string $value): bool
{
    if (preg_match('/^(0|[1-9][0-9]{0,17})\.([0-9]{2})$/D',$value,$match)!==1) return false;
    return $match[1] !== '0' || $match[2] !== '00';
}

/** @param array{snapshot:string,kind:string,publicId:string} $value */
function api_v2_financial_cursor_encode(array $value): string
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $value['snapshot'])
        || !in_array($value['kind'], ['contract', 'invoice', 'project_invoice'], true)
        || !api_v2_financial_public_id_valid($value['publicId'])) {
        throw new InvalidArgumentException('Invalid financial cursor state.');
    }
    return rtrim(strtr(base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}

/** @return array{snapshot:string,kind:string,publicId:string}|null */
function api_v2_financial_cursor_decode(?string $cursor): ?array
{
    if ($cursor === null) return null;
    if ($cursor === '' || strlen($cursor) > 256 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
        throw new InvalidArgumentException('Invalid financial cursor.');
    }
    $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
    $value = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($value) || array_keys($value) !== ['snapshot', 'kind', 'publicId']
        || !is_string($value['snapshot']) || !preg_match('/^[0-9a-f]{64}$/D', $value['snapshot'])
        || !is_string($value['kind']) || !in_array($value['kind'], ['contract', 'invoice', 'project_invoice'], true)
        || !is_string($value['publicId']) || !api_v2_financial_public_id_valid($value['publicId'])) {
        throw new InvalidArgumentException('Invalid financial cursor.');
    }
    return $value;
}

/**
 * Resolve one current application binding to an internal financial scope.
 * Numeric IDs remain internal and must never be serialized by the controller.
 *
 * @return array{kind:string,publicId:string,resourceId:int,clientId:?int,organizationId:?int,projectId:?int,revision:string}|null
 */
function api_v2_financial_resolve_scope(PDO $pdo, int $applicationPk, string $kind, string $externalId, bool $lock = false): ?array
{
    if ($applicationPk < 1 || !in_array($kind, ['client', 'organization', 'project'], true)
        || $externalId === '' || strlen($externalId) > 191) return null;
    $lockSuffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    if ($kind === 'project') {
        $statement = $pdo->prepare("SELECT project.*,binding.project_revision,binding.project_projection_sha256,
            client.public_id client_public_id,client.organization_id client_organization_id,
            organization.public_id organization_public_id,CAST(auth.authorization_generation AS CHAR) authorization_generation
            FROM api_v2_project_external_bindings binding JOIN projects project ON project.public_id=binding.project_public_id
            LEFT JOIN clients client ON client.id=project.client_id LEFT JOIN organizations organization ON organization.id=project.organization_id
            JOIN api_v2_project_authorization_state auth ON auth.application_pk=binding.application_pk
            WHERE binding.application_pk=? AND binding.external_id=? LIMIT 2".$lockSuffix);
        $statement->execute([$applicationPk, $externalId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1)return null;$row=$rows[0];
        $validOwner=($row['client_id']===null&&$row['organization_id']!==null)
            ||($row['client_id']!==null&&(($row['organization_id']===null&&$row['client_organization_id']===null)
            ||($row['organization_id']!==null&&(string)$row['organization_id']===(string)$row['client_organization_id'])));
        $hash=ProjectRevisionService::projectionHash($row);
        if (!$validOwner || !api_v2_financial_public_id_valid((string)$row['public_id'])
            || (string)$row['project_revision']!==(string)$row['revision']
            || !hash_equals((string)$row['project_projection_sha256'],$hash)
            || !api_v2_project_revision_matches($pdo,$row)) return null;
        return ['kind' => 'project', 'publicId' => (string)$row['public_id'],
            'resourceId' => (int)$row['id'], 'clientId' => $row['client_id']===null?null:(int)$row['client_id'],
            'organizationId' => $row['organization_id'] === null ? null : (int)$row['organization_id'],
            'projectId' => (int)$row['id'], 'revision' => (string)$row['revision'],
            'projectionSha256'=>$hash,'authorizationGeneration'=>(string)$row['authorization_generation']];
    }
    $table = $kind === 'client' ? 'clients' : 'organizations';
    $statement = $pdo->prepare("SELECT resource.*,CAST(state.revision AS CHAR) state_revision,state.projection_sha256,state.present,
        CAST(binding.resource_revision AS CHAR) binding_revision,binding.resource_projection_sha256,
        CAST(auth.authorization_generation AS CHAR) authorization_generation FROM api_v2_directory_external_bindings binding
        JOIN {$table} resource ON resource.public_id=binding.public_id
        JOIN api_v2_directory_resource_state state ON state.resource_type=binding.resource_type AND state.public_id=binding.public_id
        JOIN api_v2_directory_authorization_state auth ON auth.application_pk=binding.application_pk
        WHERE binding.application_pk=? AND binding.resource_type=? AND binding.external_id=? AND binding.status='active' LIMIT 2".$lockSuffix);
    $statement->execute([$applicationPk, $kind, $externalId]);
    $rows=$statement->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1)return null;$row=$rows[0];
    $hash=api_v2_directory_canonical_hash($pdo,$kind,$row);
    if ((int)$row['present']!==1 || (string)$row['binding_revision']!==(string)$row['state_revision']
        || !hash_equals((string)$row['projection_sha256'],$hash)
        || !hash_equals((string)$row['resource_projection_sha256'],$hash)
        || !api_v2_financial_public_id_valid((string)$row['public_id'])) return null;
    $resourceId = (int)$row['id'];
    return ['kind' => $kind, 'publicId' => (string)$row['public_id'], 'resourceId' => $resourceId,
        'clientId' => $kind === 'client' ? $resourceId : null,
        'organizationId' => $kind === 'organization' ? $resourceId
            : ($row['organization_id'] === null ? null : (int)$row['organization_id']),
        'projectId' => null, 'revision' => (string)$row['state_revision'],
        'projectionSha256'=>$hash,'authorizationGeneration'=>(string)$row['authorization_generation']];
}

/**
 * Select an existing bearer link without invoking any lifecycle helper.
 * This function never creates, refreshes, revokes, expires, or records access
 * to a public link. Recipient authorization remains the caller's obligation.
 *
 * @return array{documentUrl:?string,actionUrl:?string}
 */
function api_v2_financial_existing_action_urls(
    PDO $pdo,
    string $documentType,
    int $documentId,
    string $status,
    string $balanceDue,
    string $baseUrl,
    ?string $signedAt = null
): array {
    $none = ['documentUrl' => null, 'actionUrl' => null];
    if (!in_array($documentType, ['invoice', 'contract', 'project_invoice'], true)
        || $documentId < 1
        || !preg_match('~^https://[^/?#]+(?::[0-9]+)?$~D', $baseUrl)) {
        return $none;
    }
    $normalizedStatus = strtolower($status);
    $eligible = match ($documentType) {
        'contract' => $normalizedStatus === 'pending' && $signedAt === null,
        'invoice' => in_array($normalizedStatus, ['sent', 'unpaid', 'partial', 'overdue'], true),
        'project_invoice' => in_array($normalizedStatus, ['sent', 'unpaid', 'partial', 'overdue'], true),
    };
    if (!$eligible) return $none;

    $statement = $pdo->prepare(
        "SELECT token FROM public_links
         WHERE document_type=? AND document_id=? AND revoked=0
           AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)
         ORDER BY created_at DESC,id DESC LIMIT 1"
    );
    $statement->execute([$documentType, $documentId]);
    $token = $statement->fetchColumn();
    if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $token) !== 1) return $none;
    $documentUrl = rtrim($baseUrl, '/') . '/?page=public-doc&token=' . rawurlencode($token);
    $actionUrl = $documentType === 'contract'
        ? $documentUrl
        : (api_v2_financial_positive_money($balanceDue)
            ? rtrim($baseUrl, '/') . '/?page=stripe-checkout&token=' . rawurlencode($token)
            : null);
    return ['documentUrl' => $documentUrl, 'actionUrl' => $actionUrl];
}

/** Query canonical document lifecycle; callers cannot assert their own eligibility. */
function api_v2_financial_document_action_urls(PDO $pdo,string $documentType,int $documentId,string $baseUrl): array
{
    if(!in_array($documentType,['invoice','contract','project_invoice'],true)||$documentId<1)return ['documentUrl'=>null,'actionUrl'=>null];
    $table=$documentType==='project_invoice'?'project_invoices':$documentType.'s';
    $columns=$documentType==='contract'?'status,signed_at':($documentType==='invoice'?'status,balance_due,finalized_at,collection_mode,recipient_presentation_mode':'status,balance_due,finalized_at');
    $statement=$pdo->prepare("SELECT {$columns} FROM {$table} WHERE id=?");$statement->execute([$documentId]);$row=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$row)return ['documentUrl'=>null,'actionUrl'=>null];
    if($documentType!=='contract'&&(empty($row['finalized_at'])||($documentType==='invoice'&&((string)$row['collection_mode']!=='direct'||(string)$row['recipient_presentation_mode']==='general'))))return ['documentUrl'=>null,'actionUrl'=>null];
    return api_v2_financial_existing_action_urls($pdo,$documentType,$documentId,(string)$row['status'],(string)($row['balance_due']??'0.00'),$baseUrl,$row['signed_at']??null);
}

function api_v2_financial_money(string $value): ?string
{
    if(preg_match('/^(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,2}))?$/D',$value,$m)!==1)return null;
    return $m[1].'.'.str_pad($m[2]??'',2,'0');
}

/** Match invoice_pdf.php: document_date is parsed in the configured PHP timezone. */
function api_v2_financial_timezone(PDO $pdo): DateTimeZone
{
    $name='UTC';
    try{$value=$pdo->query("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='timezone' LIMIT 1")->fetchColumn();if(is_string($value)&&$value!=='')$name=$value;}catch(Throwable){}
    try{return new DateTimeZone($name);}catch(Throwable){return new DateTimeZone('UTC');}
}

function api_v2_financial_issue_date(?string $value,DateTimeZone $timezone):?string
{
    if($value===null||$value==='')return null;
    try{return (new DateTimeImmutable($value,$timezone))->format('Y-m-d');}
    catch(Throwable $error){throw new UnexpectedValueException('Invalid invoice document date.',0,$error);}
}

function api_v2_financial_due_date(?string $value):?string
{
    if($value===null||$value==='')return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
    if($date===false||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format('Y-m-d')!==$value)
        throw new UnexpectedValueException('Invalid invoice due date.');
    return $date->format('Y-m-d');
}

function api_v2_financial_finish(PDO $pdo,bool $owned,array $result): array
{
    if($owned&&$pdo->inTransaction())$pdo->commit();return $result;
}

/** Bounded full-snapshot link resolution. Eligibility comes from canonical rows
 * selected in the same read transaction, not caller/browser assertions. */
function api_v2_financial_snapshot_links(PDO $pdo,array $rows,string $baseUrl): array
{
    if(count($rows)>10000)throw new InvalidArgumentException('Financial snapshot bound exceeded');
    $urls=[];$eligible=[];
    foreach($rows as $row){
        $kind=$row['_kind'];$id=(int)$row['id'];$key=$kind.':'.$id;$urls[$key]=['documentUrl'=>null,'actionUrl'=>null];
        if(!preg_match('~^https://[^/?#]+(?::[0-9]+)?$~D',$baseUrl))continue;
        if($kind==='contract'){$allowed=$row['status']==='pending'&&$row['signed_at']===null;}
        else{$allowed=in_array($row['status'],['sent','unpaid','partial','overdue'],true)&&!empty($row['finalized_at'])&&($kind!=='invoice'||($row['collection_mode']==='direct'&&$row['recipient_presentation_mode']!=='general'));}
        if($allowed)$eligible[$kind][$id]=$row;
    }
    foreach($eligible as $kind=>$documents){
        foreach(array_chunk(array_keys($documents),500) as $ids){
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $statement=$pdo->prepare("SELECT document_id,token FROM (SELECT document_id,token,ROW_NUMBER() OVER(PARTITION BY document_id ORDER BY created_at DESC,id DESC) link_rank FROM public_links WHERE document_type=? AND revoked=0 AND document_id IN ({$placeholders}) AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)) eligible_links WHERE link_rank=1");
            $statement->execute([$kind,...$ids]);
            foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $link){
                $id=(int)$link['document_id'];$token=$link['token'];
                if(!isset($documents[$id])||!is_string($token)||preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$token)!==1)continue;
                $documentUrl=rtrim($baseUrl,'/').'/?page=public-doc&token='.rawurlencode($token);
                $urls[$kind.':'.$id]=['documentUrl'=>$documentUrl,'actionUrl'=>$kind==='contract'?$documentUrl:(api_v2_financial_positive_money((string)$documents[$id]['balance_due'])?rtrim($baseUrl,'/').'/?page=stripe-checkout&token='.rawurlencode($token):null)];
            }
        }
    }
    return $urls;
}

/** @return array{status:int,payload?:array} */
function api_v2_financial_feed_read(PDO $pdo,array $identity,array $scope,string $externalId,?string $cursor,int $limit,string $baseUrl,string $requestId,bool $includeDates=false): array
{
    if($limit<1||$limit>100||strlen($externalId)>191||preg_match('/[\x00-\x1F\x7F]/',$externalId))throw new InvalidArgumentException('Invalid financial feed request.');
    $owned=!$pdo->inTransaction();if($owned)$pdo->beginTransaction();
    $applicationPk=(int)($identity['application_pk']??0);
    $currentScope=$applicationPk>0?api_v2_financial_resolve_scope($pdo,$applicationPk,(string)($scope['kind']??''),$externalId,true):null;
    $fences=['kind','publicId','resourceId','clientId','organizationId','projectId','revision','projectionSha256','authorizationGeneration'];
    if($currentScope===null||array_intersect_key($currentScope,array_flip($fences))!==array_intersect_key($scope,array_flip($fences)))return api_v2_financial_finish($pdo,$owned,['status'=>409]);
    $decoded=api_v2_financial_cursor_decode($cursor);$documents=[];$candidates=[];
    foreach([['invoice','invoices',"d.status IN ('sent','unpaid','partial','paid','overdue') AND d.collection_mode='direct' AND NOT EXISTS(SELECT 1 FROM project_invoice_items item WHERE item.invoice_id=d.id)"],['contract','contracts',"d.status IN ('pending','active','paused','completed')"],['project_invoice','project_invoices',"d.status IN ('sent','unpaid','partial','paid','overdue')"]] as [$kind,$table,$statusWhere]){
        $clientColumn=$kind==='project_invoice'?'d.primary_client_id':'d.client_id';
        $filters=[];$params=[];
        if($scope['kind']==='project'){
            $filters[]='d.project_id=?';$params[]=$scope['projectId'];
            if($scope['clientId']===null)$filters[]=$clientColumn.' IS NULL';else{$filters[]=$clientColumn.'=?';$params[]=$scope['clientId'];}
            if($scope['organizationId']===null)$filters[]='d.organization_id IS NULL';else{$filters[]='d.organization_id=?';$params[]=$scope['organizationId'];}
        } elseif($scope['kind']==='client'){$filters[]=$clientColumn.'=?';$params[]=$scope['clientId'];}
        else{$filters[]='d.organization_id=?';$params[]=$scope['organizationId'];}
        if($scope['kind']==='project'&&$scope['clientId']!==null){
            $filters[]="EXISTS(SELECT 1 FROM clients owner WHERE owner.id={$clientColumn} AND ".($scope['organizationId']===null?'owner.organization_id IS NULL':'owner.organization_id=d.organization_id').')';
        } elseif($scope['kind']==='organization') {
            $filters[]=$kind==='project_invoice'
                ? "(EXISTS(SELECT 1 FROM clients owner WHERE owner.id=d.primary_client_id AND owner.organization_id=d.organization_id) OR (d.primary_client_id IS NULL AND EXISTS(SELECT 1 FROM projects owner_project WHERE owner_project.id=d.project_id AND owner_project.organization_id=d.organization_id AND owner_project.client_id IS NULL)))"
                : "EXISTS(SELECT 1 FROM clients owner WHERE owner.id={$clientColumn} AND owner.organization_id=d.organization_id)";
        }
        $documentWhere=implode(' AND ',$filters);
        $dates=match($kind){'contract'=>'NULL issue_timestamp,NULL due_date','invoice'=>'d.document_date issue_timestamp,d.due_date','project_invoice'=>'NULL issue_timestamp,d.due_date'};
        $select=$kind==='contract'?'d.id,d.public_id,d.status,d.currency,d.total,NULL amount_paid,NULL balance_due,d.signed_at,'.$dates:'d.id,d.public_id,d.status,d.currency,d.total,d.amount_paid,d.balance_due,d.finalized_at,'.$dates.($kind==='invoice'?',d.collection_mode,d.recipient_presentation_mode':'');
        $remaining=10001-count($candidates);
        $statement=$pdo->prepare("SELECT {$select} FROM {$table} d WHERE {$documentWhere} AND {$statusWhere} ORDER BY d.public_id LIMIT {$remaining}");$statement->execute($params);
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){$row['_kind']=$kind;$candidates[]=$row;}
        if(count($candidates)>10000)return api_v2_financial_finish($pdo,$owned,['status'=>409]);
    }
    $timezone=$includeDates?api_v2_financial_timezone($pdo):null;$links=api_v2_financial_snapshot_links($pdo,$candidates,$baseUrl);
    foreach($candidates as $row){$kind=$row['_kind'];$total=api_v2_financial_money((string)$row['total']);$paid=$row['amount_paid']===null?null:api_v2_financial_money((string)$row['amount_paid']);$balance=$row['balance_due']===null?null:api_v2_financial_money((string)$row['balance_due']);
            if(!api_v2_financial_public_id_valid((string)$row['public_id'])||!api_v2_financial_currency_valid((string)$row['currency'])||$total===null||($row['amount_paid']!==null&&$paid===null)||($row['balance_due']!==null&&$balance===null))return api_v2_financial_finish($pdo,$owned,['status'=>409]);
            $urls=$links[$kind.':'.(int)$row['id']];
            $document=['publicId'=>(string)$row['public_id'],'kind'=>$kind,'status'=>(string)$row['status'],'currency'=>(string)$row['currency'],'total'=>$total,'amountPaid'=>$paid,'balanceDue'=>$balance,'documentUrl'=>$urls['documentUrl'],'actionUrl'=>$urls['actionUrl']];
            if($includeDates){
                $document['issueDate']=$kind==='invoice'?api_v2_financial_issue_date($row['issue_timestamp']===null?null:(string)$row['issue_timestamp'],$timezone??new DateTimeZone('UTC')):null;
                $document['dueDate']=$kind==='contract'?null:api_v2_financial_due_date($row['due_date']===null?null:(string)$row['due_date']);
            }
            $documents[]=$document;
    }
    usort($documents,static fn(array$a,array$b):int=>[$a['kind'],$a['publicId']]<=>[$b['kind'],$b['publicId']]);
    if(count($documents)>10000)return api_v2_financial_finish($pdo,$owned,['status'=>409]);
    $totalCount=count($documents);
    $snapshot=hash('sha256',json_encode(['selector'=>[$scope['kind'],$scope['publicId'],$scope['revision'],$scope['projectionSha256'],$scope['authorizationGeneration']],'documents'=>$documents],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    if($decoded!==null&&$decoded['snapshot']!==$snapshot)return api_v2_financial_finish($pdo,$owned,['status'=>409]);
    if($decoded!==null)$documents=array_values(array_filter($documents,static fn(array$d):bool=>[$d['kind'],$d['publicId']]>[$decoded['kind'],$decoded['publicId']]));
    $page=array_slice($documents,0,$limit);$more=count($documents)>$limit;$last=$page!==[]?$page[count($page)-1]:null;
    $payload=['apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,
        'selector'=>['kind'=>$scope['kind'],'externalId'=>$externalId,'publicId'=>$scope['publicId'],'revision'=>$scope['revision'],'projectionSha256'=>$scope['projectionSha256'],'authorizationGeneration'=>$scope['authorizationGeneration']],
        'snapshot'=>['sha256'=>$snapshot,'documentCount'=>$totalCount],'documents'=>$page,
        'nextCursor'=>$more&&$last?api_v2_financial_cursor_encode(['snapshot'=>$snapshot,'kind'=>$last['kind'],'publicId'=>$last['publicId']]):null];
    return api_v2_financial_finish($pdo,$owned,['status'=>200,'payload'=>$payload]);
}
