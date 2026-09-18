<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_directory_revision.php';

/** @return array<string,mixed>|null */
function api_v2_directory_read(PDO $pdo, string $type, string $publicId, int $keyId, array $headers, string $requestId): ?array
{
    if (!in_array($type, ['client', 'organization'], true)
        || preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1
        || $keyId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Invalid directory read');
    }
    $pdo->beginTransaction();
    try {
        // Lock current rows on MySQL so a concurrent profile or authority edit
        // cannot turn a snapshot-consistent but already stale read into a 200.
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $binding = $pdo->prepare(
            'SELECT history.source_instance_id,history.history_epoch,app.application_id,app.id AS application_pk,
                    authorization.authorization_generation
             FROM api_keys AS api_key
             INNER JOIN api_v2_applications AS app ON app.id=api_key.api_v2_application_id
             INNER JOIN api_v2_history_identity AS history ON history.singleton=1
             INNER JOIN api_v2_directory_authorization_state AS authorization ON authorization.application_pk=app.id
             WHERE api_key.id=? AND api_key.revoked_at IS NULL' . $lock
        );
        $binding->execute([$keyId]);
        $identity = $binding->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !api_v2_identity_is_valid($identity)
            || ($headers['source'] ?? null) !== $identity['source_instance_id']
            || ($headers['application'] ?? null) !== $identity['application_id']
            || ($headers['epoch'] ?? null) !== $identity['history_epoch']
            || !preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string)$identity['authorization_generation'])
            || (strlen((string)$identity['authorization_generation']) === 19
                && strcmp((string)$identity['authorization_generation'], '9223372036854775807') > 0)) {
            $pdo->rollBack();
            return null;
        }
        $table = $type === 'client' ? 'clients' : 'organizations';
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE public_id=?' . $lock);
        $stmt->execute([$publicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->rollBack(); return null; }
        $state = $pdo->prepare('SELECT revision,projection_sha256,present FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?' . $lock);
        $state->execute([$type, $publicId]);
        $version = $state->fetch(PDO::FETCH_ASSOC);
        if (!$version || (int)$version['present'] !== 1
            || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)$version['revision'])) {
            $pdo->rollBack();
            return null;
        }
        $hash = api_v2_directory_projection_hash($type, $row);
        if (!hash_equals((string)$version['projection_sha256'], $hash)) {
            $pdo->rollBack();
            return null;
        }
        $address = [
            'line1' => $row['address_line1'] ?? null,
            'line2' => $row['address_line2'] ?? null,
            'city' => $row['city'] ?? null,
            'state' => $row['state'] ?? null,
            'postalCode' => $row['postal_code'] ?? null,
            'country' => $row['country'] ?? null,
        ];
        $data = [
            'publicId' => $publicId,
            'name' => (string)$row['name'],
            'email' => $type === 'client' ? ($row['email'] ?? null) : ($row['general_email'] ?? null),
            'phone' => $type === 'client' ? ($row['phone'] ?? null) : ($row['general_phone'] ?? null),
            'address' => $address,
        ];
        if ($type === 'client') {
            $clientType = (string)($row['client_type'] ?? 'unknown');
            if (!in_array($clientType, ['unknown', 'business', 'consumer'], true)) {
                $pdo->rollBack(); return null;
            }
            $organizationPublicId = null;
            if ($row['organization_id'] !== null) {
                $org = $pdo->prepare('SELECT public_id FROM organizations WHERE id=?');
                $org->execute([$row['organization_id']]);
                $organizationPublicId = $org->fetchColumn();
                if (!is_string($organizationPublicId) || preg_match('/^[0-9a-f]{32}$/D', $organizationPublicId) !== 1) {
                    $pdo->rollBack(); return null;
                }
            }
            $data['clientType'] = $clientType;
            $data['organizationPublicId'] = $organizationPublicId;
        }
        $result = [
            'apiVersion' => '2',
            'sourceInstanceId' => $identity['source_instance_id'],
            'applicationId' => $identity['application_id'],
            'historyEpoch' => $identity['history_epoch'],
            'requestId' => $requestId,
            'authorizationGeneration' => (string)$identity['authorization_generation'],
            'resource' => ['type' => $type, 'id' => $publicId, 'revision' => (string)$version['revision']],
            'data' => $data,
        ];
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
