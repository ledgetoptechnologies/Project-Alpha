<?php
// src/utils/acl_middleware.php
// Central permission gate. Maps ?page=X → permission key, checks user_can().

require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/audit.php';

function page_permission_map(): array
{
    static $map = null;
    if ($map !== null) return $map;

    $map = [
        // Core authenticated pages (low bar)
        'home'                => 'financial.view',
        'landing'              => null,
        'user-dashboard'       => null,
        'dashboard'           => 'financial.view',
        'account'             => null,
        'account-update'      => null,
        'account/delete'      => 'users.manage',
        'account-revoke-device' => null,
        'auth'                => null,
        'logout'              => null,
        'logout-confirm'      => null,
        'serveupload'         => null,
        'tax-lookup'          => null,

        // Settings module
        'settings'                         => 'settings.view',
        'settings-backup'                  => 'settings.manage',
        'settings/backup-download'         => 'settings.manage',
        'settings/custom-fields-handler'     => 'settings.manage',
        'settings/document-custom-fields-handler' => 'settings.manage',
        'settings/document-customization-save'    => 'settings.manage',
        'settings/dropbox-oauth'             => 'settings.manage',
        'settings/gmail-oauth'               => 'settings.manage',
        'settings/email-provider-handler'    => 'settings.manage',
        'settings/item-library-handler'      => 'settings.manage',
        'settings/item-library-search'       => 'settings.view',
        'settings/links-handler'             => 'settings.manage',
        'settings/link-resolver-run'         => 'settings.manage',
        'settings/logs'                      => 'settings.manage',
        'settings/logs-handler'                => 'settings.manage',
        'settings/permissions'                 => 'settings.manage',
        'settings/permissions-handler'         => 'settings.manage',
        'settings/external-ops-handler'        => 'settings.manage',
        'settings/link-test-connection'      => 'settings.manage',
        'settings/managed-delivery-test'     => 'settings.manage',
        'settings/managed-delivery-send'     => 'settings.manage',
        'settings/managed-delivery-revoke'   => 'settings.manage',
        'settings/managed-delivery-retry'    => 'settings.manage',
        'settings/stripe-net-backfill'       => 'settings.manage',
        'settings/stripe-import-payments'    => 'settings.manage',
        'settings/tax-import-handler'        => 'settings.manage',
        'settings/tax-import-chunk'          => 'settings.manage',
        'settings/tax-rates-handler'         => 'settings.manage',
        'settings/pricing-adjustments-handler' => 'financial.manage',

        // Unified Workforce modules
        'workforce/time'        => ['timekeeping.self', 'timekeeping.manage'],
        'workforce/overview'    => ['timekeeping.self', 'timekeeping.manage', 'workforce.manage', 'workforce.assignments.self', 'workforce.assignments.manage', 'workforce.pay_periods.manage', 'workforce.statements.self', 'workforce.statements.manage', 'workforce.business_units.manage', 'approvals.review', 'employee_pay.self', 'employee_pay.view', 'employee_pay.manage'],
        'workforce/approvals'   => 'approvals.review',
        'workforce/pay'         => ['workforce.statements.self', 'workforce.statements.manage', 'employee_pay.self', 'employee_pay.view', 'employee_pay.manage'],
        // The action controller performs an action-specific permission check.
        'workforce/action'      => null,
        'workforce/contractor-invoice' => null,
        'workforce/contractor-invoice-download' => null,
        'api/workforce-v1'      => null,
        'api/catalog-v1'        => null,
        // This endpoint contains both configuration and operational commands;
        // its controller enforces the canonical permission for each action.
        'settings/workforce-catalog-handler' => null,

        // Accounts / users
        'accounts'               => 'users.manage',
        'accounts-create'        => 'users.manage',
        'accounts-delete'        => 'users.manage',
        'accounts-reset-password' => 'users.reset_password',
        'accounts-update'        => 'users.manage',
        'account-edit'           => 'users.manage',
        'worker-documents'       => 'users.manage',
        'worker-document-download' => null,
        'employee-documents'     => 'users.manage',
        'employee-document-download' => null,
        '2fa-admin-disable'      => '2fa.manage',
        '2fa-setup'              => null,
        '2fa-setup-action'       => null,
        '2fa-verify'             => null,
        '2fa-verify-action'      => null,
        'passkeys'               => null,
        'passkey-options'        => null,
        'passkey-complete'       => null,
        'passkey-register-options' => null,
        'passkey-register-complete' => null,
        'passkey-manage'         => null,
        'passkey-admin-reset'    => '2fa.manage',

        // API keys
        'api-keys'          => 'api_keys.manage',
        'api-keys-new'      => 'api_keys.manage',
        'api-keys-edit'     => 'api_keys.manage',
        'api-keys-create'   => 'api_keys.manage',
        'api-keys-update'   => 'api_keys.manage',
        'api-keys-revoke'   => 'api_keys.manage',
        'api-clients-search' => 'api_keys.view',

        // Quotes module
        'quote/quotes-list'   => 'quotes.view',
        'quote/long-term-quotes-list' => 'quotes.view',
        'quote/on-demand-quotes-list' => 'quotes.view',
        'quote/quotes-create' => 'quotes.create',
        'quote/quotes-edit'   => 'quotes.edit',
        'quote/quotes-edit-public' => 'quotes.edit',
        'quote/quotes-update' => 'quotes.edit',
        'quote/quote-details' => 'quotes.view',
        'quote/long-term-quote-details' => 'quotes.view',
        'quote/quote-pdf'     => 'quotes.view',
        'quote/long-term-quote-pdf' => 'quotes.view',
        'quote-pdf'           => 'quotes.view',
        'long-term-quote-pdf' => 'quotes.view',
        'quote/quote-approve' => 'quotes.approve',
        'quote/quote-reject'  => 'quotes.reject',
        'quote/quote-clone'   => 'quotes.create',
        'quote/email-send'    => 'quotes.send',
        'quote-reject'        => 'quotes.reject',
        'quotes-create'       => 'quotes.create',
        'quotes-edit'         => 'quotes.edit',
        'quotes-update'       => 'quotes.edit',

        // Generic email (admin/settings only)
        'email-send'          => 'settings.manage',
        'email-test'          => 'settings.manage',

        // Contracts module
        'contract/contracts-list'   => 'contracts.view',
        'contract/long-term-contracts-list' => 'contracts.view',
        'contract/on-demand-contracts-list' => 'contracts.view',
        'contract/contracts-create' => 'contracts.create',
        'contract/contracts-edit'   => 'contracts.edit',
        'contract/contract-details' => 'contracts.view',
        'contract/long-term-contract-details' => 'contracts.view',
        'contract/contract-pdf'     => 'contracts.view',
        'contract/long-term-contract-pdf' => 'contracts.view',
        'contract-pdf'              => 'contracts.view',
        'long-term-contract-pdf'    => 'contracts.view',
        'contract/contract-sign'    => 'contracts.sign',
        'contract/contract-complete' => 'contracts.complete',
        'contract/contract-deny'    => 'contracts.edit',
        'contract/contract-deposit-received' => 'contracts.edit',
        'contract/contract-void'    => 'contracts.void',
        'contract/contracts-update' => 'contracts.edit',
        'contract/email-send'       => 'contracts.send',
        'contract/long-term-contracts-create' => 'contracts.create',
        'long-term-contracts-create'          => 'contracts.create',
        'contracts-create'          => 'contracts.create',
        'contracts-edit'            => 'contracts.edit',
        'contracts-update'          => 'contracts.edit',
        'contract-void'           => 'contracts.void',
        'contract-sign'           => 'contracts.sign',
        'contract-complete'         => 'contracts.complete',
        'public-contract-sign'    => null,
        'long-term-contract-activate' => 'contracts.edit',
        'long-term-contract-start-billing' => 'contracts.edit',
        'long-term-contract-pause'    => 'contracts.edit',
        'long-term-contract-resume'   => 'contracts.edit',
        'long-term-contract-terminate' => 'contracts.void',
        'long-term-recurring-service-save' => 'contracts.edit',
        'long-term-recurring-service-action' => 'contracts.edit',
        'on-demand-contract-activate'  => 'contracts.edit',
        'on-demand-contract-pause'     => 'contracts.edit',
        'on-demand-contract-resume'    => 'contracts.edit',
        'on-demand-contract-terminate' => 'contracts.void',

        // Invoices module
        'invoice/invoices-list'     => 'invoices.view',
        'invoice/invoices-create'   => 'invoices.create',
        'invoice/invoices-edit'     => 'invoices.edit',
        'invoice/invoices-update'   => 'invoices.edit',
        'invoice/invoice-details'   => 'invoices.view',
        'invoice/invoice-pdf'       => 'invoices.view',
        'invoice-pdf'               => 'invoices.view',
        'invoice/invoices-mark-paid' => 'invoices.mark_paid',
        'invoice/invoice-finalize'  => 'invoices.send',
        'invoice/invoice-reopen'    => 'invoices.edit',
        'invoice/invoice-void'      => 'invoices.void',
        'invoice/invoice-reenable'  => 'invoices.void',
        'invoice/email-send'        => 'invoices.send',
        'invoices-create'           => 'invoices.create',
        'invoices-edit'             => 'invoices.edit',
        'invoices-update'           => 'invoices.edit',
        'invoices-mark-paid'        => 'invoices.mark_paid',
        'on-demand-invoice-generate' => 'invoices.create',
        'invoice/recurring-invoices-list' => 'invoices.view',
        'invoice/on-demand-invoices-list' => 'invoices.view',

        // Payments module
        'payments/payments-list'    => 'payments.view',
        'payments-list'             => 'payments.view',
        'payments/payments-create'  => 'invoices.mark_paid',
        'payments/payment-refund'   => 'invoices.mark_paid',
        'payments/payment-reverse'  => 'invoices.mark_paid',
        'payments/payment-correction' => 'invoices.mark_paid',
        'payments/payment-correct'  => 'invoices.mark_paid',

        // Clients module
        'client/clients-list'    => 'clients.view',
        'client/client-details'  => 'clients.view',
        'client/clients-create'  => 'clients.create',
        'client/clients-edit'    => 'clients.edit',
        'client/clients-update'  => 'clients.edit',
        'portal/service-assignments-handler' => 'portal_service_assignments.manage',
        'client/onboarding'      => 'clients.onboarding',
        'client/onboarding-invite' => 'clients.onboarding',
        'client/onboarding-review' => 'clients.onboarding',
        'client/clients-delete'  => 'clients.delete',
        'client/clients-purge'   => 'clients.purge',
        'client/clients-restore' => 'clients.restore',
        'clients-create'         => 'clients.create',
        'clients-delete'         => 'clients.delete',
        'clients-purge'          => 'clients.purge',
        'clients-restore'        => 'clients.restore',
        'clients-search'         => 'clients.view',
        'clients-update'         => 'clients.edit',

        // Projects module
        'project/projects-list'         => 'projects.view',
        'project/projects-create'       => 'projects.create',
        'project/projects-edit'         => 'projects.edit',
        'project/projects-update'         => 'projects.edit',
        'project/projects-update-status' => 'projects.edit',
        'project/project-work-handler'    => 'projects.edit',
        'project/projects-delete'        => 'projects.delete',
        'project/project-add-document'   => 'projects.edit',
        'project/project-remove-document' => 'projects.edit',
        'project/project-files'           => 'projects.edit',
        'project/project-file-download'   => 'projects.view',
        'project/project-invoices-list'   => 'projects.view',
        'project/project-invoice-details' => 'projects.view',
        'project/project-invoice-pdf'     => 'projects.view',
        'project/project-invoice-generate' => 'invoices.create',
        'project/project-invoice-email'   => 'invoices.send',
        'project/project-invoice-payment' => 'invoices.mark_paid',
        'project/client-options'          => 'projects.create',
        'project-notes'                  => 'projects.view',
        'project-notes-update'           => 'projects.edit',
        'projects-search'                => 'projects.search',
        'projects-search-autocomplete'   => 'projects.search',

        // Jobs module
        'jobs/jobs-list'   => 'jobs.view',
        'jobs/job-details' => 'jobs.view',
        'jobs/job-settings-handler' => 'jobs.edit',
        'jobs-list'        => 'jobs.view',

        // Organizations module
        'organization/organizations-list'      => 'organizations.view',
        'organization/organization-view'       => 'organizations.view',
        'organizations-list'                     => 'organizations.view',
        'organization/organizations-update'      => 'organizations.manage',
        'organization/organizations-delete'      => 'organizations.delete',
        'organization/organization-add-client'     => 'organizations.manage',
        'organization/organization-remove-client'  => 'organizations.manage',
        'organization/organization-update-notes'   => 'organizations.manage',
        'organization/organizations_upload'        => 'organizations.manage',
        'organization/organization-departments-options' => 'organizations.view',
        'organization/organization-departments'         => 'organizations.manage',
        'organization/org-create'                => 'organizations.manage',
        'organization/org-search'                => 'organizations.view',
        'organizations-create'                   => 'organizations.manage',
        'organizations-delete'                   => 'organizations.delete',
        'organizations-update'                   => 'organizations.manage',
        'organization-update-notes'              => 'organizations.manage',
        'org-search'                             => 'organizations.view',
        'org-create'                             => 'organizations.manage',

        // Financial module
        'financial/financial-dashboard'    => 'financial.view',
        'financial/expenses-list'          => 'financial.view',
        'financial/expense-create'         => 'financial.manage',
        'financial/expense-detail'         => 'financial.view',
        'financial/recurring-expense-form' => 'financial.manage',
        'financial/recurring-expense-handler' => 'financial.manage',
        'financial/asset-detail'           => 'financial.view',
        'financial/asset-form'             => 'financial.manage',
        'financial/asset-handler'          => 'financial.manage',
        'financial/expense-handler'        => 'financial.manage',
        'financial/expense_handler'        => 'financial.manage',
        'financial/csv-import'             => 'financial.manage',
        'financial/mileage-list'           => null,
        'financial/mileage-create'         => null,
        'financial/mileage-handler'        => null,
        'financial/mileage-profile-handler'=> 'financial.manage',
        'financial/route-estimate'          => 'financial.manage',
        'financial/mileage-unbilled'       => 'invoices.create',
        'financial/mileage-tracking-api'   => 'financial.manage',
        'financial/vendor-handler'         => 'financial.manage',
        'financial/category-handler'       => 'financial.manage',
        'financial/audit'                  => 'financial.audit',
        'financial/audit-export'           => 'financial.export',
        'financial/audit-schedule-handler' => 'financial.view',
        'financial/financial-api'          => 'financial.view',
        'financial/forms-list'     => 'financial.view',
        'financial/expense-report' => 'financial.view',

        // Time tracking
        'time-tracking/unbilled'   => 'billing.view',
        'time-tracking/options'    => 'billing.view',

        // Public links
        'public-link-create'  => 'public_links.create',
        'public-link-revoke'  => 'public_links.revoke',
        'public-project'      => null,
        'public-project-upload' => null,
        'public-project-file' => null,

        // Legal / misc
        'legal/tos-accept' => null,
        'links/link-management' => 'settings.manage',
        'links/manual-link-handler' => 'settings.manage',
        'forms-handler' => 'settings.manage',
        'custom-fields-ajax' => 'settings.manage',
        'receipts-handler' => 'financial.manage',

        // Documents
        'document-date-update' => 'settings.manage',
        'document-reenable'    => 'settings.manage',

        // Billing / Stripe
        'stripe-charge'          => 'billing.manage',
        'stripe-success'         => 'billing.view',
        'stripe-webhook'         => null,
        'stripe-webhook-legacy'  => null,
        'stripe-checkout'        => null,
    ];
    return $map;
}

