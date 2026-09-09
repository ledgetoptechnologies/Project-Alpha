<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinancialSchedulingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testScheduleSchemaSupportsAuditAndExpenseReports(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        self::assertStringContainsString('report_type', (string)$baseline);
        self::assertStringContainsString('filters JSON', (string)$baseline);
    }

    public function testScheduleHandlerSupportsBusinessWideSchedules(): void
    {
        $handler = file_get_contents($this->root . '/src/controllers/financial/audit_schedule_handler.php');
        self::assertStringContainsString('request_client_org_id()', (string)$handler);
        self::assertStringContainsString('$scheduleOrgId = $organizationId > 0 ? $organizationId : null;', (string)$handler);
        self::assertStringContainsString('SELECT report_type FROM audit_schedules WHERE id=?', (string)$handler);
        self::assertStringContainsString('$requestedType === \'expense\'', (string)$handler);
    }

    public function testCronGeneratesExpenseScheduleArtifacts(): void
    {
        $cron = file_get_contents($this->root . '/src/cron/process_audit_schedules.php');
        self::assertStringContainsString('processExpenseSchedule', (string)$cron);
        self::assertStringContainsString('/var/www/config/reports/', (string)$cron);
        self::assertStringContainsString('Expense Report', (string)$cron);
    }

    public function testCronStatusSchemaSelfRepairsAndUsesConfiguredTimezone(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0018_repair_cron_job_runs_schema.sql');
        $state = file_get_contents($this->root . '/src/utils/cron_state.php');
        $backup = file_get_contents($this->root . '/src/cron/backup_database.php');
        $backupPage = file_get_contents($this->root . '/src/views/pages/settings/backup.php');
        $entrypoint = file_get_contents($this->root . '/cron/entrypoint.sh');
        $environmentHelper = file_get_contents($this->root . '/cron/entrypoint-encryption-key.sh');
        $crontab = file_get_contents($this->root . '/cron/crontab');
        $compose = file_get_contents($this->root . '/docker-compose.yml');

        self::assertStringContainsString('ADD COLUMN updated_at', (string)$migration);
        self::assertStringContainsString('cron_state_ensure_schema', (string)$state);
        self::assertStringContainsString('cron_state_ensure_schema($pdo)', (string)$backupPage);
        self::assertStringContainsString("require_once __DIR__ . '/../config/app.php';", (string)$backup);
        self::assertStringContainsString('function backup_database_run', (string)$backup);
        self::assertStringContainsString('backup_database_today_artifacts', (string)$backup);
        self::assertStringContainsString('$currentHour < $backupHour', (string)$backup);
        self::assertStringContainsString('Waiting for configured backup window', (string)$backup);
        self::assertStringNotContainsString("empty(\$appConfig['cron_enabled'])", (string)$backup);
        self::assertStringContainsString('The configured backup time has passed today; cron will catch up on the next hourly check', (string)$backupPage);
        self::assertStringContainsString("the next hourly check creates today's missing backup", (string)$backupPage);
        self::assertStringContainsString('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', (string)$crontab);
        self::assertStringContainsString('source /usr/local/lib/project-alpha/cron-encryption-key.sh', (string)$entrypoint);
        self::assertStringContainsString('cron_write_runtime_environment "$ENV_FILE"', (string)$entrypoint);
        self::assertStringContainsString("printf 'export %s=%q\\n'", (string)$environmentHelper);
        self::assertStringContainsString("config_key='timezone'", (string)$entrypoint);
        self::assertStringContainsString('/etc/localtime', (string)$entrypoint);
        self::assertStringContainsString('backup_database.php --scheduled', (string)$entrypoint);
        self::assertStringContainsString('stripe_reconciliation.php --startup', (string)$entrypoint);
        self::assertStringContainsString('project-alpha:cron-latest', (string)$compose);
        self::assertStringNotContainsString('cron-0.5.0-rc1', (string)$compose);
    }

    public function testManualBackupUsesInlineRunnerWithVisibleFailurePath(): void
    {
        $handler = file_get_contents($this->root . '/src/controllers/backup_handler.php');

        self::assertStringContainsString('backup_run_database_inline', (string)$handler);
        self::assertStringContainsString('backup_database_run([])', (string)$handler);
        self::assertStringContainsString('proc_open is disabled', (string)$handler);
        self::assertStringContainsString('set_time_limit', (string)$handler);
    }

    public function testDailyLinkResolverIsScheduledAndHonorsItsFeatureFlags(): void
    {
        $cron = file_get_contents($this->root . '/src/cron/daily_link_resolver.php');
        $crontab = file_get_contents($this->root . '/cron/crontab');
        $state = file_get_contents($this->root . '/src/utils/cron_state.php');
        $settings = file_get_contents($this->root . '/src/views/pages/settings/links.php');

        self::assertStringContainsString("empty(\$appConfig['link_resolver_enabled'])", (string)$cron);
        self::assertStringContainsString("empty(\$appConfig['link_resolver_daily_scan_enabled'])", (string)$cron);
        self::assertStringContainsString('pa_link_provider_best_rows($pdo)', (string)$cron);
        self::assertStringContainsString('$service->runProviderScan($provider)', (string)$cron);
        self::assertStringContainsString('cron_state_mark_success($pdo, $jobName, $summary)', (string)$cron);
        self::assertStringContainsString('cron_state_mark_failure($pdo, $jobName, $e)', (string)$cron);
        self::assertStringContainsString('15 4 * * * root', (string)$crontab);
        self::assertStringContainsString('daily_link_resolver.php', (string)$crontab);
        self::assertStringContainsString("'daily_link_resolver'", (string)$state);
        self::assertStringContainsString("['daily_link_resolver']", (string)$settings);
        self::assertStringContainsString('Nightly folder scan:', (string)$settings);
        self::assertStringContainsString('External delivery Worker / Public Base URL', (string)$settings);
        self::assertStringNotContainsString('LTDS-Ops Worker', (string)$settings);
    }

    public function testAuditAndOrganizationScriptsInitializeAfterAjaxNavigation(): void
    {
        $audit = file_get_contents($this->root . '/public/assets/js/audit-logic.js');
        $organization = file_get_contents($this->root . '/public/assets/js/organization-view-logic.js');
        self::assertStringContainsString("window.ProjectAlpha.registerPage('financial/audit'", (string)$audit);
        self::assertStringContainsString("window.ProjectAlpha.registerPage('organization/organization-view'", (string)$organization);
    }

    public function testAuditSchedulingIsASeparateVisibleAction(): void
    {
        $auditPage = file_get_contents($this->root . '/src/views/pages/financial/audit.php');
        self::assertStringContainsString('id="auditScheduleForm"', (string)$auditPage);
        self::assertStringContainsString('Scheduled Audit Emails', (string)$auditPage);
        self::assertStringContainsString('Save Schedule', (string)$auditPage);
    }
}
