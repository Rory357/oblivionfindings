package agent

import (
	"context"
	"encoding/json"
	"errors"
	"golang.org/x/sys/windows"
	"path/filepath"
	"time"
)

// Fixed read-only probe; no server-supplied text enters PowerShell.
const windowsProbe = `$ErrorActionPreference='Stop'
$errors = [System.Collections.Generic.List[string]]::new()
$result = [ordered]@{hostname='';os_version='';architecture='';memory_total_bytes=$null;memory_available_bytes=$null;uptime_seconds=$null;cpu_percent=$null;disks=@();software=@();software_complete=$false;services=@();services_complete=$false;probe_errors=@()}
$os = Get-CimInstance -ClassName Win32_OperatingSystem
$boot = $os.LastBootUpTime.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ss.fffffffZ')
$result.os_version = $os.Caption + ' ' + $os.Version
$result.memory_total_bytes = [UInt64]$os.TotalVisibleMemorySize * 1024
$result.memory_available_bytes = [UInt64]$os.FreePhysicalMemory * 1024
$result.uptime_seconds = [UInt64][Math]::Max(0,([DateTime]::UtcNow - $os.LastBootUpTime.ToUniversalTime()).TotalSeconds)
try { $cpu = @(Get-CimInstance -ClassName Win32_Processor); if ($cpu.Count -gt 0) { $result.cpu_percent = [double](($cpu | Measure-Object -Property LoadPercentage -Average).Average) } else { $errors.Add('cpu') } } catch { $errors.Add('cpu') }
try { $result.disks = @(Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType=3' | Select-Object -First 64 | ForEach-Object { [ordered]@{name=$_.DeviceID;total_bytes=[UInt64]$_.Size;free_bytes=[UInt64]$_.FreeSpace} }) } catch { $errors.Add('disks') }
try {
    $software = @()
    foreach ($root in @('HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall','HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall')) {
        if (Test-Path -LiteralPath $root -ErrorAction Stop) {
            $software += @(Get-ChildItem -LiteralPath $root -ErrorAction Stop | ForEach-Object { Get-ItemProperty -LiteralPath $_.PSPath -ErrorAction Stop } | Where-Object { $_.DisplayName } | ForEach-Object { [ordered]@{name=[string]$_.DisplayName;version=[string]$_.DisplayVersion;publisher=[string]$_.Publisher} })
        }
    }
    $result.software = @($software | Select-Object -First 5000)
    $result.software_complete = $software.Count -le 5000
} catch { $errors.Add('software') }
try {
    $services = @(Get-Service)
    $result.services = @($services | Select-Object -First 1000 | ForEach-Object { [ordered]@{name=$_.Name;state=$(if ($_.Status -eq 'Running') {'running'} elseif ($_.Status -eq 'Stopped') {'stopped'} else {'unknown'})} })
    $result.services_complete = $services.Count -le 1000
} catch { $errors.Add('services') }
$result.probe_errors = @($errors)
[ordered]@{boot=$boot;inventory=$result} | ConvertTo-Json -Depth 6 -Compress
`

func probe(ctx context.Context, inventory *Inventory) (string, error) {
	system, err := windows.GetSystemDirectory()
	if err != nil {
		return "", errors.New("Windows system directory unavailable")
	}
	ctx, cancel := context.WithTimeout(ctx, 25*time.Second)
	defer cancel()
	output, err := readCommand(ctx, filepath.Join(system, "WindowsPowerShell", "v1.0", "powershell.exe"), "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", windowsProbe)
	if err != nil {
		return "", err
	}
	var data struct {
		Boot      string    `json:"boot"`
		Inventory Inventory `json:"inventory"`
	}
	if err = json.Unmarshal(output, &data); err != nil || data.Boot == "" {
		return "", errors.New("Windows inventory unavailable")
	}
	data.Inventory.Hostname = inventory.Hostname
	data.Inventory.Architecture = inventory.Architecture
	data.Inventory.OSVersion = safeText(data.Inventory.OSVersion)
	for i := range data.Inventory.Software {
		item := &data.Inventory.Software[i]
		item.Name = safeText(item.Name)
		if item.Version != nil {
			item.Version = textPtr(*item.Version)
		}
		if item.Publisher != nil {
			item.Publisher = textPtr(*item.Publisher)
		}
	}
	for i := range data.Inventory.Services {
		data.Inventory.Services[i].Name = safeText(data.Inventory.Services[i].Name)
	}
	*inventory = data.Inventory
	return bootUUID(data.Boot), nil
}
