param(
    [ValidateSet('Start', 'Stop', 'Status')][string]$Action = 'Status',
    # Optional loopback application URL for signed media events, e.g. http://127.0.0.1:8767/api/chat/calling/events.
    # Without it, calls never record a connection time locally (the application trusts only signed events).
    [string]$WebhookUrl = ''
)
if ($WebhookUrl -and ([uri]$WebhookUrl).Host -notin @('127.0.0.1', 'localhost')) { throw 'The local engine only sends media events to a loopback application.' }

$ErrorActionPreference = 'Stop'
$chatRepo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$chatRuntime = Join-Path $chatRepo 'storage/app/chat-local-engine'
$chatExecutable = Join-Path $chatRuntime 'livekit-server.exe'
$chatConfig = Join-Path $chatRuntime 'local.yaml'
$chatStateFile = Join-Path $chatRuntime 'process.json'
# Derived from the official Windows archive after verifying its published SHA-256.
$chatBinaryHash = '307444D216E09164C990BD24C30D37BFE5EC749F0095E375C9AEDE1E67005FC1'

function Get-ChatProcess {
    if (-not (Test-Path -LiteralPath $chatStateFile)) { return $null }
    $chatState = Get-Content -LiteralPath $chatStateFile -Raw | ConvertFrom-Json
    $chatProcess = Get-Process -Id $chatState.process_id -ErrorAction SilentlyContinue
    if ($chatProcess -and $chatProcess.Path -eq $chatExecutable -and $chatProcess.StartTime.ToUniversalTime().Ticks.ToString() -eq $chatState.started_utc_ticks) { return $chatProcess }
    return $null
}

function Get-ChatStatus {
    $chatProcess = Get-ChatProcess
    if (-not $chatProcess) { return [pscustomobject]@{ running = $false; application_configuration_changed = $false } }
    $chatTcp = @(Get-NetTCPConnection -OwningProcess $chatProcess.Id -State Listen -ErrorAction SilentlyContinue)
    $chatUdp = @(Get-NetUDPEndpoint -OwningProcess $chatProcess.Id -ErrorAction SilentlyContinue)
    $chatPublic = @($chatTcp + $chatUdp | Where-Object { $_.LocalAddress -notin @('127.0.0.1', '::1') })
    return [pscustomobject]@{
        running = $true
        process_id = $chatProcess.Id
        loopback_only = ($chatPublic.Count -eq 0 -and $chatTcp.Count -gt 0)
        tcp = @($chatTcp | ForEach-Object { "$($_.LocalAddress):$($_.LocalPort)" })
        udp = @($chatUdp | ForEach-Object { "$($_.LocalAddress):$($_.LocalPort)" })
        application_configuration_changed = $false
    }
}

if ($Action -eq 'Status') { Get-ChatStatus | ConvertTo-Json; exit }
if ($Action -eq 'Stop') {
    $chatProcess = Get-ChatProcess
    if ($chatProcess) { Stop-Process -Id $chatProcess.Id }
    Get-ChatStatus | ConvertTo-Json
    exit
}
if (Get-ChatProcess) {
    $chatExistingStatus = Get-ChatStatus
    if (-not $chatExistingStatus.loopback_only) {
        Stop-Process -Id $chatExistingStatus.process_id
        throw 'The existing development engine had a non-loopback listener and was stopped.'
    }
    $chatExistingStatus | ConvertTo-Json
    exit
}
if (-not (Test-Path -LiteralPath $chatExecutable)) { throw 'Install the checksum-verified Windows v1.13.10 archive in storage/app/chat-local-engine first.' }
if ((Get-FileHash -LiteralPath $chatExecutable -Algorithm SHA256).Hash -ne $chatBinaryHash) { throw 'The local engine binary differs from the verified release. Review it before starting.' }
if (@(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object LocalPort -in 7880,7881).Count -or @(Get-NetUDPEndpoint -ErrorAction SilentlyContinue | Where-Object LocalPort -eq 7882).Count) { throw 'A required local calling port is already in use; no process was started.' }

