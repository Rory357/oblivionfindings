package agent

import (
	"encoding/json"
	"os"
	"testing"
	"time"
)

func TestScriptReadinessHeartbeatWire(t *testing.T) {
	// Original Go serialization of synthetic data; never probes this machine.
	heartbeat := Heartbeat{
		Sequence: 2, ObservedAt: "2026-10-21T00:00:00Z", AgentVersion: "0.1.0",
		BootID: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
		Inventory: Inventory{Hostname: "SYNTHETIC-SCRIPT-PC", OSVersion: "Synthetic Windows",
			Architecture: "amd64", Disks: []Disk{}, Software: []Software{},
			Services: []Service{}, ProbeErrors: []string{}},
	}
	absent, err := json.Marshal(heartbeat)
	if err != nil {
		t.Fatal(err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(absent, &decoded); err != nil {
		t.Fatal(err)
	}
	if _, exists := decoded["script_actions"]; exists {
		t.Fatal("default heartbeat advertised an unimplemented executor")
	}
	heartbeat.ScriptActions = &ScriptCapability{1, true, true, "reviewed_script"}
	heartbeat.Sequence = 1
	ready, err := json.Marshal(heartbeat)
	if err != nil {
		t.Fatal(err)
	}
	var original Heartbeat
	if err := json.Unmarshal(ready, &original); err != nil || original.ScriptActions == nil || *original.ScriptActions != *heartbeat.ScriptActions {
		t.Fatal("script capability did not round-trip")
	}
	if output := os.Getenv("OBLI_SCRIPT_READINESS_CORPUS"); output != "" {
		corpus := struct {
			Version   int `json:"version"`
			Documents []struct {
				Name string `json:"name"`
				JSON string `json:"request_json"`
			} `json:"documents"`
		}{Version: 1}
		for _, row := range []struct {
			name  string
			bytes []byte
		}{{"absent", absent}, {"ready", ready}} {
			corpus.Documents = append(corpus.Documents, struct {
				Name string `json:"name"`
				JSON string `json:"request_json"`
			}{row.name, string(row.bytes)})
		}
		bytes, err := json.MarshalIndent(corpus, "", "  ")
		if err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(output, bytes, 0600); err != nil {
			t.Fatal(err)
		}
	}
}

func TestScriptReadinessCannotSurvivePendingHeartbeatReuse(t *testing.T) {
	now := time.Date(2026, 10, 21, 0, 0, 0, 0, time.UTC)
	for _, capability := range []*ScriptCapability{
		{1, true, true, "reviewed_script"},
		{},
		{2, true, true, "reviewed_script"},
		{1, false, false, "reviewed_script"},
	} {
		original, err := json.Marshal(Heartbeat{ObservedAt: now.Format(time.RFC3339), ScriptActions: capability})
		if err != nil {
			t.Fatal(err)
		}
		var pending Heartbeat
		if err := json.Unmarshal(original, &pending); err != nil {
			t.Fatal(err)
		}
		if pendingHeartbeatReusable(pending, false, now) {
			t.Fatal("this build reused a pending script-readiness claim")
		}
	}
	if !pendingHeartbeatReusable(Heartbeat{ObservedAt: now.Format(time.RFC3339)}, false, now) {
		t.Fatal("ordinary lost-acknowledgement recovery was broken")
	}
	if pendingHeartbeatReusable(Heartbeat{ObservedAt: now.Add(-90 * time.Second).Format(time.RFC3339)}, false, now) {
		t.Fatal("stale heartbeat became reusable")
	}
}
