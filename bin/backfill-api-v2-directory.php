#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/migrations/migration_lib.php';
require_once __DIR__ . '/../src/utils/api_v2_directory_backfill.php';
require_once __DIR__ . '/../src/utils/api_v2_directory_release_safety.php';

$type = 'all'; $cursor = null; $limit = 100; $apply = false; $dryRunRequested = false; $confirmed = false; $maintenanceConfirmed = false; $attest = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--type=')) $type = substr($argument, 7);
    elseif (str_starts_with($argument, '--cursor=')) $cursor = substr($argument, 9);
    elseif (str_starts_with($argument, '--limit=')) $limit = substr($argument, 8);
    elseif ($argument === '--dry-run') $dryRunRequested = true;
    elseif ($argument === '--apply') $apply = true;
    elseif ($argument === '--confirm-api-v2-directory-backfill') $confirmed = true;
    elseif ($argument === '--maintenance-window-confirmed') $maintenanceConfirmed = true;
    elseif ($argument === '--attest') $attest = true;
    else { fwrite(STDERR, "Unknown option.\n"); exit(2); }
}
if (!in_array($type, ['all', 'client', 'organization'], true) || !is_string($limit) || preg_match('/^[1-9][0-9]{0,2}$/D', $limit) !== 1
    || (int)$limit > 500 || ($apply && ($dryRunRequested || !$confirmed || !$maintenanceConfirmed))) {
    fwrite(STDERR, "Usage: php bin/backfill-api-v2-directory.php [--type=all|client|organization] [--cursor=type:local-id] [--limit=1..500] [--dry-run] [--attest]\n");
    fwrite(STDERR, "Default is dry-run. Apply requires --apply --confirm-api-v2-directory-backfill --maintenance-window-confirmed.\n");
    exit(2);
}
try {
    $result = api_v2_directory_backfill(migration_connection(), $type, $cursor, (int)$limit, !$apply);
    $mode = $result['dryRun'] ? 'Dry run' : 'Applied';
    fwrite(STDOUT, $mode . ': scanned ' . $result['scanned'] . '; inserted ' . $result['inserted'] . '; current ' . $result['skippedCurrent'] . ".\n");
    if ($result['nextCursor'] !== null) fwrite(STDOUT, 'Resume cursor: ' . $result['nextCursor'] . ".\n");
} catch (PDOException) {
    fwrite(STDERR, "Directory backfill refused due to an internal database error.\n"); exit(1);
} catch (InvalidArgumentException|RuntimeException $error) {
    fwrite(STDERR, "Directory backfill refused: " . $error->getMessage() . "\n"); exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Directory backfill refused due to an internal database error.\n"); exit(1);
}
if ($attest) {
    try {
        $digest = api_v2_directory_backfill_attestation_persist(migration_connection());
        fwrite(STDOUT, 'Attestation: ' . $digest . ".\n");
    } catch (PDOException) {
        fwrite(STDERR, "Directory attestation refused due to an internal database error.\n"); exit(1);
    } catch (InvalidArgumentException|RuntimeException|LogicException $error) {
        fwrite(STDERR, 'Directory attestation refused: ' . $error->getMessage() . "\n"); exit(1);
    } catch (Throwable $error) {
        fwrite(STDERR, "Directory attestation refused due to an internal error.\n"); exit(1);
    }
}
