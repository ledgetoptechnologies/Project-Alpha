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
