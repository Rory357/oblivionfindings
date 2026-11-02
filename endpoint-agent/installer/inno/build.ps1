# Builds the one-file Windows installer (oblivion-setup-windows.exe) with Inno Setup.
# CI calls it from .github/workflows/site-hub-release.yml; it installs nothing itself.
#
#   ./build.ps1 -Iscc <path to ISCC.exe> -PayloadDir <folder> -Version 1.3.1 -OutputDir <folder> [-DefaultServer host]
#
# PayloadDir must hold the publisher-signed release package and the matching setup engine:
#   oblivion-setup-core-amd64.exe, oblivion-windows-amd64.zip
# and, for Obli on Arm PCs, optionally: oblivion-setup-core-arm64.exe, oblivion-windows-arm64.zip
param(
  [Parameter(Mandatory = $true)][string]$Iscc,
  [Parameter(Mandatory = $true)][string]$PayloadDir,
  [Parameter(Mandatory = $true)][string]$Version,
  [Parameter(Mandatory = $true)][string]$OutputDir,
  [string]$DefaultServer = ''
)
$ErrorActionPreference = 'Stop'
if ($Version -notmatch '^(0|[1-9][0-9]*)[.](0|[1-9][0-9]*)[.](0|[1-9][0-9]*)$') { throw "Version $Version is not x.y.z" }
if ($DefaultServer -and $DefaultServer -notmatch '^[a-z0-9.-]+(:[0-9]{1,5})?$') { throw 'DefaultServer must be a plain host name' }
$PayloadDir = (Resolve-Path -LiteralPath $PayloadDir).Path
foreach ($name in @('oblivion-setup-core-amd64.exe', 'oblivion-windows-amd64.zip')) {
  if (-not (Test-Path -LiteralPath (Join-Path $PayloadDir $name) -PathType Leaf)) { throw "Missing $name in $PayloadDir" }
}
$arm = @('oblivion-setup-core-arm64.exe', 'oblivion-windows-arm64.zip') | ForEach-Object { Test-Path -LiteralPath (Join-Path $PayloadDir $_) -PathType Leaf }
if (($arm -contains $true) -and ($arm -contains $false)) { throw 'Supply both Arm files (engine and package), or neither' }

# "Installs to C:\Program Files\Oblivion · about 380 MB" (screen 4): the unpacked package size.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::OpenRead((Join-Path $PayloadDir 'oblivion-windows-amd64.zip'))
try { $bytes = ($zip.Entries | Measure-Object -Property Length -Sum).Sum } finally { $zip.Dispose() }
$sizeMB = [int][Math]::Ceiling($bytes / 1MB / 10) * 10

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
$OutputDir = (Resolve-Path -LiteralPath $OutputDir).Path
$script = Join-Path $PSScriptRoot 'oblivion-setup.iss'
& $Iscc /Q "/DAppVersion=$Version" "/DPayloadDir=$PayloadDir" "/DDefaultServer=$DefaultServer" "/DInstallSizeMB=$sizeMB" "/DOutputDir=$OutputDir" $script
if ($LASTEXITCODE -ne 0) { throw "Inno Setup failed ($LASTEXITCODE)" }
$built = Join-Path $OutputDir 'oblivion-setup-windows.exe'
if (-not (Test-Path -LiteralPath $built -PathType Leaf)) { throw 'The installer was not produced' }
Write-Host "Built $built ($([Math]::Round((Get-Item -LiteralPath $built).Length / 1MB)) MB, installs about $sizeMB MB)"