# Keep credentials and logs in the ignored, current-user-owned runtime folder.
$chatIdentity = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
function Test-ChatPrivateAcl {
    $chatExistingAcl = Get-Acl -LiteralPath $chatRuntime
    $chatRules = @($chatExistingAcl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]))
    $chatExpectedSids = @($chatIdentity.Value, 'S-1-5-18')
    return ($chatExistingAcl.AreAccessRulesProtected -and $chatRules.Count -eq 2 -and
        @($chatRules | Where-Object {
            $_.IdentityReference.Value -notin $chatExpectedSids -or $_.IsInherited -or
            $_.AccessControlType -ne 'Allow' -or $_.FileSystemRights -ne 'FullControl' -or
            $_.InheritanceFlags -ne 'ContainerInherit,ObjectInherit' -or $_.PropagationFlags -ne 'None'
        }).Count -eq 0 -and @($chatRules.IdentityReference.Value | Select-Object -Unique).Count -eq 2)
}
if (-not (Test-ChatPrivateAcl)) {
    $chatAcl = [System.Security.AccessControl.DirectorySecurity]::new()
    $chatAcl.SetAccessRuleProtection($true, $false)
    $chatAcl.SetAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($chatIdentity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
    $chatAcl.SetAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new([System.Security.Principal.SecurityIdentifier]::new('S-1-5-18'), 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
    Set-Acl -LiteralPath $chatRuntime -AclObject $chatAcl
    if (-not (Test-ChatPrivateAcl)) { throw 'The local runtime directory permissions could not be restricted.' }
}
if (-not (Test-Path -LiteralPath $chatConfig)) {
    $chatRandom = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $chatKeyBytes = [byte[]]::new(16)
    $chatSecretBytes = [byte[]]::new(32)
    $chatRandom.GetBytes($chatKeyBytes)
    $chatRandom.GetBytes($chatSecretBytes)
    $chatRandom.Dispose()
    $chatKey = [BitConverter]::ToString($chatKeyBytes).Replace('-', '').ToLowerInvariant()
    $chatSecret = [BitConverter]::ToString($chatSecretBytes).Replace('-', '').ToLowerInvariant()
    @"
port: 7880
bind_addresses: [127.0.0.1]
rtc:
  # The upstream TCP media listener binds all interfaces; omit it for this local-only profile.
  tcp_port: 0
  udp_port: 7882
  use_external_ip: false
  node_ip: 127.0.0.1
  enable_loopback_candidate: true
  ips:
    includes: [127.0.0.0/8]
  stun_servers: []
keys:
  $($chatKey): $chatSecret
room:
  auto_create: true
  empty_timeout: 60
  departure_timeout: 20
  max_participants: 8
  enabled_codecs:
    - mime: audio/opus
  enable_remote_unmute: false
turn:
  enabled: false
logging:
  level: warn
  pion_level: error
  json: true
"@ | Set-Content -LiteralPath $chatConfig -Encoding utf8
}
if ($WebhookUrl -and -not (Select-String -LiteralPath $chatConfig -Pattern '^webhook:' -Quiet)) {
    $chatConfiguredKey = (Select-String -LiteralPath $chatConfig -Pattern '^\s+([0-9a-f]{32}):' | Select-Object -First 1).Matches[0].Groups[1].Value
    Add-Content -LiteralPath $chatConfig -Encoding utf8 -Value "webhook:`n  api_key: $chatConfiguredKey`n  urls:`n    - $WebhookUrl"
}
$chatProcess = Start-Process -FilePath $chatExecutable -ArgumentList @('--config', ('"' + $chatConfig + '"'), '--bind', '127.0.0.1') -WorkingDirectory $chatRuntime -WindowStyle Hidden -RedirectStandardOutput (Join-Path $chatRuntime 'stdout.log') -RedirectStandardError (Join-Path $chatRuntime 'stderr.log') -PassThru
try {
    @{ process_id = $chatProcess.Id; started_utc_ticks = $chatProcess.StartTime.ToUniversalTime().Ticks.ToString() } | ConvertTo-Json | Set-Content -LiteralPath $chatStateFile
    $chatHealthy = $false
    for ($chatAttempt = 0; $chatAttempt -lt 20; $chatAttempt++) {
        if ($chatProcess.HasExited) { throw 'The local engine exited during startup. Inspect its private runtime logs.' }
        try {
            $chatResponse = Invoke-WebRequest -Uri 'http://127.0.0.1:7880/' -TimeoutSec 1 -UseBasicParsing
            if ($chatResponse.StatusCode -eq 200) { $chatHealthy = $true; break }
        } catch { Start-Sleep -Milliseconds 200 }
    }
    if (-not $chatHealthy) { throw 'The local engine did not become healthy.' }
    $chatStatus = Get-ChatStatus
    if (-not $chatStatus.loopback_only) { throw ('The local engine did not stay restricted to loopback: ' + ($chatStatus | ConvertTo-Json -Compress)) }
    $chatStatus | ConvertTo-Json
} catch {
    if (-not $chatProcess.HasExited) { Stop-Process -Id $chatProcess.Id }
    throw
}
