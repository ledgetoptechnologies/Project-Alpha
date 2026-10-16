<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiV2CommandCapabilityMetadataTest extends TestCase
{
    private const IDENTITY = ['source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000',
        'application_id'=>'223e4567-e89b-42d3-a456-426614174000',
        'history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
    }

    public function testExistingBindingsMeetTheExactOpsPreflightAndDoNotGrantNarrowKeysWriteAccess(): void
    {
        // Ops project-alpha-existing-directory-binding-api-v2.ts route() requires
        // both booleans in addition to the three exact application identities.
        $payload = api_v2_capabilities_payload(self::IDENTITY, '423e4567-e89b-42d3-a456-426614174000',
            ['api.capabilities.read'], ['directory_binding'=>true]);
        self::assertSame([['name'=>'api.capabilities.read']], $payload['grantedCapabilities']);
        foreach (array_slice($payload['implementedEndpoints'], 1) as $endpoint) {
            self::assertSame('POST', $endpoint['method']);
            foreach (['requiresSourceInstanceId','requiresApplicationId','requiresHistoryEpoch',
                'requiresExpectedPublicId','requiresExpectedRevision'] as $flag) {
                self::assertTrue($endpoint[$flag] ?? null, $endpoint['path'] . ' ' . $flag);
            }
            self::assertArrayNotHasKey('requiresExpectedProfileSha256', $endpoint);
        }
        self::assertCount(4, $payload['implementedEndpoints']);
        $off = api_v2_capabilities_payload(self::IDENTITY, '423e4567-e89b-42d3-a456-426614174000',
            ['directory.clients.bind'], ['directory_binding'=>false]);
        self::assertCount(1, $off['implementedEndpoints']);
        self::assertSame([['name'=>'api.capabilities.read']], $off['grantedCapabilities']);
    }

    public function testPreconditionMetadataMatchesImplementedCommandShapes(): void
    {
        $cases = [
            ['directory_organization_write', true, false],
            ['directory_client_write', true, false],
            ['directory_organization_create', false, false],
            ['directory_client_create', false, false],
            ['directory_binding_refresh', true, false],
            ['directory_binding_refresh_reconciliation', true, false],
            ['directory_binding_revoke', true, true],
            ['projects_create', false, false],
            ['projects_write', true, false],
            ['projects_binding', true, true],
            ['projects_binding_refresh', true, true],
            ['projects_complete', true, false],
            ['projects_cancel', true, false],
            ['projects_archive', true, false],
            ['projects_restore', true, false],
        ];
        foreach ($cases as [$feature, $revision, $publicId]) {
            $payload = api_v2_capabilities_payload(self::IDENTITY, '423e4567-e89b-42d3-a456-426614174000', [], [$feature=>true]);
            self::assertGreaterThan(1, count($payload['implementedEndpoints']), $feature);
            foreach (array_slice($payload['implementedEndpoints'], 1) as $endpoint) {
                self::assertSame('POST', $endpoint['method'], $feature);
                self::assertSame($revision, $endpoint['requiresExpectedRevision'] ?? false, $feature . ' revision');
                self::assertSame($publicId, $endpoint['requiresExpectedPublicId'] ?? false, $feature . ' public ID');
                // Project projectionSha256 and Directory profileSha256 are distinct contracts.
                self::assertArrayNotHasKey('requiresExpectedProfileSha256', $endpoint, $feature);
            }
            self::assertSame([['name'=>'api.capabilities.read']], $payload['grantedCapabilities'], $feature);
        }
    }
}
