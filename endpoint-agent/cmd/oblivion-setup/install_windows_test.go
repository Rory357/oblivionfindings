package main

import (
	"bytes"
	"context"
	"strings"
	"testing"

	"oblivion.local/endpoint-agent/internal/setup"
)

func TestUnconfiguredSetupCannotReachMachineInstallation(t *testing.T) {
	old := publisherPublicKey
	publisherPublicKey = ""
	t.Cleanup(func() { publisherPublicKey = old })

	_, err := installOnPlatform(context.Background(), nil, setup.Profiles{}, "", "")
	if err == nil || !strings.Contains(err.Error(), "no trusted release publisher") {
		t.Fatal("unconfigured setup passed the activation boundary", err)
	}
}

func TestUnconfiguredSetupCannotReachMachineServiceRepair(t *testing.T) {
	old := publisherPublicKey
	publisherPublicKey = ""
	t.Cleanup(func() { publisherPublicKey = old })

	_, err := repairOnPlatform(context.Background(), nil, "https://care.example.test", "", "computer")
	if err == nil || !strings.Contains(err.Error(), "no trusted release publisher") {
		t.Fatal("unconfigured setup reached repair", err)
	}
}

func TestUnconfiguredSetupCannotReadProtectedInstalledRepairDetails(t *testing.T) {
	old := publisherPublicKey
	publisherPublicKey = ""
	t.Cleanup(func() { publisherPublicKey = old })

	_, err := reviewRepairOnPlatform(context.Background(), nil)
	if err == nil || !strings.Contains(err.Error(), "no trusted release publisher") {
		t.Fatal("unconfigured review reached installed machine data", err)
	}
	archive, _, _ := reviewFixture(t)
	publisherPublicKey = ""
	var output bytes.Buffer
	err = run([]string{"review-repair", "--package", archive}, &output)
	if err == nil || !strings.Contains(err.Error(), "no trusted release publisher") || output.Len() != 0 {
		t.Fatal("archive authority bypassed unconfigured read controller", err)
	}
}
