<?php

declare(strict_types=1);

use App\Services\ExternalOpsConfigService;
use App\Services\PortalClientProvisioningService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__,2).'/src/utils/api_v2_binding_status.php';

/** Only run against a newly migrated, disposable database that is destroyed
 * after this class. This fixture never connects to running PA application data. */
final class PortalMissingClientProvisioningDatabaseTest extends TestCase
{
    public function testCompletedRootMissingPersonUsesCurrentMySqlReadsAndOwnBindingProof():void
    {
        if(getenv('PA_DISPOSABLE_SCHEMA_PROOF')!=='1'||getenv('PA_PENDING_CLIENT_MYSQL_ALLOW')!=='isolated-disposable-only'){
            self::markTestSkipped('Requires a fresh migrated disposable MySQL proof runner and explicit pending-client sentinel.');
        }
        $pdo=new PendingClientRacePDO('mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('MYSQL_DATABASE')?:'project_alpha').';charset=utf8mb4',getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:getenv('MYSQL_ROOT_PASSWORD')?:'rootpass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PendingClientRaceStatement::class,[$pdo]]);
        foreach(['clients','portal_integration_profiles','portal_principals']as$table){
            if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Refusing pending-client fixture on a nonempty database.');
        }
        $other=migration_connection();$service=new PortalClientProvisioningService();
        $previousKey=getenv('APP_ENCRYPTION_KEY');$previousCaps=getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
        putenv('APP_ENCRYPTION_KEY=pending-client-disposable-test-key');
        putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.json_encode(['generic_operations'=>['portal'=>['keyId'=>'test-key','current'=>str_repeat('p',32)]]]));
        try{
            $config=(new ExternalOpsConfigService())->save($pdo,['enabled'=>1,'application_key'=>'generic_operations','label'=>'Synthetic test','webhook_url'=>'https://ops-qa.example.test/events','access_client_id'=>'synthetic-access','access_client_secret'=>'synthetic-secret','hmac_secret'=>str_repeat('h',32),'timeout_seconds'=>15,'max_attempts'=>12]);
            $service->configureConnection($pdo,$config,0);
            $org=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO organizations(public_id,name)VALUES(?,?)')->execute([$org,'Synthetic completed company']);$orgId=(int)$pdo->lastInsertId();
            $insert=$pdo->prepare("INSERT INTO clients(public_id,name,email,organization_id,client_type)VALUES(?,?,?,?,'business')");
            $insert->execute([bin2hex(random_bytes(16)),'Existing Person','existing@example.invalid',$orgId]);$oldId=(int)$pdo->lastInsertId();
            self::assertSame(1,$service->reconcileHistoricalBatch($pdo,'generic_operations')['completed']);
            $old=$pdo->query('SELECT * FROM portal_client_login_eligibility WHERE client_id='.$oldId)->fetch(PDO::FETCH_ASSOC);
            $oldPrincipal=$pdo->query('SELECT * FROM portal_principals WHERE id='.(int)$old['portal_principal_id'])->fetch(PDO::FETCH_ASSOC);
            $root=$pdo->query('SELECT * FROM portal_client_provisioning_backfill')->fetchAll(PDO::FETCH_ASSOC);
            // Neutral directory-create's committed row is deliberately unprovisioned;
            // its no-authority contract is covered independently by the real create tests.
            $insert->execute([bin2hex(random_bytes(16)),'New Person','new@example.invalid',$orgId]);$newId=(int)$pdo->lastInsertId();
            self::assertSame(1,$service->reconcileHistoricalBatch($pdo,'generic_operations')['completed']);
            $client=$pdo->query('SELECT * FROM clients WHERE id='.$newId)->fetch(PDO::FETCH_ASSOC);$proof=api_v2_binding_status_client_portal_identity($pdo,$client);
            self::assertSame('eligible',$proof['state']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$proof['associationVersion']);
            $published=$pdo->prepare('SELECT COUNT(*) FROM portal_projection_outbox WHERE instr(payload_json,?)>0');$published->execute([$proof['principalPublicId']]);self::assertGreaterThan(0,(int)$published->fetchColumn());
            self::assertSame($old,$pdo->query('SELECT * FROM portal_client_login_eligibility WHERE client_id='.$oldId)->fetch(PDO::FETCH_ASSOC));
            self::assertSame($oldPrincipal,$pdo->query('SELECT * FROM portal_principals WHERE id='.(int)$old['portal_principal_id'])->fetch(PDO::FETCH_ASSOC));
            self::assertSame($root,$pdo->query('SELECT * FROM portal_client_provisioning_backfill')->fetchAll(PDO::FETCH_ASSOC));
            self::assertSame(0,(new PortalClientProvisioningService())->reconcileHistoricalBatch($other,'generic_operations')['completed']);
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_identity_bindings')->fetchColumn());

            $other->prepare('INSERT INTO organizations(public_id,name)VALUES(?,?)')->execute([bin2hex(random_bytes(16)),'Other synthetic company']);$otherOrg=(int)$other->lastInsertId();
            $insert->execute([bin2hex(random_bytes(16)),'Racing Person','racing@example.invalid',$orgId]);$raceId=(int)$pdo->lastInsertId();$fired=false;
            $pdo->afterLockedClientRead=function()use($other,$otherOrg,$raceId,&$fired):void{
                $fired=true;$other->prepare("INSERT INTO clients(public_id,name,email,organization_id,client_type)VALUES(?,?,?,?,'business')")->execute([bin2hex(random_bytes(16)),'Duplicate email','racing@example.invalid',$otherOrg]);
            };
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $service->reconcileHistoricalBatch($pdo,'generic_operations');self::assertTrue($fired,'Duplicate must commit after the target locking read and before email uniqueness.');
            self::assertSame('review_required',$pdo->query('SELECT eligibility_status FROM portal_client_login_eligibility WHERE client_id='.$raceId)->fetchColumn());
            self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
            self::assertSame($old,$pdo->query('SELECT * FROM portal_client_login_eligibility WHERE client_id='.$oldId)->fetch(PDO::FETCH_ASSOC));
        }finally{
            if($pdo->inTransaction())$pdo->rollBack();if($other->inTransaction())$other->rollBack();
            $previousKey===false?putenv('APP_ENCRYPTION_KEY'):putenv('APP_ENCRYPTION_KEY='.$previousKey);
            $previousCaps===false?putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON'):putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.$previousCaps);
        }
    }
}

final class PendingClientRacePDO extends PDO
{
    public ?Closure $afterLockedClientRead=null;
}
final class PendingClientRaceStatement extends PDOStatement
{
    protected function __construct(private PendingClientRacePDO $connection){}
    public function execute(?array $params=null):bool
    {
        $result=parent::execute($params);
        if($this->queryString==='SELECT * FROM clients WHERE id=? FOR UPDATE'&&$this->connection->afterLockedClientRead){
            $callback=$this->connection->afterLockedClientRead;$this->connection->afterLockedClientRead=null;$callback();
        }
        return$result;
    }
}
