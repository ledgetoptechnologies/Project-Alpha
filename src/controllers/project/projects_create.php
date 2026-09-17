<?php
// src/controllers/project/projects_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../utils/external_ops.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../services/ProjectRevisionService.php';

$__orgId = request_client_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-create');

$name = trim($_POST['name'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$projectClientIds = $_POST['project_client_ids'] ?? [];
if (!is_array($projectClientIds)) { $projectClientIds = []; }
$projectInvoiceRecipientIds = $_POST['project_invoice_email_client_ids'] ?? [];
if ($projectInvoiceRecipientIds !== null && !is_array($projectInvoiceRecipientIds)) { $projectInvoiceRecipientIds = []; }
$useOrganizationInvoiceEmail = !empty($_POST['project_invoice_use_organization_email']);
$projectInvoiceLinkClientIds = $_POST['project_invoice_link_client_ids'] ?? null;
if ($projectInvoiceLinkClientIds !== null && !is_array($projectInvoiceLinkClientIds)) { $projectInvoiceLinkClientIds = []; }
$parent_id = null; // Parent projects are not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$department_id = (int)($_POST['department_id'] ?? 0);
$businessUnitId = (int)($_POST['business_unit_id'] ?? 0);
$businessUnitUserSelected = !empty($_POST['business_unit_user_selected']);
$managerUserId = array_key_exists('manager_user_id', $_POST) ? (int)$_POST['manager_user_id'] : (int)$__creator;
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$invoiceBillingPeriod = ($_POST['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'monthly' : 'per_invoice';
$invoiceNetTermsDays = trim((string)($_POST['invoice_net_terms_days'] ?? ''));
$invoiceNetTermsDays = $invoiceNetTermsDays === '' ? null : max(0, (int)$invoiceNetTermsDays);
$projectInvoiceAutoEmail = !empty($_POST['project_invoice_auto_email']) ? 1 : 0;

if ($name === '') { header('Location: /?page=project/projects-list&error=Name%20required'); exit; }

$projectPlanning = new \App\Services\ProjectWorkPlanningService();
try {
	$projectPlanning->requireAvailableManager($pdo, $managerUserId);
} catch (DomainException $error) {
	header('Location: /?page=project/projects-create&error=' . urlencode($error->getMessage()));
	exit;
}

if ($organization_id <= 0 && $__orgId !== null) {
	$organization_id = (int)$__orgId;
}
if ($organization_id > 0) {
	require_record_ownership($pdo, 'organizations', $organization_id);
}
if ($useOrganizationInvoiceEmail) {
	$organizationEmailStmt = $pdo->prepare('SELECT general_email FROM organizations WHERE id = ? LIMIT 1');
	$organizationEmailStmt->execute([$organization_id]);
	$organizationEmail = trim((string)($organizationEmailStmt->fetchColumn() ?: ''));
	if ($organization_id <= 0 || !filter_var($organizationEmail, FILTER_VALIDATE_EMAIL)) {
		header('Location: /?page=project/projects-create&error=' . urlencode('The selected organization does not have a valid company email.'));
		exit;
	}
}

if ($businessUnitId > 0) {
	$unitStmt = $pdo->prepare('SELECT 1 FROM business_units WHERE id=? AND is_active=1');
	$unitStmt->execute([$businessUnitId]);
	if (!$unitStmt->fetchColumn()) {
		header('Location: /?page=project/projects-create&error=' . urlencode('Selected business unit is unavailable.'));
		exit;
	}
} elseif (!$businessUnitUserSelected) {
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
		header('Location: /?page=project/projects-create&error=' . urlencode('Selected department does not belong to the selected organization.'));
		exit;
	}
	$organization_id = $departmentOrgId;
}
if ($organization_id > 0) {
	require_record_ownership($pdo, 'organizations', $organization_id);
}

$projectClientIds = array_values(array_unique(array_filter(array_map('intval', $projectClientIds), static fn($id) => $id > 0)));
$projectInvoiceRecipientIds = $projectInvoiceRecipientIds === null
	? null
	: array_values(array_unique(array_filter(array_map('intval', $projectInvoiceRecipientIds), static fn($id) => $id > 0)));
$projectInvoiceManualEmails = [];
$projectInvoiceLinkClientIds = $projectInvoiceLinkClientIds === null
	? null
	: array_values(array_unique(array_filter(array_map('intval', $projectInvoiceLinkClientIds), static fn($id) => $id > 0)));
$projectClientIds = array_values(array_unique(array_merge(
	$projectClientIds,
	$projectInvoiceLinkClientIds ?? []
)));
if ($client_id > 0 && !in_array($client_id, $projectClientIds, true)) {
	$projectClientIds[] = $client_id;
}
if ($organization_id > 0 && $projectClientIds) {
	$placeholders = implode(',', array_fill(0, count($projectClientIds), '?'));
	$clientScope = $pdo->prepare("SELECT id FROM clients WHERE organization_id = ? AND archived = 0 AND id IN ({$placeholders})");
	$clientScope->execute(array_merge([$organization_id], $projectClientIds));
	$validClientIds = array_map('intval', $clientScope->fetchAll(PDO::FETCH_COLUMN));
	sort($validClientIds);
	$expectedClientIds = $projectClientIds;
	sort($expectedClientIds);
	if ($validClientIds !== $expectedClientIds) {
		header('Location: /?page=project/projects-create&error=' . urlencode('Project contacts must belong to the selected organization.'));
		exit;
	}
} elseif ($projectClientIds) {
	foreach ($projectClientIds as $attachedClientId) {
		require_record_ownership($pdo, 'clients', $attachedClientId);
	}
}
$projectInvoiceRecipientIds = $projectInvoiceRecipientIds ?? [];
foreach ($projectInvoiceRecipientIds as $recipientClientId) {
	require_record_ownership($pdo, 'clients', $recipientClientId);
}
if (!project_invoice_recipient_client_ids_in_scope($pdo, $projectInvoiceRecipientIds, $organization_id > 0 ? $organization_id : null)) {
	header('Location: /?page=project/projects-create&error=' . urlencode('Invoice email recipients must be active contacts in the selected organization.'));
	exit;
}
$projectInvoiceLinkClientIds = $projectInvoiceLinkClientIds === null ? null : array_values(array_intersect($projectInvoiceLinkClientIds, $projectClientIds));
if ($projectInvoiceAutoEmail && !project_invoice_has_deliverable_recipient_config(
	$pdo,
	$projectInvoiceRecipientIds,
	$projectInvoiceManualEmails,
	$useOrganizationInvoiceEmail ? [$organization_id] : []
)) {
	header('Location: /?page=project/projects-create&error=' . urlencode('Automatic project invoice email requires at least one valid recipient.'));
	exit;
}

// Validate date window if set
if ($estimated_start !== '' && $estimated_end !== '') {
	if (strtotime($estimated_start) > strtotime($estimated_end)) {
		header('Location: /?page=project/projects-create&error=Start%20must%20be%20before%20end'); exit;
	}
}

$hasAutoEmailColumn = project_invoice_table_has_column($pdo, 'projects', 'project_invoice_auto_email');
$hasDepartmentColumn = project_invoice_table_has_column($pdo, 'projects', 'department_id');
$pdo->beginTransaction();
try {
if ($hasAutoEmailColumn) {
	$departmentColumn = $hasDepartmentColumn ? ', department_id' : '';
	$departmentValue = $hasDepartmentColumn ? ', ?' : '';
	$ins = $pdo->prepare("INSERT INTO projects (name, client_id, organization_id{$departmentColumn}, business_unit_id, manager_user_id, invoice_billing_period, invoice_net_terms_days, project_invoice_auto_email, estimated_start, estimated_end, notes, source_version, created_by, created_at) VALUES (?,?,?{$departmentValue},?,?,?,?,?,?,?,?,?,?,NOW())");
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
		$__creator
	]);
	$ins->execute($params);
} else {
	$departmentColumn = $hasDepartmentColumn ? ', department_id' : '';
	$departmentValue = $hasDepartmentColumn ? ', ?' : '';
	$ins = $pdo->prepare("INSERT INTO projects (name, client_id, organization_id{$departmentColumn}, business_unit_id, manager_user_id, invoice_billing_period, invoice_net_terms_days, estimated_start, estimated_end, notes, source_version, created_by, created_at) VALUES (?,?,?{$departmentValue},?,?,?,?,?,?,?,?,?,NOW())");
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
		$__creator
	]);
	$ins->execute($params);
}

