<?php

declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class ApiV2ApacheRoutingTest extends TestCase
{
    public function testApacheRoutesTheWholeApiV2NamespaceThroughTheFrontController(): void
    {
        $root = dirname(__DIR__, 2);
        $rewrite = (string) file_get_contents($root . '/public/.htaccess');

        self::assertMatchesRegularExpression(
            '/RewriteRule\s+\^api\/v2\(\?:\/\|\$\)\s+index\.php\s+\[QSA,L\]/',
            $rewrite
        );
        self::assertStringContainsString('RewriteCond %{HTTP:Authorization} ^(.+)$', $rewrite);
        self::assertStringContainsString('RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]', $rewrite);
        self::assertLessThan(
            strpos($rewrite, 'RewriteRule ^projects/?$'),
            strpos($rewrite, 'RewriteRule ^api/v2(?:/|$)')
        );
    }

    public function testPublicRouterRejectsUnmappedApiV2PathsBeforeLegacyPageRouting(): void
    {
        $root = dirname(__DIR__, 2);
        $front = (string) file_get_contents($root . '/public/index.php');
        $guard = "if (preg_match('#^/api/v2(?:/|$)#D', \$requestPath) === 1 && !isset(\$moduleRoutes[\$requestPath]))";

        self::assertStringContainsString($guard, $front);
        self::assertStringContainsString("http_response_code(404);", $front);
        self::assertLessThan(
            strpos($front, "\$pageRaw = isset(\$_GET['page'])"),
            strpos($front, $guard)
        );
    }
}
