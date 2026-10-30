//go:build windows

package main

import (
	"bytes"
	"encoding/json"
	"oblivion.local/endpoint-agent/internal/agent"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRejectedProfileCannotCreateStateOrContactServer(t *testing.T) {
	root := t.TempDir()
	profile := filepath.Join(root, "invalid.json")
	stateDir := filepath.Join(root, "state")
	if err := os.WriteFile(profile, []byte("{\"version\":99}"), 0600); err != nil {
		t.Fatal(err)
	}
	err := run([]string{"enrol", "--state-dir", stateDir, "--bootstrap", profile})
	if err == nil {
		t.Fatal("invalid profile was accepted")
	}
	if _, err = os.Stat(stateDir); !os.IsNotExist(err) {
		t.Fatal("invalid profile changed installation state")
	}
}
func TestCommandsRejectAmbiguousInstallationAndServiceArguments(t *testing.T) {
	for _, args := range [][]string{
		{"service", "--state-dir", t.TempDir()},
		{"enrol", "--bootstrap", "unused.json", "--server", "https://care.example.nz"},
		{"run", "--server", "https://care.example.nz"},
		{"probe", "extra"},
		{"status", "extra"},
		{"status", "--server", "https://care.example.nz"},
		{"unknown"},
	} {
		if err := run(args); err == nil {
			t.Fatalf("unsupported command accepted: %v", args)
		}
	}
}
func TestDefaultStateDirectoryDoesNotTrustProcessEnvironmentOverride(t *testing.T) {
	fake := t.TempDir()
	t.Setenv("ProgramData", fake)
	actual, err := defaultStateDirectory()
	if err != nil {
		t.Fatal(err)
	}
	if strings.EqualFold(actual, filepath.Join(fake, "Oblivion", "Endpoint")) {
		t.Fatal("service data location came from the process environment")
	}
}
func TestStatusReportsMissingAndEnrolledStateWithoutMutatingOrLeakingSecrets(t *testing.T) {
	root := t.TempDir()
	missingDir := filepath.Join(root, "unconfigured")
	var missingOut bytes.Buffer
	if err := runWithOutput([]string{"status", "--state-dir", missingDir}, &missingOut); err != nil {
		t.Fatalf("status failed for missing state dir: %v", err)
	}
	if _, err := os.Stat(missingDir); !os.IsNotExist(err) {
		t.Fatal("status created missing state directory")
	}
	var missingStatus agent.LocalStatus
	if err := json.Unmarshal(missingOut.Bytes(), &missingStatus); err != nil {
		t.Fatalf("invalid status json: %v", err)
	}
	if missingStatus.Configured || missingStatus.Enrolled || missingStatus.Platform != "windows" {
		t.Fatalf("unexpected missing status: %+v", missingStatus)
	}

	enrolledDir := filepath.Join(root, "enrolled")
	store, err := agent.OpenStore(enrolledDir)
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()

	secretCredential := strings.Repeat("a", 64)
	secretServerKey := "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
	agentUUID := "11111111-2222-4333-8444-555555555555"
	if err := store.Save(agent.State{
		Version:    1,
		ServerURL:  "https://care.example.nz",
		ServerKey:  secretServerKey,
		AgentUUID:  agentUUID,
		Credential: secretCredential,
		Sequence:   42,
	}); err != nil {
		t.Fatal(err)
	}

	// Store lock is still held here to prove status works concurrently with the running Windows service.
	var enrolledOut bytes.Buffer
	if err := runWithOutput([]string{"status", "--state-dir", enrolledDir}, &enrolledOut); err != nil {
		t.Fatalf("status failed while store lock held: %v", err)
	}
	raw := enrolledOut.String()
	for _, forbidden := range []string{secretCredential, secretServerKey, "credential", "server_key", "enrollment_token"} {
		if strings.Contains(raw, forbidden) {
			t.Fatalf("status output leaked secret material %q: %s", forbidden, raw)
		}
	}
	var enrolledStatus agent.LocalStatus
	if err := json.Unmarshal(enrolledOut.Bytes(), &enrolledStatus); err != nil {
		t.Fatalf("invalid enrolled status json: %v", err)
	}
	if !enrolledStatus.Configured || !enrolledStatus.Enrolled || enrolledStatus.AgentUUID != agentUUID || enrolledStatus.ServerURL != "https://care.example.nz" || enrolledStatus.Sequence != 42 {
		t.Fatalf("unexpected enrolled status: %+v", enrolledStatus)
	}
}
