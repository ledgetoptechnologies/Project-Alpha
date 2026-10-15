<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('Pragma: no-cache');
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='GET'){header('Allow: GET');http_response_code(405);exit;}
define('PA_STATELESS_API_NO_SESSION',true);require_once __DIR__.'/../../../vendor/autoload.php';require_once __DIR__.'/../../utils/api_v2_financial_projection.php';require_once __DIR__.'/../../utils/invoice_notifications.php';
$requestId=api_v2_uuid();header('X-Request-ID: '.$requestId);$query=[];parse_str((string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_QUERY)?:''),$query);
if(array_diff(array_keys($query),['selectorType','externalId','cursor','limit'])!==[]||!is_string($query['selectorType']??null)||!is_string($query['externalId']??null)||(array_key_exists('cursor',$query)&&!is_string($query['cursor']))||(array_key_exists('limit',$query)&&!is_string($query['limit']))){http_response_code(400);exit;}
$kind=(string)$query['selectorType'];$externalId=(string)$query['externalId'];$cursor=isset($query['cursor'])&&is_string($query['cursor'])?$query['cursor']:null;$limit=(string)($query['limit']??'50');
if(!in_array($kind,['organization','client','project'],true)||$externalId===''||strlen($externalId)>191||!ctype_digit($limit)||(int)$limit<1||(int)$limit>100){http_response_code(400);exit;}
try{$pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'db').';dbname='.(getenv('MYSQL_DATABASE')?:'project_alpha').';charset=utf8mb4',getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:getenv('MYSQL_ROOT_PASSWORD')?:'rootpass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
catch(Throwable $error){error_log('[ApiV2FinancialFeed] database unavailable: '.get_class($error));http_response_code(503);exit;}
require_once __DIR__.'/../../utils/api_auth.php';$key=api_require_key(['api.capabilities.read','financial.documents.read'],false);if(in_array('full',api_normalize_scopes($key['scopes']??''),true)){http_response_code(403);exit;}
try{$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    $identity=api_v2_financial_locked_identity($pdo,(int)$key['id'],['source'=>$_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID']??null,'application'=>$_SERVER['HTTP_X_PA_APPLICATION_ID']??null,'epoch'=>$_SERVER['HTTP_X_PA_HISTORY_EPOCH']??null]);if(!$identity){$pdo->rollBack();http_response_code(409);exit;}
    $scope=api_v2_financial_resolve_scope($pdo,(int)$identity['application_pk'],$kind,$externalId,true);if(!$scope){$pdo->rollBack();http_response_code(409);exit;}
    $config=$pdo->query("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='app_host' LIMIT 1")->fetchColumn();$base=invoice_notification_public_base(['app_host'=>$config?:'']);
    $result=api_v2_financial_feed_read($pdo,$identity,$scope,$externalId,$cursor,(int)$limit,$base,$requestId);$pdo->commit();http_response_code($result['status']);if(isset($result['payload'])){$json=json_encode($result['payload'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($json)>131072)throw new RuntimeException('Financial feed response limit');echo $json;}}
catch(InvalidArgumentException){http_response_code(400);}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();error_log('[ApiV2FinancialFeed] unavailable: '.get_class($error));http_response_code(503);}
