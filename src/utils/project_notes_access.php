<?php

declare(strict_types=1);

require_once __DIR__ . '/acl.php';

function project_notes_authorized_job(PDO $pdo, string $projectCode, int $actorId, int $clientId = 0): ?array
{
    if ($actorId <= 0 || $projectCode === '') {
        return null;
    }
    // project_meta is keyed by code alone, although jobs permit the same code
    // under different clients. Deny ambiguous codes to avoid shared notes.
    $stmt = $pdo->prepare('SELECT id, client_id, project_id, created_by, archived FROM jobs WHERE job_code = ? LIMIT 2');
    $stmt->execute([$projectCode]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($jobs) !== 1 || (int)$jobs[0]['archived'] !== 0 || (int)$jobs[0]['client_id'] <= 0
        || ($clientId > 0 && (int)$jobs[0]['client_id'] !== $clientId)) {
        return null;
    }
    if (!acl_user_has_org_wide_scope($pdo, $actorId, 0)
        && (int)$jobs[0]['created_by'] !== $actorId) {
        return null;
    }
    return $jobs[0];
}
