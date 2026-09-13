param(
    [ValidateSet('Preview', 'Apply')][string] $Mode = 'Preview',
    [Parameter(Mandatory)][ValidatePattern('^[a-f0-9]{16}$')][string] $Token,
    [Parameter(Mandatory)][ValidatePattern('^[a-f0-9]{64}$')][string] $ExpectedPreviousFingerprint,
    [ValidatePattern('^[a-f0-9]{64}$')][string] $ExpectedNextFingerprint
)

# Refresh only reviewed static assets in the existing disposable runtime.
# No schema import, migration, session/key change, provider change or guard bypass.
$ErrorActionPreference = 'Stop'
$itAssetRepo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../../..')).Path
if ($itAssetRepo -ine 'C:\Users\steph\Herd\oblivionfindings') { throw 'Unexpected checkout.' }
$itAssetParent = Join-Path $itAssetRepo 'storage/framework/testing'
$itAssetRoot = Join-Path $itAssetParent ('it-draft-browser-' + $Token)
function Assert-ItAssetPath([string] $Path) {
    $itAssetChecked = [IO.Path]::GetFullPath($Path)
    if ($itAssetChecked -ine $itAssetRoot -and !$itAssetChecked.StartsWith($itAssetRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Path escaped the exact owned root.' }
    $itAssetCursor = $itAssetChecked
    while ($itAssetCursor.Length -ge $itAssetParent.Length) {
        if (Test-Path -LiteralPath $itAssetCursor) {
            if (((Get-Item -LiteralPath $itAssetCursor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Linked path refused.' }
        }
        if ($itAssetCursor -ieq $itAssetParent) { break }
        $itAssetCursor = [IO.Path]::GetDirectoryName($itAssetCursor)
    }
}
$itAssetOwnerPath = Join-Path $itAssetRoot 'owner.json'
foreach ($itAssetName in @('owner.json', 'ready.json', 'server-started.json')) {
    $itAssetPath = Join-Path $itAssetRoot $itAssetName
    Assert-ItAssetPath $itAssetPath
    if (!(Test-Path -LiteralPath $itAssetPath -PathType Leaf)) { throw 'Settled owned runtime evidence missing.' }
}
$itAssetOriginalHash = (Get-FileHash -LiteralPath $itAssetOwnerPath -Algorithm SHA256).Hash
$itAssetOwner = Get-Content -LiteralPath $itAssetOwnerPath -Raw | ConvertFrom-Json
$itAssetReady = Get-Content -LiteralPath (Join-Path $itAssetRoot 'ready.json') -Raw | ConvertFrom-Json
$itAssetServer = Get-Content -LiteralPath (Join-Path $itAssetRoot 'server-started.json') -Raw | ConvertFrom-Json
$itAssetDatabase = 'oblivion_it_draft_browser_' + $Token
if ($itAssetOwner.token -ne $Token -or $itAssetOwner.database -ne $itAssetDatabase -or $itAssetOwner.root -ine $itAssetRoot -or $itAssetOwner.approval_fingerprint -ne $ExpectedPreviousFingerprint -or $itAssetOwner.synthetic_retention_only -ne $true -or $itAssetReady.database -ne $itAssetDatabase -or $itAssetReady.ready -ne $true) { throw 'Owner/readiness identity differs.' }
$itAssetProcess = Get-Process -Id $itAssetServer.pid -ErrorAction Stop
$itAssetCommand = Get-CimInstance Win32_Process -Filter ('ProcessId = ' + [int] $itAssetServer.pid)
$itAssetRouter = Join-Path $PSScriptRoot 'w06-draft-browser-router.php'
if ($itAssetServer.token -ne $Token -or $itAssetServer.router -ine $itAssetRouter -or $itAssetProcess.StartTime.ToUniversalTime().Ticks -ne $itAssetServer.start_ticks -or $itAssetProcess.Path -ine $itAssetServer.executable -or !$itAssetCommand.CommandLine.Contains($itAssetRouter)) { throw 'The original owned server is not live.' }
if (Test-Path -LiteralPath (Join-Path $itAssetRepo 'public/hot')) { throw 'Stable built assets required.' }

$itAssetPreview = (& (Join-Path $PSScriptRoot 'w06-draft-browser-environment.ps1') -Mode Preview -MailboxFixtures:([bool] $itAssetOwner.mailbox_fixtures)) | ConvertFrom-Json
foreach ($itAssetGroup in @('source_hashes', 'migration_hashes')) {
    $itAssetPrevious = $itAssetOwner.$itAssetGroup
    $itAssetCurrent = $itAssetPreview.review.$itAssetGroup
    if (@($itAssetPrevious.PSObject.Properties).Count -ne @($itAssetCurrent.PSObject.Properties).Count) { throw 'Helper or migration set changed; a fresh runtime is required.' }
    foreach ($itAssetProperty in $itAssetCurrent.PSObject.Properties) {
        if ($itAssetPrevious.($itAssetProperty.Name) -ne $itAssetProperty.Value) { throw 'Helper or migration source changed; a fresh runtime is required.' }
    }
}
if ($itAssetOwner.schema_sha256 -ne $itAssetPreview.review.schema_sha256) { throw 'Schema changed; a fresh runtime is required.' }
$itAssetReview = [ordered]@{
    token = $Token; database = $itAssetDatabase; server_pid = $itAssetProcess.Id
    previous_fingerprint = $itAssetOwner.approval_fingerprint; next_fingerprint = $itAssetPreview.approval_fingerprint
    previous_asset_sha256 = $itAssetOwner.asset_manifest_sha256; next_asset_sha256 = $itAssetPreview.review.asset_manifest_sha256
    helpers_migrations_schema_unchanged = $true; database_mutations = $false; session_and_key_changed = $false
    router_guards_unchanged = $true; provider_configuration_changed = $false
}
if ($Mode -eq 'Preview') { $itAssetReview | ConvertTo-Json; return }
if ($ExpectedNextFingerprint -ne $itAssetPreview.approval_fingerprint -or $itAssetReview.previous_asset_sha256 -eq $itAssetReview.next_asset_sha256) { throw 'Fresh reviewed asset change required.' }
$itAssetStaged = Join-Path $itAssetRoot ('owner-assets-' + $ExpectedNextFingerprint + '.pending.json')
$itAssetBackup = Join-Path $itAssetRoot ('owner-before-assets-' + $ExpectedNextFingerprint + '.json')
foreach ($itAssetPath in @($itAssetStaged, $itAssetBackup)) {
    Assert-ItAssetPath $itAssetPath
    if (Test-Path -LiteralPath $itAssetPath) { throw 'Asset transition evidence already exists; inspect before retrying.' }
}
# Recheck the exact original file before its atomic replacement.
$itAssetOwner.asset_manifest_sha256 = $itAssetReview.next_asset_sha256
$itAssetOwner.approval_fingerprint = $ExpectedNextFingerprint
$itAssetBytes = [Text.UTF8Encoding]::new($false).GetBytes(($itAssetOwner | ConvertTo-Json -Depth 12) + [Environment]::NewLine)
$itAssetStream = [IO.File]::Open($itAssetStaged, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
try { $itAssetStream.Write($itAssetBytes, 0, $itAssetBytes.Length); $itAssetStream.Flush($true) } finally { $itAssetStream.Dispose() }
if ((Get-FileHash -LiteralPath $itAssetOwnerPath -Algorithm SHA256).Hash -ne $itAssetOriginalHash) { throw 'Owner changed during review; retained staged evidence, no replacement.' }
[IO.File]::Replace($itAssetStaged, $itAssetOwnerPath, $itAssetBackup)
$itAssetReview['applied'] = $true
$itAssetReview | ConvertTo-Json
