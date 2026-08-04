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

        $response = Invoke-RestMethod -Method Get -Uri $HealthUrl -Headers @{
            Accept = 'application/json'
            Cookie = $SessionCookie
        }
        $required = @('state', 'workers', 'queues', 'listeners', 'storage', 'collectors', 'observed_at')
        foreach ($key in $required) {
            if ($response.PSObject.Properties.Name -notcontains $key) {
                throw "Runtime health response is missing $key."
            }
        }
    }
} finally {
    Pop-Location
}

[ordered]@{
    state = 'verified'
    worker_queues = $requiredWorkers.Count
    worker_queue_names = @($requiredWorkers.Values)
    udp_listeners = 3
    authenticated_health_checked = [bool] $HealthUrl
    checked_at = (Get-Date).ToUniversalTime().ToString('o')
} | ConvertTo-Json
