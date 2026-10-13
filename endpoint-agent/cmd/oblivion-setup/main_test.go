package main

import (
	"bytes"
	"encoding/base64"
	"strings"
	"testing"
)

func TestDevelopmentSetupCannotInstallOrOverridePublisherTrust(t *testing.T) {
	original := publisherPublicKey
	t.Cleanup(func() { publisherPublicKey = original })
	var output bytes.Buffer
	publisherPublicKey = ""
	if err := run([]string{"verify", "--package", "missing.zip"}, &output); err == nil || !strings.Contains(err.Error(), "no trusted release publisher") {
		t.Fatal("unconfigured release trust was bypassed")
	}
	publisherPublicKey = base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{7}, 32))
	for _, args := range [][]string{
		{"verify", "--package", "missing.zip", "--public-key", "attacker"},
		{"verify", "--package", "missing.zip", "--allow-unsigned"},
		{"install", "--package", "missing.zip"},
		{"uninstall"},
	} {
		if err := run(args, &output); err == nil {
			t.Fatalf("unsafe or unimplemented installer command accepted: %v", args)
		}
	}
	if output.Len() != 0 {
		t.Fatal("rejected command reported success")
	}
}
func TestSetupVersionDoesNotNeedReleaseAuthority(t *testing.T) {
	var output bytes.Buffer
	if err := run([]string{"version"}, &output); err != nil || strings.TrimSpace(output.String()) != version {
		t.Fatal("version check failed")
	}
}

func TestCollectorAndCombinedArgumentsAreAcceptedOnlyWithRequiredProfiles(t *testing.T) {
	common := []string{"install", "--package", "release.zip", "--confirm-server", "https://care.example.test", "--collector-bootstrap", "site.json", "--confirm-collector-server", "https://collectors.example.test"}
	for _, role := range []string{"site", "both"} {
		args := append(append([]string{}, common...), "--role", role)
		if role == "both" {
			args = append(args, "--bootstrap", "computer.json")
		}
		opt, err := parseOptions(args)
		if err != nil || opt.role != role || opt.collectorBootstrap != "site.json" {
			t.Fatal(role, err)
		}
	}
}
func TestInstallRequiresExplicitDestinationAndSupportedRole(t *testing.T) {
	valid := []string{"install", "--package", "release.zip", "--bootstrap", "profile.json", "--confirm-server", "https://care.example.test", "--role", "computer"}
	if got, err := parseOptions(valid); err != nil || got.confirmedServer != "https://care.example.test" || got.role != "computer" {
		t.Fatal("complete installation arguments rejected", err)
	}
	for _, args := range [][]string{
		{"install", "--package", "release.zip"},
		{"install", "--package", "release.zip", "--bootstrap", "profile.json"},
		append(append([]string{}, valid...), "--public-key", "replacement"),
		append(append([]string{}, valid...), "--allow-unsigned"),
		append(append([]string{}, valid[:len(valid)-1]...), "site"),
		append(append([]string{}, valid[:len(valid)-1]...), "both"),
		append(append([]string{}, valid[:len(valid)-1]...), "unknown"),
	} {
		if _, err := parseOptions(args); err == nil {
			t.Fatalf("incomplete or unsupported setup accepted: %v", args)
		}
	}
	original := publisherPublicKey
	publisherPublicKey = ""
	t.Cleanup(func() { publisherPublicKey = original })
	var output bytes.Buffer
	if err := run(valid, &output); err == nil || !strings.Contains(err.Error(), "no trusted release publisher") {
		t.Fatal("development installation crossed publisher boundary", err)
	}
	if output.Len() != 0 {
		t.Fatal("unconfigured setup reported progress")
	}
}

func TestServiceRepairArgumentsRequireConfirmationAndCannotSupplyNewIdentity(t *testing.T) {
	valid := []string{"repair-services", "--package", "release.zip", "--role", "computer", "--confirm-server", "https://care.example.test", "--confirm-service-repair"}
	if opt, err := parseOptions(valid); err != nil || opt.role != "computer" || !opt.confirmRepair {
		t.Fatal("complete repair arguments refused", err)
	}
	for _, args := range [][]string{
		valid[:len(valid)-1],
		{"repair-services", "--package", "release.zip", "--confirm-server", "https://care.example.test", "--confirm-service-repair"},
		{"repair-services", "--package", "release.zip", "--role", "computer", "--confirm-service-repair"},
		append(append([]string{}, valid...), "--bootstrap", "replacement.json"),
		append(append([]string{}, valid...), "--collector-bootstrap", "replacement.json"),
		append(append([]string{}, valid...), "--allow-unsigned"),
		append(append([]string{}, valid...), "--confirm-service-repair=false"),
		append(append([]string{}, valid...), "--confirm-collector-server", "https://other.example.test"),
	} {
		if _, err := parseOptions(args); err == nil {
			t.Fatal("unsafe repair accepted", args)
		}
	}
	for _, role := range []string{"site", "both"} {
		args := append([]string{}, valid...)
		args[4] = role
		args = append(args, "--confirm-collector-server", "https://collectors.example.test")
		if opt, err := parseOptions(args); err != nil || opt.role != role {
			t.Fatal("collector repair arguments refused", err)
		}
	}
	old := publisherPublicKey
	publisherPublicKey = ""
	t.Cleanup(func() { publisherPublicKey = old })
	var out bytes.Buffer
	if err := run(valid, &out); err == nil || !strings.Contains(err.Error(), "no trusted release publisher") || out.Len() != 0 {
		t.Fatal("unconfigured repair reached machine access", err)
	}
}

func TestRepairReviewAndControlledArgumentsCannotBypassConfirmation(t *testing.T) {
	if opt, err := parseOptions([]string{"review-repair", "--package", "release.zip"}); err != nil || opt.command != "review-repair" {
		t.Fatal("installed review not accepted", err)
	}
	base := []string{"repair-services", "--package", "release.zip", "--role", "both", "--confirm-server", "https://care.example.test", "--confirm-service-repair"}
	valid := append(append([]string{}, base...), "--review-digest", strings.Repeat("a", 64), "--review-expires", "2026-10-08T12:00:00Z", "--control-stdin")
	if opt, err := parseOptions(valid); err != nil || !opt.controlStdin {
		t.Fatal("controlled repair refused", err)
	}
	for _, args := range [][]string{
		append(append([]string{}, base...), "--control-stdin"),
		append(append([]string{}, base...), "--review-digest", strings.Repeat("a", 64)),
		append(append([]string{}, base...), "--review-expires", "2026-10-08T12:00:00Z"),
		append(append([]string{}, base...), "--review-digest", "bad", "--review-expires", "2026-10-08T12:00:00Z"),
		{"review-repair", "--package", "release.zip", "--bootstrap", "replacement.json"},
		{"review-repair", "--package", "release.zip", "--role", "computer"},
	} {
		if _, err := parseOptions(args); err == nil {
			t.Fatal("unsafe controlled repair or review accepted", args)
		}
	}
}
