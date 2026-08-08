[CmdletBinding()]
param(
    [string] $ApplicationPath = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string] $HealthUrl,
    [string] $SessionCookie = $env:MONITORING_HEALTH_SESSION_COOKIE
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-CheckedCommand {
    param(
        [Parameter(Mandatory)] [string] $FilePath,
        [Parameter(Mandatory)] [string[]] $Arguments
    )

    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath failed with exit code $LASTEXITCODE."
    }
}

$resolvedApplication = (Resolve-Path -LiteralPath $ApplicationPath).Path
$workerConfig = Join-Path $resolvedApplication 'ops\supervisor\oblivion-monitoring-workers.conf'
$listenerConfig = Join-Path $resolvedApplication 'ops\supervisor\oblivion-monitoring-listeners.conf'
$verificationState = 'configuration_only'
$authenticatedHealthVerified = $false

foreach ($path in @($workerConfig, $listenerConfig)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required runtime configuration is missing: $path"
    }
}

$workerText = Get-Content -LiteralPath $workerConfig -Raw
$requiredWorkers = [ordered]@{
    'oblivion-monitoring-events' = 'monitoring-events'
    'oblivion-monitoring-checks' = 'monitoring-checks'
    'oblivion-monitoring-discovery' = 'monitoring-discovery'
    'oblivion-monitoring-provider' = 'monitoring-provider'
    'oblivion-monitoring-topology' = 'monitoring-topology'
    'oblivion-monitoring-maintenance' = 'monitoring-maintenance'
    'oblivion-monitoring-orchestration' = 'monitoring'
    'oblivion-monitoring-commands' = 'monitoring-commands'
}
foreach ($program in $requiredWorkers.Keys) {
    $queue = $requiredWorkers[$program]
    $programPattern = '(?ms)^\[program:' + [regex]::Escape($program) + '\](.*?)(?=^\[program:|\z)'
    $programMatch = [regex]::Match($workerText, $programPattern)
    if (-not $programMatch.Success) {
        throw "The Supervisor configuration is missing $program."
    }

    $queuePattern = '(?m)(?:^|\s)--queue=' + [regex]::Escape($queue) + '(?=\s|$)'
    if (-not [regex]::IsMatch($programMatch.Groups[1].Value, $queuePattern)) {
        throw "The Supervisor program $program does not isolate $queue."
    }
    if ([regex]::Matches($workerText, $queuePattern).Count -ne 1) {
        throw "The Supervisor configuration must assign $queue to exactly one program."
    }
}

$monitoredQueues = @(
    'monitoring',
    'monitoring-events',
    'monitoring-checks',
    'monitoring-discovery',
    'monitoring-provider',
    'monitoring-topology',
    'monitoring-maintenance',
    'monitoring-commands'
)
$runtimeComponents = @(
    'events',
    'checks',
    'discovery',
    'provider',
    'topology',
    'maintenance',
    'orchestration',
    'commands'
)

$listenerText = Get-Content -LiteralPath $listenerConfig -Raw
foreach ($command in @('monitoring:listen-snmp-traps', 'monitoring:listen-syslog', 'monitoring:listen-flow')) {
    if ($listenerText -notmatch [regex]::Escape($command)) {
        throw "The Supervisor configuration is missing $command."
    }
}

