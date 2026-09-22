<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/** Destructive two-connection acceptance; the runner sentinel is verified below. */
final class DirectoryCutoverGateMySqlTest extends TestCase
{
    private PDO $first;
    private PDO $second;
    /** @var array<string,string|false> */
    private array $previousFlags = [];

    protected function setUp(): void
    {
        $dsn = getenv('DIRECTORY_CUTOVER_GATE_MYSQL_DSN');
        $user = getenv('DIRECTORY_CUTOVER_GATE_MYSQL_USER');
        $password = getenv('DIRECTORY_CUTOVER_GATE_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) self::markTestSkipped('Run tools/run-directory-cutover-gate-mysql-integration.ps1 for isolated MySQL acceptance.');
        $database = trim((string)(getenv('DIRECTORY_CUTOVER_GATE_MYSQL_DATABASE') ?: ''));
        if (getenv('DIRECTORY_CUTOVER_GATE_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only' || preg_match('/^directory_cutover_gate_test_[a-f0-9]{32}$/D', $database) !== 1) throw new \RuntimeException('Directory cutover MySQL acceptance requires its disposable-runner sentinel.');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        if (!hash_equals($database, (string)$this->first->query('SELECT DATABASE()')->fetchColumn())) throw new \RuntimeException('Refusing to reset a MySQL database not created by the disposable runner.');
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_management.php';
        foreach (\api_v2_directory_management_required_flags() as $flag) { $this->previousFlags[$flag] = getenv($flag); putenv($flag . '=1'); }
        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->second->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $pdo) if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        foreach ($this->previousFlags as $flag => $value) putenv($flag . ($value === false ? '' : '=' . $value));
    }

    public function testActivationWaitsForLocalSharedWriterThenRejectsTheStaleAttestation(): void
    {
        $this->configureReadyPolicy();
        $this->first->beginTransaction();
        \api_v2_directory_management_acquire_shared_gate($this->first);
        $this->insertClient($this->first, 1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Committed local change');
        $activation = $this->startWorker('activate');
        $this->assertWorkerWaiting($activation, 'Activation crossed the local shared sentinel gate.');
        $this->first->commit();
        $result = $this->finishWorker($activation);
        self::assertSame('DomainException', $result['class'] ?? null, json_encode($result));
        self::assertMatchesRegularExpression('/backfill_(unhealthy|attestation_stale)/', (string)($result['message'] ?? ''));
        self::assertSame('0', (string)$this->second->query("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='api_v2_directory_management_ownership_active'")->fetchColumn());
        self::assertSame('0', (string)$this->second->query('SELECT ownership_active FROM api_v2_directory_management_policy WHERE singleton=1')->fetchColumn());
    }

    public function testWriterWaitingBehindExclusiveActivationIsDeniedBeforeItsSourceMutation(): void
    {
        $this->configureReadyPolicy();
        $this->first->beginTransaction();
        $policy = \api_v2_directory_management_lock_policy_after_sentinel($this->first);
        self::assertTrue(\api_v2_directory_management_activation_attestation_ready($this->first, $policy));
        $writer = $this->startWorker('local-writer');
        $this->assertWorkerWaiting($writer, 'Local writer crossed the exclusive activation sentinel gate.');
        // Same in-transaction state transition as activate(); direct here so the waiting writer is observable before the transition.
        $this->first->exec("UPDATE api_v2_directory_management_policy SET ownership_active=1,last_effective=1,last_reason='ready' WHERE singleton=1");
        \api_v2_directory_management_save_sentinel($this->first, true);
        $this->first->commit();
        $result = $this->finishWorker($writer);
        self::assertSame('DomainException', $result['class'] ?? null, json_encode($result));
        self::assertSame(0, (int)$this->second->query("SELECT COUNT(*) FROM clients WHERE name='worker mutation'")->fetchColumn());
        self::assertSame('1', (string)$this->second->query("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='api_v2_directory_management_ownership_active'")->fetchColumn());
    }

    public function testApiAuthoritySharesActiveGateAndTakeoverWaitsThenAtomicallyRestoresLocalControl(): void
    {
        $this->configureReadyPolicy(); $this->activateDirectly();
        $this->first->beginTransaction();
        \api_v2_directory_management_acquire_shared_gate($this->first, false);
        $this->insertClient($this->first, 2, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'API authority mutation');
        $takeover = $this->startWorker('takeover');
        $this->assertWorkerWaiting($takeover, 'Takeover crossed an in-flight API shared sentinel gate.');
        $this->first->commit();
        $result = $this->finishWorker($takeover);
        self::assertSame(true, $result['ok'] ?? null, json_encode($result));
        self::assertSame('0', (string)$this->second->query("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='api_v2_directory_management_ownership_active'")->fetchColumn());
        self::assertSame('0', (string)$this->second->query('SELECT ownership_active FROM api_v2_directory_management_policy WHERE singleton=1')->fetchColumn());
        $this->second->beginTransaction(); \api_v2_directory_management_acquire_shared_gate($this->second); $this->second->commit();
    }

    public function testApiSharedGatesDoNotSerializeEachOther(): void
    {
        $this->configureReadyPolicy(); $this->activateDirectly();
        $this->first->beginTransaction(); \api_v2_directory_management_acquire_shared_gate($this->first, false);
        $this->second->beginTransaction(); \api_v2_directory_management_acquire_shared_gate($this->second, false);
        self::assertTrue($this->second->inTransaction(), 'A second API shared gate should not wait behind the first.');
        $this->second->commit(); $this->first->commit();
    }

    private function activateDirectly(): void
    {
        $this->first->beginTransaction(); $policy = \api_v2_directory_management_lock_policy_after_sentinel($this->first);
        self::assertTrue(\api_v2_directory_management_activation_attestation_ready($this->first, $policy));
        $this->first->exec("UPDATE api_v2_directory_management_policy SET ownership_active=1,last_effective=1,last_reason='ready' WHERE singleton=1 AND ownership_active=0");
        \api_v2_directory_management_save_sentinel($this->first, true); $this->first->commit();
    }

    private function configureReadyPolicy(): void
    {
        \api_v2_directory_backfill_attestation_persist($this->first);
        $digest = \api_v2_directory_management_persist_attestation($this->first, 1);
        $this->first->prepare('INSERT INTO api_keys(id,api_v2_application_id,scopes,revoked_at) VALUES(7,3,?,NULL)')->execute([json_encode(\api_v2_directory_management_required_scopes(), JSON_THROW_ON_ERROR)]);
        $this->first->prepare("UPDATE api_v2_directory_management_policy SET configured_enabled=1,ownership_active=0,application_pk=3,source_instance_id=?,application_id=?,history_epoch=?,release_attestation_sha256=?,last_effective=0,last_reason='ready',configured_by=1 WHERE singleton=1")->execute(['123e4567-e89b-42d3-a456-426614174000','223e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000',$digest]);
        self::assertSame('ready', \api_v2_directory_management_status($this->first, false)['eligibility_reason']);
    }

    /** @return array{0:resource,1:resource,2:resource,3:string} */
    private function startWorker(string $action): array
    {
        $script = tempnam(sys_get_temp_dir(), 'directory-cutover-gate-'); if ($script === false) throw new \RuntimeException('Could not create a disposable worker script.');
        file_put_contents($script, <<<'PHP'
<?php
require $argv[1];
$pdo = new PDO(getenv('DIRECTORY_CUTOVER_GATE_MYSQL_DSN'), getenv('DIRECTORY_CUTOVER_GATE_MYSQL_USER'), getenv('DIRECTORY_CUTOVER_GATE_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
try {
    if ($argv[2] === 'activate') api_v2_directory_management_activate($pdo, 1, true);
    elseif ($argv[2] === 'takeover') api_v2_directory_management_save($pdo, false, 3, 1, true);
    elseif ($argv[2] === 'local-writer') { $pdo->beginTransaction(); api_v2_directory_management_acquire_shared_gate($pdo); $pdo->exec("INSERT INTO clients(id,public_id,name,email,phone,client_type,organization_id,address_line1,address_line2,city,state,postal_code,country) VALUES(9,'cccccccccccccccccccccccccccccccc','worker mutation','worker@example.test','555','consumer',NULL,'','','Madison','WI','53703','US')"); $pdo->commit(); }
    else throw new RuntimeException('Unknown worker action.');
    echo json_encode(['ok' => true]);
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['ok' => false, 'class' => (new ReflectionClass($error))->getShortName(), 'message' => $error->getMessage()]); }
PHP
        );
        $pipes = []; $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 2) . '/src/utils/api_v2_directory_management.php', $action], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { unlink($script); throw new \RuntimeException('Could not start the second MySQL connection.'); }
        return [$process, $pipes[1], $pipes[2], $script];
    }

    /** @param array{0:resource,1:resource,2:resource,3:string} $worker */
    private function assertWorkerWaiting(array $worker, string $message): void { usleep(300000); self::assertTrue(proc_get_status($worker[0])['running'], $message); }
    /** @param array{0:resource,1:resource,2:resource,3:string} $worker @return array<string,mixed> */
    private function finishWorker(array $worker): array
    {
        [$process, $stdout, $stderr, $script] = $worker; stream_set_blocking($stdout, true); stream_set_blocking($stderr, true); $output = stream_get_contents($stdout); $errors = stream_get_contents($stderr); fclose($stdout); fclose($stderr); $exit = proc_close($process); unlink($script);
        self::assertSame(0, $exit, $errors); $result = json_decode($output, true); self::assertIsArray($result, $output . $errors); return $result;
    }

    private function insertClient(PDO $pdo, int $id, string $publicId, string $name): void
    {
        $pdo->prepare("INSERT INTO clients(id,public_id,name,email,phone,client_type,organization_id,address_line1,address_line2,city,state,postal_code,country) VALUES(?,?,?,'local@example.test','555','consumer',NULL,'','','Madison','WI','53703','US')")->execute([$id,$publicId,$name]);
    }

    private function resetSchema(): void
    {
        $tables = ['api_v2_directory_management_audit','api_v2_directory_management_attestations','api_v2_directory_management_policy','api_v2_directory_backfill_attestations','api_v2_directory_lifecycle_command_receipts','api_v2_directory_relationship_command_receipts','api_v2_directory_binding_revoke_command_receipts','api_v2_directory_resource_changes','api_v2_directory_resource_state','api_v2_directory_authorization_state','api_keys','api_v2_applications','api_v2_history_identity','clients','organizations','app_config','schema_migrations'];
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0'); foreach ($tables as $table) $this->first->exec("DROP TABLE IF EXISTS `$table`"); $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec("CREATE TABLE schema_migrations(version INT PRIMARY KEY,filename VARCHAR(255)); CREATE TABLE app_config(organization_id INT NOT NULL,config_key VARCHAR(191) NOT NULL,config_value TEXT,PRIMARY KEY(organization_id,config_key)); CREATE TABLE api_v2_applications(id BIGINT UNSIGNED PRIMARY KEY,application_id CHAR(36),name VARCHAR(191)); CREATE TABLE api_keys(id BIGINT UNSIGNED PRIMARY KEY,api_v2_application_id BIGINT UNSIGNED,scopes LONGTEXT,revoked_at DATETIME NULL); CREATE TABLE api_v2_history_identity(singleton TINYINT PRIMARY KEY,source_instance_id CHAR(36),history_epoch CHAR(36)); CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT UNSIGNED PRIMARY KEY,authorization_generation BIGINT UNSIGNED); CREATE TABLE clients(id INT PRIMARY KEY,public_id CHAR(32),name VARCHAR(150),email VARCHAR(255),phone VARCHAR(50),client_type VARCHAR(20),organization_id INT NULL,address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(16),postal_code VARCHAR(32),country VARCHAR(100),archived TINYINT DEFAULT 0,deleted_at DATETIME NULL); CREATE TABLE organizations(id INT PRIMARY KEY,public_id CHAR(32),name VARCHAR(150),general_email VARCHAR(255),general_phone VARCHAR(50),address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(16),postal_code VARCHAR(32),country VARCHAR(100),archived TINYINT DEFAULT 0,deleted_at DATETIME NULL); CREATE TABLE api_v2_directory_resource_state(resource_type VARCHAR(32),public_id CHAR(32),revision BIGINT UNSIGNED,projection_sha256 CHAR(64),present TINYINT,PRIMARY KEY(resource_type,public_id)); CREATE TABLE api_v2_directory_resource_changes(resource_type VARCHAR(32),public_id CHAR(32),revision BIGINT UNSIGNED,action VARCHAR(16),PRIMARY KEY(resource_type,public_id,revision)); CREATE TABLE api_v2_directory_backfill_attestations(attestation_sha256 CHAR(64) PRIMARY KEY,attestation_json LONGTEXT,created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)); CREATE TABLE api_v2_directory_management_policy(singleton TINYINT PRIMARY KEY,configured_enabled TINYINT,ownership_active TINYINT,application_pk BIGINT UNSIGNED NULL,source_instance_id CHAR(36) NULL,application_id CHAR(36) NULL,history_epoch CHAR(36) NULL,release_attestation_sha256 CHAR(64) NULL,last_effective TINYINT,last_reason VARCHAR(64),configured_by INT NULL,configured_at DATETIME NULL); CREATE TABLE api_v2_directory_management_attestations(attestation_sha256 CHAR(64) PRIMARY KEY,attestation_json LONGTEXT,created_by INT NULL); CREATE TABLE api_v2_directory_management_audit(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(64),outcome VARCHAR(32),reason VARCHAR(64),application_pk BIGINT UNSIGNED NULL,actor_user_id INT NULL,target_type VARCHAR(32) NULL,action_name VARCHAR(96) NULL,metadata_json LONGTEXT NULL); CREATE TABLE api_v2_directory_lifecycle_command_receipts(application_pk BIGINT,resource_type VARCHAR(32),history_epoch CHAR(36),command_id CHAR(36),request_sha256 CHAR(64),action_name VARCHAR(32),public_id CHAR(32),expected_revision BIGINT,expected_authorization_generation BIGINT,result_revision BIGINT,result_authorization_generation BIGINT); CREATE TABLE api_v2_directory_relationship_command_receipts(application_pk BIGINT,history_epoch CHAR(36),command_id CHAR(36),request_sha256 CHAR(64),action_name VARCHAR(32),client_public_id CHAR(32),expected_client_revision BIGINT,expected_authorization_generation BIGINT,result_client_revision BIGINT,result_authorization_generation BIGINT); CREATE TABLE api_v2_directory_binding_revoke_command_receipts(application_pk BIGINT,resource_type VARCHAR(32),history_epoch CHAR(36),command_id CHAR(36),request_sha256 CHAR(64),external_id VARCHAR(255),public_id CHAR(32),expected_resource_revision BIGINT,expected_authorization_generation BIGINT,result_authorization_generation BIGINT); INSERT INTO app_config VALUES(0,'api_v2_directory_management_ownership_active','0'); INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000','disposable acceptance application'); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO api_v2_directory_management_policy VALUES(1,0,0,NULL,NULL,NULL,NULL,NULL,0,'not_configured',NULL,NULL);");
        foreach ([88,89,90,91,92,93,94,95,96,97,98,99,103] as $version) { $matches = glob(dirname(__DIR__, 2) . '/database/migrations/' . str_pad((string)$version, 4, '0', STR_PAD_LEFT) . '_*.sql'); self::assertCount(1, $matches); $this->first->prepare('INSERT INTO schema_migrations(version,filename) VALUES(?,?)')->execute([$version, basename($matches[0])]); }
    }
}
