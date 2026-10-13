package agent

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"time"
	"unicode/utf8"
)

var errServiceAction = errors.New("governed service action could not be verified")
var serviceNamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_. -]{0,199}$`)

type ServiceActionPolicy struct {
	Version         int64  `json:"version"`
	Enabled         bool   `json:"enabled"`
	ConsentMode     string `json:"consent_mode"`
	CatalogueDigest string `json:"catalogue_digest"`
}
type ServiceActionParameters struct {
	ServiceName string `json:"service_name"`
	Operation   string `json:"operation"`
}
type ServiceCommand struct {
	Version               int                     `json:"version"`
	Type                  string                  `json:"type"`
	AgentUUID             string                  `json:"agent_uuid"`
	CommandUUID           string                  `json:"command_uuid"`
	AttemptUUID           string                  `json:"attempt_uuid"`
	AssignmentFingerprint string                  `json:"assignment_fingerprint"`
	Capability            string                  `json:"capability"`
	Parameters            ServiceActionParameters `json:"parameters"`
	Policy                ServiceActionPolicy     `json:"policy"`
	Technician            string                  `json:"technician"`
	Purpose               string                  `json:"purpose"`
	IssuedAt              string                  `json:"issued_at"`
	ExpiresAt             string                  `json:"expires_at"`
}
type ServiceClaim struct {
	Version               int     `json:"version"`
	Type                  string  `json:"type"`
	AgentUUID             string  `json:"agent_uuid"`
	CommandUUID           string  `json:"command_uuid"`
	AttemptUUID           string  `json:"attempt_uuid"`
	AssignmentFingerprint string  `json:"assignment_fingerprint"`
	PayloadHash           string  `json:"payload_hash"`
	PolicyVersion         int64   `json:"policy_version"`
	CatalogueDigest       string  `json:"catalogue_digest"`
	ConsentMode           string  `json:"consent_mode"`
	DesktopSession        *string `json:"desktop_session"`
	ConsentNonce          *string `json:"consent_nonce"`
	IssuedAt              string  `json:"issued_at"`
	ExpiresAt             string  `json:"expires_at"`
}

func serviceOperation(s string) bool { return s == "start" || s == "stop" || s == "restart" }
func serviceHash(envelope Envelope) string {
	payload, _ := base64.StdEncoding.Strict().DecodeString(envelope.Payload)
	sum := sha256.Sum256(payload)
	return hex.EncodeToString(sum[:])
}
func verifyServiceCommand(envelope Envelope, key ed25519.PublicKey, agent string, now time.Time) (ServiceCommand, error) {
	var c ServiceCommand
	fields := []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "capability", "parameters", "policy", "technician", "purpose", "issued_at", "expires_at"}
	if remotePayloadLimited(envelope, key, fields, &c, 16384) != nil {
		return c, errServiceAction
	}
	raw, _ := base64.StdEncoding.Strict().DecodeString(envelope.Payload)
	if !serviceUnicodeJSON(raw) || uniqueRemoteJSON(raw) != nil {
		return c, errServiceAction
	}
	var object map[string]json.RawMessage
	_ = json.Unmarshal(raw, &object)
	if !serviceClosedObject(object["parameters"], []string{"service_name", "operation"}) || !serviceClosedObject(object["policy"], []string{"version", "enabled", "consent_mode", "catalogue_digest"}) || c.Version != 2 || c.Type != "endpoint_service_command" || c.AgentUUID != agent || !uuidPattern.MatchString(c.CommandUUID) || !uuidPattern.MatchString(c.AttemptUUID) || !hexCredential(c.AssignmentFingerprint) || c.Capability != "endpoint.service.manage" || !permittedServiceName(c.Parameters.ServiceName) || !serviceOperation(c.Parameters.Operation) || !c.Policy.Enabled || c.Policy.Version < 1 || c.Policy.Version > 9007199254740991 || !hexCredential(c.Policy.CatalogueDigest) || (c.Policy.ConsentMode != "attended" && c.Policy.ConsentMode != "unattended") || !remoteLabel(c.Technician, 1, 200) || !remoteLabel(c.Purpose, 1, 500) || !remoteWindow(c.IssuedAt, c.ExpiresAt, now, 15*time.Minute) {
		return ServiceCommand{}, errServiceAction
	}
	return c, nil
}
func serviceClosedObject(raw json.RawMessage, fields []string) bool {
	var value map[string]json.RawMessage
	if json.Unmarshal(raw, &value) != nil || len(value) != len(fields) {
		return false
	}
	for _, field := range fields {
		v, ok := value[field]
		if !ok || string(v) == "null" {
			return false
		}
	}
	return true
}
func verifyServiceClaim(envelope Envelope, key ed25519.PublicKey, c ServiceCommand, hash, desktop, nonce string, now time.Time) (ServiceClaim, error) {
	var claim ServiceClaim
	// Nullable consent fields are intentional; authenticate bytes then require exact keys.
	raw, e := base64.StdEncoding.Strict().DecodeString(envelope.Payload)
	sig, se := base64.StdEncoding.Strict().DecodeString(envelope.Signature)
	if e != nil || se != nil || len(raw) > 4096 || len(envelope.Payload) > 5500 || len(key) != ed25519.PublicKeySize || !ed25519.Verify(key, raw, sig) || !serviceUnicodeJSON(raw) || uniqueRemoteJSON(raw) != nil {
		return claim, errServiceAction
	}
	var object map[string]json.RawMessage
	if json.Unmarshal(raw, &object) != nil || len(object) != 14 || json.Unmarshal(raw, &claim) != nil {
		return claim, errServiceAction
	}
	for _, f := range []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "payload_hash", "policy_version", "catalogue_digest", "consent_mode", "desktop_session", "consent_nonce", "issued_at", "expires_at"} {
		if _, ok := object[f]; !ok {
			return claim, errServiceAction
		}
	}
	if claim.Version != 1 || claim.Type != "endpoint_service_claim" || claim.AgentUUID != c.AgentUUID || claim.CommandUUID != c.CommandUUID || claim.AttemptUUID != c.AttemptUUID || claim.AssignmentFingerprint != c.AssignmentFingerprint || claim.PayloadHash != hash || claim.PolicyVersion != c.Policy.Version || claim.CatalogueDigest != c.Policy.CatalogueDigest || claim.ConsentMode != c.Policy.ConsentMode || !remoteWindow(claim.IssuedAt, claim.ExpiresAt, now, 30*time.Second) {
		return claim, errServiceAction
	}
	if c.Policy.ConsentMode == "attended" {
		if claim.DesktopSession == nil || claim.ConsentNonce == nil || *claim.DesktopSession != desktop || *claim.ConsentNonce != nonce || !uuidPattern.MatchString(desktop) || !hexCredential(nonce) {
			return claim, errServiceAction
		}
	} else if claim.DesktopSession != nil || claim.ConsentNonce != nil {
		return claim, errServiceAction
	}
	return claim, nil
}

// JSON escape pairs must denote Unicode scalars; encoding/json otherwise replaces lone surrogates.
func serviceUnicodeJSON(raw []byte) bool {
	if !utf8.Valid(raw) {
		return false
	}
	quoted := false
	for i := 0; i < len(raw); i++ {
		if raw[i] == '"' {
			quoted = !quoted
			continue
		}
		if !quoted || raw[i] != '\\' {
			continue
		}
		i++
		if i >= len(raw) {
			return false
		}
		if raw[i] != 'u' {
			continue
		}
		if i+4 >= len(raw) {
			return false
		}
		value, e := strconv.ParseUint(string(raw[i+1:i+5]), 16, 16)
		if e != nil {
			return false
		}
		i += 4
		if value >= 0xdc00 && value <= 0xdfff {
			return false
		}
		if value >= 0xd800 && value <= 0xdbff {
			if i+6 >= len(raw) || raw[i+1] != '\\' || raw[i+2] != 'u' {
				return false
			}
			low, e := strconv.ParseUint(string(raw[i+3:i+7]), 16, 16)
			if e != nil || low < 0xdc00 || low > 0xdfff {
				return false
			}
			i += 6
		}
	}
	return true
}
