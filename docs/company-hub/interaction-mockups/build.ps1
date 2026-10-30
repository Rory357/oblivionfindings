$ErrorActionPreference = 'Stop'
$artifactDir = $PSScriptRoot
$projectRoot = [IO.Path]::GetFullPath((Join-Path $artifactDir '../../..'))
$visualRoot = 'C:\Users\steph\.codex\visualizations\2026\10\13\01a136b7-77ea-7c12-a824-ea96761a44e6'
Add-Type -AssemblyName System.Drawing
$imageNames = @{garden='community-garden';together='colleagues-together';welcome='workplace-welcome'}
$encoded = @{}
foreach ($entry in $imageNames.GetEnumerator()) {
  $sourcePath = Join-Path $projectRoot ('public/images/company-hub/' + $entry.Value + '.png')
  $original = [Drawing.Image]::FromFile($sourcePath)
  $width = 900
  $height = [int]($original.Height * $width / $original.Width)
  $thumbnail = New-Object Drawing.Bitmap($width, $height)
  $graphics = [Drawing.Graphics]::FromImage($thumbnail)
  $graphics.InterpolationMode = [Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $graphics.DrawImage($original,0,0,$width,$height)
  $memory = New-Object IO.MemoryStream
  $thumbnail.Save($memory,[Drawing.Imaging.ImageFormat]::Jpeg)
  $encoded[$entry.Key] = 'data:image/jpeg;base64,' + [Convert]::ToBase64String($memory.ToArray())
  $graphics.Dispose(); $thumbnail.Dispose(); $original.Dispose(); $memory.Dispose()
}
$imageScript = 'const images=' + ($encoded | ConvertTo-Json -Compress) + ';'
$familyFiles = @('people.js','community.js','reading.js','personal.js','designer.js','publishing.js','refinements.js','clarity.js','editor-finish.js','journey-finish.js','hub-icons.js','connected-data.js','connected.js')
$families = ($familyFiles | Where-Object { Test-Path (Join-Path $artifactDir $_) } | ForEach-Object { Get-Content (Join-Path $artifactDir $_) -Raw }) -join "`n"
$core = (Get-Content (Join-Path $artifactDir 'core.js') -Raw).Replace('/*FAMILIES*/',$families)
$refinedStyles = '<style>' + (Get-Content (Join-Path $artifactDir 'refinements.css') -Raw) + (Get-Content (Join-Path $artifactDir 'clarity.css') -Raw) + (Get-Content (Join-Path $artifactDir 'journey-finish.css') -Raw) + (Get-Content (Join-Path $artifactDir 'hub-icons.css') -Raw) + (Get-Content (Join-Path $artifactDir 'connected.css') -Raw) + '</style>'
$fragment = (Get-Content (Join-Path $artifactDir 'frame.html') -Raw).Replace('<!--SCRIPTS-->',($refinedStyles + '<script>' + $imageScript + "`n" + $core + '</script>'))
$utf8 = New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $visualRoot 'company-hub-interactions.html'),$fragment,$utf8)
$iconScript = if (Test-Path (Join-Path $artifactDir 'preview-icons.js')) { '<script>' + (Get-Content (Join-Path $artifactDir 'preview-icons.js') -Raw) + '</script>' } else { '' }
$standalone = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Company Hub — interaction collection</title><style>html{color-scheme:light dark}body{margin:0;background:light-dark(#eeeff3,#1c1d26);padding:18px;font-family:system-ui}#hub-interactions{max-width:1440px;margin:auto;height:calc(100vh - 36px);min-height:820px}</style></head><body>' + $iconScript + $fragment + '</body></html>'
[IO.File]::WriteAllText((Join-Path $artifactDir 'index.html'),$standalone,$utf8)
Write-Output ('Built interaction collection: ' + [Text.Encoding]::UTF8.GetByteCount($fragment) + ' bytes')
