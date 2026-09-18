#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/migrations/migration_lib.php';
require_once __DIR__ . '/../src/utils/api_v2_application_provisioning.php';

$keyId = null; $name = null; $dryRun = false; $apply = false; $confirmed = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--api-key-id=')) $keyId = substr($argument, 13);
    elseif (str_starts_with($argument, '--name=')) $name = substr($argument, 7);
    elseif ($argument === '--dry-run') $dryRun = true;
    elseif ($argument === '--apply') $apply = true;
    elseif ($argument === '--confirm-bind-api-v2-application') $confirmed = true;
    else { fwrite(STDERR, "Unknown option.\n"); exit(2); }
}
if (!is_string($keyId) || preg_match('/^[1-9][0-9]*$/D', $keyId) !== 1 || strlen($keyId) > 18 || !is_string($name)
    || ($dryRun === $apply) || ($apply && !$confirmed)) {
    fwrite(STDERR, "Usage: php bin/provision-api-v2-application.php --api-key-id=<non-secret-id> --name=<application-name> --dry-run\n");
    fwrite(STDERR, "Apply only after reviewing the dry run: add --apply --confirm-bind-api-v2-application.\n");
    exit(2);
}
try {
    $result = api_v2_application_provision(migration_connection(), (int)$keyId, $name, $dryRun);
    if ($result['dryRun'] && !($result['alreadyProvisioned'] ?? false)) fwrite(STDOUT, "Dry run passed: API key {$result['apiKeyId']} is eligible; no changes were made.\n");
    elseif ($result['alreadyProvisioned'] ?? false) fwrite(STDOUT, "No change: API v2 application {$result['applicationId']} is already bound to API key {$result['apiKeyId']}.\n");
    else fwrite(STDOUT, "Provisioned API v2 application {$result['applicationId']} bound to API key {$result['apiKeyId']}.\n");
    exit(0);
} catch (PDOException) {
    fwrite(STDERR, "Provisioning refused due to an internal database error.\n");
    exit(1);
} catch (InvalidArgumentException|RuntimeException $error) {
    fwrite(STDERR, "Provisioning refused: {$error->getMessage()}\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Provisioning refused due to an internal database error.\n");
    exit(1);
}
