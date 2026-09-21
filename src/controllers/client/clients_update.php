<?php
// src/controllers/clients_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/ClientProfileMutationService.php';
require_once __DIR__ . '/../../utils/api_v2_directory_management.php';
if (api_v2_directory_management_guard($pdo,'client','profile')) { header('Location: /?page=client/clients-list&directory_managed=1'); exit; }

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

(new \App\Services\ClientProfileMutationService())->mutate($pdo, $id, [
  'name' => $name,
  'email' => $email,
  'phone' => $phone,
  'organization_id' => $organization_id,
  'notes' => $notes,
  'address' => [
    'address_line1' => $address_line1, 'address_line2' => $address_line2,
    'city' => $city, 'state' => $state ?: 'WI', 'postal_code' => $postal,
    'country' => $country,
  ],
  'google_place_id' => trim((string)($_POST['google_place_id'] ?? '')),
  'actor_id' => (int)($_SESSION['user']['id'] ?? 0),
]);

header('Location: /?page=client/client-details&id=' . $id . '&updated=1');
exit;
