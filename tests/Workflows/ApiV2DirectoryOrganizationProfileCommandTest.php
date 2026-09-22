<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryOrganizationProfileCommandTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_organization_profile_command.php';
    }
    private function headers(): array { return ['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000']; }
    private function command(): array { return ['commandId'=>'423e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0','profile'=>['name'=>'Updated Org','generalEmail'=>'OPS@EXAMPLE.TEST ','generalPhone'=>' 555 ','addressLine1'=>'1 Main','addressLine2'=>'','city'=>'Austin','state'=>'TX','postalCode'=>'78701','country'=>'US']]; }
    private function database(): PDO
    {
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php'; require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php'; require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_organization_profile_command.php';
        $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT); CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT); CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT); CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER); CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id)); CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision)); CREATE TABLE api_v2_directory_organization_profile_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,PRIMARY KEY(application_pk,history_epoch,command_id)); CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,general_email TEXT,general_phone TEXT,notes TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,source_version TEXT); CREATE TABLE addresses(id INTEGER PRIMARY KEY,label TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,google_place_id TEXT,source TEXT,created_by INTEGER,archived INTEGER DEFAULT 0); CREATE TABLE address_assignments(id INTEGER PRIMARY KEY,address_id INTEGER,entity_type TEXT,entity_id INTEGER,purpose TEXT,is_default INTEGER,UNIQUE(entity_type,entity_id,purpose,address_id)); CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT); INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO organizations VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example','old@example.test','old','private note','','','','','','','v-old'); INSERT INTO addresses VALUES(4,'Private label','old','','','','','','place-hidden','google',NULL,0); INSERT INTO address_assignments VALUES(1,4,'organization',9,'billing',1);");
        $pdo->beginTransaction(); \api_v2_directory_record($pdo, 'organization', 9); $pdo->commit(); return $pdo;
    }
    public function testParseIsOrderIndependentCanonicalAndRejectsPrivateFields(): void
    {
        $command = $this->command(); $reversed = ['profile'=>$command['profile'], 'expectedAuthorizationGeneration'=>'0', 'expectedRevision'=>'1', 'commandId'=>$command['commandId']];
        self::assertSame('ops@example.test', \api_v2_directory_organization_profile_command_parse(json_encode($reversed, JSON_THROW_ON_ERROR))['profile']['generalEmail']);
        self::assertNull(\api_v2_directory_organization_profile_command_parse(json_encode(array_replace_recursive($command, ['profile'=>['notes'=>'no']]), JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_organization_profile_command_parse(json_encode(array_replace_recursive($command, ['profile'=>['googlePlaceId'=>'no']]), JSON_THROW_ON_ERROR)));
    }
    public function testParseAcceptsSchemaMaximumNameAndRejectsOverlongNameBeforeMutation(): void
    {
        $maximum = $this->command(); $maximum['profile']['name'] = str_repeat('A', 150);
        self::assertSame(str_repeat('A', 150), \api_v2_directory_organization_profile_command_parse(json_encode($maximum, JSON_THROW_ON_ERROR))['profile']['name']);
        $overlong = $this->command(); $overlong['profile']['name'] = str_repeat('A', 151);
        self::assertNull(\api_v2_directory_organization_profile_command_parse(json_encode($overlong, JSON_THROW_ON_ERROR)));
    }
    public function testWriteReplaysExactlyAndPreservesPrivateMetadata(): void
    {
        $pdo=$this->database(); $command=\api_v2_directory_organization_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR)); $first=\api_v2_directory_organization_profile_command_write($pdo, str_repeat('a',32), $command, 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200,$first['status']); self::assertFalse($first['payload']['replayed']); self::assertSame('2',$first['payload']['result']['resource']['revision']);
        self::assertSame('private note',$pdo->query('SELECT notes FROM organizations')->fetchColumn()); self::assertSame('place-hidden',$pdo->query('SELECT google_place_id FROM addresses ORDER BY id DESC LIMIT 1')->fetchColumn()); self::assertSame('Private label',$pdo->query('SELECT label FROM addresses ORDER BY id DESC LIMIT 1')->fetchColumn());
        $reordered = ['profile'=>$this->command()['profile'], 'expectedAuthorizationGeneration'=>'0', 'expectedRevision'=>'1', 'commandId'=>$this->command()['commandId']]; $again=\api_v2_directory_organization_profile_command_write($pdo, str_repeat('a',32), \api_v2_directory_organization_profile_command_parse(json_encode($reordered, JSON_THROW_ON_ERROR)), 7, $this->headers(), '623e4567-e89b-42d3-a456-426614174000'); self::assertSame(200,$again['status']); self::assertTrue($again['payload']['replayed']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_organization_profile_command_receipts')->fetchColumn());
    }
    public function testStaleRevisionOrWrongEpochDoesNotWrite(): void
    {
        $pdo=$this->database(); $command=\api_v2_directory_organization_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR)); self::assertSame(409,\api_v2_directory_organization_profile_command_write($pdo,str_repeat('a',32),array_replace($command,['expectedRevision'=>'2']),7,$this->headers(),'523e4567-e89b-42d3-a456-426614174000')['status']); self::assertSame(409,\api_v2_directory_organization_profile_command_write($pdo,str_repeat('a',32),array_replace($command,['expectedAuthorizationGeneration'=>'1']),7,$this->headers(),'523e4567-e89b-42d3-a456-426614174000')['status']); self::assertSame(409,\api_v2_directory_organization_profile_command_write($pdo,str_repeat('a',32),$command,7,array_replace($this->headers(),['epoch'=>'wrong']),'523e4567-e89b-42d3-a456-426614174000')['status']); self::assertSame('Example',$pdo->query('SELECT name FROM organizations')->fetchColumn()); self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_organization_profile_command_receipts')->fetchColumn());
    }
    public function testReceiptFailureRollsBackTheProfileAndAddressMutation(): void
    {
        $pdo = $this->database(); $command = \api_v2_directory_organization_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR));
        $pdo->exec("CREATE TRIGGER stop_receipt BEFORE INSERT ON api_v2_directory_organization_profile_command_receipts BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        self::assertSame(409, \api_v2_directory_organization_profile_command_write($pdo, str_repeat('a', 32), $command, 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000')['status']);
        self::assertSame('Example', $pdo->query('SELECT name FROM organizations')->fetchColumn()); self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn()); self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_organization_profile_command_receipts')->fetchColumn());
    }
    public function testExactReplayRecoversTheOriginalResultAfterLaterChanges(): void
    {
        $pdo = $this->database(); $command = \api_v2_directory_organization_profile_command_parse(json_encode($this->command(), JSON_THROW_ON_ERROR));
        $first = \api_v2_directory_organization_profile_command_write($pdo, str_repeat('a', 32), $command, 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
        self::assertSame('2', $first['payload']['result']['resource']['revision']);
        $pdo->beginTransaction(); $pdo->exec("UPDATE organizations SET name='Later change'"); \api_v2_directory_record($pdo, 'organization', 9); $pdo->exec('UPDATE api_v2_directory_authorization_state SET authorization_generation=1'); $pdo->commit();
        $replay = \api_v2_directory_organization_profile_command_write($pdo, str_repeat('a', 32), $command, 7, $this->headers(), '623e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $replay['status']); self::assertTrue($replay['payload']['replayed']); self::assertSame('2', $replay['payload']['result']['resource']['revision']); self::assertSame('0', $replay['payload']['result']['authorizationGeneration']); self::assertSame('Later change', $pdo->query('SELECT name FROM organizations')->fetchColumn());
    }
    public function testReceiptCannotReplayAcrossHistoryEpochAndCommandIdCanBeReused(): void
    {
        $pdo=$this->database();$first=\api_v2_directory_organization_profile_command_parse(json_encode($this->command(),JSON_THROW_ON_ERROR));
        self::assertSame(200,\api_v2_directory_organization_profile_command_write($pdo,str_repeat('a',32),$first,7,$this->headers(),'first')['status']);
        $nextEpoch='923e4567-e89b-42d3-a456-426614174000';$pdo->prepare('UPDATE api_v2_history_identity SET history_epoch=?')->execute([$nextEpoch]);$headers=array_replace($this->headers(),['epoch'=>$nextEpoch]);
        self::assertSame(409,\api_v2_directory_organization_profile_command_write($pdo,str_repeat('a',32),$first,7,$headers,'stale-old-epoch')['status']);
        $next=$this->command();$next['expectedRevision']='2';$next['profile']['name']='Second Epoch Org';$next=\api_v2_directory_organization_profile_command_parse(json_encode($next,JSON_THROW_ON_ERROR));
        $result=\api_v2_directory_organization_profile_command_write($pdo,str_repeat('a',32),$next,7,$headers,'second');self::assertSame(200,$result['status']);self::assertFalse($result['payload']['replayed']);self::assertSame('3',$result['payload']['result']['resource']['revision']);
        self::assertSame(2,(int)$pdo->query('SELECT COUNT(DISTINCT history_epoch) FROM api_v2_directory_organization_profile_command_receipts')->fetchColumn());
    }
    public function testNoOpCommandRecordsSuccessWithoutRevisionOrAddressChurn(): void
    {
        $pdo = $this->database(); $command = $this->command();
        $command['profile'] = ['name'=>'Example','generalEmail'=>'old@example.test','generalPhone'=>'old','addressLine1'=>'','addressLine2'=>'','city'=>'','state'=>'','postalCode'=>'','country'=>''];
        $parsed = \api_v2_directory_organization_profile_command_parse(json_encode($command, JSON_THROW_ON_ERROR));
        $result = \api_v2_directory_organization_profile_command_write($pdo, str_repeat('a', 32), $parsed, 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $result['status']); self::assertSame('1', $result['payload']['result']['resource']['revision']); self::assertFalse($result['payload']['replayed']);
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn()); self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn()); self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_organization_profile_command_receipts')->fetchColumn());
    }
    public function testRouteIsDormantAndDedicated(): void
    {
        $root = dirname(__DIR__, 2); $route = (string)file_get_contents($root . '/public/index.php'); $controller = (string)file_get_contents($root . '/src/controllers/api/directory_organization_profile_command_v2.php'); $migration = (string)file_get_contents($root . '/database/migrations/0093_api_v2_directory_organization_profile_command_receipts.sql');
        self::assertStringContainsString('APP_API_V2_DIRECTORY_ORGANIZATIONS_WRITE_ENABLED', $route); self::assertStringContainsString("api_require_key(['api.capabilities.read', 'directory.organizations.write'], false)", $controller); self::assertStringContainsString("in_array('full'", $controller); self::assertStringContainsString('expected_authorization_generation', $migration); self::assertStringContainsString('api_v2_directory_organization_profile_command_receipts', $migration);
    }
}
