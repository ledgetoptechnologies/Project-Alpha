<?php

declare(strict_types=1);

require_once __DIR__ . '/acl.php';

function upload_financial_file_accessible(PDO $pdo, string $category, string $filePath, int $actorId, int $organizationId = 0): bool
{
    if ($actorId <= 0 || !user_can($pdo, $actorId, 'financial.view', 0)) {
        return false;
    }
    if ($category === 'receipts') {
        $table = 'receipts';
        $alias = 'r';
    } elseif ($category === 'forms') {
        $table = 'form_documents';
        $alias = 'd';
    } else {
        return false;
    }
    [$scopeWhere, $scopeParams] = finance_scope_clause($pdo, $alias, $actorId, $organizationId, 'uploaded_by');
    $record = $pdo->prepare("SELECT {$alias}.id FROM {$table} {$alias} WHERE {$alias}.file_path = ? AND {$scopeWhere} LIMIT 1");
    $record->execute(array_merge([$filePath], $scopeParams));
    return $record->fetchColumn() !== false;
}

function upload_contract_file_accessible(PDO $pdo, string $category, string $relativePath, int $actorId): bool
{
    if ($actorId <= 0 || !user_can($pdo, $actorId, 'contracts.view', 0)) {
        return false;
    }
    if ($category === 'signed_contracts') {
        $query = 'SELECT c.id FROM contracts c WHERE c.signed_pdf_path = ?';
    } elseif ($category === 'contract_amendments') {
        $query = 'SELECT c.id FROM contract_amendments a JOIN contracts c ON c.id = a.contract_id WHERE a.signed_document_path = ?';
    } else {
        return false;
    }
    $params = ['/?page=serve-upload&file=' . rawurlencode($relativePath)];
    if (!acl_user_has_org_wide_scope($pdo, $actorId, 0)) {
        $query .= ' AND c.created_by = ?';
        $params[] = $actorId;
    }
    $record = $pdo->prepare($query . ' LIMIT 1');
    $record->execute($params);
    return $record->fetchColumn() !== false;
}
