<?php
// src/controllers/organization/organization_add_client.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

$organization_id = (int)($_POST['organization_id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);

if ($organization_id <= 0 || $client_id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20input');
    exit;
}

require_record_ownership($pdo, 'organizations', $organization_id);

$stmt = $pdo->prepare('SELECT id, organization_id, created_by FROM clients WHERE id = ? AND archived = 0 LIMIT 1');
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    header('Location: /?page=organization/organization-view&id=' . $organization_id . '&error=Client%20not%20found');
    exit;
}

$currentOrganizationId = isset($client['organization_id']) ? (int)$client['organization_id'] : 0;
$userId = (int)($_SESSION['user']['id'] ?? 0);
$isAdmin = (($_SESSION['user']['role'] ?? '') === 'admin');
$isOrgManager = acl_user_has_org_wide_scope($pdo, $userId, $organization_id);
$createdByUser = isset($client['created_by']) && (int)$client['created_by'] === $userId;

$canAttachClient = $isAdmin
    || $currentOrganizationId === $organization_id
    || ($currentOrganizationId === 0 && ($isOrgManager || $createdByUser));

if (!$canAttachClient) {
    require_once __DIR__ . '/../../utils/acl_middleware.php';
    deny_response('organization/organization-view');
}

// Update client to set organization_id
$projection=new App\Services\PortalProjectionMutationService();portal_projection_mutate($pdo,static fn():array=>$projection->lockedClientScopes($pdo,$client_id,$organization_id),static function()use($pdo,$organization_id,$client_id,$currentOrganizationId):void{$current=$pdo->prepare('SELECT organization_id FROM clients WHERE id=?');$current->execute([$client_id]);$actualOrganizationId=(int)($current->fetchColumn()?:0);if($actualOrganizationId!==$currentOrganizationId)throw new DomainException('Client organization relationship changed.');if($actualOrganizationId===$organization_id)return;$oldOrganizationPredicate=$currentOrganizationId===0?'organization_id IS NULL':'organization_id=?';$update=$pdo->prepare('UPDATE clients SET organization_id=?,source_version=? WHERE id=? AND '.$oldOrganizationPredicate);$params=[$organization_id,portal_projection_source_version(),$client_id];if($currentOrganizationId>0)$params[]=$currentOrganizationId;$update->execute($params);if($update->rowCount()!==1)throw new DomainException('Client organization relationship changed.');api_v2_directory_record($pdo,'client',$client_id);},static fn():array=>$projection->clientScopes($pdo,$client_id),true);

header('Location: /?page=organization/organization-view&id=' . $organization_id . '&client_added=1');
exit;
