<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/billing_recipients.php';
require_once __DIR__ . '/../../utils/project_selection.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); throw new DomainException('POST required.'); }
    if (!csrf_validate()) { http_response_code(403); throw new DomainException('Reload the invoice form.'); }
    $actor = (int)($_SESSION['user']['id'] ?? 0);
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $invoice = [];
    if (!$actor) { http_response_code(403); throw new DomainException('Invoice permission required.'); }
    if ($invoiceId) {
        if (!can_access_record($pdo, 'invoices', $invoiceId, $actor)) { http_response_code(403); throw new DomainException('Invoice not available.'); }
        $statement = $pdo->prepare('SELECT client_id,project_id,organization_id FROM invoices WHERE id=?');
        $statement->execute([$invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$invoice) throw new DomainException('Invoice not available.');
    }
    $clientId = (int)($_POST['client_id'] ?? $invoice['client_id'] ?? 0);
    $projectId = (int)($_POST['project_id'] ?? $invoice['project_id'] ?? 0);
    if (!$clientId) { echo json_encode(['success'=>true,'recipients'=>[],'candidates'=>[],'reason'=>'Select a working contact to preview billing delivery.']); exit; }
    if (!can_access_record($pdo, 'clients', $clientId, $actor)) { http_response_code(403); throw new DomainException('Client not available.'); }
    $statement = $pdo->prepare('SELECT id,name,email,organization_id FROM clients WHERE id=? AND archived=0');
    $statement->execute([$clientId]); $client = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new DomainException('Client not available.');
    if ($projectId) {
        if (!can_access_record($pdo, 'projects', $projectId, $actor)) { http_response_code(403); throw new DomainException('Project not available.'); }
        $retainedProject = $invoice && (int)($invoice['project_id'] ?? 0) === $projectId && (int)$invoice['client_id'] === $clientId;
        if (!$retainedProject && !pa_project_is_active_for_client($pdo, $projectId, $clientId, $actor)) throw new DomainException('Select an active project for this client or organization.');
    }
    $organizationId = $invoice && (int)$invoice['client_id'] === $clientId ? (int)($invoice['organization_id'] ?? 0) : (int)($client['organization_id'] ?? 0);
    $mode = (string)($_POST['billing_recipient_mode'] ?? 'inherit');
    if (!in_array($mode, ['inherit','custom'], true) || !is_array($_POST['billing_recipient_ids'] ?? [])) throw new DomainException('Invalid billing recipient selection.');
    $resolution = pa_billing_preview($pdo, $clientId, $projectId ?: null, $organizationId ?: null,
        ['mode'=>$mode,'recipient_ids'=>$_POST['billing_recipient_ids'] ?? [],'copy_working_contact'=>!empty($_POST['billing_copy_working_contact'])]);
    $organizationId = (int)($resolution['organization_id'] ?? 0);
    if (!user_can($pdo, $actor, $invoiceId ? 'invoices.edit' : 'invoices.create', $organizationId ?: null)) { http_response_code(403); throw new DomainException('Invoice permission required.'); }
    $candidates = [];
    if ($organizationId) {
        if (!can_access_record($pdo, 'organizations', $organizationId, $actor)) { http_response_code(403); throw new DomainException('Organization not available.'); }
        $statement = $pdo->prepare('SELECT id,name,email FROM clients WHERE organization_id=? AND archived=0 ORDER BY name,id');
        $statement->execute([$organizationId]); $candidates = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement = $pdo->prepare('SELECT name FROM organizations WHERE id=?'); $statement->execute([$organizationId]); $organizationName = (string)$statement->fetchColumn();
    } else { $candidates = [$client]; $organizationName = ''; }
    echo json_encode(['success'=>true,'recipients'=>$resolution['recipients'],'reason'=>$resolution['reason'] ?? null,
        'source'=>$resolution['source'],'candidates'=>array_map(static fn($row)=>['id'=>(int)$row['id'],'name'=>(string)$row['name'],'email'=>(string)($row['email'] ?? '')], $candidates),
        'working_contact'=>(string)$client['name'],'organization_name'=>$organizationName], JSON_THROW_ON_ERROR);
} catch (DomainException | InvalidArgumentException $error) {
    if (http_response_code() < 400) http_response_code(409);
    echo json_encode(['success'=>false,'message'=>$error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500); echo json_encode(['success'=>false,'message'=>'Billing preview is unavailable. Reload before sending this invoice.']);
}
exit;
