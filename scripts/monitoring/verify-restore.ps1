[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $MySqlDsn,
    [Parameter(Mandatory)] [string] $RedisUrl,
    [Parameter(Mandatory)] [string] $InfluxUrl,
    [Parameter(Mandatory)] [string] $VaultUrl,
    [Parameter(Mandatory)] [string] $ObjectDisk,
    [switch] $AllowProductionReadOnly,
    [string] $ApplicationPath = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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

if ($ObjectDisk -notmatch '^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$') {
    throw 'ObjectDisk must be a configured private filesystem disk name.'
}

$resolvedApplication = (Resolve-Path -LiteralPath $ApplicationPath).Path
$variables = @('APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_URL', 'REDIS_URL', 'MONITORING_TIMESERIES_URL', 'MONITORING_SNAPSHOT_DISK', 'MONITORING_CREDENTIAL_DRIVER', 'MONITORING_VAULT_URL')
$previous = @{}
foreach ($name in $variables) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

Push-Location $resolvedApplication
try {
    [Environment]::SetEnvironmentVariable('APP_ENV', 'restore-verification', 'Process')
    [Environment]::SetEnvironmentVariable('APP_DEBUG', 'false', 'Process')
    [Environment]::SetEnvironmentVariable('DB_CONNECTION', 'mysql', 'Process')
    [Environment]::SetEnvironmentVariable('DB_URL', $MySqlDsn, 'Process')
    [Environment]::SetEnvironmentVariable('REDIS_URL', $RedisUrl, 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_TIMESERIES_URL', $InfluxUrl, 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_SNAPSHOT_DISK', $ObjectDisk, 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_CREDENTIAL_DRIVER', 'vault', 'Process')
    [Environment]::SetEnvironmentVariable('MONITORING_VAULT_URL', $VaultUrl, 'Process')

    & php artisan migrate --pretend --force
    if ($LASTEXITCODE -ne 0) {
        throw "Migration compatibility check failed with exit code $LASTEXITCODE."
    }

    $reportJson = (& php artisan monitoring:reconcile-restore --json 2>&1 | Out-String).Trim()
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
        if ($report.PSObject.Properties.Name -notcontains $key) {
            throw "Restore report is missing $key."
        }
    }

    $outputDirectory = Join-Path $resolvedApplication 'output\monitoring\restore'
    New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
    $outputPath = Join-Path $outputDirectory ("reconciliation-{0}.json" -f (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ'))
    $report | ConvertTo-Json | Set-Content -LiteralPath $outputPath -Encoding utf8NoBOM

    if ($reconciliationExit -ne 0 -or ($required | Where-Object { [int] $report.$_ -ne 0 }).Count -gt 0) {
        throw "Restore reconciliation found continuity failures. Value-free report: $outputPath"
    }

    Write-Output "Restore reconciliation passed. Value-free report: $outputPath"
} finally {
    Pop-Location
    foreach ($name in $variables) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
    }
}
