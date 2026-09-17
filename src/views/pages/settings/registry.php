<?php

/**
 * Settings information architecture.
 *
 * Keep route and permission metadata here so the dashboard, sidebar, and tests
 * all use the same source of truth. `tab` values intentionally remain the
 * legacy values consumed by settings_handler.php and dedicated tab handlers.
 */
function pa_settings_registry(): array
{
    $registry = [
        'account' => [
            'title' => 'My Account & Security',
            'short_title' => 'Account & Security',
            'marker' => 'AS',
            'description' => 'Manage your profile, password, sign-in methods, and trusted devices.',
            'keywords' => 'profile password security two factor 2fa totp passkey trusted devices sessions',
            'items' => [
                'profile' => [
                    'title' => 'Profile & password',
                    'description' => 'Update your password and review your account details.',
                    'href' => '/?page=account',
                    'keywords' => 'profile account password email',
                ],
                'security' => [
                    'title' => 'Sign-in security',
                    'description' => 'Manage authenticator verification, passkeys, and trusted devices.',
                    'href' => '/?page=account#security',
                    'keywords' => 'totp authenticator two factor 2fa passkey trusted devices',
                ],
            ],
        ],
        'business' => [
            'title' => 'Business Profile & Branding',
            'short_title' => 'Business & Branding',
            'marker' => 'BP',
            'description' => 'Business identity, branding, public access, integrations, and installation defaults.',
            'keywords' => 'business brand logo domain address timezone google email smtp integration installation',
            'items' => [
                'business-units-divisions' => [
                    'title' => 'Business units & divisions',
                    'description' => 'Organize branches, regions, departments, or crews such as Green Bay and Chippewa Falls.',
                    'tab' => 'business-units-divisions',
                    'permission' => 'settings.manage',
                    'roles' => ['admin', 'owner'],
                    'keywords' => 'division branch region department crew business unit default',
                    'form_mode' => 'self',
                ],
                'system' => [
                    'title' => 'Business & installation',
                    'description' => 'Business details, logo, domain, email providers, maps, and installation defaults.',
                    'tab' => 'system',
                    'permission' => 'settings.manage',
                    'roles' => ['admin', 'owner'],
                    'keywords' => 'system brand logo domain business email smtp gmail google maps routes timezone',
                    'form_mode' => 'wrapped',
                ],
            ],
        ],
        'people' => [
            'title' => 'People, Access & Business Units',
            'short_title' => 'People & Access',
            'marker' => 'PA',
            'description' => 'Accounts, roles, permissions, and access boundaries.',
            'keywords' => 'people users accounts roles permissions access business units',
            'items' => [
                'business-units' => [
                    'title' => 'Worker profiles & access',
                    'description' => 'Manage worker relationships, documents, capabilities, accounts, and permissions.',
                    'tab' => 'business-units',
                    'permission' => 'settings.manage',
                    'keywords' => 'workers employees contractors documents scopes access',
                    'form_mode' => 'self',
                ],
                'accounts' => [
                    'title' => 'User accounts',
                    'description' => 'Create accounts and manage each person’s access.',
                    'href' => '/?page=accounts',
                    'permission' => 'users.view',
                    'roles' => ['admin'],
                    'keywords' => 'users accounts employees contractors access',
                ],
                'permissions' => [
                    'title' => 'Roles & permissions',
                    'description' => 'Define role permissions for this installation.',
                    'tab' => 'permissions',
                    'permission' => 'settings.manage',
                    'roles' => ['admin'],
                    'keywords' => 'roles permissions acl capabilities',
                    'form_mode' => 'self',
                ],
            ],
        ],
        'work' => [
            'title' => 'Work, Jobs & Pay',
            'short_title' => 'Work, Jobs & Pay',
            'marker' => 'WJ',
            'description' => 'Time access, mileage, Job locations, workflow, and compensation defaults.',
            'keywords' => 'work jobs pay time review mileage locations scheduling compensation workflow',
            'items' => [
                'work-types' => [
                    'title' => 'Work Activities',
                    'description' => 'Define reusable activities workers select when recording time.',
                    'tab' => 'work-types',
                    'permission' => 'workforce.catalog.manage',
                    'keywords' => 'work types client billing hourly fixed base overage percentage pay compensation',
                    'form_mode' => 'self',
                ],
                'assignments' => [
                    'title' => 'Assignments & compensation review',
                    'description' => 'Offer planned Job work and approve eligible worker compensation.',
                    'tab' => 'assignments',
                    'permission' => 'settings.manage',
                    'keywords' => 'assignments offer accept decline compensation approve payable',
                    'form_mode' => 'self',
                ],
                'pay-periods' => [
                    'title' => 'Pay periods & statements',
                    'description' => 'Choose the review cadence and close employee or contractor statements.',
                    'tab' => 'pay-periods',
                    'permission' => 'settings.manage',
                    'keywords' => 'weekly biweekly semimonthly monthly pay period statements settlement',
                    'form_mode' => 'self',
                ],
                'workflow' => [
                    'title' => 'Workflow defaults',
                    'description' => 'Configure time and workforce defaults, document conversion, mileage, and Job location behavior.',
                    'tab' => 'workflow',
                    'permission' => 'settings.manage',
                    'keywords' => 'workflow workforce pay billing currency quote conversion time approval mileage route job project location',
                    'form_mode' => 'wrapped',
                ],
            ],
        ],
        'sales' => [
            'title' => 'Sales, Catalog & Documents',
            'short_title' => 'Sales & Documents',
            'marker' => 'SD',
            'description' => 'Catalog entries, document behavior, presentation, and standard terms.',
            'keywords' => 'sales service library services packages documents quotes contracts invoices terms',
            'items' => [
                'item-library' => [
                    'title' => 'Service Library',
                    'description' => 'Manage client services, packages, and their linked Work Activities.',
                    'tab' => 'item-library',
                    'permission' => 'settings.manage',
                    'keywords' => 'service library services fees packages work activities worker compensation',
                    'form_mode' => 'self',
                ],
                'documents' => [
                    'title' => 'Document settings',
                    'description' => 'Quote, contract, invoice, and document customization options.',
                    'tab' => 'documents',
                    'permission' => 'settings.manage',
                    'keywords' => 'quotes contracts invoices customization custom fields document display',
                    'form_mode' => 'self',
                ],
                'terms' => [
                    'title' => 'Terms & validity',
                    'description' => 'Set standard terms and public document validity.',
                    'tab' => 'terms',
                    'permission' => 'settings.manage',
                    'keywords' => 'terms conditions validity long term on demand',
                    'form_mode' => 'wrapped',
                ],
            ],
        ],
        'billing' => [
            'title' => 'Billing & Taxes',
            'short_title' => 'Billing & Taxes',
            'marker' => 'BT',
            'description' => 'Payment defaults, processing behavior, tax rates, and tax imports.',
            'keywords' => 'billing payment stripe surcharge taxes rates import',
            'items' => [
                'billing' => [
                    'title' => 'Billing & payments',
                    'description' => 'Configure payment methods, invoice defaults, and payment processing.',
                    'tab' => 'billing',
                    'permission' => 'settings.manage',
                    'keywords' => 'billing payment methods net terms stripe surcharge processor',
                    'form_mode' => 'wrapped',
                ],
                'taxes' => [
                    'title' => 'Taxes',
                    'description' => 'Manage tax rates, lookup behavior, and tax data imports.',
                    'tab' => 'taxes',
                    'permission' => 'settings.manage',
                    'keywords' => 'tax rates state county lookup csv import',
                    'form_mode' => 'self',
                ],
                'pricing-adjustments' => [
                    'title' => 'Pricing adjustments',
                    'description' => 'Manage reusable percentage adjustments for projects, contracts, and their documents.',
                    'tab' => 'pricing-adjustments',
                    'permission' => 'financial.manage',
                    'keywords' => 'pricing adjustment discount reusable customer project contract percentage',
                    'form_mode' => 'self',
                ],
            ],
        ],
        'communications' => [
            'title' => 'Communications & Integrations',
            'short_title' => 'Communications',
            'marker' => 'CI',
            'description' => 'Automated notices, reminders, client links, and storage connections.',
            'keywords' => 'communications notifications reminders email automation integrations links storage dropbox drive s3',
            'items' => [
                'notifications' => [
                    'title' => 'Notifications & automation',
                    'description' => 'Control reminders, receipts, system tasks, and administrative emails.',
                    'tab' => 'notifications',
                    'permission' => 'settings.manage',
                    'keywords' => 'notifications automation reminders receipts cron email onboarding',
                    'form_mode' => 'wrapped',
                ],
                'links' => [
                    'title' => 'Links & storage',
                    'description' => 'Configure external storage providers and link resolution.',
                    'tab' => 'links',
                    'permission' => 'settings.manage',
                    'keywords' => 'links storage dropbox google drive s3 resolver',
                    'form_mode' => 'self',
                ],
            ],
        ],
        'data' => [
            'title' => 'Data, Backups & System',
            'short_title' => 'Data & System',
            'marker' => 'DS',
            'description' => 'Backups, logs, diagnostics, and API access.',
            'keywords' => 'data backups restore logs diagnostics api keys system',
            'items' => [
                'backup' => [
                    'title' => 'Backups & restore',
                    'description' => 'Configure, create, download, and restore backups.',
                    'tab' => 'backup',
                    'permission' => 'settings.manage',
                    'keywords' => 'backup restore retention download database files',
                    'form_mode' => 'self',
                ],
                'logs' => [
                    'title' => 'Logs & diagnostics',
                    'description' => 'Review application activity and diagnostic logs.',
                    'tab' => 'logs',
                    'permission' => 'settings.manage',
                    'keywords' => 'logs audit diagnostics errors requests',
                    'form_mode' => 'self',
                ],
                'api-keys' => [
                    'title' => 'API keys',
                    'description' => 'Manage credentials used by approved integrations.',
                    'href' => '/?page=api-keys',
                    'permission' => 'api_keys.view',
                    'keywords' => 'api keys integrations credentials',
                ],
                'directory-management' => [
                    'title' => 'Directory management',
                    'description' => 'Optionally make client and organization topology read-only when a fully authorized external application is healthy.',
                    'tab' => 'directory-management',
                    'permission' => 'settings.manage',
                    'roles' => ['admin', 'owner'],
                    'keywords' => 'directory external application clients organizations read only policy',
                    'form_mode' => 'self',
                ],
            ],
        ],
    ];

    $registry['communications']['items']['external-ops'] = [
        'title' => 'Custom integrations',
        'description' => 'Configure optional deployment-specific application synchronization.',
        'tab' => 'external-ops',
        'permission' => 'settings.manage',
        'roles' => ['admin', 'owner'],
        'keywords' => 'advanced custom external operations application entitlements webhook synchronization',
        'form_mode' => 'self',
    ];

    // Present the installation as six broad, predictable destinations while
    // preserving every legacy tab and permission check above.
    $registry['account']['title'] = 'People & Access';
    $registry['account']['short_title'] = 'People & Access';
    $registry['account']['description'] = 'User accounts, roles, permissions, worker profiles, and access boundaries.';
    $registry['account']['keywords'] .= ' ' . (string)($registry['people']['keywords'] ?? '');
    $registry['account']['items'] = array_merge($registry['account']['items'], $registry['people']['items']);

    $registry['business']['title'] = 'Business';
    $registry['business']['short_title'] = 'Business';

    $registry['workforce'] = $registry['work'];
    $registry['workforce']['title'] = 'Workforce';
    $registry['workforce']['short_title'] = 'Workforce';
    $registry['workforce']['marker'] = 'WF';

    $registry['services'] = $registry['sales'];
    $registry['services']['title'] = 'Services & Documents';
    $registry['services']['short_title'] = 'Services & Documents';
    $registry['services']['marker'] = 'SV';

    $registry['system'] = $registry['communications'];
    $registry['system']['title'] = 'System & Integrations';
    $registry['system']['short_title'] = 'System & Integrations';
    $registry['system']['marker'] = 'SI';
    $registry['system']['description'] = 'Automation, integrations, storage, backups, diagnostics, and API access.';
    $registry['system']['keywords'] .= ' backups restore logs diagnostics api keys';
    $registry['system']['items'] = array_merge($registry['system']['items'], $registry['data']['items']);

    $registry = [
        'account' => $registry['account'],
        'business' => $registry['business'],
        'services' => $registry['services'],
        'workforce' => $registry['workforce'],
        'billing' => $registry['billing'],
        'system' => $registry['system'],
    ];

    return $registry;
}

