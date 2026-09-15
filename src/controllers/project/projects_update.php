<?php
// src/controllers/project/projects_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../utils/project_billing_transition.php';
require_once __DIR__ . '/../../utils/public_project_links.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../utils/external_ops.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../services/ProjectRevisionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-update');

$id = (int)($_POST['id'] ?? 0); if (!$id) { header('Location: /?page=project/projects-list&error=Invalid'); exit; }
$detailsRedirect = '/?page=project/projects-details&id=' . $id;
$editRedirect = '/?page=project/projects-edit&id=' . $id;
$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$projectClientIds = $_POST['project_client_ids'] ?? [];
if (!is_array($projectClientIds)) { $projectClientIds = []; }
$projectInvoiceRecipientIds = $_POST['project_invoice_email_client_ids'] ?? [];
if (!is_array($projectInvoiceRecipientIds)) { $projectInvoiceRecipientIds = []; }
$useOrganizationInvoiceEmail = !empty($_POST['project_invoice_use_organization_email']);
$projectInvoiceLinkClientIds = $_POST['project_invoice_link_client_ids'] ?? [];
if (!is_array($projectInvoiceLinkClientIds)) { $projectInvoiceLinkClientIds = []; }
$parent_id = null; // Parent projects not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$department_id = (int)($_POST['department_id'] ?? 0);
$businessUnitId = (int)($_POST['business_unit_id'] ?? 0);
$businessUnitUserSelected = !empty($_POST['business_unit_user_selected']);
$managerUserIdProvided = array_key_exists('manager_user_id', $_POST);
$managerUserId = (int)($_POST['manager_user_id'] ?? 0);
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');
$invoiceBillingPeriod = ($_POST['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'monthly' : 'per_invoice';
$postedOriginalBillingPeriod = in_array((string)($_POST['invoice_billing_period_original'] ?? ''), ['monthly', 'per_invoice'], true)
	? (string)$_POST['invoice_billing_period_original']
	: null;
$billingTransitionStrategy = in_array((string)($_POST['billing_transition_strategy'] ?? ''), ['convert_to_direct', 'final_project_statement'], true)
	? (string)$_POST['billing_transition_strategy']
	: null;
$billingTransitionDeliveryAction = (string)($_POST['delivery_action'] ?? 'review') === 'send_all' ? 'send_all' : 'review';
$monthlyAutoEmailConfirmed = (string)($_POST['monthly_auto_email_confirmed'] ?? '') === '1';
$invoiceNetTermsDays = trim((string)($_POST['invoice_net_terms_days'] ?? ''));
$invoiceNetTermsDays = $invoiceNetTermsDays === '' ? null : max(0, (int)$invoiceNetTermsDays);
$projectInvoiceAutoEmail = !empty($_POST['project_invoice_auto_email']) ? 1 : 0;
$publicProjectEnabled = !empty($_POST['public_project_enabled']) ? 1 : 0;
$publicProjectRequirePassword = !empty($_POST['public_project_require_password']) ? 1 : 0;
$publicProjectPassword = trim((string)($_POST['public_project_password'] ?? ''));
$publicProjectCanViewDocuments = !empty($_POST['public_project_can_view_documents']) ? 1 : 0;
$publicProjectCanViewInvoices = !empty($_POST['public_project_can_view_invoices']) ? 1 : 0;
$publicProjectCanUpload = !empty($_POST['public_project_can_upload']) ? 1 : 0;
$publicProjectCanRequestChanges = !empty($_POST['public_project_can_request_changes']) ? 1 : 0;

require_record_ownership($pdo, 'projects', $id);
pa_project_public_link_ensure_schema($pdo);
$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($billingTransitionDeliveryAction === 'send_all'
	&& ($actorId <= 0 || !user_can($pdo, $actorId, 'invoices.send', 0))) {
	http_response_code(403);
	exit('Forbidden');
}

$projectStmt = $pdo->prepare('SELECT organization_id,business_unit_id,manager_user_id,invoice_billing_period,public_project_token,public_project_password_hash FROM projects WHERE id = ? LIMIT 1');
$projectStmt->execute([$id]);
$storedProject = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$storedOrganizationId = (int)($storedProject['organization_id'] ?? 0);
$storedBusinessUnitId = (int)($storedProject['business_unit_id'] ?? 0);
$storedManagerUserId = (int)($storedProject['manager_user_id'] ?? 0);
$projectInvoiceManualEmails = [];
if (!$managerUserIdProvided) {
	$managerUserId = $storedManagerUserId;
}
$projectPlanning = new \App\Services\ProjectWorkPlanningService();
try {
	$projectPlanning->requireAvailableManager($pdo, $managerUserId);
} catch (DomainException $error) {
	header('Location: '.$editRedirect.'&error=' . urlencode($error->getMessage()));
	exit;
}
if ($storedOrganizationId > 0) {
	$organization_id = $storedOrganizationId;
}
if ($businessUnitId > 0) {
	$unitStmt = $pdo->prepare('SELECT 1 FROM business_units WHERE id=? AND is_active=1');
	$unitStmt->execute([$businessUnitId]);
	if (!$unitStmt->fetchColumn()) {
		header('Location: '.$editRedirect.'&error=' . urlencode('Selected business unit is unavailable.'));
		exit;
	}
} elseif (!$businessUnitUserSelected && $storedBusinessUnitId < 1) {
	$businessUnitId = $projectPlanning->primaryBusinessUnitForUser($pdo, $managerUserId);
	if ($businessUnitId < 1) {
		$businessUnitId = (int)($pdo->query("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='default_business_unit_id' LIMIT 1")->fetchColumn() ?: 0);
	}
	if ($businessUnitId > 0) {
		$defaultUnit=$pdo->prepare('SELECT 1 FROM business_units WHERE id=? AND is_active=1');$defaultUnit->execute([$businessUnitId]);if(!$defaultUnit->fetchColumn())$businessUnitId=0;
	}
	if ($businessUnitId < 1) {
		$activeUnitIds = $pdo->query('SELECT id FROM business_units WHERE is_active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
		$businessUnitId = count($activeUnitIds) === 1 ? (int)$activeUnitIds[0] : 0;
	}
}
if ($department_id > 0) {
	$departmentStmt = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ? LIMIT 1');
	$departmentStmt->execute([$department_id]);
	$departmentOrgId = (int)($departmentStmt->fetchColumn() ?: 0);
	if ($departmentOrgId <= 0 || ($organization_id > 0 && $departmentOrgId !== $organization_id)) {
		header('Location: '.$editRedirect.'&error=' . urlencode('Selected department does not belong to the project organization.'));
		exit;
	}
	$organization_id = $departmentOrgId;
}

$projectClientIds = array_values(array_unique(array_filter(array_map('intval', $projectClientIds), static fn($clientId) => $clientId > 0)));
$projectInvoiceRecipientIds = array_values(array_unique(array_filter(array_map('intval', $projectInvoiceRecipientIds), static fn($clientId) => $clientId > 0)));
if ($useOrganizationInvoiceEmail) {
	$organizationEmailStmt = $pdo->prepare('SELECT general_email FROM organizations WHERE id = ? LIMIT 1');
	$organizationEmailStmt->execute([$organization_id]);
	$organizationEmail = trim((string)($organizationEmailStmt->fetchColumn() ?: ''));
	if ($organization_id <= 0 || !filter_var($organizationEmail, FILTER_VALIDATE_EMAIL)) {
		header('Location: '.$editRedirect.'&error=' . urlencode('The project organization does not have a valid company email.'));
		exit;
	}
}
$projectInvoiceLinkClientIds = array_values(array_unique(array_filter(array_map('intval', $projectInvoiceLinkClientIds), static fn($clientId) => $clientId > 0)));
if ($client_id > 0 && !in_array($client_id, $projectClientIds, true)) {
	header('Location: '.$editRedirect.'&error=' . urlencode('Primary billed contact must remain attached to the project.'));
	exit;
}
$projectInvoiceLinkClientIds = array_values(array_intersect($projectInvoiceLinkClientIds, $projectClientIds));
foreach ($projectInvoiceRecipientIds as $recipientClientId) {
	require_record_ownership($pdo, 'clients', $recipientClientId);
}
if (!project_invoice_recipient_client_ids_in_scope($pdo, $projectInvoiceRecipientIds, $organization_id > 0 ? $organization_id : null)) {
	header('Location: '.$editRedirect.'&error=' . urlencode('Invoice email recipients must be active contacts in the project organization.'));
	exit;
}

if ($organization_id > 0) {
	require_record_ownership($pdo, 'organizations', $organization_id);
	if ($projectClientIds) {
		$placeholders = implode(',', array_fill(0, count($projectClientIds), '?'));
		$clientScope = $pdo->prepare("SELECT id FROM clients WHERE organization_id = ? AND archived = 0 AND id IN ({$placeholders})");
		$clientScope->execute(array_merge([$organization_id], $projectClientIds));
		$validClientIds = array_map('intval', $clientScope->fetchAll(PDO::FETCH_COLUMN));
		sort($validClientIds);
		$expectedClientIds = $projectClientIds;
		sort($expectedClientIds);
		if ($validClientIds !== $expectedClientIds) {
			header('Location: '.$editRedirect.'&error=' . urlencode('Project contacts must belong to the project organization.'));
			exit;
		}
	}
} else {
	foreach ($projectClientIds as $attachedClientId) {
		require_record_ownership($pdo, 'clients', $attachedClientId);
	}
}

$recipientConfigIsDeliverable = !empty($_POST['project_invoice_recipients_present'])
	? project_invoice_has_deliverable_recipient_config(
		$pdo,
		$projectInvoiceRecipientIds,
		$projectInvoiceManualEmails,
		$useOrganizationInvoiceEmail ? [$organization_id] : []
	)
	: project_invoice_has_saved_deliverable_recipient($pdo, $id);
if ($projectInvoiceAutoEmail && !$recipientConfigIsDeliverable) {
	header('Location: '.$editRedirect.'&error=' . urlencode('Automatic project invoice email requires at least one valid recipient.'));
	exit;
}

// Validate dates
if ($estimated_start !== '' && $estimated_end !== '' && strtotime($estimated_start) > strtotime($estimated_end)) {
	header('Location: '.$editRedirect.'&error=Start%20must%20be%20before%20end');
	exit;
}
if ($publicProjectRequirePassword && $publicProjectPassword === '' && trim((string)($storedProject['public_project_password_hash'] ?? '')) === '') {
	header('Location: '.$editRedirect.'&error=' . urlencode('Enter an access code before requiring one for the public project link.'));
	exit;
}

$hasAutoEmailColumn = project_invoice_table_has_column($pdo, 'projects', 'project_invoice_auto_email');
$hasDepartmentColumn = project_invoice_table_has_column($pdo, 'projects', 'department_id');
$portalProjection = new \App\Services\PortalProjectionMutationService();
$billingTransitionResult = null;
$pdo->beginTransaction();
try {
$portalBeforeScopes = $portalProjection->lockedProjectScopes(
	$pdo,
	$id,
	$client_id > 0 ? $client_id : null,
	$organization_id > 0 ? $organization_id : null,
	$department_id > 0 ? $department_id : null
);
$lockedModeStmt = $pdo->prepare('SELECT invoice_billing_period FROM projects WHERE id=?');
$lockedModeStmt->execute([$id]);
$lockedInvoiceBillingPeriod = (string)($lockedModeStmt->fetchColumn() ?: 'per_invoice');
$lockedInvoiceBillingPeriod = $lockedInvoiceBillingPeriod === 'monthly' ? 'monthly' : 'per_invoice';
if ($postedOriginalBillingPeriod === null && $invoiceBillingPeriod !== $lockedInvoiceBillingPeriod) {
	throw new DomainException('Reload Project billing settings before changing the billing period.');
}
if ($postedOriginalBillingPeriod !== null && $postedOriginalBillingPeriod !== $lockedInvoiceBillingPeriod) {
	throw new DomainException('Project billing settings changed in another session. Reload before saving.');
}
if ($lockedInvoiceBillingPeriod === 'per_invoice'
	&& $invoiceBillingPeriod === 'monthly'
	&& !$monthlyAutoEmailConfirmed) {
	throw new DomainException('Confirm the monthly automatic-email preference before enabling monthly billing.');
}
if ($hasAutoEmailColumn) {
	$departmentSet = $hasDepartmentColumn ? 'department_id=?,' : '';
	$stmt = $pdo->prepare("UPDATE projects SET name=?, client_id=?, organization_id=?, {$departmentSet} business_unit_id=?, manager_user_id=?, invoice_billing_period=?, invoice_net_terms_days=?, project_invoice_auto_email=?, estimated_start=?, estimated_end=?, notes=?, source_version=?, updated_at=NOW() WHERE id=?");
	$params = [
		$name,
		$client_id > 0 ? $client_id : null,
		$organization_id > 0 ? $organization_id : null,
	];
	if ($hasDepartmentColumn) {
		$params[] = $department_id > 0 ? $department_id : null;
	}
	$params[] = $businessUnitId > 0 ? $businessUnitId : null;
	$params[] = $managerUserId > 0 ? $managerUserId : null;
	$params = array_merge($params, [
		$invoiceBillingPeriod,
		$invoiceNetTermsDays,
		$projectInvoiceAutoEmail,
		$estimated_start !== '' ? $estimated_start : null,
		$estimated_end !== '' ? $estimated_end : null,
		$notes ?: null,
		portal_projection_source_version(),
		$id
	]);
	$stmt->execute($params);
} else {
	$departmentSet = $hasDepartmentColumn ? 'department_id=?,' : '';
	$stmt = $pdo->prepare("UPDATE projects SET name=?, client_id=?, organization_id=?, {$departmentSet} business_unit_id=?, manager_user_id=?, invoice_billing_period=?, invoice_net_terms_days=?, estimated_start=?, estimated_end=?, notes=?, source_version=?, updated_at=NOW() WHERE id=?");
	$params = [
		$name,
		$client_id > 0 ? $client_id : null,
		$organization_id > 0 ? $organization_id : null,
	];
	if ($hasDepartmentColumn) {
		$params[] = $department_id > 0 ? $department_id : null;
	}
	$params[] = $businessUnitId > 0 ? $businessUnitId : null;
	$params[] = $managerUserId > 0 ? $managerUserId : null;
	$params = array_merge($params, [
		$invoiceBillingPeriod,
		$invoiceNetTermsDays,
		$estimated_start !== '' ? $estimated_start : null,
		$estimated_end !== '' ? $estimated_end : null,
		$notes ?: null,
		portal_projection_source_version(),
		$id
	]);
	$stmt->execute($params);
}
$managerAssignmentId = 0;
$managerAssignmentChanged = false;
if ($managerUserId > 0) {
	$managerAssignmentId = $projectPlanning->addTeamMember($pdo, $id, $managerUserId, (int)($_SESSION['user']['id'] ?? 0), $managerAssignmentChanged);
}
$pdo->prepare('UPDATE operations SET business_unit_id=? WHERE project_id=?')->execute([$businessUnitId ?: null, $id]);
$pdo->prepare('UPDATE tasks SET business_unit_id=? WHERE project_id=?')->execute([$businessUnitId ?: null, $id]);
project_invoice_sync_clients(
	$pdo,
	$id,
	$client_id > 0 ? $client_id : null,
	$projectClientIds,
	array_values(array_intersect($projectInvoiceRecipientIds, $projectClientIds)),
	$projectInvoiceLinkClientIds
);
if (!empty($_POST['project_invoice_recipients_present'])) {
	project_invoice_sync_recipients(
		$pdo,
		$id,
		$projectInvoiceRecipientIds,
		$projectInvoiceManualEmails,
		$useOrganizationInvoiceEmail ? [$organization_id] : []
	);
}
$shouldRunBillingTransition = $lockedInvoiceBillingPeriod !== $invoiceBillingPeriod
	|| ($lockedInvoiceBillingPeriod === 'per_invoice'
		&& $invoiceBillingPeriod === 'per_invoice'
		&& $billingTransitionStrategy !== null);
if ($shouldRunBillingTransition) {
	$billingTransitionResult = project_billing_mode_transition(
		$pdo,
		$id,
		$lockedInvoiceBillingPeriod,
		$invoiceBillingPeriod,
		$billingTransitionStrategy,
		$billingTransitionDeliveryAction,
		$appConfig,
		$actorId ?: null,
		date('Y-m-d')
	);
}
$serviceLocationIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['service_location_ids'] ?? [])))));
$defaultServiceLocationId = (int)($_POST['default_service_location_id'] ?? 0);
if ($defaultServiceLocationId > 0 && !in_array($defaultServiceLocationId, $serviceLocationIds, true)) $serviceLocationIds[] = $defaultServiceLocationId;
$pdo->prepare('DELETE FROM project_service_locations WHERE project_id=?')->execute([$id]);
foreach ($serviceLocationIds as $locationId) {
	$validLocation=$pdo->prepare('SELECT id FROM service_locations WHERE id=? AND archived=0');$validLocation->execute([$locationId]);
	if ($validLocation->fetchColumn()) $pdo->prepare('INSERT INTO project_service_locations (project_id,service_location_id,is_default) VALUES (?,?,?)')->execute([$id,$locationId,$locationId===$defaultServiceLocationId?1:0]);
}

