<?php
// src/utils/permission_catalog.php
// SINGLE SOURCE OF TRUTH for permission groups + ordering. All UI/handlers consume this.
function permission_catalog(): array
{
    return [
        'Quotes'        => ['quotes.view','quotes.create','quotes.edit','quotes.send','quotes.approve','quotes.reject'],
        'Contracts'     => ['contracts.view','contracts.create','contracts.edit','contracts.sign','contracts.complete','contracts.void','contracts.send'],
        'Invoices'      => ['invoices.view','invoices.create','invoices.edit','invoices.mark_paid','invoices.void','invoices.send'],
        'Payments'      => ['payments.view','payments.create'],
        'Clients'       => ['clients.view','clients.create','clients.edit','clients.onboarding','clients.delete','clients.purge','clients.restore'],
        'Projects'      => ['projects.view','projects.create','projects.edit','projects.delete','projects.search'],
        'Jobs'          => ['jobs.view','jobs.edit','jobs.search'],
        'Organizations' => ['organizations.view','organizations.manage','organizations.delete'],
        'Public Links'  => ['public_links.view','public_links.create','public_links.revoke','public_links.manage'],
        'Client Portal' => ['portal_service_assignments.manage'],
        'Timekeeping'   => ['timekeeping.self','timekeeping.manage'],
        'Workforce'     => [
            'workforce.manage',
            'workforce.catalog.manage',
            'workforce.assignments.self',
            'workforce.assignments.manage',
            'workforce.pay_periods.manage',
            'workforce.statements.self',
            'workforce.statements.manage',
            'workforce.business_units.manage',
            'workforce.directory.search',
        ],
        'Approvals'     => ['approvals.review'],
        'Employee Pay'  => ['employee_pay.self','employee_pay.view','employee_pay.manage'],
        'Reports'       => ['reports.view'],
        'Financial'     => ['financial.view','financial.manage','financial.export','financial.audit'],
        'Billing'       => ['billing.view','billing.manage'],
        'Users'         => ['users.view','users.manage','users.reset_password','users.delete'],
        'API Keys'      => ['api_keys.view','api_keys.manage'],
        'Settings'      => ['settings.view','settings.manage'],
        '2FA'           => ['2fa.manage'],
        'Profile'       => ['profile.view','profile.edit'],
    ];
}

// Flat helper: returns ['quotes.view' => 'Quotes', ...] for iteration in handlers
function permission_catalog_flat(): array
{
    $flat = [];
    foreach (permission_catalog() as $group => $keys) {
        foreach ($keys as $key) { $flat[$key] = $group; }
    }
    return $flat;
}
