param(
    [ValidateSet('Preview', 'CreateAndStart', 'StopAndRemove')]
    [string] $Mode = 'Preview',
    [ValidatePattern('^[a-f0-9]{16}$')]
    [string] $Token,
    [ValidatePattern('^[a-f0-9]{64}$')]
    [string] $ExpectedFingerprint,
    [switch] $MailboxFixtures,
    [switch] $ApiFixtures
)

$ErrorActionPreference = 'Stop'
$itBrowserRepo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../../..')).Path
$itBrowserPhp = 'C:\Users\steph\.config\herd\bin\php84\php.exe'
if ($itBrowserRepo -ine 'C:\Users\steph\Herd\oblivionfindings') { throw 'Unexpected checkout.' }
$itBrowserTesting = Join-Path $itBrowserRepo 'storage/framework/testing'
$itBrowserFiles = @('w06-draft-browser-environment.ps1', 'w06-draft-browser-runtime.php', 'w06-draft-browser-bootstrap.php',
    'w06-draft-browser-router.php', 'w06-draft-browser-fixtures.php', 'w06-draft-browser-teardown.php', 'w11-mailbox-browser-fixture.php', 'w11-merge-browser-scenario.php', 'w12-delivery-browser-fixture.php', 'w13-api-browser-fixture.php')
$itBrowserSources = [ordered] @{}
foreach ($itBrowserFile in $itBrowserFiles) {
    $itBrowserSources[$itBrowserFile] = (Get-FileHash -LiteralPath (Join-Path $PSScriptRoot $itBrowserFile) -Algorithm SHA256).Hash.ToLowerInvariant()
}
$itBrowserMigrations = [ordered] @{}
Get-ChildItem -LiteralPath (Join-Path $itBrowserRepo 'database/migrations') -Filter '*.php' -File | Sort-Object Name | ForEach-Object {
    $itBrowserMigrations[$_.Name] = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}
$itBrowserSchemaHash = (Get-FileHash -LiteralPath (Join-Path $itBrowserRepo 'database/schema/mysql-schema.sql') -Algorithm SHA256).Hash.ToLowerInvariant()
$itBrowserAssetHash = (Get-FileHash -LiteralPath (Join-Path $itBrowserRepo 'public/build/manifest.json') -Algorithm SHA256).Hash.ToLowerInvariant()
$itBrowserReview = [ordered] @{
    checkout = $itBrowserRepo; source_hashes = $itBrowserSources; migration_hashes = $itBrowserMigrations
    schema_sha256 = $itBrowserSchemaHash; asset_manifest_sha256 = $itBrowserAssetHash
    host = '127.0.0.1'; port = 8766; database_prefix = 'oblivion_it_draft_browser_'
    app_environment = 'local'; csrf_testing_bypass = $false; mail = 'array'; queue = 'sync'
    php_upload_limits = [ordered] @{ upload_max_filesize = '16M'; post_max_size = '64M'; max_file_uploads = 20 }
    synthetic_only_retention_days = @(2, 3); existing_herd_environment_edited = $false
    mailbox_fixtures = [bool] $MailboxFixtures
    api_fixtures = [bool] $ApiFixtures
}
$itBrowserReviewBytes = [Text.Encoding]::UTF8.GetBytes(($itBrowserReview | ConvertTo-Json -Depth 8 -Compress))
$itBrowserSha = [Security.Cryptography.SHA256]::Create()
try { $itBrowserFingerprint = [Convert]::ToHexString($itBrowserSha.ComputeHash($itBrowserReviewBytes)).ToLowerInvariant() } finally { $itBrowserSha.Dispose() }
if ($Mode -eq 'Preview') {
    [ordered] @{ mode = 'preview'; mutations_performed = $false; approval_fingerprint = $itBrowserFingerprint; review = $itBrowserReview } | ConvertTo-Json -Depth 9
    return
}
if ($Mode -eq 'CreateAndStart' -and $ExpectedFingerprint -ne $itBrowserFingerprint) { throw 'Review fingerprint changed; obtain a fresh preview before mutation.' }
if (!$Token) { throw 'A reviewed explicit random 16-hex token is required for creation or teardown.' }
if (!(Test-Path -LiteralPath $itBrowserTesting -PathType Container)) { throw 'Canonical testing parent must already exist.' }
$itBrowserTesting = (Resolve-Path -LiteralPath $itBrowserTesting).Path
$itBrowserRoot = Join-Path $itBrowserTesting ('it-draft-browser-' + $Token)
$itBrowserDatabase = 'oblivion_it_draft_browser_' + $Token
$itBrowserOwnerPath = Join-Path $itBrowserRoot 'owner.json'

