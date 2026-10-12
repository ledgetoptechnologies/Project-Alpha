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
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_revision_refresh_reconciliation.php';
        $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT,scopes TEXT); CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT); CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT); CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER); CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id)); CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision)); CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE(application_pk,resource_type,public_id)); CREATE TABLE api_v2_directory_binding_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id)); CREATE TABLE api_v2_directory_binding_revision_refresh_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,expected_prior_revision INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,expected_authorization_generation INTEGER,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id)); CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT); INSERT INTO api_keys VALUES(7,3,NULL,'api.capabilities.read,directory.clients.binding.revision.refresh'); INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO clients(id,public_id,name) VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example');");
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

    public function testLockedPreconditionRunsInsideTransactionAndRefusalIsImmutable(): void
    {
        $pdo = $this->database();
        $beforeBinding = $pdo->query('SELECT * FROM api_v2_directory_external_bindings')->fetch(PDO::FETCH_ASSOC);
        $beforeGeneration = $pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn();
        $called = false;
        $outcome = \api_v2_directory_binding_revision_refresh_write(
            $pdo, 'client', $this->command(), 7, $this->headers(), 'refused',
            static function (array $context) use (&$called, $pdo): bool {
                $called = true;
                self::assertTrue($pdo->inTransaction());
                self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $context['binding']['public_id']);
                self::assertSame('1', $context['binding']['resource_revision']);
                self::assertSame('2', $context['state']['revision']);
                self::assertSame('1', $context['authorizationGeneration']);
                return false;
            }
        );
        self::assertTrue($called); self::assertSame(409, $outcome['status']);
        self::assertSame($beforeBinding, $pdo->query('SELECT * FROM api_v2_directory_external_bindings')->fetch(PDO::FETCH_ASSOC));
        self::assertSame($beforeGeneration, $pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_binding_revision_refresh_receipts')->fetchColumn());
    }

    public function testLockedPreconditionAcceptsAndIsNotReevaluatedForExactReplay(): void
    {
        $pdo = $this->database(); $calls = 0;
        $accepted = \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $this->command(), 7, $this->headers(), 'accepted', static function (array $context) use (&$calls): bool {
            $calls++;
            return isset($context['pdo'], $context['identity'], $context['live']) && $context['pdo'] instanceof PDO;
        });
        self::assertSame(200, $accepted['status']); self::assertSame(1, $calls);
        $replay = \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $this->command(), 7, $this->headers(), 'replay', static function (): bool {
            throw new \LogicException('A durable replay must not rerun a fresh-write precondition.');
        });
        self::assertSame(200, $replay['status']); self::assertTrue($replay['payload']['replayed']);
    }

    public function testReconciliationReturnsExistingExactReceiptAsValidatedAcknowledgement(): void
    {
        $pdo = $this->database(); $command = $this->command();
        self::assertSame(200, \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $command, 7, $this->headers(), 'write')['status']);
        $outcome = \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), '923e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $outcome['status']); self::assertSame('acknowledged', $outcome['payload']['status']);
        self::assertSame(hash('sha256', json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $outcome['payload']['command']['requestSha256']);
        self::assertTrue($outcome['payload']['acknowledgement']['replayed']);
        self::assertSame('2', $outcome['payload']['acknowledgement']['result']['binding']['authorizationGeneration']);
        self::assertSame($outcome['payload']['observation']['binding']['publicId'], $outcome['payload']['acknowledgement']['result']['binding']['publicId']);
    }

    public function testReconciliationProvesTerminalConflictOnlyAfterGenerationFence(): void
    {
        $pdo = $this->database(); $command = $this->command();
        $bindingProjection = (string)$pdo->query('SELECT resource_projection_sha256 FROM api_v2_directory_external_bindings')->fetchColumn();
        $resourceProjection = (string)$pdo->query('SELECT projection_sha256 FROM api_v2_directory_resource_state')->fetchColumn();
        self::assertNotSame($resourceProjection, $bindingProjection);
        $pdo->exec('UPDATE api_v2_directory_authorization_state SET authorization_generation=2');
        $before = [
            'generation' => $pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn(),
            'binding' => $pdo->query('SELECT * FROM api_v2_directory_external_bindings')->fetch(PDO::FETCH_ASSOC),
            'receipts' => $pdo->query('SELECT COUNT(*) FROM api_v2_directory_binding_revision_refresh_receipts')->fetchColumn(),
        ];
        $outcome = \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), '923e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $outcome['status']); self::assertSame('terminal_conflict', $outcome['payload']['status']);
        self::assertNull($outcome['payload']['acknowledgement']);
        self::assertSame('2', $outcome['payload']['observation']['authorizationGeneration']);
        self::assertSame('1', $outcome['payload']['observation']['binding']['revision']);
        self::assertSame($bindingProjection, $outcome['payload']['observation']['binding']['projectionSha256']);
        self::assertSame('2', $outcome['payload']['observation']['resource']['revision']);
        self::assertSame($resourceProjection, $outcome['payload']['observation']['resource']['projectionSha256']);
        self::assertSame($before['generation'], $pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        self::assertSame($before['binding'], $pdo->query('SELECT * FROM api_v2_directory_external_bindings')->fetch(PDO::FETCH_ASSOC));
        self::assertSame($before['receipts'], $pdo->query('SELECT COUNT(*) FROM api_v2_directory_binding_revision_refresh_receipts')->fetchColumn());
    }

    public function testReconciliationNeverTurnsPlainAbsenceOrHeadDriftIntoAuthority(): void
    {
        $pdo = $this->database(); $command = $this->command();
        $unresolved = \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), 'same-generation');
        self::assertSame(200, $unresolved['status']); self::assertSame('unresolved', $unresolved['payload']['status']); self::assertNull($unresolved['payload']['acknowledgement']);
        $pdo->exec("UPDATE api_v2_directory_authorization_state SET authorization_generation=2; UPDATE api_v2_directory_external_bindings SET resource_revision=2");
        $drifted = \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), 'drifted-binding');
        self::assertSame(200, $drifted['status']); self::assertSame('unresolved', $drifted['payload']['status']);
    }

    public function testReconciliationRejectsIdentityReceiptAndCanonicalProjectionMismatch(): void
    {
        $pdo = $this->database(); $command = $this->command();
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, array_replace($this->headers(), ['application' => 'wrong']), 'identity')['status']);
        self::assertSame(200, \api_v2_directory_binding_revision_refresh_write($pdo, 'client', $command, 7, $this->headers(), 'write')['status']);
        $collision = array_replace($command, ['expectedAuthorizationGeneration' => '2']);
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $collision, 7, $this->headers(), 'collision')['status']);
        $pdo->exec("UPDATE clients SET name='Unrecorded drift'");
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), 'projection')['status']);
    }

    public function testReconciliationRouteIsDefaultOffAndReusesExactRefreshScope(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/src/controllers/api/directory_binding_revision_refresh_reconciliation_v2.php');
        self::assertStringContainsString("api_require_key(['api.capabilities.read', \$scope], false)", $controller);
        self::assertStringContainsString("define('PA_STATELESS_API_NO_SESSION', true)", $controller);
        self::assertStringNotContainsString('session_start(', $controller);
        $router = (string)file_get_contents($root . '/public/index.php');
        self::assertStringContainsString('APP_API_V2_DIRECTORY_BINDING_REFRESH_RECONCILIATION_ENABLED', $router);
        $identity = ['source_instance_id' => '123e4567-e89b-42d3-a456-426614174000', 'application_id' => '223e4567-e89b-42d3-a456-426614174000', 'history_epoch' => '323e4567-e89b-42d3-a456-426614174000'];
        $scope = 'directory.clients.binding.revision.refresh';
        $off = \api_v2_capabilities_payload($identity, 'request', ['api.capabilities.read', $scope], ['directory_binding_refresh' => true]);
        self::assertCount(4, $off['implementedEndpoints']);
        $on = \api_v2_capabilities_payload($identity, 'request', ['api.capabilities.read', $scope], ['directory_binding_refresh' => true, 'directory_binding_refresh_reconciliation' => true]);
        self::assertCount(7, $on['implementedEndpoints']);
        self::assertSame(1, count(array_filter($on['grantedCapabilities'], static fn(array $capability): bool => $capability['name'] === $scope)));
        self::assertSame('/api/v2/directory/clients/bindings/revisions/commands/reconcile', $on['implementedEndpoints'][4]['path']);
    }

    public function testReconciliationUsesWriterCanonicalRequestHashForOrderedUnicodeCommand(): void
    {
        $pdo = $this->database();
        $externalId = 'customer/François/客户';
        $pdo->prepare('UPDATE api_v2_directory_external_bindings SET external_id=?')->execute([$externalId]);
        $json = json_encode([
            'commandId' => '623e4567-e89b-42d3-a456-426614174000',
            'externalId' => $externalId,
            'expectedPriorRevision' => '1',
            'expectedLiveRevision' => '2',
            'expectedAuthorizationGeneration' => '1',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $command = \api_v2_directory_binding_revision_refresh_parse($json);
        self::assertNotNull($command);
        self::assertSame(hash('sha256', $json), \api_v2_directory_binding_revision_refresh_request_hash($command));
        $pdo->exec('UPDATE api_v2_directory_authorization_state SET authorization_generation=2');
        $outcome = \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), 'unicode');
        self::assertSame('terminal_conflict', $outcome['payload']['status']);
        self::assertSame(hash('sha256', $json), $outcome['payload']['command']['requestSha256']);
        self::assertSame($externalId, $outcome['payload']['observation']['resource']['id']);
    }

    public function testReconciliationRevalidatesCurrentExactScopesUnderTheApiKeyLock(): void
    {
        $pdo = $this->database(); $command = $this->command();
        $pdo->exec("UPDATE api_v2_directory_authorization_state SET authorization_generation=2; UPDATE api_keys SET scopes='api.capabilities.read'");
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), 'scope-removed')['status']);
        $pdo->exec("UPDATE api_keys SET scopes='full,api.capabilities.read,directory.clients.binding.revision.refresh'");
        self::assertSame(409, \api_v2_directory_binding_revision_refresh_reconcile($pdo, 'client', $command, 7, $this->headers(), 'full')['status']);
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_binding_revision_refresh_receipts')->fetchColumn());
        self::assertSame('1', (string)$pdo->query('SELECT resource_revision FROM api_v2_directory_external_bindings')->fetchColumn());
    }
}
