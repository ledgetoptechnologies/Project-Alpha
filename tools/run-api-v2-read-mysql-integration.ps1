param([string]$Php = 'php')
$ErrorActionPreference = 'Stop'
$runId = [Guid]::NewGuid().ToString('N')
$containerName = "pa-api-v2-read-mysql-$runId"
$databaseName = "api_v2_read_test_$runId"
$databaseUser = 'api_v2_read_test'
$rootPassword = [Guid]::NewGuid().ToString('N')
$databasePassword = [Guid]::NewGuid().ToString('N')
$containerStarted = $false
$containerId = $null
$networkCreated = $false
$networkId = $null
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$testImage = ([string]$env:API_V2_READ_TEST_IMAGE).Trim()
$networkName = "pa-api-v2-read-mysql-$runId"
$phpunit = $null
if (-not $testImage) {
    $phpunit = Join-Path $repositoryRoot 'vendor/bin/phpunit'
    if (-not (Test-Path $phpunit)) { throw 'PHPUnit is unavailable. Provide the reviewed vendor cache or API_V2_READ_TEST_IMAGE.' }
}

try {
    $existing = docker ps -a --filter "name=^/$containerName$" --format '{{.Names}}'
    if ($LASTEXITCODE -ne 0) { throw 'Could not verify disposable container absence.' }
    if ($existing) { throw "Refusing to reuse existing container $containerName." }
    if ($testImage) {
        docker image inspect $testImage *> $null
        if ($LASTEXITCODE -ne 0) { throw "API_V2_READ_TEST_IMAGE is unavailable locally: $testImage" }
        $networkId = docker network create $networkName
        if ($LASTEXITCODE -ne 0 -or -not $networkId) { throw "Failed to create disposable network $networkName." }
        $networkCreated = $true
        $actualNetworkId = docker network inspect --format '{{.Id}}' $networkName
        if ($LASTEXITCODE -ne 0) { throw 'Disposable network ID verification failed.' }
        $actualNetworkName = docker network inspect --format '{{.Name}}' $networkName
        if ($LASTEXITCODE -ne 0 -or $actualNetworkId -ne $networkId -or $actualNetworkName -ne $networkName) { throw 'Disposable network identity verification failed.' }
    }
    $mysqlArgs=@('run','--pull','never','--rm','--detach','--name',$containerName)
    if ($testImage) { $mysqlArgs+=@('--network',$networkName) } else { $mysqlArgs+=@('--publish','127.0.0.1::3306') }
    $mysqlArgs+=@('--env',"MYSQL_ROOT_PASSWORD=$rootPassword",'--env',"MYSQL_DATABASE=$databaseName",'--env',"MYSQL_USER=$databaseUser",'--env',"MYSQL_PASSWORD=$databasePassword",'mysql:8.4')
    $containerId = docker @mysqlArgs
    if ($LASTEXITCODE -ne 0 -or -not $containerId) { throw 'Failed to start disposable MySQL.' }
    $containerStarted = $true
    $actualId = docker inspect --format '{{.Id}}' $containerName
    if ($LASTEXITCODE -ne 0) { throw 'Disposable container ID verification failed.' }
    $actualName = docker inspect --format '{{.Name}}' $containerName
    if ($LASTEXITCODE -ne 0 -or $actualId -ne $containerId -or $actualName -ne "/$containerName") { throw 'Disposable container identity verification failed.' }
    $databaseHost=$containerName;$databasePort='3306'
    if (-not $testImage) {
        $databaseHost='127.0.0.1';$portOutput = docker port $containerName '3306/tcp'
        if ($LASTEXITCODE -ne 0 -or $portOutput -notmatch '127\.0\.0\.1:(\d+)$') { throw "Disposable MySQL is not localhost-only: $portOutput" }
        $databasePort = $Matches[1]
    }
    $ready = $false
    for ($attempt=0; $attempt -lt 45; $attempt++) {
        if ((docker inspect --format '{{.State.Running}}' $containerName 2>$null) -ne 'true') { break }
        docker exec --env "MYSQL_PWD=$rootPassword" $containerName mysqladmin ping --host=127.0.0.1 --user=root --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'Disposable MySQL did not become ready.' }
    $dsn="mysql:host=$databaseHost;port=$databasePort;dbname=$databaseName;charset=utf8mb4"
    if ($testImage) {
        docker run --pull never --rm --network $networkName --env "API_V2_READ_MYSQL_DSN=$dsn" --env "API_V2_READ_MYSQL_USER=$databaseUser" --env "API_V2_READ_MYSQL_PASSWORD=$databasePassword" --env "API_V2_READ_MYSQL_DATABASE=$databaseName" --env 'API_V2_READ_MYSQL_ALLOW_DESTRUCTIVE=isolated-disposable-only' $testImage php vendor/bin/phpunit tests/Integration/ApiV2CatalogProjectReadMySqlTest.php --do-not-cache-result --colors=never --fail-on-skipped
    } else {
        $env:API_V2_READ_MYSQL_DSN=$dsn;$env:API_V2_READ_MYSQL_USER=$databaseUser;$env:API_V2_READ_MYSQL_PASSWORD=$databasePassword;$env:API_V2_READ_MYSQL_DATABASE=$databaseName;$env:API_V2_READ_MYSQL_ALLOW_DESTRUCTIVE='isolated-disposable-only'
        & $Php $phpunit (Join-Path $repositoryRoot 'tests/Integration/ApiV2CatalogProjectReadMySqlTest.php') --do-not-cache-result --colors=never --fail-on-skipped
    }
    if ($LASTEXITCODE -ne 0) { throw "API v2 read MySQL tests failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item Env:API_V2_READ_MYSQL_DSN -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_READ_MYSQL_USER -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_READ_MYSQL_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_READ_MYSQL_DATABASE -ErrorAction SilentlyContinue
    Remove-Item Env:API_V2_READ_MYSQL_ALLOW_DESTRUCTIVE -ErrorAction SilentlyContinue
    if ($containerStarted) {
        $cleanupId = docker inspect --format '{{.Id}}' $containerName 2>$null
        $cleanupName = docker inspect --format '{{.Name}}' $containerName 2>$null
        if ($LASTEXITCODE -ne 0 -or $cleanupId -ne $containerId -or $cleanupName -ne "/$containerName") { throw 'Refusing cleanup because disposable container identity changed.' }
        docker rm --force $containerName 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Disposable container cleanup failed.' }
    }
    if ($networkCreated) {
        $cleanupNetworkId=docker network inspect --format '{{.Id}}' $networkName 2>$null
        $cleanupNetworkName=docker network inspect --format '{{.Name}}' $networkName 2>$null
        if ($LASTEXITCODE -ne 0 -or $cleanupNetworkId -ne $networkId -or $cleanupNetworkName -ne $networkName) { throw 'Refusing cleanup because disposable network identity changed.' }
        docker network rm $networkName 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Disposable network cleanup failed.' }
    }
}
