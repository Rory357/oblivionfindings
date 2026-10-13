package agent

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func diagnosticFixture() Heartbeat {
	total, free, uptime := uint64(16000), uint64(4000), uint64(500)
	cpu := 25.06
	version := "130.0.1.2"
	secret := "private publisher"
	return Heartbeat{ObservedAt: time.Now().UTC().Format(time.RFC3339), Inventory: Inventory{
		Hostname: "SECRET-PERSON", OSVersion: "Microsoft Windows 11 Enterprise 10.0.26100", Architecture: "amd64",
		MemoryTotal: &total, MemoryAvailable: &free, Uptime: &uptime, CPU: &cpu,
		Disks:       []Disk{{Name: "C:", Total: 10000, Free: 2000}, {Name: "SECRET_PATH", Total: 10000, Free: 2000}},
		Software:    []Software{{Name: "Microsoft Edge", Version: &version, Publisher: &secret}, {Name: "SECRET-APPLICATION", Version: &version}},
		Services:    []Service{{Name: "Spooler", State: "running"}, {Name: "SECRET-SERVICE", State: "running"}},
		ProbeErrors: []string{"SECRET-ERROR"},
	}}
}
func TestDiagnosticsProjectionExcludesPrivateInventoryAndCopesWithUnknownMetrics(t *testing.T) {
	source := diagnosticFixture()
	snapshot := projectDiagnostics(source)
	data, err := json.Marshal(snapshot)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(data), "SECRET") || strings.Contains(string(data), "publisher") || len(data) > 4096 {
		t.Fatal("Private or excessive diagnostic content")
	}
	if snapshot.System.WindowsVersion != "10.0.26100" || *snapshot.System.CPU != 25.1 || len(snapshot.System.Disks) != 1 || len(snapshot.Software) != 1 || len(snapshot.Services) != 1 {
		t.Fatal("Allowed health projection lost")
	}
	*source.Inventory.MemoryTotal = 1
	if *snapshot.System.MemoryTotal != 16000 {
		t.Fatal("Snapshot aliased mutable inventory")
	}
	bad := uint64(99999999999999999)
	cpu := 200.0
	v := "version with private details"
	source.Inventory.MemoryTotal = &bad
	source.Inventory.CPU = &cpu
	source.Inventory.OSVersion = "user@example.test"
	source.Inventory.Software[0].Version = &v
	snapshot = projectDiagnostics(source)
	if snapshot.System.MemoryTotal != nil || snapshot.System.CPU != nil || snapshot.System.WindowsVersion != "unknown" || len(snapshot.Software) != 0 {
		t.Fatal("Invalid values escaped")
	}
}
func TestDiagnosticsCacheRequiresAcceptedFreshConnectionAndReturnsIndependentValues(t *testing.T) {
	m := NewStatusMonitor("test")
	m.bind("https://care.example.test")
	source := diagnosticFixture()
	payload, _ := json.Marshal(source)
	m.observedDiagnostics(payload)
	if m.Diagnostics().Available {
		t.Fatal("Diagnostics available before accepted connection")
	}
	m.connection("connected")
	first := m.Diagnostics()
	if !first.Available || first.Snapshot == nil {
		t.Fatal("Expected diagnostic snapshot")
	}
	first.Snapshot.System.WindowsVersion = "changed"
	if m.Diagnostics().Snapshot.System.WindowsVersion == "changed" {
		t.Fatal("Cache could be changed by consumer")
	}
	m.connection("retrying")
	if m.Diagnostics().Available {
		t.Fatal("Interrupted connection kept diagnostics available")
	}
	m.connection("connected")
	source.ObservedAt = time.Now().Add(-7 * time.Minute).UTC().Format(time.RFC3339)
	payload, _ = json.Marshal(source)
	m.observedDiagnostics(payload)
	if m.Diagnostics().Available {
		t.Fatal("Stale observation available")
	}
	source.ObservedAt = time.Now().Add(time.Minute).UTC().Format(time.RFC3339)
	payload, _ = json.Marshal(source)
	m.observedDiagnostics(payload)
	if m.Diagnostics().Available {
		t.Fatal("Future observation available")
	}
}
