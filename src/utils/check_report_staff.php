<?php
declare(strict_types=1);
require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/document_organization.php';

function pa_check_report_staff_access(PDO $pdo, string $type, int $documentId, int $actor, string $permission = 'invoices.view'): bool
{
    if ($actor <= 0 || $documentId <= 0 || !in_array($type, ['invoice','project_invoice'], true)) return false;
    if ($type === 'invoice') {
        if (!can_access_record($pdo, 'invoices', $documentId, $actor)) return false;
    } else {
        $statement = $pdo->prepare('SELECT project_id FROM project_invoices WHERE id=?');
        $statement->execute([$documentId]); $projectId = (int)$statement->fetchColumn();
        if (!$projectId || !can_access_record($pdo, 'projects', $projectId, $actor)) return false;
    }
    return user_can($pdo, $actor, $permission, pa_document_effective_organization_id($pdo, $type, $documentId));
}

function pa_check_report_current_client(PDO $pdo, string $type, int $documentId): array
{
    if (!in_array($type, ['invoice','project_invoice'], true)) return [];
    $table = $type === 'invoice' ? 'invoices' : 'project_invoices';
    $clientColumn = $type === 'invoice' ? 'client_id' : 'primary_client_id';
    $statement = $pdo->prepare('SELECT c.public_id FROM ' . $table . ' d JOIN clients c ON c.id=d.' . $clientColumn . ' WHERE d.id=? AND c.archived=0');
    $statement->execute([$documentId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
