<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('Pragma: no-cache');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){header('Allow: GET');http_response_code(405);exit;}
define('PA_STATELESS_API_NO_SESSION',true);
require_once __DIR__.'/../../utils/api_v2_financial_summary.php';
$requestId=api_v2_uuid();header('X-Request-ID: '.$requestId);
try{$period=api_v2_financial_summary_period((string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_QUERY)?:''));}catch(InvalidArgumentException){http_response_code(400);exit;}
try{$pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'db').';dbname='.(getenv('MYSQL_DATABASE')?:'project_alpha').';charset=utf8mb4',getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:getenv('MYSQL_ROOT_PASSWORD')?:'rootpass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $error){error_log('[ApiV2FinancialSummary] database unavailable: '.get_class($error));http_response_code(503);exit;}
require_once __DIR__.'/../../utils/api_auth.php';
$key=api_require_key(['api.capabilities.read','financial.summary.read'],false);
try{
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    $identity=api_v2_financial_summary_identity($pdo,(int)$key['id'],['sourceInstanceId'=>$_SERVER['HTTP_X_PA_SOURCE_INSTANCE_ID']??null,'applicationId'=>$_SERVER['HTTP_X_PA_APPLICATION_ID']??null,'historyEpoch'=>$_SERVER['HTTP_X_PA_HISTORY_EPOCH']??null]);
    if(!$identity){$pdo->rollBack();http_response_code(409);exit;}
    $payload=api_v2_financial_summary_read($pdo,$identity,$period,$requestId);
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($json)>131072)throw new RuntimeException('Summary too large');
    $pdo->commit();echo $json;
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();error_log('[ApiV2FinancialSummary] unavailable: '.get_class($error));http_response_code(503);}
