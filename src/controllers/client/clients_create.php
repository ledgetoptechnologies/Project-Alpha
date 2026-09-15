<?php
// src/controllers/clients_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/address_book.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../../utils/api_v2_directory_management.php';
if (api_v2_directory_management_guard($pdo,'client','create')) { header('Location: /?page=client/clients-list&directory_managed=1'); exit; }

$__orgId = request_client_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$organization_id = (int)($_POST['organization_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$address_line1 = trim($_POST['address_line1'] ?? '');
$address_line2 = trim($_POST['address_line2'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
if ($state === '') { $state = ($appConfig['primary_state'] ?? 'WI'); }
$postal = trim($_POST['postal'] ?? '');
$country = trim($_POST['country'] ?? '');
if ($country === '') { $country = 'USA'; }

if ($name === '') {
    header('Location: /?page=client/clients-create&error=Name%20is%20required');
    exit;
}

try {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare('INSERT INTO clients (name, email, phone, organization_id, notes, address_line1, address_line2, city, state, postal_code, country, source_version, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([
    $name,
    $email ?: null,
    $phone ?: null,
    $organization_id > 0 ? $organization_id : null,
    $notes ?: null,
    $address_line1 ?: null,
    $address_line2 ?: null,
    $city ?: null,
    ($state ?: ($appConfig['primary_state'] ?? 'WI')),
    $postal ?: null,
    $country,
    portal_projection_source_version(),
    $__creator
  ]);

  $client_id = (int)$pdo->lastInsertId();
  address_book_save($pdo, [
    'label'=>'Billing address','address_line1'=>$address_line1,'address_line2'=>$address_line2,'city'=>$city,
    'state'=>$state,'postal_code'=>$postal,'country'=>$country,
    'google_place_id'=>trim((string)($_POST['google_place_id']??'')),
    'source'=>trim((string)($_POST['google_place_id']??''))!==''?'google':'manual',
  ], 'client', $client_id, 'billing', true, $__creator);
  audit_log($pdo, 'client.create', 'client', $client_id, ['organization_id' => $organization_id > 0 ? $organization_id : null, 'created_by' => $__creator]);
  $projection=new App\Services\PortalProjectionMutationService();
  $projection->afterMutation($pdo,$projection->clientScopes($pdo,$client_id));
  api_v2_directory_record($pdo, 'client', $client_id);
  $pdo->commit();
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[client_create] failed code='.substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12));
  header('Location: /?page=client/clients-create&error=Create%20failed');
  exit;
}

header('Location: /?page=client/clients-list&created=1');
exit;
