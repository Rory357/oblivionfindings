[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $ObjectDisk,
    [Parameter(Mandatory)]
    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$')]
    [string] $BackupGeneration,
    [Parameter(Mandatory)] [DateTimeOffset] $RecoveryPointUtc,
    [Parameter(Mandatory)] [DateTimeOffset] $RecoveryStartedAtUtc,
    [Parameter(Mandatory)] [ValidateRange(1, 10080)] [int] $MaximumRpoMinutes,
    [Parameter(Mandatory)] [ValidateRange(1, 10080)] [int] $MaximumRtoMinutes,
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
$isolatedConfigCache = Join-Path ([IO.Path]::GetTempPath()) ("oblivion-monitoring-restore-config-{0}.php" -f [Guid]::NewGuid().ToString('N'))
if (Test-Path -LiteralPath $isolatedConfigCache) {
    throw 'Unable to allocate an isolated restore-verification config cache path.'
}

$variables = @('APP_ENV', 'APP_DEBUG', 'APP_CONFIG_CACHE', 'DB_CONNECTION', 'DB_URL', 'REDIS_URL', 'MONITORING_TIMESERIES_URL', 'MONITORING_SNAPSHOT_DISK', 'MONITORING_CREDENTIAL_DRIVER', 'MONITORING_VAULT_URL')
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

    & php artisan monitoring:reconcile-restore --assert-process-config --config-only
    if ($LASTEXITCODE -ne 0) {
        throw "Restore process configuration preflight failed with exit code $LASTEXITCODE. No restored store was read."
    }

    & php artisan migrate --pretend --force
    if ($LASTEXITCODE -ne 0) {
        throw "Migration compatibility check failed with exit code $LASTEXITCODE."
    }

    $reportJson = (& php artisan monitoring:reconcile-restore --json --assert-process-config 2>&1 | Out-String).Trim()
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
    $evidence = [ordered]@{}
    foreach ($key in $required) {
        $evidence[$key] = [long] $report.$key
    }
    $evidence['checked_at'] = $report.checked_at
    $evidence['backup_generation'] = $BackupGeneration
    $evidence['recovery_point_utc'] = $RecoveryPointUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['recovery_started_at_utc'] = $RecoveryStartedAtUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['verification_started_at_utc'] = $verificationStartedAtUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['verification_completed_at_utc'] = $verificationCompletedAtUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $evidence['rpo_minutes'] = [Math]::Round($rpoMinutes, 3)
    $evidence['rto_minutes'] = [Math]::Round($rtoMinutes, 3)
    $evidence['maximum_rpo_minutes'] = $MaximumRpoMinutes
    $evidence['maximum_rto_minutes'] = $MaximumRtoMinutes
    $evidence['recovery_objectives_met'] = $recoveryObjectivesMet

    $outputDirectory = Join-Path $resolvedApplication 'output\monitoring\restore'
    New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
    $evidenceTimestamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffffffZ', [Globalization.CultureInfo]::InvariantCulture)
    $evidenceNonce = [Guid]::NewGuid().ToString('N')
    $outputPath = Join-Path $outputDirectory ("reconciliation-{0}-{1}.json" -f $evidenceTimestamp, $evidenceNonce)
    $evidenceBytes = [Text.UTF8Encoding]::new($false).GetBytes(($evidence | ConvertTo-Json))
    $evidenceStream = $null
    $evidenceCreated = $false
    $evidenceCommitted = $false
    try {
        $evidenceStream = [IO.File]::Open($outputPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $evidenceCreated = $true
        $evidenceStream.Write($evidenceBytes, 0, $evidenceBytes.Length)
        $evidenceStream.Flush($true)
        $evidenceStream.Dispose()
        $evidenceStream = $null
        $evidenceCommitted = $true
    } finally {
        if ($null -ne $evidenceStream) {
            $evidenceStream.Dispose()
        }
        if ($evidenceCreated -and -not $evidenceCommitted -and (Test-Path -LiteralPath $outputPath)) {
            Remove-Item -LiteralPath $outputPath -Force
        }
    }

    if ($reconciliationExit -ne 0 -or ($required | Where-Object { $report.$_ -ne 0 }).Count -gt 0) {
        throw "Restore reconciliation found continuity failures. Value-free report: $outputPath"
    }
    if (-not $recoveryObjectivesMet) {
        throw "Restore reconciliation exceeded the approved RPO or RTO. Value-free report: $outputPath"
    }

    Write-Output "Restore reconciliation and recovery objectives passed. Value-free report: $outputPath"
} finally {
    Pop-Location
    foreach ($name in $variables) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
    }
    if (Test-Path -LiteralPath $isolatedConfigCache) {
        Remove-Item -LiteralPath $isolatedConfigCache -Force
    }
}