function pa_settings_item_visible(array $item, callable $can, string $role): bool
{
    if (!empty($item['roles']) && !in_array($role, (array)$item['roles'], true)) {
        return false;
    }

    $permission = (string)($item['permission'] ?? '');
    return $permission === '' || (bool)$can($permission);
}

function pa_settings_visible_registry(array $registry, callable $can, string $role): array
{
    $visible = [];
    foreach ($registry as $categoryKey => $category) {
        $items = [];
        foreach (($category['items'] ?? []) as $itemKey => $item) {
            if (pa_settings_item_visible($item, $can, $role)) {
                $items[$itemKey] = $item;
            }
        }
        if ($items !== []) {
            $category['items'] = $items;
            $visible[$categoryKey] = $category;
        }
    }
    return $visible;
}

/** @return array{category_key:string,category:array,item_key:string,item:array}|null */
function pa_settings_find_tab(array $registry, string $tab): ?array
{
    foreach ($registry as $categoryKey => $category) {
        foreach (($category['items'] ?? []) as $itemKey => $item) {
            if (($item['tab'] ?? null) === $tab) {
                return [
                    'category_key' => $categoryKey,
                    'category' => $category,
                    'item_key' => $itemKey,
                    'item' => $item,
                ];
            }
        }
    }
    return null;
}

function pa_settings_item_href(array $item): string
{
    if (!empty($item['href'])) {
        return (string)$item['href'];
    }
    return '/?page=settings&tab=' . rawurlencode((string)($item['tab'] ?? ''));
}