$publicProjectToken = trim((string)($storedProject['public_project_token'] ?? ''));
if ($publicProjectEnabled && $publicProjectToken === '') {
	$publicProjectToken = pa_project_public_token();
}
$publicProjectPasswordHash = trim((string)($storedProject['public_project_password_hash'] ?? ''));
if ($publicProjectPassword !== '') {
	$publicProjectPasswordHash = password_hash($publicProjectPassword, PASSWORD_DEFAULT);
}
$publicStmt = $pdo->prepare('
	UPDATE projects
	SET public_project_enabled = ?,
	    public_project_token = ?,
	    public_project_require_password = ?,
	    public_project_password_hash = ?,
	    public_project_can_view_documents = ?,
	    public_project_can_view_invoices = ?,
	    public_project_can_upload = ?,
	    public_project_can_request_changes = ?
	WHERE id = ?
');
$publicStmt->execute([
	$publicProjectEnabled,
	$publicProjectToken !== '' ? $publicProjectToken : null,
	$publicProjectRequirePassword,
	$publicProjectPasswordHash !== '' ? $publicProjectPasswordHash : null,
	$publicProjectCanViewDocuments,
	$publicProjectCanViewInvoices,
	$publicProjectCanUpload,
	$publicProjectCanRequestChanges,
	$id,
]);
ScheduleService::syncProject($pdo, $id, (string)($appConfig['timezone'] ?? 'UTC'), (int)($_SESSION['user']['id']??0));
$opsConfig=pa_external_ops_delivery_config($pdo);
if(!empty($opsConfig['enabled'])){
	$integration = new \App\Services\ExternalOpsIntegrationService();
	$projectEvent=$pdo->prepare('SELECT * FROM projects WHERE id=?');
	$projectEvent->execute([$id]);
	$integration->enqueueProjectionChange($pdo,(string)$opsConfig['application_key'],'project',$id,'upsert',$projectEvent->fetch(PDO::FETCH_ASSOC)?:[]);
	if ($managerAssignmentId > 0 && $managerAssignmentChanged) {
		$assignmentEvent=$pdo->prepare('SELECT *,1 active FROM project_assignments WHERE id=?');
		$assignmentEvent->execute([$managerAssignmentId]);
		$integration->enqueueProjectionChange($pdo,(string)$opsConfig['application_key'],'project_assignment',$managerAssignmentId,'upsert',$assignmentEvent->fetch(PDO::FETCH_ASSOC)?:[]);
	}
}
if($storedBusinessUnitId!==$businessUnitId)audit_log($pdo,'project.business_unit.changed','project',$id,['from'=>$storedBusinessUnitId?:null,'to'=>$businessUnitId?:null]);
if($storedManagerUserId!==$managerUserId)audit_log($pdo,'project.manager.changed','project',$id,['from'=>$storedManagerUserId?:null,'to'=>$managerUserId?:null]);
(new \App\Services\ProjectRevisionService($pdo))->advance($id, 'update', null, null, $actorId ?: null);
$portalProjection->afterMutation($pdo, array_merge($portalBeforeScopes, $portalProjection->projectScopes($pdo, $id)));
(new \App\Services\PortalServiceAssignmentManager())->reconcileRoots(
	$pdo,
	array_merge($portalBeforeScopes, $portalProjection->projectScopes($pdo, $id))
);
$pdo->commit();
} catch (DomainException $error) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	header('Location: '.$editRedirect.'&error=' . urlencode($error->getMessage()) . '#project-billing');
	exit;
} catch (Throwable $error) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	throw $error;
}
$redirect = $detailsRedirect . '&updated=1';
if (is_array($billingTransitionResult)
	&& (!empty($billingTransitionResult['changed']) || (int)($billingTransitionResult['candidate_count'] ?? 0) > 0)) {
	$deliveryResult = project_billing_transition_deliver($pdo, $billingTransitionResult, $appConfig);
	$billingTransitionResult['delivery_results'] = $deliveryResult;
	$message = (string)($billingTransitionResult['message'] ?? 'Project billing was updated.');
	if ((int)($deliveryResult['sent'] ?? 0) > 0) {
		$message .= ' ' . (int)$deliveryResult['sent'] . ' email(s) were sent.';
	}
	if ((int)($deliveryResult['failed'] ?? 0) > 0) {
		$message .= ' ' . (string)($deliveryResult['message'] ?? 'Some email delivery attempts need attention.');
	}
	$redirect .= '&billing_transition=success&transition_message=' . urlencode($message);
}
header('Location: '.$redirect);
exit;
