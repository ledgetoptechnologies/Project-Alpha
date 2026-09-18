<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class ApiV2BindingStatusFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_binding_status.php';
    }

    public function testStrictlyDecodesCanonicalBase64UrlUtf8ExternalIds(): void
    {
        $external = 'ops/client/測量';
        $encoded = rtrim(strtr(base64_encode($external), '+/', '-_'), '=');
        self::assertSame($external, \api_v2_binding_status_decode_external_id($encoded));
        self::assertNull(\api_v2_binding_status_decode_external_id($encoded . '='));
        self::assertNull(\api_v2_binding_status_decode_external_id('AA'));
        self::assertSame(['kind' => 'client', 'externalId' => $external], \api_v2_binding_status_route('/api/v2/bindings/client/status/' . $encoded));
        self::assertNull(\api_v2_binding_status_route('/api/v2/bindings/project/status/' . $encoded));
    }

    public function testPayloadIsExactOpsBindingStatusEnvelope(): void
    {
        $identity = ['source_instance_id' => '123e4567-e89b-42d3-a456-426614174000', 'application_id' => '223e4567-e89b-42d3-a456-426614174000', 'history_epoch' => '323e4567-e89b-42d3-a456-426614174000'];
        $requestId = '423e4567-e89b-42d3-a456-426614174000';
        $binding = ['resource_type' => 'client', 'external_id' => 'exact/id', 'public_id' => str_repeat('a', 32), 'resource_revision' => '7', 'authorization_generation' => '0', 'created_at' => '2026-09-14 12:35:56.789000'];
        self::assertSame(['directory.clients.binding_status.read', 'directory.organizations.binding_status.read'], [\api_v2_binding_status_scope('client'), \api_v2_binding_status_scope('organization')]);
        self::assertSame(['apiVersion' => '2', 'sourceInstanceId' => $identity['source_instance_id'], 'historyEpoch' => $identity['history_epoch'], 'authorizationGeneration' => '0', 'binding' => ['type' => 'client', 'externalId' => 'exact/id', 'publicId' => str_repeat('a', 32), 'createdAt' => '2026-09-14T12:35:56.789Z'], 'resource' => ['revision' => '7', 'present' => true], 'applicationId' => $identity['application_id'], 'requestId' => $requestId], \api_v2_binding_status_payload($identity, $requestId, $binding));
    }

    public function testLiveProfileDriftAndTrailingSpaceExternalIdsFailClosedExactly(): void
    {
        $pdo = new \PDO('sqlite::memory:'); $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT); CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT); CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT); CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,created_at TEXT); CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER); CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER); CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT)");
        $source = '123e4567-e89b-42d3-a456-426614174000'; $application = '223e4567-e89b-42d3-a456-426614174000'; $epoch = '323e4567-e89b-42d3-a456-426614174000'; $public = str_repeat('a', 32); $external = 'external id ';
        $pdo->prepare('INSERT INTO api_keys VALUES(7,3,NULL)')->execute(); $pdo->prepare('INSERT INTO api_v2_applications VALUES(3,?)')->execute([$application]); $pdo->prepare('INSERT INTO api_v2_history_identity VALUES(1,?,?)')->execute([$source, $epoch]);
        $pdo->prepare('INSERT INTO clients VALUES(9,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$public, 'Original', null, null, 'business', null, null, null, null, null, null, null]);
        $live = $pdo->query('SELECT * FROM clients WHERE id=9')->fetch(\PDO::FETCH_ASSOC); $hash = \api_v2_directory_projection_hash('client', $live);
        $pdo->prepare('INSERT INTO api_v2_directory_resource_state VALUES(?,?,?,?,1)')->execute(['client', $public, 7, $hash]); $pdo->prepare('INSERT INTO api_v2_directory_external_bindings VALUES(?,?,?,?,?,?,?,?)')->execute([3, 'client', $external, $public, 7, $hash, 'active', '2026-09-14 12:35:56.789000']); $pdo->prepare('INSERT INTO api_v2_directory_authorization_state VALUES(3,0)')->execute();
        $headers = ['source' => $source, 'application' => $application, 'epoch' => $epoch]; $request = '423e4567-e89b-42d3-a456-426614174000';
        self::assertSame(200, \api_v2_binding_status_read($pdo, 'client', $external, 7, $headers, $request)['status']);
        self::assertSame(404, \api_v2_binding_status_read($pdo, 'client', rtrim($external), 7, $headers, $request)['status']);
        $pdo->exec("UPDATE clients SET name='Untracked change' WHERE id=9");
        self::assertSame(409, \api_v2_binding_status_read($pdo, 'client', $external, 7, $headers, $request)['status']);
    }

    public function testFoundationIsStatelessAndDiscoveryRemainsFeatureGated(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/src/controllers/api/binding_status_v2.php');
        $helper = file_get_contents($root . '/src/utils/api_v2_binding_status.php');
        $front = file_get_contents($root . '/public/index.php');
        self::assertStringContainsString("api_require_key(['api.capabilities.read', \$scope], false)", $controller);
        self::assertStringContainsString("define('PA_STATELESS_API_NO_SESSION', true)", $controller);
        self::assertStringContainsString("header('Cache-Control: no-store')", $controller);
        self::assertStringContainsString('APP_API_V2_BINDING_STATUS_ENABLED', $front);
        self::assertStringContainsString('HTTP_X_PA_SOURCE_INSTANCE_ID', $controller);
        self::assertStringContainsString('HTTP_X_PA_APPLICATION_ID', $controller);
        self::assertStringContainsString('HTTP_X_PA_HISTORY_EPOCH', $controller);
        self::assertStringContainsString('resource_projection_sha256', $helper);
        self::assertStringContainsString("WHERE api_key.id=? AND api_key.revoked_at IS NULL LIMIT 2' . \$lock", $helper);
        self::assertStringContainsString("stateRow['projection_sha256']", $helper);
        self::assertLessThan(
            strpos($helper, 'SELECT present,CAST(revision AS CHAR) state_revision'),
            strpos($helper, "SELECT * FROM ' . \$table . ' WHERE public_id=? LIMIT 2")
        );
        self::assertStringContainsString('api_v2_directory_projection_hash', $helper);
        $identity = ['source_instance_id' => '123e4567-e89b-42d3-a456-426614174000', 'application_id' => '223e4567-e89b-42d3-a456-426614174000', 'history_epoch' => '323e4567-e89b-42d3-a456-426614174000'];
        $discovery = \api_v2_capabilities_payload($identity, '423e4567-e89b-42d3-a456-426614174000', ['directory.clients.binding_status.read'], []);
        self::assertNotContains('directory.clients.binding_status.read', array_column($discovery['grantedCapabilities'], 'name'));
    }
}
