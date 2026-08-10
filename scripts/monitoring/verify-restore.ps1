[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $ObjectDisk,
    [Parameter(Mandatory)]
    [ValidatePattern('^BKP-[a-f0-9]{32}$')]
    [string] $BackupGeneration,
    [Parameter(Mandatory)]
    [ValidatePattern('^[a-f0-9]{64}$')]
    [string] $BackupManifestSha256,
    [Parameter(Mandatory)] [DateTimeOffset] $RecoveryPointUtc,
    [Parameter(Mandatory)] [DateTimeOffset] $RecoveryStartedAtUtc,
    [Parameter(Mandatory)] [ValidateRange(1, 10080)] [int] $MaximumRpoMinutes,
    [Parameter(Mandatory)] [ValidateRange(1, 10080)] [int] $MaximumRtoMinutes,
    [Parameter(Mandatory)]
    [ValidatePattern('^[a-f0-9]{64}$')]
    [string] $RestoredEnvironmentReferenceSha256,
    [Parameter(Mandatory)] [string] $OutputDirectory,
    [switch] $AllowProductionReadOnly,
    [string] $ApplicationPath = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$verificationStartedAtUtc = [DateTimeOffset]::UtcNow

if ($RecoveryPointUtc.Offset -ne [TimeSpan]::Zero -or $RecoveryStartedAtUtc.Offset -ne [TimeSpan]::Zero) {
    throw 'RecoveryPointUtc and RecoveryStartedAtUtc must use an explicit UTC offset.'
}
if ($RecoveryPointUtc -gt $RecoveryStartedAtUtc) {
    throw 'RecoveryPointUtc cannot be later than RecoveryStartedAtUtc.'
}
if ($RecoveryStartedAtUtc -gt $verificationStartedAtUtc) {
    throw 'RecoveryStartedAtUtc cannot be later than the verifier start.'
}

function Get-RequiredProcessEnvironmentValue {
    param([Parameter(Mandatory)] [string] $Name)

    $value = [Environment]::GetEnvironmentVariable($Name, 'Process')
    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "Required restore-verification environment variable '$Name' is unavailable."
    }

    return $value
}

function Invoke-ReleaseGit {
    param(
        [Parameter(Mandatory)] [string] $GitPath,
        [Parameter(Mandatory)] [string] $Checkout,
        [Parameter(Mandatory)] [string[]] $Arguments
    )

    $gitEnvironmentNames = @(
        @(Get-ChildItem Env: | Where-Object { $_.Name -like 'GIT_*' } | ForEach-Object { $_.Name })
        'LD_PRELOAD'
        'LD_LIBRARY_PATH'
    ) | Select-Object -Unique
    $previousGitEnvironment = @{}
    foreach ($name in $gitEnvironmentNames) {
        $previousGitEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }

    try {
        [Environment]::SetEnvironmentVariable('GIT_OPTIONAL_LOCKS', '0', 'Process')
        $output = @(
            & $GitPath --no-optional-locks `
                -c core.fsmonitor=false `
                -c core.untrackedCache=false `
                -C $Checkout `
                @Arguments 2>&1
        )
        $exitCode = $LASTEXITCODE
        if ($exitCode -ne 0) {
            throw 'The deployed release checkout could not be verified.'
        }

        return (($output | ForEach-Object { "$_" }) -join "`n").Trim()
    } finally {
        [Environment]::SetEnvironmentVariable('GIT_OPTIONAL_LOCKS', $null, 'Process')
        foreach ($name in $gitEnvironmentNames) {
            [Environment]::SetEnvironmentVariable($name, $previousGitEnvironment[$name], 'Process')
        }
    }
}

