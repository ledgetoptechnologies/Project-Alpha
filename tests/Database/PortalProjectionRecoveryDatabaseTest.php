<?php

declare(strict_types=1);

use App\Services\ExternalOpsConfigService;
use App\Services\PortalClientProvisioningService;
use App\Services\PortalProjectionOutboxSender;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';

/** Native prepares and locking execute only inside a new owned disposable schema.
 * CREATE LIKE retains the migrated MySQL columns/indexes, not foreign keys; this
 * test proves sender queries/transactions, not independent migration or FK health. */
final class PortalProjectionRecoveryDatabaseTest extends TestCase
{
    public function testNativeMySqlRecoveryClaimsAndExplicitLegacyReplanPreserveOriginalRows():void
    {
        if(getenv('PA_DISPOSABLE_SCHEMA_PROOF')!=='1'||getenv('PA_PORTAL_RECOVERY_MYSQL_ALLOW')!=='isolated-disposable-only'){
            self::markTestSkipped('Requires the explicit disposable MySQL recovery runner.');
        }
        $source=migration_connection();
        self::assertSame('mysql',$source->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(false,$source->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $sourceName=(string)$source->query('SELECT DATABASE()')->fetchColumn();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$sourceName))throw new RuntimeException('Unsafe disposable source schema name.');
        $schema='pa_portal_recovery_probe_'.bin2hex(random_bytes(8));
        $oldKey=getenv('APP_ENCRYPTION_KEY');$oldCaps=getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
        $source->exec('CREATE DATABASE `'.$schema.'`');$pdo=null;
        try{
            foreach($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM)as$row){
                $table=(string)$row[0];if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unsafe source table name.');
                $source->exec('CREATE TABLE `'.$schema.'`.`'.$table.'` LIKE `'.$sourceName.'`.`'.$table.'`');
            }
            $pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.$schema.';charset=utf8mb4',getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:getenv('MYSQL_ROOT_PASSWORD')?:'rootpass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            putenv('APP_ENCRYPTION_KEY=disposable-recovery-only');
            putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.json_encode(['generic_operations'=>['portal'=>['keyId'=>'recovery-test','current'=>str_repeat('r',32)]]]));
            $service=new PortalClientProvisioningService();
            $config=(new ExternalOpsConfigService())->save($pdo,['enabled'=>1,'application_key'=>'generic_operations','label'=>'Disposable recovery','webhook_url'=>'https://ops-qa.example.test/events','access_client_id'=>'synthetic-id','access_client_secret'=>'synthetic-secret','hmac_secret'=>str_repeat('h',32),'timeout_seconds'=>15,'max_attempts'=>12]);
            $service->configureConnection($pdo,$config,0);
            $pdo->prepare("INSERT INTO clients(public_id,name,email,client_type)VALUES(?,?,?,'consumer')")->execute([bin2hex(random_bytes(16)),'Synthetic Recovery','recovery@example.invalid']);
            self::assertSame(1,$service->reconcileHistoricalBatch($pdo,'generic_operations',1)['completed']);
            $first=(int)$pdo->query('SELECT MIN(id) FROM portal_projection_outbox')->fetchColumn();
            $cutoff=(int)$pdo->query('SELECT MAX(id) FROM portal_projection_outbox')->fetchColumn();
            self::assertGreaterThan($first,$cutoff);
            $pdo->exec("UPDATE portal_projection_outbox SET attempts=12,dead_lettered_at=CURRENT_TIMESTAMP,last_http_status=422,last_error_code='http_4xx' WHERE id={$first}");
            self::assertSame(1,$service->recoverFailedPortalWorkspaces($pdo,'generic_operations',0,1)['queued']);
            $old=$pdo->query('SELECT * FROM portal_projection_recoveries')->fetch(PDO::FETCH_ASSOC);
            self::assertSame($cutoff,(int)$old['failed_row_cutoff_id']);
            // Model a previously persisted legacy terminal-only fence. The
            // actual service generated every snapshot and activation payload.
            $pdo->exec("UPDATE portal_projection_recoveries SET failed_row_cutoff_id={$first}");
            $original=$pdo->query('SELECT * FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $originalLast=(int)end($original)['id'];
            self::assertSame(1,$service->recoverFailedPortalWorkspaces($pdo,'generic_operations',0,1)['queued']);
            $recoveries=$pdo->query('SELECT * FROM portal_projection_recoveries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2,$recoveries);self::assertSame('failed',$recoveries[0]['state']);self::assertSame('replaced_after_review',$recoveries[0]['last_error_code']);
            self::assertSame($old['source_generation'],$recoveries[0]['source_generation']);self::assertSame($old['activation_delivery_id'],$recoveries[0]['activation_delivery_id']);
            self::assertNotSame($old['source_generation'],$recoveries[1]['source_generation']);
            self::assertSame($originalLast,(int)$recoveries[1]['failed_row_cutoff_id']);
            $sent=[];$transport=static function(string$url,array$headers,string$body)use(&$sent):array{$p=json_decode($body,true,64,JSON_THROW_ON_ERROR)['projection'];$sent[]=$p;return['status'=>204];};
            $sender=new PortalProjectionOutboxSender();
            self::assertSame(1,$sender->deliverDue($pdo,1,$transport)['delivered']);
            self::assertSame('snapshot.page',$sent[0]['kind']);self::assertSame($recoveries[1]['source_generation'],$sent[0]['sourceGeneration']);
            self::assertSame('queued',$pdo->query('SELECT state FROM portal_projection_recoveries ORDER BY id DESC LIMIT 1')->fetchColumn());
            self::assertSame($original,$pdo->query('SELECT * FROM portal_projection_outbox WHERE id<='.$originalLast.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
            $sender->deliverDue($pdo,100,$transport);
            self::assertSame('snapshot.activate',end($sent)['kind']);self::assertSame('complete',$pdo->query('SELECT state FROM portal_projection_recoveries ORDER BY id DESC LIMIT 1')->fetchColumn());
            self::assertSame(0,$sender->deliverDue($pdo,100,$transport)['processed']);
            self::assertSame($original,$pdo->query('SELECT * FROM portal_projection_outbox WHERE id<='.$originalLast.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
            self::assertSame(0,$service->status($pdo,'generic_operations')['counts']['failed_portal']);
        }finally{
            if($pdo?->inTransaction())$pdo->rollBack();$pdo=null;
            $source->exec('DROP DATABASE `'.$schema.'`');
            $oldKey===false?putenv('APP_ENCRYPTION_KEY'):putenv('APP_ENCRYPTION_KEY='.$oldKey);
            $oldCaps===false?putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON'):putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.$oldCaps);
        }
    }
}