function Assert-ItBrowserOwnedPath([string] $Path) {
    $itChecked = [IO.Path]::GetFullPath($Path)
    if ($itChecked -ine $itBrowserRoot -and !$itChecked.StartsWith($itBrowserRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Path is outside the exact owned token directory.'
    }
    $itCursor = $itChecked
    while ($itCursor.Length -ge $itBrowserTesting.Length) {
        if (Test-Path -LiteralPath $itCursor) {
            $itItem = Get-Item -LiteralPath $itCursor -Force
            if (($itItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Linked/junction path refused.' }
        }
        if ($itCursor -ieq $itBrowserTesting) { break }
        $itCursor = [IO.Path]::GetDirectoryName($itCursor)
    }
}

function Write-ItBrowserNewJson([string] $Path, $Value) {
    $itJsonBytes = [Text.UTF8Encoding]::new($false).GetBytes(($Value | ConvertTo-Json -Depth 12) + [Environment]::NewLine)
    $itStream = [IO.File]::Open($Path, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::Read)
    try { $itStream.Write($itJsonBytes, 0, $itJsonBytes.Length); $itStream.Flush($true) } finally { $itStream.Dispose() }
}

Assert-ItBrowserOwnedPath $itBrowserRoot
if ($Mode -eq 'CreateAndStart') {
    if (Test-Path -LiteralPath $itBrowserRoot) { throw 'Token directory already exists; no reset or reuse is permitted.' }
    if (Get-NetTCPConnection -LocalPort 8766 -State Listen -ErrorAction SilentlyContinue) { throw 'Loopback port 8766 is occupied; do not stop another process.' }
    if (Test-Path -LiteralPath (Join-Path $itBrowserRepo 'public/hot')) { throw 'A Vite hot file is active; wait for a stable reviewed build.' }
    New-Item -ItemType Directory -Path $itBrowserRoot | Out-Null
    foreach ($itRelative in @('storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'storage/bootstrap-cache')) {
        New-Item -ItemType Directory -Path (Join-Path $itBrowserRoot $itRelative) -Force | Out-Null
    }
    $itParent = Get-Process -Id $PID
    $itOwner = [ordered] @{
        token = $Token; database = $itBrowserDatabase; root = $itBrowserRoot; port = 8766
        source_hashes = $itBrowserSources; migration_hashes = $itBrowserMigrations; schema_sha256 = $itBrowserSchemaHash
        asset_manifest_sha256 = $itBrowserAssetHash; approval_fingerprint = $itBrowserFingerprint
        launcher_pid = $PID; launcher_start_ticks = $itParent.StartTime.ToUniversalTime().Ticks
        created_at = [DateTime]::UtcNow.ToString('o'); synthetic_retention_only = $true
        mailbox_fixtures = [bool] $MailboxFixtures
        api_fixtures = [bool] $ApiFixtures
    }
    Write-ItBrowserNewJson $itBrowserOwnerPath $itOwner
} else {
    if (!(Test-Path -LiteralPath $itBrowserOwnerPath -PathType Leaf)) { throw 'The exact token owner manifest is missing.' }
    $itOwner = Get-Content -LiteralPath $itBrowserOwnerPath -Raw | ConvertFrom-Json
    if ($itOwner.token -ne $Token -or $itOwner.database -ne $itBrowserDatabase -or $itOwner.root -ine $itBrowserRoot -or $itOwner.approval_fingerprint -ne $ExpectedFingerprint) {
        throw 'Stored source/token identity differs; inspect instead of inferring a cleanup target.'
    }
    # Asset/application changes must not strand a safely stoppable environment.
    # Teardown binds the original reviewed identity and unchanged helper sources.
    foreach ($itBrowserFile in $itBrowserFiles) {
        if ($itOwner.source_hashes.$itBrowserFile -ne $itBrowserSources[$itBrowserFile]) { throw 'Verification helper source changed; review it before teardown.' }
    }
    $itOriginalLauncher = Get-Process -Id $itOwner.launcher_pid -ErrorAction SilentlyContinue
    if ($itOriginalLauncher -and $itOriginalLauncher.StartTime.ToUniversalTime().Ticks -eq $itOwner.launcher_start_ticks) {
        throw 'The creating launcher is still active; wait until schema/server setup has settled.'
    }
}

# Resolve only existing local test database access; never print credential values.
[xml] $itBrowserPhpunit = Get-Content -LiteralPath (Join-Path $itBrowserRepo 'phpunit.xml') -Raw
$itBrowserDbAccess = @{}
foreach ($itSetting in $itBrowserPhpunit.phpunit.php.env) {
    if ([string] $itSetting.name -in @('DB_USERNAME', 'DB_PASSWORD')) { $itBrowserDbAccess[[string] $itSetting.name] = [string] $itSetting.value }
}
if (!$itBrowserDbAccess.ContainsKey('DB_USERNAME') -or !$itBrowserDbAccess.ContainsKey('DB_PASSWORD')) { throw 'Existing local test database access source is incomplete.' }
$itBrowserKeyBytes = [byte[]]::new(32)
[Security.Cryptography.RandomNumberGenerator]::Fill($itBrowserKeyBytes)
$itBrowserEnv = @{
    IT_DRAFT_BROWSER_TOKEN = $Token; APP_ENV = 'local'; APP_DEBUG = 'false'; APP_URL = 'http://127.0.0.1:8766'
    APP_KEY = ('base64:' + [Convert]::ToBase64String($itBrowserKeyBytes)); LARAVEL_STORAGE_PATH = (Join-Path $itBrowserRoot 'storage')
    DB_CONNECTION = 'mysql'; DB_HOST = '127.0.0.1'; DB_PORT = '3306'; DB_DATABASE = $itBrowserDatabase; DB_URL = 'null'; DB_SOCKET = ''
    DB_USERNAME = $itBrowserDbAccess['DB_USERNAME']; DB_PASSWORD = $itBrowserDbAccess['DB_PASSWORD']; DB_EMULATE_PREPARES = 'true'
    MAIL_MAILER = 'array'; QUEUE_CONNECTION = 'sync'; BROADCAST_CONNECTION = 'null'; CACHE_STORE = 'array'
    SESSION_DRIVER = 'database'; SESSION_COOKIE = ('it_draft_browser_' + $Token); SESSION_DOMAIN = 'null'; SESSION_SECURE_COOKIE = 'false'
    IT_DRAFTS_ENABLED = 'true'; IT_DRAFT_RETENTION_DAYS = '2'; IT_DRAFT_TERMINAL_RETENTION_DAYS = '3'
    PULSE_ENABLED = 'false'; TELESCOPE_ENABLED = 'false'; NIGHTWATCH_ENABLED = 'false'; APP_MAINTENANCE_DRIVER = 'file'; BCRYPT_ROUNDS = '4'
    IT_INBOUND_MAIL_SECRET = 'null'; IT_OUTBOUND_MAIL_STATUS_SECRET = 'null'; IT_RELEASE_ACCEPTANCE_ENABLED = 'false'
    IT_MAILBOX_BROWSER_FIXTURES = $(if ($itOwner.mailbox_fixtures) { 'true' } else { 'false' })
    IT_API_BROWSER_FIXTURES = $(if ($itOwner.api_fixtures) { 'true' } else { 'false' })
    TEST_TOKEN = ''; PARALLEL_PROCESS = ''; PROCESS_TOKEN = ''
}
foreach ($itCacheName in @('CONFIG', 'ROUTES', 'EVENTS', 'SERVICES', 'PACKAGES')) {
    $itBrowserEnv['APP_' + $itCacheName + '_CACHE'] = Join-Path $itBrowserRoot ('storage/bootstrap-cache/' + $itCacheName.ToLowerInvariant() + '.php')
}
$itBrowserPrevious = @{}
$itBrowserPreviousLocation = Get-Location
try {
    foreach ($itEnvName in $itBrowserEnv.Keys) {
        $itBrowserPrevious[$itEnvName] = [Environment]::GetEnvironmentVariable($itEnvName, 'Process')
        [Environment]::SetEnvironmentVariable($itEnvName, $itBrowserEnv[$itEnvName], 'Process')
    }
    Set-Location -LiteralPath $itBrowserRepo
    if ($Mode -eq 'CreateAndStart') {
        & $itBrowserPhp (Join-Path $PSScriptRoot 'w06-draft-browser-bootstrap.php') --create-owned-schema
        if ($LASTEXITCODE -ne 0) { throw 'Isolated bootstrap failed; retained token data requires inspection, never an automatic reset.' }
        if (Get-NetTCPConnection -LocalPort 8766 -State Listen -ErrorAction SilentlyContinue) { throw 'Port became occupied during bootstrap; do not replace that listener.' }
        $itPublicArgument = '"' + (Join-Path $itBrowserRepo 'public') + '"'
        $itRouterArgument = '"' + (Join-Path $PSScriptRoot 'w06-draft-browser-router.php') + '"'
        # This process alone has headroom to exercise canonical 10 MB/five-file
        # validation. No shared PHP/Herd ini is modified.
        $itServer = Start-Process -FilePath $itBrowserPhp -ArgumentList @('-d', 'upload_max_filesize=16M', '-d', 'post_max_size=64M', '-d', 'max_file_uploads=20', '-S', '127.0.0.1:8766', '-t', $itPublicArgument, $itRouterArgument) -WorkingDirectory $itBrowserRepo -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $itBrowserRoot 'server-stdout.log') -RedirectStandardError (Join-Path $itBrowserRoot 'server-stderr.log')
        Write-ItBrowserNewJson (Join-Path $itBrowserRoot 'server-started.json') ([ordered] @{
            token = $Token; pid = $itServer.Id; start_ticks = $itServer.StartTime.ToUniversalTime().Ticks
            executable = $itBrowserPhp; router = (Join-Path $PSScriptRoot 'w06-draft-browser-router.php'); url = 'http://127.0.0.1:8766'
        })
        [ordered] @{ started = $true; token = $Token; database = $itBrowserDatabase; pid = $itServer.Id; url = 'http://127.0.0.1:8766'; root = $itBrowserRoot; browser_verified = $false } | ConvertTo-Json
    } else {
        $itStartedPath = Join-Path $itBrowserRoot 'server-started.json'
        if (Test-Path -LiteralPath $itStartedPath) {
            $itStarted = Get-Content -LiteralPath $itStartedPath -Raw | ConvertFrom-Json
            if ($itStarted.token -ne $Token -or $itStarted.router -ine (Join-Path $PSScriptRoot 'w06-draft-browser-router.php')) { throw 'Server ownership record differs.' }
            $itServer = Get-Process -Id $itStarted.pid -ErrorAction SilentlyContinue
            if ($itServer) {
                $itServerCommand = Get-CimInstance Win32_Process -Filter ('ProcessId = ' + [int] $itStarted.pid)
                if ($itServer.StartTime.ToUniversalTime().Ticks -ne $itStarted.start_ticks -or $itServer.Path -ine $itBrowserPhp -or !$itServerCommand.CommandLine.Contains($itStarted.router)) { throw 'Recorded PID is no longer the exact owned server.' }
                Stop-Process -Id $itStarted.pid
                $itServer.WaitForExit(10000) | Out-Null
                if (!$itServer.HasExited) { throw 'Owned server has not settled; no cleanup may proceed.' }
            }
        } elseif (!(Test-Path -LiteralPath (Join-Path $itBrowserRoot 'launch-failed.json'))) {
            throw 'No settled launch outcome is recorded; inspect before cleanup.'
        }
        if (Get-NetTCPConnection -LocalPort 8766 -State Listen -ErrorAction SilentlyContinue) { throw 'Port still has a listener; inspect ownership before cleanup.' }
        Write-ItBrowserNewJson (Join-Path $itBrowserRoot 'server-stopped.json') (@{ token = $Token; server_settled = $true; stopped_at = [DateTime]::UtcNow.ToString('o') })
        & $itBrowserPhp (Join-Path $PSScriptRoot 'w06-draft-browser-teardown.php') --drop-stopped-owned-schema
        if ($LASTEXITCODE -ne 0) { throw 'Exact schema removal was not confirmed; retain the token directory for recovery.' }
        Assert-ItBrowserOwnedPath $itBrowserRoot
        Get-ChildItem -LiteralPath $itBrowserRoot -Recurse -Force | ForEach-Object {
            if (($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Linked descendant prevents recursive removal.' }
            Assert-ItBrowserOwnedPath $_.FullName
        }
        $itCleanupEvidence = [ordered] @{ token = $Token; database = $itBrowserDatabase; root = $itBrowserRoot; server_settled = $true; database_removed = $true; captured_at = [DateTime]::UtcNow.ToString('o'); owner = $itOwner }
        if (Test-Path -LiteralPath (Join-Path $itBrowserRoot 'ready.json')) { $itCleanupEvidence['readiness'] = Get-Content -LiteralPath (Join-Path $itBrowserRoot 'ready.json') -Raw | ConvertFrom-Json }
        Write-ItBrowserNewJson (Join-Path $PSScriptRoot ('w06-draft-browser-cleanup-' + $Token + '.json')) $itCleanupEvidence
        # Exact token target was resolved and checked above; use native PowerShell
        # end-to-end, never pass enumerated paths to another shell for deletion.
        Remove-Item -LiteralPath $itBrowserRoot -Recurse -Force
        if (Test-Path -LiteralPath $itBrowserRoot) { throw 'Owned directory removal is incomplete; inspect cleanup evidence.' }
        [ordered] @{ token = $Token; database_removed = $true; owned_directory_removed = $true; current_herd_environment_changed = $false } | ConvertTo-Json
    }
} catch {
    if ($Mode -eq 'CreateAndStart' -and (Test-Path -LiteralPath $itBrowserRoot) -and !(Test-Path -LiteralPath (Join-Path $itBrowserRoot 'launch-failed.json'))) {
        Write-ItBrowserNewJson (Join-Path $itBrowserRoot 'launch-failed.json') (@{ token = $Token; failed_at = [DateTime]::UtcNow.ToString('o'); private_exception_suppressed = $true })
    }
    throw
} finally {
    foreach ($itEnvName in $itBrowserPrevious.Keys) { [Environment]::SetEnvironmentVariable($itEnvName, $itBrowserPrevious[$itEnvName], 'Process') }
    Set-Location -LiteralPath $itBrowserPreviousLocation
    [Array]::Clear($itBrowserKeyBytes, 0, $itBrowserKeyBytes.Length)
    $itBrowserDbAccess.Clear(); $itBrowserEnv.Clear()
}
