<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/views/pages/settings/registry.php';

use PHPUnit\Framework\TestCase;

final class SettingsRedesignTest extends TestCase
{
    public function testEveryActiveSettingsTabAppearsExactlyOnce(): void
    {
        $tabs = [];
        foreach (pa_settings_registry() as $category) {
            foreach ($category['items'] as $item) {
                if (isset($item['tab'])) {
                    $tabs[] = $item['tab'];
                }
            }
        }

        $expected = [
            'backup', 'billing', 'documents',
            'item-library', 'links', 'logs', 'notifications',
            'permissions', 'system', 'taxes', 'terms', 'workflow',
        ];
        sort($tabs);
        sort($expected);

        foreach ($expected as $legacyTab) {
            self::assertSame(1, count(array_keys($tabs, $legacyTab, true)), $legacyTab . ' must appear exactly once.');
        }
        self::assertSame($tabs, array_values(array_unique($tabs)));
    }

    public function testAccountSecurityRemainsVisibleWithoutInstallationManagement(): void
    {
        $registry = pa_settings_visible_registry(
            pa_settings_registry(),
            static fn (string $permission): bool => false,
            'employee'
        );

        self::assertSame(['account'], array_keys($registry));
        self::assertArrayHasKey('profile', $registry['account']['items']);
        self::assertArrayHasKey('security', $registry['account']['items']);
    }

    public function testInstallationCategoriesArePermissionAndRoleAware(): void
    {
        $canManage = static fn (string $permission): bool => $permission === 'settings.manage';
        $staffRegistry = pa_settings_visible_registry(pa_settings_registry(), $canManage, 'staff');
        $ownerRegistry = pa_settings_visible_registry(pa_settings_registry(), $canManage, 'owner');
        $adminRegistry = pa_settings_visible_registry(pa_settings_registry(), $canManage, 'admin');

        self::assertArrayNotHasKey('business', $staffRegistry);
        self::assertArrayHasKey('business', $ownerRegistry);
        self::assertCount(6, pa_settings_registry());
        self::assertSame(['account', 'business', 'services', 'workforce', 'billing', 'system'], array_keys(pa_settings_registry()));
        self::assertArrayHasKey('workflow', $staffRegistry['workforce']['items']);
        self::assertArrayNotHasKey('accounts', $staffRegistry['account']['items']);
        self::assertArrayNotHasKey('api-keys', $staffRegistry['system']['items']);
        self::assertArrayNotHasKey('backup', $ownerRegistry['system']['items']);
        self::assertArrayHasKey('backup', $adminRegistry['system']['items']);
    }

    public function testSettingsShellUsesSharedNavigationAndExplicitSaveControls(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings.php');
        $sidebar = (string)file_get_contents($root . '/src/views/pages/settings/sidebar.php');
        $script = (string)file_get_contents($root . '/public/assets/js/settings-page.js');
        $styles = (string)file_get_contents($root . '/public/assets/settings.css');

        self::assertStringContainsString("settings/registry.php", $view);
        self::assertStringContainsString("settings/dashboard.php", $view);
        self::assertStringContainsString("settings/sidebar.php", $view);
        self::assertStringContainsString('data-settings-primary-form', $view);
        self::assertStringContainsString('data-settings-cancel', $view);
        self::assertStringContainsString('Save changes', $view);
        self::assertStringContainsString("registerPage('settings'", $script);
        self::assertStringContainsString("beforeunload", $script);
        self::assertStringContainsString('You have unsaved changes.', $script);
        self::assertMatchesRegularExpression('/\.settings-sidebar\s*\{[^}]*max-height:\s*calc\(100vh - 40px\);[^}]*overflow:\s*auto;[^}]*position:\s*sticky;[^}]*top:\s*18px;/s', $styles);
        self::assertStringContainsString('data-settings-nav-disclosure open', $sidebar);
        self::assertStringContainsString('class="settings-nav-toggle"', $sidebar);
        self::assertStringContainsString('.settings-sidebar { margin-bottom: 14px; max-height: none; padding: 0; position: static; }', $styles);
        self::assertMatchesRegularExpression('/@media \(max-width: 800px\)[\s\S]*?\.settings-nav-toggle\s*\{[\s\S]*?display:\s*grid;/', $styles);
        self::assertStringContainsString("window.matchMedia('(max-width: 800px)')", $script);
        self::assertStringContainsString('navigationDisclosure.open = !compactNavigation.matches', $script);
        self::assertStringContainsString("'project-alpha:settings-sidebar-scroll'", $script);
        self::assertStringContainsString("sidebar.addEventListener('scroll', saveSidebarScroll", $script);
        self::assertStringContainsString("sidebar.removeEventListener('scroll', saveSidebarScroll", $script);
        self::assertStringContainsString('.settings-category-grid', $styles);
    }

