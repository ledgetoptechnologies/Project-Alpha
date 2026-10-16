<?php
declare(strict_types=1);

// The real handler runs in a separate process because its PRG path exits.
// Translate MySQL syntax only; SQLite still enforces real NOT NULL storage.
final class SettingsSaveTestPDO extends PDO
{
    public function __construct(string $database)
    {
        parent::__construct('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function exec(string $statement): int|false
    {
        if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS app_config')) {
            return 0; // The fixture already created the equivalent constrained table.
        }
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'INFORMATION_SCHEMA.COLUMNS')) {
            return parent::query('SELECT 1'); // organization_id exists in the fixture schema.
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
            'ON CONFLICT(organization_id, config_key) DO UPDATE SET config_value = excluded.config_value', $query);
        return parent::prepare($query, $options);
    }
}

session_save_path(__DIR__ . '/sessions');
session_start();
$_SESSION = ['user' => ['id' => 1, 'role' => 'admin'], 'csrf' => 'settings-save-fixture'];
$_POST = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
$_FILES = [];
require __DIR__ . '/src/controllers/settings_handler.php';
