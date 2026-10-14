param([string]$DotNet='dotnet',[string]$OutputRoot='')
$ErrorActionPreference='Stop'
if (-not [OperatingSystem]::IsWindows()) { throw 'The Obli desktop helper is Windows only.' }
$projectRoot=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
if (-not $OutputRoot) { $OutputRoot=Join-Path $projectRoot '.endpoint-tools' }
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot)
$previous=@{}
foreach ($name in @('DOTNET_CLI_HOME','DOTNET_CLI_TELEMETRY_OPTOUT','DOTNET_NOLOGO','NUGET_PACKAGES')) { $previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process') }
Push-Location $PSScriptRoot
try {
 $env:DOTNET_CLI_HOME=Join-Path $OutputRoot 'dotnet-home'
 $env:DOTNET_CLI_TELEMETRY_OPTOUT='1'
 $env:DOTNET_NOLOGO='1'
 # Avoid the Windows 260-character copy-path failure for long resource names.
 $env:NUGET_PACKAGES=Join-Path $OutputRoot 'n'
 & $DotNet run --project Obli.Tests/Obli.Tests.csproj --configuration Release
 if ($LASTEXITCODE -ne 0) { throw 'Native helper checks failed.' }
 foreach ($architecture in @('x64','arm64')) {
  & $DotNet publish Obli/Obli.csproj --configuration Release --runtime "win-$architecture" --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=false -o (Join-Path $OutputRoot "obli-windows-$architecture") --nologo
  if ($LASTEXITCODE -ne 0) { throw "Native helper build failed: $architecture" }
 }
 & $DotNet Obli/bin/Release/net10.0-windows/obli.dll --render-preview (Join-Path $OutputRoot 'obli-native-preview.png')
 if ($LASTEXITCODE -ne 0) { throw 'Native helper render failed.' }
} finally {
 Pop-Location
 foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
}
