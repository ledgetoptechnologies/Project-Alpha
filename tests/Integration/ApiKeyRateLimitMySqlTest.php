<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ApiKeyRateLimitMySqlTest extends TestCase
{
    private PDO $first;
    private PDO $second;

    protected function setUp(): void
    {
        $dsn = getenv('API_RATE_LIMIT_MYSQL_DSN');
        $user = getenv('API_RATE_LIMIT_MYSQL_USER');
        $password = getenv('API_RATE_LIMIT_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) {
            self::markTestSkipped('Run tools/run-api-rate-limit-mysql-integration.ps1 for isolated MySQL tests.');
        }
        $database = trim((string)(getenv('API_RATE_LIMIT_MYSQL_DATABASE') ?: ''));
        if (getenv('API_RATE_LIMIT_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only'
            || preg_match('/^api_rate_limit_test_[a-f0-9]{32}$/D', $database) !== 1) {
            throw new \RuntimeException('API rate-limit MySQL tests require the disposable runner sentinel.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        $this->second->exec('SET SESSION innodb_lock_wait_timeout=1');
        $pdo = $this->first;
        $GLOBALS['pdo'] = $pdo;
        require_once dirname(__DIR__, 2) . '/src/utils/api_auth.php';
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $pdo) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testAdmissionSerializesOnTheApiKeyRowAndEnforcesTheRollingLimit(): void
    {
        $this->first->beginTransaction();
        $this->first->query('SELECT id FROM api_keys WHERE id=1 FOR UPDATE')->fetchColumn();
        try {
            \api_admit_rate_limited_key($this->second, 1, 1);
            self::fail('A competing admission crossed the API-key row lock.');
        } catch (PDOException $error) {
            self::assertSame(1205, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        }
        self::assertFalse($this->second->inTransaction());
        $this->first->commit();

        self::assertSame('allowed', \api_admit_rate_limited_key($this->second, 1, 1));
        self::assertSame('limited', \api_admit_rate_limited_key($this->first, 1, 1));
        self::assertSame(1, (int)$this->first->query('SELECT COUNT(*) FROM api_usage WHERE api_key_id=1')->fetchColumn());
        self::assertNotFalse($this->first->query('SELECT last_used_at FROM api_keys WHERE id=1')->fetchColumn());
    }

    public function testAccountingFailureRollsBackAndPropagates(): void
    {
        $this->first->exec("CREATE TRIGGER reject_api_usage BEFORE INSERT ON api_usage FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='blocked usage'");
        try {
            \api_admit_rate_limited_key($this->first, 1, 1);
            self::fail('Accounting failures must fail API-key admission closed.');
        } catch (PDOException $error) {
            self::assertSame(1062, (int)($error->errorInfo[1] ?? 0), $error->getMessage());
        }
        self::assertFalse($this->first->inTransaction());
        self::assertSame(0, (int)$this->first->query('SELECT COUNT(*) FROM api_usage WHERE api_key_id=1')->fetchColumn());
        self::assertNull($this->first->query('SELECT last_used_at FROM api_keys WHERE id=1')->fetchColumn());
    }

    public function testExpiredUsageDoesNotConsumeTheCurrentWindow(): void
    {
        $this->first->exec("INSERT INTO api_usage (api_key_id, used_at) VALUES (1, NOW() - INTERVAL 61 SECOND)");
        self::assertSame('allowed', \api_admit_rate_limited_key($this->first, 1, 1));
        self::assertSame(2, (int)$this->first->query('SELECT COUNT(*) FROM api_usage WHERE api_key_id=1')->fetchColumn());
    }

    public function testRevokedKeyIsRejectedAtTheSerializedBoundary(): void
    {
        $this->first->exec('UPDATE api_keys SET revoked_at=NOW() WHERE id=1');
        self::assertSame('invalid', \api_admit_rate_limited_key($this->first, 1, 1));
        self::assertSame(0, (int)$this->first->query('SELECT COUNT(*) FROM api_usage WHERE api_key_id=1')->fetchColumn());
    }

    private function resetSchema(): void
    {
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['api_usage', 'api_keys'] as $table) $this->first->exec("DROP TABLE IF EXISTS `$table`");
        $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec(
            'CREATE TABLE api_keys (
                id INT NOT NULL PRIMARY KEY,
                last_used_at TIMESTAMP NULL,
                revoked_at TIMESTAMP NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->first->exec(
            'CREATE TABLE api_usage (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                api_key_id INT NOT NULL,
                used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_api_usage_key_time (api_key_id, used_at),
                CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->first->exec('INSERT INTO api_keys (id) VALUES (1)');
    }
}