function Get-ReleaseRevision {
    param(
        [Parameter(Mandatory)] [string] $GitPath,
        [Parameter(Mandatory)] [string] $Checkout
    )

    $topLevel = Invoke-ReleaseGit -GitPath $GitPath -Checkout $Checkout -Arguments @('rev-parse', '--show-toplevel')
    $head = Invoke-ReleaseGit -GitPath $GitPath -Checkout $Checkout -Arguments @('rev-parse', '--verify', 'HEAD')
    $originMain = Invoke-ReleaseGit -GitPath $GitPath -Checkout $Checkout -Arguments @('rev-parse', '--verify', 'refs/remotes/origin/main')
    $status = Invoke-ReleaseGit -GitPath $GitPath -Checkout $Checkout -Arguments @('status', '--porcelain=v1', '--untracked-files=all')
    $comparison = if ($IsWindows) { [StringComparison]::OrdinalIgnoreCase } else { [StringComparison]::Ordinal }
    if (-not [string]::Equals(
        [IO.Path]::GetFullPath($topLevel),
        [IO.Path]::GetFullPath($Checkout),
        $comparison
    )) {
        throw 'The Git checkout root does not match ApplicationPath.'
    }
    if ($head -notmatch '^[a-f0-9]{40}$' -or -not [string]::Equals($head, $originMain, [StringComparison]::Ordinal)) {
        throw 'The deployed HEAD does not equal the reviewed origin/main revision.'
    }
    if (-not [string]::IsNullOrEmpty($status)) {
        throw 'The deployed checkout contains tracked or untracked source changes.'
    }

    return $head
}

function Get-ProtectedLinuxBinary {
    param(
        [Parameter(Mandatory)] [string] $Path,
        [Parameter(Mandatory)] [string] $Label
    )

    if (-not $IsLinux -or -not (Test-Path -LiteralPath '/usr/bin/stat' -PathType Leaf)) {
        throw 'Release restore evidence requires the protected Linux runtime.'
    }
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.PSIsContainer -or $item.LinkType -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "The protected $Label binary is invalid."
    }
    $resolved = (Resolve-Path -LiteralPath $Path).Path
    if (-not [string]::Equals($resolved, $Path, [StringComparison]::Ordinal)) {
        throw "The protected $Label binary is invalid."
    }
    $previousPreload = [Environment]::GetEnvironmentVariable('LD_PRELOAD', 'Process')
    $previousLibraryPath = [Environment]::GetEnvironmentVariable('LD_LIBRARY_PATH', 'Process')
    try {
        [Environment]::SetEnvironmentVariable('LD_PRELOAD', $null, 'Process')
        [Environment]::SetEnvironmentVariable('LD_LIBRARY_PATH', $null, 'Process')
        $metadata = (& /usr/bin/stat --format='%u:%a:%F' -- $resolved 2>&1 | Out-String).Trim()
        $statExitCode = $LASTEXITCODE
    } finally {
        [Environment]::SetEnvironmentVariable('LD_PRELOAD', $previousPreload, 'Process')
        [Environment]::SetEnvironmentVariable('LD_LIBRARY_PATH', $previousLibraryPath, 'Process')
    }
    if ($statExitCode -ne 0 -or $metadata -notmatch '^0:([0-7]{3,4}):regular file$') {
        throw "The protected $Label binary is invalid."
    }
    $mode = [Convert]::ToInt32($Matches[1], 8)
    if (($mode -band 0x12) -ne 0 -or ($mode -band 0x49) -eq 0) {
        throw "The protected $Label binary is invalid."
    }

    return $resolved
}

