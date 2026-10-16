<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryClientContactProfileCommandTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_client_profile_command.php';
    }

    private function headers(): array { return ['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000']; }
    private function command(): array { return ['commandId'=>'423e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0','profile'=>['name'=>'Updated Client','email'=>'CLIENT@EXAMPLE.TEST ','phone'=>' 555 ','addressLine1'=>'1 Main','addressLine2'=>'','city'=>'Austin','state'=>'TX','postalCode'=>'78701','country'=>'US']]; }

    private function database(): PDO
    {
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT); CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT); CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT); CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER); CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id)); CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision)); CREATE TABLE api_v2_directory_client_profile_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,PRIMARY KEY(application_pk,history_epoch,command_id)); CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT); CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,notes TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,stripe_customer_id TEXT,stripe_payment_method_id TEXT,auto_pay_enabled INTEGER,source_version TEXT); CREATE TABLE projects(id INTEGER PRIMARY KEY,client_id INTEGER); CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER); CREATE TABLE addresses(id INTEGER PRIMARY KEY,label TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,google_place_id TEXT,source TEXT,created_by INTEGER,archived INTEGER DEFAULT 0); CREATE TABLE address_assignments(id INTEGER PRIMARY KEY,address_id INTEGER,entity_type TEXT,entity_id INTEGER,purpose TEXT,is_default INTEGER,UNIQUE(entity_type,entity_id,purpose,address_id)); CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER,PRIMARY KEY(portal_principal_id,client_id)); CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,enabled INTEGER,service_assignment_projection_enabled INTEGER,delivery_enabled INTEGER); CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER); CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,root_type TEXT,root_public_id TEXT,active INTEGER); CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT); INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO organizations VALUES(10,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'); INSERT INTO clients VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example','old@example.test','old','consumer',10,'private note','','','','','','','customer-private','payment-private',1,'v-old'); INSERT INTO addresses VALUES(4,'Private label','old','','','','','','place-hidden','google',NULL,0); INSERT INTO address_assignments VALUES(1,4,'client',9,'billing',1); INSERT INTO portal_principal_clients VALUES(77,9); INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled','0')");
        $pdo->beginTransaction(); \api_v2_directory_record($pdo, 'client', 9); $pdo->commit(); $this->identitySchema($pdo); return $pdo;
    }

    private function identitySchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
