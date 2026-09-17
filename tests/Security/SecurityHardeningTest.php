<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/acl.php';
require_once dirname(__DIR__, 2) . '/src/utils/password_reset_tokens.php';

use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase
{
    private string $root;
    private ?PDO $pdo = null;
    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $userIds = [];
    /** @var int[] */
    private array $orgIds = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (!$this->pdo instanceof PDO) {
            return;
        }

        foreach ($this->clientIds as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach ($this->userIds as $id) {
            $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        foreach ($this->orgIds as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id = ?')->execute([$id]);
        }
    }

    public function testClientRecordAccessIsScopedToActiveOrganization(): void
    {
        $pdo = $this->mysql();
        $suffix = bin2hex(random_bytes(5));
        $orgA = $this->insertOrganization("Security Org A {$suffix}");
        $orgB = $this->insertOrganization("Security Org B {$suffix}");
        $userId = $this->insertUser("security-client-{$suffix}@example.invalid");

        $sameOrgClient = $this->insertClient('Same Org Client', $orgA, $userId);
        $otherOrgClient = $this->insertClient('Other Org Client', $orgB, $userId);

        $_SESSION['user'] = [
            'id' => $userId,
            'email' => "security-client-{$suffix}@example.invalid",
            'role' => 'member',
        ];

        self::assertTrue(can_access_record($pdo, 'clients', $sameOrgClient, $userId));
        self::assertTrue(can_access_record($pdo, 'clients', $otherOrgClient, $userId));
    }

    public function testPasswordResetFailuresIncrementAndLockSharedCounter(): void
    {
        $pdo = $this->mysql();
        $suffix = bin2hex(random_bytes(5));
        $email = "security-reset-{$suffix}@example.invalid";
        $userId = $this->insertUser($email);

        $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at, attempts, used) VALUES (?, '123456', DATE_ADD(NOW(), INTERVAL 1 HOUR), 0, 0)")
            ->execute([$userId]);

        for ($i = 0; $i < 3; $i++) {
            try {
                password_reset_verify_and_consume($pdo, $email, '000000');
                self::fail('Wrong reset token should not verify.');
            } catch (RuntimeException $expected) {
                self::assertSame('badtoken', $expected->getMessage());
            }
        }

        $row = $pdo->prepare('SELECT attempts, used FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $row->execute([$userId]);
        $state = $row->fetch(PDO::FETCH_ASSOC);

        self::assertSame(3, (int)$state['attempts']);
        self::assertSame(1, (int)$state['used'], 'Third failed attempt should lock/consume the reset token.');
    }

    public function testHashedPasswordResetCodeIsSingleUseAndRevocable(): void
    {
        $pdo = $this->mysql();
        $suffix = bin2hex(random_bytes(5));
        $email = "security-reset-hash-{$suffix}@example.invalid";
        $userId = $this->insertUser($email);
        $code = '654321';

        $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at, attempts, used) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 0)')
            ->execute([$userId, hash('sha256', $code)]);

        self::assertSame($userId, password_reset_verify_and_consume($pdo, $email, $code));
        $this->expectException(RuntimeException::class);
        password_reset_verify_and_consume($pdo, $email, $code);
    }

    public function testPasswordResetEmailRequiresConfiguredSmtp(): void
    {
        self::assertFalse(password_reset_email_is_configured([]));
        self::assertFalse(password_reset_email_is_configured(['smtp_host' => '  ']));
        self::assertTrue(password_reset_email_is_configured(['smtp_host' => 'smtp.example.test']));
    }

    public function testConfirmedFindingFixesRemainInPlace(): void
    {
        $acl = $this->read('src/utils/acl.php');
        self::assertStringNotContainsString("if (\$table === 'clients') return true", $acl);

        $csv = $this->read('src/controllers/financial/csv_import.php');
        self::assertStringNotContainsString('$orgId = 1;', $csv);
        self::assertStringContainsString('request_client_org_id()', $csv);
        self::assertStringContainsString('financial.manage', $csv);
        self::assertStringContainsString('expense_categories WHERE id = ?', $csv);
        self::assertStringContainsString('$taxAmount = null;', $csv);
        self::assertStringContainsString('$pm = null;', $csv);

        $payments = $this->read('src/controllers/payments_create.php');
        $invoiceLifecycle = $this->read('src/utils/invoice_lifecycle.php');
        self::assertStringContainsString("can_access_record(\$pdo, 'invoices'", $payments);
        self::assertStringContainsString('invoice_record_locked_payment', $payments);
        self::assertStringContainsString('FOR UPDATE', $invoiceLifecycle);
        self::assertStringContainsString('organization_id = ?', $invoiceLifecycle);

        $revoke = $this->read('src/controllers/public_link_revoke.php');
        self::assertStringContainsString('document_access_require_manage', $revoke);

        $sign = $this->read('src/controllers/public_view/public_contract_sign.php');
        self::assertStringContainsString("\$status !== 'pending'", $sign);
        self::assertStringContainsString("AND status = 'pending'", $sign);
        self::assertStringContainsString("pa_public_link_terminalize(\$pdo, 'contract', \$contractId, 'signed')", $sign);

        $resetVerify = $this->read('src/controllers/auth/reset_verify.php');
        $resetUpdate = $this->read('src/controllers/auth/reset_update.php');
        self::assertStringContainsString('password_reset_verify_and_consume', $resetVerify);
        self::assertStringContainsString('reset_auth_version', $resetUpdate);
        self::assertStringContainsString('auth_version=?', $resetUpdate);

        $forms = $this->read('src/controllers/forms_handler.php');
        self::assertStringContainsString('INNER JOIN form_categories c ON c.id = d.category_id', $forms);
        self::assertStringContainsString('$stmt->rowCount() !== 1', $forms);
    }

    public function testStateChangingSecurityRoutesHaveExpectedGuardrails(): void
    {
        $front = $this->read('public/index.php');
        self::assertStringContainsString("'payments/payments-create'", $front);
        self::assertStringContainsString("'public-link-revoke'", $front);
        self::assertStringContainsString("'forms-handler'", $front);
        self::assertStringContainsString("'financial/csv-import'", $front);
        self::assertStringContainsString('csrf_verify_post_or_redirect($page)', $front);
        self::assertStringNotContainsString("'public-link-revoke'", $this->csrfSkipList($front));
        self::assertStringNotContainsString("'payments/payments-create'", $this->csrfSkipList($front));
        self::assertStringContainsString("'public-contract-sign'", $this->csrfSkipList($front));
        self::assertStringContainsString("'stripe-webhook'", $this->csrfSkipList($front));
        self::assertStringContainsString("'2fa-verify-action'", $this->csrfSkipList($front));
        self::assertStringContainsString("'2fa-setup-action'", $this->csrfSkipList($front));

        $aclMiddleware = $this->read('src/utils/acl_middleware.php');
        foreach (['payments/payments-create', 'public-link-revoke', 'forms-handler', 'financial/csv-import'] as $route) {
            self::assertStringContainsString("'{$route}'", $aclMiddleware, "{$route} must have an ACL decision.");
        }

        $publicContractSign = $this->read('src/controllers/public_view/public_contract_sign.php');
        self::assertStringContainsString("csrf_sf_is_valid('public_contract_sign'", $publicContractSign);
    }

    public function testOperatorHardeningPoliciesAreEnabled(): void
    {
        $securityPolicy = $this->read('SECURITY.md');
        self::assertStringContainsString('Do not commit `.env`, `config/.env`', $securityPolicy);
        self::assertStringContainsString('Public document, quote, invoice, payment, and onboarding links', $securityPolicy);

        $envExample = $this->read('config/.env.example');
        self::assertStringContainsString('Never commit real credentials', $envExample);
        self::assertStringContainsString('APP_ENCRYPTION_KEY=', $envExample);
        self::assertStringContainsString('BACKUP_ENCRYPTION_KEY=', $envExample);
        self::assertStringNotContainsString('rootpass', $envExample);
        self::assertStringNotContainsString('sk_live_', $envExample);

        $migrator = $this->read('docker/migrate.sh');
        $compose = $this->read('docker-compose.yml');
        $recovery = $this->read('bin/admin-recovery.php');
        self::assertStringNotContainsString('admin_sync.php', $migrator);
        self::assertStringNotContainsString('ADMIN_PASSWORD', $compose);
        self::assertStringContainsString('recover_admin_account', $recovery);

        $front = $this->read('public/index.php');
        self::assertStringContainsString('AUTH_DISABLED ignored because APP_ENV is production or not explicitly development/test', $front);
        self::assertStringContainsString('two_factor_warning_needed', $front);
        self::assertStringNotContainsString('two_factor_enforce_required($pdo, $page)', $front);
        self::assertStringContainsString('Development auth bypass', $this->read('src/views/partials/header.php'));
        self::assertStringContainsString('2fa-warning-dismiss', $front);

        $setup = $this->read('src/controllers/auth/two_factor_setup.php');
        $policy = $this->read('src/utils/two_factor_policy.php');
        self::assertStringContainsString('two_factor_recommended_for_user', $policy);
        self::assertStringContainsString('two_factor_warning_needed', $policy);
        self::assertStringNotContainsString('function two_factor_enforce_required', $policy);
        self::assertStringContainsString("'employee_pay.view'", $policy);
        self::assertStringNotContainsString('Two-factor authentication is required for your account', $setup);
        self::assertStringContainsString('Disable Authenticator', $this->read('src/views/pages/auth/two_factor_setup.php'));

        $docker = $this->read('docker/start.sh');
        $dockerfile = $this->read('Dockerfile');
        self::assertStringContainsString('Production readiness checks:', $docker);
        self::assertStringContainsString('BACKUP_ENCRYPTION_KEY is not set', $docker);
        self::assertStringContainsString('Stripe webhook secret is not configured in app settings', $docker);
        self::assertStringContainsString('AUTH_DISABLED/APP_AUTH_DISABLED is set but ignored in production', $docker);
        self::assertStringNotContainsString('ServerTokens Prod', $docker);
        self::assertStringContainsString('echo "ServerTokens Prod"', $dockerfile);
        self::assertStringContainsString('echo "ServerSignature Off"', $dockerfile);
    }

    public function testComposeHasOneExplicitProductionDefinition(): void
    {
        $composeFiles = glob($this->root . '/docker-compose*.yml') ?: [];
        $productionComposeFiles = array_values(array_filter(
            $composeFiles,
            static fn(string $path): bool => !preg_match('/docker-compose\.(?:staging|truenas)\.yml$/', $path)
        ));
        sort($productionComposeFiles);
        self::assertSame([$this->root . '/docker-compose.yml'], $productionComposeFiles);

        $compose = $this->read('docker-compose.yml');
        self::assertDoesNotMatchRegularExpression('/\$\{[A-Z0-9_]+:-/', $compose);
        self::assertStringContainsString('image: "ghcr.io/ledgetoptechnologies/project-alpha:latest"', $compose);
        self::assertStringContainsString('image: "ghcr.io/ledgetoptechnologies/project-alpha:cron-latest"', $compose);
        self::assertStringContainsString('image: "ghcr.io/ledgetoptechnologies/project-alpha:db-latest"', $compose);
        self::assertStringNotContainsString('x-pa-settings:', $compose);
        self::assertStringNotContainsString('&pa-', $compose);
        self::assertLessThanOrEqual(160, count(file($this->root . '/docker-compose.yml') ?: []));
        self::assertStringContainsString('APP_ENV: production', $compose);
        self::assertStringContainsString('- "1627:80"', $compose);
    }

    public function testMysqlNativeEncryptionDeploymentFailsClosed(): void
    {
        $compose = $this->read('docker-compose.yml');
        $dockerfile = $this->read('Dockerfile');
        $mysqlConfig = $this->read('docker/mysql/pa-encryption.cnf');
        $manifest = $this->read('docker/mysql/mysqld.my');
        $componentConfig = $this->read('docker/mysql/component_keyring_file.cnf');
        $entrypoint = $this->read('docker/mysql/entrypoint.sh');
        $healthcheck = $this->read('docker/mysql/healthcheck.sh');
        $publishWorkflow = $this->read('.github/workflows/docker-publish.yml');

        self::assertStringContainsString('MYSQL_ENCRYPTION_REQUIRED: "true"', $compose);
        self::assertStringContainsString('/var/lib/mysql-keyring', $compose);
        self::assertStringNotContainsString('keyring-init:', $compose);
        self::assertStringNotContainsString('mysql_keyring_manifest:', $compose);
        self::assertStringContainsString('FROM mysql:8.4 AS db', $dockerfile);
        self::assertStringContainsString('microdnf remove -y mysql-shell', $dockerfile);
        self::assertStringContainsString('exec chroot --userspec=mysql:mysql --groups=mysql', $dockerfile);
        self::assertStringContainsString('rm -f /usr/local/bin/gosu', $dockerfile);
        self::assertStringContainsString('pa-mysql-entrypoint.sh', $dockerfile);
        self::assertStringContainsString('pa-mysql-healthcheck.sh', $dockerfile);
        self::assertStringContainsString('default_table_encryption=ON', $mysqlConfig);
        self::assertStringContainsString('table_encryption_privilege_check=ON', $mysqlConfig);
        self::assertStringContainsString('innodb_redo_log_encrypt=ON', $mysqlConfig);
        self::assertStringContainsString('innodb_undo_log_encrypt=ON', $mysqlConfig);
        self::assertStringContainsString('binlog_encryption=ON', $mysqlConfig);
        self::assertStringContainsString('file://component_keyring_file', $manifest);
        self::assertStringContainsString('/var/lib/mysql-keyring/component_keyring_file', $componentConfig);
        self::assertStringContainsString('{"version":"1.0","elements":[]}', $entrypoint);
        self::assertStringContainsString('chmod 0600 "$keyring_file"', $entrypoint);
        self::assertStringContainsString("STATUS_KEY='Component_status'", $healthcheck);
        self::assertStringContainsString('@@global.binlog_encryption=1', $healthcheck);
        self::assertStringContainsString('target: db', $publishWorkflow);
        self::assertStringContainsString('db_tag=db-latest', $publishWorkflow);

        $migrator = $this->read('docker/migrate.sh');
        $encryption = $this->read('docker/enable-mysql-encryption.sh');
        self::assertStringContainsString('/usr/local/bin/enable-mysql-encryption.sh', $migrator);
        self::assertStringContainsString('SELECT @@GLOBAL.log_bin_trust_function_creators', $migrator);
        self::assertStringContainsString('SET GLOBAL log_bin_trust_function_creators = 1', $migrator);
        self::assertStringContainsString('restore_function_creator_trust', $migrator);
        self::assertStringNotContainsString('log_bin_trust_function_creators=ON', $mysqlConfig);
        self::assertStringContainsString('component_keyring_file is not active', $encryption);
        self::assertStringContainsString('ALTER DATABASE', $encryption);
        self::assertStringContainsString("ALTER TABLESPACE mysql ENCRYPTION = 'Y'", $encryption);
        self::assertStringContainsString("ENCRYPTION = ''Y''", $encryption);
        self::assertStringContainsString('remaining=$remaining', $encryption);
        self::assertStringContainsString('@@global.binlog_encryption', $encryption);

        if (PHP_OS_FAMILY !== 'Windows') {
            foreach ([
                'docker/migrate.sh' => 'bash',
                'docker/enable-mysql-encryption.sh' => 'bash',
                'docker/mysql/entrypoint.sh' => 'sh',
                'docker/mysql/healthcheck.sh' => 'sh',
            ] as $script => $shell) {
                $output = [];
                $exitCode = 0;
                exec($shell . ' -n ' . escapeshellarg($this->root . '/' . $script) . ' 2>&1', $output, $exitCode);
                self::assertSame(0, $exitCode, $script . ': ' . implode("\n", $output));
            }
        }
    }

    public function testBackupRestoreAvoidsShellCommandComposition(): void
    {
        $handler = $this->read('src/controllers/backup_handler.php');

        self::assertStringContainsString('proc_open(', $handler);
        self::assertStringContainsString("['mysql', '-h', \$host, '-P', \$port, '-u', \$user, \$database]", $handler);
        self::assertStringContainsString("putenv('MYSQL_PWD=' . \$password)", $handler);
        self::assertStringContainsString('backup_restore_database_stream', $handler);
        self::assertStringNotContainsString('gunzip -c', $handler);
        self::assertStringNotContainsString(' < %s', $handler);
        self::assertStringNotContainsString('-p%s', $handler);
        self::assertStringNotContainsString('exec($cmd', $handler);
    }

    public function testSettingsLogViewerShowsRecentFilesAndNormalViewLinks(): void
    {
        $view = $this->read('src/views/pages/settings/logs.php');
        $handler = $this->read('src/controllers/settings/logs_handler.php');

        self::assertStringContainsString('array_slice($allLogFileMap, 0, 5, true)', $view);
        self::assertStringContainsString('filemtime($b)', $view);
        self::assertStringContainsString('Showing the five most recent', $view);
        self::assertStringContainsString('data-skip-nav href="/?page=settings&amp;tab=logs&amp;file=', $view);
        self::assertStringContainsString('white-space:pre-wrap', $view);
        self::assertStringContainsString('overflow-wrap:anywhere', $view);
        self::assertStringContainsString('uasort($files', $handler);
        self::assertStringContainsString('filemtime($b)', $handler);
    }

    public function testApiKeysExposeScopedAclControlsAndKeepLegacyScopesWorking(): void
    {
        $front = $this->read('public/index.php');
        $auth = $this->read('src/utils/api_auth.php');
        $scopes = $this->read('src/utils/api_scopes.php');
        $view = $this->read('src/views/pages/api-keys.php');
        $newView = $this->read('src/views/pages/api-keys-new.php');
        $editView = $this->read('src/views/pages/api-keys-edit.php');
        $sharedView = $this->read('src/views/pages/api_keys_shared.php');
        $create = $this->read('src/controllers/api_keys_create.php');
        $update = $this->read('src/controllers/api_keys_update.php');
        $acl = $this->read('src/utils/acl_middleware.php');
        $audit = $this->read('src/utils/audit_middleware.php');

        self::assertStringContainsString('api_scope_endpoint_map()', $front);
        self::assertStringContainsString('api_require_key([$requiredApiScope])', $front);
        self::assertStringContainsString('X-API-Key', $front);
        self::assertStringNotContainsString("api_require_key(['full'])", $front);

        foreach (['dashboard.read', 'financial.read', 'clients.read', 'projects.read', 'quotes.read', 'invoices.read'] as $scope) {
            self::assertStringContainsString($scope, $scopes);
        }
        foreach (['api-dashboard-summary', 'api-financial-summary', 'api-clients-search'] as $endpoint) {
            self::assertStringContainsString($endpoint, $scopes);
        }

        self::assertStringContainsString('api_key_has_scope', $auth);
        self::assertStringContainsString('$GLOBALS[\'pa_service_principal\']', $auth);
        self::assertStringNotContainsString('$_SESSION[\'service_principal\']', $auth);
        self::assertStringNotContainsString('$_SESSION[\'user\'] =', $auth);
        self::assertStringNotContainsString("'role' => 'admin'", $auth);
        self::assertStringContainsString("['read', 'write', 'read.write', 'read_write']", $scopes);
        self::assertStringContainsString("['*', 'all', 'admin', 'full_access', 'full-access']", $scopes);

        self::assertStringContainsString('api_keys_shared.php', $view);
        self::assertStringContainsString('api_scope_options_for_form()', $newView);
        self::assertStringContainsString('api_scope_options_for_form()', $editView);
        self::assertStringContainsString('api_keys_scope_checkboxes', $sharedView);
        self::assertStringContainsString('/?page=api-keys-update', $editView);
        self::assertStringContainsString('/?page=api-keys-edit&amp;id=', $view);
        self::assertStringContainsString('pa_api_keys_existing_columns($pdo)', $view);
        self::assertStringContainsString('api_scopes_to_storage($scopes)', $create);
        self::assertStringContainsString('api_scopes_to_storage($scopes)', $update);
        self::assertStringContainsString("'api-keys-new'", $acl);
        self::assertStringContainsString("'api-keys-edit'", $acl);
        self::assertStringContainsString("'api-keys-update'", $acl);
        self::assertStringContainsString("'api-keys-update'", $audit);
    }

    public function testPublicLinkUploadsAndTextInputsAreHardened(): void
    {
        $billing = $this->read('src/views/pages/settings/billing.php');
        self::assertStringContainsString('<summary style="cursor:pointer;font-weight:600;color:#111827">Advanced</summary>', $billing);
        self::assertStringContainsString('Processor Imports', $billing);

        $uploadValidator = $this->read('src/utils/upload_validator.php');
        self::assertStringContainsString('array $options = []', $uploadValidator);
        self::assertStringContainsString('function upload_looks_like_archive', $uploadValidator);
        self::assertStringContainsString('function validate_image_upload_shape', $uploadValidator);
        self::assertStringContainsString('function validate_pdf_upload_content', $uploadValidator);
        self::assertStringContainsString('Virus scanning is required but not configured', $uploadValidator);
        self::assertStringContainsString('scan_clamav(string $filepath, bool $required = false)', $uploadValidator);

        foreach ([
            'src/controllers/public_view/public_contract_sign.php',
            'src/controllers/public_view/public_contract_action.php',
        ] as $path) {
            $controller = $this->read($path);
            self::assertStringContainsString('reject_archives', $controller);
            self::assertStringContainsString('reject_pdf_active_content', $controller);
            self::assertStringContainsString('PUBLIC_UPLOAD_CLAMAV_REQUIRED', $controller);
            self::assertStringContainsString("preg_match('/^[a-f0-9]{32,64}$/", $controller);
            self::assertStringContainsString("pa_public_link_redirect_path('contract', 'signed')", $controller);
        }
        self::assertStringContainsString('max_image_pixels', $this->read('src/controllers/public_view/public_contract_sign.php'));
        self::assertStringContainsString('is_readable($storedFile)', $this->read('src/controllers/public_view/public_contract_sign.php'));

        $onboarding = $this->read('src/utils/client_onboarding.php');
        self::assertStringContainsString('strip_tags($value)', $onboarding);
        self::assertStringContainsString('/[^\\P{C}\\t\\r\\n]/u', $onboarding);

        $publicDoc = $this->read('src/views/public/doc-wrapper.php');
        self::assertStringContainsString('accept="application/pdf,.pdf,image/jpeg,.jpg,.jpeg,image/png,.png,image/gif,.gif,image/webp,.webp"', $publicDoc);
        self::assertStringContainsString('download it to your device first', $publicDoc);

        $publicTwig = $this->read('src/views/public/doc-template.twig');
        self::assertStringContainsString('/?page=public-contract-sign', $publicTwig);
        self::assertStringContainsString('contractSignCsrf', $publicTwig);
        self::assertStringContainsString('accept="application/pdf,.pdf,image/jpeg,.jpg,.jpeg,image/png,.png,image/gif,.gif,image/webp,.webp"', $publicTwig);
    }

    public function testMigrationRunnerRetriesTransientMysqlLockFailures(): void
    {
        $runner = $this->read('src/migrations/run_migrations.php');

        self::assertStringContainsString('migration_execute_with_lock_retry', $runner);
        self::assertStringContainsString('migration_is_retryable_lock_failure', $runner);
        self::assertStringContainsString("['40001', 'HY000']", $runner);
        self::assertStringContainsString('[1205, 1213]', $runner);
        self::assertStringContainsString('MIGRATION_LOCK_RETRY_ATTEMPTS', $runner);
        self::assertStringContainsString('MIGRATION_LOCK_RETRY_BASE_MS', $runner);
        self::assertStringContainsString('$pdo->query($statement)', $runner);
        self::assertStringContainsString('ledger insert', $runner);
    }

    public function testFinancialModuleDoesNotHardCodeOrganizationOne(): void
    {
        foreach ([
            $this->root . '/src/controllers/financial',
            $this->root . '/src/views/pages/financial',
        ] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string)file_get_contents($file->getPathname());
                self::assertDoesNotMatchRegularExpression('/\$orgId\s*=\s*1\s*;/', $contents, $file->getPathname());
                self::assertDoesNotMatchRegularExpression('/organization_id\s*=\s*1\b/', $contents, $file->getPathname());
            }
        }
    }

    public function testCiAndReleaseGatesAreStrict(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        self::assertStringContainsString('composer validate --strict', $ci);
        self::assertStringContainsString('composer audit --locked', $ci);
        self::assertStringContainsString('docker compose config', $ci);

        $docker = $this->read('.github/workflows/docker-publish.yml');
        self::assertStringContainsString('exit-code: \'1\'', $docker);
        self::assertStringContainsString("severity: 'CRITICAL,HIGH'", $docker);
        self::assertStringContainsString(':sha-${{ steps.source.outputs.sha }}', $docker);
        self::assertStringContainsString(':cron-sha-${{ steps.source.outputs.sha }}', $docker);
        self::assertStringContainsString(':db-sha-${{ steps.source.outputs.sha }}', $docker);
    }

    public function testDockerPublishingUsesAuthoritativeBranchPushesWithoutDuplicateMergeTrigger(): void
    {
        $docker = $this->read('.github/workflows/docker-publish.yml');

        self::assertStringContainsString('branches: ["dev", "main"]', $docker);
        self::assertStringContainsString('workflow_dispatch:', $docker);
        self::assertStringContainsString('group: docker-${{ github.ref_name }}', $docker);
        self::assertStringContainsString('cancel-in-progress: true', $docker);
        self::assertStringNotContainsString('pull_request_target:', $docker);
        self::assertStringNotContainsString('github.event.pull_request', $docker);
        self::assertSame(3, substr_count($docker, 'cache-to: type=gha,mode=max,ignore-error=true'));
    }

    public function testNavigationDiagnosticsDoNotTreatExternalInputAsCodeOrMarkup(): void
    {
        $navigation = $this->read('public/assets/navigation.js');

        self::assertStringContainsString(
            "console.error('Page initializer failed for %s', normalizedPage, err);",
            $navigation
        );
        self::assertStringNotContainsString(
            "console.error('Page initializer failed for ' + normalizedPage, err);",
            $navigation
        );
        self::assertStringContainsString("newMainContent.querySelectorAll('script')", $navigation);
        self::assertStringNotContainsString("scripts.length === 0 && doc.querySelector('script')", $navigation);
        self::assertStringNotContainsString('html.match(/<script', $navigation);
    }

    public function testSecurityWorkflowsUseExplicitLeastPrivilegeTokens(): void
    {
        $gitleaks = str_replace(["\r\n", "\r"], "\n", $this->read('.github/workflows/gitleaks.yml'));
        self::assertMatchesRegularExpression('/(?m)^permissions:\n  contents: read$/', $gitleaks);

        $docker = str_replace(["\r\n", "\r"], "\n", $this->read('.github/workflows/docker-publish.yml'));
        self::assertMatchesRegularExpression(
            '/(?m)^    permissions:\n      contents: read\n      packages: write$/',
            $docker
        );
        self::assertFileDoesNotExist($this->root . '/.github/workflows/docker-image.yml');
    }

    private function mysql(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL security backend unavailable: ' . $error->getMessage());
        }

        return $this->pdo;
    }

    private function insertOrganization(string $name): int
    {
        $pdo = $this->mysql();
        $pdo->prepare('INSERT INTO organizations (name) VALUES (?)')->execute([$name]);
        $id = (int)$pdo->lastInsertId();
        $this->orgIds[] = $id;
        return $id;
    }

    private function insertUser(string $email): int
    {
        $pdo = $this->mysql();
        $pdo->prepare('INSERT INTO users (email, username, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([$email, 'security-test', password_hash('Security-Test-123!', PASSWORD_DEFAULT), 'user']);
        $id = (int)$pdo->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function insertClient(string $name, int $orgId, int $createdBy): int
    {
        $pdo = $this->mysql();
        $pdo->prepare('INSERT INTO clients (name, organization_id, created_by) VALUES (?, ?, ?)')
            ->execute([$name, $orgId, $createdBy]);
        $id = (int)$pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }

    private function csrfSkipList(string $frontController): string
    {
        self::assertMatchesRegularExpression('/\$skipCsrfFor\s*=\s*\[(.*?)\];/s', $frontController);
        preg_match('/\$skipCsrfFor\s*=\s*\[(.*?)\];/s', $frontController, $matches);
        return $matches[1] ?? '';
    }
}
