<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettingsSavePersistenceTest extends TestCase
{
    private string $directory;
    private PDO $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pa-settings-save-' . bin2hex(random_bytes(8));
        foreach (['', '/src/controllers', '/src/utils', '/src/config', '/config', '/sessions'] as $suffix) {
            mkdir($this->directory . $suffix, 0700, true);
        }
        $root = dirname(__DIR__, 2);
        $handler = file_get_contents($root . '/src/controllers/settings_handler.php');
        // Never let this test touch a developer's real /var/www/config mount.
        $handler = str_replace("\$configMount = '/var/www/config';",
            "\$configMount = __DIR__ . '/../../config';", $handler, $replacements);
        self::assertSame(1, $replacements);
        file_put_contents($this->directory . '/src/controllers/settings_handler.php', $handler);
        foreach (['payment_methods.php', 'financial_reporting_currency.php', 'csrf.php', 'acl.php',
            'upload_validator.php', 'audit.php', 'client_ip.php', 'workforce_availability.php'] as $file) {
            copy($root . '/src/utils/' . $file, $this->directory . '/src/utils/' . $file);
        }
        copy($root . '/tests/fixtures/settings-save-driver.php', $this->directory . '/driver.php');
        file_put_contents($this->directory . '/src/config/db.php',
            '<?php $pdo = new SettingsSaveTestPDO(__DIR__ . "/../../settings.sqlite");');
        file_put_contents($this->directory . '/config/settings.json', '{}');
        $this->database = new PDO('sqlite:' . $this->directory . '/settings.sqlite', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('CREATE TABLE app_config(organization_id INTEGER NOT NULL DEFAULT 0,
            config_key TEXT NOT NULL, config_value TEXT NOT NULL, UNIQUE(organization_id,config_key))');
        $this->database->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, role TEXT NOT NULL);
            INSERT INTO users VALUES(1,'admin'),(2,'member');
            CREATE TABLE user_permissions_overrides(user_id INTEGER, organization_id INTEGER, permission TEXT, allowed INTEGER);
            INSERT INTO user_permissions_overrides VALUES(2,NULL,'settings.view',1),(2,NULL,'settings.manage',0)");
        $insert = $this->database->prepare('INSERT INTO app_config VALUES(0,?,?)');
        foreach (['google_oauth_client_secret_enc' => 'fixture-encrypted-google-credential',
            'google_routes_api_key_enc' => 'fixture-encrypted-routes-credential',
            'financial_base_currency' => '', 'notify_invoice_paid' => '0'] as $key => $value) {
            $insert->execute([$key, $value]);
        }
    }

    protected function tearDown(): void
    {
        unset($this->database);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testBlankOptionalIntegrationFieldsDoNotAbortCurrencySaveOrClearCredentials(): void
    {
        [$exit, $output] = $this->save();
        self::assertSame(0, $exit, $output);
        $values = $this->database->query('SELECT config_key,config_value FROM app_config')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('USD', $values['financial_base_currency']);
        self::assertSame('', $values['google_oauth_client_id']);
        self::assertSame('', $values['google_maps_browser_key']);
        self::assertSame('fixture-encrypted-google-credential', $values['google_oauth_client_secret_enc']);
        self::assertSame('fixture-encrypted-routes-credential', $values['google_routes_api_key_enc']);
        self::assertSame('0', $values['notify_invoice_paid']);
        $file = json_decode(file_get_contents($this->directory . '/config/settings.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('USD', $file['financial_base_currency']); // Reached the final save/PRG path.
        self::assertSame('fixture-encrypted-google-credential', $file['google_oauth_client_secret_enc']);
    }

    public function testInvalidCurrencyStopsBeforeAnySettingsPersistence(): void
    {
        [$exit, $output] = $this->save(['financial_base_currency' => 'usd']);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Reporting currency must be an uppercase', $output);
        self::assertSame('', $this->database->query("SELECT config_value FROM app_config WHERE config_key='financial_base_currency'")->fetchColumn());
        self::assertSame('{}', file_get_contents($this->directory . '/config/settings.json'));
    }

    public function testWorkflowSavePreservesShelvedWorkforceSettings(): void
    {
        $this->database->exec("INSERT INTO app_config VALUES(0,'workforce_currency','CAD')");
        file_put_contents($this->directory . '/config/settings.json', '{"workforce_currency":"CAD"}');
        [$exit, $output] = $this->save(['tab' => 'workflow', 'default_mileage_rate' => '0.700',
            'workforce_currency' => 'USD', 'workforce_allow_non_admin_time_management' => '1']);
        self::assertSame(0, $exit, $output);
        self::assertSame('CAD', $this->database->query("SELECT config_value FROM app_config WHERE config_key='workforce_currency'")->fetchColumn());
        self::assertFalse($this->database->query("SELECT config_value FROM app_config WHERE config_key='workforce_allow_non_admin_time_management'")->fetchColumn());
        $file = json_decode(file_get_contents($this->directory . '/config/settings.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CAD', $file['workforce_currency']);
        self::assertArrayNotHasKey('workforce_allow_non_admin_time_management', $file);
    }

    public function testViewOnlyMemberCannotWriteThroughLinksOrAccountTabs(): void
    {
        foreach (['links', 'account'] as $tab) {
            [$exit, $output] = $this->save(['tab' => $tab, 'brand_name' => 'Attacker brand'], 2);
            self::assertSame(0, $exit, $output);
            self::assertStringContainsString(
                $tab === 'links' ? 'You do not have permission' : 'No account change was requested',
                $output
            );
            self::assertSame('{}', file_get_contents($this->directory . '/config/settings.json'));
            self::assertFalse($this->database->query("SELECT config_value FROM app_config WHERE config_key='brand_name'")->fetchColumn());
        }
    }

    /** @return array{int,string} */
    private function save(array $overrides = [], int $userId = 1): array
    {
        $post = array_replace(['tab' => 'system', 'csrf' => 'settings-save-fixture', 'financial_base_currency' => 'USD',
            'google_oauth_client_id' => '', 'google_oauth_client_secret' => '',
            'google_maps_browser_key' => '', 'google_routes_api_key' => ''], $overrides);
        $process = proc_open([PHP_BINARY, $this->directory . '/driver.php', base64_encode(json_encode($post)), (string)$userId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->directory);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }
}
