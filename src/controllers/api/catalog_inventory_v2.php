<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); header('Pragma: no-cache');
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){header('Allow: GET');http_response_code(405);exit;}
define('PA_STATELESS_API_NO_SESSION',true);require_once __DIR__.'/../../../vendor/autoload.php';require_once __DIR__.'/../../utils/api_v2_catalog_inventory.php';$requestId=api_v2_uuid();header('X-Request-ID: '.$requestId);
$cursor=isset($_GET['cursor'])?(string)$_GET['cursor']:null;$limitRaw=(string)($_GET['limit']??'100');
if(preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D',$limitRaw)!==1||array_diff(array_keys($_GET),['cursor','limit'])!==[]){http_response_code(400);exit;}
try{$host=getenv('DB_HOST')?:'db';$db=getenv('MYSQL_DATABASE')?:'project_alpha';$user=getenv('MYSQL_USER')?:'root';$pass=getenv('MYSQL_PASSWORD')?:getenv('MYSQL_ROOT_PASSWORD')?:'rootpass';$pdo=new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable$error){error_log('[ApiV2CatalogInventory] database unavailable: '.get_class($error));http_response_code(503);exit;}
require_once __DIR__.'/../../utils/api_auth.php';$key=api_require_key(['api.capabilities.read','catalog.inventory.read'],false);if(in_array('full',api_normalize_scopes($key['scopes']??''),true)){http_response_code(403);exit;}
try{$outcome=api_v2_catalog_inventory_read($pdo,$cursor,(int)$limitRaw,(int)$key['id'],['source'=>$_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID']??null,'application'=>$_SERVER['HTTP_X_PA_APPLICATION_ID']??null,'epoch'=>$_SERVER['HTTP_X_PA_HISTORY_EPOCH']??null],$requestId);http_response_code($outcome['status']);if(isset($outcome['payload'])){$json=json_encode($outcome['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($json)>API_V2_CATALOG_RESPONSE_MAX_BYTES)throw new RuntimeException('Catalog inventory response limit');echo$json;}}catch(Throwable$error){error_log('[ApiV2CatalogInventory] read unavailable: '.get_class($error));http_response_code(503);}
