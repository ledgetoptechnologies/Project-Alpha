<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryReadTest extends TestCase
{
    private function database(): PDO
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_read.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,general_email TEXT,general_phone TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            INSERT INTO api_keys VALUES(7,3,NULL);
            INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0);
            INSERT INTO organizations(id,public_id,name,general_email) VALUES(5,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Example Org','org@example.test');
            INSERT INTO clients(id,public_id,name,email,phone,client_type,organization_id) VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example Client','client@example.test',NULL,'business',5);");
        $pdo->beginTransaction();
        \api_v2_directory_record($pdo, 'organization', 5);
        \api_v2_directory_record($pdo, 'client', 9);
        $pdo->commit();
        return $pdo;
    }

    private function headers(): array
    {
        return [
            'source' => '123e4567-e89b-42d3-a456-426614174000',
            'application' => '223e4567-e89b-42d3-a456-426614174000',
            'epoch' => '323e4567-e89b-42d3-a456-426614174000',
        ];
    }

    public function testExactClientAndOrganizationPayloads(): void
    {
        $pdo = $this->database();
        $requestId = '423e4567-e89b-42d3-a456-426614174000';
        $client = \api_v2_directory_read($pdo, 'client', str_repeat('a', 32), 7, $this->headers(), $requestId);
        self::assertSame(['apiVersion','sourceInstanceId','applicationId','historyEpoch','requestId','authorizationGeneration','resource','data'], array_keys($client));
        self::assertSame('0', $client['authorizationGeneration']);
        self::assertSame(['type' => 'client', 'id' => str_repeat('a', 32), 'revision' => '1'], $client['resource']);
        self::assertSame(['publicId','name','email','phone','address','clientType','organizationPublicId'], array_keys($client['data']));
        self::assertSame(str_repeat('b', 32), $client['data']['organizationPublicId']);
        self::assertSame(['line1' => null, 'line2' => null, 'city' => null, 'state' => null, 'postalCode' => null, 'country' => null], $client['data']['address']);
        $organization = \api_v2_directory_read($pdo, 'organization', str_repeat('b', 32), 7, $this->headers(), $requestId);
        self::assertSame(['publicId','name','email','phone','address'], array_keys($organization['data']));
        self::assertSame('org@example.test', $organization['data']['email']);
    }

    public function testFailsClosedForBindingRevisionAndProfileDrift(): void
    {
        $pdo = $this->database();
        $id = str_repeat('a', 32);
        $requestId = '423e4567-e89b-42d3-a456-426614174000';
        self::assertNull(\api_v2_directory_read($pdo, 'client', $id, 7, array_replace($this->headers(), ['application' => 'wrong']), $requestId));
        $pdo->exec("UPDATE clients SET name='Untracked' WHERE id=9");
        self::assertNull(\api_v2_directory_read($pdo, 'client', $id, 7, $this->headers(), $requestId));
        $pdo->exec("UPDATE clients SET name='Example Client' WHERE id=9; UPDATE api_v2_directory_resource_state SET present=0 WHERE resource_type='client'");
        self::assertNull(\api_v2_directory_read($pdo, 'client', $id, 7, $this->headers(), $requestId));
        $pdo->exec('DELETE FROM api_v2_directory_authorization_state');
        self::assertNull(\api_v2_directory_read($pdo, 'organization', str_repeat('b', 32), 7, $this->headers(), $requestId));
    }

    public function testControllerIsStatelessAndExplicitlyScoped(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/api/directory_read_v2.php');
        self::assertStringContainsString("api_require_key(['api.capabilities.read', \$scope], false)", $source);
        self::assertStringContainsString("define('PA_STATELESS_API_NO_SESSION', true)", $source);
        self::assertStringContainsString("header('Cache-Control: no-store')", $source);
        self::assertStringContainsString("header('X-Request-ID: '", $source);
        self::assertStringNotContainsString('session_start(', $source);
        self::assertStringNotContainsString('Set-Cookie', $source);
    }
}