    public function testSharedSettingsStylesKeepFormsReadableAndActionsInline(): void
    {
        $styles = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/settings.css');

        self::assertStringContainsString('--settings-muted: #475467', $styles);
        self::assertStringContainsString('--settings-control-border: #8491a5', $styles);
        self::assertStringContainsString('.settings-managed-section .settings-card', $styles);
        self::assertStringContainsString('.settings-form-grid', $styles);
        self::assertStringContainsString('.settings-content .check-row', $styles);
        self::assertStringContainsString('.settings-managed-section .inline-form', $styles);
        self::assertStringContainsString('.settings-action-row', $styles);
        self::assertStringContainsString('display: inline-flex', $styles);
        self::assertStringContainsString('.settings-form-grid { grid-template-columns: 1fr; }', $styles);
    }

    public function testTimeAndWorkforceSettingsLiveUnderWorkJobsAndPay(): void
    {
        $root = dirname(__DIR__, 2);
        $system = (string)file_get_contents($root . '/src/views/pages/settings/system.php');
        $workflow = (string)file_get_contents($root . '/src/views/pages/settings/workflow.php');
        $handler = (string)file_get_contents($root . '/src/controllers/settings_handler.php');

        self::assertStringNotContainsString('Time &amp; Workforce', $system);
        self::assertStringContainsString('Time &amp; Workforce', $workflow);
        self::assertStringContainsString('name="workforce_currency"', $workflow);
        self::assertStringContainsString('if ($isWorkflowTab)', $handler);
        self::assertStringContainsString('tab=workflow&error=', $handler);
        self::assertStringContainsString('tab=workflow', (string)file_get_contents($root . '/src/views/pages/workforce/overview.php'));
    }

    public function testLegacyAccountAndCustomizationAliasesRemainSupported(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/settings.php');

        self::assertStringContainsString("\$requestedTab === 'account'", $view);
        self::assertStringContainsString("\$requestedTab === 'customization'", $view);
        self::assertStringContainsString("\$_GET['doc_tab'] = 'customization'", $view);
    }

    public function testSettingsActionScriptsBindToTheActualRouterPage(): void
    {
        $root = dirname(__DIR__, 2);
        $systemScript = (string)file_get_contents($root . '/public/assets/js/settings-system.js');
        $catalogScript = (string)file_get_contents($root . '/public/assets/js/item-library.js');
        $notificationScript = (string)file_get_contents($root . '/public/assets/js/settings-notifications.js');

        self::assertStringContainsString("registerPage('settings', initSettingsSystem)", $systemScript);
        self::assertStringNotContainsString("registerPage('settings/system'", $systemScript);
        self::assertStringContainsString("registerPage('settings',initItemLibraryPage)", $catalogScript);
        self::assertStringContainsString("registerPage('settings', initSettingsNotifications)", $notificationScript);
        self::assertStringContainsString('data-settings-notifications', (string)file_get_contents($root . '/src/views/pages/settings/notifications.php'));
    }

