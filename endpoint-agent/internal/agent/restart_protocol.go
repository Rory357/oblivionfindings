package agent

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"time"
)

type RestartParameters struct {
	ExecuteAt      string `json:"execute_at"`
	WarningSeconds int    `json:"warning_seconds"`
	ForceClose     bool   `json:"force_close"`
}
type RestartPolicy struct {
	Version     int64  `json:"version"`
	Enabled     bool   `json:"enabled"`
	ConsentMode string `json:"consent_mode"`
}
type RestartCommand struct {
	Version               int               `json:"version"`
	Type                  string            `json:"type"`
	AgentUUID             string            `json:"agent_uuid"`
	CommandUUID           string            `json:"command_uuid"`
	AttemptUUID           string            `json:"attempt_uuid"`
	AssignmentFingerprint string            `json:"assignment_fingerprint"`
	Capability            string            `json:"capability"`
	Parameters            RestartParameters `json:"parameters"`
	Policy                RestartPolicy     `json:"policy"`
	Technician            string            `json:"technician"`
	Purpose               string            `json:"purpose"`
	IssuedAt              string            `json:"issued_at"`
	ExpiresAt             string            `json:"expires_at"`
}
type RestartClaim struct {
	Version               int     `json:"version"`
	Type                  string  `json:"type"`
	AgentUUID             string  `json:"agent_uuid"`
	CommandUUID           string  `json:"command_uuid"`
	AttemptUUID           string  `json:"attempt_uuid"`
	AssignmentFingerprint string  `json:"assignment_fingerprint"`
	PayloadHash           string  `json:"payload_hash"`
	PolicyVersion         int64   `json:"policy_version"`
	ConsentMode           string  `json:"consent_mode"`
	BootID                string  `json:"boot_id"`
	ExecuteAt             string  `json:"execute_at"`
	WarningStartedAt      *string `json:"warning_started_at"`
	DesktopSession        *string `json:"desktop_session"`
	ConsentNonce          *string `json:"consent_nonce"`
	IssuedAt              string  `json:"issued_at"`
	ExpiresAt             string  `json:"expires_at"`
}
type RestartCancellation struct {
	Version               int    `json:"version"`
	Type                  string `json:"type"`
	AgentUUID             string `json:"agent_uuid"`
	CommandUUID           string `json:"command_uuid"`
	AttemptUUID           string `json:"attempt_uuid"`
	AssignmentFingerprint string `json:"assignment_fingerprint"`
	PayloadHash           string `json:"payload_hash"`
	IssuedAt              string `json:"issued_at"`
	ExpiresAt             string `json:"expires_at"`
}
type RestartResult struct {
	CommandUUID     string `json:"command_uuid"`
	PayloadHash     string `json:"payload_hash"`
	Status          string `json:"status"`
	MutationStarted bool   `json:"mutation_started"`
	ReasonCode      string `json:"reason_code"`
	BootID          string `json:"boot_id"`
}
type RestartObservation struct {
	CommandUUID  string `json:"command_uuid"`
	AttemptUUID  string `json:"attempt_uuid"`
	BeforeBootID string `json:"before_boot_id"`
	BootID       string `json:"boot_id"`
	ObservedAt   string `json:"observed_at"`
}
type RestartAcceptance struct {
	CommandUUID  string `json:"command_uuid"`
	AttemptUUID  string `json:"attempt_uuid"`
	BeforeBootID string `json:"before_boot_id"`
	BootID       string `json:"boot_id"`
	Status       string `json:"status"`
}
type RestartCapability struct {
	Version          int    `json:"version"`
	InstalledService bool   `json:"installed_service"`
	ReleaseEnabled   bool   `json:"release_enabled"`
	Executor         string `json:"executor"`
}

