param(
    [Parameter(Mandatory = $true)]
    [string[]] $TestPaths,
    [string] $Filter,
    [switch] $PreflightOnly,
    [switch] $Diagnostics
)

$ErrorActionPreference = 'Stop'
$itRepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../../..')).Path
$itPhpBinary = 'C:\Users\steph\.config\herd\bin\php84\php.exe'
$itPreviousLocation = Get-Location
$itPreviousEnvironment = @{}

# Only bounded IT test files are accepted; callers cannot override PHPUnit's
# configuration/bootstrap or point this wrapper at a different test harness.
foreach ($itTestPath in $TestPaths) {
    $itResolvedTest = (Resolve-Path -LiteralPath (Join-Path $itRepoRoot $itTestPath)).Path
    $itTestRoot = Join-Path $itRepoRoot 'tests'
    if (!$itResolvedTest.StartsWith($itTestRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or
        ![IO.File]::Exists($itResolvedTest) -or
        [IO.Path]::GetExtension($itResolvedTest) -ne '.php') {
        throw 'Only existing PHP test files inside this checkout are accepted.'
    }
}

[xml] $itPhpunit = Get-Content -LiteralPath (Join-Path $itRepoRoot 'phpunit.xml') -Raw
$itEnvironment = @{}
foreach ($itSetting in $itPhpunit.phpunit.php.env) {
    $itEnvironment[[string] $itSetting.name] = [string] $itSetting.value
}

$itToken = 'it_' + [Guid]::NewGuid().ToString('N').Substring(0, 16)
$itEnvironment['APP_ENV'] = 'testing'
$itEnvironment['DB_CONNECTION'] = 'mysql'
$itEnvironment['DB_HOST'] = '127.0.0.1'
$itEnvironment['DB_PORT'] = '3306'
$itEnvironment['DB_DATABASE'] = 'oblivion_it_support_test'
$itEnvironment['DB_URL'] = 'null'
$itEnvironment['DB_SOCKET'] = ''
$itEnvironment['TEST_TOKEN'] = $itToken
$itEnvironment['APP_CONFIG_CACHE'] = Join-Path $itRepoRoot "bootstrap/cache/$itToken.no-config-cache.php"
$itEnvironment['MAIL_MAILER'] = 'array'
$itEnvironment['QUEUE_CONNECTION'] = 'sync'
$itEnvironment['BROADCAST_CONNECTION'] = 'null'
$itEnvironment['CACHE_STORE'] = 'array'
$itEnvironment['SESSION_DRIVER'] = 'array'
$itEnvironment['MYSQL_TEST_SCHEMA_PATH'] = Join-Path $itRepoRoot 'database/schema/mysql-schema.sql'
if ($Diagnostics) {
    $itEnvironment['IT_TEST_DIAGNOSTIC_PATH'] = Join-Path $PSScriptRoot "$itToken.diagnostic.jsonl"
}

try {
    Set-Location -LiteralPath $itRepoRoot
    foreach ($itName in $itEnvironment.Keys) {
        $itPreviousEnvironment[$itName] = [Environment]::GetEnvironmentVariable($itName, 'Process')
        [Environment]::SetEnvironmentVariable($itName, $itEnvironment[$itName], 'Process')
    }

    & $itPhpBinary (Join-Path $PSScriptRoot 'isolated-test-preflight.php')
    if ($LASTEXITCODE -ne 0) { throw 'The read-only isolation preflight failed.' }
    if ($PreflightOnly) { return }

    # A failed database bootstrap must not retry a full schema import for every
    # subsequent test. Ordinary assertion failures still run the selected suite.
    $itPestArguments = @('vendor/bin/pest', '--stop-on-error') + $TestPaths
    if ($Filter) { $itPestArguments += @('--filter', $Filter) }
    if ($Diagnostics) {
        # Metadata only. The prepend neither boots Laravel nor changes the
        # configured memory limit, test arguments, database or notification sinks.
        $itDiagnosticBootstrap = Join-Path $PSScriptRoot 'isolated-test-diagnostic.php'
        @{ phase = 'diagnostic_enabled'; path = $itEnvironment['IT_TEST_DIAGNOSTIC_PATH'] } | ConvertTo-Json -Compress
        & $itPhpBinary '-d' "auto_prepend_file=$itDiagnosticBootstrap" @itPestArguments
    } else {
        & $itPhpBinary @itPestArguments
    }
    $itTestExit = $LASTEXITCODE
    @{ phase = 'focused_tests_finished'; exit_code = $itTestExit } | ConvertTo-Json -Compress
    # TestCase's shutdown cleanup is best-effort. Verify this exact owned
    # schema is absent before claiming a successful isolated run; this
    # preflight only reads information_schema and never drops anything.
    & $itPhpBinary (Join-Path $PSScriptRoot 'isolated-test-preflight.php')
    if ($LASTEXITCODE -ne 0) { throw 'The read-only post-test isolation/cleanup check failed.' }
    if ($itTestExit -ne 0) { throw "Focused Pest run failed with exit code $itTestExit." }
} finally {
    foreach ($itName in $itPreviousEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($itName, $itPreviousEnvironment[$itName], 'Process')
    }
    Set-Location -LiteralPath $itPreviousLocation.Path
}
