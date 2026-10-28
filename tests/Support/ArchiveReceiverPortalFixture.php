<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\PortalClientProvisioningService;
use App\Services\PortalProjectionService;
use PDO;
use RuntimeException;
use Throwable;

/** Synthetic producer setup for the optional archive-controller receiver fixture. */
final class ArchiveReceiverPortalFixture
{
    public static function setupPortal(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()
            || preg_match('/^pa_archive_http_[a-f0-9]{32}$/D', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()) !== 1) {
            throw new RuntimeException('Portal archive fixtures require the disposable archive HTTP database.');
        }
        $client = $pdo->query('SELECT id,public_id,name,email FROM clients WHERE id=91001')->fetch(PDO::FETCH_ASSOC);
        if (!$client || $client['public_id'] !== 'a1111111111111111111111111111111'
            || $client['name'] !== 'Synthetic archive fixture' || $client['email'] !== 'contact@example.invalid') {
            throw new RuntimeException('Synthetic archive client fixture is missing.');
        }
        $policy = $pdo->query('SELECT portal_principal_id,login_email FROM portal_client_contact_identity_policy WHERE client_id=91001')->fetch(PDO::FETCH_ASSOC);
        if (!$policy || $policy['portal_principal_id'] !== null || $policy['login_email'] !== null
            || (int)$pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn() !== 0) {
            throw new RuntimeException('Receiver fixture requires the protected NONE identity policy.');
        }

        $pdo->beginTransaction();
        try {
            // Delivery stays disabled and no credentials are installed. These
            // rows produce local outbox bytes only, never a receiver request.
            $pdo->prepare('INSERT INTO portal_integration_profiles
                (application_key,display_label,enabled,portal_projection_enabled,relation_projection_enabled,
                 contact_assignment_projection_enabled,delivery_enabled,portal_route,created_by,updated_by)
                VALUES (?,?,1,1,1,0,0,?,91001,91001)')
                ->execute(['synthetic_archive_receiver', 'Synthetic archive receiver fixture',
                    'https://archive-receiver.example.invalid/portal-v2']);
            $profileId = (int)$pdo->lastInsertId();
            $config = $pdo->prepare('INSERT INTO app_config(organization_id,config_key,config_value) VALUES (0,?,?)
                ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)');
            foreach (['portal_authoritative_hooks_enabled' => '1',
                'external_ops_client_portal_profile_id' => (string)$profileId,
                'portal_outbound_delivery_enabled' => '0'] as $key => $value) {
                $config->execute([$key, $value]);
            }
            $profile = $pdo->query('SELECT * FROM portal_integration_profiles WHERE id=' . $profileId)->fetch(PDO::FETCH_ASSOC);
            $scope = [['root_type' => 'standalone_client', 'root_public_id' => (string)$client['public_id']]];
            (new PortalClientProvisioningService())->ensureScopes($pdo, $scope, 91001, $profile);
            $workspace = $pdo->prepare('SELECT w.public_id FROM portal_v2_workspaces w
                JOIN portal_integration_profile_workspaces pw ON pw.workspace_id=w.id
                WHERE pw.profile_id=? AND pw.active=1 AND w.active=1
                  AND w.root_type="standalone_client" AND w.root_public_id=?');
            $workspace->execute([$profileId, $client['public_id']]);
            $workspaceId = $workspace->fetchColumn();
            if (!is_string($workspaceId) || $workspaceId === '') {
                throw new RuntimeException('Synthetic archive workspace was not provisioned.');
            }
            (new PortalProjectionService())->queueWorkspaceSnapshot($pdo, $profile, $workspaceId);
            if ((int)$pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn() !== 0
                || (int)$pdo->query('SELECT COUNT(*) FROM portal_principal_clients')->fetchColumn() !== 0
                || (int)$pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn() !== 0) {
                throw new RuntimeException('Protected NONE fixture unexpectedly gained portal authority.');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
