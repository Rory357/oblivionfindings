package agent

import (
	"encoding/json"
	"math"
	"regexp"
	"sort"
	"strings"
	"time"
)

type DiagnosticSystem struct {
	WindowsVersion  string   `json:"windows_version"`
	Architecture    string   `json:"architecture"`
	MemoryTotal     *uint64  `json:"memory_total_bytes"`
	MemoryAvailable *uint64  `json:"memory_available_bytes"`
	Uptime          *uint64  `json:"uptime_seconds"`
	CPU             *float64 `json:"cpu_percent"`
	Disks           []Disk   `json:"disks"`
}
type DiagnosticSoftware struct {
	Name    string `json:"name"`
	Version string `json:"version"`
}
type DiagnosticSnapshot struct {
	Schema     int                  `json:"schema"`
	ObservedAt string               `json:"observed_at"`
	System     DiagnosticSystem     `json:"system"`
	Software   []DiagnosticSoftware `json:"software"`
	Services   []Service            `json:"services"`
}
type DiagnosticEnvelope struct {
	ServerOrigin string              `json:"server_origin,omitempty"`
	Protocol     int                 `json:"protocol"`
	Available    bool                `json:"available"`
	Snapshot     *DiagnosticSnapshot `json:"snapshot,omitempty"`
}

var diagnosticVersion = regexp.MustCompile(`^[0-9]{1,9}(\.[0-9]{1,9}){1,5}$`)
var windowsBuild = regexp.MustCompile(`(?:^| )([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,8}(?:\.[0-9]{1,8})?)$`)
var driveLetter = regexp.MustCompile(`^[A-Z]:$`)

const diagnosticMaxBytes uint64 = 9007199254740991

// Only fixed field names, numeric versions, bounded counters and known services
// leave this projection. Hostnames, publisher strings, users, paths, raw probe
// errors, all other software and every machine credential are excluded.
func projectDiagnostics(heartbeat Heartbeat) DiagnosticSnapshot {
	inventory := heartbeat.Inventory
	system := DiagnosticSystem{WindowsVersion: "unknown", Architecture: "unknown", Disks: []Disk{}}
	if match := windowsBuild.FindStringSubmatch(inventory.OSVersion); len(match) > 1 {
		system.WindowsVersion = match[1]
	}
	if inventory.Architecture == "amd64" || inventory.Architecture == "arm64" {
		system.Architecture = inventory.Architecture
	}
	if inventory.MemoryTotal != nil && *inventory.MemoryTotal > 0 && *inventory.MemoryTotal <= diagnosticMaxBytes {
		value := *inventory.MemoryTotal
		system.MemoryTotal = &value
		if inventory.MemoryAvailable != nil && *inventory.MemoryAvailable <= value {
			v := *inventory.MemoryAvailable
			system.MemoryAvailable = &v
		}
	}
	if inventory.Uptime != nil && *inventory.Uptime <= 3155760000 {
		v := *inventory.Uptime
		system.Uptime = &v
	}
	if inventory.CPU != nil && !math.IsNaN(*inventory.CPU) && !math.IsInf(*inventory.CPU, 0) && *inventory.CPU >= 0 && *inventory.CPU <= 100 {
		v := math.Round(*inventory.CPU*10) / 10
		system.CPU = &v
	}
	seen := map[string]bool{}
	for _, disk := range inventory.Disks {
		if driveLetter.MatchString(disk.Name) && disk.Total > 0 && disk.Total <= diagnosticMaxBytes && disk.Free <= disk.Total && !seen[disk.Name] && len(system.Disks) < 8 {
			system.Disks = append(system.Disks, disk)
			seen[disk.Name] = true
		}
	}
	sort.Slice(system.Disks, func(i, j int) bool { return system.Disks[i].Name < system.Disks[j].Name })
	software := []DiagnosticSoftware{}
	products := map[string]string{"microsoft edge": "microsoft_edge", "google chrome": "google_chrome", "microsoft teams": "microsoft_teams", "teams machine-wide installer": "teams_installer"}
	seen = map[string]bool{}
	for _, item := range inventory.Software {
		name := products[strings.ToLower(item.Name)]
		if name != "" && !seen[name] && item.Version != nil && diagnosticVersion.MatchString(*item.Version) {
			software = append(software, DiagnosticSoftware{Name: name, Version: *item.Version})
			seen[name] = true
		}
	}
	sort.Slice(software, func(i, j int) bool { return software[i].Name < software[j].Name })
	services := []Service{}
	known := map[string]string{"spooler": "print_spooler", "wuauserv": "windows_update", "bits": "background_transfer", "windefend": "windows_defender", "mpssvc": "windows_firewall"}
	seen = map[string]bool{}
	for _, item := range inventory.Services {
		name := known[strings.ToLower(item.Name)]
		if name != "" && !seen[name] {
			state := item.State
			if state != "running" && state != "stopped" {
				state = "unknown"
			}
			services = append(services, Service{Name: name, State: state})
			seen[name] = true
		}
	}
	sort.Slice(services, func(i, j int) bool { return services[i].Name < services[j].Name })
	return DiagnosticSnapshot{Schema: 1, ObservedAt: heartbeat.ObservedAt, System: system, Software: software, Services: services}
}
func (m *StatusMonitor) observedDiagnostics(payload []byte) {
	var heartbeat Heartbeat
	var data []byte
	if len(payload) <= 1024*1024 && json.Unmarshal(payload, &heartbeat) == nil {
		when, err := time.Parse(time.RFC3339, heartbeat.ObservedAt)
		if err == nil && time.Since(when) <= 6*time.Minute && time.Until(when) <= 30*time.Second {
			snapshot := projectDiagnostics(heartbeat)
			data, _ = json.Marshal(snapshot)
		}
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	m.diagnostics = data
}
func (m *StatusMonitor) Diagnostics() DiagnosticEnvelope {
	m.mu.RLock()
	defer m.mu.RUnlock()
	result := DiagnosticEnvelope{Protocol: 1}
	if m.status.Connection != "connected" || m.status.ServerOrigin == "" || len(m.diagnostics) == 0 {
		return result
	}
	var snapshot DiagnosticSnapshot
	if json.Unmarshal(m.diagnostics, &snapshot) != nil {
		return result
	}
	when, err := time.Parse(time.RFC3339, snapshot.ObservedAt)
	if err != nil || time.Since(when) > 6*time.Minute || time.Until(when) > 30*time.Second {
		return result
	}
	result.ServerOrigin = m.status.ServerOrigin
	result.Available = true
	result.Snapshot = &snapshot
	return result
}
