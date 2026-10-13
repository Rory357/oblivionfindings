package agent

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

func TestPendingHeartbeatSurvivesRestartWithoutReprobing(t *testing.T) {
	store := newTestStore(t)
	original := Heartbeat{Sequence: 42, ObservedAt: time.Now().UTC().Format(time.RFC3339), AgentVersion: "0.1.0", BootID: testAgentUUID}
	data, err := json.Marshal(original)
	if err != nil {
		t.Fatal(err)
	}
	state := State{Version: 1, Credential: strings.Repeat("a", 64), AgentUUID: testAgentUUID, Sequence: 42, PendingHeartbeat: data}
	if err = store.Save(state); err != nil {
		t.Fatal(err)
	}
	recovered, err := store.Load()
	if err != nil {
		t.Fatal(err)
	}
	got, err := PrepareHeartbeat(context.Background(), store, &recovered, "0.1.0")
	if err != nil || string(got) != string(data) || recovered.Sequence != 42 {
		t.Fatalf("pending observation changed on retry: %v", err)
	}
}
func TestProbeTextIsBoundedWithoutControlCharacters(t *testing.T) {
	got := safeText(" \x00host\n" + strings.Repeat("é", 400))
	if len([]rune(got)) != 300 || strings.ContainsAny(got, "\x00\n") {
		t.Fatal("unsafe probe text")
	}
	if textPtr("") != nil {
		t.Fatal("empty optional text should remain unknown")
	}
}
func TestLiveReadOnlyProbe(t *testing.T) {
	if os.Getenv("OBLIVION_AGENT_PROBE_TEST") != "1" {
		t.Skip("explicit local read-only host probe required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), time.Minute)
	defer cancel()
	first, err := Collect(ctx, "0.1.0", 1)
	if err != nil {
		t.Fatal(err)
	}
	second, err := Collect(ctx, "0.1.0", 2)
	if err != nil {
		t.Fatal(err)
	}
	if first.BootID != second.BootID || !uuidPattern.MatchString(first.BootID) {
		t.Fatal("boot identity did not remain stable")
	}
	if first.Inventory.Hostname == "" || first.Inventory.OSVersion == "" {
		t.Fatal("identity observations absent")
	}
	if first.Inventory.MemoryTotal == nil || first.Inventory.Uptime == nil {
		t.Fatal("core health probes unavailable")
	}
	encoded, err := json.Marshal(first)
	if err != nil || len(encoded) > 1024*1024 {
		t.Fatal("invalid heartbeat encoding")
	}
	// No inventory content is printed, retained as a fixture or transmitted.
}