ALTER TABLE clients ADD COLUMN archived INTEGER DEFAULT 0;
ALTER TABLE clients ADD COLUMN deleted_at TEXT;
ALTER TABLE portal_principal_clients ADD COLUMN created_by INTEGER;
CREATE TABLE portal_principals(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT(lower(hex(randomblob(16)))),email_hint TEXT,display_name TEXT,source_version TEXT,enabled INTEGER,authorization_version INTEGER DEFAULT 1,activated_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_identity_bindings(id INTEGER PRIMARY KEY,portal_principal_id INTEGER,issuer TEXT,subject_hash TEXT,enabled INTEGER,revoked_at TEXT,updated_by INTEGER);
CREATE TABLE portal_v2_entitlements(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,portal_principal_id INTEGER,capability TEXT,effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,valid_from TEXT,expires_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_client_login_eligibility(client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,manual_state TEXT,eligibility_status TEXT,review_reason TEXT,canonical_email TEXT,source_version TEXT,last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_client_contact_identity_policy(client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,login_email TEXT,application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id TEXT,public_id TEXT,status TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT);
INSERT INTO api_v2_directory_external_bindings SELECT 3,resource_type,'ops/existing/client',public_id,'active',revision,projection_sha256 FROM api_v2_directory_resource_state;
INSERT INTO portal_principals(id,email_hint,display_name,source_version,enabled,authorization_version) VALUES(77,'old@example.test','Existing Person','principal-v1',1,4);
INSERT INTO portal_identity_bindings VALUES(88,77,'https://issuer.example.test','existing-subject',1,NULL,NULL);
INSERT INTO portal_v2_entitlements(id,public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active) VALUES(99,'entitlement-existing',77,'directory.read','allow','client','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','entitlement-v1',1);
INSERT INTO portal_client_login_eligibility VALUES(9,77,'automatic','eligible','none','old@example.test','eligibility-v1','2026-01-01',NULL,NULL);
SQL);
    }

    private function footprint(PDO $pdo): array
    {
        $result=[];
        foreach(['portal_principals','portal_identity_bindings','portal_v2_entitlements','portal_principal_clients','portal_client_login_eligibility','portal_v2_workspaces','portal_integration_profile_workspaces'] as $table)
            $result[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        return$result;
    }

    private function write(PDO $pdo,?array $command=null,bool $neutral=true):array
    {
        $command=\api_v2_directory_client_profile_command_parse(json_encode($command??$this->command(),JSON_THROW_ON_ERROR));
        return \api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),$command,7,$this->headers(),'request-neutral',$neutral);
    }

    private function reconcile(PDO $pdo,bool $rootActive=true):string
    {
        $method=new \ReflectionMethod(\App\Services\PortalClientProvisioningService::class,'reconcileClient');
        $client=$pdo->query('SELECT * FROM clients WHERE id=9')->fetch(PDO::FETCH_ASSOC);
        $pdo->beginTransaction();
        try{$status=$method->invoke(new \App\Services\PortalClientProvisioningService(),$pdo,$client,['root_type'=>'organization','root_public_id'=>str_repeat('b',32)],$rootActive,0);$pdo->commit();return$status;}
        catch(\Throwable $e){$pdo->rollBack();throw$e;}
    }

    public function testContactEmailAndLaterCronPreserveExactExistingPortalAuthority():void
    {
        $pdo=$this->database();$before=$this->footprint($pdo);
        self::assertSame(200,$this->write($pdo)['status']);
        self::assertSame('client@example.test',$pdo->query('SELECT email FROM clients WHERE id=9')->fetchColumn());
        self::assertSame($before,$this->footprint($pdo));
        self::assertSame('old@example.test',$pdo->query('SELECT login_email FROM portal_client_contact_identity_policy')->fetchColumn());
        for($n=0;$n<3;$n++)self::assertSame('eligible',$this->reconcile($pdo));
        self::assertSame($before,$this->footprint($pdo),'Cron cannot replace identity, activate grants, or rotate access from a contact email');
        self::assertTrue($this->write($pdo)['payload']['replayed']);
        self::assertSame(409,$this->write($pdo,null,false)['status'],'Legacy and neutral commands cannot cross-replay');
    }

    public function testNewContactEmailDoesNotMakeAnotherClientsLoginDuplicate():void
    {
        $pdo=$this->database();$before=$this->footprint($pdo);$command=$this->command();$command['profile']['email']='other@example.test';
        self::assertSame(200,$this->write($pdo,$command)['status']);
        $pdo->exec("INSERT INTO clients(id,public_id,name,email,client_type,organization_id) VALUES(10,'cccccccccccccccccccccccccccccccc','Other','other@example.test','business',10)");
        $method=new \ReflectionMethod(\App\Services\PortalClientProvisioningService::class,'emailCount');
        $service=new \App\Services\PortalClientProvisioningService();
        self::assertSame(1,$method->invoke($service,$pdo,'other@example.test'));
        self::assertSame(1,$method->invoke($service,$pdo,'old@example.test'));
        self::assertSame('eligible',$this->reconcile($pdo));
        self::assertSame($before,$this->footprint($pdo));
    }

    public function testRevokedOrUnprovisionedIdentityIsNeverRecreatedByCron():void
    {
        $pdo=$this->database();self::assertSame(200,$this->write($pdo)['status']);
        self::assertSame('revoked',$this->reconcile($pdo,false));$revoked=$this->footprint($pdo);
        self::assertSame('revoked',$this->reconcile($pdo,true));self::assertSame($revoked,$this->footprint($pdo));
        self::assertSame(0,(int)$pdo->query('SELECT enabled FROM portal_identity_bindings WHERE id=88')->fetchColumn());
        $none=$this->database();$none->exec('DELETE FROM portal_client_login_eligibility; DELETE FROM portal_principal_clients; DELETE FROM portal_identity_bindings; DELETE FROM portal_v2_entitlements; DELETE FROM portal_principals');
        self::assertSame(200,$this->write($none)['status']);$before=$this->footprint($none);
        self::assertSame('review_required',$this->reconcile($none));self::assertSame($before,$this->footprint($none));
        self::assertNull($none->query('SELECT portal_principal_id FROM portal_client_contact_identity_policy')->fetchColumn());
    }

    public function testMissingBindingAndStaleRevisionNeverOptInOrWrite():void
    {
        $pdo=$this->database();$before=$this->footprint($pdo);$pdo->exec("UPDATE api_v2_directory_external_bindings SET status='tombstoned'");
        self::assertSame(409,$this->write($pdo)['status']);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_client_contact_identity_policy')->fetchColumn());
        self::assertSame('old@example.test',$pdo->query('SELECT email FROM clients WHERE id=9')->fetchColumn());self::assertSame($before,$this->footprint($pdo));
        $pdo->exec("UPDATE api_v2_directory_external_bindings SET status='active'");$command=$this->command();$command['expectedRevision']='9';
        self::assertSame(409,$this->write($pdo,$command)['status']);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_client_contact_identity_policy')->fetchColumn());
    }

    public function testReceiptFailureRollsBackContactProfilePolicyAndAuthority():void
    {
        $pdo=$this->database();$before=$this->footprint($pdo);$pdo->exec("CREATE TRIGGER stop_receipt BEFORE INSERT ON api_v2_directory_client_profile_command_receipts BEGIN SELECT RAISE(ABORT,'blocked'); END");
        self::assertSame(409,$this->write($pdo)['status']);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_client_contact_identity_policy')->fetchColumn());
        self::assertSame('old@example.test',$pdo->query('SELECT email FROM clients WHERE id=9')->fetchColumn());
        self::assertSame('1',(string)$pdo->query("SELECT revision FROM api_v2_directory_resource_state WHERE resource_type='client'")->fetchColumn());
        self::assertSame($before,$this->footprint($pdo));
    }

    public function testLegacyReceiptCannotOptInAndChangedNeutralPayloadCannotReplay():void
    {
        $pdo=$this->database();self::assertSame(200,$this->write($pdo,null,false)['status']);
        self::assertSame(409,$this->write($pdo)['status']);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_client_contact_identity_policy')->fetchColumn());
        $fresh=$this->database();self::assertSame(200,$this->write($fresh)['status']);$changed=$this->command();$changed['profile']['phone']='different';
        self::assertSame(409,$this->write($fresh,$changed)['status']);
    }

    public function testManualBlockAndInactiveClientStayBlockedAfterLaterCron():void
    {
        foreach(['manual','inactive']as$mode){$pdo=$this->database();self::assertSame(200,$this->write($pdo)['status']);
            if($mode==='manual')$pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='revoked'");
            else$pdo->exec('UPDATE clients SET archived=1 WHERE id=9');
            self::assertSame($mode==='manual'?'revoked':'review_required',$this->reconcile($pdo));$before=$this->footprint($pdo);
            $pdo->exec('UPDATE clients SET archived=0 WHERE id=9');$this->reconcile($pdo);
            self::assertSame($before,$this->footprint($pdo));self::assertSame(0,(int)$pdo->query('SELECT enabled FROM portal_principals WHERE id=77')->fetchColumn());
        }
    }

    public function testNeutralCapabilityIsExplicitAndUsesExistingWriteScope():void
    {
        $identity=['source_instance_id'=>$this->headers()['source'],'application_id'=>$this->headers()['application'],'history_epoch'=>$this->headers()['epoch']];
        $path='/api/v2/directory/clients/{publicId}/contact-profile/commands';
        $off=\api_v2_capabilities_payload($identity,'request',['directory.clients.write'],['directory_client_write'=>true]);
        self::assertNotContains($path,array_column($off['implementedEndpoints'],'path'));
        $on=\api_v2_capabilities_payload($identity,'request',['directory.clients.write'],['directory_client_contact_write'=>true]);
        $endpoint=array_values(array_filter($on['implementedEndpoints'],static fn(array $e):bool=>$e['path']===$path))[0];
        self::assertSame('directory.clients.write',$endpoint['requiredCapability']);self::assertTrue($endpoint['requiresExpectedRevision']);
        self::assertTrue($endpoint['requiresSourceInstanceId']);self::assertTrue($endpoint['requiresApplicationId']);self::assertTrue($endpoint['requiresHistoryEpoch']);
    }
}
