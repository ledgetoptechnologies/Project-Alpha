<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectWorkflowUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProjectListCardsAreKeyboardAccessibleLinksWithoutHijackingActions(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/project/projects-list.php');

        self::assertStringContainsString('class="project-row-link"', $view);
        self::assertStringContainsString('/?page=project/projects-details&amp;id=<?php echo (int)$project[\'id\']; ?>', $view);
        self::assertStringContainsString('.project-row-link::after{content:"";position:absolute;inset:0;z-index:1}', $view);
        self::assertStringContainsString('.project-row-link:focus-visible::after', $view);
        self::assertStringContainsString('.project-row-actions>a,.project-row-actions>form{position:relative;z-index:2}', $view);
        self::assertStringContainsString('<form method="post" action="/?page=project/projects-delete"', $view);
        self::assertStringContainsString("'Archive'", $view);
        self::assertStringContainsString('name="lifecycle_action"', $view);
        self::assertStringContainsString('>View Project</a>', $view);
    }

    public function testProjectCreateUsesDynamicClientTagPickers(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/project/projects-create.php');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $edit = file_get_contents($this->root . '/src/views/pages/project/projects-edit.php');
        $script = file_get_contents($this->root . '/public/assets/js/project-form.js');
        $settingsScript = file_get_contents($this->root . '/public/assets/js/project-settings.js');
        $controller = file_get_contents($this->root . '/src/controllers/project/projects_create.php');

        self::assertStringContainsString('data-project-client-picker="project"', (string)$view);
        self::assertStringContainsString('data-project-client-picker="invoice"', (string)$view);
        self::assertStringContainsString('$activeOrgName', (string)$view);
        self::assertStringContainsString('value="<?php echo $activeOrgId > 0 ? (int)$activeOrgId : \'\'; ?>"', (string)$view);
        self::assertStringNotContainsString('name="project_client_ids[]" multiple', (string)$view);
        self::assertStringContainsString('/?page=project/projects-edit&amp;id=', (string)$details);
        self::assertStringContainsString('data-legacy-project-settings-panel style="display:none"', (string)$details);
        self::assertStringContainsString('data-project-settings-contact-manager', (string)$edit);
        self::assertStringContainsString('data-project-settings-picker="project"', (string)$edit);
        self::assertStringContainsString('data-project-settings-picker="invoice"', (string)$edit);
        self::assertStringContainsString('data-project-settings-picker="links"', (string)$edit);
        self::assertStringContainsString('/assets/js/project-settings.js', (string)$edit);
        self::assertStringContainsString('/?page=project/client-options', (string)$script);
        self::assertStringContainsString('is_primary_department_contact', (string)$script);
        self::assertStringNotContainsString('selected.project.add(id)', (string)$script);
        self::assertStringNotContainsString('/?page=clients-search&term=', (string)$script);
        self::assertStringNotContainsString('/?page=clients-search&term=', (string)$settingsScript);
        self::assertStringNotContainsString('project_invoice_manual_emails', (string)$view);
        self::assertStringNotContainsString('project_invoice_manual_emails', (string)$edit);
        self::assertStringContainsString('Primary billed contact', (string)$view);
        self::assertStringContainsString('Primary billed contact', (string)$edit);
        self::assertStringContainsString('defaultPrimaryRecipient', (string)$script);
        self::assertStringContainsString('selected.invoice.add(primaryId)', (string)$settingsScript);
        self::assertStringContainsString('project_invoice_use_organization_email', (string)$view);
        self::assertStringContainsString('project_invoice_use_organization_email', (string)$edit);
        self::assertStringContainsString('initializedNewPicker', (string)$script);
        self::assertStringContainsString('project_invoice_link_client_ids[]', (string)$settingsScript);
        self::assertStringContainsString('$projectInvoiceRecipientIds ?? []', (string)$controller);
    }

    public function testProjectFilesAreSeparateFromFormsAndDocs(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0008_project_file_uploads.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $handler = file_get_contents($this->root . '/src/controllers/project/project_files_handler.php');
        $download = file_get_contents($this->root . '/src/controllers/project/project_file_download.php');
        $router = file_get_contents($this->root . '/public/index.php');
        $acl = file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $helpers = file_get_contents($this->root . '/src/utils/project_files.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_file_folders', (string)$migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_files', (string)$migration);
        self::assertStringContainsString('client_upload_enabled', (string)$migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_file_folders', (string)$baseline);
        self::assertStringContainsString('id="project-files"', (string)$details);
        self::assertStringContainsString('These are separate from Forms &amp; Docs.', (string)$details);
        self::assertStringContainsString('name="project_file"', (string)$details);
        self::assertStringContainsString('/?page=project/project-file-download&id=', (string)$details);
        self::assertStringContainsString('move_uploaded_file', (string)$handler);
        self::assertStringContainsString('project_files_folder_dir($projectId, $folderId)', (string)$handler);
        self::assertStringContainsString('require_record_ownership($pdo, \'projects\', $projectId)', (string)$download);
        self::assertStringContainsString('project/project-files', (string)$router);
        self::assertStringContainsString('project/project-file-download', (string)$router);
        self::assertStringContainsString("'project/project-files'           => 'projects.edit'", (string)$acl);
        self::assertStringContainsString("'project/project-file-download'   => 'projects.view'", (string)$acl);
        self::assertStringContainsString('src/uploads/projects', (string)$helpers);
    }

    public function testProjectPublicLinksHavePasswordAndCapabilityControls(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0021_project_public_links.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $edit = file_get_contents($this->root . '/src/views/pages/project/projects-edit.php');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $update = file_get_contents($this->root . '/src/controllers/project/projects_update.php');
        $portal = file_get_contents($this->root . '/src/controllers/public_view/public_project.php');
        $upload = file_get_contents($this->root . '/src/controllers/public_view/public_project_upload.php');
        $download = file_get_contents($this->root . '/src/controllers/public_view/public_project_file.php');
        $router = file_get_contents($this->root . '/public/index.php');
        $acl = file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $helper = file_get_contents($this->root . '/src/utils/public_project_links.php');

        foreach (['public_project_enabled', 'public_project_token', 'public_project_password_hash', 'public_project_can_upload', 'public_project_can_request_changes'] as $column) {
            self::assertStringContainsString($column, (string)$migration);
            self::assertStringContainsString($column, (string)$baseline);
            self::assertStringContainsString($column, (string)$edit);
            self::assertStringContainsString($column, (string)$update);
        }
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_public_events', (string)$migration);
        self::assertStringContainsString('Public Project Link', (string)$edit);
        self::assertStringContainsString('pa_project_public_badge_html', (string)$details);
        self::assertStringContainsString('Public Link Activity', (string)$details);
        self::assertStringContainsString('password_hash($publicProjectPassword', (string)$update);
        self::assertStringContainsString('password_verify($code, $hash)', (string)$portal);
        self::assertStringContainsString('pa_project_public_document_url', (string)$portal);
        self::assertStringContainsString('validate_and_store_upload', (string)$upload);
        self::assertStringContainsString('reject_pdf_active_content', (string)$upload);
        self::assertStringContainsString('pa_project_public_is_unlocked($project, $token)', (string)$download);
        self::assertStringContainsString('public-project-upload', (string)$router);
        self::assertStringContainsString('public-project-file', (string)$router);
        self::assertStringContainsString("'public-project'      => null", (string)$acl);
        self::assertStringContainsString('function pa_project_public_resolve', (string)$helper);
    }

    public function testDepartmentPrimaryContactsCanBeManaged(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/organization/organization-view.php');
        $handler = file_get_contents($this->root . '/src/controllers/organization/organization_departments.php');
        $endpoint = file_get_contents($this->root . '/src/controllers/project/project_client_options.php');

        self::assertStringContainsString('name="is_primary"', (string)$view);
        self::assertStringContainsString('set_primary_contact', (string)$view);
        self::assertStringContainsString('SET is_primary = 0 WHERE department_id = ?', (string)$handler);
        self::assertStringContainsString('is_primary_department_contact', (string)$endpoint);
    }

    public function testOrganizationLinksSwitchFromOverallFolderToDepartmentLinks(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $originalMigration = file_get_contents($this->root . '/database/migrations/0006_org_link_strategy_and_link_only_onboarding.sql');
        $migration = file_get_contents($this->root . '/database/migrations/0022_dynamic_org_link_strategy.sql');
        $view = file_get_contents($this->root . '/src/views/pages/organization/organization-view.php');
        $handler = file_get_contents($this->root . '/src/controllers/organization/organization_departments.php');
        $resolver = file_get_contents($this->root . '/src/services/LinkResolverService.php');

        self::assertStringContainsString("DEFAULT 'overall_folder'", (string)$baseline);
        self::assertStringContainsString("DEFAULT ''department_links_only''", (string)$originalMigration);
        self::assertStringContainsString("DEFAULT 'overall_folder'", (string)$migration);
        self::assertStringContainsString('PA uses the overall organization folder until the first department is added.', (string)$view);
        self::assertStringContainsString('When a first department is created, PA switches this organization to department links only', (string)$view);
        self::assertStringContainsString('$existingDepartmentCount === 0', (string)$handler);
        self::assertStringContainsString('link_strategy = "department_links_only"', (string)$handler);
        self::assertStringContainsString('removeResolverOrganizationLinks($organizationId)', (string)$handler);
        self::assertStringContainsString('autoGenerateForDepartment($departmentId)', (string)$handler);
        self::assertStringContainsString('function removeResolverOrganizationLinks', (string)$resolver);
    }

    public function testStandaloneClientsHaveAFirstClassDetailsPageForLinks(): void
    {
        $list = file_get_contents($this->root . '/src/views/pages/client/clients-list.php');
        $details = file_get_contents($this->root . '/src/views/pages/client/client-details.php');
        $organization = file_get_contents($this->root . '/src/views/pages/organization/organization-view.php');
        $permissions = file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $update = file_get_contents($this->root . '/src/controllers/client/clients_update.php');

        self::assertStringContainsString('/?page=client/client-details&id=', (string)$list);
        self::assertStringContainsString("\$entityType = 'client'", (string)$details);
        self::assertStringContainsString("include __DIR__ . '/../../components/links_section.php'", (string)$details);
        self::assertStringContainsString('Standalone client', (string)$details);
        self::assertStringContainsString('/?page=client/client-details&id=', (string)$organization);
        self::assertStringContainsString("'client/client-details'  => 'clients.view'", (string)$permissions);
        self::assertStringContainsString("page=client/client-details&id=' . \$id", (string)$update);
    }

    public function testClientListOptsIntoAccessibleLivePartialFiltering(): void
    {
        $list = file_get_contents($this->root . '/src/views/pages/client/clients-list.php');
        $template = file_get_contents($this->root . '/src/views/templates/components/document-filter.html.twig');
        $script = file_get_contents($this->root . '/public/assets/js/client-list-live-filter.js');

        self::assertStringContainsString("'live_filter_fields' => ['q', 'org']", (string)$list);
        self::assertStringContainsString("'live_filter_debounce' => 300", (string)$list);
        self::assertStringContainsString('data-live-filter-fields', (string)$template);
        self::assertStringContainsString('aria-live="polite"', (string)$template);
        self::assertStringContainsString('projectAlpha.registerPage([', (string)$script);
        self::assertStringContainsString("'client/clients-list'", (string)$script);
        self::assertStringContainsString("'organization/organizations-list'", (string)$script);
        self::assertStringContainsString('window.navigateToPage(state.page, false)', (string)$script);
    }

    public function testResolverFoldersUseProviderAvailabilityInsteadOfCalendarExpiration(): void
    {
        $resolver = file_get_contents($this->root . '/src/services/LinkResolverService.php');
        $links = file_get_contents($this->root . '/src/views/components/links_section.php');
        $expirationCron = file_get_contents($this->root . '/src/cron/link_expiration_checker.php');
        $settings = file_get_contents($this->root . '/src/views/pages/settings/links.php');

        self::assertStringNotContainsString('$expirationDate = date(', (string)$resolver);
        self::assertStringContainsString('SET title = ?, url = ?, expiration_date = NULL', (string)$resolver);
        self::assertStringContainsString('markResolverLinkUnavailable($context, $linkType)', (string)$resolver);
        self::assertStringContainsString("\$statusText = \$isResolverLink ? 'Unavailable' : 'Expired';", (string)$links);
        self::assertStringContainsString("link_source <> 'resolver'", (string)$expirationCron);
        self::assertStringContainsString('Resolver-managed folders do not expire by date.', (string)$settings);
    }

    public function testClientAndOrganizationSuiteAddressesFlowToDocuments(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $migration = file_get_contents($this->root . '/database/migrations/0013_ensure_client_and_org_address_line2.sql');
        $clientCreate = file_get_contents($this->root . '/src/views/pages/client/clients-create.php');
        $clientEdit = file_get_contents($this->root . '/src/views/pages/client/clients-edit.php');
        $clientCreateController = file_get_contents($this->root . '/src/controllers/client/clients_create.php');
        $clientUpdateController = file_get_contents($this->root . '/src/controllers/client/clients_update.php');
        $clientOnboarding = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding.php');
        $orgCreate = file_get_contents($this->root . '/src/views/pages/organization/organizations-create.php');
        $orgEdit = file_get_contents($this->root . '/src/views/pages/organization/organizations-edit.php');
        $orgCreateController = file_get_contents($this->root . '/src/controllers/organization/organizations_create.php');
        $orgUpdateController = file_get_contents($this->root . '/src/controllers/organization/organizations_update.php');
        $orgSearchController = file_get_contents($this->root . '/src/controllers/organization/org_search.php');
        $clientCreateScript = file_get_contents($this->root . '/public/assets/js/clients-create-logic.js');
        $quoteDetail = file_get_contents($this->root . '/src/views/pages/quote/quote-details.php');
        $longQuoteDetail = file_get_contents($this->root . '/src/views/pages/quote/long-term-quote-details.php');
        $contractDetail = file_get_contents($this->root . '/src/views/pages/contract/contract-details.php');
        $longContractDetail = file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php');
        $invoiceDetail = file_get_contents($this->root . '/src/views/pages/invoice/invoice-details.php');
        $projectInvoiceDetail = file_get_contents($this->root . '/src/views/pages/project/project-invoice-details.php');
        $accountEdit = file_get_contents($this->root . '/src/views/pages/auth/account-edit.php');
        $systemSettings = file_get_contents($this->root . '/src/views/pages/settings/system.php');

        self::assertStringContainsString('address_line2 VARCHAR(255) NULL', (string)$baseline);
        self::assertStringContainsString('ALTER TABLE clients ADD COLUMN address_line2', (string)$migration);
        self::assertStringContainsString('ALTER TABLE organizations ADD COLUMN address_line2', (string)$migration);
        foreach ([$clientCreate, $clientEdit, $clientOnboarding, $orgCreate, $orgEdit, $accountEdit, $systemSettings] as $view) {
            self::assertStringContainsString('Apartment / Suite', (string)$view);
        }
        self::assertStringContainsString('$client[\'postal_code\'] ?? \'\'', (string)$clientEdit);
        foreach ([$clientCreateController, $clientUpdateController, $orgCreateController, $orgUpdateController] as $controller) {
            self::assertStringContainsString('address_line2', (string)$controller);
        }
        self::assertStringContainsString('pa_organization_address_select($pdo)', (string)$orgSearchController);
        self::assertStringContainsString('dataset.clientAddressDirty', (string)$clientCreateScript);
        self::assertStringContainsString('fillAddressFromOrg(item)', (string)$clientCreateScript);
        self::assertStringContainsString("\$appConfig['primary_state']", (string)$orgCreate);
        self::assertStringContainsString("\$org['state'] ?: (\$appConfig['primary_state'] ?? '')", (string)$orgEdit);
        foreach ([$quoteDetail, $longQuoteDetail, $contractDetail, $longContractDetail, $invoiceDetail] as $documentView) {
            self::assertStringContainsString('address_line2', (string)$documentView);
            self::assertStringContainsString('pa_document_recipient(', (string)$documentView);
        }
        self::assertStringContainsString('organization_address_line2', (string)$projectInvoiceDetail);
        self::assertStringContainsString('pa_document_recipient($pi)', (string)$projectInvoiceDetail);
        self::assertStringContainsString('$documentPartyRecipient = $billingRecipient;', (string)$projectInvoiceDetail);
        self::assertStringContainsString('components/document_parties.php', (string)$projectInvoiceDetail);
    }

    public function testWorkforceTimekeepingUsesTheUnifiedDomainService(): void
    {
        $view = (string) file_get_contents($this->root . '/src/views/pages/workforce/time.php');
        $controller = (string) file_get_contents($this->root . '/src/controllers/workforce/action.php');
        $service = (string) file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');
        $migration = (string) file_get_contents($this->root . '/database/migrations/0039_unified_workforce_timekeeping.sql');

        self::assertStringContainsString('Entries are stored in UTC', $view);
        self::assertStringContainsString('Record time', $view);
        self::assertStringContainsString('Add duration', $view);
        self::assertStringContainsString('Start timer', $view);
        self::assertStringContainsString('Exact times', $view);
        self::assertStringContainsString('Add to invoice', $view);
        self::assertStringContainsString('Type to search clients', $view);
        self::assertStringContainsString('name="client_id"', $view);
        self::assertStringContainsString('name="invoice_id"', $view);
        self::assertStringContainsString('Assigned project', $view);
        self::assertStringContainsString("['clock-in','clock-out','break-start','break-end','manual-create','quick-duration','resubmit','cancel']", $controller);
        self::assertStringContainsString('public function clockIn', $service);
        self::assertStringContainsString('public function startBreak', $service);
        self::assertStringContainsString('public function saveManual', $service);
        self::assertStringContainsString('FOR UPDATE', $service);
        self::assertStringContainsString('Client and invoice details are available only to timekeeping managers.', $service);
        self::assertStringContainsString('public function reviseEntry', $service);
        self::assertStringContainsString('work_timer_locks', $migration);
        self::assertStringContainsString('work_time_breaks', $migration);
    }

    public function testWorkforceCleanPathsReachTheFrontController(): void
    {
        $htaccess = (string) file_get_contents($this->root . '/public/.htaccess');

        foreach ([
            '^time/?$ index.php?page=workforce/time',
            '^time/action/?$ index.php?page=workforce/action',
            '^workforce/?$ index.php?page=workforce/overview',
            '^workforce/action/?$ index.php?page=workforce/action',
            '^approvals/?$ index.php?page=workforce/approvals',
            '^approvals/action/?$ index.php?page=workforce/action',
            '^pay/?$ index.php?page=workforce/pay',
            '^pay/action/?$ index.php?page=workforce/action',
        ] as $route) {
            self::assertStringContainsString('RewriteRule ' . $route . ' [QSA,L]', $htaccess);
        }

        self::assertStringNotContainsString('api/v1/integrations/alphaledger', $htaccess);
    }

    public function testWorkforceUsesPaAccountsSettingsAndLoginDesign(): void
    {
        $accounts = (string) file_get_contents($this->root . '/src/views/pages/auth/accounts.php');
        $accountEdit = (string) file_get_contents($this->root . '/src/views/pages/auth/account-edit.php');
        $accountCreate = (string) file_get_contents($this->root . '/src/controllers/accounts/accounts_create.php');
        $accountUpdate = (string) file_get_contents($this->root . '/src/controllers/accounts/accounts_update.php');
        $settings = (string) file_get_contents($this->root . '/src/views/pages/settings/workflow.php');
        $login = (string) file_get_contents($this->root . '/src/views/pages/auth/login.php');
        $twoFactor = (string) file_get_contents($this->root . '/src/views/pages/auth/two_factor_verify.php');
        $authLoginStyles = (string) file_get_contents($this->root . '/public/assets/auth-login.css');
        $authHeader = (string) file_get_contents($this->root . '/src/views/partials/auth_header.php');
        $navigation = (string) file_get_contents($this->root . '/public/assets/navigation.js');

        foreach ([$accounts, $accountEdit] as $view) {
            self::assertStringContainsString('employee_first_name', $view);
            self::assertStringContainsString('employee_project_ids[]', $view);
            self::assertStringContainsString('Employee role', $view);
        }
        foreach ([$accountCreate, $accountUpdate] as $controller) {
            self::assertStringContainsString('employee_profiles', $controller);
            self::assertStringContainsString('project_assignments', $controller);
            self::assertStringContainsString('WorkforceSettings::load', $controller);
        }
        self::assertStringContainsString('workforce_default_hourly_rate', $settings);
        self::assertStringContainsString('workforce_require_project', $settings);
        self::assertStringContainsString('login-shell', $login);
        self::assertStringContainsString('$brandLogo', $login);
        self::assertStringNotContainsString('IMG_0342.JPG', $login);
        self::assertStringContainsString("asset_url('/assets/auth-login.css')", $login);
        self::assertStringContainsString("asset_url('/assets/auth-login.css')", $twoFactor);
        self::assertStringContainsString('class="login-shell"', $twoFactor);
        self::assertStringContainsString('class="login-story"', $twoFactor);
        self::assertStringContainsString('class="login-panel"', $twoFactor);
        self::assertStringContainsString('Verify your sign-in', $twoFactor);
        self::assertStringContainsString('name="remember_device"', $twoFactor);
        self::assertStringContainsString('name="code"', $twoFactor);
        self::assertStringContainsString('autocomplete="one-time-code"', $twoFactor);
        self::assertStringNotContainsString('IMG_0342.JPG', $twoFactor);
        self::assertStringNotContainsString('photo-credit', $twoFactor);
        self::assertStringContainsString('.two-factor-code', $authLoginStyles);
        self::assertStringContainsString('@media (max-width: 480px)', $authLoginStyles);
        self::assertStringContainsString("\$favicon = \$logo !== '' ? \$logo : '/assets/favicon-32.png';", $authHeader);
        self::assertStringContainsString('<link rel="icon" href="<?php echo htmlspecialchars($favicon); ?>">', $authHeader);
        self::assertStringContainsString("'/workforce': 'workforce/overview'", $navigation);
    }

    public function testPdfFacingDocumentCopyUsesWorkAndJobWording(): void
    {
        $quoteDetails = file_get_contents($this->root . '/src/views/pages/quote/quote-details.php');
        $contractDetails = file_get_contents($this->root . '/src/views/pages/contract/contract-details.php');
        $contractEdit = file_get_contents($this->root . '/src/views/pages/contract/contracts-edit.php');

        self::assertStringContainsString('Scope of Work', (string)$quoteDetails);
        self::assertStringContainsString('Scope of Work', (string)$contractDetails);
        self::assertStringNotContainsString('Scope of Project', (string)$quoteDetails);
        self::assertStringNotContainsString('Scope of Project', (string)$contractDetails);
        self::assertStringContainsString("' (Job '", (string)$contractEdit);
    }

    public function testInvoiceCreateCanSaveWithoutSendingOrSaveAndSend(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/invoice/invoices-create.php');
        $controller = file_get_contents($this->root . '/src/controllers/invoice/invoices_create.php');
        $updateController = file_get_contents($this->root . '/src/controllers/invoice/invoices_update.php');
        $unbilledTime = file_get_contents($this->root . '/src/controllers/time-tracking/time_entries_unbilled.php');

        self::assertStringContainsString('value="save" class="btn">Save Invoice</button>', (string)$view);
        self::assertStringContainsString('value="finalize_send" class="btn btn-primary">Save &amp; Send</button>', (string)$view);
        self::assertStringContainsString('display:flex;gap:8px;flex-wrap:wrap;padding-top:8px', (string)$view);
        self::assertStringNotContainsString('Save Draft</button>', (string)$view);
        self::assertStringContainsString("\$invoiceAction = (string)(\$_POST['invoice_action'] ?? 'save');", (string)$controller);
        self::assertStringContainsString("['save', 'draft', 'finalize', 'finalize_send']", (string)$controller);
        self::assertStringContainsString('$finalizeAndSend = $invoiceAction === \'finalize_send\';', (string)$controller);
        self::assertStringContainsString('client_id = ? OR client_id IS NULL OR client_id = 0', (string)$controller);
        self::assertStringContainsString('CASE WHEN client_id IS NULL OR client_id = 0 THEN ? ELSE client_id END', (string)$controller);
        self::assertStringContainsString('UPDATE time_entries te', (string)$updateController);
        self::assertStringContainsString('SET te.client_id = ?, te.invoice_id = ?', (string)$updateController);
        self::assertStringContainsString('SET te.billed = 0, te.invoice_item_id = NULL, te.invoice_id = NULL', (string)$updateController);
        self::assertStringContainsString('te.client_id = ? OR te.client_id IS NULL OR te.client_id = 0', (string)$unbilledTime);
    }

    public function testDocumentsCanAttachToActiveOrNotStartedProjects(): void
    {
        $projectSelection = file_get_contents($this->root . '/src/utils/project_selection.php');
        $contractCreate = file_get_contents($this->root . '/src/views/pages/contract/contracts-create.php');
        $contractEdit = file_get_contents($this->root . '/src/views/pages/contract/contracts-edit.php');
        $contractEditScript = file_get_contents($this->root . '/public/assets/js/contracts-edit-logic.js');
        $contractUpdate = file_get_contents($this->root . '/src/controllers/contract/contracts_update.php');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');

        self::assertStringContainsString('p.status IN ("active","not_started")', (string)$projectSelection);
        self::assertStringContainsString('p.status IN ("active","not_started")', (string)$contractCreate);
        self::assertStringContainsString('name="project_id"', (string)$contractEdit);
        self::assertStringContainsString('id="contractEditClientSearch"', (string)$contractEdit);
        self::assertStringContainsString('id="contractEditClientId"', (string)$contractEdit);
        self::assertStringNotContainsString('SELECT id, name FROM clients ORDER BY name ASC', (string)$contractEdit);
        self::assertStringContainsString('/?page=clients-search&term=', (string)$contractEditScript);
        self::assertStringContainsString('/?page=projects-search&client_id=', (string)$contractEditScript);
        self::assertStringContainsString('pa_project_is_active_for_client($pdo, $project_id, $client_id', (string)$contractUpdate);
        self::assertStringContainsString('project_documents WHERE document_type="contract" AND document_id=?', (string)$contractUpdate);
        self::assertStringContainsString('foreach ($projectClients as $projectClient)', (string)$details);
        self::assertStringContainsString('client_id IN (SELECT id FROM clients WHERE organization_id = ?)', (string)$details);
    }

    public function testContractEditPostsActualDepositValue(): void
    {
        $view=(string)file_get_contents($this->root.'/src/views/pages/contract/contracts-edit.php');
        $controller=(string)file_get_contents($this->root.'/src/controllers/contract/contracts_update.php');
        self::assertStringContainsString('depositEditValue/$contractTotal*100',$view);
        self::assertStringContainsString('name="deposit_value"',$view);
        self::assertStringNotContainsString('name="deposit_amount" value=',$view);
        self::assertStringContainsString("\$deposit_value = (float)(\$_POST['deposit_value'] ?? \$_POST['deposit_amount'] ?? 0);",$controller);
        self::assertStringNotContainsString('deposit_percentage',$controller);
        self::assertStringContainsString('pricing_recompute_contract_percentage_deposit($pdo,(int)$organizationId,$id,(string)$deposit_value)',$controller);
    }

    public function testDocumentEditorsRevalidateLockedStateBeforeMutation(): void
    {
        $quote=(string)file_get_contents($this->root.'/src/controllers/quote/quotes_update.php');
        $contract=(string)file_get_contents($this->root.'/src/controllers/contract/contracts_update.php');
        $invoice=(string)file_get_contents($this->root.'/src/controllers/invoice/invoices_update.php');
        $date=(string)file_get_contents($this->root.'/src/controllers/document_date_update_handler.php');
        foreach([[$quote,"DocumentPolicy::assertMutable(\$pdo,'quote',\$id,'commercial',true)",'UPDATE quotes SET'],[$contract,"DocumentPolicy::assertMutable(\$pdo,'contract',\$id,'commercial',true)",'UPDATE contracts'],[$invoice,"DocumentPolicy::assertMutable(\$pdo,'invoice',\$id,'monetary_adjustment',true)",'UPDATE invoices']] as [$source,$locked,$update]){
            $begin=strpos($source,'$pdo->beginTransaction();');$lock=strpos($source,$locked,$begin);$ownership=strpos($source,'require_record_ownership',$lock);$mutation=strpos($source,$update,$ownership);
            self::assertNotFalse($begin);self::assertNotFalse($lock);self::assertNotFalse($ownership);self::assertNotFalse($mutation);self::assertTrue($begin<$lock&&$lock<$ownership&&$ownership<$mutation);
        }
        self::assertGreaterThanOrEqual(3,substr_count($quote,'$jobClientLockMessage'));
        self::assertGreaterThanOrEqual(3,substr_count($contract,'$jobClientLockMessage'));
        self::assertStringContainsString('status,finalized_at,last_sent_revision FROM invoices WHERE contract_id=?',$contract);
        self::assertStringContainsString("\$linkedInvoiceLockSuffix=\$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''",$contract);
        self::assertStringContainsString("(int)(\$linkedInvoice['last_sent_revision']??0)>0",$contract);
        self::assertGreaterThanOrEqual(2,substr_count($invoice,'pricing_invoice_is_fixed_total_installment($pdo,$id)'));
        self::assertTrue(strpos($invoice,"\$isDraft=\$invoiceStatus==='draft'",strpos($invoice,"DocumentPolicy::assertMutable(\$pdo,'invoice',\$id,'monetary_adjustment',true)"))!==false);
        $dateBegin=strpos($date,'$pdo->beginTransaction();');$dateLock=strpos($date,"DocumentPolicy::assertMutable(\$pdo,\$policyType,\$id,'administrative',true)",$dateBegin);$dateOwner=strpos($date,'require_record_ownership',$dateLock);$dateUpdate=strpos($date,'switch ($type)',$dateOwner);
        self::assertTrue($dateBegin<$dateLock&&$dateLock<$dateOwner&&$dateOwner<$dateUpdate);
        self::assertStringContainsString('$allowedRedirects=',$date);self::assertStringNotContainsString('urlencode($e->getMessage())',$date);
    }

    public function testStaffAndPublicQuoteConversionsUseFrozenPricingLineage(): void
    {
        $staff=(string)file_get_contents($this->root.'/src/controllers/quote/quote_approve.php');
        $public=(string)file_get_contents($this->root.'/src/controllers/public_view/public_quote_action.php');
        foreach([$staff,$public] as $controller){
            self::assertStringContainsString('pricing_finalize_derived_document_revision(',$controller);
            self::assertStringContainsString("'quote'",$controller);
            self::assertStringNotContainsString('pricing_copy_document_override',$controller);
        }
        self::assertStringContainsString('FOR UPDATE',$staff);
        self::assertStringContainsString("WHERE q.id=? FOR UPDATE",$public);
        self::assertStringContainsString('WHERE token=? LIMIT 1 FOR UPDATE',$public);
        self::assertSame(1,substr_count($public,'$pdo->beginTransaction();'));
        self::assertLessThan(strpos($public,'WHERE token=? LIMIT 1 FOR UPDATE'),strpos($public,'WHERE q.id=? FOR UPDATE'));
        self::assertStringContainsString("if(\$currentStatus===\$expectedStatus)",$public);
        self::assertStringContainsString("http_response_code(409)",$public);
        self::assertStringContainsString('This quote already has a different response.',$public);
        self::assertStringContainsString("http_response_code(410)",$public);
        $terminalizePosition=strrpos($public,'pa_public_link_terminalize($pdo, \'quote\'');
        self::assertNotFalse($terminalizePosition);
        self::assertGreaterThan($terminalizePosition,strpos($public,'$pdo->commit();',$terminalizePosition));
        self::assertStringContainsString('if ($pdo->inTransaction()) { $pdo->rollBack(); }',$public);
        self::assertStringContainsString('pricing_recompute_contract_percentage_deposit',$staff);
        self::assertStringContainsString('pricing_recompute_contract_percentage_deposit',$public);
    }

    public function testRecurringAndOnDemandInvoicesFreezeContractPricingButFixedTotalDoesNotReapply(): void
    {
        $recurring=(string)file_get_contents($this->root.'/src/utils/recurring_billing.php');
        $onDemand=(string)file_get_contents($this->root.'/src/controllers/contract/on_demand_invoice_generate.php');
        $proration=(string)file_get_contents($this->root.'/src/controllers/contract/long_term_recurring_service_save.php');
        self::assertGreaterThanOrEqual(2,substr_count($recurring,'pricing_finalize_derived_document_revision('));
        self::assertStringContainsString("'contract',(int)\$contractId,pricing_contract_source_revision(\$contract)",$recurring);
        self::assertStringContainsString('pa_recurring_fixed_installment_minor',$recurring);
        self::assertStringContainsString('DocumentRevisionService::snapshotAndSave($pdo,\'invoice\',$invoiceId,$createdBy,false)',$recurring);
        self::assertStringContainsString('Contract installment',$recurring);
        self::assertStringContainsString("if(\$contract['pricing_type']==='fixed_total')",$recurring);
        self::assertStringContainsString("\$discountType='none';\$discountValue=0.0;\$taxPercent=0.0;",$recurring);
        self::assertStringContainsString('pricing_finalize_derived_document_revision(',$onDemand);
        self::assertStringContainsString('contract_type = "on_demand" FOR UPDATE',$onDemand);
        self::assertStringContainsString("'contract',\$contract_id,pricing_contract_source_revision(\$contract)",$onDemand);
        self::assertStringContainsString('contract_type = "on_demand" FOR UPDATE',$onDemand);
        self::assertStringContainsString("generation_key=? LIMIT 1",$onDemand);
        self::assertStringContainsString("&idempotent=1",$onDemand);
        self::assertStringContainsString('prorationGenerationKey',$proration);
        self::assertStringContainsString('generation_key=? LIMIT 1',$proration);
        self::assertStringContainsString('$idempotentResult = $idempotentReplay ? \'&idempotent=1\' : \'\';',$proration);
        self::assertStringContainsString('$generationKey',$recurring);
        self::assertStringContainsString('created_at,generation_key',$recurring);
        $onDemandView=(string)file_get_contents($this->root.'/src/views/pages/contract/on-demand-contracts-list.php');
        self::assertMatchesRegularExpression('/<form[^>]+class="od-generate-form"[\\s\\S]*?<input[^>]+name="generation_key"[^>]+value="<\\?php echo bin2hex\\(random_bytes\\(16\\)\\); \\?>"[\\s\\S]*?<\\/form>/', $onDemandView);
        self::assertStringContainsString('name="generation_key"',(string)file_get_contents($this->root.'/src/views/pages/contract/long-term-contract-details.php'));
        self::assertStringContainsString("pricing_money_to_minor((string)(\$contract['total_invoiced']??'0'))",$onDemand);
        self::assertStringContainsString("pricing_money_to_minor((string)(\$contract['total_invoiced']??'0'))",$recurring);
        foreach(['long_term_contracts_create.php','on_demand_contracts_create.php'] as $create){$source=(string)file_get_contents($this->root.'/src/controllers/contract/'.$create);self::assertStringContainsString('pricing_finalize_document_revision(',$source);self::assertStringContainsString('pricing_recompute_contract_percentage_deposit',$source);}
        self::assertStringNotContainsString('pricing_finalize_document_revision(',(string)file_get_contents($this->root.'/src/utils/project_invoice_billing.php'));
    }

    public function testLongTermAndOnDemandBillingBehaviorsAreExplicit(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $migration = file_get_contents($this->root . '/database/migrations/0010_long_term_billing_start_mode.sql');
        $createView = file_get_contents($this->root . '/src/views/pages/contract/contracts-create.php');
        $ltCreate = file_get_contents($this->root . '/src/controllers/contract/long_term_contracts_create.php');
        $sign = file_get_contents($this->root . '/src/controllers/public_view/public_contract_sign.php');
        $start = file_get_contents($this->root . '/src/controllers/contract/long_term_contract_start_billing.php');
        $activate = file_get_contents($this->root . '/src/controllers/contract/long_term_contract_activate.php');
        $odcList = file_get_contents($this->root . '/src/views/pages/contract/on-demand-contracts-list.php');
        $editView = file_get_contents($this->root . '/src/views/pages/contract/contracts-edit.php');
        $editScript = file_get_contents($this->root . '/public/assets/js/contracts-edit-logic.js');
        $update = file_get_contents($this->root . '/src/controllers/contract/contracts_update.php');
        $ltDetails = file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php');
        $ltList = file_get_contents($this->root . '/src/views/pages/contract/long-term-contracts-list.php');
        $recurringList = file_get_contents($this->root . '/src/views/pages/invoice/recurring-invoices-list.php');
        $recurringBilling = file_get_contents($this->root . '/src/utils/recurring_billing.php');
        $invoiceLifecycle = file_get_contents($this->root . '/src/utils/invoice_lifecycle.php');
        $stripePayment = file_get_contents($this->root . '/src/controllers/webhook/stripe_payment_succeeded.php');
        $stripeCheckout = file_get_contents($this->root . '/src/controllers/webhook/stripe_checkout_completed.php');
        $stripeReconciliation = file_get_contents($this->root . '/src/utils/stripe_reconciliation_import.php');
        $recurringServices = file_get_contents($this->root . '/src/utils/recurring_services.php');
        $recurringServiceMigration = file_get_contents($this->root . '/database/migrations/0029_long_term_recurring_services.sql');
        $recurringServiceSave = file_get_contents($this->root . '/src/controllers/contract/long_term_recurring_service_save.php');
        $router = file_get_contents($this->root . '/public/index.php');
        $recurringCron = file_get_contents($this->root . '/src/cron/generate_recurring_invoices.php');

        self::assertStringContainsString("billing_start_mode ENUM('on_upload','manual')", (string)$baseline);
        self::assertStringContainsString('ADD COLUMN billing_start_mode', (string)$migration);
        self::assertStringContainsString('name="billing_start_mode" value="on_upload" checked', (string)$createView);
        self::assertStringContainsString('$next_invoice_date = null;', (string)$ltCreate);
        self::assertStringContainsString('pa_long_term_starts_billing_on_upload($contract)', (string)$sign);
        self::assertStringContainsString('generate_recurring_invoice($pdo, $contract, $appConfig)', (string)$start);
        self::assertStringContainsString('$hasGeneratedInvoices', (string)$activate);
        self::assertStringContainsString('recurring_invoice_send_on_generate_if_enabled($pdo, $invoiceId, $appConfig)', (string)$start);
        self::assertStringContainsString('UPDATE invoices SET sent_at=COALESCE(sent_at,NOW())', (string)file_get_contents($this->root . '/src/controllers/email_send.php'));
        self::assertStringContainsString('$billingText = \'Manual invoices\';', (string)$odcList);
        self::assertStringContainsString('Recurring Billing Settings', (string)$editView);
        self::assertStringContainsString('name="billing_interval_unit"', (string)$editView);
        self::assertStringContainsString('name="next_invoice_date"', (string)$editView);
        self::assertStringContainsString('id="pricePerInvoiceEditCo"', (string)$editView);
        self::assertStringContainsString('function initLongTermEditFieldsCo()', (string)$editScript);
        self::assertStringContainsString('$isLongTermContract = $contractType === \'long_term\';', (string)$update);
        self::assertStringContainsString('next_invoice_date=?', (string)$update);
        self::assertStringContainsString('Long-term recurring invoices are historical billing records and must not be rewritten.', (string)$update);
        self::assertStringContainsString('if (!$isLongTermContract)', (string)$update);
        self::assertStringContainsString("['pending', 'active', 'paused']", (string)$ltDetails);
        self::assertStringContainsString('Generate Invoice Now', (string)$ltDetails);
        self::assertStringContainsString('Email Latest Invoice', (string)$ltDetails);
        self::assertStringContainsString('Edit Billing', (string)$ltList);
        self::assertStringContainsString('invoices_generated', (string)$ltList);
        self::assertStringContainsString('latest_invoice_id', (string)$ltList);
        self::assertStringContainsString('Edit billing', (string)$recurringList);
        self::assertStringContainsString('Recurring Invoice History', (string)$recurringList);
        self::assertStringContainsString('i.invoice_type="long_term"', (string)$recurringList);
        self::assertStringContainsString('invoice_status', (string)$recurringList);
        self::assertStringContainsString('balance_due, status, due_date', (string)$recurringBilling);
        self::assertStringContainsString('invoice_complete_linked_contract_if_eligible', (string)$invoiceLifecycle);
        self::assertStringContainsString("contract_type']) !== 'regular'", (string)$invoiceLifecycle);
        foreach ([$stripePayment, $stripeCheckout, $stripeReconciliation] as $paymentPath) {
            self::assertStringContainsString('invoice_complete_linked_contract_if_eligible($pdo, $invoiceId)', (string)$paymentPath);
            self::assertStringNotContainsString("execute(['completed', \$contractId])", (string)$paymentPath);
        }
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS contract_recurring_services', (string)$recurringServiceMigration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS contract_amendments', (string)$recurringServiceMigration);
        self::assertStringContainsString('pa_recurring_services_due', (string)$recurringServices);
        self::assertStringContainsString('generate_recurring_proration_invoice', (string)$recurringServiceSave);
        self::assertStringContainsString('signed_addendum', (string)$recurringServiceSave);
        self::assertStringContainsString('Recurring Services &amp; Amendments', (string)$ltDetails);
        self::assertStringContainsString('long-term-recurring-service-save', (string)$router);
        self::assertStringContainsString('invoice_notification_process($pdo, $appConfig)', (string)$recurringCron);
        self::assertStringContainsString('invoice_notification_enqueue_generated($pdo, $invoiceId, $appConfig)', (string)$recurringBilling);
    }

    public function testInvoiceAndContractNumbersArePerDocumentType(): void
    {
        $helper = file_get_contents($this->root . '/src/utils/invoice_numbers.php');
        $invoiceCreate = file_get_contents($this->root . '/src/controllers/invoice/invoices_create.php');
        $contractCreate = file_get_contents($this->root . '/src/controllers/contract/contracts_create.php');
        $odcInvoice = file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');

        self::assertStringContainsString('invoice_type = "regular" OR invoice_type IS NULL', (string)$helper);
        self::assertStringContainsString('pa_next_invoice_doc_number($pdo, \'regular\')', (string)$invoiceCreate);
        self::assertStringContainsString('contracts WHERE contract_type = "regular"', (string)$contractCreate);
        self::assertStringContainsString('pa_next_invoice_doc_number($pdo, \'on_demand\')', (string)$odcInvoice);
    }

    public function testInvoiceLabelsAreConsistentAcrossInvoiceTypes(): void
    {
        require_once $this->root . '/src/utils/invoice_numbers.php';

        self::assertSame('I-12', pa_invoice_label(12, 'regular'));
        self::assertSame('I-12', pa_invoice_label(12, null));
        self::assertSame('LTI-12', pa_invoice_label(12, 'long_term'));
        self::assertSame('ODI-12', pa_invoice_label(12, 'on_demand'));
        self::assertSame('LTI-44', pa_invoice_label_from_row([
            'invoice_id' => 44,
            'doc_number' => null,
            'invoice_type' => 'long_term',
        ]));

        $details = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoice-details.php');
        $history = (string)file_get_contents($this->root . '/src/views/pages/invoice/recurring-invoices-list.php');
        $onDemand = (string)file_get_contents($this->root . '/src/views/pages/invoice/on-demand-invoices-list.php');
        $onDemandGenerator = (string)file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');
        $delivery = (string)file_get_contents($this->root . '/src/utils/invoice_lifecycle.php');
        $notificationDelivery = (string)file_get_contents($this->root . '/src/utils/invoice_notifications.php');
        self::assertStringContainsString('pa_invoice_label_from_row($inv)', $details);
        self::assertStringContainsString("'long_term'", $history);
        self::assertStringContainsString("'on_demand'", $onDemand);
        self::assertStringContainsString("pa_invoice_label(\$docNumber, 'on_demand')", $onDemandGenerator);
        self::assertStringContainsString('pa_invoice_label', $delivery . $notificationDelivery);
        self::assertStringNotContainsString('Invoice I-', $delivery . $notificationDelivery);
    }

    public function testCreatePageScriptsInitializeAfterAjaxNavigation(): void
    {
        $navigation = file_get_contents($this->root . '/public/assets/navigation.js');
        $clientCreate = file_get_contents($this->root . '/public/assets/js/clients-create-logic.js');
        $clientEdit = file_get_contents($this->root . '/public/assets/js/clients-edit-logic.js');
        $projectCreate = file_get_contents($this->root . '/public/assets/js/project-form.js');
        $accounts = file_get_contents($this->root . '/src/views/pages/auth/accounts.php');
        $accountsCreate = file_get_contents($this->root . '/src/controllers/accounts/accounts_create.php');

        self::assertStringContainsString('function registerPageInitializer', (string)$navigation);
        self::assertStringContainsString('runPageCleanups();', (string)$navigation);
        self::assertStringContainsString('runPageInitializers(page, mainContent);', (string)$navigation);
        self::assertStringContainsString('function shellAssetSignature', (string)$navigation);
        self::assertStringContainsString('content.reloadRequired', (string)$navigation);
        self::assertStringContainsString("urlParams.delete('page')", (string)$navigation);
        self::assertStringContainsString('link[rel~="stylesheet"][href]', (string)$navigation);
        self::assertStringContainsString('await ensurePageStylesheets(stylesheets);', (string)$navigation);
        self::assertStringContainsString("link.dataset.pageStylesheet = '1'", (string)$navigation);
        self::assertStringContainsString('form[action="/?page=client/clients-create"]', (string)$clientCreate);
        self::assertStringContainsString('dataset.orgCreateReady', (string)$clientCreate);
        self::assertStringContainsString("window.ProjectAlpha.registerPage(['client/clients-create', 'clients-create']", (string)$clientCreate);
        self::assertStringNotContainsString('setTimeout(initializeOrgCreate', (string)$clientCreate);
        self::assertStringContainsString("window.ProjectAlpha.registerPage(['client/clients-edit', 'clients-edit']", (string)$clientEdit);
        self::assertStringContainsString("window.ProjectAlpha.registerPage('project/projects-create'", (string)$projectCreate);
        self::assertStringContainsString('function initAccountCreateForm()', (string)$accounts);
        self::assertStringContainsString("window.ProjectAlpha.registerPage('accounts', initAccountCreateForm)", (string)$accounts);
        self::assertStringContainsString("header('Location: /?page=accounts&created=1')", (string)$accountsCreate);
    }

    public function testVersionedAssetsPreventStalePageScripts(): void
    {
        $appVersion = file_get_contents($this->root . '/src/utils/app_version.php');
        $header = file_get_contents($this->root . '/src/views/partials/header.php');
        $settings = file_get_contents($this->root . '/src/views/pages/settings.php');
        $footer = file_get_contents($this->root . '/src/views/partials/footer.php');
        $payments = file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        $projectCreate = file_get_contents($this->root . '/src/views/pages/project/projects-create.php');

        self::assertStringContainsString('function asset_url(string $path): string', (string)$appVersion);
        self::assertStringContainsString("filemtime(\$filePath)", (string)$appVersion);
        self::assertStringContainsString("\$_SERVER['DOCUMENT_ROOT']", (string)$appVersion);
        self::assertStringContainsString("asset_url('/assets/styles.css')", (string)$header);
        self::assertStringContainsString("asset_url('/assets/settings.css')", (string)$header);
        self::assertStringContainsString('meta name="project-alpha-version"', (string)$header);
        self::assertSame(6, substr_count((string)$header, 'data-pa-shell-asset'));
        self::assertStringContainsString("asset_url('/assets/js/item-library.js')", (string)$header);
        self::assertStringContainsString("asset_url('/assets/js/workforce.js')", (string)$header);
        self::assertStringNotContainsString("asset_url('/assets/settings.css')", (string)$settings);
        self::assertStringContainsString("asset_url('/assets/js/csrf-auto-link.js')", (string)$footer);
        self::assertStringContainsString("asset_url('/assets/js/payments-create-logic.js')", (string)$payments);
        self::assertStringContainsString("asset_url('/assets/js/project-form.js')", (string)$projectCreate);
        self::assertStringNotContainsString('<script src="/assets/js/payments-create-logic.js"', (string)$payments);
    }

    public function testSidebarFooterControlsShareInteractionStyles(): void
    {
        $header = (string)file_get_contents($this->root . '/src/views/partials/header.php');
        $styles = (string)file_get_contents($this->root . '/public/assets/styles.css');

        self::assertStringContainsString('class="nav-footer-form" method="post" action="/?page=logout"', $header);
        self::assertStringContainsString('<button class="settings" type="submit">Logout</button>', $header);
        self::assertStringNotContainsString('class="settings" type="submit" style=', $header);
        foreach ([
            '.nav-footer .settings{',
            '.nav-footer .settings:hover',
            '.nav-footer .settings:active',
            '.nav-footer .settings:focus-visible',
            '.nav-footer .settings.active',
        ] as $selector) {
            self::assertStringContainsString($selector, $styles);
        }
        self::assertStringContainsString('.nav-footer-form{margin:0;width:100%}', $styles);
        self::assertMatchesRegularExpression(
            '/\.nav-footer \.settings\s*\{(?=[^}]*background:rgba\(255,255,255,0\.06\))(?=[^}]*color:rgba\(255,255,255,0\.9\))[^}]*\}/s',
            $styles
        );
        self::assertStringContainsString('-webkit-appearance:none;', $styles);
        self::assertStringContainsString('appearance:none;', $styles);
        self::assertStringNotContainsString('style="margin-top:8px;display:block"', $header);
    }

    public function testFormsDocsRemainSeparateFromProjectDocuments(): void
    {
        $formsList = file_get_contents($this->root . '/src/views/pages/financial/forms-list.php');
        $formDetail = file_get_contents($this->root . '/src/views/pages/financial/form-detail.php');
        $folderDetail = file_get_contents($this->root . '/src/views/pages/financial/folder-detail.php');
        $handler = file_get_contents($this->root . '/src/controllers/forms_handler.php');
        $formScript = file_get_contents($this->root . '/public/assets/js/form-detail-logic.js');
        $folderScript = file_get_contents($this->root . '/public/assets/js/folder-detail-logic.js');
        $pickerScript = file_get_contents($this->root . '/public/assets/js/forms-email-recipient-picker.js');

        self::assertStringContainsString('request_client_org_id()', (string)$formsList);
        self::assertStringContainsString('request_client_org_id()', (string)$handler);
        self::assertStringContainsString('WHERE project_id IS NULL OR project_id = 0', (string)$formsList);
        self::assertStringContainsString('fd.project_id IS NULL OR fd.project_id = 0', (string)$formDetail);
        self::assertStringContainsString('fd.project_id IS NULL OR fd.project_id = 0', (string)$folderDetail);
        self::assertStringNotContainsString('name="project_id"', (string)$formsList);
        self::assertStringNotContainsString('Project (Optional)', (string)$formDetail);
        foreach ([$formDetail, $folderDetail] as $detailView) {
            self::assertStringContainsString('data-forms-email-client-search', (string)$detailView);
            self::assertStringContainsString('data-forms-email-org-search', (string)$detailView);
            self::assertStringContainsString('/assets/js/forms-email-recipient-picker.js', (string)$detailView);
            self::assertStringNotContainsString('name="client_ids[]" value="<?php echo $client[\'id\']; ?>"', (string)$detailView);
            self::assertStringNotContainsString('-- Select a client --', (string)$detailView);
            self::assertStringNotContainsString('-- Select an organization --', (string)$detailView);
        }
        self::assertStringContainsString('FormsEmailRecipientPicker', (string)$formScript);
        self::assertStringContainsString('FormsEmailRecipientPicker', (string)$folderScript);
        self::assertStringContainsString('/?page=clients-search&term=', (string)$pickerScript);
        self::assertStringContainsString('/?page=organization/org-search&term=', (string)$pickerScript);
        self::assertStringContainsString('/?page=organization/organization-departments-options&organization_id=', (string)$pickerScript);
        self::assertStringContainsString('name="department_ids[]"', (string)$pickerScript);
        self::assertStringContainsString('function forms_email_recipients', (string)$handler);
        self::assertStringContainsString('organization_department_contacts', (string)$handler);
    }

    public function testContractSignatureLabelsAndPaymentReferencesArePreserved(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0011_contract_signature_labels.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $helper = file_get_contents($this->root . '/src/utils/contract_signatures.php');
        $contractCreate = file_get_contents($this->root . '/src/controllers/contract/contracts_create.php');
        $longTermDetails = file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php');
        $paymentView = file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        $paymentController = file_get_contents($this->root . '/src/controllers/payments_create.php');
        $paymentScript = file_get_contents($this->root . '/public/assets/js/payments-create-logic.js');

        self::assertStringContainsString('ADD COLUMN signer_title', (string)$migration);
        self::assertStringContainsString('signer_title VARCHAR(190) NULL', (string)$baseline);
        self::assertStringContainsString('pa_save_contract_signatures', (string)$helper);
        self::assertStringContainsString('pa_save_contract_signatures($pdo, $co_id', (string)$contractCreate);
        self::assertStringContainsString('$sig[\'signer_title\'] ?? \'Client Signature\'', (string)$longTermDetails);
        self::assertStringContainsString('data-remaining=', (string)$paymentView);
        self::assertStringContainsString('name="reference_number"', (string)$paymentView);
        self::assertStringContainsString('id="manualClientSearch"', (string)$paymentView);
        self::assertStringContainsString('id="manualClientId"', (string)$paymentView);
        self::assertStringNotContainsString('id="manualClientSelect"', (string)$paymentView);
        self::assertStringContainsString('reference_number', (string)$paymentController);
        self::assertStringContainsString('referenceLabel.textContent', (string)$paymentScript);
        self::assertStringContainsString('/?page=clients-search&term=', (string)$paymentScript);
        self::assertStringContainsString('sendReceiptInput.disabled = !hasEmail;', (string)$paymentScript);
        self::assertStringContainsString('id="manualJobSelect"', (string)$paymentView);
    }

    public function testSignedContractUploadsWorkWithProductionCspAndRespectSignatureLock(): void
    {
        $navigation = file_get_contents($this->root . '/public/assets/navigation.js');
        $controller = file_get_contents($this->root . '/src/controllers/contract/contract_sign.php');
        $dockerStart = file_get_contents($this->root . '/docker/start.sh');
        $securityHeaders = file_get_contents($this->root . '/src/utils/security_headers.php');
        $organizationView = file_get_contents($this->root . '/src/views/pages/organization/organization-view.php');
        $views = [
            file_get_contents($this->root . '/src/views/pages/contract/contracts-list.php'),
            file_get_contents($this->root . '/src/views/pages/contract/on-demand-contracts-list.php'),
            file_get_contents($this->root . '/src/views/pages/contract/long-term-contracts-list.php'),
            file_get_contents($this->root . '/src/views/pages/contract/contract-details.php'),
            file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php'),
        ];

        self::assertStringContainsString('handleFilePickerAction', (string)$navigation);
        self::assertStringContainsString('handleFileAutoSubmit', (string)$navigation);
        self::assertStringContainsString("document.addEventListener('change', handleFileAutoSubmit)", (string)$navigation);
        foreach ($views as $view) {
            self::assertStringContainsString('data-file-picker-target=', (string)$view);
            self::assertStringContainsString('data-submit-on-file', (string)$view);
            self::assertStringNotContainsString('Replace Signed PDF', (string)$view);
            self::assertStringNotContainsString(".click()\"", (string)$view);
        }

        self::assertStringContainsString("'on_demand' => 'contract/on-demand-contracts-list'", (string)$controller);
        self::assertStringContainsString("'long_term' => 'contract/long-term-contracts-list'", (string)$controller);
        self::assertStringContainsString('validate_and_store_upload(', (string)$controller);
        self::assertStringContainsString("'require_pdf_header' => true", (string)$controller);
        self::assertStringContainsString("'reject_pdf_active_content' => true", (string)$controller);
        self::assertStringContainsString("empty(\$r['signed_at']) && empty(\$r['signed_pdf_path'])", (string)$views[1]);
        self::assertStringNotContainsString('Header always set Content-Security-Policy', (string)$dockerStart);
        self::assertStringContainsString('Content-Security-Policy:', (string)$securityHeaders);
        self::assertStringContainsString('name="tax_exempt_file"', (string)$organizationView);
        self::assertStringContainsString('data-submit-on-file', (string)$organizationView);
        self::assertStringNotContainsString('onchange="this.form.submit()"', (string)$organizationView);
    }

    public function testLegacyServerSchemaRepairsProtectAjaxPages(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0012_activity_log_and_legacy_schema_repairs.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $apiKeys = file_get_contents($this->root . '/src/views/pages/api-keys.php');
        $apiKeysSchema = file_get_contents($this->root . '/src/utils/api_keys_schema.php');
        $clientsList = file_get_contents($this->root . '/src/controllers/api/clients_list.php');
        $notifications = file_get_contents($this->root . '/src/utils/notifications.php');
        $dropbox = file_get_contents($this->root . '/src/controllers/settings/dropbox_oauth.php');
        $publicSign = file_get_contents($this->root . '/src/controllers/public_view/public_contract_sign.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS activity_log', (string)$migration);
        self::assertStringContainsString('ALTER TABLE api_keys ADD COLUMN name', (string)$migration);
        self::assertStringContainsString('ALTER TABLE payments ADD COLUMN reference_number', (string)$migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS activity_log', (string)$baseline);
        self::assertStringContainsString('pa_api_keys_existing_columns($pdo)', (string)$apiKeys);
        self::assertStringContainsString('schema repair incomplete', (string)$apiKeysSchema);
        self::assertStringContainsString('api_clients_table_has_column', (string)$clientsList);
        self::assertStringContainsString('bindValue(count($params) + 1, $limit, PDO::PARAM_INT)', (string)$clientsList);
        self::assertStringContainsString('ensure_activity_log_table($pdo)', (string)$notifications);
        self::assertStringContainsString('PHP_VERSION_ID < 80500', (string)$dropbox);
        self::assertStringContainsString('validate_and_store_upload', (string)$publicSign);
        self::assertStringContainsString("'image/jpeg' => ['jpg', 'jpeg']", (string)$publicSign);
        self::assertStringNotContainsString('finfo_close', (string)$publicSign);
    }

    public function testPaymentsExpensesAndPdfPreviewsHandleLegacyServers(): void
    {
        $paymentController = file_get_contents($this->root . '/src/controllers/payments_create.php');
        $invoiceLifecycle = file_get_contents($this->root . '/src/utils/invoice_lifecycle.php');
        $expenseHandler = file_get_contents($this->root . '/src/controllers/financial/expense_handler.php');
        $expenseCreate = file_get_contents($this->root . '/src/views/pages/financial/expense-create.php');
        $formsList = file_get_contents($this->root . '/src/views/pages/financial/forms-list.php');
        $formDetail = file_get_contents($this->root . '/src/views/pages/financial/form-detail.php');
        $securityHeaders = file_get_contents($this->root . '/src/utils/security_headers.php');

        self::assertStringContainsString('invoice_ensure_payments_schema($pdo)', (string)$paymentController);
        self::assertStringContainsString("'organization_id' => \$organization_id", (string)$paymentController);
        self::assertStringContainsString('function invoice_ensure_payments_schema', (string)$invoiceLifecycle);
        self::assertStringContainsString('ALTER TABLE payments ADD COLUMN {$column}', (string)$invoiceLifecycle);
        self::assertStringContainsString('$taxAmount = null;', (string)$expenseHandler);
        self::assertStringContainsString('$paymentMethod = null;', (string)$expenseHandler);
        self::assertStringNotContainsString('id="taxInput"', (string)$expenseCreate);
        self::assertStringNotContainsString('name="payment_method"', (string)$expenseCreate);
        self::assertStringNotContainsString('fetch(form.action', (string)$expenseCreate);
        self::assertStringContainsString('$_GET[\'error\']', (string)$expenseCreate);
        self::assertStringContainsString('#toolbar=0&navpanes=0&scrollbar=0&view=FitH', (string)$formsList);
        self::assertStringContainsString('Open PDF in new tab', (string)$formDetail);
        self::assertStringContainsString('X-Frame-Options: SAMEORIGIN', (string)$securityHeaders);
        self::assertStringContainsString("frame-src 'self'", (string)$securityHeaders);
        self::assertStringContainsString("frame-ancestors 'self'", (string)$securityHeaders);
    }

    public function testAdminPaymentAndContractNotificationsAreConfigurable(): void
    {
        $notifications = file_get_contents($this->root . '/src/utils/notifications.php');
        $settingsView = file_get_contents($this->root . '/src/views/pages/settings/notifications.php');
        $settingsHandler = file_get_contents($this->root . '/src/controllers/settings_handler.php');
        $appConfig = file_get_contents($this->root . '/src/config/app.php');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $projectBilling = file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $legacyWebhook = file_get_contents($this->root . '/src/controllers/stripe/stripe_webhook.php');
        $manualPayments = file_get_contents($this->root . '/src/controllers/payments_create.php');

        foreach ([
            'notify_signed_contract_uploaded',
            'notify_invoice_paid',
            'notify_invoice_paid_regular',
            'notify_invoice_paid_on_demand',
            'notify_invoice_paid_long_term',
            'notify_invoice_paid_project',
        ] as $key) {
            self::assertStringContainsString($key, (string)$settingsView);
            self::assertStringContainsString($key, (string)$settingsHandler);
            self::assertStringContainsString($key, (string)$appConfig);
            self::assertStringContainsString($key, (string)$baseline);
        }

        self::assertStringNotContainsString('admin@project-alpha.local', (string)$notifications);
        self::assertStringContainsString("role IN ('admin','owner')", (string)$notifications);
        self::assertStringContainsString('admin_notification_email_is_deliverable', (string)$notifications);
        self::assertStringContainsString("str_ends_with(\$domain, '.local')", (string)$notifications);
        self::assertStringContainsString('foreach ($adminEmails as $adminEmail)', (string)$notifications);
        self::assertStringContainsString("notification_setting_enabled(\$appConfig, 'notify_signed_contract_uploaded', true)", (string)$notifications);
        self::assertStringContainsString('admin_invoice_paid_notification_enabled($appConfig, $invoice)', (string)$notifications);
        self::assertStringContainsString('function notify_admin_project_invoice_paid', (string)$notifications);
        self::assertStringContainsString("\$statusValue === 'paid' ? 'paid' : 'partial', false, true", (string)$projectBilling);
        self::assertStringContainsString("\$statusValue === 'paid' ? 'paid' : 'partial', true, true", (string)$projectBilling);
        self::assertStringContainsString('notify_admin_invoice_paid($pdo, $GLOBALS[\'appConfig\'] ?? [], $invoiceId, $paymentAmount, $status)', (string)$legacyWebhook);
        self::assertStringNotContainsString('notify_admin_invoice_paid', (string)$manualPayments);
    }

    public function testGeneratedInvoiceRequestKeysReplayDeliveryWithoutDuplicatingMoney(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE contracts (id INTEGER PRIMARY KEY, invoice_count INTEGER NOT NULL, total_invoiced TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, generation_key TEXT UNIQUE NOT NULL, total TEXT NOT NULL)');
        $pdo->exec("INSERT INTO contracts VALUES (1,0,'0.00')");
        $generate = static function (PDO $db, string $key): array {
            $lookup = $db->prepare('SELECT id FROM invoices WHERE contract_id=1 AND generation_key=?');
            $lookup->execute([$key]);
            $existing = (int)$lookup->fetchColumn();
            if ($existing > 0) return [$existing, true];
            $db->prepare("INSERT INTO invoices(contract_id,generation_key,total) VALUES(1,?,'12.34')")->execute([$key]);
            $id = (int)$db->lastInsertId();
            $db->exec("UPDATE contracts SET invoice_count=invoice_count+1,total_invoiced='12.34' WHERE id=1");
            return [$id, false];
        };
        [$firstId, $firstReplay] = $generate($pdo, 'same-key');
        [$replayedId, $sameKeyReplay] = $generate($pdo, 'same-key');
        self::assertFalse($firstReplay);
        self::assertTrue($sameKeyReplay);
        self::assertSame($firstId, $replayedId);
        self::assertSame(1, (int)$pdo->query('SELECT invoice_count FROM contracts WHERE id=1')->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        $finalized = [$replayedId];
        $delivered = [$replayedId];
        self::assertSame([$firstId], $finalized);
        self::assertSame([$firstId], $delivered);
        self::assertSame(1, (int)$pdo->query('SELECT invoice_count FROM contracts WHERE id=1')->fetchColumn());
        [$secondId, $differentKeyReplay] = $generate($pdo, 'different-key');
        self::assertFalse($differentKeyReplay);
        self::assertNotSame($firstId, $secondId);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());

        $onDemand = (string)file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');
        $proration = (string)file_get_contents($this->root . '/src/controllers/contract/long_term_recurring_service_save.php');
        self::assertStringContainsString('invoice_finalize($pdo, $existingInvoiceId', $onDemand);
        self::assertStringContainsString('invoice_notification_process($pdo, $appConfig, null, null, 10, $existingInvoiceId)', $onDemand);
        self::assertStringContainsString('$prorationInvoiceId=$existingProrationId;$idempotentReplay=true;', $proration);
        self::assertStringContainsString('Could not discard the duplicate signed addendum upload.', $proration);
        self::assertStringContainsString('$storedAddendum=null;$storedAddendumPath=null;$addendumUrl=null;', $proration);
        self::assertStringContainsString('if (!$idempotentReplay && $approved', $proration);
        self::assertStringContainsString('invoice_send_finalized($pdo, $prorationInvoiceId', $proration);
        self::assertStringNotContainsString('$pdo->commit();header(\'Location: /?page=contract/long-term-contract-details', $proration);
    }

    public function testProjectCloseOutGuardIsTransactionalScopedAndActionable(): void
    {
        $service = (string)file_get_contents($this->root . '/src/services/ProjectCloseGuardService.php');
        $eligibility = (string)file_get_contents($this->root . '/src/services/ProjectContractEligibilityGuardService.php');
        $receivables = (string)file_get_contents($this->root . '/src/services/ProjectReceivablesSummaryService.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/project/projects_update_status.php');
        $details = (string)file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $projectsList = (string)file_get_contents($this->root . '/src/views/pages/project/projects-list.php');
        $dashboard = (string)file_get_contents($this->root . '/src/controllers/api/dashboard_summary.php');
        $acl = (string)file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $appConfig = (string)file_get_contents($this->root . '/src/config/app.php');

        self::assertStringContainsString("['completed','cancelled']", $service);
        self::assertStringContainsString("['completed','cancelled','denied','void']", $service);
        self::assertStringContainsString('Project status transitions require an active transaction.', $service);
        self::assertStringContainsString("\\require_record_ownership(\$pdo, 'projects', \$projectId)", $service);
        self::assertStringContainsString('FROM contracts WHERE project_id=? ORDER BY id', $service);
        self::assertStringContainsString('project.closeout.blocked', $service);
        self::assertStringContainsString('closed_with_outstanding_receivables', $service);
        self::assertStringContainsString('outstanding_receivables_minor', $service);
        self::assertStringContainsString('UTC_TIMESTAMP(6)', $service);
        self::assertStringContainsString('INSERT INTO system_audit', $service);
        self::assertStringNotContainsString('FROM invoices', $service);
        self::assertStringNotContainsString('UPDATE invoices', $service);
        self::assertStringContainsString("config_key='contract_settlement_enabled'", $eligibility);
        self::assertStringContainsString('SELECT id,status FROM projects WHERE id=?', $eligibility);
        self::assertStringContainsString('ORDER BY id', $eligibility);
        self::assertStringNotContainsString('catch (', $eligibility);
        self::assertStringContainsString("collection_mode='direct'", $receivables);
        self::assertStringContainsString("status IN ('sent','unpaid','partial','overdue')", $receivables);
        self::assertStringContainsString("'pending_project_charges'", $receivables);
        self::assertStringContainsString('balance_due>0.005', $receivables);
        self::assertStringContainsString('summarizeProjects', $receivables);

        self::assertStringContainsString('ProjectLifecycleService($pdo))->apply(', $controller);
        self::assertStringContainsString('&closeout_blocked=1', $controller);
        self::assertStringContainsString('&closeout_target=', $controller);
        self::assertStringContainsString('#project-closeout-alert', $controller);
        self::assertStringNotContainsString("\$_POST['redirect']", $controller);
        self::assertStringNotContainsString('UPDATE projects', $controller);
        $transitionAt = strpos($controller, 'ProjectLifecycleService($pdo))->apply(');
        $scheduleAt = strpos($controller, 'ScheduleService::syncProject');
        $projectionAt = strpos($controller, 'queueProject($pdo,$id)');
        $commitAt = strrpos($controller, '$pdo->commit()');
        self::assertIsInt($transitionAt);
        self::assertIsInt($scheduleAt);
        self::assertIsInt($projectionAt);
        self::assertIsInt($commitAt);
        self::assertLessThan($scheduleAt, $transitionAt);
        self::assertLessThan($projectionAt, $scheduleAt);
        self::assertLessThan($commitAt, $projectionAt);
        $blockedAt = (int)strpos($controller, "if (!\$transition['transitioned'])");
        $blockedBranch = substr($controller, $blockedAt, $scheduleAt - $blockedAt);
        self::assertStringContainsString('$pdo->commit()', $blockedBranch);
        self::assertStringNotContainsString('ScheduleService::syncProject', $blockedBranch);
        self::assertStringNotContainsString('queueProject', $blockedBranch);

        self::assertStringContainsString("'project/projects-update-status' => 'projects.edit'", $acl);
        self::assertStringContainsString("'contract_settlement_enabled'", $appConfig);
        self::assertStringContainsString('$canEditProjectStatus && $contractSettlementEnabled', $details);
        self::assertStringContainsString('Project <?php echo $closeoutActionNoun; ?> is blocked', $details);
        self::assertStringContainsString('Review close-out', $details);
        self::assertStringContainsString('Open invoices do not block project close-out.', $details);
        self::assertStringContainsString('contract_type, total', $details);
        self::assertStringContainsString('ProjectReceivablesSummaryService($pdo))->summarize($projectId)', $details);
        /* Retired mojibake assertion from the first close-out prototype:
        self::assertStringContainsString('Closed · Outstanding $', $details);
        */
        self::assertStringContainsString("'completed' ? 'Completed' : 'Cancelled'", $details);
        self::assertStringContainsString('role="alert"', $details);
        self::assertStringContainsString('aria-labelledby="project-closeout-alert-title"', $details);
        self::assertStringContainsString('tabindex="-1"', $details);
        self::assertStringContainsString('aria-label="Review close-out for Contract', $details);
        self::assertStringContainsString('project-closeout-action{min-height:44px', $details);
        self::assertStringContainsString('.project-closeout-row{align-items:stretch;flex-direction:column}', $details);
        self::assertStringContainsString('Retry <?php echo $closeoutActionNoun; ?>', $details);
        self::assertStringContainsString('Project status updated.', $details);
        self::assertStringContainsString('Complete this Project? Collectible receivables remain due', $details);
        self::assertStringContainsString('Cancel this Project? Collectible receivables remain due', $details);
        self::assertStringContainsString('summarizeProjects(', $projectsList);
        self::assertStringNotContainsString('SUM(GREATEST(0, i.balance_due))', $projectsList);
        self::assertStringContainsString('summarizeAll()', $dashboard);
        self::assertStringNotContainsString('SUM(balance_due)', $dashboard);

        $guardedPaths = [
            'src/controllers/contract/contracts_create.php',
            'src/controllers/contract/long_term_contracts_create.php',
            'src/controllers/contract/on_demand_contracts_create.php',
            'src/controllers/contract/contracts_update.php',
            'src/controllers/quote/quote_approve.php',
            'src/controllers/public_view/public_quote_action.php',
            'src/controllers/project/project_add_document.php',
            'src/controllers/document_reenable_handler.php',
            'src/services/JobAssignmentService.php',
        ];
        foreach ($guardedPaths as $path) {
            $source = (string)file_get_contents($this->root . '/' . $path);
            self::assertStringContainsString('ProjectContractEligibilityGuardService', $source, $path);
            self::assertStringContainsString('assertCanCreateOrAttach', $source, $path);
        }
        foreach ([
            'src/controllers/contract/contracts_create.php',
            'src/controllers/contract/long_term_contracts_create.php',
            'src/controllers/contract/on_demand_contracts_create.php',
            'src/controllers/quote/quote_approve.php',
            'src/controllers/public_view/public_quote_action.php',
        ] as $path) {
            $source = (string)file_get_contents($this->root . '/' . $path);
            self::assertLessThan(strpos($source, 'INSERT INTO contracts'), strpos($source, 'assertCanCreateOrAttach'), $path);
        }
        $contractUpdate = (string)file_get_contents($this->root . '/src/controllers/contract/contracts_update.php');
        self::assertLessThan(strpos($contractUpdate, "DocumentPolicy::assertMutable(\$pdo,'contract',\$id,'commercial',true)"), strpos($contractUpdate, 'assertCanCreateOrAttach'));
        $jobAssignment = (string)file_get_contents($this->root . '/src/services/JobAssignmentService.php');
        self::assertLessThan(strpos($jobAssignment, 'SELECT * FROM jobs WHERE id=? FOR UPDATE'), strpos($jobAssignment, 'assertCanCreateOrAttach'));
        $reenable = (string)file_get_contents($this->root . '/src/controllers/document_reenable_handler.php');
        self::assertLessThan(strpos($reenable, 'SELECT * FROM contracts WHERE id=? FOR UPDATE'), strpos($reenable, 'assertCanCreateOrAttach'));
        self::assertStringContainsString('Contract Project assignment changed. Retry the request.', $reenable);
    }
}