    public function testStaticSettingsScriptsNeverContainUnrenderedPhpTokens(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['customization-logic.js', 'document-customization-logic.js'] as $file) {
            $script = (string)file_get_contents($root . '/public/assets/js/' . $file);
            self::assertStringNotContainsString('<?php', $script, $file . ' is served as a static asset and cannot render PHP.');
            self::assertStringContainsString('CsrfToken()', $script, $file . ' must read the rendered form token.');
        }

        $documentView = (string)file_get_contents($root . '/src/views/pages/settings/documents/customization.php');
        self::assertStringContainsString('data-active-field-tab=', $documentView);
        self::assertStringContainsString('name="csrf"', $documentView);
    }

    public function testWorkforceSettingsActionsAndPermissionGateStayAligned(): void
    {
        $root = dirname(__DIR__, 2);
        $views = '';
        foreach (['business-units.php', 'work-types.php', 'assignments.php', 'pay-periods.php'] as $file) {
            $views .= (string)file_get_contents($root . '/src/views/pages/settings/' . $file);
        }
        preg_match_all('/name="action"\s+value="([a-z-]+)"/', $views, $matches);
        // Worker-document upload uses its own `worker-documents` controller.
        $actions = array_values(array_diff(array_unique($matches[1] ?? []), ['upload']));
        $controller = (string)file_get_contents($root . '/src/controllers/settings/workforce_catalog_handler.php');

        self::assertNotEmpty($actions);
        foreach ($actions as $action) {
            self::assertStringContainsString("\$action==='{$action}'", $controller, "Missing backend action: {$action}");
        }
        self::assertStringContainsString('user_can($pdo,$userId,$permission,0)', $controller);
        self::assertStringContainsString("'save-work-type','set-work-type-status','delete-work-type'=>['workforce.catalog.manage','settings.manage']", $controller);
        self::assertStringNotContainsString("in_array((string)(\$_SESSION['user']['role']??''),['admin','owner']", $controller);
        self::assertStringNotContainsString("user_can(\$pdo,\$userId,'workforce.manage',0)", $controller);
    }

    public function testWorkTypesSeparateClientBillingFromWorkerCompensation(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings/work-types.php');
        $controller = (string)file_get_contents($root . '/src/controllers/settings/workforce_catalog_handler.php');
        $registry = pa_settings_registry();

        self::assertStringContainsString('LEFT JOIN work_type_billing_defaults', $view);
        self::assertStringContainsString('client pricing belongs to the Service Library', $view);
        self::assertStringContainsString('Worker compensation default', $view);
        self::assertStringNotContainsString('name="billing_treatment"', $view);
        self::assertStringNotContainsString('name="billing_rate"', $view);
        self::assertStringContainsString('name="compensation_currency"', $view);
        self::assertStringContainsString('edit_work_type=', $view);
        self::assertStringContainsString('value="set-work-type-status"', $view);
        self::assertStringContainsString('settings-action-row', $view);
        self::assertStringContainsString('settings-save-bar', $view);

        self::assertStringContainsString('INSERT INTO work_type_billing_defaults', $controller);
        self::assertStringContainsString('default_treatment="undecided"', $controller);
        self::assertStringContainsString("\$action==='set-work-type-status'", $controller);
        self::assertStringNotContainsString("['undecided','internal','fixed_price_included','hourly']", $controller);
        self::assertArrayNotHasKey('work-types', $registry['workforce']['items']);

        $catalogManagerRegistry = pa_settings_visible_registry(
            $registry,
            static fn (string $permission): bool => $permission === 'workforce.catalog.manage',
            'staff'
        );
        self::assertArrayNotHasKey('workforce', $catalogManagerRegistry);
    }

    public function testItemLibraryIsServiceFocusedAndPackagesUseSearchSelection(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings/item-library.php');
        $script = (string)file_get_contents($root . '/public/assets/js/item-library.js');
        $handler = (string)file_get_contents($root . '/src/controllers/settings/item_library_handler.php');

        self::assertStringNotContainsString('id="itemSku"', $view);
        self::assertStringNotContainsString('value="product"', $view);
        self::assertStringNotContainsString('name="tax_behavior"', $view);
        self::assertStringContainsString('Uses the document default automatically', $view);
        self::assertStringContainsString('<option value="each">Service unit</option>', $view);
        self::assertStringContainsString('Type to search the Service Library', $view);
        self::assertStringContainsString('data-bundle-selected', $view);
        self::assertStringContainsString('renderBundleResults', $script);
        self::assertStringContainsString('$types = [\'service\',\'fee\',\'bundle\']', $handler);
        self::assertStringContainsString('$billingUnit,\'inherit\'', $handler);
    }

    public function testItemLibrarySkuIsRemovedFromRuntimeAndSchema(): void
    {
        $root = dirname(__DIR__, 2);
        $handler = (string)file_get_contents($root . '/src/controllers/settings/item_library_handler.php');
        $snapshots = (string)file_get_contents($root . '/src/utils/catalog_documents.php');
        $baseline = (string)file_get_contents($root . '/database/baseline.sql');
        $migration = (string)file_get_contents($root . '/database/migrations/0048_service_catalog_tax_lookup_performance.sql');

        self::assertStringNotContainsString('sku', strtolower($handler));
        self::assertStringNotContainsString('sku', strtolower($snapshots));
        self::assertStringNotContainsString('sku varchar', strtolower($baseline));
        self::assertStringContainsString('DROP COLUMN sku', $migration);
    }

    public function testServiceCatalogWorkflowIsPublishedInRepositoryDocs(): void
    {
        $root = dirname(__DIR__, 2);
        $workflowPath = $root . '/docs/workflows/service-catalog-and-work-types.md';
        if (!is_readable($workflowPath)) {
            self::markTestSkipped('Repository documentation is not packaged in the production web image.');
        }
        $workflow = (string)file_get_contents($workflowPath);
        $index = (string)file_get_contents($root . '/docs/workflows/index.md');

        self::assertStringContainsString('Service Library Settings', $workflow);
        self::assertStringContainsString('Work Activity Settings', $workflow);
        self::assertStringContainsString('How Hourly Billing Resolves', $workflow);
        self::assertStringContainsString('Tax-Exempt Clients', $workflow);
        self::assertStringContainsString('service-catalog-and-work-types.html', $index);
    }

    public function testItemLibraryLoadsRelatedDataInBulk(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/settings/item-library.php');

        self::assertStringContainsString('$componentsByItem', $view);
        self::assertStringContainsString('$bundleItemsByItem', $view);
        self::assertStringNotContainsString('$componentStmt->execute([$item[\'id\']])', $view);
        self::assertStringNotContainsString('$bundleStmt->execute([$item[\'id\']])', $view);
    }

    public function testRestrictedSettingsActionsAreNotRenderedToIneligibleRoles(): void
    {
        $registry = pa_settings_visible_registry(
            pa_settings_registry(),
            static fn (string $permission): bool => $permission === 'settings.manage',
            'staff'
        );

        self::assertArrayNotHasKey('permissions', $registry['account']['items']);
        self::assertArrayNotHasKey('business-units', $registry['account']['items']);

        $businessUnits = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/settings/business-units.php');
        self::assertStringContainsString("user_can(\$pdo,(int)(\$_SESSION['user']['id']??0),'users.manage',0)", $businessUnits);
        self::assertStringContainsString('if($canManageWorkerDocuments)', $businessUnits);
    }

    public function testSettingsFormEndpointsAreRegisteredByTheFrontController(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach ([
            'settings', 'settings-backup', 'settings/item-library-handler',
            'settings/links-handler', 'settings/permissions-handler',
            'settings/stripe-import-payments', 'settings/stripe-net-backfill',
            'settings/tax-import-handler', 'settings/tax-rates-handler',
            'settings/workforce-catalog-handler', 'worker-documents',
        ] as $endpoint) {
            self::assertStringContainsString("'{$endpoint}'", $index, "Settings endpoint is not routed: {$endpoint}");
        }
    }
}
