<?php

declare(strict_types=1);

use App\Services\ExternalOpsIntegrationService;
use App\Services\ExternalOpsConfigService;
use App\Services\PortalAuthorityService;
use App\Services\PortalProjectionService;
use App\Services\PortalProjectionMutationService;
use App\Services\PortalProjectionDeliveryConfigService;
use App\Services\PortalProjectionOutboxSender;
use App\Services\PortalClientProvisioningService;
use App\Services\ExternalOpsSyncOrchestrator;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/external_ops.php';

$actorUserId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorUserId < 1 || !user_can($pdo, $actorUserId, 'settings.manage', 0)) {
    http_response_code(403);
    exit('Permission denied');
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || !csrf_validate()) {
    header('Location: /?page=settings&tab=external-ops&saved=0&error=' . rawurlencode('Invalid request'));
    exit;
}

try {
    $action = (string)($_POST['action'] ?? '');
    $config = pa_external_ops_delivery_config($pdo);
    if ($action === 'save-config') {
        $pdo->beginTransaction();
        try {
            $config = (new ExternalOpsConfigService())->save($pdo, $_POST);
            // This capability is intentionally not part of the generic
            // integration config or deployment secrets. It remains an
            // explicit, administrator-controlled checkbox on the one connection.
            $config['service_assignment_projection_enabled'] = !empty($_POST['service_assignment_projection_enabled']);
            $config['contact_assignment_projection_enabled'] = !empty($_POST['contact_assignment_projection_enabled']);
            $portalProvisioning = new PortalClientProvisioningService();
            $profileId = $portalProvisioning->configureConnection($pdo, $config, $actorUserId);
            $portalConnectionStatus = $portalProvisioning->status($pdo, (string)$config['application_key']);
            if (!empty($portalConnectionStatus['transition_message'])) $message = (string)$portalConnectionStatus['transition_message'];
            audit_log($pdo, 'external_ops.configured', 'settings', null, [
                'enabled' => !empty($config['enabled']),
                'application_key' => (string)$config['application_key'],
                'webhook_host' => (string)(parse_url((string)$config['webhook_url'], PHP_URL_HOST) ?: ''),
                'client_portal_profile_id' => $profileId,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    } elseif ($action === 'generate-ed25519-signing-key') {
        $created = (new ExternalOpsConfigService())->generateEd25519Key($pdo);
        audit_log($pdo, 'external_ops.signing_key.generated', 'settings', null, ['key_id' => $created['key_id']]);
        $message = 'A staged Ed25519 public key was created. Register that public key at the receiver, then activate it here.';
    } elseif ($action === 'activate-ed25519-signing-key') {
        if (empty($_POST['receiver_key_registered'])) {
            throw new DomainException('Confirm that the receiver has registered this exact Ed25519 public key before activation.');
        }
        $keyId = trim((string)($_POST['signing_key_id'] ?? ''));
        (new ExternalOpsConfigService())->activateEd25519Key($pdo, $keyId);
        audit_log($pdo, 'external_ops.signing_key.activated', 'settings', null, ['key_id' => $keyId]);
        $message = 'Ed25519 signing was activated on the existing External Operations connection.';
    } elseif ($action === 'activate-hmac-signing') {
        if (empty($_POST['confirm_hmac_fallback'])) {
            throw new DomainException('Confirm the receiver still accepts the configured HMAC key before switching signing methods.');
        }
        (new ExternalOpsConfigService())->activateHmacSigning($pdo);
        audit_log($pdo, 'external_ops.signing_hmac.activated', 'settings', null, []);
        $message = 'HMAC-SHA256 signing was reactivated on the existing External Operations connection.';
    } elseif ($action === 'retire-ed25519-signing-key') {
        $keyId = trim((string)($_POST['signing_key_id'] ?? ''));
        (new ExternalOpsConfigService())->retireEd25519Key($pdo, $keyId);
        audit_log($pdo, 'external_ops.signing_key.retired', 'settings', null, ['key_id' => $keyId]);
        $message = 'The staged Ed25519 private key was removed. Its public-key record remains for audit.';
    } elseif ($action === 'reconcile-client-portal') {
        $summary=(new ExternalOpsSyncOrchestrator())->run($pdo,100,50,50,null,null,20);
        $reconciliation=$summary['reconciliation'];$portal=$summary['portal'];
        $message=sprintf('Workspace sync considered %d roots, completed %d, with %d remaining; %d workspace events delivered and %d retrying.',$reconciliation['considered'],$reconciliation['completed'],$reconciliation['remaining'],$portal['delivered'],$portal['failed']);
    } elseif ($action === 'retry-client-portal-revocations') {
        $retried=(new PortalClientProvisioningService())->retryFailedRevocations($pdo,(string)$config['application_key'],$actorUserId);
        $message=sprintf('%d failed workspace revocation(s) were requeued against the unchanged retired receiver contract.',$retried);
    } elseif ($action === 'set-client-portal-root') {
        if (!user_can($pdo,$actorUserId,'users.manage',0)) throw new DomainException('User-management permission is required to change client portal login access.');
        $active=(string)($_POST['access_state']??'')==='active';
        (new PortalClientProvisioningService())->setRootAccess($pdo,(string)$config['application_key'],(string)($_POST['root_type']??''),(string)($_POST['root_public_id']??''),$active,$actorUserId);
        $message=$active?'Client portal workspace restored.':'Client portal workspace revoked; existing content grants remain recorded but are no longer reachable.';
    } elseif ($action === 'set-client-portal-client') {
        if (!user_can($pdo,$actorUserId,'users.manage',0)) throw new DomainException('User-management permission is required to change client portal login access.');
        $active=(string)($_POST['access_state']??'')==='active';
        (new PortalClientProvisioningService())->setClientAccess($pdo,(string)$config['application_key'],(int)($_POST['client_id']??0),$active,$actorUserId);
        $message=$active?'Client portal login eligibility restored for reconciliation.':'Client portal login eligibility revoked.';
    } elseif ($action === 'save-portal-profile') {
        $profileId=(new PortalAuthorityService())->saveProfile($pdo,$_POST,$actorUserId);
        audit_log($pdo,'portal.integration_profile.saved','portal_integration_profile',$profileId,['application_key'=>(string)($_POST['application_key']??''),'enabled'=>!empty($_POST['enabled'])]);
        $message='Portal integration profile saved.';
    } elseif ($action === 'save-portal-runtime') {
        $runtime=(new PortalProjectionDeliveryConfigService())->saveRuntime($pdo,$_POST);audit_log($pdo,'portal.runtime.saved','settings',null,$runtime);$message='Portal projection runtime gates saved.';
    } elseif ($action === 'save-portal-delivery') {
        $profileId=(int)($_POST['profile_id']??0);(new PortalProjectionDeliveryConfigService())->saveProfile($pdo,$profileId,$_POST,$actorUserId);audit_log($pdo,'portal.delivery.saved','portal_integration_profile',$profileId,['enabled'=>!empty($_POST['delivery_enabled']),'key_id'=>(string)($_POST['delivery_key_id']??'')]);$message='Encrypted portal delivery settings saved.';
    } elseif ($action === 'send-portal-now') {
        $summary=(new PortalProjectionOutboxSender())->deliverDue($pdo,5,null,20);audit_log($pdo,'portal.delivery.run','settings',null,$summary);$message=sprintf('Portal delivery processed %d records; %d delivered, %d retrying, %d dead-lettered.',$summary['processed'],$summary['delivered'],$summary['failed'],$summary['dead_lettered']);
    } elseif ($action === 'save-portal-workspace') {
        $workspaceId=(new PortalAuthorityService())->saveWorkspace($pdo,(int)($_POST['profile_id']??0),(string)($_POST['root_type']??''),(string)($_POST['root_public_id']??''),(string)($_POST['display_name']??''),$actorUserId);
        audit_log($pdo,'portal.workspace.saved','portal_workspace',null,['workspace_public_id'=>$workspaceId]);$message='Portal workspace saved and explicitly linked to the selected profile.';
    } elseif ($action === 'set-portal-workspace-link') {
        $active=(string)($_POST['link_state']??'')==='link';
        (new PortalAuthorityService())->setWorkspaceLink($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),$active,$actorUserId);
        audit_log($pdo,$active?'portal.workspace.linked':'portal.workspace.unlinked','portal_workspace',null,['profile_id'=>(int)($_POST['profile_id']??0),'workspace_public_id'=>(string)($_POST['workspace_public_id']??'')]);
        $message=$active?'Workspace linked to profile.':'Workspace unlinked; projection and command authorization now fail closed for this profile.';
    } elseif ($action === 'save-portal-principal') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        $clientIds=array_values(array_filter(array_map('intval',(array)($_POST['client_ids']??[]))));
        $principalId=(new PortalAuthorityService())->savePrincipalAccess($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),!empty($_POST['principal_id'])?(int)$_POST['principal_id']:null,(string)($_POST['email']??''),(string)($_POST['display_name']??''),$clientIds,$actorUserId);
        $message='Client portal principal saved and its normalized projection was queued.';
    } elseif ($action === 'revoke-portal-principal') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->revokePrincipalAccess($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),$actorUserId);$message='Client portal principal, identity bindings, and scoped authority were revoked.';
    } elseif ($action === 'save-portal-entitlement') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->saveScopedEntitlement($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),(string)($_POST['capability']??''),(string)($_POST['scope_type']??''),(string)($_POST['scope_public_id']??''),(string)($_POST['effect']??''),(string)($_POST['entitlement_state']??'')==='active',$actorUserId);$message='Scoped client portal authority saved and projected.';
    } elseif ($action === 'appoint-portal-manager') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->appointManager($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),(string)($_POST['scope_type']??''),(string)($_POST['scope_public_id']??''),!empty($_POST['replace_principal_id'])?(int)$_POST['replace_principal_id']:null,$actorUserId,!empty($_POST['viewer_share_create']));$message='Portal manager authority saved and projection changes were queued atomically.';
    } elseif ($action === 'offboard-portal-manager') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->offboardManager($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),(string)($_POST['scope_type']??''),(string)($_POST['scope_public_id']??''),$actorUserId);$message='Portal manager was offboarded. If this was the final manager, the scope is now visibly locked until a replacement is appointed.';
    } elseif ($action === 'save-viewer-share-entitlement') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->saveViewerShareEntitlement($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),(string)($_POST['scope_type']??''),(string)($_POST['scope_public_id']??''),(string)($_POST['effect']??''),(string)($_POST['entitlement_state']??'')==='active',$actorUserId);$message='Scoped public-link authority saved and projection changes were queued atomically.';
    } elseif ($action === 'queue-portal-snapshot') {
        $profileId=(int)($_POST['profile_id']??0);$profileStmt=$pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=? AND enabled=1 AND portal_projection_enabled=1');$profileStmt->execute([$profileId]);$profile=$profileStmt->fetch(PDO::FETCH_ASSOC);if(!$profile)throw new DomainException('Enable portal projection before queuing a snapshot.');
        $pdo->beginTransaction();try{$summary=(new PortalProjectionService())->queueWorkspaceSnapshot($pdo,$profile,(string)($_POST['workspace_public_id']??''));audit_log($pdo,'portal.snapshot.queued','portal_workspace',null,$summary);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}$message='Complete portal snapshot queued.';
    } elseif ($action === 'queue-catalog-snapshot') {
        $profileId=(int)($_POST['profile_id']??0);$pdo->beginTransaction();try{$summaries=(new PortalProjectionMutationService())->queueCatalog($pdo,$profileId);if($summaries===[])throw new DomainException('Enable catalog projection and configure its receiver route before queuing a snapshot.');audit_log($pdo,'portal.catalog_snapshot.queued','portal_integration_profile',$profileId,$summaries[0]);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}$message='Complete Service Library snapshot queued.';
    } elseif (empty($config['configured_enabled'])) {
        throw new DomainException('Enable and save outbound delivery before managing synchronized records.');
    } elseif (in_array($action, ['grant-access','revoke-access','save-entitlement','resend-access'], true)) {
        if (!user_can($pdo, $actorUserId, 'users.manage', 0)) {
            throw new DomainException('User-management permission is required to change external operations access.');
        }
        $managedUserId = (int)($_POST['user_id'] ?? 0);
        $service = new ExternalOpsIntegrationService();
        $displayLabel = trim((string)($config['label'] ?? 'External operations')) ?: 'External operations';
        if ($action === 'resend-access') {
            $result = $service->resendAccountAccess($pdo, $managedUserId, (string)$config['application_key'], $actorUserId);
            if ($result === null || empty($result['event_id'])) {
                throw new DomainException('Only an active account with granted external operations access can be resent.');
            }
            audit_log($pdo, 'external_application.access_resent', 'user', $managedUserId, [
                'application_key' => (string)$config['application_key'],
                'event_id' => (string)$result['event_id'],
            ]);
            $message = $displayLabel . ' access was queued for resend.';
        } else {
            $grant = $action === 'grant-access' || ($action === 'save-entitlement' && !empty($_POST['enabled']));
            $result = $grant
                ? $service->grantAccountAccess($pdo, $managedUserId, (string)$config['application_key'], $actorUserId, (string)$config['label'])
                : $service->revokeAccountAccess($pdo, $managedUserId, (string)$config['application_key'], $actorUserId, (string)$config['label']);
            $message = $grant
                ? ($result['changed'] ? $displayLabel . ' access was granted.' : $displayLabel . ' access was already granted.')
                : ($result['changed'] ? $displayLabel . ' access was revoked.' : $displayLabel . ' access was already revoked.');
        }
    } elseif ($action === 'send-now') {
        if (empty($config['delivery_ready'])) {
            throw new DomainException('Outbound delivery is paused. Complete the required delivery settings or disable it until the receiver is ready.');
        }
        $summary=(new ExternalOpsSyncOrchestrator())->run($pdo,25,50,50,null,null,20);
        $reconciliation=$summary['reconciliation'];$ordinary=$summary['ordinary'];$portal=$summary['portal'];
        $message=sprintf('Synchronization completed a bounded pass: %d historical roots completed, %d remaining; ordinary events %d delivered / %d retrying; portal events %d delivered / %d retrying / %d failed.',$reconciliation['completed'],$reconciliation['remaining'],$ordinary['delivered'],$ordinary['failed'],$portal['delivered'],$portal['failed'],$portal['dead_lettered']);
    } else {
        throw new DomainException('Unknown integration action.');
    }

    $returnTo = trim((string)($_POST['return_to'] ?? ''));
    $clientActions = ['save-portal-workspace','set-portal-workspace-link','save-portal-principal','revoke-portal-principal','save-portal-entitlement','appoint-portal-manager','offboard-portal-manager','save-viewer-share-entitlement'];
    $advancedActions = ['save-portal-profile','save-portal-runtime','save-portal-delivery','send-portal-now','queue-portal-snapshot','queue-catalog-snapshot'];
    $returnTab = in_array($action, $clientActions, true) ? 'client-portal-access' : (in_array($action, $advancedActions, true) ? 'integration-advanced' : 'external-ops');
    if($returnTo==='client-details'&&!empty($_POST['client_id']))$location='/?page=client/client-details&id='.(int)$_POST['client_id'].'&updated=1';
    elseif($returnTo==='organization-view'&&!empty($_POST['organization_id']))$location='/?page=organization/organization-view&id='.(int)$_POST['organization_id'].'&updated=1';
    else $location = $returnTo === 'account-edit' && !empty($_POST['user_id'])
        ? '/?page=account-edit&id=' . (int)$_POST['user_id'] . '&success=' . rawurlencode($message ?? 'Saved')
        : '/?page=settings&tab=' . $returnTab . '&saved=1' . (isset($message) ? '&message=' . rawurlencode($message) : '');
    header('Location: ' . $location);
} catch (Throwable $error) {
    $diagnostic=substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12);error_log('[external_ops_settings] failed code='.$diagnostic);
    $publicError = $error instanceof DomainException
        ? $error->getMessage()
        : (($error instanceof PDOException && str_starts_with((string)$error->getCode(), '42'))
            ? 'The integration database schema does not match this Project Alpha release. Apply the current database migrations, then retry. Diagnostic code '.$diagnostic
            : 'The integration action failed. Diagnostic code '.$diagnostic);
    error_log('[external_ops_settings] action='.(string)($_POST['action']??'unknown').' class='.get_class($error).' error_code='.(string)$error->getCode().' diagnostic='.$diagnostic);
    $returnTo = trim((string)($_POST['return_to'] ?? ''));
    $failedAction = (string)($_POST['action'] ?? '');
    $clientActions = ['save-portal-workspace','set-portal-workspace-link','save-portal-principal','revoke-portal-principal','save-portal-entitlement','appoint-portal-manager','offboard-portal-manager','save-viewer-share-entitlement'];
    $advancedActions = ['save-portal-profile','save-portal-runtime','save-portal-delivery','send-portal-now','queue-portal-snapshot','queue-catalog-snapshot'];
    $returnTab = in_array($failedAction, $clientActions, true) ? 'client-portal-access' : (in_array($failedAction, $advancedActions, true) ? 'integration-advanced' : 'external-ops');
    if($returnTo==='client-details'&&!empty($_POST['client_id']))$location='/?page=client/client-details&id='.(int)$_POST['client_id'].'&error='.rawurlencode($publicError);
    elseif($returnTo==='organization-view'&&!empty($_POST['organization_id']))$location='/?page=organization/organization-view&id='.(int)$_POST['organization_id'].'&error='.rawurlencode($publicError);
    else $location = $returnTo === 'account-edit' && !empty($_POST['user_id'])
        ? '/?page=account-edit&id=' . (int)$_POST['user_id'] . '&error=' . rawurlencode($publicError)
        : '/?page=settings&tab=' . $returnTab . '&saved=0&error=' . rawurlencode($publicError);
    header('Location: ' . $location);
}
exit;
