<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryClientProfileCommandTest extends TestCase
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
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT); CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT); CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT); CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER); CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id)); CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision)); CREATE TABLE api_v2_directory_client_profile_command_receipts(application_pk INTEGER,command_id TEXT,request_sha256 TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,PRIMARY KEY(application_pk,command_id)); CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT); CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,notes TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,stripe_customer_id TEXT,stripe_payment_method_id TEXT,auto_pay_enabled INTEGER,source_version TEXT); CREATE TABLE projects(id INTEGER PRIMARY KEY,client_id INTEGER); CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER); CREATE TABLE addresses(id INTEGER PRIMARY KEY,label TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,google_place_id TEXT,source TEXT,created_by INTEGER,archived INTEGER DEFAULT 0); CREATE TABLE address_assignments(id INTEGER PRIMARY KEY,address_id INTEGER,entity_type TEXT,entity_id INTEGER,purpose TEXT,is_default INTEGER,UNIQUE(entity_type,entity_id,purpose,address_id)); CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER,PRIMARY KEY(portal_principal_id,client_id)); CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,enabled INTEGER,service_assignment_projection_enabled INTEGER,delivery_enabled INTEGER); CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER); CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,root_type TEXT,root_public_id TEXT,active INTEGER); CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT); INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO organizations VALUES(10,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'); INSERT INTO clients VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example','old@example.test','old','consumer',10,'private note','','','','','','','customer-private','payment-private',1,'v-old'); INSERT INTO addresses VALUES(4,'Private label','old','','','','','','place-hidden','google',NULL,0); INSERT INTO address_assignments VALUES(1,4,'client',9,'billing',1); INSERT INTO portal_principal_clients VALUES(77,9); INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled','0')");
        $pdo->beginTransaction(); \api_v2_directory_record($pdo, 'client', 9); $pdo->commit(); return $pdo;
    }

    public function testParseRejectsNonProfileFieldsAndValidatesDatabaseLimits(): void
    {
        $command = $this->command(); $reversed = ['profile'=>$command['profile'], 'expectedAuthorizationGeneration'=>'0', 'expectedRevision'=>'1', 'commandId'=>$command['commandId']];
        self::assertSame('client@example.test', \api_v2_directory_client_profile_command_parse(json_encode($reversed, JSON_THROW_ON_ERROR))['profile']['email']);
        self::assertNull(\api_v2_directory_client_profile_command_parse(json_encode(array_replace_recursive($command, ['profile'=>['notes'=>'no']]), JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_client_profile_command_parse(json_encode(array_replace_recursive($command, ['profile'=>['organizationId'=>'10']]), JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_client_profile_command_parse(json_encode(array_replace_recursive($command, ['profile'=>['state'=>'TEX']]), JSON_THROW_ON_ERROR)));
        $maximum = $command; $maximum['profile']['postalCode'] = str_repeat('9', 20);
        self::assertSame(str_repeat('9', 20), \api_v2_directory_client_profile_command_parse(json_encode($maximum, JSON_THROW_ON_ERROR))['profile']['postalCode']);
    }

    public function testWriteReplaysExactlyAndPreservesPrivateAndRelationshipFields(): void
    {
        $pdo=$this->database(); $command=\api_v2_directory_client_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR)); $first=\api_v2_directory_client_profile_command_write($pdo, str_repeat('a',32), $command, 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200,$first['status']); self::assertFalse($first['payload']['replayed']); self::assertSame('2',$first['payload']['result']['resource']['revision']);
        $private = $pdo->query('SELECT organization_id,notes,stripe_customer_id,stripe_payment_method_id,auto_pay_enabled FROM clients')->fetch(PDO::FETCH_NUM);
        self::assertSame([10,'private note','customer-private','payment-private',1], $private); self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM portal_principal_clients')->fetchColumn()); self::assertSame('place-hidden',$pdo->query('SELECT google_place_id FROM addresses')->fetchColumn()); self::assertSame('Private label',$pdo->query('SELECT label FROM addresses')->fetchColumn());
        $reordered = ['profile'=>$this->command()['profile'], 'expectedAuthorizationGeneration'=>'0', 'expectedRevision'=>'1', 'commandId'=>$this->command()['commandId']]; $again=\api_v2_directory_client_profile_command_write($pdo, str_repeat('a',32), \api_v2_directory_client_profile_command_parse(json_encode($reordered, JSON_THROW_ON_ERROR)), 7, $this->headers(), '623e4567-e89b-42d3-a456-426614174000'); self::assertSame(200,$again['status']); self::assertTrue($again['payload']['replayed']); self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_client_profile_command_receipts')->fetchColumn());
    }

    public function testStaleAndAllIdentityMismatchesDoNotWrite(): void
    {
        $pdo=$this->database(); $command=\api_v2_directory_client_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR));
        self::assertSame(409,\api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),array_replace($command,['expectedRevision'=>'2']),7,$this->headers(),'523e4567-e89b-42d3-a456-426614174000')['status']); self::assertSame(409,\api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),array_replace($command,['expectedAuthorizationGeneration'=>'1']),7,$this->headers(),'523e4567-e89b-42d3-a456-426614174000')['status']);
        foreach (['source','application','epoch'] as $header) self::assertSame(409, \api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),$command,7,array_replace($this->headers(),[$header=>'wrong']),'523e4567-e89b-42d3-a456-426614174000')['status']);
        self::assertSame('Example',$pdo->query('SELECT name FROM clients')->fetchColumn()); self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_client_profile_command_receipts')->fetchColumn());
    }

    public function testReceiptFailureRollsBackTheProfileAddressAndRevision(): void
    {
        $pdo=$this->database(); $command=\api_v2_directory_client_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR)); $pdo->exec("CREATE TRIGGER stop_receipt BEFORE INSERT ON api_v2_directory_client_profile_command_receipts BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        self::assertSame(409,\api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),$command,7,$this->headers(),'523e4567-e89b-42d3-a456-426614174000')['status']); self::assertSame('Example',$pdo->query('SELECT name FROM clients')->fetchColumn()); self::assertSame('old',$pdo->query('SELECT address_line1 FROM addresses')->fetchColumn()); self::assertSame('1',(string)$pdo->query("SELECT revision FROM api_v2_directory_resource_state WHERE resource_type='client'")->fetchColumn());
    }

    public function testNoOpAndReplayAfterLaterChangeHaveImmutableResults(): void
    {
        $pdo=$this->database(); $command=$this->command(); $command['profile']=['name'=>'Example','email'=>'old@example.test','phone'=>'old','addressLine1'=>'','addressLine2'=>'','city'=>'','state'=>'','postalCode'=>'','country'=>'']; $parsed=\api_v2_directory_client_profile_command_parse(json_encode($command, JSON_THROW_ON_ERROR)); $result=\api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),$parsed,7,$this->headers(),'523e4567-e89b-42d3-a456-426614174000'); self::assertSame('1',$result['payload']['result']['resource']['revision']);
        $pdo=$this->database(); $command=\api_v2_directory_client_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR)); \api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),$command,7,$this->headers(),'523e4567-e89b-42d3-a456-426614174000'); $pdo->beginTransaction(); $pdo->exec("UPDATE clients SET name='Later change'"); \api_v2_directory_record($pdo,'client',9); $pdo->exec('UPDATE api_v2_directory_authorization_state SET authorization_generation=1'); $pdo->commit(); $replay=\api_v2_directory_client_profile_command_write($pdo,str_repeat('a',32),$command,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174000'); self::assertTrue($replay['payload']['replayed']); self::assertSame('2',$replay['payload']['result']['resource']['revision']); self::assertSame('Later change',$pdo->query('SELECT name FROM clients')->fetchColumn());
    }

    public function testRouteIsDormantAndDedicated(): void
    {
        $root=dirname(__DIR__,2); $route=(string)file_get_contents($root.'/public/index.php'); $controller=(string)file_get_contents($root.'/src/controllers/api/directory_client_profile_command_v2.php'); $migration=(string)file_get_contents($root.'/database/migrations/0094_api_v2_directory_client_profile_command_receipts.sql');
        self::assertStringContainsString('APP_API_V2_DIRECTORY_CLIENTS_WRITE_ENABLED',$route); self::assertStringContainsString("api_require_key(['api.capabilities.read', 'directory.clients.write'], false)",$controller); self::assertStringContainsString("in_array('full'",$controller); self::assertStringContainsString('expected_authorization_generation',$migration); self::assertStringContainsString('api_v2_directory_client_profile_command_receipts',$migration);
    }
}