Push-Location $resolvedApplication
try {
    Invoke-CheckedCommand -FilePath 'php' -Arguments @('artisan', 'route:list', '--name=security-devices.runtime-health')
    Invoke-CheckedCommand -FilePath 'php' -Arguments @('artisan', 'schedule:list')
    Invoke-CheckedCommand -FilePath 'php' -Arguments @('artisan', 'queue:monitor', ($monitoredQueues -join ','), '--max=1000', '--json')

    if ($HealthUrl) {
        if (-not $SessionCookie) {
            throw 'HealthUrl requires MONITORING_HEALTH_SESSION_COOKIE or -SessionCookie; no unauthenticated bypass is permitted.'
        }

        $healthUri = $null
        if (-not [Uri]::TryCreate([string] $HealthUrl, [UriKind]::Absolute, [ref] $healthUri) -or
            $healthUri.Scheme -cne 'https' -or
            $healthUri.Port -ne 443 -or
            [string]::IsNullOrWhiteSpace($healthUri.Host) -or
            -not [string]::IsNullOrEmpty($healthUri.UserInfo) -or
            $healthUri.AbsolutePath -cne '/security-devices/runtime-health' -or
            -not [string]::IsNullOrEmpty($healthUri.Query) -or
            -not [string]::IsNullOrEmpty($healthUri.Fragment)) {
            throw 'HealthUrl must be the exact HTTPS runtime-health route on port 443 without userinfo, query or fragment.'
        }

        $response = Invoke-RestMethod -Method Get -Uri $healthUri.AbsoluteUri -MaximumRedirection 0 -Headers @{
            Accept = 'application/json'
            Cookie = $SessionCookie
            'Cache-Control' = 'no-cache, no-store'
            Pragma = 'no-cache'
        }
        $required = @('state', 'workers', 'queues', 'listeners', 'external_heartbeat', 'storage', 'collectors', 'observed_at')
        foreach ($key in $required) {
            if ($response.PSObject.Properties.Name -notcontains $key) {
                throw "Runtime health response is missing $key."
            }
        }

        if ($response.state -ne 'operational') {
            throw 'Runtime health is not operational.'
        }
        if ($response.workers.state -ne 'available' -or
            [int] $response.workers.total -ne $requiredWorkers.Count -or
            [int] $response.workers.available -ne $requiredWorkers.Count -or
            [int] $response.workers.attention -ne 0 -or
            [int] $response.workers.not_observed -ne 0) {
            throw 'Not every required runtime worker is currently available.'
        }
        foreach ($component in $runtimeComponents) {
            $queueProperty = $response.queues.PSObject.Properties[$component]
            if ($null -eq $queueProperty -or
                $queueProperty.Value.state -ne 'clear' -or
                $queueProperty.Value.worker_state -ne 'available') {
                throw "Runtime component $component is not clear with an available worker."
            }
        }
        foreach ($listener in @('snmp_traps', 'syslog', 'flow')) {
            $listenerProperty = $response.listeners.PSObject.Properties[$listener]
            if ($null -eq $listenerProperty -or $listenerProperty.Value.state -ne 'available') {
                throw "Runtime listener $listener is not currently available."
            }
        }
        if ($response.storage.time_series.state -ne 'available' -or
            $response.storage.snapshots.state -ne 'available') {
            throw 'Runtime storage dependencies are not currently available.'
        }
        if ($response.external_heartbeat.state -ne 'sent') {
            throw 'The independent runtime heartbeat has not been delivered successfully.'
        }

        $observedAtText = [string] $response.observed_at
        if ($observedAtText -cnotmatch '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|\+00:00)$') {
            throw 'Runtime health observed_at must be expressed in UTC.'
        }
        $observedAt = [DateTimeOffset]::MinValue
        $styles = [Globalization.DateTimeStyles]::RoundtripKind
        if (-not [DateTimeOffset]::TryParse($observedAtText, [Globalization.CultureInfo]::InvariantCulture, $styles, [ref] $observedAt) -or
            $observedAt.Offset -ne [TimeSpan]::Zero) {
            throw 'Runtime health observed_at is not a strict timestamp.'
        }
        $healthAgeSeconds = ([DateTimeOffset]::UtcNow - $observedAt.ToUniversalTime()).TotalSeconds
        if ($healthAgeSeconds -lt -5 -or $healthAgeSeconds -gt 60) {
            throw 'Runtime health evidence is stale or unreasonably future-dated.'
        }

        $verificationState = 'verified'
        $authenticatedHealthVerified = $true
    }
} finally {
    Pop-Location
}

[ordered]@{
    state = $verificationState
    worker_queues = $requiredWorkers.Count
    worker_queue_names = @($requiredWorkers.Values)
    udp_listeners = 3
    authenticated_health_checked = [bool] $HealthUrl
    authenticated_health_verified = $authenticatedHealthVerified
    checked_at = (Get-Date).ToUniversalTime().ToString('o')
} | ConvertTo-Json
