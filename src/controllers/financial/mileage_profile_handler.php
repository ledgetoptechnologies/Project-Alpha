<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../utils/csrf.php';require_once __DIR__.'/../../utils/csrf_sf.php';require_once __DIR__.'/../../utils/acl.php';require_once __DIR__.'/../../utils/crypto.php';require_once __DIR__.'/../../utils/mileage.php';require_once __DIR__.'/../../utils/address_book.php';
$userId=(int)($_SESSION['user']['id']??0);$orgId=request_client_org_id();$token=(string)($_POST['_token']??'');
if($userId<=0||!csrf_sf_is_valid('mileage_profile',$token)||!user_can($pdo,$userId,'financial.manage',0)){http_response_code(403);exit('Forbidden');}
$action=(string)($_POST['action']??'');
try{
 if($action==='save_origin'){
  $label=trim((string)($_POST['label']??''));$location=['address_line1'=>trim((string)($_POST['address_line1']??'')),'city'=>trim((string)($_POST['city']??'')),'state'=>trim((string)($_POST['state']??'')),'postal_code'=>trim((string)($_POST['postal_code']??'')),'country'=>'US'];
  if($label===''||$location['address_line1']==='')throw new InvalidArgumentException('Origin label and address are required.');$enc=crypto_encrypt(json_encode($location));if(!$enc)throw new RuntimeException('APP_ENCRYPTION_KEY is required to save a private billing origin.');
  if(!empty($_POST['is_default']))$pdo->prepare('UPDATE user_mileage_origins SET is_default=0 WHERE user_id=?')->execute([$userId]);
  $pdo->prepare('INSERT INTO user_mileage_origins (organization_id,user_id,label,location_enc,is_default) VALUES (?,?,?,?,?)')->execute([$orgId?:null,$userId,$label,$enc,!empty($_POST['is_default'])?1:0]);
 }elseif($action==='save_location'){
  $clientId=max(0,(int)($_POST['client_id']??0));$name=trim((string)($_POST['name']??''));if($clientId<=0||$name==='')throw new InvalidArgumentException('Client and location name are required.');
  $address=['label'=>$name,'address_line1'=>trim((string)($_POST['address_line1']??'')),'address_line2'=>trim((string)($_POST['address_line2']??'')),'city'=>trim((string)($_POST['city']??'')),'state'=>trim((string)($_POST['state']??'')),'postal_code'=>trim((string)($_POST['postal_code']??'')),'country'=>'US','google_place_id'=>trim((string)($_POST['google_place_id']??'')),'source'=>trim((string)($_POST['google_place_id']??''))!==''?'google':'manual'];
  $addressId=address_book_save($pdo,$address,'client',$clientId,'service',false,$userId);
  $pdo->prepare('INSERT INTO service_locations (organization_id,client_id,name,address_id,address_line1,address_line2,city,state,postal_code,country,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$orgId?:null,$clientId,$name,$addressId,$address['address_line1']?:null,$address['address_line2']?:null,$address['city']?:null,$address['state']?:null,$address['postal_code']?:null,'US',$userId]);
 }elseif($action==='save_distance'){
  $originId=max(0,(int)($_POST['origin_id']??0));$locationId=max(0,(int)($_POST['service_location_id']??0));$miles=(float)($_POST['one_way_miles']??0);if($miles<=0)throw new InvalidArgumentException('One-way miles must be greater than zero.');
  $check=$pdo->prepare('SELECT id FROM user_mileage_origins WHERE id=? AND user_id=?');$check->execute([$originId,$userId]);if(!$check->fetchColumn())throw new RuntimeException('Billing origin not found.');
  $pdo->prepare('INSERT INTO travel_distance_cache (origin_id,service_location_id,one_way_miles,source) VALUES (?,?,?,"manual") ON DUPLICATE KEY UPDATE one_way_miles=VALUES(one_way_miles),source="manual"')->execute([$originId,$locationId,$miles]);
 }elseif($action==='save_client_rule'){
  $clientId=max(0,(int)($_POST['client_id']??0));
  $owned=$pdo->prepare('SELECT id FROM clients WHERE id=? AND archived=0');$owned->execute([$clientId]);if(!$owned->fetchColumn())throw new RuntimeException('Client not found.');
  mileage_save_client_rule($pdo,$orgId?:null,$clientId,$userId,mileage_rule_from_post($_POST));
 }else throw new InvalidArgumentException('Invalid mileage setup action.');
 header('Location: /?page=financial/setup&tab=rates&saved=1');
}catch(Throwable $e){header('Location: /?page=financial/setup&tab=rates&error='.urlencode($e->getMessage()));}exit;
