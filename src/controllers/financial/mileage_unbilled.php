<?php
require_once __DIR__ . '/../../utils/workforce_availability.php';
pa_workforce_require_available('financial/mileage-unbilled');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/api_response.php';
header('Content-Type: application/json');
$userId=(int)($_SESSION['user']['id']??0);$clientId=max(0,(int)($_GET['client_id']??0));
if($userId<=0)api_json_failure(401,'authentication_required','Authentication is required.');
if($clientId<=0)api_json_failure(422,'client_required','Choose a client first.');
if(!user_can($pdo,$userId,'invoices.create',0))api_json_failure(403,'permission_denied','Permission denied.');
try{
 $stmt=$pdo->prepare('SELECT a.id,a.mileage_log_id,a.charge_method,a.billable_miles,a.mileage_rate,a.fixed_amount,a.client_charge,
   m.trip_date,m.start_location,m.end_location,m.logged_miles,m.description,
   CASE WHEN a.charge_method="fixed_fee" THEN 1 ELSE a.billable_miles END quantity,
   CASE WHEN a.charge_method="fixed_fee" THEN a.client_charge ELSE a.mileage_rate END unit_price,
   CASE WHEN a.charge_method="fixed_fee" THEN "each" ELSE "mile" END billing_unit,
   p.name project_name
   FROM mileage_charge_allocations a JOIN mileage_logs m ON m.id=a.mileage_log_id
   LEFT JOIN projects p ON p.id=a.project_id
   WHERE a.client_id=? AND a.billed=0 ORDER BY m.trip_date,m.id,a.id');
 $stmt->execute([$clientId]);api_json_success(['data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}catch(PDOException $e){error_log('[MileageUnbilled]['.api_request_id().'] '.$e->getMessage());api_json_failure(503,'schema_out_of_date','Client travel charges are unavailable until the latest database migration is applied.');
}catch(Throwable $e){error_log('[MileageUnbilled]['.api_request_id().'] '.$e->getMessage());api_json_failure(500,'internal_error','Unable to load client travel charges.');}
