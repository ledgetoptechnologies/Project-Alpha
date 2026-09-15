$ErrorActionPreference = 'Stop'
$runId = [Guid]::NewGuid().ToString('N')
$containerName = "pa-api-v2-project-mysql-test-$runId"
$databaseName = "api_v2_project_test_$runId"
$databaseUser = 'api_v2_project'
$rootPassword = [Guid]::NewGuid().ToString('N')
$databasePassword = [Guid]::NewGuid().ToString('N')
$containerStarted = $false
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$phpunit = Join-Path $repositoryRoot 'vendor/bin/phpunit'
if (-not (Test-Path $phpunit) -and $env:PA_TEST_VENDOR_AUTOLOAD) { $phpunit = Join-Path (Split-Path -Parent $env:PA_TEST_VENDOR_AUTOLOAD) 'bin/phpunit' }
if (-not (Test-Path $phpunit)) { throw 'PHPUnit is unavailable. Run composer install or set PA_TEST_VENDOR_AUTOLOAD.' }
try {
    if (docker ps -a --filter "name=^/$containerName$" --format '{{.Names}}') { throw "Refusing to reuse existing container $containerName." }
    $containerId = docker run --detach --name $containerName --publish '127.0.0.1::3306' --env "MYSQL_ROOT_PASSWORD=$rootPassword" --env "MYSQL_DATABASE=$databaseName" --env "MYSQL_USER=$databaseUser" --env "MYSQL_PASSWORD=$databasePassword" mysql:8.4
    if ($LASTEXITCODE -ne 0 -or -not $containerId) { throw 'Failed to start disposable MySQL.' }
    $containerStarted = $true
    $portOutput = docker port $containerName '3306/tcp'
    if ($LASTEXITCODE -ne 0 -or $portOutput -notmatch '127\.0\.0\.1:(\d+)$') { throw "Could not determine disposable MySQL port: $portOutput" }
    $databasePort = $Matches[1]
    $ready = $false
    for ($attempt=0; $attempt -lt 45; $attempt++) {
        if ((docker inspect --format '{{.State.Running}}' $containerName 2>$null) -ne 'true') { break }
        docker exec --env "MYSQL_PWD=$rootPassword" $containerName mysqladmin ping --host=127.0.0.1 --user=root --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) {
        $ErrorActionPreference = 'Continue'
        $containerLogs = docker logs $containerName 2>&1 | Out-String
        $ErrorActionPreference = 'Stop'
        throw "Disposable MySQL did not become ready: $containerLogs"
    }
    docker exec --env "MYSQL_PWD=$rootPassword" $containerName mysql --host=127.0.0.1 --user=root --execute 'SET GLOBAL log_bin_trust_function_creators = 1'
    if ($LASTEXITCODE -ne 0) { throw 'Could not enable trigger creation in disposable MySQL.' }
    $env:API_V2_PROJECT_MYSQL_DSN="mysql:host=127.0.0.1;port=$databasePort;dbname=$databaseName;charset=utf8mb4"
    $env:API_V2_PROJECT_MYSQL_USER=$databaseUser
    $env:API_V2_PROJECT_MYSQL_PASSWORD=$databasePassword
    $env:API_V2_PROJECT_MYSQL_DATABASE=$databaseName
    $env:API_V2_PROJECT_MYSQL_ALLOW_DESTRUCTIVE='isolated-disposable-only'
    & php $phpunit (Join-Path $repositoryRoot 'tests/Integration/ApiV2ProjectLifecycleMySqlTest.php') --do-not-cache-result --colors=never --fail-on-skipped
    if ($LASTEXITCODE -ne 0) { throw "Project lifecycle MySQL tests failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item Env:API_V2_PROJECT_MYSQL_DSN -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_PROJECT_MYSQL_USER -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_PROJECT_MYSQL_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_PROJECT_MYSQL_DATABASE -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_PROJECT_MYSQL_ALLOW_DESTRUCTIVE -ErrorAction SilentlyContinue
    if ($containerStarted) { docker rm --force $containerName 2>&1 | Out-Null }
}
