<?php
declare(strict_types=1);

require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/autopay_enrollment.php';
require_once __DIR__ . '/audit.php';

/** Client ownership and billing permissions are both required. */
function pa_autopay_staff_can_manage_client(PDO $pdo, int $clientId, int $actorId): bool
{
    if ($clientId <= 0 || $actorId <= 0) return false;
    $statement = $pdo->prepare('SELECT organization_id FROM clients WHERE id=? AND archived=0');
    $statement->execute([$clientId]);
    $client = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$client) return false;
    $organizationId = (int)($client['organization_id'] ?? 0);
    return user_can($pdo, $actorId, 'clients.edit', $organizationId)
        && user_can($pdo, $actorId, 'invoices.send', $organizationId)
        && can_access_record($pdo, 'clients', $clientId, $actorId);
}

/** Safe summary for the client details screen; no processor or management secrets. */
function pa_autopay_staff_client_authorizations(PDO $pdo, int $clientId, array $config): array
{
    if (!pa_autopay_test_context_enabled($config)) return [];
    $statement = $pdo->prepare('SELECT id,scope,contract_id,status,method_type FROM autopay_enrollments
        WHERE client_id=? AND installation_id=? AND processor_account_id=? AND livemode=0
        AND status IN ("pending","active") ORDER BY id DESC');
    $statement->execute([$clientId, getenv('AUTOPAY_INSTALLATION_ID'), getenv('AUTOPAY_STRIPE_ACCOUNT_ID')]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function pa_autopay_staff_disable_client(PDO $pdo, int $clientId, int $actorId, array $config): bool
{
    if (!pa_autopay_test_context_enabled($config)) throw new DomainException('AutoPay management is unavailable.');
    if (!pa_autopay_staff_can_manage_client($pdo, $clientId, $actorId)) throw new DomainException('Client and billing management permission required.');
    $statement = $pdo->prepare('SELECT id,email FROM clients WHERE id=?');
    $statement->execute([$clientId]);
    $client = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new DomainException('Client not found.');
    $changed = pa_autopay_revoke_authorization($pdo, $client, 0);
    if ($changed) audit_log($pdo, 'client.autopay_disabled', 'client', $clientId, ['scope' => 'all_future_collections'], $actorId);
    return $changed;
}