$project_id = (int)$pdo->lastInsertId();
$managerAssignmentId = 0;
$managerAssignmentChanged = false;
if ($managerUserId > 0) {
	$managerAssignmentId = $projectPlanning->addTeamMember($pdo, $project_id, $managerUserId, (int)$__creator, $managerAssignmentChanged);
}
$serviceLocationIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['service_location_ids'] ?? [])))));
$defaultServiceLocationId = (int)($_POST['default_service_location_id'] ?? 0);
if ($defaultServiceLocationId > 0 && !in_array($defaultServiceLocationId, $serviceLocationIds, true)) $serviceLocationIds[] = $defaultServiceLocationId;
foreach ($serviceLocationIds as $locationId) {
	$validLocation=$pdo->prepare('SELECT id FROM service_locations WHERE id=? AND archived=0');$validLocation->execute([$locationId]);
	if ($validLocation->fetchColumn()) $pdo->prepare('INSERT INTO project_service_locations (project_id,service_location_id,is_default) VALUES (?,?,?)')->execute([$project_id,$locationId,$locationId===$defaultServiceLocationId?1:0]);
}
project_invoice_sync_clients(
	$pdo,
	$project_id,
	$client_id > 0 ? $client_id : null,
	$projectClientIds,
	array_values(array_intersect($projectInvoiceRecipientIds, $projectClientIds)),
	$projectInvoiceLinkClientIds
);
project_invoice_sync_recipients(
	$pdo,
	$project_id,
	$projectInvoiceRecipientIds,
	$projectInvoiceManualEmails,
	$useOrganizationInvoiceEmail ? [$organization_id] : []
);
audit_log($pdo, 'project.create', 'project', $project_id, ['client_id' => $client_id > 0 ? $client_id : null, 'organization_id' => $organization_id > 0 ? $organization_id : null, 'department_id' => $department_id > 0 ? $department_id : null, 'business_unit_id' => $businessUnitId > 0 ? $businessUnitId : null, 'manager_user_id' => $managerUserId > 0 ? $managerUserId : null, 'created_by' => $__creator]);
(new \App\Services\ProjectRevisionService($pdo))->initialize($project_id, 'create', null, null, $__creator);
ScheduleService::syncProject($pdo, $project_id, (string)($appConfig['timezone'] ?? 'UTC'), $__creator);
$opsConfig=pa_external_ops_delivery_config($pdo);
if(!empty($opsConfig['enabled'])){
	$integration = new \App\Services\ExternalOpsIntegrationService();
	$projectEvent=$pdo->prepare('SELECT * FROM projects WHERE id=?');
	$projectEvent->execute([$project_id]);
	$integration->enqueueProjectionChange($pdo,(string)$opsConfig['application_key'],'project',$project_id,'upsert',$projectEvent->fetch(PDO::FETCH_ASSOC)?:[]);
	if ($managerAssignmentId > 0 && $managerAssignmentChanged) {
		$assignmentEvent=$pdo->prepare('SELECT *,1 active FROM project_assignments WHERE id=?');
		$assignmentEvent->execute([$managerAssignmentId]);
		$integration->enqueueProjectionChange($pdo,(string)$opsConfig['application_key'],'project_assignment',$managerAssignmentId,'upsert',$assignmentEvent->fetch(PDO::FETCH_ASSOC)?:[]);
	}
}
(new \App\Services\PortalProjectionMutationService())->queueProject($pdo, $project_id);
$pdo->commit();
} catch (Throwable $error) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	throw $error;
}
header('Location: /?page=project/projects-details&id=' . $project_id . '&created=1');
exit;
