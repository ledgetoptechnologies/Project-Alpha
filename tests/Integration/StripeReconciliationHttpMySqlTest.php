<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Security\DatabaseSessionHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Exercises the actual front controller; never points at an existing database. */
final class StripeReconciliationHttpMySqlTest extends TestCase
{
    public function testRealAuthenticationPermissionsCsrfPreviewAndCanonicalFailureRedirects(): void
    {
        $dsn = (string)getenv('STRIPE_RECONCILIATION_HTTP_DSN');
        if ($dsn === '') self::markTestSkipped('Use the disposable Stripe HTTP runner.');
        $database = (string)getenv('STRIPE_RECONCILIATION_HTTP_DATABASE');
        if (getenv('STRIPE_RECONCILIATION_HTTP_ALLOW') !== 'isolated-disposable-only'
            || !preg_match('/^pa_stripe_http_[a-f0-9]{32}$/D', $database)
            || !preg_match('/^mysql:host=127\.0\.0\.1;port=([0-9]+);dbname=' . preg_quote($database, '/') . ';charset=utf8mb4$/D', $dsn, $match)) {
            throw new RuntimeException('Refusing a non-disposable loopback database.');
        }
        $pdo = new PDO($dsn, 'root', (string)getenv('STRIPE_RECONCILIATION_HTTP_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll());
        $root = dirname(__DIR__, 2);
        require_once $root . '/src/migrations/migration_lib.php';
        $pdo->exec('SET GLOBAL log_bin_trust_function_creators=1');
        $this->apply($pdo, $root . '/database/baseline.sql');
        foreach (\migration_files($root . '/database/migrations') as $file) {
            \migration_preflight($pdo, (int)$file['version']);
            $this->apply($pdo, $file['path']);
            $pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(?,?,?)')->execute([$file['version'], $file['filename'], $file['checksum']]);
        }
        \migration_schema_health($pdo);
        $pdo->exec("INSERT INTO users(id,email,password_hash,role) VALUES(90001,'admin@example.invalid','unusable','admin'),(90002,'member@example.invalid','unusable','member');
            INSERT INTO clients(id,name,email) VALUES(90001,'Synthetic HTTP client','client@example.invalid');
            INSERT INTO invoices(id,client_id,status,total,amount_paid,balance_due) VALUES(90001,90001,'unpaid',527.50,0,527.50);
            INSERT INTO payments(id,client_id,invoice_id,payment_method,stripe_payment_intent_id,status,amount,payment_date) VALUES(90001,90001,90001,'stripe','pi_synthetic_http','failed',0,CURDATE())");
        $admin = $this->session($pdo, 90001, 'admin');
        $member = $this->session($pdo, 90002, 'member');
        // A previously verified preview can outlive removal of Stripe config.
        $previousPreviewToken = bin2hex(random_bytes(32));
        $previousPreview = $this->session($pdo, 90001, 'admin', [90001 => ['token' => $previousPreviewToken, 'at' => time()]]);
        $before = $this->financialSnapshot($pdo);
        $this->withServer((int)$match[1], function (string $base) use ($admin, $member, $previousPreview, $previousPreviewToken, $pdo, $before): void {
            $preview = '/?page=payments/stripe-reconcile-preview&payment_id=90001';
            $action = '/?page=payments/stripe-reconcile';
            $anonymous = $this->request($base . $preview);
            self::assertSame(302, $anonymous['status']);
            self::assertSame('/?page=login', $anonymous['headers']['location']);
            self::assertSame(403, $this->request($base . $preview, $member['id'])['status']);
            self::assertSame(403, $this->request($base . $action, $member['id'], ['csrf' => $member['csrf'], 'payment_id' => '90001'])['status']);
            $response = $this->request($base . $preview, $admin['id']);
            self::assertSame(200, $response['status'], $response['body']);
            self::assertStringContainsString('Stripe', $response['body']);
            self::assertStringContainsString('provider_unavailable', $response['body']);
            self::assertStringNotContainsString('name="preview_token"', $response['body']);
            self::assertSame($before, $this->financialSnapshot($pdo), 'GET preview must not alter financial records');
            $missing = $this->request($base . $action, $admin['id'], ['payment_id' => '90001']);
            $this->assertCanonicalFailure($missing);
            self::assertStringContainsString('CSRF', rawurldecode($missing['headers']['location']));
            foreach (['0', '90001'] as $paymentId) {
                $response = $this->request($base . $action, $admin['id'], ['csrf' => $admin['csrf'], 'payment_id' => $paymentId]);
                $this->assertCanonicalFailure($response);
            }
            $this->assertCanonicalFailure($this->request($base . $action, $previousPreview['id'], [
                'csrf' => $previousPreview['csrf'], 'payment_id' => '90001', 'preview_token' => $previousPreviewToken,
            ]));
            self::assertSame($before, $this->financialSnapshot($pdo), 'denied/unconfigured POSTs must not settle or alter payments');
        });
    }

    private function apply(PDO $pdo, string $file): void
    {
        foreach (\migration_statements((string)file_get_contents($file)) as $sql) {
            $result = $pdo->query($sql);
            if ($result !== false) $result->closeCursor();
        }
    }

    private function financialSnapshot(PDO $pdo): array
    {
        $snapshot = [];
        foreach (['payments', 'invoices', 'stripe_refunds', 'stripe_disputes', 'email_delivery_log', 'public_links'] as $table) {
            $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        }
        return $snapshot;
    }

    private function session(PDO $pdo, int $userId, string $role, array $previews = []): array
    {
        $id = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $_SESSION = ['user' => ['id' => $userId, 'email' => $role . '@example.invalid', 'role' => $role, 'auth_version' => 1], 'csrf' => $csrf,
            'last_activity' => time(), 'authn' => ['method' => 'password', 'authenticated_at' => time(), 'absolute_expires_at' => time() + 3600]];
        if ($previews !== []) $_SESSION['stripe_reconciliation_preview'] = $previews;
        $payload = '';
        foreach ($_SESSION as $key => $value) $payload .= $key . '|' . serialize($value);
        $handler = new DatabaseSessionHandler($pdo);
        self::assertTrue($handler->write($id, $payload));
        self::assertTrue($handler->close());
        $_SESSION = [];
        return ['id' => $id, 'csrf' => $csrf];
    }

    private function assertCanonicalFailure(array $response): void
    {
        self::assertSame(302, $response['status'], $response['body']);
        $location = parse_url($response['headers']['location']);
        self::assertArrayNotHasKey('host', $location);
        self::assertSame('/', $location['path']);
        parse_str($location['query'] ?? '', $query);
        self::assertSame('payments/payments-list', $query['page'] ?? null);
        self::assertNotEmpty($query['error'] ?? null);
    }

    private function withServer(int $port, callable $test): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) throw new RuntimeException('Loopback port reservation failed');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $env = getenv();
        $env['DB_HOST'] = '127.0.0.1;port=' . $port;
        $env['MYSQL_DATABASE'] = (string)getenv('STRIPE_RECONCILIATION_HTTP_DATABASE');
        $env['MYSQL_USER'] = 'root';
        $env['MYSQL_PASSWORD'] = (string)getenv('STRIPE_RECONCILIATION_HTTP_PASSWORD');
        $env['APP_SKIP_DB_CONFIG'] = '1';
        $env['APP_ENV'] = 'production';
        foreach (['AUTH_DISABLED', 'APP_AUTH_DISABLED'] as $key) $env[$key] = 'false';
        foreach (['STRIPE_SECRET_KEY', 'stripe_secret_key', 'stripe_secret_key_enc', 'STRIPE_WEBHOOK_SECRET'] as $key) $env[$key] = '';
        $log = tmpfile();
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'], [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, dirname(__DIR__, 2), $env, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Loopback PHP server unavailable');
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($probe) { fclose($probe); $ready = true; break; }
                usleep(100000);
            }
            self::assertTrue($ready);
            $test('http://' . $address);
        } catch (\Throwable $error) {
            rewind($log);
            fwrite(STDERR, stream_get_contents($log));
            throw $error;
        } finally {
            proc_terminate($process);
            proc_close($process);
            fclose($log);
        }
    }

    private function request(string $url, ?string $session = null, ?array $post = null): array
    {
        $headers = ['X-Requested-With: XMLHttpRequest'];
        if ($session !== null) $headers[] = 'Cookie: PHPSESSID=' . $session;
        if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
        ]]));
        preg_match('/\s([0-9]{3})\s/', $http_response_header[0], $status);
        $parsed = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $parsed[strtolower($key)] = trim($value); }
        }
        return ['status' => (int)$status[1], 'headers' => $parsed, 'body' => (string)$body];
    }
}
