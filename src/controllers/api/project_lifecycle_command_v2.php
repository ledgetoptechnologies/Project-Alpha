<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); header('Pragma: no-cache');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../utils/api_v2_project_lifecycle.php';
$requestId=api_v2_uuid(); header('X-Request-ID: '.$requestId);
$path=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'),PHP_URL_PATH) ?: '/');
if(preg_match('#^/api/v2/projects/([0-9a-f]{32})/(complete|cancel|archive|restore)/commands$#D',$path,$match)!==1){http_response_code(404);exit;}
if(preg_match('/^application\/json(?:\s*;\s*charset\s*=\s*utf-8)?\s*$/iD',(string)($_SERVER['CONTENT_TYPE']??''))!==1){http_response_code(415);exit;}
$length=$_SERVER['CONTENT_LENGTH']??null;if($length!==null&&(!ctype_digit((string)$length)||(int)$length>8192)){http_response_code(413);exit;}
$stream=fopen('php://input','rb');$body=$stream===false?false:stream_get_contents($stream,8193);if(is_resource($stream))fclose($stream);
if(!is_string($body)||strlen($body)>8192){http_response_code(413);exit;}$command=api_v2_project_lifecycle_command_parse($body);if($command===null){http_response_code(400);exit;}
try{$pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'db').';dbname='.(getenv('MYSQL_DATABASE')?:'project_alpha').';charset=utf8mb4',getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:getenv('MYSQL_ROOT_PASSWORD')?:'rootpass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
catch(Throwable $error){error_log('[ApiV2Project] database unavailable: '.get_class($error));http_response_code(503);exit;}
require_once __DIR__.'/../../utils/api_auth.php';$scope='projects.lifecycle.'.$match[2];$key=api_require_key(['api.capabilities.read',$scope],false);
if(in_array('full',api_normalize_scopes($key['scopes']??''),true)){http_response_code(403);exit;}
try{$outcome=api_v2_project_lifecycle_write($pdo,$match[1],$match[2],$command,(int)$key['id'],[
    'source'=>$_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID']??null,'application'=>$_SERVER['HTTP_X_PA_APPLICATION_ID']??null,'epoch'=>$_SERVER['HTTP_X_PA_HISTORY_EPOCH']??null,
],$requestId);http_response_code($outcome['status']);if(isset($outcome['payload'])){$json=json_encode($outcome['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($json)>16384)throw new RuntimeException('Project response limit.');echo $json;}}
catch(Throwable $error){error_log('[ApiV2Project] lifecycle unavailable: '.get_class($error));http_response_code(503);}
