//go:build windows

package main

import (
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
