param([string]$DotNet='dotnet',[string]$OutputRoot='',[string]$BackendX64='',[string]$BackendArm64='')
$ErrorActionPreference='Stop'
if (-not [OperatingSystem]::IsWindows()) { throw 'The graphical installer is Windows only.' }
$projectRoot=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
if (-not $OutputRoot) { $OutputRoot=Join-Path $projectRoot '.endpoint-tools' }
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot)
if ([bool]$BackendX64 -ne [bool]$BackendArm64) { throw 'Supply both approved architecture backends, or neither for a non-installing development build.' }
function Get-InstallerMachine([string]$path) {
 $stream=[IO.File]::OpenRead($path)
 $reader=[IO.BinaryReader]::new($stream)
 try {
  if ($reader.ReadUInt16() -ne 0x5a4d) { throw 'The installer backend is not a Windows executable.' }
  $stream.Position=0x3c
  $offset=$reader.ReadInt32()
  if ($offset -lt 64 -or $offset -gt $stream.Length-6) { throw 'The installer backend has an invalid header.' }
  $stream.Position=$offset
  if ($reader.ReadUInt32() -ne 0x4550) { throw 'The installer backend has an invalid Windows header.' }
  return $reader.ReadUInt16()
 } finally { $reader.Dispose(); $stream.Dispose() }
}
$previous=@{}
foreach ($name in @('DOTNET_CLI_HOME','DOTNET_CLI_TELEMETRY_OPTOUT','DOTNET_NOLOGO','NUGET_PACKAGES')) { $previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process') }
Push-Location $PSScriptRoot
try {
 $env:DOTNET_CLI_HOME=Join-Path $OutputRoot 'dotnet-home'
 $env:DOTNET_CLI_TELEMETRY_OPTOUT='1'
 $env:DOTNET_NOLOGO='1'
 $env:NUGET_PACKAGES=Join-Path $OutputRoot 'n'
 & $DotNet run --project Obli.Setup.Tests/Obli.Setup.Tests.csproj --configuration Release
 if ($LASTEXITCODE -ne 0) { throw 'Installer checks failed.' }
 foreach ($architecture in @('x64','arm64')) {
  $backend=if ($architecture -eq 'x64') {$BackendX64} else {$BackendArm64}
  $digest='unconfigured'
  $destination=Join-Path $OutputRoot ("obli-setup-windows-"+$architecture)
  if ($backend) {
   $backend=(Resolve-Path -LiteralPath $backend).Path
   if ((Get-AuthenticodeSignature -LiteralPath $backend).Status -ne 'Valid') {throw 'The installer backend must have a valid publisher signature.'}
   $expectedMachine=if ($architecture -eq 'x64') {0x8664} else {0xaa64}
   if ((Get-InstallerMachine $backend) -ne $expectedMachine) {throw 'The installer backend architecture does not match.'}
   $digest=(Get-FileHash -LiteralPath $backend -Algorithm SHA256).Hash.ToLowerInvariant()
  }
  & $DotNet publish Obli.Setup/Obli.Setup.csproj --configuration Release --runtime ("win-"+$architecture) --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:DebugType=None ("-p:BackendSha256="+$digest) -o $destination --nologo
  if ($LASTEXITCODE -ne 0) { throw ("Installer publish failed: "+$architecture) }
  if ($backend) {
   $target=Join-Path $destination 'oblivion-setup-core.exe'
   Copy-Item -LiteralPath $backend -Destination $target
   if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant() -ne $digest) {throw 'Backend changed while being packaged.'}
  } elseif (Test-Path -LiteralPath (Join-Path $destination 'oblivion-setup-core.exe')) {
   throw 'Development output contains a previous backend; use a fresh output directory.'
  }
 }
} finally {
 Pop-Location
 foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
}
