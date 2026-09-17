<?php
// src/views/pages/project/projects-details.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../../services/ProjectReceivablesSummaryService.php';
require_once __DIR__ . '/../../../utils/project_files.php';
require_once __DIR__ . '/../../../utils/public_project_links.php';
require_once __DIR__ . '/../../../config/app.php';

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) {
    header('Location: /?page=project/projects-list');
    exit;
}
require_record_ownership($pdo, 'projects', $projectId);
pa_project_public_link_ensure_schema($pdo);

// Fetch project details
$stmt = $pdo->prepare('
    SELECT p.*, c.name AS client_name, o.name AS organization_name, od.name AS department_name,
           bu.name AS business_unit_name,
           COALESCE(NULLIF(manager_wp.display_name,\'\'),NULLIF(manager_tm.display_name,\'\'),NULLIF(manager_u.username,\'\'),manager_u.email) AS manager_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN organizations o ON o.id = p.organization_id
    LEFT JOIN organization_departments od ON od.id = p.department_id
    LEFT JOIN business_units bu ON bu.id = p.business_unit_id
    LEFT JOIN users manager_u ON manager_u.id=p.manager_user_id
    LEFT JOIN worker_profiles manager_wp ON manager_wp.user_id=manager_u.id
    LEFT JOIN team_members manager_tm ON manager_tm.user_id=manager_u.id
    WHERE p.id = ?
');
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    header('Location: /?page=project/projects-list');
    exit;
}

$projectTeam = [];
$activeProjectTeam = [];
$planningUsers = [];
$projectOperations = [];
$operationAssignees = [];
$projectTasks = [];
$taskAssignees = [];
$planningLoadError = '';
try {
    $projectTeamStmt = $pdo->prepare('SELECT pa.user_id,pa.assigned_at,pa.ends_at,u.username,u.email,COALESCE(NULLIF(wp.display_name,\'\'),NULLIF(tm.display_name,\'\'),NULLIF(u.username,\'\'),u.email) AS display_name,COALESCE(NULLIF(wp.display_name,\'\'),NULLIF(tm.display_name,\'\'),NULLIF(u.username,\'\'),u.email) AS name FROM project_assignments pa JOIN users u ON u.id=pa.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE pa.project_id=? ORDER BY (pa.ends_at IS NOT NULL),name');
    $projectTeamStmt->execute([$projectId]);
    $projectTeam = $projectTeamStmt->fetchAll(PDO::FETCH_ASSOC);
    $activeProjectTeam = array_values(array_filter($projectTeam, static fn(array $row): bool => empty($row['ends_at']) || strtotime((string)$row['ends_at']) > time()));
    $planningUsers = $pdo->query('SELECT u.id,COALESCE(NULLIF(wp.display_name,\'\'),NULLIF(tm.display_name,\'\'),NULLIF(u.username,\'\'),u.email) AS name FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE u.is_disabled=0 AND u.deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $projectOperationsStmt = $pdo->prepare('SELECT * FROM operations WHERE project_id=? ORDER BY scheduled_start_at IS NULL,scheduled_start_at,id');
    $projectOperationsStmt->execute([$projectId]);
    $projectOperations = $projectOperationsStmt->fetchAll(PDO::FETCH_ASSOC);
    $operationAssigneesStmt = $pdo->prepare('SELECT oa.operation_id,oa.user_id,COALESCE(NULLIF(wp.display_name,\'\'),NULLIF(tm.display_name,\'\'),NULLIF(u.username,\'\'),u.email) AS name FROM operation_assignments oa JOIN users u ON u.id=oa.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id JOIN operations o ON o.id=oa.operation_id WHERE o.project_id=? ORDER BY name');
    $operationAssigneesStmt->execute([$projectId]);
    foreach ($operationAssigneesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $operationAssignees[(int)$row['operation_id']][] = $row;
    $projectTasksStmt = $pdo->prepare('SELECT t.*,o.title AS operation_title FROM tasks t LEFT JOIN operations o ON o.id=t.operation_id WHERE t.project_id=? ORDER BY t.due_at IS NULL,t.due_at,t.id');
    $projectTasksStmt->execute([$projectId]);
    $projectTasks = $projectTasksStmt->fetchAll(PDO::FETCH_ASSOC);
    $taskAssigneesStmt = $pdo->prepare('SELECT ta.task_id,ta.user_id,COALESCE(NULLIF(wp.display_name,\'\'),NULLIF(tm.display_name,\'\'),NULLIF(u.username,\'\'),u.email) AS name FROM task_assignments ta JOIN users u ON u.id=ta.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id JOIN tasks t ON t.id=ta.task_id WHERE t.project_id=? ORDER BY name');
    $taskAssigneesStmt->execute([$projectId]);
    foreach ($taskAssigneesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $taskAssignees[(int)$row['task_id']][] = $row;
} catch (Throwable $error) {
    $planningLoadError = 'Team and work planning is temporarily unavailable. The rest of the Project is still available.';
    error_log('[projects-details] planning query failed: ' . $error->getMessage());
}
$editOperationId=max(0,(int)($_GET['operation_id']??0));$editTaskId=max(0,(int)($_GET['task_id']??0));$editOperation=null;$editTask=null;
foreach($projectOperations as $row)if((int)$row['id']===$editOperationId)$editOperation=$row;
foreach($projectTasks as $row)if((int)$row['id']===$editTaskId)$editTask=$row;
$canManageProjectWork = user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'projects.edit', 0) && user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'workforce.assignments.manage', 0);
$canEditProjectStatus = user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'projects.edit', 0);

// Fetch associated quotes
$stmt = $pdo->prepare('SELECT id, doc_number, status, total, created_at FROM quotes WHERE project_id = ? ORDER BY created_at DESC');
$stmt->execute([$projectId]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch associated contracts
$stmt = $pdo->prepare('SELECT id, doc_number, status, contract_type, total, created_at FROM contracts WHERE project_id = ? ORDER BY created_at DESC');
$stmt->execute([$projectId]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch associated invoices
$stmt = $pdo->prepare('
    SELECT i.id, i.doc_number, i.invoice_type, i.status, i.collection_mode, i.total,
           i.amount_paid, i.balance_due, i.finalized_at, i.created_at,
           COALESCE((
               SELECT SUM(
                   CASE
                       WHEN pay.amount - COALESCE(pay.refunded_amount, 0) - COALESCE(pay.disputed_amount, 0) > 0
                       THEN pay.amount - COALESCE(pay.refunded_amount, 0) - COALESCE(pay.disputed_amount, 0)
                       ELSE 0
                   END
               )
               FROM payments pay
               WHERE pay.invoice_id = i.id AND pay.status = "succeeded"
           ), 0) AS effective_amount_paid,
           pii.project_invoice_id AS assigned_project_invoice_id,
           assigned_pi.doc_number AS assigned_project_invoice_number
    FROM invoices i
    LEFT JOIN project_invoice_items pii ON pii.invoice_id = i.id
    LEFT JOIN project_invoices assigned_pi ON assigned_pi.id = pii.project_invoice_id
    WHERE i.project_id = ?
    ORDER BY i.created_at DESC
');
$stmt->execute([$projectId]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceStats = [
    'paid_count' => 0,
    'unpaid_count' => 0,
    'paid_total' => 0.0,
    'unpaid_total' => 0.0,
];
$unresolvedMonthlyInvoices = ['count' => 0, 'balance' => 0.0];
$pendingMonthlyReady = ['count' => 0, 'balance' => 0.0];
$pendingMonthlyDrafts = ['count' => 0, 'balance' => 0.0];
foreach ($invoices as $invoice) {
    $total = (float)($invoice['total'] ?? 0);
    $balanceDue = max(0.0, $total - (float)($invoice['effective_amount_paid'] ?? 0));
    if (($invoice['status'] ?? '') === 'paid') {
        $invoiceStats['paid_count']++;
        $invoiceStats['paid_total'] += $total;
    } elseif (!in_array(($invoice['status'] ?? ''), ['void', 'cancelled'], true)) {
        $invoiceStats['unpaid_count']++;
        $invoiceStats['unpaid_total'] += $balanceDue;
    }
    if (($invoice['collection_mode'] ?? 'direct') === 'project_aggregate'
        && empty($invoice['assigned_project_invoice_id'])
        && !in_array(strtolower((string)($invoice['status'] ?? '')), ['paid', 'void', 'cancelled'], true)
        && $balanceDue > 0.005) {
        $unresolvedMonthlyInvoices['count']++;
        $unresolvedMonthlyInvoices['balance'] += $balanceDue;
        if (!empty($invoice['finalized_at']) && in_array(strtolower((string)($invoice['status'] ?? '')), ['sent', 'unpaid', 'partial', 'overdue'], true)) {
            $pendingMonthlyReady['count']++;
            $pendingMonthlyReady['balance'] += $balanceDue;
        } elseif (strtolower((string)($invoice['status'] ?? '')) === 'draft') {
            $pendingMonthlyDrafts['count']++;
            $pendingMonthlyDrafts['balance'] += $balanceDue;
        }
    }
}

$stmt = $pdo->prepare('
    SELECT pi.*, COUNT(pii.id) AS child_count
    FROM project_invoices pi
    LEFT JOIN project_invoice_items pii ON pii.project_invoice_id = pi.id
    WHERE pi.project_id = ?
    GROUP BY pi.id
    ORDER BY pi.billing_period_end DESC, pi.id DESC
');
$stmt->execute([$projectId]);
$projectInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$projectClientsSendSelect = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices')
    ? 'pc.send_project_invoices'
    : '1 AS send_project_invoices';
$projectClientsLinkSelect = project_invoice_table_has_column($pdo, 'project_clients', 'can_view_invoice_links')
    ? 'pc.can_view_invoice_links'
    : '1 AS can_view_invoice_links';
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.email, pc.is_primary_billing, {$projectClientsSendSelect}, {$projectClientsLinkSelect}
    FROM project_clients pc
    JOIN clients c ON c.id = pc.client_id
    WHERE pc.project_id = ?
    ORDER BY pc.is_primary_billing DESC, pc.sort_order ASC, c.name ASC
");
$stmt->execute([$projectId]);
$projectClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$projectOrganizationId = (int)($project['organization_id'] ?? 0);
$projectDepartments = [];
if ($projectOrganizationId > 0) {
    $deptStmt = $pdo->prepare('
        SELECT id, name, folder_name
        FROM organization_departments
        WHERE organization_id = ?
        ORDER BY name
    ');
    $deptStmt->execute([$projectOrganizationId]);
    $projectDepartments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($projectOrganizationId > 0) {
    $allClientsStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id = ? AND archived = 0
        ORDER BY name
    ');
    $allClientsStmt->execute([$projectOrganizationId]);
    $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $activeOrgId = request_client_org_id();
    if ($activeOrgId > 0) {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([$activeOrgId]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (($_SESSION['user']['role'] ?? '') === 'admin') {
        $allClients = $pdo->query('SELECT id, name, email FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id IS NULL AND created_by = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([(int)($_SESSION['user']['id'] ?? 0)]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$selectedProjectClientIds = array_map(static fn($row) => (int)$row['id'], $projectClients);
$selectedProjectInvoiceRecipientIds = array_map(
    static fn($row) => (int)$row['id'],
    array_values(array_filter($projectClients, static fn($row) => !empty($row['send_project_invoices'])))
);
$selectedProjectInvoiceLinkClientIds = array_map(
    static fn($row) => (int)$row['id'],
    array_values(array_filter($projectClients, static fn($row) => !empty($row['can_view_invoice_links'])))
);
$projectDepartmentContactIds = [];
if (!empty($project['department_id'])) {
    $deptContactStmt = $pdo->prepare('SELECT client_id FROM organization_department_contacts WHERE department_id = ?');
    $deptContactStmt->execute([(int)$project['department_id']]);
    $projectDepartmentContactIds = array_map('intval', $deptContactStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($projectDepartmentContactIds) {
        usort($allClients, static function (array $a, array $b) use ($projectDepartmentContactIds): int {
            $aDept = in_array((int)$a['id'], $projectDepartmentContactIds, true) ? 0 : 1;
            $bDept = in_array((int)$b['id'], $projectDepartmentContactIds, true) ? 0 : 1;
            if ($aDept !== $bDept) {
                return $aDept <=> $bDept;
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }
}
$projectSettingsClients = [];
$projectSettingsClientIds = [];
foreach ($allClients as $client) {
    $clientId = (int)$client['id'];
    $projectSettingsClientIds[$clientId] = true;
    $projectSettingsClients[] = [
        'id' => $clientId,
        'name' => (string)($client['name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'is_selected' => in_array($clientId, $selectedProjectClientIds, true) ? 1 : 0,
        'is_invoice_recipient' => in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 1 : 0,
        'can_view_links' => in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 1 : 0,
        'is_primary' => (int)($project['client_id'] ?? 0) === $clientId ? 1 : 0,
        'is_department_contact' => in_array($clientId, $projectDepartmentContactIds, true) ? 1 : 0,
    ];
}
foreach ($projectClients as $client) {
    $clientId = (int)$client['id'];
    if (isset($projectSettingsClientIds[$clientId])) {
        continue;
    }
    $projectSettingsClients[] = [
        'id' => $clientId,
        'name' => (string)($client['name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'is_selected' => 1,
        'is_invoice_recipient' => in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 1 : 0,
        'can_view_links' => in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 1 : 0,
        'is_primary' => (int)($project['client_id'] ?? 0) === $clientId ? 1 : 0,
        'is_department_contact' => in_array($clientId, $projectDepartmentContactIds, true) ? 1 : 0,
    ];
}

$projectFileFolders = [];
$projectFiles = [];
$projectPublicEvents = [];
try {
    $folderStmt = $pdo->prepare('
        SELECT f.*, COUNT(pf.id) AS file_count
        FROM project_file_folders f
        LEFT JOIN project_files pf ON pf.folder_id = f.id
        WHERE f.project_id = ?
        GROUP BY f.id
        ORDER BY f.name ASC
    ');
    $folderStmt->execute([$projectId]);
    $projectFileFolders = $folderStmt->fetchAll(PDO::FETCH_ASSOC);

    $filesStmt = $pdo->prepare('
        SELECT pf.*, u.email AS uploaded_by_email, u.username AS uploaded_by_username
        FROM project_files pf
        LEFT JOIN users u ON u.id = pf.uploaded_by
        WHERE pf.project_id = ?
        ORDER BY pf.folder_id IS NOT NULL ASC, pf.folder_id ASC, pf.uploaded_at DESC, pf.id DESC
    ');
    $filesStmt->execute([$projectId]);
    foreach ($filesStmt->fetchAll(PDO::FETCH_ASSOC) as $fileRow) {
        $folderKey = !empty($fileRow['folder_id']) ? (int)$fileRow['folder_id'] : 0;
        $projectFiles[$folderKey][] = $fileRow;
    }
    $eventStmt = $pdo->prepare('
        SELECT e.*, pf.display_name AS file_display_name, pf.original_name AS file_original_name
        FROM project_public_events e
        LEFT JOIN project_files pf ON pf.id = e.file_id
        WHERE e.project_id = ?
        ORDER BY e.created_at DESC, e.id DESC
        LIMIT 10
    ');
    $eventStmt->execute([$projectId]);
    $projectPublicEvents = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    @error_log('[projects-details] project files query failed: ' . $e->getMessage());
}

// Fetch associated form documents
$stmt = $pdo->prepare('
    SELECT fd.*, fc.title as category_title
    FROM form_documents fd
    LEFT JOIN form_categories fc ON fc.id = fd.category_id
    WHERE fd.project_id = ?
    ORDER BY fd.uploaded_at DESC
');
$stmt->execute([$projectId]);
$formDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$docScopeWhere = [];
$docScopeParams = [];
$docScopeClientIds = [];
if (!empty($project['client_id'])) {
    $docScopeClientIds[] = (int)$project['client_id'];
}
foreach ($projectClients as $projectClient) {
    $docScopeClientIds[] = (int)$projectClient['id'];
}
$docScopeClientIds = array_values(array_unique(array_filter($docScopeClientIds, static fn($id) => $id > 0)));
if ($docScopeClientIds) {
    $docScopeWhere[] = 'client_id IN (' . implode(',', array_fill(0, count($docScopeClientIds), '?')) . ')';
    $docScopeParams = array_merge($docScopeParams, $docScopeClientIds);
}
if (!empty($project['organization_id'])) {
    $docScopeWhere[] = 'client_id IN (SELECT id FROM clients WHERE organization_id = ?)';
    $docScopeParams[] = (int)$project['organization_id'];
}
$docScopeSql = $docScopeWhere ? '(' . implode(' OR ', $docScopeWhere) . ')' : '1=0';

$stmt = $pdo->prepare("SELECT id, doc_number, status, total, created_at FROM quotes WHERE {$docScopeSql} AND project_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stmt->execute($docScopeParams);
$availableQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, doc_number, status, total, created_at FROM contracts WHERE {$docScopeSql} AND project_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stmt->execute($docScopeParams);
$availableContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, doc_number, invoice_type, status, total, created_at FROM invoices WHERE {$docScopeSql} AND project_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stmt->execute($docScopeParams);
$availableInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status colors
$statusColors = [
    'not_started' => ['bg' => '#fef3c7', 'color' => '#92400e', 'text' => 'Not Started'],
    'active' => ['bg' => '#d1fae5', 'color' => '#065f46', 'text' => 'Active'],
    'overdue' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'text' => 'Overdue'],
    'completed' => ['bg' => '#e0e7ff', 'color' => '#3730a3', 'text' => 'Completed'],
    'cancelled' => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'text' => 'Cancelled']
];

$projectDisplayStatus = in_array((string)$project['status'], ['not_started','active'], true)
    && !empty($project['estimated_end']) && (string)$project['estimated_end'] < date('Y-m-d')
    ? 'overdue' : (string)$project['status'];
$currentStatus = $statusColors[$projectDisplayStatus] ?? $statusColors['not_started'];
$contractSettlementEnabled = (string)($appConfig['contract_settlement_enabled'] ?? '0') === '1';
$projectIsTerminal = in_array((string)$project['status'], ['completed', 'cancelled'], true);
$closeoutTarget = in_array((string)($_GET['closeout_target'] ?? ''), ['completed', 'cancelled'], true)
    ? (string)$_GET['closeout_target']
    : null;
$closeoutBlocked = $canEditProjectStatus && $contractSettlementEnabled && !empty($_GET['closeout_blocked']) && $closeoutTarget !== null;
$terminalContractStatuses = ['completed', 'cancelled', 'denied', 'void'];
$closeoutBlockers = $closeoutBlocked
    ? array_values(array_filter(
        $contracts,
        static fn(array $contract): bool => !in_array(strtolower((string)$contract['status']), $terminalContractStatuses, true)
    ))
    : [];
$terminalInvoiceBadge = null;
if ($projectIsTerminal) {
    $receivables = (new App\Services\ProjectReceivablesSummaryService($pdo))->summarize($projectId);
    $terminalLabel = (string)$project['status'] === 'completed' ? 'Completed' : 'Cancelled';
    $terminalInvoiceBadge = $receivables['has_outstanding']
        ? $terminalLabel . ' · Outstanding $' . number_format($receivables['total_minor'] / 100, 2)
        : $terminalLabel . ' · No outstanding balance';
}
$autoEmailEnabled = !array_key_exists('project_invoice_auto_email', $project) || !empty($project['project_invoice_auto_email']);
$lastProjectInvoice = $projectInvoices[0] ?? null;
$monthlyBilling = ($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly';
$nextBillingLabel = $monthlyBilling ? date('M j, Y', strtotime('first day of next month')) : 'Per invoice';
$publicProjectUrl = pa_project_public_url($appConfig ?? [], (string)($project['public_project_token'] ?? ''));

?>

<?php
$projectReturnUrl = '/?page=project/projects-details&id=' . $projectId;
$renderDocumentAttachForm = static function (string $type, string $label, array $documents) use ($projectId): void {
    ?>
    <form method="post" action="/?page=project/project-add-document" style="display:grid;gap:8px;margin:0">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
        <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($type); ?>">
        <label>
            <div style="font-size:13px;font-weight:600;margin-bottom:4px"><?php echo htmlspecialchars($label); ?></div>
            <select name="document_id" <?php echo empty($documents) ? 'disabled' : ''; ?> style="width:100%;padding:9px;border-radius:8px;border:1px solid #ddd">
                <?php if (empty($documents)): ?>
                    <option value="">No available <?php echo htmlspecialchars(strtolower($label)); ?></option>
                <?php else: ?>
                    <option value="">Select <?php echo htmlspecialchars(strtolower($label)); ?></option>
                    <?php foreach ($documents as $document): ?>
                        <option value="<?php echo (int)$document['id']; ?>">
                            <?php echo htmlspecialchars($type === 'invoice' ? pa_invoice_label_from_row($document) : '#' . (string)($document['doc_number'] ?? $document['id'])); ?>
                            - <?php echo htmlspecialchars(ucfirst((string)$document['status'])); ?>
                            - $<?php echo number_format((float)$document['total'], 2); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-sm" <?php echo empty($documents) ? 'disabled' : ''; ?>>Add <?php echo htmlspecialchars($label); ?></button>
    </form>
    <?php
};
$renderProjectFileRow = static function (array $file, int $projectId): void {
    $fileId = (int)$file['id'];
    $displayName = (string)($file['display_name'] ?: $file['original_name']);
    $kind = project_files_kind($file['mime_type'] ?? '', $displayName);
    $kindLabel = strtoupper($kind === 'file' ? (pathinfo($displayName, PATHINFO_EXTENSION) ?: 'file') : $kind);
    $viewUrl = '/?page=project/project-file-download&id=' . $fileId;
    $downloadUrl = $viewUrl . '&download=1';
    $canPreview = in_array($kind, ['pdf', 'image', 'text'], true);
    ?>
    <div class="project-file-row">
        <div class="project-file-main">
            <div class="project-file-icon"><?php echo htmlspecialchars(substr($kindLabel, 0, 4)); ?></div>
            <div style="min-width:0">
                <div class="project-file-name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="project-file-meta">
                    <?php echo htmlspecialchars(project_files_format_size((int)($file['file_size'] ?? 0))); ?>
                    <?php if (!empty($file['uploaded_at'])): ?>
                        &middot; Uploaded <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$file['uploaded_at']))); ?>
                    <?php endif; ?>
                </div>
                <form method="post" action="/?page=project/project-files" class="project-file-compact-form" style="margin-top:8px">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="action" value="rename_file">
                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="file_id" value="<?php echo $fileId; ?>">
                    <input name="display_name" value="<?php echo htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" aria-label="File display name">
                    <button type="submit" class="btn btn-sm">Rename</button>
                </form>
            </div>
        </div>
        <div class="project-file-buttons">
            <?php if ($canPreview): ?>
                <a class="btn btn-sm" data-skip-nav href="<?php echo htmlspecialchars($viewUrl); ?>" target="_blank" rel="noopener">View</a>
            <?php endif; ?>
            <a class="btn btn-sm" data-skip-nav href="<?php echo htmlspecialchars($downloadUrl); ?>" download>Download</a>
            <form method="post" action="/?page=project/project-files" onsubmit="return confirm('Delete this project file?');" style="margin:0">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <input type="hidden" name="file_id" value="<?php echo $fileId; ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
            </form>
        </div>
    </div>
    <?php
};
?>

<style>
.project-page{max-width:1440px;margin:0 auto;padding:24px}.project-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px;align-items:start}.project-main,.project-sidebar{min-width:0}.project-panel{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}.project-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}.project-header h1{margin:0 0 6px;font-size:26px;line-height:1.2}.project-subtitle{color:var(--muted);font-size:13px}.project-status{display:inline-flex;align-items:center;padding:6px 10px;border-radius:6px;font-size:13px;font-weight:700;white-space:nowrap}.project-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;padding-top:18px;margin-top:18px;border-top:1px solid #e5e7eb}.project-fact-label{font-size:12px;color:var(--muted);margin-bottom:3px}.project-fact-value{font-size:14px;font-weight:600}.project-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));border:1px solid #dfe3e8;border-radius:8px;background:#fff;margin-bottom:18px;overflow:hidden}.project-metric{padding:15px 16px;border-right:1px solid #e5e7eb}.project-metric:last-child{border-right:0}.project-metric-label{font-size:12px;color:var(--muted)}.project-metric-value{font-size:22px;font-weight:750;line-height:1.25;margin-top:2px}.project-metric-note{font-size:12px;margin-top:2px}.project-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.project-section-head h2{font-size:18px;margin:0}.project-doc-row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:11px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fafbfc;text-decoration:none;color:inherit}.project-doc-row:hover{border-color:#c9d1d9;background:#fff}.project-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.project-actions .btn{display:flex;align-items:center;justify-content:center;text-align:center;min-height:38px}.project-actions .project-action-wide{grid-column:1/-1}.project-field{display:grid;gap:5px}.project-field>span,.project-field>div:first-child{font-size:13px;font-weight:600}.project-field input,.project-field select,.project-field textarea{width:100%;padding:9px 10px;border:1px solid #cfd5dc;border-radius:6px;background:#fff}.project-field small{color:var(--muted);font-size:12px;line-height:1.4}.project-check-list{display:grid;gap:7px;padding:10px;border:1px solid #dfe3e8;border-radius:6px;max-height:180px;overflow:auto}.project-check{display:flex;align-items:flex-start;gap:8px;font-size:13px}.project-check input{width:auto;margin-top:2px}.project-sidebar-title{font-size:15px;font-weight:700;margin-bottom:12px}.project-muted{color:var(--muted);font-size:13px}.project-danger{border-color:#fecaca;background:#fffafa}.project-danger .project-sidebar-title{color:#991b1b}.project-closeout-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:9px 10px;border:1px solid #f1c27d;border-radius:6px;background:#fff}.project-closeout-action{min-height:44px;display:inline-flex;align-items:center;justify-content:center}.project-closeout-retry{margin-top:12px}.project-closeout-retry .btn{min-height:44px}@media(max-width:1050px){.project-layout{grid-template-columns:1fr}.project-sidebar{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.project-sidebar>.project-panel{margin:0}.project-sidebar>.project-panel:nth-child(3),.project-sidebar>.project-panel:nth-child(4){grid-column:1/-1}}@media(max-width:760px){.site-shell{display:block!important}.main-content{width:100%!important;min-width:0!important}.project-page{width:100%;padding:16px}.project-header{display:grid}.project-facts{grid-template-columns:1fr}.project-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.project-metric{border-bottom:1px solid #e5e7eb}.project-metric:nth-child(2n){border-right:0}.project-metric:last-child{grid-column:1/-1;border-bottom:0}.project-sidebar{display:block}.project-sidebar>.project-panel{margin-bottom:16px}.project-actions{grid-template-columns:1fr}.project-actions .project-action-wide{grid-column:auto}.project-doc-row{align-items:flex-start;flex-direction:column}.project-doc-row>div:last-child{width:100%;justify-content:space-between}.project-closeout-row{align-items:stretch;flex-direction:column}.project-closeout-action,.project-closeout-retry .btn{width:100%}}
</style>
<style>
.project-settings-form{display:grid;gap:14px}.project-settings-card{border:1px solid #dfe3e8;border-radius:10px;background:#fbfcfd;padding:14px;display:grid;gap:11px}.project-settings-card h3{margin:0;font-size:14px;color:#111827}.project-settings-card p{margin:0;color:var(--muted);font-size:12px;line-height:1.45}.project-settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.project-help-icon{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:999px;background:#e0e7ff;color:#3730a3;font-size:11px;cursor:help}.project-pill{display:inline-flex;align-items:center;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;background:#dbeafe;color:#1d4ed8;white-space:nowrap}.project-info-box{padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;color:#1e3a8a;font-size:12px;line-height:1.45}.project-settings-picker{display:grid;gap:8px}.project-settings-picker__selected{display:grid;gap:8px;min-height:42px}.project-settings-picker__empty{padding:10px;border:1px dashed #d1d5db;border-radius:8px;color:var(--muted);background:#fff;font-size:13px}.project-settings-picker__item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fff}.project-settings-picker__name{font-weight:700}.project-settings-picker__meta{display:block;color:var(--muted);font-size:12px;margin-top:2px}.project-settings-picker__remove{border:0;background:#f3f4f6;color:#111827;border-radius:999px;width:28px;height:28px;cursor:pointer;font-weight:800}.project-settings-picker__search{position:relative}.project-settings-picker__suggestions{position:absolute;left:0;right:0;top:100%;z-index:40;display:none;max-height:210px;overflow:auto;border:1px solid #dfe3e8;border-radius:8px;background:#fff;box-shadow:0 12px 24px rgba(15,23,42,.12)}.project-settings-picker__suggestion{padding:9px 10px;border-bottom:1px solid #eef2f7;cursor:pointer}.project-settings-picker__suggestion:hover{background:#f8fafc}.project-settings-save{position:sticky;bottom:12px;z-index:1;box-shadow:0 10px 24px rgba(15,23,42,.12)}@media(max-width:760px){.project-settings-grid{grid-template-columns:1fr}.project-settings-save{position:static}}
</style>
<style>
.project-file-actions{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:12px;margin-bottom:16px}.project-file-action{border:1px solid #dfe3e8;border-radius:8px;background:#fbfcfd;padding:12px;display:grid;gap:8px}.project-file-action h3{font-size:14px;margin:0}.project-file-folder{border:1px solid #dfe3e8;border-radius:8px;background:#fff;margin-top:12px;overflow:hidden}.project-file-folder__head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px;background:#f8fafc;border-bottom:1px solid #e5e7eb}.project-file-folder__title{font-weight:750}.project-file-grid{display:grid;gap:8px;padding:12px}.project-file-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}.project-file-main{display:flex;align-items:flex-start;gap:10px;min-width:0}.project-file-icon{display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:#eef2ff;color:#3730a3;font-weight:800;font-size:12px;flex:none}.project-file-name{font-weight:700;word-break:break-word}.project-file-meta{font-size:12px;color:var(--muted);margin-top:2px}.project-file-buttons{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:6px}.project-file-compact-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.project-file-compact-form input{min-width:160px;padding:7px 8px;border:1px solid #cfd5dc;border-radius:6px}.project-file-empty{padding:18px;text-align:center;color:var(--muted);border:1px dashed #d1d5db;border-radius:8px;background:#fff}@media(max-width:900px){.project-file-actions{grid-template-columns:1fr}.project-file-row{grid-template-columns:1fr}.project-file-buttons{justify-content:flex-start}}
</style>

<style>
.project-work-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:end;margin:14px 0;padding:12px;border:1px solid #dfe3e8;border-radius:8px;background:#fafbfc}.project-work-form>.btn{justify-self:start}.project-work-wide{grid-column:1/-1}.project-work-row{padding:10px 0;border-bottom:1px solid #e5e7eb}.project-panel details{padding:12px 0;border-top:1px solid #e5e7eb}.project-panel summary{cursor:pointer}.project-panel fieldset{border:0;padding:0;margin:0}@media(max-width:760px){.project-work-form{grid-template-columns:1fr}.project-work-wide{grid-column:auto}}
</style>

<div class="project-page">
    <div style="margin-bottom:24px">
        <a href="/?page=project/projects-list" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to Projects
        </a>
    </div>

    <div class="project-layout">
        <!-- Main Content -->
        <div class="project-main">
            <!-- Project Header -->
            <div class="project-panel">
                <div class="project-header">
                    <div>
                        <h1 style="margin:0 0 8px 0;font-size:28px"><?php echo htmlspecialchars($project['name']); ?></h1>
                        <div class="project-subtitle">
                            Created <?php echo date('F j, Y', strtotime($project['created_at'])); ?>
                        </div>
                    </div>
                    <div style="display:grid;justify-items:end;gap:6px">
                        <div class="project-status" style="background:<?php echo $currentStatus['bg']; ?>;color:<?php echo $currentStatus['color']; ?>">
                            <?php echo $currentStatus['text']; ?>
                        </div>
                        <?php if ($terminalInvoiceBadge !== null): ?>
                            <div class="project-status" style="background:#fff7ed;color:#9a3412">
                                <?php echo htmlspecialchars($terminalInvoiceBadge); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;align-items:center">
                    <a class="btn btn-primary" href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>">Edit Project</a>
                    <a class="btn" href="/?page=project/projects-list">All Projects</a>
                    <?php echo pa_project_public_badge_html($project, $appConfig ?? []); ?>
                </div>

                <div class="project-facts">
                    <?php if ($project['client_name']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Client</div>
                        <div class="font-600"><?php echo htmlspecialchars($project['client_name']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($projectClients)): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Project Clients</div>
                        <div class="font-600">
                            <?php echo htmlspecialchars(implode(', ', array_map(static fn($c) => $c['name'] . (!empty($c['is_primary_billing']) ? ' (primary)' : ''), $projectClients))); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($project['organization_name']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Organization</div>
                        <div class="font-600"><?php echo htmlspecialchars($project['organization_name']); ?></div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Department</div>
                        <div class="font-600"><?php echo htmlspecialchars((string)($project['department_name'] ?? '') !== '' ? (string)$project['department_name'] : 'Org-level / no department'); ?></div>
                    </div>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Business Unit</div>
                        <div class="font-600"><?php echo htmlspecialchars((string)($project['business_unit_name'] ?: 'Unassigned — review needed')); ?></div>
                    </div>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Project Manager</div>
                        <div class="font-600"><?php echo htmlspecialchars((string)($project['manager_name'] ?: 'Unassigned — review needed')); ?></div>
                    </div>

                    <?php if ($project['estimated_start'] || $project['estimated_end']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Timeline</div>
                        <div class="font-600">
                            <?php if ($project['estimated_start']): ?>
                                <?php echo date('M j, Y', strtotime($project['estimated_start'])); ?>
                            <?php endif; ?>
                            <?php if ($project['estimated_start'] && $project['estimated_end']): ?>
                                →
                            <?php endif; ?>
                            <?php if ($project['estimated_end']): ?>
                                <?php echo date('M j, Y', strtotime($project['estimated_end'])); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Invoice Billing</div>
                        <div class="font-600">
                            <?php echo ($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'Monthly project billing' : 'Each invoice uses its own due date'; ?>
                            <?php if (($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly'): ?>
                                <?php if ($project['invoice_net_terms_days'] !== null && $project['invoice_net_terms_days'] !== ''): ?>
                                    - NET <?php echo (int)$project['invoice_net_terms_days']; ?>
                                <?php else: ?>
                                    - system NET terms
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (($project['invoice_billing_period'] ?? 'per_invoice') === 'per_invoice' && (int)$unresolvedMonthlyInvoices['count'] > 0): ?>
                    <div style="grid-column:1/-1;padding:12px;border:1px solid #f59e0b;border-radius:8px;background:#fffbeb;color:#92400e">
                        <div style="font-weight:700">Previous monthly invoices still need a collection path</div>
                        <div style="font-size:13px;margin-top:3px"><?php echo (int)$unresolvedMonthlyInvoices['count']; ?> unassigned invoice(s) totaling $<?php echo number_format((float)$unresolvedMonthlyInvoices['balance'], 2); ?> are waiting for billing-transition review.</div>
                        <a class="btn btn-sm" href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>#project-billing" style="margin-top:8px">Resolve prior monthly invoices</a>
                    </div>
                    <?php endif; ?>

                    <?php if (($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly' && ((int)$pendingMonthlyReady['count'] > 0 || (int)$pendingMonthlyDrafts['count'] > 0)): ?>
                    <div style="grid-column:1/-1;padding:12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1e3a8a">
                        <?php if ((int)$pendingMonthlyReady['count'] > 0): ?>
                        <div style="font-weight:700"><?php echo (int)$pendingMonthlyReady['count']; ?> finalized charge(s) totaling $<?php echo number_format((float)$pendingMonthlyReady['balance'], 2); ?> are ready for the next Project Invoice.</div>
                        <?php endif; ?>
                        <?php if ((int)$pendingMonthlyDrafts['count'] > 0): ?>
                        <div style="font-size:13px;margin-top:3px"><?php echo (int)$pendingMonthlyDrafts['count']; ?> draft charge(s) totaling $<?php echo number_format((float)$pendingMonthlyDrafts['balance'], 2); ?> are excluded until you finalize them for project billing.</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($project['notes']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Notes</div>
                        <div style="white-space:pre-wrap"><?php echo htmlspecialchars($project['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="project-metrics">
                <div class="project-metric">
                    <div class="project-metric-label">Quotes</div>
                    <div class="project-metric-value"><?php echo count($quotes); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Contracts</div>
                    <div class="project-metric-value"><?php echo count($contracts); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Paid Invoices</div>
                    <div class="project-metric-value"><?php echo (int)$invoiceStats['paid_count']; ?></div>
                    <div class="project-metric-note" style="color:#067647">$<?php echo number_format((float)$invoiceStats['paid_total'], 2); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Open Invoices</div>
                    <div class="project-metric-value"><?php echo (int)$invoiceStats['unpaid_count']; ?></div>
                    <div class="project-metric-note" style="color:#b54708">$<?php echo number_format((float)$invoiceStats['unpaid_total'], 2); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Project Invoices</div>
                    <div class="project-metric-value"><?php echo count($projectInvoices); ?></div>
                </div>
            </div>

            <div class="project-panel" id="assigned-services">
                <?php
                $portalServiceAssignmentSubjectType = 'project';
                $portalServiceAssignmentSubjectId = $projectId;
                $portalServiceAssignmentEntityPermission = 'projects.edit';
                include __DIR__ . '/../../components/portal_service_assignments.php';
                ?>
            </div>

            <?php if ($closeoutBlocked): ?>
                <?php
                $closeoutIsCompletion = $closeoutTarget === 'completed';
                $closeoutActionNoun = $closeoutIsCompletion ? 'completion' : 'cancellation';
                $closeoutActionVerb = $closeoutIsCompletion ? 'Complete' : 'Cancel';
                $closeoutConfirm = $closeoutActionVerb . ' this Project? Collectible receivables remain due and continue after close-out.';
                ?>
                <div id="project-closeout-alert" class="alert alert-warning" role="alert" aria-labelledby="project-closeout-alert-title" tabindex="-1" style="margin-bottom:18px">
                    <div id="project-closeout-alert-title" style="font-weight:700;margin-bottom:4px">Project <?php echo $closeoutActionNoun; ?> is blocked</div>
                    <div style="margin-bottom:10px">Close the Contracts below before retrying <?php echo $closeoutActionNoun; ?>. Open invoices do not block project close-out and collectible balances remain due.</div>
                    <?php if ($closeoutBlockers): ?>
                        <div style="display:grid;gap:8px">
                            <?php foreach ($closeoutBlockers as $contract): ?>
                                <?php $contractPage = ($contract['contract_type'] ?? '') === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details'; ?>
                                <div class="project-closeout-row">
                                    <div>
                                        <strong>Contract #<?php echo htmlspecialchars((string)($contract['doc_number'] ?? $contract['id'])); ?></strong>
                                        <span class="project-muted"> · <?php echo htmlspecialchars(ucfirst((string)$contract['status'])); ?></span>
                                    </div>
                                    <a class="btn btn-sm project-closeout-action" aria-label="Review close-out for Contract <?php echo htmlspecialchars((string)($contract['doc_number'] ?? $contract['id'])); ?>" href="/?page=<?php echo $contractPage; ?>&amp;id=<?php echo (int)$contract['id']; ?>">Review close-out</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="project-muted">The Contracts changed after this attempt. Review their current status and try again.</div>
                    <?php endif; ?>
                    <?php if ($canEditProjectStatus): ?>
                        <form class="project-closeout-retry" method="post" action="/?page=project/projects-update-status" onsubmit="return confirm('<?php echo htmlspecialchars($closeoutConfirm, ENT_QUOTES); ?>');">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($closeoutTarget); ?>">
                            <button type="submit" class="btn btn-primary">Retry <?php echo $closeoutActionNoun; ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <script>document.getElementById('project-closeout-alert')?.focus();</script>
            <?php endif; ?>

            <?php if (!empty($_GET['status_updated'])): ?>
                <div class="alert alert-success" role="status" style="margin-bottom:16px">Project status updated.</div>
            <?php endif; ?>
            <?php if (!empty($_GET['billing_transition']) && (string)$_GET['billing_transition'] === 'success'): ?>
                <div class="alert alert-success" role="status" style="margin-bottom:16px">
                    <?php echo htmlspecialchars((string)($_GET['transition_message'] ?? 'Project billing was updated. Existing invoice and Project Invoice links remain unchanged.')); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['transition_error'])): ?>
                <div class="alert alert-danger" role="alert" style="margin-bottom:16px">
                    <?php echo htmlspecialchars((string)$_GET['transition_error']); ?>
                    <a href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>#project-billing" style="margin-left:8px;font-weight:700">Review billing transition</a>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['billing_msg'])): ?>
                <div class="alert alert-info" style="margin-bottom:16px">
                    <?php echo htmlspecialchars((string)$_GET['billing_msg']); ?>
                    <?php if (!empty($_GET['billing_missing_recipients'])): ?>
                        <a href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>#project-contacts" style="margin-left:8px;font-weight:700">Choose delivery recipients</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div id="team-work" class="project-panel">
                <div class="project-section-head"><div><h2>Team &amp; Work</h2><div class="project-muted">Project Team membership controls who may be assigned. External application access is managed separately in Custom Integrations.</div></div></div>
                <?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Project work plan saved.</div><?php endif; ?>
                <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>
                <?php if ($planningLoadError !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($planningLoadError); ?></div><?php endif; ?>
                <details open><summary><strong>Team (<?php echo count($activeProjectTeam); ?> active)</strong></summary>
                    <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Member</th><th>Status</th><?php if($canManageProjectWork):?><th></th><?php endif;?></tr></thead><tbody>
                    <?php foreach($projectTeam as $member): $active=empty($member['ends_at'])||strtotime((string)$member['ends_at'])>time(); $isManager=(int)$member['user_id']===(int)($project['manager_user_id']??0); ?><tr><td><?php echo htmlspecialchars((string)$member['name']); ?><?php if($isManager):?> <span class="project-pill project-pill--primary">Manager</span><?php endif;?></td><td><?php echo $active?'Active':'Ended'; ?></td><?php if($canManageProjectWork):?><td class="text-right"><?php if($active&&!$isManager):?><form method="post" action="/?page=project/project-work-handler" class="inline-form" onsubmit="return confirm('End this Project Team membership?');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="end-team-member"><input type="hidden" name="project_id" value="<?php echo $projectId; ?>"><input type="hidden" name="user_id" value="<?php echo (int)$member['user_id']; ?>"><button class="btn btn-sm">End membership</button></form><?php endif;?></td><?php endif;?></tr><?php endforeach; ?>
                    <?php if(!$projectTeam):?><tr><td colspan="3">No team members yet.</td></tr><?php endif;?></tbody></table></div>
                    <?php if($canManageProjectWork):?><form method="post" action="/?page=project/project-work-handler" class="project-work-form"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="add-team-member"><input type="hidden" name="project_id" value="<?php echo $projectId; ?>"><label class="project-field"><span>Add active PA user</span><select name="user_id" required><option value="">Search or select a user</option><?php foreach($planningUsers as $user):?><option value="<?php echo (int)$user['id']; ?>"><?php echo htmlspecialchars((string)$user['name']); ?></option><?php endforeach;?></select></label><button class="btn btn-primary">Add to Team</button></form><?php endif; ?>
                </details>
                <details><summary><strong>Operations (<?php echo count($projectOperations); ?>)</strong></summary>
                    <?php foreach($projectOperations as $operation):?><div class="project-work-row"><div><strong><?php echo htmlspecialchars((string)$operation['title']); ?></strong><div class="project-muted"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',(string)$operation['status']))); ?> · <?php echo htmlspecialchars((string)($operation['scheduled_start_at']?:'Unscheduled')); ?> · <?php echo htmlspecialchars(implode(', ',array_column($operationAssignees[(int)$operation['id']]??[],'name')) ?: 'No crew assigned'); ?></div></div><?php if($canManageProjectWork):?><a class="btn btn-sm" href="/?page=project/projects-details&amp;id=<?php echo $projectId;?>&amp;operation_id=<?php echo (int)$operation['id'];?>#team-work">Edit</a><?php endif;?></div><?php endforeach;?>
                    <?php if(!$projectOperations):?><p class="project-muted">No Operations yet.</p><?php endif;?>
                    <?php if($canManageProjectWork&&$editOperation):$selectedOperationUsers=array_map('intval',array_column($operationAssignees[(int)$editOperation['id']]??[],'user_id'));?><form method="post" action="/?page=project/project-work-handler" class="project-work-form"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="save-operation"><input type="hidden" name="project_id" value="<?php echo $projectId; ?>"><input type="hidden" name="id" value="<?php echo (int)$editOperation['id'];?>"><label class="project-field"><span>Operation / visit name</span><input name="title" required value="<?php echo htmlspecialchars((string)$editOperation['title']);?>"></label><label class="project-field"><span>Status</span><select name="status"><?php foreach(\App\Services\OperationsPlanningService::OPERATION_STATUSES as $status):?><option value="<?php echo $status;?>" <?php echo $editOperation['status']===$status?'selected':'';?>><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$status)));?></option><?php endforeach;?></select></label><label class="project-field"><span>Starts</span><input type="datetime-local" name="scheduled_start_at" value="<?php echo htmlspecialchars(str_replace(' ','T',substr((string)$editOperation['scheduled_start_at'],0,16)));?>"></label><label class="project-field"><span>Ends</span><input type="datetime-local" name="scheduled_end_at" value="<?php echo htmlspecialchars(str_replace(' ','T',substr((string)$editOperation['scheduled_end_at'],0,16)));?>"></label><label class="project-field"><span>Location</span><input name="location" value="<?php echo htmlspecialchars((string)$editOperation['location']);?>"></label><fieldset class="project-field"><legend>Crew</legend><?php foreach($activeProjectTeam as $member):?><label class="project-check"><input type="checkbox" name="assigned_user_ids[]" value="<?php echo (int)$member['user_id'];?>" <?php echo in_array((int)$member['user_id'],$selectedOperationUsers,true)?'checked':'';?>> <?php echo htmlspecialchars((string)($member['display_name']?:$member['username']?:$member['email']));?></label><?php endforeach;?></fieldset><label class="project-field project-work-wide"><span>Notes</span><textarea name="notes"><?php echo htmlspecialchars((string)$editOperation['notes']);?></textarea></label><button class="btn btn-primary">Save Operation</button><a class="btn" href="/?page=project/projects-details&amp;id=<?php echo $projectId;?>#team-work">Cancel</a></form><?php endif;?>
                    <?php if($canManageProjectWork):?><form method="post" action="/?page=project/project-work-handler" class="project-work-form"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="save-operation"><input type="hidden" name="project_id" value="<?php echo $projectId; ?>"><label class="project-field"><span>Operation / visit name</span><input name="title" required maxlength="255"></label><label class="project-field"><span>Status</span><select name="status"><?php foreach(\App\Services\OperationsPlanningService::OPERATION_STATUSES as $status):?><option value="<?php echo $status; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$status))); ?></option><?php endforeach;?></select></label><label class="project-field"><span>Starts</span><input type="datetime-local" name="scheduled_start_at"></label><label class="project-field"><span>Ends</span><input type="datetime-local" name="scheduled_end_at"></label><label class="project-field"><span>Location</span><input name="location" maxlength="500"></label><fieldset class="project-field"><legend>Crew</legend><?php foreach($activeProjectTeam as $member):?><label class="project-check"><input type="checkbox" name="assigned_user_ids[]" value="<?php echo (int)$member['user_id']; ?>"> <?php echo htmlspecialchars((string)($member['display_name']?:$member['username']?:$member['email'])); ?></label><?php endforeach;?></fieldset><label class="project-field project-work-wide"><span>Notes</span><textarea name="notes"></textarea></label><button class="btn btn-primary">Create Operation</button></form><?php endif;?>
                </details>
                <details><summary><strong>Tasks (<?php echo count($projectTasks); ?>)</strong></summary>
                    <?php foreach($projectTasks as $task):?><div class="project-work-row"><div><strong><?php echo htmlspecialchars((string)$task['title']); ?></strong><div class="project-muted"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',(string)$task['status']))); ?><?php if($task['operation_title']):?> · <?php echo htmlspecialchars((string)$task['operation_title']); ?><?php endif;?> · <?php echo htmlspecialchars(implode(', ',array_column($taskAssignees[(int)$task['id']]??[],'name')) ?: 'Unassigned'); ?></div></div><?php if($canManageProjectWork):?><a class="btn btn-sm" href="/?page=project/projects-details&amp;id=<?php echo $projectId;?>&amp;task_id=<?php echo (int)$task['id'];?>#team-work">Edit</a><?php endif;?></div><?php endforeach;?>
                    <?php if(!$projectTasks):?><p class="project-muted">No Tasks yet.</p><?php endif;?>
                    <?php if($canManageProjectWork&&$editTask):$selectedTaskUsers=array_map('intval',array_column($taskAssignees[(int)$editTask['id']]??[],'user_id'));?><form method="post" action="/?page=project/project-work-handler" class="project-work-form"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="save-task"><input type="hidden" name="project_id" value="<?php echo $projectId; ?>"><input type="hidden" name="id" value="<?php echo (int)$editTask['id'];?>"><label class="project-field"><span>Task</span><input name="title" required value="<?php echo htmlspecialchars((string)$editTask['title']);?>"></label><label class="project-field"><span>Operation</span><select name="operation_id"><option value="">No Operation</option><?php foreach($projectOperations as $operation):?><option value="<?php echo (int)$operation['id'];?>" <?php echo (int)$editTask['operation_id']===(int)$operation['id']?'selected':'';?>><?php echo htmlspecialchars((string)$operation['title']);?></option><?php endforeach;?></select></label><label class="project-field"><span>Status</span><select name="status"><?php foreach(\App\Services\OperationsPlanningService::TASK_STATUSES as $status):?><option value="<?php echo $status;?>" <?php echo $editTask['status']===$status?'selected':'';?>><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$status)));?></option><?php endforeach;?></select></label><label class="project-field"><span>Due</span><input type="datetime-local" name="due_at" value="<?php echo htmlspecialchars(str_replace(' ','T',substr((string)$editTask['due_at'],0,16)));?>"></label><fieldset class="project-field"><legend>Assignees</legend><?php foreach($activeProjectTeam as $member):?><label class="project-check"><input type="checkbox" name="assigned_user_ids[]" value="<?php echo (int)$member['user_id'];?>" <?php echo in_array((int)$member['user_id'],$selectedTaskUsers,true)?'checked':'';?>> <?php echo htmlspecialchars((string)($member['display_name']?:$member['username']?:$member['email']));?></label><?php endforeach;?></fieldset><label class="project-field project-work-wide"><span>Notes</span><textarea name="notes"><?php echo htmlspecialchars((string)$editTask['notes']);?></textarea></label><button class="btn btn-primary">Save Task</button><a class="btn" href="/?page=project/projects-details&amp;id=<?php echo $projectId;?>#team-work">Cancel</a></form><?php endif;?>
                    <?php if($canManageProjectWork):?><form method="post" action="/?page=project/project-work-handler" class="project-work-form"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="save-task"><input type="hidden" name="project_id" value="<?php echo $projectId; ?>"><label class="project-field"><span>Task</span><input name="title" required maxlength="255"></label><label class="project-field"><span>Operation (optional)</span><select name="operation_id"><option value="">No Operation</option><?php foreach($projectOperations as $operation):?><option value="<?php echo (int)$operation['id']; ?>"><?php echo htmlspecialchars((string)$operation['title']); ?></option><?php endforeach;?></select></label><label class="project-field"><span>Status</span><select name="status"><?php foreach(\App\Services\OperationsPlanningService::TASK_STATUSES as $status):?><option value="<?php echo $status; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$status))); ?></option><?php endforeach;?></select></label><label class="project-field"><span>Due</span><input type="datetime-local" name="due_at"></label><fieldset class="project-field"><legend>Assignees</legend><?php foreach($activeProjectTeam as $member):?><label class="project-check"><input type="checkbox" name="assigned_user_ids[]" value="<?php echo (int)$member['user_id']; ?>"> <?php echo htmlspecialchars((string)($member['display_name']?:$member['username']?:$member['email'])); ?></label><?php endforeach;?></fieldset><label class="project-field project-work-wide"><span>Notes</span><textarea name="notes"></textarea></label><button class="btn btn-primary">Create Task</button></form><?php endif;?>
                </details>
            </div>

            <div id="project-files" class="project-panel">
                <div class="project-section-head">
                    <div>
                        <h2>Project Files</h2>
                        <div class="project-muted">Upload project-only documents or group them into one-level folders. These are separate from Forms &amp; Docs.</div>
                    </div>
                </div>
                <?php if (!empty($_GET['file_msg'])): ?>
                    <div class="alert alert-success" style="margin-bottom:12px"><?php echo htmlspecialchars((string)$_GET['file_msg']); ?></div>
                <?php endif; ?>
                <?php if (!empty($_GET['file_error'])): ?>
                    <div class="alert alert-danger" style="margin-bottom:12px"><?php echo htmlspecialchars((string)$_GET['file_error']); ?></div>
                <?php endif; ?>

                <div class="project-file-actions">
                    <form class="project-file-action" method="post" action="/?page=project/project-files" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="upload_file">
                        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                        <h3>Upload File</h3>
                        <label class="project-field">
                            <span>Folder</span>
                            <select name="folder_id">
                                <option value="">Root of project</option>
                                <?php foreach ($projectFileFolders as $folder): ?>
                                    <option value="<?php echo (int)$folder['id']; ?>"><?php echo htmlspecialchars((string)$folder['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="project-field">
                            <span>File</span>
                            <input type="file" name="project_file" required>
                            <small>Any file type is accepted. PDFs, images, text files, and standard office files get clearer file cards.</small>
                        </label>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </form>

                    <form class="project-file-action" method="post" action="/?page=project/project-files">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_folder">
                        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                        <h3>Create Folder</h3>
                        <label class="project-field">
                            <span>Folder Name</span>
                            <input name="name" required placeholder="e.g. Permits, Site Photos, Client Uploads">
                        </label>
                        <button type="submit" class="btn">Create Folder</button>
                    </form>
                </div>

                <div class="project-file-folder">
                    <div class="project-file-folder__head">
                        <div>
                            <div class="project-file-folder__title">Root Files</div>
                            <div class="project-muted"><?php echo count($projectFiles[0] ?? []); ?> file(s)</div>
                        </div>
                    </div>
                    <div class="project-file-grid">
                        <?php if (empty($projectFiles[0])): ?>
                            <div class="project-file-empty">No root files uploaded yet.</div>
                        <?php else: ?>
                            <?php foreach ($projectFiles[0] as $file): ?>
                                <?php $renderProjectFileRow($file, $projectId); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($projectFileFolders as $folder): ?>
                    <?php $folderId = (int)$folder['id']; ?>
                    <div class="project-file-folder">
                        <div class="project-file-folder__head">
                            <div>
                                <div class="project-file-folder__title"><?php echo htmlspecialchars((string)$folder['name']); ?></div>
                                <div class="project-muted"><?php echo (int)($folder['file_count'] ?? 0); ?> file(s)</div>
                            </div>
                            <div class="project-file-buttons">
                                <form method="post" action="/?page=project/project-files" class="project-file-compact-form">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="rename_folder">
                                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                                    <input type="hidden" name="folder_id" value="<?php echo $folderId; ?>">
                                    <input name="name" value="<?php echo htmlspecialchars((string)$folder['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" aria-label="Folder name">
                                    <button type="submit" class="btn btn-sm">Rename</button>
                                </form>
                                <form method="post" action="/?page=project/project-files" onsubmit="return confirm('Delete this folder and all files inside it?');" style="margin:0">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete_folder">
                                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                                    <input type="hidden" name="folder_id" value="<?php echo $folderId; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                        <div class="project-file-grid">
                            <?php if (empty($projectFiles[$folderId])): ?>
                                <div class="project-file-empty">No files in this folder yet.</div>
                            <?php else: ?>
                                <?php foreach ($projectFiles[$folderId] as $file): ?>
                                    <?php $renderProjectFileRow($file, $projectId); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($projectInvoices)): ?>
            <div class="project-panel">
                <div class="project-section-head">
                    <h2 style="margin:0;font-size:20px">Project Invoices</h2>
                    <a class="btn btn-sm" href="/?page=project/project-invoices-list&project_id=<?php echo $projectId; ?>">View All</a>
                </div>
                <div style="display:grid;gap:10px">
                    <?php foreach (array_slice($projectInvoices, 0, 5) as $projectInvoice): ?>
                        <div class="project-doc-row">
                            <div>
                                <div class="font-600">PI-<?php echo htmlspecialchars((string)($projectInvoice['doc_number'] ?: $projectInvoice['id'])); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo htmlspecialchars(project_invoice_period_label($projectInvoice)); ?>
                                    · <?php echo (int)$projectInvoice['child_count']; ?> invoice(s)
                                    · <?php echo htmlspecialchars(ucfirst((string)$projectInvoice['status'])); ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;align-items:center">
                                <div style="font-weight:700">$<?php echo number_format((float)$projectInvoice['balance_due'], 2); ?> due</div>
                                <a class="btn btn-sm" href="/?page=project/project-invoice-details&id=<?php echo (int)$projectInvoice['id']; ?>">View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Associated Documents Section -->
            <div class="project-panel">
                <div class="project-section-head"><h2>Associated Documents</h2></div>

                <!-- Quotes -->
                <?php if (!empty($quotes)): ?>
                <div style="margin-bottom:24px">
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Quotes (<?php echo count($quotes); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($quotes as $quote): ?>
                        <a href="/?page=quote/quote-details&id=<?php echo (int)$quote['id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600">Quote #<?php echo e($quote['doc_number'] ?? $quote['id']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo e(ucfirst($quote['status'])); ?> · 
                                    <?php echo date('M j, Y', strtotime($quote['created_at'])); ?>
                                </div>
                            </div>
                            <div style="font-weight:600;color:var(--nav-accent)">
                                $<?php echo number_format($quote['total'], 2); ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contracts -->
                <?php if (!empty($contracts)): ?>
                <div style="margin-bottom:24px">
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Contracts (<?php echo count($contracts); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($contracts as $contract): ?>
                        <a href="/?page=contract/contract-details&id=<?php echo (int)$contract['id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600">Contract #<?php echo e($contract['doc_number'] ?? $contract['id']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo e(ucfirst($contract['status'])); ?> · 
                                    <?php echo date('M j, Y', strtotime($contract['created_at'])); ?>
                                </div>
                            </div>
                            <div style="font-weight:600;color:var(--nav-accent)">
                                $<?php echo number_format($contract['total'], 2); ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Invoices -->
                <?php if (!empty($invoices)): ?>
                <div style="margin-bottom:24px">
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Invoices (<?php echo count($invoices); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($invoices as $invoice): ?>
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb">
                            <div>
                                <div class="font-600">Invoice <?php echo e(pa_invoice_label_from_row($invoice)); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo e(ucfirst($invoice['status'])); ?> · 
                                    <?php echo date('M j, Y', strtotime($invoice['created_at'])); ?>
                                </div>
                                <div class="document-actions" style="margin-top:8px">
                                    <a href="/?page=invoice/invoice-details&id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm">View</a>
                                    <a href="/?page=invoice/invoice-pdf&id=<?php echo (int)$invoice['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
                                    <?php if (($invoice['collection_mode'] ?? 'direct') === 'direct'): ?>
                                    <form method="post" action="/?page=invoice/email-send" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="type" value="invoice">
                                        <input type="hidden" name="id" value="<?php echo (int)$invoice['id']; ?>">
                                        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($projectReturnUrl); ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Email</button>
                                    </form>
                                    <?php else: ?>
                                        <span style="color:var(--muted);font-size:12px">Project statement billing</span>
                                        <?php if (!empty($invoice['assigned_project_invoice_id'])): ?>
                                            <a class="btn btn-sm" href="/?page=project/project-invoice-details&amp;id=<?php echo (int)$invoice['assigned_project_invoice_id']; ?>">Included in PI-<?php echo htmlspecialchars((string)($invoice['assigned_project_invoice_number'] ?: $invoice['assigned_project_invoice_id'])); ?></a>
                                        <?php elseif (($project['invoice_billing_period'] ?? 'per_invoice') === 'per_invoice'): ?>
                                            <a class="btn btn-sm" href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>#project-billing" style="border-color:#f59e0b;color:#92400e;background:#fffbeb">Pending billing-transition review</a>
                                        <?php else: ?>
                                            <span style="color:var(--muted);font-size:12px">Pending next Project Invoice</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="font-weight:600;color:var(--nav-accent);white-space:nowrap">
                                $<?php echo number_format($invoice['total'], 2); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form Documents -->
                <?php if (!empty($formDocuments)): ?>
                <div>
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Files (<?php echo count($formDocuments); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($formDocuments as $doc): ?>
                        <a href="/?page=financial/form-detail&id=<?php echo (int)$doc['category_id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600"><?php echo htmlspecialchars($doc['file_name']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo htmlspecialchars($doc['category_title'] ?? 'Uncategorized'); ?> · 
                                    <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?>
                                </div>
                            </div>
                            <div style="font-size:13px;color:var(--muted)">
                                <?php echo e(strtoupper(pathinfo($doc['file_path'], PATHINFO_EXTENSION))); ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($quotes) && empty($contracts) && empty($invoices) && empty($formDocuments)): ?>
                <div style="text-align:center;padding:48px;color:var(--muted)">
                    <div style="font-size:48px;margin-bottom:16px">📄</div>
                    <div style="font-size:16px">No documents associated with this project yet</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar Actions -->
        <div class="project-sidebar">
            <!-- Status Management -->
            <?php if ($canEditProjectStatus): ?>
            <div class="project-panel">
                <div class="project-sidebar-title">Change Status</div>
                <?php if ($contractSettlementEnabled): ?>
                    <div class="project-muted" style="margin-bottom:12px">Completing or cancelling checks attached Contracts. Open invoices do not block project close-out.</div>
                <?php endif; ?>
                <div class="grid">
                    <?php foreach ($statusColors as $statusKey => $statusInfo): ?>
                        <?php if ($statusKey !== 'overdue' && $statusKey !== $project['status']): ?>
                        <?php
                        $statusConfirmation = $statusKey === 'completed'
                            ? 'Complete this Project? Collectible receivables remain due and continue after completion.'
                            : ($statusKey === 'cancelled'
                                ? 'Cancel this Project? Collectible receivables remain due and continue after cancellation.'
                                : null);
                        ?>
                        <form method="post" action="/?page=project/projects-update-status" style="margin:0"<?php if ($statusConfirmation !== null): ?> onsubmit="return confirm('<?php echo htmlspecialchars($statusConfirmation, ENT_QUOTES); ?>');"<?php endif; ?>>
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                            <input type="hidden" name="status" value="<?php echo $statusKey; ?>">
                            <button type="submit" 
                                    style="width:100%;padding:10px;border-radius:6px;border:1px solid #e5e7eb;background:<?php echo $statusInfo['bg']; ?>;color:<?php echo $statusInfo['color']; ?>;font-weight:600;cursor:pointer;text-align:left">
                                → <?php echo $statusInfo['text']; ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="project-panel">
                <div class="project-sidebar-title">Billing Actions</div>
                <div class="project-muted" style="margin-bottom:12px">
                    <?php if ($monthlyBilling): ?>Next automatic cycle: <?php echo htmlspecialchars($nextBillingLabel); ?><?php else: ?>Monthly billing is currently disabled.<?php endif; ?>
                </div>
                <div class="project-actions">
                    <?php if ($monthlyBilling): ?>
                    <form method="post" action="/?page=project/project-invoice-generate" onsubmit="return confirm('Generate a project invoice for the current month through today without emailing it?');" style="margin:0">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                        <input type="hidden" name="period" value="current">
                        <button type="submit" class="btn btn-sm" style="width:100%">Generate Only</button>
                    </form>
                    <form method="post" action="/?page=project/project-invoice-generate" onsubmit="return confirm('Generate this project invoice and email the selected default recipients?');" style="margin:0">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                        <input type="hidden" name="period" value="current">
                        <input type="hidden" name="send_email" value="1">
                        <button type="submit" class="btn btn-sm btn-success" style="width:100%">Generate &amp; Email</button>
                    </form>
                    <?php elseif ((int)$unresolvedMonthlyInvoices['count'] > 0): ?>
                    <div class="project-action-wide" style="padding:10px;border:1px solid #f59e0b;border-radius:8px;background:#fffbeb;color:#92400e;font-size:13px">
                        <?php echo (int)$unresolvedMonthlyInvoices['count']; ?> prior monthly invoice(s) totaling $<?php echo number_format((float)$unresolvedMonthlyInvoices['balance'], 2); ?> need review before they can be collected.
                        <a href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>#project-billing" style="display:block;font-weight:700;margin-top:6px">Resolve billing transition</a>
                    </div>
                    <?php else: ?>
                    <div class="project-action-wide project-muted">New invoices are billed and sent individually. Existing Project Invoices remain available and payable through their original links.</div>
                    <?php endif; ?>
                    <a href="/?page=project/project-invoices-list&project_id=<?php echo $projectId; ?>" class="btn btn-sm project-action-wide">View Project Invoices</a>
                    <div class="project-sidebar-title project-action-wide" style="margin:10px 0 0">Create Document</div>
                    <a href="/?page=quote/quotes-create&project_id=<?php echo $projectId; ?>" 
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        📄 Create Quote
                    </a>
                    <a href="/?page=contract/contracts-create&project_id=<?php echo $projectId; ?>" 
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        📋 Create Contract
                    </a>
                    <a href="/?page=invoice/invoices-create&project_id=<?php echo $projectId; ?>" 
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        💰 Create Invoice
                    </a>
                </div>
            </div>

            <!-- Project Settings -->
            <div class="project-panel">
                <div class="project-section-head" style="margin-bottom:10px">
                    <div>
                        <div class="project-sidebar-title" style="margin-bottom:3px">Project Settings</div>
                        <div class="project-muted">Contacts, invoice recipients, schedule, billing defaults, notes, and public project access are managed on the edit page.</div>
                    </div>
                </div>
                <?php if (!empty($project['public_project_enabled']) && $publicProjectUrl !== ''): ?>
                    <label class="project-field" style="margin-bottom:10px">
                        <span>Public project link</span>
                        <input readonly value="<?php echo htmlspecialchars($publicProjectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" onclick="this.select()">
                    </label>
                    <div class="project-muted" style="margin-bottom:12px">
                        Code <?php echo !empty($project['public_project_require_password']) ? 'required' : 'not required'; ?>.
                        Uploads <?php echo !empty($project['public_project_can_upload']) ? 'allowed' : 'off'; ?>.
                    </div>
                <?php endif; ?>
                <a class="btn btn-primary project-action-wide" href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>" style="width:100%">Edit Project Settings</a>
            </div>

            <?php if (!empty($projectPublicEvents)): ?>
            <div class="project-panel">
                <div class="project-sidebar-title">Public Link Activity</div>
                <div class="grid" style="gap:8px">
                    <?php foreach ($projectPublicEvents as $event): ?>
                        <div style="padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#fbfcfd">
                            <div style="font-weight:700;font-size:13px">
                                <?php echo htmlspecialchars((string)$event['event_type'] === 'upload' ? 'File uploaded' : 'Update request'); ?>
                            </div>
                            <?php if (!empty($event['file_display_name']) || !empty($event['file_original_name'])): ?>
                                <div style="font-size:12px;color:var(--muted);margin-top:2px"><?php echo htmlspecialchars((string)($event['file_display_name'] ?: $event['file_original_name'])); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($event['message'])): ?>
                                <div style="font-size:13px;margin-top:6px;line-height:1.4"><?php echo nl2br(htmlspecialchars((string)$event['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
                            <?php endif; ?>
                            <div style="font-size:12px;color:var(--muted);margin-top:6px"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$event['created_at']))); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="project-panel" data-legacy-project-settings-panel style="display:none">
                <div class="project-section-head" style="margin-bottom:10px">
                    <div>
                        <div class="project-sidebar-title" style="margin-bottom:3px">Project Settings</div>
                        <div class="project-muted">Control this project’s scope, contact list, invoice recipients, and content-link access.</div>
                    </div>
                </div>
                <form method="post" action="/?page=project/projects-update" class="project-settings-form">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="organization_id" value="<?php echo (int)($project['organization_id'] ?? 0); ?>">

                    <section class="project-settings-card">
                        <h3>1. Project scope</h3>
                        <p>This decides the project’s organization or department context. Department links attach to invoices for department projects; organization links only inherit when the link explicitly allows it.</p>
                        <label class="project-field">
                            <span>Project Name</span>
                            <input name="name" value="<?php echo htmlspecialchars($project['name']); ?>">
                        </label>
                        <label class="project-field">
                            <span>Department <span class="project-help-icon" title="Leave this blank for organization-level work. Choose a department only when this project belongs to a specific group within the organization.">?</span></span>
                            <select name="department_id">
                                <option value="">No department / org-level project</option>
                                <?php foreach ($projectDepartments as $department): ?>
                                    <?php $departmentId = (int)$department['id']; ?>
                                    <option value="<?php echo $departmentId; ?>" <?php echo (int)($project['department_id'] ?? 0) === $departmentId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department['name'] . (!empty($department['folder_name']) ? ' — ' . $department['folder_name'] : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Leave blank if the organization has no departments or this project should use organization-level settings.</small>
                        </label>
                    </section>

                    <section class="project-settings-card">
                        <h3>2. Project contacts</h3>
                        <p>This is a project-only contact list. Type a client name or email to add them, and use the x on a row to remove them from this project.</p>
                        <div class="project-info-box">
                            Invoice email recipients and content-link viewers are separate lists. Selecting someone for invoice emails or links keeps them attached to the project automatically.
                        </div>
                        <div data-project-settings-contact-manager>
                            <script type="application/json" data-project-settings-clients><?php echo json_encode($projectSettingsClients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
                            <label class="project-field">
                                <span>Primary billed contact</span>
                                <select name="client_id" data-project-primary-select></select>
                                <small>Primary billed contact must be one of the attached project contacts.</small>
                            </label>
                            <div class="project-settings-grid">
                                <div class="project-settings-picker" data-project-settings-picker="project" data-empty-text="No project contacts selected.">
                                    <div class="label">Project contacts</div>
                                    <div class="project-settings-picker__selected" data-picker-selected></div>
                                    <div class="project-settings-picker__search">
                                        <input type="text" class="input" data-picker-search placeholder="Type a client name or email...">
                                        <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
                                    </div>
                                    <div data-picker-hidden></div>
                                </div>
                                <div class="project-settings-picker" data-project-settings-picker="invoice" data-empty-text="No invoice email recipients selected.">
                                    <div class="label">Project invoice email recipients</div>
                                    <div class="project-settings-picker__selected" data-picker-selected></div>
                                    <div class="project-settings-picker__search">
                                        <input type="text" class="input" data-picker-search placeholder="Type a client name or email...">
                                        <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
                                    </div>
                                    <div data-picker-hidden></div>
                                </div>
                            </div>
                            <div class="project-settings-picker" data-project-settings-picker="links" data-empty-text="No invoice content-link viewers selected." style="margin-top:10px">
                                <div class="label">Invoice content-link viewers</div>
                                <div class="project-settings-picker__selected" data-picker-selected></div>
                                <div class="project-settings-picker__search">
                                    <input type="text" class="input" data-picker-search placeholder="Type a client name or email...">
                                    <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
                                </div>
                                <div data-picker-hidden></div>
                            </div>
                        </div>
                        <div data-legacy-project-contact-ui style="display:none">
                            <?php if (empty($projectClients)): ?>
                                <div class="project-muted" style="padding:10px;border:1px dashed #d1d5db;border-radius:8px">No project contacts attached yet.</div>
                            <?php else: ?>
                                <?php foreach ($projectClients as $client): ?>
                                    <?php
                                        $clientId = (int)$client['id'];
                                        $isPrimaryClient = (int)($project['client_id'] ?? 0) === $clientId;
                                    ?>
                                    <div class="project-contact-card <?php echo $isPrimaryClient ? 'is-primary' : ''; ?>">
                                        <input type="checkbox" name="project_client_ids[]" value="<?php echo $clientId; ?>" checked style="margin-top:3px" title="Uncheck to remove from this project">
                                        <div>
                                            <div class="project-contact-heading">
                                                <div>
                                                    <div style="font-weight:700"><?php echo htmlspecialchars($client['name']); ?></div>
                                                    <div style="font-size:12px;color:var(--muted)"><?php echo !empty($client['email']) ? htmlspecialchars($client['email']) : 'No email address'; ?></div>
                                                </div>
                                                <?php if ($isPrimaryClient || !empty($client['is_primary_billing'])): ?>
                                                    <span class="project-pill">Primary</span>
                                                <?php endif; ?>
                                                <?php if (in_array($clientId, $projectDepartmentContactIds, true)): ?>
                                                    <span class="project-pill">Department</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="project-contact-options">
                                                <label class="project-check">
                                                    <input type="radio" name="client_id" value="<?php echo $clientId; ?>" <?php echo $isPrimaryClient ? 'checked' : ''; ?>>
                                                    <span>Primary billed contact</span>
                                                </label>
                                                <label class="project-check">
                                                    <input type="checkbox" name="project_invoice_email_client_ids[]" value="<?php echo $clientId; ?>" <?php echo in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 'checked' : ''; ?> <?php echo empty($client['email']) ? 'disabled' : ''; ?>>
                                                    <span>Receives project invoice emails<?php echo empty($client['email']) ? ' (no email saved)' : ''; ?></span>
                                                </label>
                                                <label class="project-check">
                                                    <input type="checkbox" name="project_invoice_link_client_ids[]" value="<?php echo $clientId; ?>" <?php echo in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 'checked' : ''; ?>>
                                                    <span>Can view invoice content links</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <label class="project-field" data-legacy-project-contact-ui style="display:none">
                            <span>Add contacts from this project’s scope</span>
                            <?php if (empty($allClients) || count($allClients) === count($selectedProjectClientIds)): ?>
                                <div class="project-muted" style="padding:10px;border:1px dashed #d1d5db;border-radius:8px">No additional contacts are available in this scope.</div>
                            <?php else: ?>
                            <div class="project-add-contact-list">
                                <?php foreach ($allClients as $client): ?>
                                    <?php if (in_array((int)$client['id'], $selectedProjectClientIds, true)) { continue; } ?>
                                    <label class="project-check">
                                        <input type="checkbox" name="project_client_ids[]" value="<?php echo (int)$client['id']; ?>">
                                        <span>
                                            <?php echo htmlspecialchars($client['name'] . (!empty($client['email']) ? ' - ' . $client['email'] : '')); ?>
                                            <?php if (in_array((int)$client['id'], $projectDepartmentContactIds, true)): ?>
                                                <span class="project-pill" style="margin-left:4px">Department</span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <small>Checked contacts are added when you save. After they are attached, use their row above to enable invoice emails or content-link access.</small>
                        </label>
                    </section>

                    <section class="project-settings-card">
                        <h3>3. Invoice defaults</h3>
                        <p>Generated project invoices use the primary invoice receiver as the billed client. Emails only go to attached project contacts selected above as invoice recipients.</p>
                        <label class="project-field">
                            <span>Billing Period</span>
                            <?php $detailsBillingPeriod = ($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'monthly' : 'per_invoice'; ?>
                            <input type="hidden" name="invoice_billing_period" value="<?php echo htmlspecialchars($detailsBillingPeriod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <input type="hidden" name="invoice_billing_period_original" value="<?php echo htmlspecialchars($detailsBillingPeriod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <div class="project-info-box">
                                <?php echo $detailsBillingPeriod === 'monthly' ? 'Monthly project billing' : 'Each invoice on its own'; ?>
                                · <a href="/?page=project/projects-edit&amp;id=<?php echo $projectId; ?>#project-billing">Review or change billing</a>
                            </div>
                        </label>
                        <label class="project-check" style="padding:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fff">
                            <input type="checkbox" name="project_invoice_auto_email" value="1" <?php echo $autoEmailEnabled ? 'checked' : ''; ?>>
                            <span>
                                Automatically email monthly project invoices
                                <small style="display:block">Uses the selected project invoice email recipients after the monthly invoice is generated.</small>
                            </span>
                        </label>
                        <label class="project-field">
                            <span>Project NET Days</span>
                            <input type="number" min="0" step="1" name="invoice_net_terms_days" value="<?php echo htmlspecialchars((string)($project['invoice_net_terms_days'] ?? '')); ?>" placeholder="System default">
                            <small>Leave blank to use the system default due-date terms.</small>
                        </label>
                    </section>

                    <section class="project-settings-card">
                        <h3>4. Schedule &amp; notes</h3>
                        <div class="project-settings-grid">
                            <label class="project-field">
                                <span>Start</span>
                                <input type="date" name="estimated_start" value="<?php echo htmlspecialchars((string)($project['estimated_start'] ?? '')); ?>">
                            </label>
                            <label class="project-field">
                                <span>End</span>
                                <input type="date" name="estimated_end" value="<?php echo htmlspecialchars((string)($project['estimated_end'] ?? '')); ?>">
                            </label>
                        </div>
                        <label class="project-field">
                            <span>Notes</span>
                            <textarea name="notes" rows="3"><?php echo htmlspecialchars((string)($project['notes'] ?? '')); ?></textarea>
                        </label>
                    </section>

                    <button type="submit" class="btn btn-sm project-settings-save">Save Project Settings</button>
                </form>
            </div>

            <!-- Attach Existing Documents -->
            <div class="project-panel">
                <div class="project-sidebar-title" style="margin-bottom:6px">Add Existing Document</div>
                <div style="font-size:13px;color:var(--muted);margin-bottom:12px">Available unassigned documents for this client or organization.</div>
                <div class="grid">
                    <?php $renderDocumentAttachForm('quote', 'Quote', $availableQuotes); ?>
                    <?php $renderDocumentAttachForm('contract', 'Contract', $availableContracts); ?>
                    <?php $renderDocumentAttachForm('invoice', 'Invoice', $availableInvoices); ?>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="project-panel project-danger">
                <div class="project-sidebar-title">Danger Zone</div>
                <form method="post" action="/?page=project/projects-delete" onsubmit="return confirm('Are you sure you want to delete this project?');" style="margin:0">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="redirect" value="/?page=project/projects-list">
                    <button type="submit" style="width:100%;padding:10px;border-radius:6px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;font-weight:600;cursor:pointer">
                        🗑️ Delete Project
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
  function initProjectSettingsContactManager() {
    var root = document.querySelector('[data-project-settings-contact-manager]');
    if (!root || root.dataset.ready === '1') return;
    root.dataset.ready = '1';

    document.querySelectorAll('[data-legacy-project-contact-ui] input, [data-legacy-project-contact-ui] select, [data-legacy-project-contact-ui] textarea').forEach(function (control) {
      control.disabled = true;
    });

    var dataNode = root.querySelector('[data-project-settings-clients]');
    var clients = [];
    try {
      clients = JSON.parse(dataNode ? dataNode.textContent : '[]');
    } catch (e) {
      clients = [];
    }
    clients = clients.map(function (client) {
      client.id = String(client.id);
      client.email = client.email || '';
      return client;
    });

    var selected = {
      project: new Set(clients.filter(function (client) { return Number(client.is_selected || 0) === 1; }).map(function (client) { return client.id; })),
      invoice: new Set(clients.filter(function (client) { return Number(client.is_invoice_recipient || 0) === 1; }).map(function (client) { return client.id; })),
      links: new Set(clients.filter(function (client) { return Number(client.can_view_links || 0) === 1; }).map(function (client) { return client.id; }))
    };
    var primaryId = (clients.find(function (client) { return Number(client.is_primary || 0) === 1; }) || {}).id || '';

    function escapeHtml(value) {
      var div = document.createElement('div');
      div.textContent = value == null ? '' : String(value);
      return div.innerHTML;
    }

    function picker(type) {
      var pickerRoot = root.querySelector('[data-project-settings-picker="' + type + '"]');
      if (!pickerRoot) return null;
      return {
        root: pickerRoot,
        selected: pickerRoot.querySelector('[data-picker-selected]'),
        search: pickerRoot.querySelector('[data-picker-search]'),
        suggestions: pickerRoot.querySelector('[data-picker-suggestions]'),
        hidden: pickerRoot.querySelector('[data-picker-hidden]')
      };
    }

    function clientById(id) {
      id = String(id);
      return clients.find(function (client) { return client.id === id; }) || null;
    }

    function inputName(type) {
      if (type === 'invoice') return 'project_invoice_email_client_ids[]';
      if (type === 'links') return 'project_invoice_link_client_ids[]';
      return 'project_client_ids[]';
    }

    function renderPrimarySelect() {
      var select = root.querySelector('[data-project-primary-select]');
      if (!select) return;
      var ids = Array.from(selected.project).filter(function (id) { return clientById(id); });
      if (primaryId && ids.indexOf(primaryId) === -1) {
        primaryId = ids[0] || '';
      }
      select.innerHTML = '';
      if (!ids.length) {
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'No project contacts selected';
        select.appendChild(empty);
        return;
      }
      ids.forEach(function (id) {
        var client = clientById(id);
        var option = document.createElement('option');
        option.value = id;
        option.textContent = client.name + (client.email ? ' - ' + client.email : '');
        if (id === primaryId) option.selected = true;
        select.appendChild(option);
      });
    }

    function render(type) {
      var p = picker(type);
      if (!p) return;
      var ids = Array.from(selected[type]).filter(function (id) { return clientById(id); });
      selected[type] = new Set(ids);
      p.selected.innerHTML = '';
      p.hidden.innerHTML = '';
      if (!ids.length) {
        p.selected.innerHTML = '<div class="project-settings-picker__empty">' + escapeHtml(p.root.dataset.emptyText || 'No clients selected.') + '</div>';
      }
      ids.forEach(function (id) {
        var client = clientById(id);
        var row = document.createElement('div');
        row.className = 'project-settings-picker__item';
        row.innerHTML =
          '<span><span class="project-settings-picker__name">' + escapeHtml(client.name) + '</span>' +
          (Number(client.is_department_contact || 0) === 1 ? '<span class="project-pill" style="margin-left:6px">Department</span>' : '') +
          (client.email ? '<span class="project-settings-picker__meta">' + escapeHtml(client.email) + '</span>' : '<span class="project-settings-picker__meta">No email address</span>') +
          '</span><button type="button" class="project-settings-picker__remove" data-remove-id="' + escapeHtml(id) + '" aria-label="Remove ' + escapeHtml(client.name) + '">x</button>';
        p.selected.appendChild(row);

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = inputName(type);
        hidden.value = id;
        p.hidden.appendChild(hidden);
      });
      p.selected.querySelectorAll('[data-remove-id]').forEach(function (button) {
        button.addEventListener('click', function () {
          var id = String(button.getAttribute('data-remove-id') || '');
          selected[type].delete(id);
          if (type === 'project') {
            selected.invoice.delete(id);
            selected.links.delete(id);
            if (primaryId === id) primaryId = '';
          }
          renderAll();
        });
      });
    }

    function renderAll() {
      render('project');
      render('invoice');
      render('links');
      renderPrimarySelect();
    }

    function renderSuggestions(type) {
      var p = picker(type);
      if (!p) return;
      var query = (p.search.value || '').trim().toLowerCase();
      p.suggestions.innerHTML = '';
      if (!query) {
        p.suggestions.style.display = 'none';
        return;
      }
      var matches = clients.filter(function (client) {
        if (selected[type].has(client.id)) return false;
        if (type === 'invoice' && !client.email) return false;
        return (client.name + ' ' + client.email).toLowerCase().indexOf(query) !== -1;
      }).slice(0, 12);
      if (!matches.length) {
        p.suggestions.innerHTML = '<div class="project-settings-picker__suggestion" style="color:var(--muted)">No matching clients</div>';
        p.suggestions.style.display = 'block';
        return;
      }
      matches.forEach(function (client) {
        var option = document.createElement('div');
        option.className = 'project-settings-picker__suggestion';
        option.setAttribute('data-client-id', client.id);
        option.innerHTML =
          '<strong>' + escapeHtml(client.name) + '</strong>' +
          (Number(client.is_department_contact || 0) === 1 ? '<span class="project-pill" style="margin-left:6px">Department</span>' : '') +
          '<span class="project-settings-picker__meta">' + escapeHtml(client.email || 'No email address') + '</span>';
        p.suggestions.appendChild(option);
      });
      p.suggestions.style.display = 'block';
    }

    function add(type, id) {
      id = String(id);
      if (!clientById(id)) return;
      selected[type].add(id);
      if (type === 'invoice' || type === 'links') {
        selected.project.add(id);
      }
      if (!primaryId && selected.project.has(id)) {
        primaryId = id;
      }
      renderAll();
    }

    ['project', 'invoice', 'links'].forEach(function (type) {
      var p = picker(type);
      if (!p) return;
      p.search.addEventListener('input', function () { renderSuggestions(type); });
      p.search.addEventListener('focus', function () { renderSuggestions(type); });
      p.suggestions.addEventListener('click', function (event) {
        var option = event.target.closest('[data-client-id]');
        if (!option) return;
        add(type, option.getAttribute('data-client-id'));
        p.search.value = '';
        p.suggestions.style.display = 'none';
      });
    });

    var primarySelect = root.querySelector('[data-project-primary-select]');
    if (primarySelect) {
      primarySelect.addEventListener('change', function () {
        primaryId = primarySelect.value || '';
      });
    }

    document.addEventListener('click', function (event) {
      ['project', 'invoice', 'links'].forEach(function (type) {
        var p = picker(type);
        if (p && !p.root.contains(event.target)) {
          p.suggestions.style.display = 'none';
        }
      });
    });

    renderAll();
  }

  initProjectSettingsContactManager();
  document.addEventListener('pageLoaded', initProjectSettingsContactManager);
})();
</script>
<script>
(function(){function initProjectTeamSearch(){document.querySelectorAll('#team-work select[name="user_id"]').forEach(function(select){if(select.dataset.searchReady)return;select.dataset.searchReady='1';var search=document.createElement('input');search.type='search';search.placeholder='Type a name or email to filter';search.setAttribute('aria-label','Search Project Team users');select.parentNode.insertBefore(search,select);search.addEventListener('input',function(){var term=search.value.trim().toLowerCase();Array.from(select.options).forEach(function(option,index){option.hidden=index>0&&term!==''&&!option.text.toLowerCase().includes(term);});if(select.selectedOptions[0]&&select.selectedOptions[0].hidden)select.value='';});});}initProjectTeamSearch();document.addEventListener('pageLoaded',initProjectTeamSearch);})();
</script>
