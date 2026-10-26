<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/recurring_history.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); header('Allow: POST'); echo json_encode(['success'=>false,'message'=>'Use the historical payment form.']); exit;
}
if (!csrf_validate()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Invalid request. Reload the form.']); exit; }
$contractId=(int)($_POST['contract_id']??0); $serviceId=(int)($_POST['service_id']??0); $actor=(int)($_SESSION['user']['id']??0);
if ($actor<=0 || !user_can($pdo,$actor,'contracts.edit',request_client_org_id()?:null) || !user_can($pdo,$actor,'invoices.mark_paid',request_client_org_id()?:null)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Editing agreements and recording invoice payments are both required.']); exit;
}
if ($contractId<=0 || $serviceId<=0 || !can_access_record($pdo,'contracts',$contractId,$actor)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Agreement not available.']); exit;
}
try {
    $appConfig['_history_preview_secret']=(string)$_SESSION['csrf'];
    $action=(string)($_POST['history_action']??'');
    if ($action==='preview') $result=pa_recurring_history_preview($pdo,$contractId,$serviceId,$_POST,$appConfig);
    elseif ($action==='record') {
        $result=pa_recurring_history_record($pdo,$contractId,$serviceId,$_POST,$appConfig,$actor);
        $result['redirect']='/?page=contract/long-term-contract-details&id='.$contractId.'&history_recorded=1#recurring-services';
    } else throw new DomainException('Choose preview or record.');
    echo json_encode(['success'=>true]+$result,JSON_THROW_ON_ERROR);
} catch (DomainException|InvalidArgumentException $error) {
    http_response_code(409); echo json_encode(['success'=>false,'message'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('[recurring_history] '.$error->getMessage());
    http_response_code(500); echo json_encode(['success'=>false,'message'=>'No historical payment was recorded. Review the application log and try again.']);
}
exit;