function Get-RestoreReleaseAuthority {
    param(
        [Parameter(Mandatory)] [string] $PhpPath,
        [Parameter(Mandatory)] [string] $Checkout
    )

    $scriptPath = Join-Path $Checkout 'scripts/monitoring/verify-restore-authority.php'
    $scriptItem = Get-Item -LiteralPath $scriptPath -Force
    if ($scriptItem.PSIsContainer -or $scriptItem.LinkType -or ($scriptItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'The tracked restore authority verifier is invalid.'
    }

    $environmentNames = @('PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_PRELOAD', 'LD_LIBRARY_PATH')
    $previousEnvironment = @{}
    foreach ($name in $environmentNames) {
        $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }
    try {
        [Environment]::SetEnvironmentVariable('PATH', '/usr/bin:/bin', 'Process')
        [Environment]::SetEnvironmentVariable('PHPRC', $null, 'Process')
        [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $null, 'Process')
        [Environment]::SetEnvironmentVariable('LD_PRELOAD', $null, 'Process')
        [Environment]::SetEnvironmentVariable('LD_LIBRARY_PATH', $null, 'Process')
        $json = (& $PhpPath $scriptPath 2>&1 | Out-String).Trim()
        $exitCode = $LASTEXITCODE
    } finally {
        foreach ($name in $environmentNames) {
            [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
        }
    }
    if ($exitCode -ne 0 -or [string]::IsNullOrWhiteSpace($json)) {
        throw 'The protected restore release authority could not be verified.'
    }
    try {
        $authority = $json | ConvertFrom-Json
    } catch {
        throw 'The protected restore release authority could not be verified.'
    }
    $expected = @(
        'authority_reference',
        'authority_sha256',
        'backup_generation',
        'backup_manifest_sha256',
        'maximum_rpo_minutes',
        'maximum_rto_minutes',
        'recovery_point_utc',
        'recovery_started_at_utc',
        'release_revision',
        'restored_environment_reference_sha256'
    ) | Sort-Object
    $actual = @($authority.PSObject.Properties.Name) | Sort-Object
    if ((Compare-Object -ReferenceObject $expected -DifferenceObject $actual).Count -ne 0) {
        throw 'The protected restore release authority could not be verified.'
    }

    return $authority
}

function Assert-PrivateEvidenceDirectory {
    param([Parameter(Mandatory)] [string] $Path)

    if (-not $IsLinux -or -not (Test-Path -LiteralPath '/usr/bin/id' -PathType Leaf)) {
        throw 'Release restore evidence requires the protected Linux runtime.'
    }
    $previousPreload = [Environment]::GetEnvironmentVariable('LD_PRELOAD', 'Process')
    $previousLibraryPath = [Environment]::GetEnvironmentVariable('LD_LIBRARY_PATH', 'Process')
    try {
        [Environment]::SetEnvironmentVariable('LD_PRELOAD', $null, 'Process')
        [Environment]::SetEnvironmentVariable('LD_LIBRARY_PATH', $null, 'Process')
        $metadata = (& /usr/bin/stat --format='%u:%a:%F' -- $Path 2>&1 | Out-String).Trim()
        $statExitCode = $LASTEXITCODE
        $currentUid = (& /usr/bin/id -u 2>&1 | Out-String).Trim()
        $idExitCode = $LASTEXITCODE
    } finally {
        [Environment]::SetEnvironmentVariable('LD_PRELOAD', $previousPreload, 'Process')
        [Environment]::SetEnvironmentVariable('LD_LIBRARY_PATH', $previousLibraryPath, 'Process')
    }
    $metadataMatches = $metadata -match '^([0-9]+):700:directory$'
    $ownerMatches = $metadataMatches -and [string]::Equals(
        $Matches[1],
        $currentUid,
        [StringComparison]::Ordinal
    )
    if ($statExitCode -ne 0 -or $idExitCode -ne 0 -or -not $ownerMatches) {
        throw 'OutputDirectory must be mode 0700 and owned by the application service account.'
    }
}

$gitPath = Get-ProtectedLinuxBinary -Path '/usr/bin/git' -Label 'Git'
$phpPath = Get-ProtectedLinuxBinary -Path '/usr/bin/php8.4' -Label 'PHP'

$MySqlDsn = Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_MYSQL_DSN'
$RedisUrl = Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_REDIS_URL'
$InfluxUrl = Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_INFLUX_URL'
$VaultUrl = Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_VAULT_URL'
$RestoreFilesystemDriver = Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_FILESYSTEM_DRIVER'
if ($RestoreFilesystemDriver -ceq 'local') {
    Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_FILESYSTEM_ROOT' | Out-Null
} elseif ($RestoreFilesystemDriver -ceq 's3') {
    @(
        'MONITORING_RESTORE_OBJECT_ACCESS_KEY_ID',
        'MONITORING_RESTORE_OBJECT_SECRET_ACCESS_KEY',
        'MONITORING_RESTORE_OBJECT_REGION',
        'MONITORING_RESTORE_OBJECT_BUCKET',
        'MONITORING_RESTORE_OBJECT_ENDPOINT',
        'MONITORING_RESTORE_OBJECT_PATH_STYLE'
    ) | ForEach-Object {
        Get-RequiredProcessEnvironmentValue -Name $_ | Out-Null
    }
} else {
    throw 'MONITORING_RESTORE_FILESYSTEM_DRIVER must be exactly local or s3.'
}

function Get-ConnectionHost {
    param([Parameter(Mandatory)] [string] $Connection)

    $uri = $null
    if ([Uri]::TryCreate($Connection, [UriKind]::Absolute, [ref] $uri) -and $uri.Host) {
        return $uri.Host
    }
    if ($Connection -match '(?i)(?:host|server)=([^;]+)') {
        return $Matches[1].Trim()
    }

    throw 'A connection value does not contain a recognisable host.'
}

function Test-PrivateOrTestHost {
    param([Parameter(Mandatory)] [string] $HostName)

    if ($HostName -in @('localhost', '127.0.0.1', '::1') -or $HostName.EndsWith('.test') -or $HostName.EndsWith('.local')) {
        return $true
    }
    $address = $null
    if ([Net.IPAddress]::TryParse($HostName, [ref] $address)) {
        $bytes = $address.GetAddressBytes()
        if ($address.AddressFamily -eq [Net.Sockets.AddressFamily]::InterNetwork) {
            return $bytes[0] -eq 10 -or ($bytes[0] -eq 172 -and $bytes[1] -ge 16 -and $bytes[1] -le 31) -or ($bytes[0] -eq 192 -and $bytes[1] -eq 168) -or $bytes[0] -eq 127
        }
        return $address.IsIPv6LinkLocal -or $address.IsIPv6SiteLocal -or [Net.IPAddress]::IsLoopback($address)
    }

    return $false
}

foreach ($connection in @($MySqlDsn, $RedisUrl, $InfluxUrl, $VaultUrl)) {
    $hostName = Get-ConnectionHost -Connection $connection
    if (-not $AllowProductionReadOnly -and -not (Test-PrivateOrTestHost -HostName $hostName)) {
        throw "Refusing non-private restore host '$hostName'. Supply -AllowProductionReadOnly only for an approved read-only rehearsal."
    }
}

if ($ObjectDisk -cne 'monitoring-restore') {
    throw 'ObjectDisk must be the dedicated monitoring-restore filesystem disk.'
}

$resolvedApplication = (Resolve-Path -LiteralPath $ApplicationPath).Path
$outputItem = Get-Item -LiteralPath $OutputDirectory
if (-not $outputItem.PSIsContainer -or ($outputItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    throw 'OutputDirectory must be an existing non-reparse directory.'
}
$resolvedOutputDirectory = (Resolve-Path -LiteralPath $OutputDirectory).Path
Assert-PrivateEvidenceDirectory -Path $resolvedOutputDirectory
$relativeOutput = [IO.Path]::GetRelativePath($resolvedApplication, $resolvedOutputDirectory)
$outsideApplication = [IO.Path]::IsPathRooted($relativeOutput) `
    -or $relativeOutput -eq '..' `
    -or $relativeOutput.StartsWith("..$([IO.Path]::DirectorySeparatorChar)", [StringComparison]::Ordinal)
if (-not $outsideApplication) {
    throw 'OutputDirectory must be outside the application checkout.'
}
$releaseRevision = Get-ReleaseRevision -GitPath $gitPath -Checkout $resolvedApplication
$releaseAuthority = Get-RestoreReleaseAuthority -PhpPath $phpPath -Checkout $resolvedApplication
$recoveryPointValue = $RecoveryPointUtc.ToString('yyyy-MM-ddTHH:mm:ssZ', [Globalization.CultureInfo]::InvariantCulture)
$recoveryStartedValue = $RecoveryStartedAtUtc.ToString('yyyy-MM-ddTHH:mm:ssZ', [Globalization.CultureInfo]::InvariantCulture)
if (-not [string]::Equals($releaseRevision, $releaseAuthority.release_revision, [StringComparison]::Ordinal) `
    -or -not [string]::Equals($BackupGeneration, $releaseAuthority.backup_generation, [StringComparison]::Ordinal) `
    -or -not [string]::Equals($BackupManifestSha256, $releaseAuthority.backup_manifest_sha256, [StringComparison]::Ordinal) `
    -or -not [string]::Equals($RestoredEnvironmentReferenceSha256, $releaseAuthority.restored_environment_reference_sha256, [StringComparison]::Ordinal) `
    -or -not [string]::Equals($recoveryPointValue, $releaseAuthority.recovery_point_utc, [StringComparison]::Ordinal) `
    -or -not [string]::Equals($recoveryStartedValue, $releaseAuthority.recovery_started_at_utc, [StringComparison]::Ordinal) `
    -or $MaximumRpoMinutes -ne [int] $releaseAuthority.maximum_rpo_minutes `
    -or $MaximumRtoMinutes -ne [int] $releaseAuthority.maximum_rto_minutes) {
    throw 'Restore inputs do not match the protected release authority.'
}
$isolatedConfigCache = Join-Path ([IO.Path]::GetTempPath()) ("oblivion-monitoring-restore-config-{0}.php" -f [Guid]::NewGuid().ToString('N'))
if (Test-Path -LiteralPath $isolatedConfigCache) {
    throw 'Unable to allocate an isolated restore-verification config cache path.'
}

$variables = @(
    'APP_ENV',
    'APP_DEBUG',
    'APP_CONFIG_CACHE',
    'DB_CONNECTION',
    'DB_URL',
    'REDIS_URL',
    'MONITORING_TIMESERIES_URL',
    'MONITORING_SNAPSHOT_DISK',
    'MONITORING_CREDENTIAL_DRIVER',
    'MONITORING_VAULT_URL',
    'PATH',
    'PHPRC',
    'PHP_INI_SCAN_DIR',
    'LD_PRELOAD',
    'LD_LIBRARY_PATH'
)
$previous = @{}
foreach ($name in $variables) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

Push-Location $resolvedApplication
try {
    [Environment]::SetEnvironmentVariable('APP_ENV', 'restore-verification', 'Process')
    [Environment]::SetEnvironmentVariable('APP_DEBUG', 'false', 'Process')
    [Environment]::SetEnvironmentVariable('APP_CONFIG_CACHE', $isolatedConfigCache, 'Process')
    [Environment]::SetEnvironmentVariable('DB_CONNECTION', 'mysql', 'Process')
    [Environment]::SetEnvironmentVariable('DB_URL', $MySqlDsn, 'Process')
    [Environment]::SetEnvironmentVariable('REDIS_URL', $RedisUrl, 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_TIMESERIES_URL', $InfluxUrl, 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_SNAPSHOT_DISK', $ObjectDisk, 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_CREDENTIAL_DRIVER', 'vault', 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_VAULT_URL', $VaultUrl, 'Process')
    [Environment]::SetEnvironmentVariable('PATH', '/usr/bin:/bin', 'Process')
    [Environment]::SetEnvironmentVariable('PHPRC', $null, 'Process')
    [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $null, 'Process')
    [Environment]::SetEnvironmentVariable('LD_PRELOAD', $null, 'Process')
    [Environment]::SetEnvironmentVariable('LD_LIBRARY_PATH', $null, 'Process')

    & $phpPath artisan monitoring:reconcile-restore --assert-process-config --config-only
    if ($LASTEXITCODE -ne 0) {
        throw "Restore process configuration preflight failed with exit code $LASTEXITCODE. No restored store was read."
    }

    & $phpPath artisan migrate --pretend --force
    if ($LASTEXITCODE -ne 0) {
        throw "Migration compatibility check failed with exit code $LASTEXITCODE."
    }

    $reportJson = (& $phpPath artisan monitoring:reconcile-restore --json --assert-process-config 2>&1 | Out-String).Trim()
    $reconciliationExit = $LASTEXITCODE
    $report = $reportJson | ConvertFrom-Json
    $required = @(
        'outbox_gap',
        'inbox_checkpoint_gap',
        'orphan_series',
        'timeseries_pointer_gap',
        'snapshot_hash_mismatch',
        'topology_pointer_gap',
        'collector_sequence_regression',
        'stale_unpublished_delivery',
        'published_projection_gap',
        'provider_cursor_scope_gap',
        'provider_cursor_stall',
        'credential_reference_recovery_gap',
        'credential_lease_recovery_gap',
        'redis_unavailable',
        'timeseries_unavailable',
        'snapshot_store_unavailable',
        'secret_manager_unavailable'
    )
    foreach ($key in $required) {
        $property = $report.PSObject.Properties[$key]
        if ($null -eq $property) {
            throw "Restore report is missing $key."
        }
        $value = $property.Value
        if ($value -isnot [long] -or $value -lt 0) {
            throw "Restore report field $key must be a non-negative integer."
        }
    }

    $verificationCompletedAtUtc = [DateTimeOffset]::UtcNow
    $rpoMinutes = ($RecoveryStartedAtUtc - $RecoveryPointUtc).TotalMinutes
    $rtoMinutes = ($verificationCompletedAtUtc - $RecoveryStartedAtUtc).TotalMinutes
    $recoveryObjectivesMet = $rpoMinutes -le $MaximumRpoMinutes -and $rtoMinutes -le $MaximumRtoMinutes
    $continuityPassed = ($required | Where-Object { $report.$_ -ne 0 }).Count -eq 0
    $releasePassed = $reconciliationExit -eq 0 -and $continuityPassed -and $recoveryObjectivesMet
    $completedReleaseRevision = Get-ReleaseRevision -GitPath $gitPath -Checkout $resolvedApplication
    if (-not [string]::Equals($releaseRevision, $completedReleaseRevision, [StringComparison]::Ordinal)) {
        throw 'The deployed release revision changed during restore verification.'
    }
    $completedReleaseAuthority = Get-RestoreReleaseAuthority -PhpPath $phpPath -Checkout $resolvedApplication
    if (-not [string]::Equals(
        $releaseAuthority.authority_sha256,
        $completedReleaseAuthority.authority_sha256,
        [StringComparison]::Ordinal
    )) {
        throw 'The protected restore release authority changed during verification.'
    }
    $evidence = [ordered]@{}
    $evidence['schema_version'] = 3
    $evidence['evidence_class'] = 'isolated-restore-reconciliation-v3'
    $evidence['environment'] = 'restore-verification'
    $evidence['fixture'] = $false
    $evidence['synthetic'] = $false
    $evidence['status'] = if ($releasePassed) { 'verified' } else { 'failed' }
    $evidence['restore_release_evidence'] = $releasePassed
    $evidence['release_revision'] = $releaseRevision
    $evidence['restored_environment_reference_sha256'] = $releaseAuthority.restored_environment_reference_sha256
    $evidence['restore_authority_reference'] = $releaseAuthority.authority_reference
    $evidence['restore_authority_sha256'] = $releaseAuthority.authority_sha256
    $evidence['checkout_clean_verified'] = $true
    $evidence['checksum_algorithm'] = 'sha256'
    $evidence['publication'] = 'collision_safe_exclusive_create'
    foreach ($key in $required) {
        $evidence[$key] = [long] $report.$key
    }
    $evidence['checked_at'] = $report.checked_at
    $evidence['backup_generation'] = $releaseAuthority.backup_generation
    $evidence['backup_manifest_sha256'] = $releaseAuthority.backup_manifest_sha256
    $evidence['recovery_point_utc'] = $RecoveryPointUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['recovery_started_at_utc'] = $RecoveryStartedAtUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['verification_started_at_utc'] = $verificationStartedAtUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['verification_completed_at_utc'] = $verificationCompletedAtUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['rpo_minutes'] = [Math]::Round($rpoMinutes, 3)
    $evidence['rto_minutes'] = [Math]::Round($rtoMinutes, 3)
    $evidence['maximum_rpo_minutes'] = $MaximumRpoMinutes
    $evidence['maximum_rto_minutes'] = $MaximumRtoMinutes
    $evidence['recovery_objectives_met'] = $recoveryObjectivesMet

    $evidenceTimestamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffffffZ', [Globalization.CultureInfo]::InvariantCulture)
    $evidenceNonce = [Guid]::NewGuid().ToString('N')
    $outputPath = Join-Path $resolvedOutputDirectory ("reconciliation-{0}-{1}.json" -f $evidenceTimestamp, $evidenceNonce)
    $checksumPath = "$outputPath.sha256"
    $utf8 = [Text.UTF8Encoding]::new($false)
    $evidenceBytes = $utf8.GetBytes(($evidence | ConvertTo-Json))
    $evidenceSha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($evidenceBytes)).ToLowerInvariant()
    $checksumBytes = $utf8.GetBytes("$evidenceSha256  $([IO.Path]::GetFileName($outputPath))`n")
    $evidenceStream = $null
    $checksumStream = $null
    $evidenceCreated = $false
    $checksumCreated = $false
    $evidenceCommitted = $false
    try {
        $evidenceStream = [IO.File]::Open($outputPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $evidenceCreated = $true
        $evidenceStream.Write($evidenceBytes, 0, $evidenceBytes.Length)
        $evidenceStream.Flush($true)
        $evidenceStream.Dispose()
        $evidenceStream = $null

        $checksumStream = [IO.File]::Open($checksumPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $checksumCreated = $true
        $checksumStream.Write($checksumBytes, 0, $checksumBytes.Length)
        $checksumStream.Flush($true)
        $checksumStream.Dispose()
        $checksumStream = $null

        $readbackSha256 = [Convert]::ToHexString(
            [Security.Cryptography.SHA256]::HashData([IO.File]::ReadAllBytes($outputPath))
        ).ToLowerInvariant()
        $readbackChecksum = $utf8.GetString([IO.File]::ReadAllBytes($checksumPath))
        if (-not [string]::Equals($evidenceSha256, $readbackSha256, [StringComparison]::Ordinal) `
            -or -not [string]::Equals($utf8.GetString($checksumBytes), $readbackChecksum, [StringComparison]::Ordinal)) {
            throw 'Restore evidence publication could not be read back exactly.'
        }
        $evidenceCommitted = $true
    } finally {
        if ($null -ne $evidenceStream) {
            $evidenceStream.Dispose()
        }
        if ($null -ne $checksumStream) {
            $checksumStream.Dispose()
        }
        if ($evidenceCreated -and -not $evidenceCommitted -and (Test-Path -LiteralPath $outputPath)) {
            Remove-Item -LiteralPath $outputPath -Force
        }
        if ($checksumCreated -and -not $evidenceCommitted -and (Test-Path -LiteralPath $checksumPath)) {
            Remove-Item -LiteralPath $checksumPath -Force
        }
    }

    if ($reconciliationExit -ne 0 -or -not $continuityPassed) {
        throw "Restore reconciliation found continuity failures. Value-free report: $outputPath Checksum: $checksumPath"
    }
    if (-not $recoveryObjectivesMet) {
        throw "Restore reconciliation exceeded the approved RPO or RTO. Value-free report: $outputPath Checksum: $checksumPath"
    }

    Write-Output "Restore reconciliation and recovery objectives passed. Value-free report: $outputPath Checksum: $checksumPath"
} finally {
    Pop-Location
    foreach ($name in $variables) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
    }
    if (Test-Path -LiteralPath $isolatedConfigCache) {
        Remove-Item -LiteralPath $isolatedConfigCache -Force
    }
}