func restartTime(s string) (time.Time, error) { return time.Parse("2006-01-02T15:04:05Z", s) }
func restartRaw(e Envelope, key ed25519.PublicKey, limit int) ([]byte, error) {
	if len(e.Payload) > ((limit+2)/3)*4 || len(e.Signature) > 100 {
		return nil, errServiceAction
	}
	b, err := base64.StdEncoding.Strict().DecodeString(e.Payload)
	sig, se := base64.StdEncoding.Strict().DecodeString(e.Signature)
	if err != nil || se != nil || len(b) > limit || len(key) != ed25519.PublicKeySize || !ed25519.Verify(key, b, sig) || !serviceUnicodeJSON(b) || uniqueRemoteJSON(b) != nil {
		return nil, errServiceAction
	}
	return b, nil
}
func validRestartCommand(c RestartCommand) bool {
	at, e := restartTime(c.Parameters.ExecuteAt)
	issued, ie := restartTime(c.IssuedAt)
	expires, ee := restartTime(c.ExpiresAt)
	return e == nil && ie == nil && ee == nil && c.Version == 1 && c.Type == "endpoint_restart_schedule" && uuidPattern.MatchString(c.AgentUUID) && uuidPattern.MatchString(c.CommandUUID) && uuidPattern.MatchString(c.AttemptUUID) && hexCredential(c.AssignmentFingerprint) && c.Capability == "endpoint.computer.restart" && !c.Parameters.ForceClose && c.Parameters.WarningSeconds >= 60 && c.Parameters.WarningSeconds <= 3600 && expires.Equal(at.Add(5*time.Second)) && !issued.After(at.Add(-time.Duration(c.Parameters.WarningSeconds)*time.Second)) && at.Sub(issued) <= 24*time.Hour && c.Policy.Version >= 1 && c.Policy.Version <= 9007199254740991 && c.Policy.Enabled && (c.Policy.ConsentMode == "attended" || c.Policy.ConsentMode == "unattended") && remoteLabel(c.Technician, 1, 200) && remoteLabel(c.Purpose, 1, 500)
}
func verifyRestartCommand(e Envelope, key ed25519.PublicKey, agent string, now time.Time) (RestartCommand, error) {
	var c RestartCommand
	b, err := restartRaw(e, key, 16384)
	if err != nil {
		return c, err
	}
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(b, &fields)
	if !serviceClosedObject(b, []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "capability", "parameters", "policy", "technician", "purpose", "issued_at", "expires_at"}) || !serviceClosedObject(fields["parameters"], []string{"execute_at", "warning_seconds", "force_close"}) || !serviceClosedObject(fields["policy"], []string{"version", "enabled", "consent_mode"}) || json.Unmarshal(b, &c) != nil || !validRestartCommand(c) || c.AgentUUID != agent {
		return c, errServiceAction
	}
	issued, _ := restartTime(c.IssuedAt)
	expires, _ := restartTime(c.ExpiresAt)
	if !expires.After(now) || issued.After(now.Add(5*time.Second)) {
		return c, errServiceAction
	}
	return c, nil
}
func verifyRestartClaim(e Envelope, key ed25519.PublicKey, c RestartCommand, hash, boot, desktop, nonce, warning string, now time.Time) (RestartClaim, error) {
	var claim RestartClaim
	b, err := restartRaw(e, key, 4096)
	if err != nil {
		return claim, err
	}
	var fields map[string]json.RawMessage
	if json.Unmarshal(b, &fields) != nil || len(fields) != 16 || json.Unmarshal(b, &claim) != nil {
		return claim, errServiceAction
	}
	for _, name := range []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "payload_hash", "policy_version", "consent_mode", "boot_id", "execute_at", "warning_started_at", "desktop_session", "consent_nonce", "issued_at", "expires_at"} {
		if _, ok := fields[name]; !ok {
			return claim, errServiceAction
		}
	}
	at, _ := restartTime(c.Parameters.ExecuteAt)
	issued, ie := restartTime(claim.IssuedAt)
	expires, ee := restartTime(claim.ExpiresAt)
	if ie != nil || ee != nil || claim.Version != 1 || claim.Type != "endpoint_restart_claim" || claim.AgentUUID != c.AgentUUID || claim.CommandUUID != c.CommandUUID || claim.AttemptUUID != c.AttemptUUID || claim.AssignmentFingerprint != c.AssignmentFingerprint || claim.PayloadHash != hash || claim.PolicyVersion != c.Policy.Version || claim.ConsentMode != c.Policy.ConsentMode || claim.BootID != boot || !uuidPattern.MatchString(boot) || claim.ExecuteAt != c.Parameters.ExecuteAt || issued.Before(at.Add(-3*time.Second)) || issued.After(at.Add(5*time.Second)) || issued.After(now.Add(time.Second)) || !expires.After(now) || expires.After(at.Add(5*time.Second)) || !expires.After(issued) {
		return claim, errServiceAction
	}
	if c.Policy.ConsentMode == "attended" {
		if claim.DesktopSession == nil || claim.ConsentNonce == nil || claim.WarningStartedAt == nil || *claim.DesktopSession != desktop || *claim.ConsentNonce != nonce || *claim.WarningStartedAt != warning || !uuidPattern.MatchString(desktop) || !hexCredential(nonce) {
			return claim, errServiceAction
		}
	} else if claim.DesktopSession != nil || claim.ConsentNonce != nil || claim.WarningStartedAt != nil {
		return claim, errServiceAction
	}
	return claim, nil
}
func verifyRestartCancellation(e Envelope, key ed25519.PublicKey, c RestartCommand, hash string, now time.Time) bool {
	b, err := restartRaw(e, key, 4096)
	if err != nil || !serviceClosedObject(b, []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "payload_hash", "issued_at", "expires_at"}) {
		return false
	}
	var x RestartCancellation
	if json.Unmarshal(b, &x) != nil {
		return false
	}
	issued, ie := restartTime(x.IssuedAt)
	expires, ee := restartTime(x.ExpiresAt)
	return ie == nil && ee == nil && x.Version == 1 && x.Type == "endpoint_restart_cancel" && x.AgentUUID == c.AgentUUID && x.CommandUUID == c.CommandUUID && x.AttemptUUID == c.AttemptUUID && x.AssignmentFingerprint == c.AssignmentFingerprint && x.PayloadHash == hash && x.ExpiresAt == c.ExpiresAt && !issued.After(now.Add(5*time.Second)) && expires.After(now)
}
func validRestartResult(x RestartResult, c RestartCommand, hash string) bool {
	if x.CommandUUID != c.CommandUUID || x.PayloadHash != hash || !uuidPattern.MatchString(x.BootID) {
		return false
	}
	switch x.Status {
	case "awaiting_boot":
		return x.MutationStarted && x.ReasonCode == "restart_requested"
	case "declined", "cancelled", "expired":
		return !x.MutationStarted && x.ReasonCode == x.Status
	case "failed":
		if x.MutationStarted {
			return x.ReasonCode == "restart_refused"
		}
		fallthrough
	case "uncertain":
		switch x.ReasonCode {
		case "policy_unavailable", "consent_lost", "warning_incomplete", "clock_changed", "offline", "boot_unavailable", "interrupted", "operation_failed", "state_unavailable":
			return true
		}
	}
	return false
}
