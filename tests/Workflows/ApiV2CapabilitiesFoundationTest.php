<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class ApiV2CapabilitiesFoundationTest extends TestCase
{
    public function testDedicatedScopeDoesNotPassThroughLegacyFullAccess(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_scopes.php';
        self::assertFalse(\api_key_has_scope('full', 'api.capabilities.read', false));
        self::assertTrue(\api_key_has_scope('api.capabilities.read', 'api.capabilities.read', false));
        self::assertSame([], \api_scope_catalog()['api.capabilities.read']['endpoints']);
        self::assertNotSame(['api.capabilities.read'], \api_normalize_scopes('api.capabilities.read,clients.read'));
    }

    public function testPayloadMatchesOpsPreflightContract(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        $identity = [
            'source_instance_id' => '123e4567-e89b-42d3-a456-426614174000',
            'application_id' => '223e4567-e89b-42d3-a456-426614174000',
            'history_epoch' => '323e4567-e89b-42d3-a456-426614174000',
        ];
        $requestId = \api_v2_uuid();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $requestId);
        self::assertTrue(\api_v2_identity_is_valid($identity));
        self::assertFalse(\api_v2_identity_is_valid(array_replace($identity, ['history_epoch' => strtoupper($identity['history_epoch'])])));
        self::assertFalse(\api_v2_identity_is_valid(array_replace($identity, ['application_id' => 'not-a-uuid'])));
        self::assertSame([
            'apiVersion' => '2',
            'sourceInstanceId' => $identity['source_instance_id'],
            'applicationId' => $identity['application_id'],
            'historyEpoch' => $identity['history_epoch'],
            'requestId' => $requestId,
            'grantedCapabilities' => [['name' => 'api.capabilities.read']],
            'implementedEndpoints' => [[
                'method' => 'GET',
                'path' => '/api/v2/capabilities',
                'requiredCapability' => 'api.capabilities.read',
            ]],
        ], \api_v2_capabilities_payload($identity, $requestId));
    }

    public function testStatelessRouteAndBindingAreExplicit(): void
    {
        $root = dirname(__DIR__, 2);
        $front = file_get_contents($root . '/public/index.php');
        $route = file_get_contents($root . '/src/controllers/api/capabilities_v2.php');
        self::assertLessThan(strpos($front, 'session_start()'), strpos($front, "'/api/v2/capabilities'"));
        self::assertStringContainsString("api_require_key(['api.capabilities.read'], false)", $route);
        self::assertStringContainsString('app.id = api_key.api_v2_application_id', $route);
        self::assertStringContainsString('api_v2_capabilities_payload($identity, $requestId)', $route);
        self::assertLessThan(strpos($route, 'new PDO('), strpos($route, "'REQUEST_METHOD'"));
        self::assertStringContainsString('api_normalize_scopes($key', $route);
        self::assertStringNotContainsString('$_GET[', $route);
    }
}
