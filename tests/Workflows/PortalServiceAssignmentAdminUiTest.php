<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalServiceAssignmentAdminUiTest extends TestCase
{
    public function testDedicatedPermissionIsSeededOnlyForOwnersAndRegisteredInTheCatalog(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents($root . '/database/migrations/0081_portal_service_assignment_management_permission.sql');
        $catalog = (string)file_get_contents($root . '/src/utils/permission_catalog.php');

        self::assertStringContainsString("'portal_service_assignments.manage'", $migration);
        self::assertStringContainsString("name = 'owner'", $migration);
        self::assertStringContainsString("'Client Portal' => ['portal_service_assignments.manage']", $catalog);
    }

    public function testPostRouteUsesGlobalCsrfAndDedicatedAclPermission(): void
    {
        $root = dirname(__DIR__, 2);
        $router = (string)file_get_contents($root . '/public/index.php');
        $acl = (string)file_get_contents($root . '/src/utils/acl_middleware.php');

        self::assertStringContainsString("if (\$page === 'portal/service-assignments-handler')", $router);
        self::assertStringContainsString("'portal/service-assignments-handler'", $this->csrfBypassList($router));
        self::assertStringContainsString('csrf_validate()', (string)file_get_contents($root . '/src/controllers/portal/service_assignments_handler.php'));
        self::assertStringContainsString("Invalid request (CSRF)", (string)file_get_contents($root . '/src/controllers/portal/service_assignments_handler.php'));
        self::assertStringContainsString('That service is already assigned to this record.', (string)file_get_contents($root . '/src/controllers/portal/service_assignments_handler.php'));
        self::assertStringContainsString("'portal/service-assignments-handler' => 'portal_service_assignments.manage'", $acl);
    }

    public function testControllerDerivesWireIdentityAndProjectionProfilesServerSide(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/portal/service_assignments_handler.php');

        self::assertStringContainsString('require_record_ownership', $controller);
        self::assertStringContainsString("user_can(\$pdo, \$actorId, \$subject['permission'], 0)", $controller);
        self::assertStringContainsString('new PortalServiceAssignmentManager()', $controller);
        self::assertStringNotContainsString("\$_POST['profile_id']", $controller);
        self::assertStringNotContainsString("\$_POST['subject_public_id']", $controller);
        self::assertStringNotContainsString("\$_POST['service_public_id']", $controller);
        self::assertStringContainsString("audit_log(\$pdo, 'portal_service_assignment.' . \$action", $controller);
    }

    public function testEntityViewsOwnTheirAssignmentControlsAndCustomIntegrationsDoesNot(): void
    {
        $root = dirname(__DIR__, 2);
        $component = (string)file_get_contents($root . '/src/views/components/portal_service_assignments.php');
        $client = (string)file_get_contents($root . '/src/views/pages/client/client-details.php');
        $project = (string)file_get_contents($root . '/src/views/pages/project/projects-details.php');
        $organization = (string)file_get_contents($root . '/src/views/pages/organization/organization-view.php');

        self::assertStringContainsString("user_can(\$pdo, \$assignmentActorId, 'portal_service_assignments.manage', 0)", $component);
        self::assertStringContainsString('It does not grant portal access, membership, files, billing, or notifications.', $component);
        self::assertStringContainsString('name="csrf"', $component);
        self::assertStringContainsString('portal_service_assignments.php', $client);
        self::assertStringContainsString('portal_service_assignments.php', $project);
        self::assertGreaterThanOrEqual(2, substr_count($organization, 'portal_service_assignments.php'));
    }

    public function testUnavailableCurrentServiceCannotSilentlySelectAnotherCatalogService(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = (string)file_get_contents($root . '/src/services/PortalServiceAssignmentManager.php');
        $component = (string)file_get_contents($root . '/src/views/components/portal_service_assignments.php');

        self::assertStringContainsString('service_available', $manager);
        self::assertStringContainsString('if (!$assignmentServiceAvailable)', $component);
        self::assertStringContainsString('selected disabled>Unavailable:', $component);
    }

    public function testSingleExternalOperationsFormOwnsTheDefaultOffProducerToggle(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings/external-ops.php');
        $handler = (string)file_get_contents($root . '/src/controllers/settings/external_ops_handler.php');
        $provisioning = (string)file_get_contents($root . '/src/services/PortalClientProvisioningService.php');

        self::assertSame(1, substr_count($view, 'name="service_assignment_projection_enabled"'));
        self::assertStringContainsString('Default off.', $view);
        self::assertStringContainsString("!empty(\$_POST['service_assignment_projection_enabled'])", $handler);
        self::assertStringContainsString("array_key_exists('service_assignment_projection_enabled', \$externalConfig)", $provisioning);
        self::assertStringNotContainsString('service_assignment_route', $view);
    }

    public function testPortalDeploymentKnobsAreDocumentedWithoutSecrets(): void
    {
        $root = dirname(__DIR__, 2);
        $env = (string)file_get_contents($root . '/config/.env.example');
        $compose = (string)file_get_contents($root . '/docker-compose.yml');
        $deployment = (string)file_get_contents($root . '/docs/admin/deployment.md');
        self::assertStringContainsString('PORTAL_INTEGRATION_HMAC_SECRETS_JSON={}', $env);
        self::assertStringNotContainsString('EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL=', $env);
        self::assertStringNotContainsString('EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_KEY_ID=', $env);
        self::assertStringNotContainsString('EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_SECRET=', $env);
        self::assertStringContainsString('PORTAL_INTEGRATION_HMAC_SECRETS_JSON: ""', $compose);
        self::assertStringNotContainsString('EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL:', $compose);
        self::assertStringContainsString('same signed event URL', $deployment);
        preg_match('/^  cron:\R(.*?)(?=^  [a-z]+:|\z)/ms', $compose, $cron);
        self::assertStringNotContainsString('EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL:', $cron[1] ?? '');
        self::assertStringNotContainsString('PORTAL_INTEGRATION_HMAC_SECRETS_JSON', $cron[1] ?? '');
        self::assertStringNotContainsString('EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL', $deployment);
        $entrypoint = (string)file_get_contents($root . '/cron/entrypoint.sh');
        self::assertStringNotContainsString('EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL', $entrypoint);
        self::assertStringNotContainsString('EXTERNAL_OPS_*', $entrypoint);
        self::assertStringNotContainsString('PORTAL_INTEGRATION_HMAC_SECRETS_JSON', $entrypoint);
        self::assertStringNotContainsString(str_repeat('a', 32), $env);
    }

    private function csrfBypassList(string $router): string
    {
        $start = strpos($router, '$skipCsrfFor =');
        $end = $start === false ? false : strpos($router, '];', $start);
        return $start === false || $end === false ? '' : substr($router, $start, $end - $start + 2);
    }
}
