<?php
declare(strict_types=1);

function api_v2_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function api_v2_identity_is_valid(array $identity): bool
{
    foreach (['source_instance_id', 'application_id', 'history_epoch'] as $field) {
        if (!isset($identity[$field]) || !is_string($identity[$field])
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $identity[$field]) !== 1) {
            return false;
        }
    }
    return true;
}

function api_v2_enabled(string $name): bool
{
    return filter_var(getenv($name) ?: 'false', FILTER_VALIDATE_BOOLEAN);
}

/**
 * Advertise only routes enabled on this installation. Capability metadata is
 * discovery, not a grant: the key's explicit scopes are listed separately.
 */
function api_v2_capabilities_payload(array $identity, string $requestId, array $keyScopes = [], array $features = []): array
{
    $granted = [['name' => 'api.capabilities.read']];
    $endpoints = [[
        'method' => 'GET',
        'path' => '/api/v2/capabilities',
        'requiredCapability' => 'api.capabilities.read',
    ]];
    $definitions = [
        'directory_read' => [
            ['directory.clients.read', '/api/v2/directory/clients/{publicId}'],
            ['directory.organizations.read', '/api/v2/directory/organizations/{publicId}'],
        ],
        'binding_status' => [
            ['directory.clients.binding_status.read', '/api/v2/bindings/client/status/{base64urlExternalId}'],
            ['directory.organizations.binding_status.read', '/api/v2/bindings/organization/status/{base64urlExternalId}'],
        ],
        'directory_binding' => [
            ['directory.clients.bind', '/api/v2/directory/clients/bindings/commands', 'POST'],
            ['directory.organizations.bind', '/api/v2/directory/organizations/bindings/commands', 'POST'],
        ],
        'directory_binding_refresh' => [
            ['directory.clients.binding.revision.refresh', '/api/v2/directory/clients/bindings/revisions/commands', 'POST'],
            ['directory.organizations.binding.revision.refresh', '/api/v2/directory/organizations/bindings/revisions/commands', 'POST'],
        ],
        'directory_organization_write' => [
            ['directory.organizations.write', '/api/v2/directory/organizations/{publicId}/profile/commands', 'POST'],
        ],
        'directory_client_write' => [
            ['directory.clients.write', '/api/v2/directory/clients/{publicId}/profile/commands', 'POST'],
        ],
    ];
    foreach ($definitions as $feature => $routes) {
        if (($features[$feature] ?? false) !== true) continue;
        foreach ($routes as $route) {
            [$scope, $path] = $route;
            $endpoints[] = [
                'method' => $route[2] ?? 'GET', 'path' => $path, 'requiredCapability' => $scope,
                'requiresSourceInstanceId' => true, 'requiresApplicationId' => true,
                'requiresHistoryEpoch' => true,
            ];
            if (in_array($scope, $keyScopes, true)) $granted[] = ['name' => $scope];
        }
    }
    return [
        'apiVersion' => '2',
        'sourceInstanceId' => (string)$identity['source_instance_id'],
        'applicationId' => (string)$identity['application_id'],
        'historyEpoch' => (string)$identity['history_epoch'],
        'requestId' => $requestId,
        'grantedCapabilities' => $granted,
        'implementedEndpoints' => $endpoints,
    ];
}
