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
    $configured = getenv($name);
    if ($configured !== false && $configured !== '') {
        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    // These endpoints are read-only and still require an application-bound,
    // explicitly scoped key. Commands stay opt-in by default.
    return in_array($name, [
        'APP_API_V2_DIRECTORY_READ_ENABLED',
        'APP_API_V2_BINDING_STATUS_ENABLED',
        'APP_API_V2_DIRECTORY_INVENTORY_ENABLED',
        'APP_API_V2_PROJECTS_READ_ENABLED',
        'APP_API_V2_PROJECTS_BINDING_STATUS_ENABLED',
        'APP_API_V2_PROJECTS_INVENTORY_ENABLED',
    ], true);
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
            ['directory.units.read', '/api/v2/directory/units/{publicId}'],
        ],
        'binding_status' => [
            ['directory.clients.binding_status.read', '/api/v2/bindings/client/status/{base64urlExternalId}'],
            ['directory.organizations.binding_status.read', '/api/v2/bindings/organization/status/{base64urlExternalId}'],
            ['directory.units.binding_status.read', '/api/v2/bindings/unit/status/{base64urlExternalId}'],
        ],
        'directory_binding' => [
            ['directory.clients.bind', '/api/v2/directory/clients/bindings/commands', 'POST'],
            ['directory.organizations.bind', '/api/v2/directory/organizations/bindings/commands', 'POST'],
            ['directory.units.bind', '/api/v2/directory/units/bindings/commands', 'POST'],
        ],
        'directory_binding_refresh' => [
            ['directory.clients.binding.revision.refresh', '/api/v2/directory/clients/bindings/revisions/commands', 'POST'],
            ['directory.organizations.binding.revision.refresh', '/api/v2/directory/organizations/bindings/revisions/commands', 'POST'],
            ['directory.units.binding.revision.refresh', '/api/v2/directory/units/bindings/revisions/commands', 'POST'],
        ],
        'directory_organization_write' => [
            ['directory.organizations.write', '/api/v2/directory/organizations/{publicId}/profile/commands', 'POST'],
        ],
        'directory_client_write' => [
            ['directory.clients.write', '/api/v2/directory/clients/{publicId}/profile/commands', 'POST'],
        ],
        'directory_unit_write' => [
            ['directory.units.write', '/api/v2/directory/units/{publicId}/profile/commands', 'POST'],
        ],
        'directory_organization_create' => [
            ['directory.organizations.create', '/api/v2/directory/organizations/commands', 'POST'],
        ],
        'directory_client_create' => [
            ['directory.clients.create', '/api/v2/directory/clients/commands', 'POST'],
        ],
        'directory_unit_create' => [
            ['directory.units.create', '/api/v2/directory/units/commands', 'POST'],
        ],
        'directory_client_archive' => [
            ['directory.clients.archive', '/api/v2/directory/clients/{publicId}/archive/commands', 'POST'],
        ],
        'directory_client_restore' => [
            ['directory.clients.restore', '/api/v2/directory/clients/{publicId}/restore/commands', 'POST'],
        ],
        'directory_organization_archive' => [
            ['directory.organizations.archive', '/api/v2/directory/organizations/{publicId}/archive/commands', 'POST'],
        ],
        'directory_organization_restore' => [
            ['directory.organizations.restore', '/api/v2/directory/organizations/{publicId}/restore/commands', 'POST'],
        ],
        'directory_unit_archive' => [
            ['directory.units.archive', '/api/v2/directory/units/{publicId}/archive/commands', 'POST'],
        ],
        'directory_unit_restore' => [
            ['directory.units.restore', '/api/v2/directory/units/{publicId}/restore/commands', 'POST'],
        ],
        'directory_unit_contacts_write' => [
            ['directory.units.contacts.assign', '/api/v2/directory/units/{publicId}/contacts/assign/commands', 'POST'],
            ['directory.units.contacts.remove', '/api/v2/directory/units/{publicId}/contacts/remove/commands', 'POST'],
            ['directory.units.contacts.set_primary', '/api/v2/directory/units/{publicId}/contacts/set-primary/commands', 'POST'],
        ],
        'directory_relationship_write' => [
            ['directory.clients.organization.assign', '/api/v2/directory/clients/{publicId}/organization/assign/commands', 'POST'],
            ['directory.clients.organization.remove', '/api/v2/directory/clients/{publicId}/organization/remove/commands', 'POST'],
            ['directory.clients.organization.move', '/api/v2/directory/clients/{publicId}/organization/move/commands', 'POST'],
        ],
        'directory_binding_revoke' => [
            ['directory.clients.unbind', '/api/v2/directory/clients/bindings/revoke/commands', 'POST'],
            ['directory.organizations.unbind', '/api/v2/directory/organizations/bindings/revoke/commands', 'POST'],
            ['directory.units.unbind', '/api/v2/directory/units/bindings/revoke/commands', 'POST'],
        ],
        'directory_inventory' => [
            ['directory.inventory.read', '/api/v2/directory/inventory'],
        ],
        'projects_read' => [
            ['projects.v2.read', '/api/v2/projects/{publicId}'],
        ],
        'projects_complete' => [
            ['projects.lifecycle.complete', '/api/v2/projects/{publicId}/complete/commands', 'POST'],
        ],
        'projects_cancel' => [
            ['projects.lifecycle.cancel', '/api/v2/projects/{publicId}/cancel/commands', 'POST'],
        ],
        'projects_archive' => [
            ['projects.lifecycle.archive', '/api/v2/projects/{publicId}/archive/commands', 'POST'],
        ],
        'projects_restore' => [
            ['projects.lifecycle.restore', '/api/v2/projects/{publicId}/restore/commands', 'POST'],
        ],
        'projects_create' => [
            ['projects.create', '/api/v2/projects/commands', 'POST'],
        ],
        'projects_write' => [
            ['projects.write', '/api/v2/projects/profile/commands', 'POST'],
        ],
        'projects_binding' => [
            ['projects.bind', '/api/v2/projects/bindings/commands', 'POST'],
        ],
        'projects_binding_refresh' => [
            ['projects.binding.revision.refresh', '/api/v2/projects/bindings/revisions/commands', 'POST'],
        ],
        'projects_binding_status' => [
            ['projects.binding_status.read', '/api/v2/projects/bindings/status/{base64urlExternalId}'],
        ],
        'projects_inventory' => [
            ['projects.inventory.read', '/api/v2/projects/inventory'],
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
    // Client create can carry an exact organization relationship even when
    // the standalone relationship-command feature is disabled.
    if (($features['directory_client_create'] ?? false) === true
        && in_array('directory.clients.organization.assign', $keyScopes, true)
        && !in_array(['name'=>'directory.clients.organization.assign'], $granted, true)) {
        $granted[] = ['name'=>'directory.clients.organization.assign'];
    }
    if (($features['directory_unit_create'] ?? false) === true
        && in_array('directory.units.organization.assign', $keyScopes, true)) {
        $granted[] = ['name'=>'directory.units.organization.assign'];
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
