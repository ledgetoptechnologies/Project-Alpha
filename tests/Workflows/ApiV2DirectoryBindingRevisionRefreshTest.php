<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryBindingRevisionRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_scopes.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
    }

    private function database(): PDO
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_command.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_revision_refresh.php';
        $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT); CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT); CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT); CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER); CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id)); CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision)); CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE(application_pk,resource_type,public_id)); CREATE TABLE api_v2_directory_binding_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id)); CREATE TABLE api_v2_directory_binding_revision_refresh_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,expected_prior_revision INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,expected_authorization_generation INTEGER,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id)); CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT); INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO clients(id,public_id,name) VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example');");
        $pdo->beginTransaction(); \api_v2_directory_record($pdo, 'client', 9); $pdo->commit();
        $bind = ['commandId' => '423e4567-e89b-42d3-a456-426614174000', 'externalId' => 'same/external', 'expectedPublicId' => str_repeat('a', 32), 'expectedRevision' => '1'];
        \api_v2_directory_binding_command_write($pdo, 'client', $bind, 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
        $pdo->exec("UPDATE clients SET name='Revised' WHERE id=9"); $pdo->beginTransaction(); \api_v2_directory_record($pdo, 'client', 9); $pdo->commit();
        return $pdo;
    }

    private function headers(): array { return ['source' => '123e4567-e89b-42d3-a456-426614174000', 'application' => '223e4567-e89b-42d3-a456-426614174000', 'epoch' => '323e4567-e89b-42d3-a456-426614174000']; }
    private function command(): array { return ['commandId' => '623e4567-e89b-42d3-a456-426614174000', 'externalId' => 'same/external', 'expectedPriorRevision' => '1', 'expectedLiveRevision' => '2', 'expectedAuthorizationGeneration' => '1']; }

    public function testRefreshIsSameBindingOnlyAndReplayIsDurable(): void
    {
        $pdo = $this->database(); $command = $this->command();
        $first = \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $command, 7, $this->headers(), '723e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $first['status']); self::assertFalse($first['payload']['replayed']);
        self::assertSame(['type' => 'client', 'id' => 'same/external', 'revision' => '2'], $first['payload']['result']['resource']);
        self::assertSame(str_repeat('a', 32), $first['payload']['result']['binding']['publicId']); self::assertSame('2', $first['payload']['result']['binding']['authorizationGeneration']);
        self::assertSame(2, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        $replay = \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $command, 7, $this->headers(), '823e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $replay['status']); self::assertTrue($replay['payload']['replayed']);
        self::assertSame(2, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
    }

    public function testConflictsFailClosedAndReplayCannotMaskNewDrift(): void
    {
        $pdo = $this->database(); $command = $this->command();
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_write($pdo, 'client', array_replace($command, ['expectedPriorRevision' => '2', 'expectedLiveRevision' => '2']), 7, $this->headers(), '723e4567-e89b-42d3-a456-426614174000')['status']);
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $command, 7, array_replace($this->headers(), ['epoch' => 'wrong']), '723e4567-e89b-42d3-a456-426614174000')['status']);
        self::assertSame(200, \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $command, 7, $this->headers(), '723e4567-e89b-42d3-a456-426614174000')['status']);
        $pdo->exec("UPDATE clients SET name='Drift' WHERE id=9");
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $command, 7, $this->headers(), '823e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testParserRejectsRetargetFieldsAndControllerIsStateless(): void
    {
        $command = $this->command(); self::assertSame($command, \api_v2_directory_binding_revision_refresh_parse(json_encode($command, JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_binding_revision_refresh_parse(json_encode($command + ['publicId' => str_repeat('a', 32)], JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_binding_revision_refresh_parse(json_encode(array_replace($command, ['commandId' => 'bad']), JSON_THROW_ON_ERROR)));
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/api/directory_binding_revision_refresh_v2.php');
        self::assertStringContainsString("api_require_key(['api.capabilities.read', \$scope], false)", $source); self::assertStringContainsString("define('PA_STATELESS_API_NO_SESSION', true)", $source); self::assertStringNotContainsString('session_start(', $source);
    }

    public function testCapabilityIsDedicatedAndDefaultOff(): void
    {
        $identity = ['source_instance_id' => '123e4567-e89b-42d3-a456-426614174000', 'application_id' => '223e4567-e89b-42d3-a456-426614174000', 'history_epoch' => '323e4567-e89b-42d3-a456-426614174000'];
        $scopes = ['api.capabilities.read', 'directory.clients.binding.revision.refresh'];
        self::assertSame($scopes, \api_normalize_scopes($scopes));
        self::assertFalse(\api_key_has_scope('full', 'directory.clients.binding.revision.refresh', false));
        self::assertCount(1, \api_v2_capabilities_payload($identity, '423e4567-e89b-42d3-a456-426614174000', $scopes)['implementedEndpoints']);
        $on = \api_v2_capabilities_payload($identity, '423e4567-e89b-42d3-a456-426614174000', $scopes, ['directory_binding_refresh' => true]);
        self::assertSame(['api.capabilities.read', 'directory.clients.binding.revision.refresh'], array_column($on['grantedCapabilities'], 'name'));
        self::assertSame('/api/v2/directory/clients/bindings/revisions/commands', $on['implementedEndpoints'][1]['path']);
        self::assertStringContainsString('APP_API_V2_DIRECTORY_BINDING_REFRESH_ENABLED', (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php'));
    }

    public function testPriorEpochRefreshReceiptCannotReplayAfterEpochRotation(): void
    {
        $pdo=$this->database();$command=$this->command();
        self::assertSame(200,\api_v2_directory_binding_revision_refresh_write($pdo,'client',$command,7,$this->headers(),'first')['status']);
        $next='423e4567-e89b-42d3-a456-426614174001';$pdo->prepare('UPDATE api_v2_history_identity SET history_epoch=?')->execute([$next]);$headers=$this->headers();$headers['epoch']=$next;
        $outcome=\api_v2_directory_binding_revision_refresh_write($pdo,'client',$command,7,$headers,'next');
        self::assertSame(409,$outcome['status']);self::assertArrayNotHasKey('payload',$outcome);
        self::assertSame('323e4567-e89b-42d3-a456-426614174000',$pdo->query('SELECT history_epoch FROM api_v2_directory_binding_revision_refresh_receipts')->fetchColumn());
    }
}
