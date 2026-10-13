package agent

import (
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"time"
	"unicode"
	"unicode/utf8"
)

// Remote offers and leases use their own signed type, never a command capability.
type RemoteOffer struct {
	Type           string `json:"type"`
	Version        int    `json:"version"`
	AgentUUID      string `json:"agent_uuid"`
	SessionUUID    string `json:"session_uuid"`
	PolicyVersion  int    `json:"policy_version"`
	TechnicianName string `json:"technician_name"`
	Purpose        string `json:"purpose"`
	AccessMode     string `json:"access_mode"`
	ConsentMode    string `json:"consent_mode"`
	Recording      bool   `json:"recording"`
	Challenge      string `json:"challenge"`
	IssuedAt       string `json:"issued_at"`
	ExpiresAt      string `json:"expires_at"`
}
type RemoteLease struct {
	Type           string `json:"type"`
	Version        int    `json:"version"`
	AgentUUID      string `json:"agent_uuid"`
	SessionUUID    string `json:"session_uuid"`
	DesktopSession string `json:"desktop_session"`
	AccessMode     string `json:"access_mode"`
	Status         string `json:"status"`
	Recording      bool   `json:"recording"`
	IssuedAt       string `json:"issued_at"`
	ExpiresAt      string `json:"expires_at"`
}

var errRemote = errors.New("remote support authorisation could not be verified")

func remotePayload(envelope Envelope, key ed25519.PublicKey, fields []string, destination any) error {
	return remotePayloadLimited(envelope, key, fields, destination, 4096)
}
func remotePayloadLimited(envelope Envelope, key ed25519.PublicKey, fields []string, destination any, maximum int) error {
	if len(envelope.Payload) > (maximum+2)/3*4 || len(envelope.Signature) > 100 || len(key) != ed25519.PublicKeySize {
		return errRemote
	}
	payload, err := base64.StdEncoding.Strict().DecodeString(envelope.Payload)
	signature, sigErr := base64.StdEncoding.Strict().DecodeString(envelope.Signature)
	if err != nil || sigErr != nil || len(payload) > maximum || !utf8.Valid(payload) || !ed25519.Verify(key, payload, signature) {
		return errRemote
	}
	// Exact flat fields reject duplicates, unknown fields, null and JSON ambiguity.
	decoder := json.NewDecoder(bytes.NewReader(payload))
	token, err := decoder.Token()
	if err != nil || token != json.Delim('{') {
		return errRemote
	}
	seen := map[string]bool{}
	allowed := map[string]bool{}
	for _, name := range fields {
		allowed[name] = true
	}
	for decoder.More() {
		token, err = decoder.Token()
		name, ok := token.(string)
		if err != nil || !ok || !allowed[name] || seen[name] {
			return errRemote
		}
		seen[name] = true
		var value json.RawMessage
		if decoder.Decode(&value) != nil || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return errRemote
		}
	}
	if token, err = decoder.Token(); err != nil || token != json.Delim('}') || len(seen) != len(fields) {
		return errRemote
	}
	if _, err = decoder.Token(); err != io.EOF {
		return errRemote
	}
	if json.Unmarshal(payload, destination) != nil {
		return errRemote
	}
	return nil
}
func remoteWindow(issued, expires string, now time.Time, maximum time.Duration) bool {
	start, a := time.Parse("2006-01-02T15:04:05Z", issued)
	end, b := time.Parse("2006-01-02T15:04:05Z", expires)
	return a == nil && b == nil && !start.After(now.Add(5*time.Second)) && end.After(now) && end.After(start) && end.Sub(start) <= maximum && end.Sub(now) <= maximum
}
func remoteLabel(value string, min, max int) bool {
	count := utf8.RuneCountInString(value)
	if count < min || count > max {
		return false
	}
	for _, r := range value {
		if unicode.IsControl(r) || unicode.Is(unicode.Cf, r) {
			return false
		}
	}
	return true
}
func VerifyRemoteOffer(envelope Envelope, key ed25519.PublicKey, agentUUID string, now time.Time) (RemoteOffer, error) {
	var offer RemoteOffer
	if remotePayload(envelope, key, []string{"type", "version", "agent_uuid", "session_uuid", "policy_version", "technician_name", "purpose", "access_mode", "consent_mode", "recording", "challenge", "issued_at", "expires_at"}, &offer) != nil {
		return RemoteOffer{}, errRemote
	}
	if offer.Type != "remote_support_offer" || offer.Version != 1 || offer.AgentUUID != agentUUID || !uuidPattern.MatchString(agentUUID) ||
		!uuidPattern.MatchString(offer.SessionUUID) || offer.PolicyVersion < 1 || offer.PolicyVersion > 2147483647 ||
		(offer.AccessMode != "view" && offer.AccessMode != "control") || (offer.ConsentMode != "attended" && offer.ConsentMode != "unattended") ||
		offer.Recording || !hexCredential(offer.Challenge) || !remoteLabel(offer.TechnicianName, 1, 100) || !remoteLabel(offer.Purpose, 10, 500) ||
		!remoteWindow(offer.IssuedAt, offer.ExpiresAt, now, 120*time.Second) {
		return RemoteOffer{}, errRemote
	}
	return offer, nil
}
func VerifyRemoteLease(envelope Envelope, key ed25519.PublicKey, offer RemoteOffer, desktopSession string, now time.Time) (RemoteLease, error) {
	var lease RemoteLease
	if remotePayload(envelope, key, []string{"type", "version", "agent_uuid", "session_uuid", "desktop_session", "access_mode", "status", "recording", "issued_at", "expires_at"}, &lease) != nil {
		return RemoteLease{}, errRemote
	}
	if lease.Type != "remote_support_lease" || lease.Version != 1 || lease.AgentUUID != offer.AgentUUID || lease.SessionUUID != offer.SessionUUID ||
		!uuidPattern.MatchString(lease.AgentUUID) || !uuidPattern.MatchString(lease.SessionUUID) ||
		lease.DesktopSession != desktopSession || !uuidPattern.MatchString(desktopSession) ||
		lease.AccessMode != offer.AccessMode || (lease.AccessMode != "view" && lease.AccessMode != "control") ||
		(lease.Status != "allowed" && lease.Status != "active") || lease.Recording ||
		!remoteWindow(lease.IssuedAt, lease.ExpiresAt, now, 15*time.Second) {
		return RemoteLease{}, errRemote
	}
	return lease, nil
}

// A remote lease alone is not local consent. The future screen/input worker must
// hold both its current verified desktop approval and an active unexpired lease.
// This validator does not capture the desktop, inject input or execute commands.
