$ErrorActionPreference = 'Stop'
$runId = [Guid]::NewGuid().ToString('N')
$containerName = "pa-api-v2-directory-mysql-$runId"
$networkName = "pa-api-v2-directory-mysql-$runId"
$databaseName = "api_v2_create_test_$runId"
$databaseUser = 'api_v2_directory_create'
$rootPassword = [Guid]::NewGuid().ToString('N')
$databasePassword = [Guid]::NewGuid().ToString('N')
$containerStarted = $false
$networkCreated = $false
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$testImage = ([string]$env:API_V2_DIRECTORY_CREATE_TEST_IMAGE).Trim()
$phpunit = $null
if (-not $testImage) {
    $phpunit = Join-Path $repositoryRoot 'vendor/bin/phpunit'
    if (-not (Test-Path $phpunit) -and $env:PA_TEST_VENDOR_AUTOLOAD) { $phpunit = Join-Path (Split-Path -Parent $env:PA_TEST_VENDOR_AUTOLOAD) 'bin/phpunit' }
    if (-not (Test-Path $phpunit)) { throw 'PHPUnit is unavailable. Run composer install, set PA_TEST_VENDOR_AUTOLOAD, or set API_V2_DIRECTORY_CREATE_TEST_IMAGE to the already-built test image.' }
}
try {
    if (docker ps -a --filter "name=^/$containerName$" --format '{{.Names}}') { throw "Refusing to reuse existing container $containerName." }
    if ($testImage) {
        docker image inspect $testImage *> $null
        if ($LASTEXITCODE -ne 0) { throw "API_V2_DIRECTORY_CREATE_TEST_IMAGE is unavailable locally: $testImage" }
        docker network create $networkName *> $null
        if ($LASTEXITCODE -ne 0) { throw "Failed to create disposable Docker network $networkName." }
        $networkCreated = $true
    }
    $mysqlArgs = @('run', '--rm', '--detach', '--name', $containerName)
    if ($testImage) { $mysqlArgs += @('--network', $networkName) } else { $mysqlArgs += @('--publish', '127.0.0.1::3306') }
    $mysqlArgs += @('--env', "MYSQL_ROOT_PASSWORD=$rootPassword", '--env', "MYSQL_DATABASE=$databaseName", '--env', "MYSQL_USER=$databaseUser", '--env', "MYSQL_PASSWORD=$databasePassword", 'mysql:8.4')
    $containerId = docker @mysqlArgs
    if ($LASTEXITCODE -ne 0 -or -not $containerId) { throw 'Failed to start disposable MySQL.' }
    $containerStarted = $true
    $databaseHost = '127.0.0.1'; $databasePort = '3306'
    if ($testImage) { $databaseHost = $containerName } else {
        $portOutput = docker port $containerName '3306/tcp'
        if ($LASTEXITCODE -ne 0 -or $portOutput -notmatch '127\.0\.0\.1:(\d+)$') { throw "Could not determine disposable MySQL port: $portOutput" }
        $databasePort = $Matches[1]
    }
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
    $dsn="mysql:host=$databaseHost;port=$databasePort;dbname=$databaseName;charset=utf8mb4"
    if ($testImage) {
        docker run --rm --network $networkName --env "API_V2_DIRECTORY_CREATE_MYSQL_DSN=$dsn" --env "API_V2_DIRECTORY_CREATE_MYSQL_USER=$databaseUser" --env "API_V2_DIRECTORY_CREATE_MYSQL_PASSWORD=$databasePassword" --env "API_V2_DIRECTORY_CREATE_MYSQL_DATABASE=$databaseName" --env 'API_V2_DIRECTORY_CREATE_MYSQL_ALLOW_DESTRUCTIVE=isolated-disposable-only' $testImage php vendor/bin/phpunit tests/Integration/ApiV2DirectoryCreateCommandMySqlTest.php --do-not-cache-result --colors=never --fail-on-skipped
    } else {
        $env:API_V2_DIRECTORY_CREATE_MYSQL_DSN=$dsn; $env:API_V2_DIRECTORY_CREATE_MYSQL_USER=$databaseUser; $env:API_V2_DIRECTORY_CREATE_MYSQL_PASSWORD=$databasePassword; $env:API_V2_DIRECTORY_CREATE_MYSQL_DATABASE=$databaseName; $env:API_V2_DIRECTORY_CREATE_MYSQL_ALLOW_DESTRUCTIVE='isolated-disposable-only'
        & php $phpunit (Join-Path $repositoryRoot 'tests/Integration/ApiV2DirectoryCreateCommandMySqlTest.php') --do-not-cache-result --colors=never --fail-on-skipped
    }
    if ($LASTEXITCODE -ne 0) { throw "Directory create MySQL tests failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item Env:API_V2_DIRECTORY_CREATE_MYSQL_DSN -ErrorAction SilentlyContinue; Remove-Item Env:API_V2_DIRECTORY_CREATE_MYSQL_USER -ErrorAction SilentlyContinue; Remove-Item Env:API_V2_DIRECTORY_CREATE_MYSQL_PASSWORD -ErrorAction SilentlyContinue; Remove-Item Env:API_V2_DIRECTORY_CREATE_MYSQL_DATABASE -ErrorAction SilentlyContinue; Remove-Item Env:API_V2_DIRECTORY_CREATE_MYSQL_ALLOW_DESTRUCTIVE -ErrorAction SilentlyContinue
    if ($containerStarted) { docker rm --force $containerName 2>&1 | Out-Null }
    if ($networkCreated) { docker network rm $networkName 2>&1 | Out-Null }
}
