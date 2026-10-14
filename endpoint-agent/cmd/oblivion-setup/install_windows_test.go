package main

import (
	"bytes"
	"context"
	"strings"
	"testing"

	"oblivion.local/endpoint-agent/internal/setup"
)

func TestUnsignedSetupCannotReachMachineInstallation(t *testing.T) {
	// The running Go test executable is deliberately unsigned. A nil package would
	// fail if setup proceeded into installer mutation, so rejection must come first.
	_, err := installOnPlatform(context.Background(), nil, setup.Profiles{}, "", "")
	if err == nil || !strings.Contains(err.Error(), "signature") {
		t.Fatal("unsigned setup passed the activation boundary", err)
	}
}

func TestUnsignedSetupCannotReachMachineServiceRepair(t *testing.T) {
	_, err := repairOnPlatform(context.Background(), nil, "https://care.example.test", "", "computer")
	if err == nil || !strings.Contains(err.Error(), "signature") {
		t.Fatal("unsigned setup reached repair", err)
	}
}

func TestRealPublisherArchiveDoesNotBypassRepairSetupSignature(t *testing.T) {
	archive, _, profile := reviewFixture(t)
	var output bytes.Buffer
	err := run([]string{"repair-services", "--package", archive, "--role", "computer", "--confirm-server", profile.ServerURL, "--confirm-service-repair"}, &output)
	if err == nil || !strings.Contains(err.Error(), "signature") || output.Len() != 0 {
		t.Fatal("valid archive bypassed signed repair controller", err)
	}
}

func TestUnsignedSetupCannotReadProtectedInstalledRepairDetails(t *testing.T) {
	_, err := reviewRepairOnPlatform(context.Background(), nil)
	if err == nil || !strings.Contains(err.Error(), "signature") {
		t.Fatal("unsigned review reached installed machine data", err)
	}
	archive, _, _ := reviewFixture(t)
	var output bytes.Buffer
	err = run([]string{"review-repair", "--package", archive}, &output)
	if err == nil || !strings.Contains(err.Error(), "signature") || output.Len() != 0 {
		t.Fatal("archive authority bypassed signed read controller", err)
	}
}
