param([string]$Filter = '')

$ErrorActionPreference = 'Stop'
$runId = [Guid]::NewGuid().ToString('N')
# Docker exposes the container name as a DNS label on the disposable network.
# Keep the UUID-suffixed label at or below the RFC 1123 63-character limit so
# the PHP test container can resolve the isolated MySQL host.
$containerName = "pa-dir-cutover-mysql-$runId"
$networkName = "pa-dir-cutover-mysql-$runId"
$databaseName = "directory_cutover_gate_test_$runId"
$databaseUser = 'directory_cutover_gate'
$rootPassword = [Guid]::NewGuid().ToString('N')
$databasePassword = [Guid]::NewGuid().ToString('N')
$containerStarted = $false
$networkCreated = $false
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$testImage = ([string]$env:DIRECTORY_CUTOVER_GATE_MYSQL_TEST_IMAGE).Trim()
$phpunit = $null
if (-not $testImage) {
    $phpunit = Join-Path $repositoryRoot 'vendor/bin/phpunit'
    if (-not (Test-Path $phpunit) -and $env:PA_TEST_VENDOR_AUTOLOAD) { $phpunit = Join-Path (Split-Path -Parent $env:PA_TEST_VENDOR_AUTOLOAD) 'bin/phpunit' }
    if (-not (Test-Path $phpunit)) { throw 'PHPUnit is unavailable. Run composer install, set PA_TEST_VENDOR_AUTOLOAD, or set DIRECTORY_CUTOVER_GATE_MYSQL_TEST_IMAGE to an already-built test image.' }
}
try {
    if (docker ps -a --filter "name=^/$containerName$" --format '{{.Names}}') { throw "Refusing to reuse existing container $containerName." }
    if ($testImage) {
        docker image inspect $testImage *> $null
        if ($LASTEXITCODE -ne 0) { throw "DIRECTORY_CUTOVER_GATE_MYSQL_TEST_IMAGE is unavailable locally: $testImage" }
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
    for ($attempt = 0; $attempt -lt 45; $attempt++) {
        if ((docker inspect --format '{{.State.Running}}' $containerName 2>$null) -ne 'true') { break }
        docker exec --env "MYSQL_PWD=$rootPassword" $containerName mysqladmin ping --host=127.0.0.1 --user=root --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) {
        $ErrorActionPreference = 'Continue'; $containerLogs = docker logs $containerName 2>&1 | Out-String; $ErrorActionPreference = 'Stop'
        throw "Disposable MySQL did not become ready: $containerLogs"
    }
    $dsn = "mysql:host=$databaseHost;port=$databasePort;dbname=$databaseName;charset=utf8mb4"
    if ($testImage) {
        $phpunitArgs = @('vendor/bin/phpunit', 'tests/Integration/DirectoryCutoverGateMySqlTest.php', '--do-not-cache-result', '--colors=never', '--fail-on-skipped')
        if ($Filter) { $phpunitArgs += @('--filter', $Filter) }
        docker run --rm --network $networkName --env "DIRECTORY_CUTOVER_GATE_MYSQL_DSN=$dsn" --env "DIRECTORY_CUTOVER_GATE_MYSQL_USER=$databaseUser" --env "DIRECTORY_CUTOVER_GATE_MYSQL_PASSWORD=$databasePassword" --env "DIRECTORY_CUTOVER_GATE_MYSQL_DATABASE=$databaseName" --env 'DIRECTORY_CUTOVER_GATE_MYSQL_ALLOW_DESTRUCTIVE=isolated-disposable-only' $testImage php @phpunitArgs
    } else {
        $env:DIRECTORY_CUTOVER_GATE_MYSQL_DSN = $dsn; $env:DIRECTORY_CUTOVER_GATE_MYSQL_USER = $databaseUser; $env:DIRECTORY_CUTOVER_GATE_MYSQL_PASSWORD = $databasePassword; $env:DIRECTORY_CUTOVER_GATE_MYSQL_DATABASE = $databaseName; $env:DIRECTORY_CUTOVER_GATE_MYSQL_ALLOW_DESTRUCTIVE = 'isolated-disposable-only'
        $phpunitArgs = @((Join-Path $repositoryRoot 'tests/Integration/DirectoryCutoverGateMySqlTest.php'), '--do-not-cache-result', '--colors=never', '--fail-on-skipped')
        if ($Filter) { $phpunitArgs += @('--filter', $Filter) }
        & php $phpunit @phpunitArgs
    }
    if ($LASTEXITCODE -ne 0) { throw "Directory cutover MySQL acceptance failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item Env:DIRECTORY_CUTOVER_GATE_MYSQL_DSN -ErrorAction SilentlyContinue; Remove-Item Env:DIRECTORY_CUTOVER_GATE_MYSQL_USER -ErrorAction SilentlyContinue; Remove-Item Env:DIRECTORY_CUTOVER_GATE_MYSQL_PASSWORD -ErrorAction SilentlyContinue; Remove-Item Env:DIRECTORY_CUTOVER_GATE_MYSQL_DATABASE -ErrorAction SilentlyContinue; Remove-Item Env:DIRECTORY_CUTOVER_GATE_MYSQL_ALLOW_DESTRUCTIVE -ErrorAction SilentlyContinue
    if ($containerStarted) { docker rm --force $containerName 2>&1 | Out-Null }
    if ($networkCreated) { docker network rm $networkName 2>&1 | Out-Null }
}
