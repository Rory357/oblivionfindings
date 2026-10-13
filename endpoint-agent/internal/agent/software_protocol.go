package agent

import (
	"crypto/ed25519"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const softwareMaxBytes int64 = 536870912

var softwareProductCode = regexp.MustCompile(`^\{[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}\}$`)

type SoftwareParameters struct {
	PackageUUID    string `json:"package_uuid"`
	PackageVersion int64  `json:"package_version"`
	SHA256         string `json:"sha256"`
	SizeBytes      int64  `json:"size_bytes"`
	SignerSHA256   string `json:"signer_sha256"`
	ProductCode    string `json:"product_code"`
	ProductVersion string `json:"product_version"`
	Architecture   string `json:"architecture"`
	DisplayName    string `json:"display_name"`
	PublisherLabel string `json:"publisher_label"`
}
type SoftwareCommand struct {
	Version               int                `json:"version"`
	Type                  string             `json:"type"`
	AgentUUID             string             `json:"agent_uuid"`
	CommandUUID           string             `json:"command_uuid"`
	AttemptUUID           string             `json:"attempt_uuid"`
	AssignmentFingerprint string             `json:"assignment_fingerprint"`
	Capability            string             `json:"capability"`
	Parameters            SoftwareParameters `json:"parameters"`
	Policy                RestartPolicy      `json:"policy"`
	Technician            string             `json:"technician"`
	Purpose               string             `json:"purpose"`
	IssuedAt              string             `json:"issued_at"`
	ExpiresAt             string             `json:"expires_at"`
}
type SoftwareClaim struct {
	Version               int     `json:"version"`
	Type                  string  `json:"type"`
	AgentUUID             string  `json:"agent_uuid"`
	CommandUUID           string  `json:"command_uuid"`
	AttemptUUID           string  `json:"attempt_uuid"`
	AssignmentFingerprint string  `json:"assignment_fingerprint"`
	PayloadHash           string  `json:"payload_hash"`
	PolicyVersion         int64   `json:"policy_version"`
	ConsentMode           string  `json:"consent_mode"`
	ArtifactSHA256        string  `json:"artifact_sha256"`
	BootID                string  `json:"boot_id"`
	DesktopSession        *string `json:"desktop_session"`
	ConsentNonce          *string `json:"consent_nonce"`
	IssuedAt              string  `json:"issued_at"`
	ExpiresAt             string  `json:"expires_at"`
}
type SoftwareResult struct {
	CommandUUID     string  `json:"command_uuid"`
	PayloadHash     string  `json:"payload_hash"`
	Status          string  `json:"status"`
	MutationStarted bool    `json:"mutation_started"`
	ReasonCode      string  `json:"reason_code"`
	ExitCode        *uint32 `json:"exit_code"`
	RebootRequired  bool    `json:"reboot_required"`
	BootID          string  `json:"boot_id"`
}
type SoftwareObservation struct {
	CommandUUID          string  `json:"command_uuid"`
	AttemptUUID          string  `json:"attempt_uuid"`
	ProductCode          string  `json:"product_code"`
	ProductVersion       *string `json:"product_version"`
	State                string  `json:"state"`
	BootID               string  `json:"boot_id"`
	TransactionQuiescent bool    `json:"transaction_quiescent"`
	ObservedAt           string  `json:"observed_at"`
}
type SoftwareAcceptance struct {
	SoftwareObservation
	Status string `json:"status"`
}
type SoftwareCapability struct {
	Version          int    `json:"version"`
	InstalledService bool   `json:"installed_service"`
	ReleaseEnabled   bool   `json:"release_enabled"`
	Executor         string `json:"executor"`
}

func softwareVersion(s string) ([3]int, bool) {
	var v [3]int
	p := strings.Split(s, ".")
	if len(p) != 3 {
		return v, false
	}
	for i, x := range p {
		n, e := strconv.Atoi(x)
		if e != nil || n < 0 || strconv.Itoa(n) != x || (i < 2 && n > 255) || (i == 2 && n > 65535) {
			return v, false
		}
		v[i] = n
	}
	return v, true
}
func compareSoftwareVersion(a, b string) int {
	x, _ := softwareVersion(a)
	y, _ := softwareVersion(b)
	for i := range x {
		if x[i] < y[i] {
			return -1
		}
		if x[i] > y[i] {
			return 1
		}
	}
	return 0
}
func validSoftwareCommand(c SoftwareCommand) bool {
	p := c.Parameters
	_, v := softwareVersion(p.ProductVersion)
	a, ae := restartTime(c.IssuedAt)
	b, be := restartTime(c.ExpiresAt)
	return c.Version == 1 && c.Type == "endpoint_software_install" && c.Capability == "endpoint.software.install" && uuidPattern.MatchString(c.AgentUUID) && uuidPattern.MatchString(c.CommandUUID) && uuidPattern.MatchString(c.AttemptUUID) && hexCredential(c.AssignmentFingerprint) && uuidPattern.MatchString(p.PackageUUID) && p.PackageVersion >= 1 && p.PackageVersion <= 9007199254740991 && hexCredential(p.SHA256) && hexCredential(p.SignerSHA256) && p.SizeBytes > 0 && p.SizeBytes <= softwareMaxBytes && softwareProductCode.MatchString(p.ProductCode) && v && (p.Architecture == "amd64" || p.Architecture == "arm64") && remoteLabel(p.DisplayName, 1, 200) && remoteLabel(p.PublisherLabel, 1, 200) && remoteLabel(c.Technician, 1, 200) && remoteLabel(c.Purpose, 1, 500) && c.Policy.Enabled && c.Policy.Version > 0 && c.Policy.Version <= 9007199254740991 && (c.Policy.ConsentMode == "attended" || c.Policy.ConsentMode == "unattended") && ae == nil && be == nil && b.After(a) && b.Sub(a) <= 24*time.Hour
}
func softwareCommandShape(b []byte) bool {
	var m map[string]json.RawMessage
	if json.Unmarshal(b, &m) != nil {
		return false
	}
	return serviceClosedObject(b, []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "capability", "parameters", "policy", "technician", "purpose", "issued_at", "expires_at"}) && serviceClosedObject(m["parameters"], []string{"package_uuid", "package_version", "sha256", "size_bytes", "signer_sha256", "product_code", "product_version", "architecture", "display_name", "publisher_label"}) && serviceClosedObject(m["policy"], []string{"version", "enabled", "consent_mode"})
}
func verifySoftwareCommand(e Envelope, key ed25519.PublicKey, agent string, now time.Time) (SoftwareCommand, error) {
	var c SoftwareCommand
	b, err := restartRaw(e, key, 16384)
	if err != nil || !softwareCommandShape(b) || json.Unmarshal(b, &c) != nil || !validSoftwareCommand(c) || c.AgentUUID != agent {
		return c, errServiceAction
	}
	issued, _ := restartTime(c.IssuedAt)
	expires, _ := restartTime(c.ExpiresAt)
	if issued.After(now.Add(5*time.Second)) || !expires.After(now) {
		return c, errServiceAction
	}
	return c, nil
}
func verifySoftwareClaim(e Envelope, key ed25519.PublicKey, c SoftwareCommand, hash, boot, desktop, nonce string, now time.Time) (SoftwareClaim, error) {
	var x SoftwareClaim
	b, err := restartRaw(e, key, 4096)
	var m map[string]json.RawMessage
	names := []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "payload_hash", "policy_version", "consent_mode", "artifact_sha256", "boot_id", "desktop_session", "consent_nonce", "issued_at", "expires_at"}
	if err != nil || json.Unmarshal(b, &m) != nil || len(m) != len(names) || json.Unmarshal(b, &x) != nil {
		return x, errServiceAction
	}
	for _, n := range names {
		if m[n] == nil || (n != "desktop_session" && n != "consent_nonce" && string(m[n]) == "null") {
			return x, errServiceAction
		}
	}
	a, ae := restartTime(x.IssuedAt)
	z, ze := restartTime(x.ExpiresAt)
	end, _ := restartTime(c.ExpiresAt)
	if ae != nil || ze != nil || x.Version != 1 || x.Type != "endpoint_software_claim" || x.AgentUUID != c.AgentUUID || x.CommandUUID != c.CommandUUID || x.AttemptUUID != c.AttemptUUID || x.AssignmentFingerprint != c.AssignmentFingerprint || x.PayloadHash != hash || x.PolicyVersion != c.Policy.Version || x.ConsentMode != c.Policy.ConsentMode || x.ArtifactSHA256 != c.Parameters.SHA256 || x.BootID != boot || !uuidPattern.MatchString(boot) || a.After(now.Add(time.Second)) || !z.After(now) || !z.After(a) || z.Sub(a) > 30*time.Second || z.After(end) {
		return x, errServiceAction
	}
	if c.Policy.ConsentMode == "attended" {
		if x.DesktopSession == nil || x.ConsentNonce == nil || *x.DesktopSession != desktop || *x.ConsentNonce != nonce || !uuidPattern.MatchString(desktop) || !hexCredential(nonce) {
			return x, errServiceAction
		}
	} else if x.DesktopSession != nil || x.ConsentNonce != nil {
		return x, errServiceAction
	}
	return x, nil
}
func validSoftwareResult(x SoftwareResult, c SoftwareCommand, hash string) bool {
	if x.CommandUUID != c.CommandUUID || x.PayloadHash != hash || !uuidPattern.MatchString(x.BootID) {
		return false
	}
	if x.ExitCode != nil {
		if !x.MutationStarted {
			return false
		}
		switch *x.ExitCode {
		case 0:
			return x.Status == "awaiting_observation" && x.ReasonCode == "installed" && !x.RebootRequired
		case 3010:
			return x.Status == "awaiting_observation" && x.ReasonCode == "installed_restart_required" && x.RebootRequired
		case 1641:
			return x.Status == "uncertain" && x.ReasonCode == "restart_initiated" && x.RebootRequired
		case 1618:
			return x.Status == "failed" && x.ReasonCode == "installer_busy" && !x.RebootRequired
		default:
			return x.Status == "failed" && x.ReasonCode == "installer_failed" && !x.RebootRequired
		}
	}
	if x.RebootRequired {
		return false
	}
	if x.Status == "declined" || x.Status == "expired" {
		return !x.MutationStarted && x.ReasonCode == x.Status
	}
	if x.Status == "uncertain" {
		return x.ReasonCode == "interrupted" || x.ReasonCode == "state_unavailable"
	}
	if x.Status != "failed" || x.MutationStarted {
		return false
	}
	switch x.ReasonCode {
	case "artifact_unavailable", "artifact_invalid", "signature_invalid", "product_mismatch", "architecture_mismatch", "already_installed", "downgrade_denied", "policy_unavailable", "consent_lost", "operation_failed":
		return true
	}
	return false
}

func softwarePackedVersion(s string) (string, bool) {
	n, e := strconv.ParseUint(s, 10, 32)
	if e != nil || strconv.FormatUint(n, 10) != s {
		return "", false
	}
	return fmt.Sprintf("%d.%d.%d", n>>24, (n>>16)&255, n&65535), true
}
