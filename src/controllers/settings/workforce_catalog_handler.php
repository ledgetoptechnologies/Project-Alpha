<?php

declare(strict_types=1);

use App\Services\JobWorkPlanningService;
use App\Services\CompensationRuleService;
use App\Services\PayPeriodService;
use App\Services\PortalProjectionMutationService;
use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/external_ops.php';
require_once __DIR__ . '/../../utils/audit.php';

$userId=(int)($_SESSION['user']['id']??0);$action=(string)($_POST['action']??'');$tab=(string)($_POST['return_tab']??'work-types');
$redirect='/?page=settings&tab='.rawurlencode($tab);
if($userId<=0||!csrf_validate()){http_response_code(403);exit('Forbidden');}
$can=static fn(string $permission):bool=>user_can($pdo,$userId,$permission,0);
$syncOpsAccount=static function(?int $accountId)use($pdo,$userId):void{
 if(!$accountId)return;
 $config=pa_external_ops_delivery_config($pdo);
 if(!empty($config['enabled']))(new \App\Services\ExternalOpsIntegrationService())->resyncAccountAccess($pdo,$accountId,(string)$config['application_key'],$userId);
};
$requiredPermissions=match($action){
 'save-worker-profile','save-business-unit','save-unit-membership','end-unit-membership','assign-worker-unit','assign-client-unit','save-worker-scope'=>['workforce.business_units.manage','settings.manage'],
 'save-work-type','set-work-type-status','delete-work-type'=>['workforce.catalog.manage','settings.manage'],
 'save-pay-schedule'=>['workforce.pay_periods.manage','settings.manage'],
 'assignment-offer','assignment-approve','assignment-eligible'=>['workforce.assignments.manage'],
 'close-pay-period'=>['workforce.pay_periods.manage'],
 'settle-statement'=>['workforce.statements.manage'],
 default=>[],
};
if(!$requiredPermissions||!array_filter($requiredPermissions,$can)){http_response_code(403);exit('Forbidden');}
try{
 if($action==='save-worker-profile'){
  $id=(int)($_POST['id']??0);$display=trim((string)($_POST['display_name']??''));$relationship=strtolower(trim((string)($_POST['relationship_type']??'employee')));$accountId=(int)($_POST['user_id']??0)?:null;$status=(string)($_POST['status']??'active');$currency=strtoupper(trim((string)($_POST['currency']??'USD')));$reviewPolicy=trim((string)($_POST['time_review_policy']??''));$compensationPolicy=trim((string)($_POST['compensation_policy']??''));
  $previousAccountId=null;if($id){$previousAccount=$pdo->prepare('SELECT user_id,time_review_policy,compensation_policy FROM worker_profiles WHERE id=?');$previousAccount->execute([$id]);$previousProfile=$previousAccount->fetch(PDO::FETCH_ASSOC)?:[];$previousAccountId=(int)($previousProfile['user_id']??0)?:null;$reviewPolicy=(string)($previousProfile['time_review_policy']??'manager_review');$compensationPolicy=$compensationPolicy!==''?$compensationPolicy:(string)($previousProfile['compensation_policy']??'needs_setup');}else{$reviewPolicy='manager_review';$compensationPolicy=$compensationPolicy!==''?$compensationPolicy:'needs_setup';}
  if($display===''||!preg_match('/^[a-z0-9_-]{2,50}$/',$relationship))throw new DomainException('Enter a worker name and valid relationship type.');
  if(!in_array($status,['active','inactive','terminated'],true)||!preg_match('/^[A-Z]{3}$/',$currency))throw new DomainException('Choose a valid worker status and currency.');
  if(!in_array($reviewPolicy,['manager_review','self_confirm','auto_confirm'],true))throw new DomainException('Choose a valid time review policy.');
  if(!in_array($compensationPolicy,['rules','nonpayable','owner_no_pay','needs_setup'],true))throw new DomainException('Choose a valid compensation policy.');
  $pdo->beginTransaction();
  if($id){
   $pdo->prepare('UPDATE worker_profiles SET user_id=?,relationship_type=?,relationship_review_required=0,relationship_review_reason=NULL,relationship_reviewed_by=?,relationship_reviewed_at=UTC_TIMESTAMP(6),time_review_policy=?,compensation_policy=?,status=?,display_name=?,currency=?,ended_at=CASE WHEN ?="terminated" THEN COALESCE(ended_at,CURRENT_DATE) ELSE NULL END WHERE id=?')->execute([$accountId,$relationship,$userId,$reviewPolicy,$compensationPolicy,$status,$display,$currency,$status,$id]);
  }else{
   $pdo->prepare('INSERT INTO worker_profiles (user_id,relationship_type,time_review_policy,compensation_policy,status,display_name,currency) VALUES (?,?,?,?,?,?,?)')->execute([$accountId,$relationship,$reviewPolicy,$compensationPolicy,$status,$display,$currency]);
  }
  $syncOpsAccount($previousAccountId);if($accountId!==$previousAccountId)$syncOpsAccount($accountId);
  if($accountId&&$relationship==='owner'&&$status==='active'){
   $approval=new ApprovalService($pdo,new AuditRecorder($pdo),new BillingTimeConsumer($pdo));
   $result=$approval->reconcileVerifiedOwnerEntries($accountId);
   if($result['failed'])@error_log('[owner-time-reconciliation] '.count($result['failed']).' entries still require review after owner verification.');
  }
  $pdo->commit();
 }elseif($action==='save-work-type'){
  $id=max(0,(int)($_POST['id']??0));
  $name=trim((string)($_POST['name']??''));
  $rawCode=trim((string)($_POST['code']??''));
  $code=trim(strtoupper((string)preg_replace('/[^A-Za-z0-9]+/','_',$rawCode!==''?$rawCode:$name)),'_');
  $description=trim((string)($_POST['description']??''));
  $method=(string)($_POST['method']??'nonpayable');
  $basis=(string)($_POST['percentage_basis']??'net_line');
  $trigger=(string)($_POST['eligibility_trigger']??'completed_approved');
  $compensationCurrency=strtoupper(trim((string)($_POST['compensation_currency']??'USD')));
  $amount=($_POST['amount']??'')===''?null:(float)$_POST['amount'];
  $includedMinutes=($_POST['included_minutes']??'')===''?null:(int)$_POST['included_minutes'];
  $overageRate=($_POST['overage_rate']??'')===''?null:(float)$_POST['overage_rate'];
  $percentage=($_POST['percentage']??'')===''?null:(float)$_POST['percentage'];

  if($name===''||$code==='')throw new DomainException('Name and code are required.');
  if(strlen($name)>190||strlen($code)>64)throw new DomainException('The Work Activity name or code is too long.');
  if(!in_array($method,['nonpayable','hourly','fixed','base_overage','percentage'],true))throw new DomainException('Invalid compensation method.');
  if(!in_array($basis,['gross_line','net_line','cash_collected'],true)||!in_array($trigger,['completed_approved','delivered','invoice_paid','manual_release'],true))throw new DomainException('Invalid compensation basis or eligibility trigger.');
  if(!preg_match('/^[A-Z]{3}$/',$compensationCurrency))throw new DomainException('Currency must use a three-letter code.');
  foreach([$amount,$overageRate,$percentage] as $number)if($number!==null&&$number<0)throw new DomainException('Rates and amounts cannot be negative.');
  if($includedMinutes!==null&&$includedMinutes<0)throw new DomainException('Included minutes cannot be negative.');
  if($percentage!==null&&$percentage>100)throw new DomainException('Compensation percentage cannot exceed 100.');
  if(in_array($method,['hourly','fixed'],true)&&$amount===null)throw new DomainException('Enter the worker rate or fixed amount.');
  if($method==='base_overage'&&($amount===null||$includedMinutes===null||$overageRate===null))throw new DomainException('Base-plus-overage compensation requires a base amount, included minutes, and overage rate.');
  if($method==='percentage'&&$percentage===null)throw new DomainException('Enter the worker compensation percentage.');
  if($basis==='cash_collected'&&$method==='percentage'&&$trigger!=='invoice_paid')throw new DomainException('Cash-collected percentages require the invoice-paid trigger.');

  if($method==='nonpayable'){$amount=null;$includedMinutes=null;$overageRate=null;$percentage=null;}
  elseif(in_array($method,['hourly','fixed'],true)){$includedMinutes=null;$overageRate=null;$percentage=null;}
  elseif($method==='base_overage'){$percentage=null;}
  elseif($method==='percentage'){$amount=null;$includedMinutes=null;$overageRate=null;}

  $pdo->beginTransaction();
  $workTypeValues=[$name,$code,$description!==''?$description:null,isset($_POST['is_active'])?1:0,$method,$amount,$includedMinutes,$overageRate,$percentage,$basis,$trigger,$compensationCurrency];
  if($id>0){
   $exists=$pdo->prepare('SELECT id FROM work_types WHERE id=? FOR UPDATE');$exists->execute([$id]);
   if(!$exists->fetchColumn())throw new DomainException('Work Activity not found.');
   $pdo->prepare('UPDATE work_types SET name=?,code=?,description=?,is_active=?,default_compensation_method=?,default_amount=?,default_base_minutes=?,default_overage_rate=?,default_percentage=?,default_percentage_basis=?,default_eligibility_trigger=?,currency=? WHERE id=?')->execute(array_merge($workTypeValues,[$id]));
  }else{
   $pdo->prepare('INSERT INTO work_types (name,code,description,is_active,default_compensation_method,default_amount,default_base_minutes,default_overage_rate,default_percentage,default_percentage_basis,default_eligibility_trigger,currency,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($workTypeValues,[$userId]));
   $id=(int)$pdo->lastInsertId();
  }
  // Client pricing belongs to Services. This compatibility row intentionally has no price.
  $pdo->prepare('INSERT INTO work_type_billing_defaults (work_type_id,default_treatment,default_billing_rate,currency,created_by,updated_by) VALUES (?,"undecided",NULL,?,?,?) ON DUPLICATE KEY UPDATE default_treatment="undecided",default_billing_rate=NULL,currency=VALUES(currency),updated_by=VALUES(updated_by)')->execute([$id,$compensationCurrency,$userId,$userId]);
  $pdo->commit();
  }elseif($action==='set-work-type-status'){
  $id=max(0,(int)($_POST['id']??0));$status=(string)($_POST['status']??'');
  if($id<=0||!in_array($status,['active','inactive'],true))throw new DomainException('Choose a valid Work Activity status.');
  $pdo->beginTransaction();
  $linked=$pdo->prepare('SELECT item_library_id FROM catalog_work_components WHERE work_type_id=? AND is_active=1 LIMIT 1 FOR UPDATE');$linked->execute([$id]);$linkedServiceId=(int)($linked->fetchColumn()?:0);
  $update=$pdo->prepare('UPDATE work_types SET is_active=? WHERE id=?');$update->execute([$status==='active'?1:0,$id]);
   if($update->rowCount()===0){$exists=$pdo->prepare('SELECT 1 FROM work_types WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())throw new DomainException('Work Activity not found.');}
  if($linkedServiceId>0){$pdo->prepare('UPDATE item_library SET is_active=? WHERE id=?')->execute([$status==='active'?1:0,$linkedServiceId]);(new PortalProjectionMutationService())->queueCatalogChanges($pdo);}
  $pdo->commit();
  }elseif($action==='delete-work-type'){
   $id=max(0,(int)($_POST['id']??0));if($id<=0)throw new DomainException('Choose a Work Activity to delete.');
   $pdo->beginTransaction();$exists=$pdo->prepare('SELECT name FROM work_types WHERE id=? FOR UPDATE');$exists->execute([$id]);if(!$exists->fetchColumn())throw new DomainException('Work Activity not found.');
   foreach([['job_work_components','work_type_id'],['work_time_entries','work_type_id'],['worker_compensation_rules','work_type_id']] as [$table,$column]){$used=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$column}=? LIMIT 1");$used->execute([$id]);if($used->fetchColumn())throw new DomainException('This Work Activity has already been used. Deactivate it to preserve historical time, billing, and compensation records.');}
   $linked=$pdo->prepare('SELECT item_library_id FROM catalog_work_components WHERE work_type_id=? AND is_active=1 LIMIT 1 FOR UPDATE');$linked->execute([$id]);$linkedServiceId=(int)($linked->fetchColumn()?:0);
   if($linkedServiceId>0){foreach([['quote_items','item_library_id'],['contract_items','item_library_id'],['invoice_items','item_library_id'],['job_work_components','item_library_id'],['catalog_bundle_items','child_item_library_id']] as [$table,$column]){$used=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$column}=? LIMIT 1");$used->execute([$linkedServiceId]);if($used->fetchColumn())throw new DomainException('The linked Service has already been used. Deactivate the pair to preserve document and Job history.');}$pdo->prepare('DELETE FROM catalog_work_components WHERE work_type_id=?')->execute([$id]);$pdo->prepare('DELETE FROM item_library WHERE id=?')->execute([$linkedServiceId]);(new PortalProjectionMutationService())->queueCatalogChanges($pdo);}
   $pdo->prepare('DELETE FROM work_types WHERE id=?')->execute([$id]);$pdo->commit();
 }elseif($action==='save-business-unit'){
  $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$code=strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',trim((string)($_POST['code']??$name))));if($name===''||$code==='')throw new DomainException('Name and code are required.');
  $isDefault=!empty($_POST['is_default']);
  if($id)$pdo->prepare('UPDATE business_units SET name=?,code=?,description=?,is_active=? WHERE id=?')->execute([$name,$code,trim((string)($_POST['description']??''))?:null,isset($_POST['is_active'])?1:0,$id]);
  else $pdo->prepare('INSERT INTO business_units (name,code,description,is_active,created_by) VALUES (?,?,?,?,?)')->execute([$name,$code,trim((string)($_POST['description']??''))?:null,isset($_POST['is_active'])?1:0,$userId]);
  if(!$id)$id=(int)$pdo->lastInsertId();
  if($isDefault){if(empty($_POST['is_active']))throw new DomainException('The default Business Unit must be active.');$pdo->prepare('INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,"default_business_unit_id",?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)')->execute([(string)$id]);}
  else{$defaultUnit=$pdo->query('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key="default_business_unit_id" LIMIT 1')->fetchColumn();if((int)$defaultUnit===$id)$pdo->prepare('DELETE FROM app_config WHERE organization_id=0 AND config_key="default_business_unit_id"')->execute();}
  audit_log($pdo,'business_unit.saved','business_unit',$id,['name'=>$name,'is_active'=>isset($_POST['is_active']),'is_default'=>$isDefault]);
  $opsConfig=pa_external_ops_delivery_config($pdo);if(!empty($opsConfig['enabled'])){$unitEvent=$pdo->prepare('SELECT * FROM business_units WHERE id=?');$unitEvent->execute([$id]);(new \App\Services\ExternalOpsIntegrationService())->enqueueProjectionChange($pdo,(string)$opsConfig['application_key'],'business_unit',$id,'upsert',$unitEvent->fetch(PDO::FETCH_ASSOC)?:[]);}
 }elseif($action==='save-unit-membership'){
  $businessUnitId=(int)($_POST['business_unit_id']??0);$accountId=(int)($_POST['user_id']??0);$role=(string)($_POST['membership_role']??'member');$isPrimary=!empty($_POST['is_primary']);
  if($businessUnitId<1||$accountId<1||!in_array($role,['member','head'],true))throw new DomainException('Choose an active user, Business Unit, and valid designation.');
  $pdo->beginTransaction();
  $unit=$pdo->prepare('SELECT name FROM business_units WHERE id=? AND is_active=1 FOR UPDATE');$unit->execute([$businessUnitId]);$unitName=$unit->fetchColumn();if(!$unitName)throw new DomainException('The selected Business Unit is not active.');
  $account=$pdo->prepare('SELECT COALESCE(NULLIF(username,""),email) FROM users WHERE id=? AND is_disabled=0 AND deleted_at IS NULL FOR UPDATE');$account->execute([$accountId]);$accountName=$account->fetchColumn();if(!$accountName)throw new DomainException('The selected user is not active.');
  $existing=$pdo->prepare('SELECT id FROM business_unit_memberships WHERE business_unit_id=? AND user_id=? AND (ended_at IS NULL OR ended_at>UTC_TIMESTAMP(6)) ORDER BY id DESC LIMIT 1 FOR UPDATE');$existing->execute([$businessUnitId,$accountId]);$membershipId=(int)($existing->fetchColumn()?:0);
  if($isPrimary)$pdo->prepare('UPDATE business_unit_memberships SET is_primary=0 WHERE user_id=? AND (ended_at IS NULL OR ended_at>UTC_TIMESTAMP(6))')->execute([$accountId]);
  if($membershipId>0){
   $pdo->prepare('UPDATE business_unit_memberships SET membership_role=?,is_primary=?,assigned_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$role,$isPrimary?1:0,$userId,$membershipId]);
  }else{
   $pdo->prepare('INSERT INTO business_unit_memberships (business_unit_id,user_id,membership_role,is_primary,assigned_by) VALUES (?,?,?,?,?)')->execute([$businessUnitId,$accountId,$role,$isPrimary?1:0,$userId]);$membershipId=(int)$pdo->lastInsertId();
  }
  $primary=$pdo->prepare('SELECT id FROM business_unit_memberships WHERE user_id=? AND is_primary=1 AND (ended_at IS NULL OR ended_at>UTC_TIMESTAMP(6)) LIMIT 1');$primary->execute([$accountId]);
  if(!$primary->fetchColumn())$pdo->prepare('UPDATE business_unit_memberships SET is_primary=1 WHERE id=?')->execute([$membershipId]);
  $effectivePrimaryStmt=$pdo->prepare('SELECT is_primary FROM business_unit_memberships WHERE id=?');$effectivePrimaryStmt->execute([$membershipId]);$effectivePrimary=(bool)$effectivePrimaryStmt->fetchColumn();
  audit_log($pdo,'business_unit.membership.saved','business_unit_membership',$membershipId,['business_unit_id'=>$businessUnitId,'user_id'=>$accountId,'designation'=>$role,'is_primary'=>$effectivePrimary]);
  $pdo->commit();
 }elseif($action==='end-unit-membership'){
  $membershipId=(int)($_POST['membership_id']??0);if($membershipId<1)throw new DomainException('Choose a Business Unit membership to end.');
  $pdo->beginTransaction();
  $membership=$pdo->prepare('SELECT business_unit_id,user_id,membership_role,is_primary FROM business_unit_memberships WHERE id=? AND (ended_at IS NULL OR ended_at>UTC_TIMESTAMP(6)) FOR UPDATE');$membership->execute([$membershipId]);$membershipRow=$membership->fetch(PDO::FETCH_ASSOC);if(!$membershipRow)throw new DomainException('This membership is no longer active.');
  $pdo->prepare('UPDATE business_unit_memberships SET is_primary=0,ended_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$membershipId]);
  if(!empty($membershipRow['is_primary'])){
   $replacement=$pdo->prepare('SELECT id FROM business_unit_memberships WHERE user_id=? AND id<>? AND (ended_at IS NULL OR ended_at>UTC_TIMESTAMP(6)) ORDER BY assigned_at,id LIMIT 1 FOR UPDATE');$replacement->execute([(int)$membershipRow['user_id'],$membershipId]);$replacementId=(int)($replacement->fetchColumn()?:0);
   if($replacementId>0)$pdo->prepare('UPDATE business_unit_memberships SET is_primary=1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$replacementId]);
  }
  audit_log($pdo,'business_unit.membership.ended','business_unit_membership',$membershipId,['business_unit_id'=>(int)$membershipRow['business_unit_id'],'user_id'=>(int)$membershipRow['user_id'],'designation'=>(string)$membershipRow['membership_role']]);
  $pdo->commit();
 }elseif($action==='assign-worker-unit'){
  $workerProfileId=(int)$_POST['worker_profile_id'];$pdo->prepare('INSERT INTO worker_business_units (worker_profile_id,business_unit_id,is_lead,assigned_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_lead=VALUES(is_lead),assigned_by=VALUES(assigned_by),assigned_at=UTC_TIMESTAMP(6),ends_at=NULL')->execute([$workerProfileId,(int)$_POST['business_unit_id'],isset($_POST['is_lead'])?1:0,$userId]);
 }elseif($action==='assign-client-unit'){
  $pdo->prepare('INSERT INTO client_business_units (client_id,business_unit_id,assigned_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by),assigned_at=UTC_TIMESTAMP(6)')->execute([(int)$_POST['client_id'],(int)$_POST['business_unit_id'],$userId]);
 }elseif($action==='save-worker-scope'){
  $scope=(string)($_POST['access_scope']??'own');if(!in_array($scope,['own','assigned','business_unit','all'],true))throw new DomainException('Invalid worker access scope.');
  $capability=(string)($_POST['capability']??'');if(!in_array($capability,['clients.search','jobs.view','documents.create','documents.view','timekeeping.self','timekeeping.manage','approvals.review','mileage.self','workforce.assignments.manage','workforce.pay_periods.manage','workforce.statements.self','workforce.statements.manage'],true))throw new DomainException('Invalid worker capability.');
  $pdo->prepare('INSERT INTO worker_capability_scopes (worker_profile_id,capability,access_scope,allowed,granted_by) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE access_scope=VALUES(access_scope),allowed=1,granted_by=VALUES(granted_by)')->execute([(int)$_POST['worker_profile_id'],$capability,$scope,$userId]);
 }elseif($action==='save-pay-schedule'){
  $cadence=(string)($_POST['cadence']??'biweekly');if(!in_array($cadence,['weekly','biweekly','semimonthly','monthly','custom'],true))throw new DomainException('Invalid cadence.');
  $deadlineTime=trim((string)($_POST['deadline_time']??'20:00'));if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$deadlineTime))throw new DomainException('Choose a valid finalization deadline.');
  foreach(['workforce_pay_period_cadence'=>$cadence,'workforce_pay_period_anchor'=>trim((string)($_POST['anchor']??'')),'workforce_pay_period_custom_days'=>(string)max(1,min(366,(int)($_POST['custom_days']??14))),'workforce_period_deadline_time'=>$deadlineTime,'workforce_period_auto_confirm'=>!empty($_POST['auto_confirm'])?'1':'0'] as $key=>$value)$pdo->prepare('INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)')->execute([$key,$value]);
 }elseif($action==='assignment-offer'){
  (new JobWorkPlanningService($pdo,new CompensationRuleService($pdo)))->offer((int)$_POST['assignment_id'],(int)$_POST['worker_profile_id'],$userId);
 }elseif($action==='assignment-approve'){
  (new JobWorkPlanningService($pdo,new CompensationRuleService($pdo)))->approvePayable((int)$_POST['assignment_id'],$userId);
 }elseif($action==='assignment-eligible'){
  $trigger=(string)($_POST['trigger_event']??'completed_approved');
  if(!in_array($trigger,['completed_approved','delivered','manual_release'],true))throw new DomainException('This compensation trigger cannot be released manually.');
  (new JobWorkPlanningService($pdo,new CompensationRuleService($pdo)))->markEligible((int)$_POST['assignment_id'],['trigger_event'=>$trigger],$userId);
 }elseif($action==='close-pay-period'){
  $result=(new PayPeriodService($pdo))->close((int)$_POST['pay_period_id'],$userId,!empty($_POST['force']));
  if(!$result['closed'])throw new DomainException('Missing submissions: '.implode(' ',(array)$result['warnings']).' Review them, or use Close anyway.');
 }elseif($action==='settle-statement'){
  (new PayPeriodService($pdo))->settleStatement((int)$_POST['statement_id']);
 }else throw new DomainException('Unsupported workforce setting action.');
 header('Location: '.$redirect.'&saved=1');exit;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();header('Location: '.$redirect.'&error='.rawurlencode($e instanceof DomainException?$e->getMessage():'Unable to save workforce settings.'));exit;}
