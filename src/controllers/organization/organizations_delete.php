<?php
// src/controllers/organization/organizations_delete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../../utils/api_v2_directory_management.php';
if (api_v2_directory_management_guard($pdo,'directory','delete')) { header('Location: /?page=organization/organizations-list&directory_managed=1'); exit; }

// Verify CSRF token
csrf_verify_post_or_redirect('organization/organizations-edit');

error_log('ORG_DELETE: Request received. POST: ' . json_encode($_POST));

$id = (int)($_POST['id'] ?? 0);
error_log('ORG_DELETE: Organization ID: ' . $id);

if ($id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20organization');
    exit;
}

// Delete the organization (clients will have organization_id set to NULL via ON DELETE SET NULL)
$projection = new App\Services\PortalProjectionMutationService();
$before = $projection->organizationScopes($pdo, $id);
$deleted = portal_projection_mutate($pdo, $before, static function () use ($pdo, $id): int {
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $lockClientIds = static function () use ($pdo, $id, $suffix): array {
        $clients = $pdo->prepare('SELECT id FROM clients WHERE organization_id=? ORDER BY id' . $suffix);
        $clients->execute([$id]);
        return array_map('intval', $clients->fetchAll(PDO::FETCH_COLUMN));
    };
    // Match client mutation order (children before parent) to avoid an
    // organization/client deadlock. Re-read after the parent lock: a client
    // inserted before that lock is included in the FK-detach revisions.
    $lockClientIds();
    $organization = $pdo->prepare('SELECT public_id FROM organizations WHERE id=?' . $suffix);
    $organization->execute([$id]);
    $organizationPublicId = $organization->fetchColumn();
    if ($organizationPublicId === false) return 0;
    $clientIds = $lockClientIds();
    api_v2_directory_record_delete($pdo, 'organization', (string)$organizationPublicId);
    $stmt = $pdo->prepare('DELETE FROM organizations WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Organization changed while preparing deletion.');
    foreach ($clientIds as $clientId) api_v2_directory_record($pdo, 'client', $clientId);
    return 1;
}, static fn(): array => []);
error_log('ORG_DELETE: Organization deleted. Rows affected: ' . (int)$deleted);

header('Location: /?page=organization/organizations-list&deleted=1');
exit;
