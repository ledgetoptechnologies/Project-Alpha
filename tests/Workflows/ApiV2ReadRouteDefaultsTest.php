<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiV2ReadRouteDefaultsTest extends TestCase
{
    private const READ_FLAGS = [
        'APP_API_V2_DIRECTORY_READ_ENABLED',
        'APP_API_V2_BINDING_STATUS_ENABLED',
        'APP_API_V2_DIRECTORY_INVENTORY_ENABLED',
        'APP_API_V2_PROJECTS_READ_ENABLED',
        'APP_API_V2_PROJECTS_BINDING_STATUS_ENABLED',
        'APP_API_V2_PROJECTS_INVENTORY_ENABLED',
    ];

    private const MUTATION_FLAGS = [
        'APP_API_V2_PROJECTS_ADOPTION_CANDIDATES_ENABLED',
    ];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
    }

    public function testReadRoutesDefaultOnAndAllowExplicitFalseOverride(): void
    {
        foreach (self::READ_FLAGS as $flag) {
            $previous = getenv($flag);
            try {
                putenv($flag);
                self::assertTrue(api_v2_enabled($flag), $flag . ' should default on.');
                putenv($flag . '=false');
                self::assertFalse(api_v2_enabled($flag), $flag . ' should honor explicit false.');
                putenv($flag . '=true');
                self::assertTrue(api_v2_enabled($flag), $flag . ' should honor explicit true.');
            } finally {
                $previous === false ? putenv($flag) : putenv($flag . '=' . $previous);
            }
        }
    }

    public function testMutationRoutesRemainDefaultOff(): void
    {
        foreach (self::MUTATION_FLAGS as $flag) {
            $previous = getenv($flag);
            try {
                putenv($flag);
                self::assertFalse(api_v2_enabled($flag), $flag . ' must remain default-off.');
            } finally {
                $previous === false ? putenv($flag) : putenv($flag . '=' . $previous);
            }
        }
    }

    public function testRouterAndCapabilityControllerUseTheSharedDefaults(): void
    {
        $root = dirname(__DIR__, 2);
        $router = (string) file_get_contents($root . '/public/index.php');
        $capabilities = (string) file_get_contents($root . '/src/controllers/api/capabilities_v2.php');

        foreach (self::READ_FLAGS as $flag) {
            self::assertStringContainsString("api_v2_enabled('{$flag}')", $router);
            self::assertStringContainsString("api_v2_enabled('{$flag}')", $capabilities);
        }
    }

    public function testAdoptionCandidateDiscoveryIsDefaultOffAndExplicitlyAdvertised(): void
    {
        $flag='APP_API_V2_PROJECTS_ADOPTION_CANDIDATES_ENABLED';$previous=getenv($flag);
        try{putenv($flag);self::assertFalse(api_v2_enabled($flag));putenv($flag.'=true');self::assertTrue(api_v2_enabled($flag));
            $identity=['source_instance_id'=>'123e4567-e89b-42d3-a456-426614174000','application_id'=>'223e4567-e89b-42d3-a456-426614174000','history_epoch'=>'323e4567-e89b-42d3-a456-426614174000'];$payload=api_v2_capabilities_payload($identity,'423e4567-e89b-42d3-a456-426614174000',['projects.adoption_candidates.read'],['projects_adoption_candidates'=>true]);self::assertContains('projects.adoption_candidates.read',array_column($payload['grantedCapabilities'],'name'));self::assertContains('/api/v2/projects/adoption-candidates',array_column($payload['implementedEndpoints'],'path'));
            [$disabledStatus]=$this->runRoute('/api/v2/projects/adoption-candidates?limit=1',$flag.'=false');self::assertSame(404,$disabledStatus);
            [$enabledStatus]=$this->runRoute('/api/v2/projects/adoption-candidates?limit=1',$flag.'=true');self::assertSame(503,$enabledStatus);
            foreach(['/api/v2/projects/adoption-candidates?limit=0','/api/v2/projects/adoption-candidates?limit=201','/api/v2/projects/adoption-candidates?limit=999999999999999999999999','/api/v2/projects/adoption-candidates?limit=1x','/api/v2/projects/adoption-candidates?cursor=bad','/api/v2/projects/adoption-candidates?unknown=1','/api/v2/projects/adoption-candidates?limit=1&limit=2','/api/v2/projects/adoption-candidates?%6cimit=1&limit=2','/api/v2/projects/adoption-candidates?limit%5B%5D=1','/api/v2/projects/adoption-candidates?cursor%5Bvalue%5D=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']as$invalid){[$status]=$this->runRoute($invalid,$flag.'=true');self::assertSame(400,$status);}
            [$methodStatus]=$this->runRoute('/api/v2/projects/adoption-candidates?limit=1',$flag.'=true','POST');self::assertSame(405,$methodStatus);
        }finally{$previous===false?putenv($flag):putenv($flag.'='.$previous);}
    }

    public function testReadRoutesPassTheFeatureGateByDefaultAndHonorExplicitFalse(): void
    {
        foreach ($this->readRoutes() as $route) {
            [$defaultStatus, $defaultOutput, $defaultErrors] = $this->runRoute($route['path']);
            self::assertSame(503, $defaultStatus, $route['flag'] . ' should reach its controller when unset. ' . $defaultOutput . $defaultErrors);

            [$disabledStatus, $disabledOutput, $disabledErrors] = $this->runRoute($route['path'], $route['flag'] . '=false');
            self::assertSame(404, $disabledStatus, $route['flag'] . ' should return the feature-gate 404 when false. ' . $disabledOutput . $disabledErrors);
        }
    }

    public function testExplicitFalseSuppressesReadFeaturesFromCapabilities(): void
    {
        $identity = [
            'source_instance_id' => '123e4567-e89b-42d3-a456-426614174000',
            'application_id' => '223e4567-e89b-42d3-a456-426614174000',
            'history_epoch' => '323e4567-e89b-42d3-a456-426614174000',
        ];
        foreach ($this->readRoutes() as $route) {
            $previous = getenv($route['flag']);
            try {
                putenv($route['flag'] . '=false');
                $payload = api_v2_capabilities_payload($identity, '423e4567-e89b-42d3-a456-426614174000', [$route['scope']], [
                    $route['feature'] => api_v2_enabled($route['flag']),
                ]);
                self::assertNotContains($route['scope'], array_column($payload['grantedCapabilities'], 'name'));
                self::assertNotContains($route['pathWithoutQuery'], array_column($payload['implementedEndpoints'], 'path'));
            } finally {
                $previous === false ? putenv($route['flag']) : putenv($route['flag'] . '=' . $previous);
            }
        }
    }

    public function testComposeDoesNotRequireReadRouteFlags(): void
    {
        $root = dirname(__DIR__, 2);
        $compose = (string) file_get_contents($root . '/docker-compose.yml');
        $example = (string) file_get_contents($root . '/config/.env.example');

        foreach (self::READ_FLAGS as $flag) {
            self::assertStringNotContainsString($flag . ':', $compose);
            self::assertDoesNotMatchRegularExpression('/^' . preg_quote($flag, '/') . '=/m', $example);
            self::assertStringContainsString('# ' . $flag . '=false', $example);
        }
    }

    private function readRoutes(): array
    {
        return [
            ['flag' => 'APP_API_V2_DIRECTORY_READ_ENABLED', 'feature' => 'directory_read', 'scope' => 'directory.clients.read', 'path' => '/api/v2/directory/clients/' . str_repeat('a', 32), 'pathWithoutQuery' => '/api/v2/directory/clients/{publicId}'],
            ['flag' => 'APP_API_V2_BINDING_STATUS_ENABLED', 'feature' => 'binding_status', 'scope' => 'directory.clients.binding_status.read', 'path' => '/api/v2/bindings/client/status/YQ', 'pathWithoutQuery' => '/api/v2/bindings/client/status/{base64urlExternalId}'],
            ['flag' => 'APP_API_V2_DIRECTORY_INVENTORY_ENABLED', 'feature' => 'directory_inventory', 'scope' => 'directory.inventory.read', 'path' => '/api/v2/directory/inventory?limit=1', 'pathWithoutQuery' => '/api/v2/directory/inventory'],
            ['flag' => 'APP_API_V2_PROJECTS_READ_ENABLED', 'feature' => 'projects_read', 'scope' => 'projects.v2.read', 'path' => '/api/v2/projects/' . str_repeat('a', 32), 'pathWithoutQuery' => '/api/v2/projects/{publicId}'],
            ['flag' => 'APP_API_V2_PROJECTS_BINDING_STATUS_ENABLED', 'feature' => 'projects_binding_status', 'scope' => 'projects.binding_status.read', 'path' => '/api/v2/projects/bindings/status/YQ', 'pathWithoutQuery' => '/api/v2/projects/bindings/status/{base64urlExternalId}'],
            ['flag' => 'APP_API_V2_PROJECTS_INVENTORY_ENABLED', 'feature' => 'projects_inventory', 'scope' => 'projects.inventory.read', 'path' => '/api/v2/projects/inventory?limit=1', 'pathWithoutQuery' => '/api/v2/projects/inventory'],
        ];
    }

    private function runRoute(string $path, ?string $setting = null, string $method = 'GET'): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for front-controller gate coverage.');
        }

        $root = dirname(__DIR__, 2);
        $script = '$_SERVER=["REQUEST_URI"=>' . var_export($path, true) . ',"REQUEST_METHOD"=>' . var_export($method, true) . '];'
            . 'register_shutdown_function(static function():void{fwrite(STDOUT,"\\n__STATUS__=".http_response_code());});'
            . 'require ' . var_export($root . '/public/index.php', true) . ';';
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $environment['DB_HOST'] = '127.0.0.1;port=9';
        foreach (self::READ_FLAGS as $flag) {
            unset($environment[$flag]);
        }
        if ($setting !== null) {
            [$name, $value] = explode('=', $setting, 2);
            $environment[$name] = $value;
        }

        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $root, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertMatchesRegularExpression('/__STATUS__=(\d+)$/', $output);
        preg_match('/__STATUS__=(\d+)$/', $output, $match);
        return [(int) $match[1], $output, $errors];
    }
}
