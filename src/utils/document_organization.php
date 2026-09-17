<?php

declare(strict_types=1);

/**
 * A project is the authoritative customer context for every document attached
 * to it. Standalone documents retain their saved organization snapshot, with
 * the client's current organization used only as a legacy fallback.
 */
function pa_document_effective_organization_joins(
    string $documentAlias,
    string $clientAlias,
    string $projectAlias = 'document_project',
    string $organizationAlias = 'o'
): string {
    foreach ([$documentAlias, $clientAlias, $projectAlias, $organizationAlias] as $alias) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid SQL alias for document organization resolution.');
        }
    }

    return " LEFT JOIN projects {$projectAlias} ON {$projectAlias}.id={$documentAlias}.project_id"
        . " LEFT JOIN organizations {$organizationAlias} ON {$organizationAlias}.id="
        . "COALESCE({$projectAlias}.organization_id,{$documentAlias}.organization_id,{$clientAlias}.organization_id)";
}

function pa_document_effective_organization_id(PDO $pdo, string $documentType, int $documentId): ?int
{
    $documents = [
        'quote' => ['table' => 'quotes', 'client_column' => 'client_id'],
        'contract' => ['table' => 'contracts', 'client_column' => 'client_id'],
        'invoice' => ['table' => 'invoices', 'client_column' => 'client_id'],
        'project_invoice' => ['table' => 'project_invoices', 'client_column' => 'primary_client_id'],
    ];
    if (!isset($documents[$documentType]) || $documentId <= 0) {
        return null;
    }

    $definition = $documents[$documentType];
    $table = $definition['table'];
    $clientColumn = $definition['client_column'];
    $statement = $pdo->prepare(
        "SELECT COALESCE(p.organization_id,d.organization_id,c.organization_id)"
        . " FROM {$table} d"
        . " LEFT JOIN projects p ON p.id=d.project_id"
        . " LEFT JOIN clients c ON c.id=d.{$clientColumn}"
        . ' WHERE d.id=? LIMIT 1'
    );
    $statement->execute([$documentId]);
    $organizationId = (int)($statement->fetchColumn() ?: 0);
    return $organizationId > 0 ? $organizationId : null;
}
