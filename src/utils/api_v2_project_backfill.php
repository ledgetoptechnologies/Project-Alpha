<?php

declare(strict_types=1);

use App\Services\ProjectRevisionService;
use App\Services\ProjectPresentationService;

require_once __DIR__ . '/../services/ProjectRevisionService.php';
require_once __DIR__ . '/../services/ProjectPresentationService.php';
require_once __DIR__ . '/../services/ManagedDeliveryService.php';

/** @return array{dryRun:bool,scanned:int,inserted:int,skippedCurrent:int,presentationRevoked:int,nextCursor:?string} */
function api_v2_project_backfill(PDO $pdo, ?string $cursor, int $limit, bool $dryRun): array
{
    if ($limit < 1 || $limit > 500 || ($cursor !== null && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $cursor) !== 1) || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Invalid Project backfill request.');
    }
    $after = (int)($cursor ?? 0);
    $statement = $pdo->prepare('SELECT id FROM projects WHERE id>? ORDER BY id LIMIT ' . ($limit + 1));
    $statement->execute([$after]);
    $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $hasMore = count($ids) > $limit;
    if ($hasMore) array_pop($ids);
    $inserted = 0; $current = 0; $presentationRevoked = 0;
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            $service = new ProjectRevisionService($pdo);
            $project = $service->lockedProject($id);
            if (($project['archived_at'] ?? null) !== null) {
                $presentation = (new ProjectPresentationService($pdo))->revokeForArchive($project, 0, $dryRun);
                if ($presentation['changed']) $presentationRevoked++;
                if (!$dryRun && $presentation['localDisabled']) $project = $service->lockedProject($id);
            }
            $existing = $pdo->prepare('SELECT projection_sha256 FROM project_changes WHERE project_public_id=? AND revision=?');
            $existing->execute([$project['public_id'],$project['revision']]);
            $hash = $existing->fetchColumn();
            if ($hash !== false) {
                if (!is_string($hash) || !hash_equals($hash, ProjectRevisionService::projectionHash($project))) {
                    throw new RuntimeException('Project history drift detected at id ' . $id . '.');
                }
                $current++;
            } else {
                if ((string)$project['revision'] !== '1') throw new RuntimeException('Project history gap detected at id ' . $id . '.');
                if (!$dryRun) $service->initialize($id, 'baseline');
                $inserted++;
            }
            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    return ['dryRun'=>$dryRun,'scanned'=>count($ids),'inserted'=>$inserted,'skippedCurrent'=>$current,'presentationRevoked'=>$presentationRevoked,
        'nextCursor'=>$hasMore && $ids !== [] ? (string)end($ids) : null];
}
