<?php
// src/controllers/clients_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/address_book.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

$id = (int)($_POST['id'] ?? 0);
require_record_ownership($pdo, 'clients', $id);
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$organization_id = (int)($_POST['organization_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$address_line1 = trim($_POST['address_line1'] ?? '');
$address_line2 = trim($_POST['address_line2'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
$postal = trim($_POST['postal'] ?? '');
$country = trim($_POST['country'] ?? '');
if ($country === '') { $country = 'USA'; }

if ($id <= 0 || $name === '') {
  header('Location: /?page=client/clients-edit&id='.(int)$id.'&error=Invalid%20input');
  exit;
}

$portalProjection=new App\Services\PortalProjectionMutationService();
portal_projection_mutate($pdo,static fn():array=>$portalProjection->lockedClientScopes($pdo,$id,$organization_id>0?$organization_id:null),static function()use($pdo,$name,$email,$phone,$organization_id,$notes,$address_line1,$address_line2,$city,$state,$postal,$country,$id):void{$st = $pdo->prepare('UPDATE clients SET name=?, email=?, phone=?, organization_id=?, notes=?, address_line1=?, address_line2=?, city=?, state=?, postal_code=?, country=?,source_version=? WHERE id=?');
$st->execute([
  $name,
  $email ?: null,
  $phone ?: null,
  $organization_id > 0 ? $organization_id : null,
  $notes ?: null,
  $address_line1 ?: null,
  $address_line2 ?: null,
  $city ?: null,
  ($state ?: 'WI'),
  $postal ?: null,
  $country,
  portal_projection_source_version(),
  $id
]);api_v2_directory_record($pdo, 'client', $id);},static fn():array=>$portalProjection->clientScopes($pdo,$id),true);
address_book_save($pdo, [
  'label'=>'Billing address','address_line1'=>$address_line1,'address_line2'=>$address_line2,'city'=>$city,
  'state'=>$state,'postal_code'=>$postal,'country'=>$country,
  'google_place_id'=>trim((string)($_POST['google_place_id']??'')),
  'source'=>trim((string)($_POST['google_place_id']??''))!==''?'google':'manual',
], 'client', $id, 'billing', true, (int)($_SESSION['user']['id']??0));

header('Location: /?page=client/client-details&id=' . $id . '&updated=1');
exit;
