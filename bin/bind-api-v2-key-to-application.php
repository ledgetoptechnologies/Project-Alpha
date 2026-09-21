#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/migrations/migration_lib.php';
require_once __DIR__ . '/../src/utils/api_v2_application_provisioning.php';

$keyId = null; $applicationId = null; $rebindFromApplicationId = null;
$dryRun = false; $apply = false; $confirmed = false; $rebindConfirmed = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--api-key-id=')) $keyId = substr($argument, 13);
    elseif (str_starts_with($argument, '--application-id=')) $applicationId = substr($argument, 17);
    elseif (str_starts_with($argument, '--rebind-from-application-id=')) $rebindFromApplicationId = substr($argument, 29);
    elseif ($argument === '--dry-run') $dryRun = true;
    elseif ($argument === '--apply') $apply = true;
    elseif ($argument === '--confirm-bind-existing-api-v2-application') $confirmed = true;
    elseif ($argument === '--confirm-rebind-api-v2-application') $rebindConfirmed = true;
    else { fwrite(STDERR, "Unknown option.\n"); exit(2); }
}
$validKeyId = is_string($keyId) && preg_match('/^[1-9][0-9]*$/D', $keyId) === 1 && strlen($keyId) <= 18;
$validApplicationId = is_string($applicationId) && api_v2_application_provision_uuid_v4_valid($applicationId);
$validRebind = $rebindFromApplicationId === null || (is_string($rebindFromApplicationId) && api_v2_application_provision_uuid_v4_valid($rebindFromApplicationId));
if (!$validKeyId || !$validApplicationId || !$validRebind || ($dryRun === $apply) || ($apply && !$confirmed)
    || ($rebindConfirmed && $rebindFromApplicationId === null)) {
    fwrite(STDERR, "Usage: php bin/bind-api-v2-key-to-application.php --api-key-id=<non-secret-id> --application-id=<public-uuid> --dry-run\n");
    fwrite(STDERR, "Apply only after reviewing the dry run: add --apply --confirm-bind-existing-api-v2-application.\n");
    fwrite(STDERR, "To rebind, also select --rebind-from-application-id=<public-uuid> and add --confirm-rebind-api-v2-application on apply.\n");
    exit(2);
}
try {
    $result = api_v2_application_bind_existing(
        migration_connection(), (int)$keyId, $applicationId, $dryRun, $rebindFromApplicationId, $rebindConfirmed
    );
    if ($result['alreadyBound']) fwrite(STDOUT, "No change: API key {$result['apiKeyId']} is already bound to API v2 application {$result['applicationId']}.\n");
    elseif ($result['dryRun']) fwrite(STDOUT, "Dry run passed: API key {$result['apiKeyId']} can be bound to API v2 application {$result['applicationId']}.\n");
    elseif ($result['rebound']) fwrite(STDOUT, "Rebound API key {$result['apiKeyId']} to API v2 application {$result['applicationId']}; affected authorization generations advanced.\n");
    else fwrite(STDOUT, "Bound API key {$result['apiKeyId']} to existing API v2 application {$result['applicationId']}.\n");
    exit(0);
} catch (PDOException) {
    fwrite(STDERR, "Binding refused due to an internal database error.\n");
    exit(1);
} catch (InvalidArgumentException|RuntimeException $error) {
    fwrite(STDERR, "Binding refused: {$error->getMessage()}\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Binding refused due to an internal database error.\n");
    exit(1);
}