function acl_middleware(PDO $pdo, string $page): void
{
    $publicPages = ['login', 'session-status', 'serve-upload', 'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update', '2fa-verify', '2fa-verify-action', 'passkey-options', 'passkey-complete', 'public-doc', 'public-doc-pdf', 'public-redirect', 'public-project', 'public-project-upload', 'public-project-file', 'payment-receipt', 'client-onboarding', 'client-onboarding-submit', 'public-quote-action', 'public-contract-sign', 'stripe-checkout', 'stripe-success', 'stripe-webhook', 'stripe-webhook-legacy', 'legal/terms-of-service', 'legal/privacy-policy', 'legal/acceptable-use-policy', 'legal/dmca-policy', 'legal/data-retention-policy', 'account-deleted'];
    if (in_array($page, $publicPages, true)) return;

    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId === 0) return; // auth gate handles redirect

    if (($_SESSION['user']['role'] ?? '') === 'admin') return; // super-admin bypass

    // Session staleness check
    if (!empty($_SESSION['user']['permissions_hash'])) {
        $expected = compute_permissions_hash($pdo, $userId, 0);
        if ($_SESSION['user']['permissions_hash'] !== $expected) {
            audit_log($pdo, 'acl.session_stale', 'permission', null, ['page' => $page, 'user_id' => $userId]);
            session_destroy();
            header('Location: /?page=login&error=' . urlencode('Session expired. Please log in again.'));
            exit;
        }
    }

    // Approval access has a feature gate and worker/business-unit scope in
    // addition to the role ACL. Evaluate the same policy as the page and POST
    // controller so a visible route cannot diverge from its data queue.
    if ($page === 'workforce/approvals') {
        if ((new \App\Services\TimeApprovalPolicy($pdo))->canAccessQueue($userId)) {
            return;
        }
        audit_log($pdo, 'acl.denied', 'permission', null, ['page' => $page, 'required' => 'time-approval-policy', 'user_id' => $userId]);
        deny_response($page);
    }

    $map = page_permission_map();
    $perm = $map[$page] ?? null;

    // null permission = authenticated users only, no specific permission needed
    if ($perm === null) {
        // Check if the page is actually in the map (mapped with null intent)
        // vs unmapped (not in the map at all)
        if (array_key_exists($page, $map)) {
            return; // Mapped as null = auth-only, allow
        }
        // Not in the map at all = unmapped page, deny
        audit_log($pdo, 'acl.denied_unmapped', 'permission', null, ['page' => $page, 'user_id' => $userId]);
        deny_response($page);
    }

    $permissions = is_array($perm) ? $perm : [$perm];
    foreach ($permissions as $candidate) {
        if (user_can($pdo, $userId, $candidate, 0)) return;
    }

    audit_log($pdo, 'acl.denied', 'permission', null, ['page' => $page, 'required' => implode('|', $permissions), 'user_id' => $userId]);
    deny_response($page);
}

