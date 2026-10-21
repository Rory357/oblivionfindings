# Starts the disposable Chat preview detached, returning once its schema is migrated and seeded.
# Run it through the machine-wide lock so the heavy migration respects the PC load limit:
#   bash ~/.claude/heavy-lock.sh "chat-preview" -- powershell -NoProfile -File docs/chat-experience/qa/start-preview.ps1 -Calling
# The preview keeps running (and keeps its database) until its php process is stopped.
param([switch]$Calling)

$ErrorActionPreference = 'Stop'
$qa = $PSScriptRoot
$root = (Resolve-Path (Join-Path $qa '../../..')).Path
$runtime = Join-Path $qa 'runtime'
New-Item -ItemType Directory -Force $runtime | Out-Null
$log = Join-Path $runtime 'preview.log'
$errors = Join-Path $runtime 'preview.err.log'
Remove-Item $log, $errors -ErrorAction SilentlyContinue
$env:CHAT_PREVIEW_CALLING = if ($Calling) { '1' } else { '0' }
$php = Join-Path $env:USERPROFILE '.config\herd\bin\php84\php.exe'
$process = Start-Process -FilePath $php -ArgumentList @((Join-Path $qa 'preview.php')) -WorkingDirectory $root `
    -RedirectStandardOutput $log -RedirectStandardError $errors -WindowStyle Hidden -PassThru
$deadline = (Get-Date).AddMinutes(20)
while ((Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 5
    if ($process.HasExited) { throw "Preview exited early ($($process.ExitCode)); see $errors" }
    $ready = Select-String -Path $log -Pattern '"url"' -SimpleMatch -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($ready) {
        Set-Content -Path (Join-Path $runtime 'preview.pid') -Value $process.Id
        Write-Output $ready.Line
        exit 0
    }
}
Stop-Process -Id $process.Id
throw 'Preview did not become ready within 20 minutes.'
