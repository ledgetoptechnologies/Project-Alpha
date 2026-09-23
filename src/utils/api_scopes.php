<?php

function api_scope_catalog(): array
{
    return [
        'api.capabilities.read' => [
            'label' => 'API v2 capabilities',
            'description' => 'Read the identity and implemented capabilities of a bound application. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Routed before the interactive front controller.
        ],
        'directory.clients.read' => [
            'label' => 'API v2 client directory read',
            'description' => 'Read exact client profiles through an application-bound API v2 connection. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.organizations.read' => [
            'label' => 'API v2 organization directory read',
            'description' => 'Read exact organization profiles through an application-bound API v2 connection. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.clients.binding_status.read' => [
            'label' => 'API v2 client binding status',
            'description' => 'Check one application-scoped client external identity binding. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.organizations.binding_status.read' => [
            'label' => 'API v2 organization binding status',
            'description' => 'Check one application-scoped organization external identity binding. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.clients.bind' => [
            'label' => 'API v2 client identity binding',
            'description' => 'Bind an existing client to one application-scoped external identity. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.organizations.bind' => [
            'label' => 'API v2 organization identity binding',
            'description' => 'Bind an existing organization to one application-scoped external identity. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.clients.binding.revision.refresh' => [
            'label' => 'API v2 client binding revision refresh',
            'description' => 'Advance one existing application-scoped client binding to a verified live revision. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.organizations.binding.revision.refresh' => [
            'label' => 'API v2 organization binding revision refresh',
            'description' => 'Advance one existing application-scoped organization binding to a verified live revision. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [], // Stateless versioned route.
        ],
        'directory.organizations.write' => [
            'label' => 'API v2 organization directory write',
            'description' => 'Update one application-bound organization profile through a version-checked API v2 command. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.write' => [
            'label' => 'API v2 client directory write',
            'description' => 'Update one application-bound client profile through a version-checked API v2 command. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.create' => [
            'label' => 'API v2 client directory create',
            'description' => 'Create and bind one client through an application-scoped API v2 command. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.organizations.create' => [
            'label' => 'API v2 organization directory create',
            'description' => 'Create and bind one organization through an application-scoped API v2 command. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.organization.assign' => [
            'label' => 'API v2 client organization assignment',
            'description' => 'Assign an unassigned client through an exact active application-bound organization identity. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.organization.remove' => [
            'label' => 'API v2 client organization removal',
            'description' => 'Remove an exact versioned client-organization relationship. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.organization.move' => [
            'label' => 'API v2 client organization move',
            'description' => 'Move an exact versioned client relationship to an application-bound organization. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.archive' => [
            'label' => 'API v2 client archive',
            'description' => 'Soft-archive a client without deleting linked financial or historical records. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.restore' => [
            'label' => 'API v2 client restore',
            'description' => 'Restore a soft-archived client without reviving external bindings. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.organizations.archive' => [
            'label' => 'API v2 organization archive',
            'description' => 'Soft-archive an organization while retaining financial and historical relationships. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.organizations.restore' => [
            'label' => 'API v2 organization restore',
            'description' => 'Restore a soft-archived organization without reviving external bindings. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.clients.unbind' => [
            'label' => 'API v2 client binding revocation',
            'description' => 'Explicitly revoke one application-scoped client external identity binding. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.organizations.unbind' => [
            'label' => 'API v2 organization binding revocation',
            'description' => 'Explicitly revoke one application-scoped organization external identity binding. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'directory.inventory.read' => [
            'label' => 'API v2 historical directory inventory',
            'description' => 'Read a bounded inventory of live directory resources, retained tombstones, and this application\'s binding state. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'projects.v2.read' => [
            'label' => 'API v2 Project read',
            'description' => 'Read one exact permanently identified Project and its derived overdue warning. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'projects.lifecycle.complete' => [
            'label' => 'API v2 Project completion',
            'description' => 'Complete one exact revision-checked Project through the shared closeout rules. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'projects.lifecycle.cancel' => [
            'label' => 'API v2 Project cancellation',
            'description' => 'Cancel one exact revision-checked Project through the shared closeout rules. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'projects.lifecycle.archive' => [
            'label' => 'API v2 Project archive',
            'description' => 'Reversibly archive one exact Project without deleting financial, document, public identity, or change history. Explicit grant required.',
            'endpoints' => [],
        ],
        'projects.lifecycle.restore' => [
            'label' => 'API v2 Project restore',
            'description' => 'Restore one exact archived Project without granting portal or public-link access. Explicit grant required.',
            'endpoints' => [],
        ],
        'projects.create' => [
            'label' => 'API v2 Project create',
            'description' => 'Create one private Project and bind it to an application-scoped external identity. Explicit grant required; legacy full access does not inherit this scope.',
            'endpoints' => [],
        ],
        'projects.write' => [
            'label' => 'API v2 Project profile write',
            'description' => 'Update one explicitly bound Project using revision, projection-hash, and authorization-generation fences. Explicit grant required.',
            'endpoints' => [],
        ],
        'projects.bind' => [
            'label' => 'API v2 Project identity binding',
            'description' => 'Bind one existing Project to one application-scoped external identity using exact immutable proof. Explicit grant required.',
            'endpoints' => [],
        ],
        'projects.binding.revision.refresh' => [
            'label' => 'API v2 Project binding revision refresh',
            'description' => 'Advance one permanent Project binding to an exact verified current revision without changing its external or PA identity. Explicit grant required.',
            'endpoints' => [],
        ],
        'projects.binding_status.read' => [
            'label' => 'API v2 Project binding status',
            'description' => 'Read one exact application-scoped Project binding and reject stale canonical state. Explicit grant required.',
            'endpoints' => [],
        ],
        'projects.inventory.read' => [
            'label' => 'API v2 Project binding inventory',
            'description' => 'Read a bounded inventory containing only this application\'s explicit Project bindings. Explicit grant required.',
            'endpoints' => [],
        ],
        'catalog.inventory.read' => [
            'label' => 'API v2 catalog inventory',
            'description' => 'Read a bounded, fenced full snapshot of externally requestable catalog items. Explicit grant required.',
            'endpoints' => [],
        ],
        'dashboard.read' => [
            'label' => 'Dashboard summary',
            'description' => 'Read high-level dashboard counts and status summaries.',
            'endpoints' => ['api-dashboard-summary'],
        ],
        'financial.read' => [
            'label' => 'Financial summary',
            'description' => 'Read revenue, expense, and profit summaries.',
            'endpoints' => ['api-financial-summary'],
        ],
        'clients.read' => [
            'label' => 'Clients',
            'description' => 'Read client lists and client search results.',
            'endpoints' => ['api-clients', 'api-clients-search'],
        ],
        'projects.read' => [
            'label' => 'Projects',
            'description' => 'Read project lists.',
            'endpoints' => ['api-projects'],
        ],
        'quotes.read' => [
            'label' => 'Quotes',
            'description' => 'Read quote lists.',
            'endpoints' => ['api-quotes'],
        ],
        'invoices.read' => [
            'label' => 'Invoices',
            'description' => 'Read invoice lists.',
            'endpoints' => ['api-invoices'],
        ],
        'ops.sync.read' => [
            'label' => 'External operations synchronization',
            'description' => 'Read the least-privilege workforce, client, Project, and service-location snapshot used by a configured external operations application.',
            'endpoints' => ['api-ops-snapshot', 'api-ops-snapshot-v2'],
        ],
        'portal.catalog.publish' => [
            'label' => 'Portal catalog publisher',
            'description' => 'Dedicated producer capability for signed Service Library projection delivery. It grants no browser or broad API access.',
            'endpoints' => [],
        ],
        'portal.pricing.preview' => [
            'label' => 'Portal pricing preview',
            'description' => 'Request non-binding aggregate pricing guidance for an authorized project and active portal services. Dedicated keys only.',
            'endpoints' => ['api-integration-pricing-hints'],
        ],
        'portal.quote-draft.create' => [
            'label' => 'Portal draft quote creation',
            'description' => 'Create private draft quotes through the strict signed idempotent integration contract. Dedicated keys only.',
            'endpoints' => ['api-integration-draft-quotes'],
        ],
        'notifications.enqueue' => [
            'label' => 'Internal notification relay',
            'description' => 'Enqueue allowlisted transactional notifications. Requires an IP-restricted dedicated key; full-access keys do not inherit this scope.',
            // Served only by an operator-configured private listener; never by public/index.php.
            'endpoints' => [],
        ],
    ];
}

function api_scope_endpoint_map(): array
{
    $map = [];
    foreach (api_scope_catalog() as $scope => $definition) {
        foreach ((array)($definition['endpoints'] ?? []) as $endpoint) {
            $map[$endpoint] = $scope;
        }
    }
    return $map;
}

function api_scope_options_for_form(): array
{
    return ['full' => [
        'label' => 'Legacy broad API access',
        'description' => 'Allow legacy endpoints that accept the broad scope. Fine-grained API v2 endpoints always require their explicit scopes and do not inherit this grant.',
        'endpoints' => ['*'],
    ]] + api_scope_catalog();
}

function api_normalize_scopes($value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $raw);
    }

    $allowed = ['full' => true] + array_fill_keys(array_keys(api_scope_catalog()), true);
    $scopes = [];
    foreach ($items as $item) {
        $scope = strtolower(trim((string)$item));
        if ($scope === '') {
            continue;
        }
        if (in_array($scope, ['*', 'all', 'admin', 'full_access', 'full-access'], true)) {
            $scope = 'full';
        }
        if ($scope === 'dashboard') {
            $scope = 'dashboard.read';
        }
        if (in_array($scope, ['read', 'write', 'read.write', 'read_write'], true)) {
            $scope = 'full';
        }
        if (isset($allowed[$scope])) {
            $scopes[$scope] = true;
        }
    }

    if (isset($scopes['full'])) {
        return ['full'];
    }
    return array_keys($scopes);
}

function api_scopes_to_storage(array $scopes): string
{
    $normalized = api_normalize_scopes($scopes);
    return $normalized ? implode(',', $normalized) : '';
}

function api_key_has_scope($storedScopes, string $requiredScope, bool $allowFull = true): bool
{
    $scopes = api_normalize_scopes($storedScopes);
    if ($allowFull && in_array('full', $scopes, true)) {
        return true;
    }
    return in_array(strtolower($requiredScope), $scopes, true);
}
