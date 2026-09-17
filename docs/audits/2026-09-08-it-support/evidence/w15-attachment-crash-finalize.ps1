$ErrorActionPreference = 'Stop'
$itRecoveryRepo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../../..')).Path
if ($itRecoveryRepo -ine 'C:\Users\steph\Herd\oblivionfindings') { throw 'Unexpected checkout.' }
$itRecoveryToken = '03dee6c781fe4587'
$itRecoveryRoot = Join-Path $itRecoveryRepo ('storage/framework/testing/it-draft-browser-' + $itRecoveryToken)
$itRecoveryDatabase = 'oblivion_it_draft_browser_' + $itRecoveryToken
$itRecoveryFingerprint = '65f611dcff46a7c52347a7ac5ff01f60eaca6c0a69c9f835c7adb5a2a3855a81'
if ((Resolve-Path -LiteralPath $itRecoveryRoot).Path -ine $itRecoveryRoot) { throw 'Resolved recovery target differs.' }
$itRecoveryOwner = Get-Content -LiteralPath (Join-Path $itRecoveryRoot 'owner.json') -Raw | ConvertFrom-Json
$itRecoveryStopped = Get-Content -LiteralPath (Join-Path $itRecoveryRoot 'server-stopped.json') -Raw | ConvertFrom-Json
$itRecoveryCreated = Get-Content -LiteralPath (Join-Path $itRecoveryRoot 'schema-created.json') -Raw | ConvertFrom-Json
if ($itRecoveryOwner.token -ne $itRecoveryToken -or $itRecoveryOwner.database -ne $itRecoveryDatabase -or
    $itRecoveryOwner.root -ine $itRecoveryRoot -or $itRecoveryOwner.port -ne 8767 -or
    $itRecoveryOwner.approval_fingerprint -ne $itRecoveryFingerprint -or
    $itRecoveryStopped.token -ne $itRecoveryToken -or !$itRecoveryStopped.server_settled -or
    $itRecoveryCreated.token -ne $itRecoveryToken -or $itRecoveryCreated.database -ne $itRecoveryDatabase) { throw 'Recovery ownership differs.' }
foreach ($itRecoverySource in $itRecoveryOwner.source_hashes.PSObject.Properties) {
    if ((Get-FileHash -LiteralPath (Join-Path $PSScriptRoot $itRecoverySource.Name) -Algorithm SHA256).Hash.ToLowerInvariant() -ne $itRecoverySource.Value) { throw 'Original helper changed.' }
}
foreach ($itRecoveryProcessFile in @('bootstrap-started.json', 'server-started.json')) {
    $itRecoveryStarted = Get-Content -LiteralPath (Join-Path $itRecoveryRoot $itRecoveryProcessFile) -Raw | ConvertFrom-Json
    if ($itRecoveryStarted.token -ne $itRecoveryToken) { throw 'Process token differs.' }
    $itRecoveryProcess = Get-Process -Id $itRecoveryStarted.pid -ErrorAction SilentlyContinue
    if ($itRecoveryProcess -and $itRecoveryProcess.StartTime.ToUniversalTime().Ticks -eq $itRecoveryStarted.start_ticks) { throw 'Original process still active.' }
}
if (Get-NetTCPConnection -LocalPort 8767 -State Listen -ErrorAction SilentlyContinue) { throw 'Port has a live listener.' }
$itRecoveryObservationJson = & 'C:\Users\steph\.config\herd\bin\php84\php.exe' (Join-Path $PSScriptRoot 'w15-attachment-crash-observe.php')
if ($LASTEXITCODE -ne 0) { throw 'Fresh schema observation failed.' }
$itRecoveryObservation = $itRecoveryObservationJson | ConvertFrom-Json
if ($itRecoveryObservation.token -ne $itRecoveryToken -or $itRecoveryObservation.database -ne $itRecoveryDatabase -or
    $itRecoveryObservation.schema_exists -ne $false -or $itRecoveryObservation.database_mutations_performed -ne $false) { throw 'Schema absence unproven.' }
$itRecoveryTesting = (Resolve-Path -LiteralPath (Join-Path $itRecoveryRepo 'storage/framework/testing')).Path
$itRecoveryItems = @((Get-Item -LiteralPath $itRecoveryTesting -Force), (Get-Item -LiteralPath $itRecoveryRoot -Force)) + @(Get-ChildItem -LiteralPath $itRecoveryRoot -Recurse -Force)
foreach ($itRecoveryItem in $itRecoveryItems) {
    if (($itRecoveryItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Linked path prevents removal.' }
    $itRecoveryAbsolute = [IO.Path]::GetFullPath($itRecoveryItem.FullName)
    if ($itRecoveryAbsolute -ine $itRecoveryTesting -and $itRecoveryAbsolute -ine $itRecoveryRoot -and
        !$itRecoveryAbsolute.StartsWith($itRecoveryRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Descendant escaped the owned root.' }
}
$itRecoveryEvidence = [ordered]@{
    token = $itRecoveryToken; database = $itRecoveryDatabase; root = $itRecoveryRoot
    server_settled = $true; database_removed = $true; recovered_after_crash = $true
    original_cleanup_process_outcome = 'Process lost after stopped marker; schema absence independently observed before filesystem finalization.'
    schema_observation = $itRecoveryObservation; captured_at = [DateTime]::UtcNow.ToString('o'); owner = $itRecoveryOwner
    readiness = (Get-Content -LiteralPath (Join-Path $itRecoveryRoot 'ready.json') -Raw | ConvertFrom-Json)
}
$itRecoveryEvidencePath = Join-Path $PSScriptRoot ('w06-draft-browser-cleanup-' + $itRecoveryToken + '.json')
$itRecoveryHandle = [IO.File]::Open($itRecoveryEvidencePath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
try {
    $itRecoveryBytes = [Text.Encoding]::UTF8.GetBytes(($itRecoveryEvidence | ConvertTo-Json -Depth 12))
    $itRecoveryHandle.Write($itRecoveryBytes, 0, $itRecoveryBytes.Length)
    $itRecoveryHandle.Flush($true)
} finally { $itRecoveryHandle.Dispose() }
# The exact absolute token root and every descendant were checked above.
Remove-Item -LiteralPath $itRecoveryRoot -Recurse -Force
if (Test-Path -LiteralPath $itRecoveryRoot) { throw 'Owned directory removal incomplete.' }
[ordered]@{ token = $itRecoveryToken; schema_absence_observed = $true; owned_directory_removed = $true; database_mutations_performed = $false } | ConvertTo-Json
