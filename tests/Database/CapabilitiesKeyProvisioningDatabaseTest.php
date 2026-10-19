<?php
declare(strict_types=1);

namespace Tests\Database;

use App\Services\ApiV2CapabilitiesKeyProvisioner;
use App\Services\LocalOperatorKeyReceipt;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\CapabilityLostAckPDO;
use Tests\Fixtures\CapabilitiesKeyProvisioningCases;

require_once dirname(__DIR__) . '/fixtures/CapabilitiesKeyProvisioningCases.php';
require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/services/LocalOperatorKeyReceipt.php';

/** Destructive fixture reset is allowed only in an explicitly disposable DB. */
final class CapabilitiesKeyProvisioningDatabaseTest extends TestCase
{
    use CapabilitiesKeyProvisioningCases;

    private CapabilityLostAckPDO $pdo;
    private mixed $oldSession;
    private string $privateDirectory;

    protected function setUp(): void
    {
        if (getenv('PA_TEST_DISPOSABLE_CAPABILITY_KEYS') !== '1'
            || !preg_match('/^pa_capability_proof_[a-z0-9]+$/D', getenv('MYSQL_DATABASE') ?: '')
            || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires an explicitly disposable root CLI MySQL fixture.');
        }
        $this->oldSession = $_SESSION ?? null;
        $_SESSION = [];
        $this->pdo = new CapabilityLostAckPDO(
            'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('MYSQL_DATABASE') . ';charset=utf8mb4',
            getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        self::assertSame(getenv('MYSQL_DATABASE'), $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame(110, (int) $this->pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn());
        foreach (['system_audit', 'api_keys', 'api_v2_directory_authorization_state', 'api_v2_project_authorization_state', 'api_v2_applications'] as $table) {
            $this->pdo->exec('DELETE FROM ' . $table);
        }
        $this->pdo->exec("UPDATE api_v2_history_identity SET source_instance_id='123e4567-e89b-42d3-a456-426614174000',history_epoch='223e4567-e89b-42d3-a456-426614174000' WHERE singleton=1;
            INSERT INTO api_v2_applications(id,application_id,name) VALUES(3,'323e4567-e89b-42d3-a456-426614174000','Existing app'),(4,'423e4567-e89b-42d3-a456-426614174000','Unrelated app');
            INSERT INTO api_v2_directory_authorization_state(application_pk,authorization_generation) VALUES(3,4),(4,8);
            INSERT INTO api_v2_project_authorization_state(application_pk,authorization_generation) VALUES(3,5),(4,9);
            INSERT INTO api_keys(id,name,key_prefix,key_hash,scopes,api_v2_application_id) VALUES(7,'Existing command key','existing','synthetic-existing-hash','full',3)");
        $this->privateDirectory = '/tmp/pa-capability-cli-' . bin2hex(random_bytes(8));
        mkdir($this->privateDirectory, 0700);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateDirectory)) {
            foreach (glob($this->privateDirectory . '/*') ?: [] as $path) unlink($path);
            rmdir($this->privateDirectory);
        }
        if (isset($this->oldSession)) $_SESSION = $this->oldSession;
        else unset($_SESSION);
    }

    private function command(array $arguments): array
    {
        $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testCliRequiresReviewedPlanAndNeverPrintsSavedSecret(): void
    {
        $cli = dirname(__DIR__, 2) . '/bin/provision-api-v2-capabilities-key.php';
        $planFile = $this->privateDirectory . '/plan.json';
        $secretFile = $this->privateDirectory . '/key.json';
        $args = [PHP_BINARY, $cli, '--dry-run', '--plan-file=' . $planFile, '--secret-file=' . $secretFile];
        foreach ($this->input() as $key => $value) {
            $option = ['requestId'=>'request-id','sourceInstanceId'=>'source-instance-id','historyEpoch'=>'history-epoch','applicationId'=>'application-id'][$key];
            $args[] = '--' . $option . '=' . $value;
        }
        [$exit, $output, $error] = $this->command($args);
        self::assertSame(0, $exit, $error);
        self::assertFileDoesNotExist($secretFile);
        $seal = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['planSha256'];
        $apply = [PHP_BINARY, $cli, '--apply', '--plan-file=' . $planFile, '--reviewed-plan-sha256=' . $seal];
        self::assertSame(1, $this->command($apply)[0]);
        self::assertFileDoesNotExist($secretFile);
        $apply[] = '--confirm-create-capabilities-key';
        [$exit, $output, $error] = $this->command($apply);
        self::assertSame(0, $exit, $error);
        self::assertSame('created', json_decode($output, true)['state']);
        $secret = json_decode(file_get_contents($secretFile), true)['secret'];
        self::assertStringNotContainsString($secret, $output . $error);
        self::assertSame(0600, fileperms($secretFile) & 0777);
        [$exit, $output, $error] = $this->command($apply);
        self::assertSame(0, $exit, $error);
        self::assertSame('already_committed', json_decode($output, true)['state']);
        self::assertStringNotContainsString($secret, $output . $error);
        self::assertSame(['5','6'], $this->generation());
        self::assertSame(2, $this->countKeys());
    }

    public function testConcurrentDifferentFilesCannotCreateDuplicateRequest(): void
    {
        $plan = $this->service()->dryRun($this->input());
        // Hold the same existing application row while both independent PHP
        // connections start, then allow MySQL to serialize their transactions.
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT singleton FROM api_v2_history_identity WHERE singleton=1 FOR UPDATE');
        $this->pdo->query('SELECT id FROM api_v2_applications WHERE id=3 FOR UPDATE');
        $children = [];
        foreach ([$this->secret(), 'pa_live_' . str_repeat('c',12) . '_' . str_repeat('d',64)] as $secret) {
            $code = 'require ' . var_export(dirname(__DIR__,2).'/src/migrations/migration_lib.php',true) . ';require '
                . var_export(dirname(__DIR__,2).'/src/services/ApiV2CapabilitiesKeyProvisioner.php',true)
                . ';try{$r=(new App\\Services\\ApiV2CapabilitiesKeyProvisioner(migration_connection()))->apply('
                . var_export($plan,true) . ',' . var_export($secret,true) . ');echo $r["state"];}catch(Throwable){echo "held";}';
            $process = proc_open([PHP_BINARY, '-r', $code], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
            self::assertIsResource($process); $children[] = [$process,$pipes];
        }
        usleep(100000);
        $this->pdo->rollBack();
        $results = [];
        foreach ($children as [$process,$pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            self::assertSame('', stream_get_contents($pipes[2]));
            fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($process));
        }
        sort($results); self::assertSame(['created','held'], $results);
        self::assertSame(2,$this->countKeys()); self::assertSame(['5','6'],$this->generation());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM system_audit')->fetchColumn());
    }

    public function testPreparedReceiptDefaultsToReconciliationAndRequiresExplicitResume(): void
    {
        $cli = dirname(__DIR__, 2) . '/bin/provision-api-v2-capabilities-key.php';
        $path = $this->privateDirectory . '/plan.json';
        $plan = ['version'=>1, 'provisioning'=>$this->service()->dryRun($this->input()),
            'secretFile'=>$this->privateDirectory.'/key.json'];
        LocalOperatorKeyReceipt::writeNew($path, $plan);
        // Simulates process death after durable key storage, before DB work.
        $vault = new LocalOperatorKeyReceipt($plan['secretFile']);
        $vault->lock(); $material = $vault->create($plan); unset($vault);
        $bytes = file_get_contents($plan['secretFile']);
        $apply = [PHP_BINARY,$cli,'--apply','--confirm-create-capabilities-key',
            '--plan-file='.$path,'--reviewed-plan-sha256='.hash_file('sha256',$path)];
        [$exit,$output,$error] = $this->command($apply);
        self::assertSame(3,$exit,$error);
        self::assertSame('not_committed',json_decode($output,true)['state']);
        self::assertSame(1,$this->countKeys()); self::assertSame(['4','5'],$this->generation());
        $apply[] = '--resume-prepared';
        [$exit,$output,$error] = $this->command($apply);
        self::assertSame(0,$exit,$error);
        self::assertSame('created',json_decode($output,true)['state']);
        self::assertStringNotContainsString($material['secret'],$output.$error);
        self::assertSame($bytes,file_get_contents($plan['secretFile']));
        self::assertSame(hash('sha256',$material['secret']),$this->pdo->query('SELECT key_hash FROM api_keys WHERE id<>7')->fetchColumn());
        self::assertSame(['5','6'],$this->generation());
    }

    public function testFailedDatabaseAttemptRetainsPrivateMaterialAndNoGrant(): void
    {
        $cli = dirname(__DIR__, 2) . '/bin/provision-api-v2-capabilities-key.php';
        $path = $this->privateDirectory . '/plan.json';
        $plan = ['version'=>1,'provisioning'=>$this->service()->dryRun($this->input()),
            'secretFile'=>$this->privateDirectory.'/key.json'];
        LocalOperatorKeyReceipt::writeNew($path,$plan);
        $this->pdo->exec('UPDATE api_v2_project_authorization_state SET authorization_generation=6 WHERE application_pk=3');
        $apply = [PHP_BINARY,$cli,'--apply','--confirm-create-capabilities-key',
            '--plan-file='.$path,'--reviewed-plan-sha256='.hash_file('sha256',$path)];
        [$exit,$output,$error] = $this->command($apply);
        self::assertSame(1,$exit); self::assertFileExists($plan['secretFile']);
        $bytes = file_get_contents($plan['secretFile']);
        $secret = json_decode($bytes,true)['secret'];
        self::assertStringNotContainsString($secret,$output.$error);
        self::assertSame(1,$this->countKeys()); self::assertSame(['4','6'],$this->generation());
        [$exit,$output,$error] = $this->command($apply);
        self::assertSame(3,$exit); self::assertSame('not_committed',json_decode($output,true)['state']);
        self::assertSame($bytes,file_get_contents($plan['secretFile']));
        self::assertSame(1,$this->countKeys());
    }
}
