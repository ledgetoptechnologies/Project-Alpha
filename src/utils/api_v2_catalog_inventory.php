<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_capabilities.php';

const API_V2_CATALOG_RESPONSE_MAX_BYTES = 1048576;

function api_v2_catalog_cursor_encode(string $snapshotId, int $totalCount, string $afterPublicId): string
{
    $json = json_encode(['snapshotId'=>$snapshotId,'totalCount'=>$totalCount,'afterPublicId'=>$afterPublicId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function api_v2_catalog_cursor_decode(?string $cursor): ?array
{
    if ($cursor === null) return ['snapshotId'=>null,'totalCount'=>null,'afterPublicId'=>null];
    if ($cursor === '' || strlen($cursor) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) return null;
    $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
    if (!is_string($decoded)) return null;
    try { $value = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    if (!is_array($value) || array_keys($value) !== ['snapshotId','totalCount','afterPublicId']
        || !is_string($value['snapshotId']) || preg_match('/^[0-9a-f]{64}$/D', $value['snapshotId']) !== 1
        || !is_int($value['totalCount']) || $value['totalCount'] < 1
        || !is_string($value['afterPublicId']) || preg_match('/^[0-9a-f]{32}$/D', $value['afterPublicId']) !== 1) return null;
    return $value;
}

function api_v2_catalog_question_list(mixed $json): array
{
    try { $questions = json_decode(is_string($json) && $json !== '' ? $json : '[]', true, 16, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { throw new RuntimeException('Catalog questions are invalid', 0, $error); }
    if (!is_array($questions) || !array_is_list($questions) || count($questions) > 10) throw new RuntimeException('Catalog questions are invalid');
    $ids=[];
    foreach ($questions as $question) {
        if (!is_array($question) || array_diff(array_keys($question),['id','label','type','required','helpText','options','minimum','maximum'])!==[]
            || !array_key_exists('id',$question)||!array_key_exists('label',$question)||!array_key_exists('type',$question)||!array_key_exists('required',$question)
            || !is_string($question['id'])||preg_match('/^[a-z][a-z0-9_:-]{0,63}$/D',$question['id'])!==1||isset($ids[$question['id']])
            || !api_v2_catalog_plain_text($question['label'],1,200,false)||!is_bool($question['required'])
            || !in_array($question['type'],['text','number','boolean','select','multi-select'],true)
            || !api_v2_catalog_plain_text($question['helpText']??null,1,500,true)) throw new RuntimeException('Catalog questions are invalid');
        $ids[$question['id']]=true;$select=in_array($question['type'],['select','multi-select'],true);
        if($select!==array_key_exists('options',$question))throw new RuntimeException('Catalog questions are invalid');
        if($select){$options=$question['options'];if(!is_array($options)||!array_is_list($options)||count($options)<1||count($options)>50)throw new RuntimeException('Catalog questions are invalid');$values=[];foreach($options as$option){$keys=is_array($option)?array_keys($option):[];sort($keys);if(!is_array($option)||$keys!==['label','value']||!api_v2_catalog_plain_text($option['value']??null,1,100,false)||!api_v2_catalog_plain_text($option['label']??null,1,200,false)||isset($values[$option['value']]))throw new RuntimeException('Catalog questions are invalid');$values[$option['value']]=true;}}
        $hasMin=array_key_exists('minimum',$question);$hasMax=array_key_exists('maximum',$question);
        if($question['type']!=='number'&&($hasMin||$hasMax))throw new RuntimeException('Catalog questions are invalid');
        foreach(['minimum','maximum']as$bound)if(array_key_exists($bound,$question)&&(!is_int($question[$bound])&&!is_float($question[$bound])||!is_finite((float)$question[$bound])))throw new RuntimeException('Catalog questions are invalid');
        if($hasMin&&$hasMax&&$question['minimum']>$question['maximum'])throw new RuntimeException('Catalog questions are invalid');
    }
    return $questions;
}

function api_v2_catalog_plain_text(mixed $value,int $min,int $max,bool $nullable):bool
{
    if($value===null)return$nullable;
    return is_string($value)&&mb_strlen($value)>=$min&&mb_strlen($value)<=$max
        &&preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value)!==1&&!str_contains($value,'<')&&!str_contains($value,'>')
        &&preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u',$value)!==1;
}

/**
 * Full-resnapshot inventory. Every page recomputes the complete aggregate
 * fingerprint, so a consumer can reject mixed pages after any insert, update,
 * deactivate, or delete and atomically replace only a complete scan.
 */
function api_v2_catalog_inventory_read(PDO $pdo, ?string $cursor, int $limit, int $apiKeyId, array $headers, string $requestId): array
{
    if ($limit < 1 || $limit > 200 || $apiKeyId < 1 || $pdo->inTransaction()) throw new InvalidArgumentException('Invalid catalog inventory request');
    $decodedCursor = api_v2_catalog_cursor_decode($cursor);
    if ($decodedCursor === null) return ['status'=>400];
    $pdo->beginTransaction();
    try {
        $identityStatement=$pdo->prepare('SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id application_pk FROM api_keys api_key JOIN api_v2_applications app ON app.id=api_key.api_v2_application_id JOIN api_v2_history_identity history ON history.singleton=1 WHERE api_key.id=? AND api_key.revoked_at IS NULL');
        $identityStatement->execute([$apiKeyId]); $identity=$identityStatement->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !api_v2_identity_is_valid($identity)
            || !hash_equals((string)$identity['source_instance_id'],(string)($headers['source']??''))
            || !hash_equals((string)$identity['application_id'],(string)($headers['application']??''))
            || !hash_equals((string)$identity['history_epoch'],(string)($headers['epoch']??''))) {
            $pdo->rollBack(); return ['status'=>409];
        }
        $statement=$pdo->query("SELECT portal_public_id,item_name,portal_summary,portal_category,portal_display_order,portal_geometry_requirement,portal_questions_json FROM item_library WHERE entry_type='service' AND is_active=1 AND portal_requestable=1 ORDER BY portal_public_id");
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC); $items=[]; $seen=[];
        foreach ($rows as $row) {
            $publicId=(string)($row['portal_public_id']??'');
            if (preg_match('/^[0-9a-f]{32}$/D',$publicId)!==1 || isset($seen[$publicId])) throw new RuntimeException('Catalog identity is invalid');
            $seen[$publicId]=true;
            $item=['publicId'=>$publicId,'name'=>(string)$row['item_name'],'summary'=>$row['portal_summary']===null?null:(string)$row['portal_summary'],
                'category'=>(string)($row['portal_category']??'Uncategorized'),'displayOrder'=>(int)($row['portal_display_order']??0),
                'geometryRequirement'=>(string)($row['portal_geometry_requirement']??'none'),'questions'=>api_v2_catalog_question_list($row['portal_questions_json']??null)];
            if(!api_v2_catalog_plain_text($item['name'],1,255,false)||!api_v2_catalog_plain_text($item['summary'],1,1000,true)
                ||!api_v2_catalog_plain_text($item['category'],1,100,false)||$item['displayOrder']<0||$item['displayOrder']>1000000
                ||!in_array($item['geometryRequirement'],['none','optional','required'],true))throw new RuntimeException('Catalog item is invalid');
            $canonical=json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $items[]=['publicId'=>$publicId,'version'=>hash('sha256',$canonical)]+array_slice($item,1,null,true);
        }
        $totalCount=count($items);
        $fingerprintInput=json_encode(array_map(static fn(array$item):array=>[$item['publicId'],$item['version']],$items),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $snapshotId=hash('sha256',$fingerprintInput);
        if ($cursor !== null && (!hash_equals($decodedCursor['snapshotId'],$snapshotId) || $decodedCursor['totalCount'] !== $totalCount)) {
            $payload=['apiVersion'=>'2','sourceInstanceId'=>(string)$identity['source_instance_id'],'applicationId'=>(string)$identity['application_id'],'historyEpoch'=>(string)$identity['history_epoch'],'requestId'=>$requestId,'error'=>['code'=>'catalog_snapshot_changed']];
            $pdo->commit(); return ['status'=>409,'payload'=>$payload];
        }
        $start=0;
        if ($decodedCursor['afterPublicId'] !== null) {
            while ($start<$totalCount && strcmp($items[$start]['publicId'],$decodedCursor['afterPublicId'])<=0) $start++;
            if($start===0||$items[$start-1]['publicId']!==$decodedCursor['afterPublicId']){$pdo->rollBack();return['status'=>400];}
        }
        $base=['apiVersion'=>'2','sourceInstanceId'=>(string)$identity['source_instance_id'],'applicationId'=>(string)$identity['application_id'],'historyEpoch'=>(string)$identity['history_epoch'],'requestId'=>$requestId,'snapshotId'=>$snapshotId,'totalCount'=>$totalCount];
        $page=[];
        for($index=$start;$index<$totalCount&&count($page)<$limit;$index++){
            $candidate=[...$page,$items[$index]];$candidateMore=$index+1<$totalCount;
            $candidateNext=$candidateMore?api_v2_catalog_cursor_encode($snapshotId,$totalCount,$items[$index]['publicId']):null;
            $candidatePayload=$base+['items'=>$candidate,'nextCursor'=>$candidateNext];
            $bytes=strlen(json_encode($candidatePayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            if($bytes>API_V2_CATALOG_RESPONSE_MAX_BYTES){if($page===[])throw new RuntimeException('Catalog item exceeds response limit');break;}
            $page=$candidate;
        }
        $hasMore=$start+count($page)<$totalCount;
        $next=$hasMore?api_v2_catalog_cursor_encode($snapshotId,$totalCount,$page[count($page)-1]['publicId']):null;
        $payload=$base+['items'=>$page,'nextCursor'=>$next];
        $pdo->commit(); return ['status'=>200,'payload'=>$payload];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
