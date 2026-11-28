<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinancialAssetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAssetSchemaIsInBaselineAndForwardMigration(): void
    {
        $baseline = $this->read('database/baseline.sql');
        $migration = $this->read('database/migrations/0009_financial_assets.sql');

        foreach ([$baseline, $migration] as $sql) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS financial_assets', $sql);
            self::assertStringContainsString('asset_tag VARCHAR(80)', $sql);
            self::assertStringContainsString("depreciation_method ENUM('none','straight_line')", $sql);
            self::assertStringContainsString('useful_life_months INT', $sql);
            self::assertStringContainsString('CONSTRAINT fk_fin_asset_org', $sql);
        }
    }

    public function testAssetRoutesAndPermissionsAreRegistered(): void
    {
        $front = $this->read('public/index.php');
        self::assertStringContainsString("'financial/asset-handler'", $front);
        self::assertStringContainsString('src/controllers/financial/asset_handler.php', $front);

        $acl = $this->read('src/utils/acl_middleware.php');
        self::assertStringContainsString("'financial/asset-detail'           => 'financial.view'", $acl);
        self::assertStringContainsString("'financial/asset-form'             => 'financial.manage'", $acl);
        self::assertStringContainsString("'financial/asset-handler'          => 'financial.manage'", $acl);
    }

    public function testExpenseRoutesPermissionsAndFormSubmitAreRegistered(): void
    {
        $front = $this->read('public/index.php');
        self::assertStringContainsString("'financial/expense-handler'", $front);
        self::assertStringContainsString("'financial/expense_handler'", $front);
        self::assertStringContainsString('src/controllers/financial/expense_handler.php', $front);

        $acl = $this->read('src/utils/acl_middleware.php');
        self::assertStringContainsString("'financial/expense-create'         => 'financial.manage'", $acl);
        self::assertStringContainsString("'financial/expense-detail'         => 'financial.view'", $acl);
        self::assertStringContainsString("'financial/expense-handler'        => 'financial.manage'", $acl);
        self::assertStringContainsString("'financial/expense_handler'        => 'financial.manage'", $acl);

        $form = $this->read('src/views/pages/financial/expense-create.php');
        self::assertStringContainsString('action="/?page=financial/expense-handler"', $form);
        self::assertStringContainsString('(function () {', $form);
        self::assertStringNotContainsString('fetch(form.action', $form);
        self::assertStringContainsString('$_GET[\'error\']', $form);
        self::assertStringContainsString('/?page=financial/expenses-list&tab=expenses', $form);
        self::assertStringContainsString('id="newCategoryForm"', $form);
        self::assertStringContainsString('id="expenseCategory"', $form);
        self::assertStringContainsString('New Category', $form);
        self::assertStringContainsString('payload.id', $form);

        $handler = $this->read('src/controllers/financial/expense_handler.php');
        self::assertStringContainsString('function expense_handler_is_ajax()', $handler);
        self::assertStringContainsString("'status_param' => 'created'", $handler);
        self::assertStringContainsString("'status_param' => 'updated'", $handler);
        self::assertStringContainsString('/?page=financial/expenses-list&tab=expenses', $handler);

        $categoryHandler = $this->read('src/controllers/financial/category_handler.php');
        self::assertStringContainsString('function category_handler_safe_return_url', $categoryHandler);
        self::assertStringContainsString('category_id', $categoryHandler);
        self::assertStringContainsString("'name' => \$name", $categoryHandler);

        $categories = $this->read('src/views/pages/financial/_categories_tab.php');
        self::assertStringContainsString("'onclick' => 'createCategory()'", $this->read('src/views/pages/financial/setup.php'));
        self::assertStringContainsString('id="categoryAction"', $categories);
        self::assertStringContainsString('window.createCategory', $categories);
        self::assertStringNotContainsString('/?page=financial/vendor-form" class="btn btn-primary">+ Add Category', $categories);
    }

    public function testFinancialNavIsOverviewExpensesAssetsReportsSetup(): void
    {
        $nav = $this->read('src/views/partials/header.php');
        $labels = [];
        preg_match_all('#<li><a href="/\?page=financial/[^"]+" data-page="[^"]+"(?: data-match="[^"]+")?>([^<]+)</a></li>#', $nav, $m);
        self::assertSame(['Overview', 'Expenses', 'Assets', 'Reports', 'Setup', 'Forms &amp; Docs'], $m[1]);
        self::assertStringContainsString('data-page="financial/assets-list" data-match="financial/asset"', $nav);
        self::assertStringContainsString('data-page="financial/setup" data-match="financial/vendor financial/category"', $nav);
        self::assertStringNotContainsString('Assets &amp; Expenses', $nav);

        $navigation = $this->read('public/assets/navigation.js');
        self::assertStringContainsString("prefixes.some(prefix => normalizedPage.startsWith(prefix))", $navigation);

        $acl = $this->read('src/utils/acl_middleware.php');
        foreach (['assets-list', 'reports', 'setup', 'mileage-export'] as $page) {
            self::assertStringContainsString("'financial/{$page}'", $acl);
        }
    }

    public function testTabbedFinancialPagesShareOneShell(): void
    {
        foreach (['expenses-list.php' => ['expenses', 'recurring', 'receipts', 'mileage'], 'reports.php' => ['income', 'expense', 'mileage', 'schedules'], 'setup.php' => ['vendors', 'categories', 'rates']] as $file => $tabs) {
            $page = $this->read('src/views/pages/financial/' . $file);
            self::assertStringContainsString("include __DIR__ . '/_tab_shell.php';", $page, $file);
            foreach ($tabs as $tab) {
                self::assertMatchesRegularExpression("#'{$tab}' => \[|\['{$tab}'\] = \[#", $page, $file . ' ' . $tab);
            }
        }
        $shell = $this->read('src/views/pages/financial/_tab_shell.php');
        self::assertStringContainsString('class="doc-tabs"', $shell);
        self::assertStringContainsString('Financial tab', $shell);
        self::assertStringNotContainsString('<b>', $shell);

        $assets = $this->read('src/views/pages/financial/assets-list.php');
        self::assertStringContainsString('financial_asset_depreciation', $assets);
        self::assertStringContainsString('year_depreciation', $assets);
        self::assertStringContainsString('Book Value', $assets);
        self::assertStringContainsString('name="page" value="financial/assets-list"', $assets);
    }

    public function testAssetCreateAndDetailPagesIncludeLifecycleAndDepreciationControls(): void
    {
        $form = $this->read('src/views/pages/financial/asset-form.php');
        self::assertStringContainsString("csrf_sf_token('asset')", $form);
        self::assertStringContainsString('action="/?page=financial/asset-handler"', $form);
        self::assertStringNotContainsString("fetch('/?page=financial/asset-handler'", $form);
        self::assertStringContainsString('Useful Life (months)', $form);
        self::assertStringContainsString('Estimated Monthly', $form);
        self::assertStringContainsString('Linked Expense', $form);
        self::assertStringContainsString('Make new expense from this asset', $form);
        self::assertStringContainsString('data-vendor-id', $form);
        self::assertStringContainsString('applyLinkedExpense', $form);
        self::assertStringContainsString('Warranty Expires', $form);

        $detail = $this->read('src/views/pages/financial/asset-detail.php');
        self::assertStringContainsString('Accumulated Depreciation', $detail);
        self::assertStringContainsString('Linked Expense', $detail);
        self::assertStringContainsString('Mark Disposed', $detail);
        self::assertStringNotContainsString("fetch('/?page=financial/asset-handler'", $detail);

        $handler = $this->read('src/controllers/financial/asset_handler.php');
        self::assertStringContainsString('function asset_handler_finish', $handler);
        self::assertStringContainsString('function asset_fetch_expense', $handler);
        self::assertStringContainsString('$createExpenseFromAsset', $handler);
        self::assertStringContainsString('Asset purchase: ', $handler);
        self::assertStringContainsString('UPDATE expenses SET vendor_id = ?', $handler);
        self::assertStringContainsString('request_client_org_id()', $handler);
        self::assertStringContainsString('finance_scope_clause($pdo, \'a\'', $handler);
    }

    public function testFinancialDashboardsUseExpenseAmountFallbacks(): void
    {
        $home = $this->read('src/views/pages/home.php');
        $dashboard = $this->read('src/views/pages/financial/financial-dashboard.php');
        $expensesTab = $this->read('src/views/pages/financial/_expenses_tab.php');

        self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $home);
        self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $dashboard);
        self::assertStringContainsString('display_total', $dashboard);
        self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $expensesTab);
        self::assertStringContainsString("\$dashboard_finance_period_start = date('Y') . '-01-01'", $home);
        self::assertStringContainsString('dashboard_finance_subtitle', $home);
        self::assertStringContainsString('dashboard_actionable_invoice_where', $home);
        self::assertStringContainsString("invoice_type != 'long_term'", $home);
        self::assertStringContainsString('actionable_unpaid', $home);
    }

    public function testDashboardExpenseApisUseScopedActiveTotals(): void
    {
        $financialApi = $this->read('src/controllers/api/financial_summary.php');
        $dashboardApi = $this->read('src/controllers/api/dashboard_summary.php');

        foreach ([$financialApi, $dashboardApi] as $source) {
            self::assertStringContainsString('finance_scope_clause', $source);
            self::assertStringContainsString("e.status != 'void'", $source);
            self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $source);
        }

        self::assertStringContainsString("'expense_total'", $dashboardApi);
    }

    public function testFinancialHubUsesConsistentFinanceScope(): void
    {
        $acl = $this->read('src/utils/acl.php');
        self::assertStringContainsString('function finance_scope_clause', $acl);

        foreach ([
            'src/views/pages/financial/_expenses_tab.php',
            'src/views/pages/financial/assets-list.php',
            'src/views/pages/financial/financial-dashboard.php',
            'src/views/pages/financial/_expense_report_tab.php',
            'src/controllers/financial/expense_export.php',
        ] as $path) {
            self::assertStringContainsString('finance_scope_clause', $this->read($path), $path);
        }
    }

    public function testEachTabHasOneAddButtonInThePageHeader(): void
    {
        $expenses = $this->read('src/views/pages/financial/expenses-list.php');
        foreach (['+ Add expense', '+ Add recurring expense', '+ Upload receipt', '+ Log trip'] as $label) {
            self::assertSame(1, substr_count($expenses, "'{$label}'"), $label);
        }
        foreach (['_expenses_tab.php', '_recurring_expenses_tab.php', '_receipts_tab.php', '_vendors_tab.php', '_categories_tab.php', '_mileage_rates_tab.php'] as $file) {
            $source = $this->read('src/views/pages/financial/' . $file);
            self::assertStringNotContainsString('expense-ledger__head', $source, $file);
            self::assertStringNotContainsString('Back to Mileage', $source, $file);
        }
        foreach (['audit.php', 'expense-report.php', '_overview_tab.php', '_assets_tab.php', 'receipts-list.php', 'vendors-list.php', 'categories-list.php', 'mileage-settings.php'] as $retired) {
            self::assertFileDoesNotExist($this->root . '/src/views/pages/financial/' . $retired);
        }
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }
}
