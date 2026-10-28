<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/portal_integration_security.php';

final class PortalIntegrationRouteTest extends TestCase
{
    public function testFeatureFlagsAndExactPathGateBothIntegrationCommands(): void
    {
        $originalPricing = getenv('APP_PORTAL_PRICING_PREVIEW_ENABLED');
        $originalDraft = getenv('APP_PORTAL_DRAFT_QUOTES_ENABLED');
        try {
            putenv('APP_PORTAL_PRICING_PREVIEW_ENABLED');
            putenv('APP_PORTAL_DRAFT_QUOTES_ENABLED');
            self::assertNull(portal_integration_route_key('/api/v2/integrations/example/pricing-hints', 'api-integration-pricing-hints'));
            self::assertNull(portal_integration_route_key('/api/v2/integrations/example/draft-quotes', 'api-integration-draft-quotes'));

            putenv('APP_PORTAL_PRICING_PREVIEW_ENABLED=true');
            putenv('APP_PORTAL_DRAFT_QUOTES_ENABLED=true');
            self::assertSame('example', portal_integration_route_key('/api/v2/integrations/example/pricing-hints', 'api-integration-pricing-hints'));
            self::assertSame('example', portal_integration_route_key('/api/v2/integrations/example/draft-quotes', 'api-integration-draft-quotes'));
            foreach (['/', '/index.php', '/api/v2/integrations/example/pricing-hints/', '/api/v2/integrations/example/draft-quotes/'] as $path) {
                self::assertNull(portal_integration_route_key($path, 'api-integration-pricing-hints'), $path);
                self::assertNull(portal_integration_route_key($path, 'api-integration-draft-quotes'), $path);
            }
            self::assertNull(portal_integration_route_key('/api/v2/integrations/example/draft-quotes', 'api-integration-pricing-hints'));
            self::assertNull(portal_integration_route_key('/api/v2/integrations/example/pricing-hints', 'api-integration-draft-quotes'));
            self::assertNull(portal_integration_route_key('/api/v2/integrations/../draft-quotes', 'api-integration-draft-quotes'));
        } finally {
            $originalPricing === false ? putenv('APP_PORTAL_PRICING_PREVIEW_ENABLED') : putenv('APP_PORTAL_PRICING_PREVIEW_ENABLED=' . $originalPricing);
            $originalDraft === false ? putenv('APP_PORTAL_DRAFT_QUOTES_ENABLED') : putenv('APP_PORTAL_DRAFT_QUOTES_ENABLED=' . $originalDraft);
        }
    }

    public function testFrontControllerUsesRouteGateBeforeIntegrationAuthentication(): void
    {
        $router = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $guard = strpos($router, 'portal_integration_route_key($requestPath, $page)');
        $auth = strpos($router, 'api_require_key([$requiredApiScope]');
        self::assertNotFalse($guard);
        self::assertNotFalse($auth);
        self::assertLessThan($auth, $guard);
    }
}
