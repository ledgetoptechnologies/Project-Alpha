<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiV2ApprovedRouteDefaultsTest extends TestCase
{
    private const ROUTES = [
        ['DIRECTORY_BINDING', 'directory_binding', '/api/v2/directory/organizations/bindings/commands', 'directory.organizations.bind'],
        ['DIRECTORY_BINDING_REFRESH', 'directory_binding_refresh', '/api/v2/directory/organizations/bindings/revisions/commands', 'directory.organizations.binding.revision.refresh'],
        ['DIRECTORY_BINDING_REFRESH_RECONCILIATION', 'directory_binding_refresh_reconciliation', '/api/v2/directory/organizations/bindings/revisions/commands/reconcile', 'directory.organizations.binding.revision.refresh'],
        ['DIRECTORY_ORGANIZATIONS_WRITE', 'directory_organization_write', '/api/v2/directory/organizations/{publicId}/profile/commands', 'directory.organizations.write'],
        ['DIRECTORY_CLIENTS_WRITE', 'directory_client_write', '/api/v2/directory/clients/{publicId}/profile/commands', 'directory.clients.write'],
        ['DIRECTORY_ORGANIZATIONS_CREATE', 'directory_organization_create', '/api/v2/directory/organizations/commands', 'directory.organizations.create'],
        ['DIRECTORY_CLIENTS_CREATE', 'directory_client_create', '/api/v2/directory/clients/commands', 'directory.clients.create'],
        ['DIRECTORY_RELATIONSHIPS_WRITE', 'directory_relationship_write', '/api/v2/directory/clients/{publicId}/organization/assign/commands', 'directory.clients.organization.assign'],
        ['PROJECTS_COMPLETE', 'projects_complete', '/api/v2/projects/{publicId}/complete/commands', 'projects.lifecycle.complete'],
        ['PROJECTS_CANCEL', 'projects_cancel', '/api/v2/projects/{publicId}/cancel/commands', 'projects.lifecycle.cancel'],
        ['PROJECTS_ARCHIVE', 'projects_archive', '/api/v2/projects/{publicId}/archive/commands', 'projects.lifecycle.archive'],
        ['PROJECTS_RESTORE', 'projects_restore', '/api/v2/projects/{publicId}/restore/commands', 'projects.lifecycle.restore'],
        ['PROJECTS_CREATE', 'projects_create', '/api/v2/projects/commands', 'projects.create'],
        ['PROJECTS_WRITE', 'projects_write', '/api/v2/projects/profile/commands', 'projects.write'],
        ['PROJECTS_BINDING_REFRESH', 'projects_binding_refresh', '/api/v2/projects/bindings/revisions/commands', 'projects.binding.revision.refresh'],
        ['DIRECTORY_CLIENTS_ARCHIVE', 'directory_client_archive', '/api/v2/directory/clients/{publicId}/archive/commands', 'directory.clients.archive'],
        ['DIRECTORY_CLIENTS_RESTORE', 'directory_client_restore', '/api/v2/directory/clients/{publicId}/restore/commands', 'directory.clients.restore'],
        ['DIRECTORY_ORGANIZATIONS_ARCHIVE', 'directory_organization_archive', '/api/v2/directory/organizations/{publicId}/archive/commands', 'directory.organizations.archive'],
        ['DIRECTORY_ORGANIZATIONS_RESTORE', 'directory_organization_restore', '/api/v2/directory/organizations/{publicId}/restore/commands', 'directory.organizations.restore'],
        ['DIRECTORY_BINDING_REVOKE', 'directory_binding_revoke', '/api/v2/directory/organizations/bindings/revoke/commands', 'directory.organizations.unbind'],
        ['PROJECTS_BINDING', 'projects_binding', '/api/v2/projects/bindings/commands', 'projects.bind'],
        ['FINANCIAL_DOCUMENTS', 'financial_documents', '/api/v2/financial-documents', 'financial.documents.read'],
    ];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
    }

    public function testExactlyTheApprovedTwentyTwoDefaultsHonorExplicitDisableAndInvalidValues(): void
    {
        self::assertCount(22, self::ROUTES);
        foreach (self::ROUTES as [$name]) {
            $flag = 'APP_API_V2_' . $name . '_ENABLED';
            $previous = getenv($flag);
            try {
                putenv($flag);
                self::assertTrue(api_v2_enabled($flag), $flag);
                foreach (['false', '0', 'off', 'invalid'] as $disabled) {
                    putenv($flag . '=' . $disabled);
                    self::assertFalse(api_v2_enabled($flag), $flag . '=' . $disabled);
                }
                putenv($flag . '=true');
                self::assertTrue(api_v2_enabled($flag), $flag);
            } finally {
                $previous === false ? putenv($flag) : putenv($flag . '=' . $previous);
            }
        }
    }

    public function testUnapprovedGatesRemainOffWithoutConfiguration(): void
    {
        foreach (['DIRECTORY_UNITS_WRITE', 'DIRECTORY_UNITS_CREATE', 'DIRECTORY_UNITS_ARCHIVE',
            'DIRECTORY_UNITS_RESTORE', 'DIRECTORY_UNIT_CONTACTS_WRITE', 'PROJECTS_ADOPTION_CANDIDATES',
            'CATALOG_INVENTORY', 'UNKNOWN'] as $name) {
            $flag = 'APP_API_V2_' . $name . '_ENABLED'; $previous = getenv($flag);
            try { putenv($flag); self::assertFalse(api_v2_enabled($flag), $flag); }
            finally { $previous === false ? putenv($flag) : putenv($flag . '=' . $previous); }
        }
    }

    public function testDiscoveryAdvertisesEnabledRoutesWithoutGrantingThemToANarrowKey(): void
    {
        $identity = ['source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000',
            'application_id'=>'223e4567-e89b-42d3-a456-426614174000',
            'history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
        foreach (self::ROUTES as [$name, $feature, $path, $scope]) {
            $flag = 'APP_API_V2_' . $name . '_ENABLED'; $previous = getenv($flag);
            try {
                putenv($flag);
                $payload = api_v2_capabilities_payload($identity, '423e4567-e89b-42d3-a456-426614174000',
                    ['api.capabilities.read'], [$feature=>api_v2_enabled($flag)]);
                self::assertContains($path, array_column($payload['implementedEndpoints'], 'path'), $flag);
                self::assertNotContains($scope, array_column($payload['grantedCapabilities'], 'name'), $flag);
                $granted = api_v2_capabilities_payload($identity, '423e4567-e89b-42d3-a456-426614174000',
                    [$scope], [$feature=>api_v2_enabled($flag)]);
                self::assertContains($scope, array_column($granted['grantedCapabilities'], 'name'), $flag);
                putenv($flag . '=false');
                $disabled = api_v2_capabilities_payload($identity, '423e4567-e89b-42d3-a456-426614174000',
                    [$scope], [$feature=>api_v2_enabled($flag)]);
                self::assertNotContains($path, array_column($disabled['implementedEndpoints'], 'path'), $flag);
                self::assertNotContains($scope, array_column($disabled['grantedCapabilities'], 'name'), $flag);
            } finally { $previous === false ? putenv($flag) : putenv($flag . '=' . $previous); }
        }
    }

    public function testCanonicalDeploymentDoesNotSilentlyOverrideApprovedDefaults(): void
    {
        $root = dirname(__DIR__, 2);
        $compose = (string) file_get_contents($root . '/docker-compose.yml');
        $example = (string) file_get_contents($root . '/config/.env.example');
        foreach (self::ROUTES as [$name]) {
            $flag = 'APP_API_V2_' . $name . '_ENABLED';
            self::assertDoesNotMatchRegularExpression('/^\\s*' . preg_quote($flag, '/') . '\\s*:/m', $compose);
            self::assertDoesNotMatchRegularExpression('/^' . preg_quote($flag, '/') . '=/m', $example);
            self::assertStringContainsString('# ' . $flag . '=false', $example);
        }
    }

    public function testStandaloneComposeRequiresOnlyDeploymentDatabasePasswords(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml');
        self::assertDoesNotMatchRegularExpression('/^\\s*env_file\\s*:/m', $compose);
        preg_match_all('/\$\{([^}:?]+):\?[^}]+\}/', $compose, $requiredVariables);
        $names = array_values(array_unique($requiredVariables[1]));
        sort($names);
        self::assertSame(['MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'], $names);
        self::assertDoesNotMatchRegularExpression('/^\\s*-\\s*[^#\\r\\n]*\\.env(?::|$)/m', $compose);
        self::assertStringContainsString('services:', $compose);
        self::assertStringContainsString('ghcr.io/ledgetoptechnologies/project-alpha:latest', $compose);
    }

    public function testDefaultDispatchAndExplicitFalseAgreeWithCapabilities(): void
    {
        foreach (self::ROUTES as [$name, , $path]) {
            $flag = 'APP_API_V2_' . $name . '_ENABLED';
            $actualPath = str_replace('{publicId}', str_repeat('a', 32), $path);
            // No JSON Content-Type: enabled commands must still reject malformed requests.
            $expected = $name === 'FINANCIAL_DOCUMENTS' ? 400 : 415;
            self::assertSame($expected, $this->routeStatus($actualPath, $name === 'FINANCIAL_DOCUMENTS' ? 'GET' : 'POST'), $flag);
            self::assertSame(404, $this->routeStatus($actualPath, $name === 'FINANCIAL_DOCUMENTS' ? 'GET' : 'POST', $flag), $flag);
        }
    }

    private function routeStatus(string $path, string $method, ?string $disabled = null): int
    {
        if (!function_exists('proc_open')) self::markTestSkipped('proc_open required for front-controller coverage.');
        $root = dirname(__DIR__, 2);
        $script = '$_SERVER=["REQUEST_URI"=>' . var_export($path, true) . ',"REQUEST_METHOD"=>' . var_export($method, true) . '];'
            . 'register_shutdown_function(static function():void{fwrite(STDOUT,"__STATUS__=".http_response_code());});'
            . 'require ' . var_export($root . '/public/index.php', true) . ';';
        $environment = getenv();
        foreach (array_keys($environment) as $flag) if (str_starts_with($flag, 'APP_API_V2_')) unset($environment[$flag]);
        $environment['DB_HOST'] = '127.0.0.1;port=9';
        if ($disabled !== null) $environment[$disabled] = 'false';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, $environment);
        self::assertIsResource($process); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        self::assertMatchesRegularExpression('/__STATUS__=(\\d+)$/', $output, $errors);
        preg_match('/__STATUS__=(\\d+)$/', $output, $matches);
        return (int) $matches[1];
    }
}
