<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryBindingCommandTest extends TestCase
{
    private function database(): PDO
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_command.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE(application_pk,resource_type,public_id));
            CREATE TABLE api_v2_directory_binding_command_receipts(application_pk INTEGER,resource_type TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,PRIMARY KEY(application_pk,resource_type,command_id));
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0);
            INSERT INTO clients(id,public_id,name) VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example');");
        $pdo->beginTransaction(); \api_v2_directory_record($pdo, 'client', 9); $pdo->commit();
        return $pdo;
    }

    private function headers(): array
    {
        return ['source' => '123e4567-e89b-42d3-a456-426614174000', 'application' => '223e4567-e89b-42d3-a456-426614174000', 'epoch' => '323e4567-e89b-42d3-a456-426614174000'];
    }

    private function command(string $externalId = 'external  '): array
    {
        return ['commandId' => '423e4567-e89b-42d3-a456-426614174000', 'externalId' => $externalId, 'expectedPublicId' => str_repeat('a', 32), 'expectedRevision' => '1'];
    }

    public function testExactCommandValidationAndBytePreservingId(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_command.php';
        self::assertSame($this->command(), \api_v2_directory_binding_command_parse(json_encode(array_reverse($this->command(), true), JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_binding_command_parse(json_encode($this->command("bad\n"), JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_binding_command_parse(json_encode($this->command() + ['extra' => 1], JSON_THROW_ON_ERROR)));
        self::assertNull(\api_v2_directory_binding_command_parse(str_repeat(' ', 32769)));
    }

    public function testWriteReplayAndConflicts(): void
    {
        $pdo = $this->database(); $command = $this->command(); $requestId = '523e4567-e89b-42d3-a456-426614174000';
        $first = \api_v2_directory_binding_command_write($pdo, 'client', $command, 7, $this->headers(), $requestId);
        self::assertSame(200, $first['status']); self::assertFalse($first['payload']['replayed']);
        self::assertSame(['type' => 'client', 'id' => 'external  ', 'revision' => '1'], $first['payload']['result']['resource']);
        self::assertSame(1, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        $again = \api_v2_directory_binding_command_write($pdo, 'client', $command, 7, $this->headers(), $requestId);
        self::assertSame(200, $again['status']); self::assertTrue($again['payload']['replayed']);
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_binding_command_receipts')->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        self::assertSame(409, \api_v2_directory_binding_command_write($pdo, 'client', $this->command('external '), 7, $this->headers(), $requestId)['status']);
        self::assertSame(409, \api_v2_directory_binding_command_write($pdo, 'client', $command, 7, array_replace($this->headers(), ['epoch' => 'wrong']), $requestId)['status']);
        $pdo->exec("UPDATE clients SET name='Drift' WHERE id=9");
        self::assertSame(409, \api_v2_directory_binding_command_write($pdo, 'client', $command, 7, $this->headers(), $requestId)['status']);
    }

    public function testControllerIsStatelessAndBounded(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/api/directory_binding_command_v2.php');
        self::assertStringContainsString("api_require_key(['api.capabilities.read', \$scope], false)", $source);
        self::assertStringContainsString("header('Cache-Control: no-store')", $source);
        self::assertStringContainsString("stream_get_contents(\$stream, 32 * 1024 + 1)", $source);
        self::assertStringContainsString("define('PA_STATELESS_API_NO_SESSION', true)", $source);
        self::assertStringNotContainsString('session_start(', $source);
    }

    public function testFailedGenerationAdvanceRollsBackBindingAndReceipt(): void
    {
        $pdo = $this->database();
        $pdo->exec("CREATE TRIGGER stop_generation BEFORE UPDATE ON api_v2_directory_authorization_state BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        $outcome = \api_v2_directory_binding_command_write($pdo, 'client', $this->command(), 7, $this->headers(), '523e4567-e89b-42d3-a456-426614174000');
        self::assertSame(409, $outcome['status']);
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_external_bindings')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_binding_command_receipts')->fetchColumn());
    }
}