function is_ajax_request(): bool
{
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($requestedWith === 'xmlhttprequest') return true;
    if (($_GET['ajax'] ?? '') === '1') return true;
    if (($_POST['ajax'] ?? '') === '1') return true;
    return false;
}

function deny_response(string $page): void
{
    if (is_ajax_request()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Permission denied']);
        exit;
    }
    // Prevent infinite redirect loop: if the target page IS the redirect target,
    // show a 403 page instead of redirecting again
    if ($page === 'landing' || $page === 'user-dashboard' || $page === 'quote/quotes-list') {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f9fafb">';
        echo '<div style="text-align:center;padding:40px;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)">';
        echo '<h1 style="color:#dc2626;margin:0 0 12px">Access Denied</h1>';
        echo '<p style="color:#6b7280;margin:0">You do not have permission to access this page. Please contact an administrator.</p>';
        $logoutCsrf = htmlspecialchars((string)($_SESSION['csrf'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<form method="post" action="/?page=logout" style="margin:16px 0 0"><input type="hidden" name="csrf" value="' . $logoutCsrf . '"><button type="submit" style="border:0;background:none;padding:0;color:#3b82f6;text-decoration:none;cursor:pointer">Log out</button></form>';
        echo '</div></body></html>';
        exit;
    }
    header('Location: /?page=user-dashboard&error=' . urlencode('You do not have permission to access that page.'));
    exit;
}
