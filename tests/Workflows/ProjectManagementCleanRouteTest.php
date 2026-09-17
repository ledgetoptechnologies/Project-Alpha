<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectManagementCleanRouteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProjectsAliasUsesOnlyTheExistingReadController(): void
    {
        $rewrite = $this->read('public/.htaccess');
        $router = $this->read('public/index.php');

        self::assertMatchesRegularExpression(
            '/RewriteRule\s+\^projects\/\?\$\s+index\.php\s+\[QSA,L\]/',
            $rewrite
        );
        self::assertStringContainsString("project_management_clean_route(\$requestPath, \$_SERVER['REQUEST_METHOD'] ?? 'GET', \$_GET)", $router);
        self::assertStringContainsString("header('Allow: GET, HEAD');", $router);
        self::assertStringContainsString("http_response_code(405);", $router);
    }

    public function testAliasRoutePrecedenceAndReadOnlyMethodsExecute(): void
    {
        require_once $this->root . '/src/utils/project_management_clean_route.php';

        $query = ['page' => 'settings/permissions', 'q' => 'survey'];
        self::assertSame(200, project_management_clean_route('/projects', 'GET', $query));
        self::assertSame(['page' => 'project/projects-list', 'q' => 'survey'], $query);

        $headQuery = ['page' => 'project/projects-create'];
        self::assertSame(200, project_management_clean_route('/projects/', 'HEAD', $headQuery));
        self::assertSame('project/projects-list', $headQuery['page']);

        $postQuery = ['page' => 'project/projects-delete'];
        self::assertSame(405, project_management_clean_route('/projects', 'POST', $postQuery));
        self::assertSame('project/projects-delete', $postQuery['page']);

        $otherQuery = ['page' => 'settings'];
        self::assertNull(project_management_clean_route('/projects/42', 'GET', $otherQuery));
        self::assertSame('settings', $otherQuery['page']);
    }

    public function testProjectsAliasDoesNotCreateANewPermissionOrContextualRoute(): void
    {
        $router = $this->read('public/index.php');
        $acl = $this->read('src/utils/acl_middleware.php');

        self::assertStringContainsString("'project/projects-list'", $router);
        self::assertMatchesRegularExpression("/'project\/projects-list'\s*=>\s*'projects\.view'/", $acl);
        self::assertStringNotContainsString("'/projects/' =>", $router);
        self::assertStringNotContainsString("'project/projects-create'", $this->read('src/utils/project_management_clean_route.php'));
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertNotFalse($contents, "Unable to read {$relativePath}");

        return $contents;
    }
}
