package cctv

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"sync"
	"time"
	"unicode"
	"unicode/utf8"
)

const hikvisionDiscoveryOperation = "discover_hikvision"

// This grant authorizes only two fixed read-only ISAPI resources. It cannot
// activate a live source or establish a canonical channel/camera assignment.
type hikvisionDiscoveryGrant struct {
	Version           int    `json:"version"`
	Type              string `json:"type"`
	Collector         string `json:"collector_uuid"`
	Site              int64  `json:"site_id"`
	Device            int64  `json:"device_id"`
	Scope             int64  `json:"discovery_scope_id"`
	Request           string `json:"request_uuid"`
	ContextHash       string `json:"context_sha256"`
	CredentialBinding string `json:"credential_binding_sha256"`
	SourceHash        string `json:"source_sha256"`
	IP                string `json:"allowed_ip"`
	Port              int    `json:"allowed_port"`
	ServerName        string `json:"tls_server_name"`
	Pin               string `json:"tls_pin"`
	Issued            string `json:"issued_at"`
	Expires           string `json:"expires_at"`
}

type hikvisionDiscoverySource struct {
	IP         string `json:"allowed_ip"`
	Port       int    `json:"allowed_port"`
	ServerName string `json:"tls_server_name"`
	Pin        string `json:"tls_pin"`
	Username   string `json:"username"`
	Password   string `json:"password"`
}

func (s hikvisionDiscoverySource) endpoint() httpsTunnelSource {
	return httpsTunnelSource{IP: s.IP, Port: s.Port, ServerName: s.ServerName, Pin: s.Pin, Username: s.Username, Password: s.Password}
}

type hikvisionDiscoveryReply struct {
	Version           int                 `json:"version"`
	Operation         string              `json:"operation"`
	Request           string              `json:"request_uuid"`
	Device            int64               `json:"device_id"`
	ContextHash       string              `json:"context_sha256"`
	CredentialBinding string              `json:"credential_binding_sha256"`
	Observed          string              `json:"observed_at"`
	Status            string              `json:"status"`
	Inventory         *hikvisionInventory `json:"inventory"`
	Reason            *string             `json:"reason_code"`
}

type hikvisionDiscoveryReceipt struct {
	payloadHash string
	reply       []byte // Immutable bounded public observations, never source credentials.
}

type hikvisionDiscoveryState struct {
	serial      sync.Mutex // Discovery must not hold the media-session/watchdog lock.
	receipts    map[string]hikvisionDiscoveryReceipt
	cancel      context.CancelFunc // Guarded by Worker.mu.
	stopped     bool               // Guarded by Worker.mu.
	run         func(context.Context, httpsTunnelSource) (hikvisionInventory, error)
	runRecorder func(context.Context, httpsTunnelSource) (hikvisionRecorderInventory, error)
}

func verifyHikvisionDiscovery(e Envelope, key ed25519.PublicKey, collector string, site int64, now time.Time) (hikvisionDiscoveryGrant, []byte, error) {
	var g hikvisionDiscoveryGrant
	b, err := base64.StdEncoding.Strict().DecodeString(e.Payload)
	signature, signatureErr := base64.StdEncoding.Strict().DecodeString(e.Signature)
	if err != nil || signatureErr != nil || len(b) > 8192 || base64.StdEncoding.EncodeToString(b) != e.Payload || base64.StdEncoding.EncodeToString(signature) != e.Signature || len(key) != ed25519.PublicKeySize || !ed25519.Verify(key, b, signature) {
		return g, nil, ErrProtocol
	}
	if Closed(b, "version", "type", "collector_uuid", "site_id", "device_id", "discovery_scope_id", "request_uuid", "context_sha256", "credential_binding_sha256", "source_sha256", "allowed_ip", "allowed_port", "tls_server_name", "tls_pin", "issued_at", "expires_at") != nil || json.Unmarshal(b, &g) != nil {
		return g, nil, ErrProtocol
	}
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(b, &fields)
	for _, v := range fields {
		if bytes.Equal(bytes.TrimSpace(v), []byte("null")) {
			return g, nil, ErrProtocol
		}
	}
	endpoint := httpsTunnelSource{IP: g.IP, Port: g.Port, ServerName: g.ServerName, Pin: g.Pin, Username: "validation", Password: "validation"}
	issued, ie := instant(g.Issued)
	expiry, xe := instant(g.Expires)
	if g.Version != 1 || g.Type != "collector_hikvision_discovery" || g.Collector != collector || g.Site != site || g.Site <= 0 || g.Site > 9007199254740991 || g.Device <= 0 || g.Device > 9007199254740991 || g.Scope <= 0 || g.Scope > 9007199254740991 || !uuid.MatchString(g.Request) || !digest.MatchString(g.ContextHash) || !digest.MatchString(g.CredentialBinding) || !digest.MatchString(g.SourceHash) || !endpoint.validEndpoint() || ie != nil || xe != nil || issued.After(now) || !expiry.After(now) || expiry.After(issued.Add(15*time.Second)) {
		return g, nil, ErrProtocol
	}
	return g, b, nil
}

func discoveryFailure(request string, now time.Time) hikvisionDiscoveryReply {
	return hikvisionDiscoveryReply{Version: 1, Operation: hikvisionDiscoveryOperation, Request: request, Observed: now.UTC().Truncate(time.Second).Format("2006-01-02T15:04:05Z"), Status: "failed", Reason: reason("authority_lost")}
}

func (w *Worker) DiscoverHikvision(parent context.Context, raw []byte) hikvisionDiscoveryReply {
	m, err := decodeMessage(raw)
	w.discovery.serial.Lock()
	defer w.discovery.serial.Unlock()
	w.mu.Lock()
	now := w.now()
	out := discoveryFailure(m.Request, now)
	if err != nil || m.Operation != hikvisionDiscoveryOperation || !w.configured || w.discovery.stopped || parent.Err() != nil {
		w.mu.Unlock()
		return out
	}
	g, payload, err := verifyHikvisionDiscovery(m.Grant, w.key, w.collector, w.site, now)
	w.mu.Unlock()
	if err != nil || g.Request != m.Request {
		return out
	}
	out.Device, out.ContextHash, out.CredentialBinding = g.Device, g.ContextHash, g.CredentialBinding
	var source hikvisionDiscoverySource
	if len(m.Source) > 8192 || Closed(m.Source, "allowed_ip", "allowed_port", "tls_server_name", "tls_pin", "username", "password") != nil || json.Unmarshal(m.Source, &source) != nil || hash(m.Source) != g.SourceHash || !source.endpoint().validEndpoint() || source.IP != g.IP || source.Port != g.Port || source.ServerName != g.ServerName || source.Pin != g.Pin {
		return out
	}
	// Revalidate every incoming signature, scope, expiry and original source
	// before returning a receipt. No expired grant can retrieve cached inventory.
	if receipt, exists := w.discovery.receipts[g.Request]; exists {
		if receipt.payloadHash != hash(payload) {
			return out
		}
		var cached hikvisionDiscoveryReply
		if json.Unmarshal(receipt.reply, &cached) != nil {
			return out
		}
		w.mu.Lock()
		valid := !w.discovery.stopped && parent.Err() == nil
		expires, _ := instant(g.Expires)
		valid = valid && w.now().Before(expires)
		w.mu.Unlock()
		if !valid {
			return out
		}
		return cached
	}
	// Never evict a used identity within this worker incarnation and then reuse
	// it for network work. The future central broker owns durable request replay.
	if len(w.discovery.receipts) >= 256 {
		out.Reason = reason("capacity_unavailable")
		return out
	}
	expiry, _ := instant(g.Expires)
	remaining := expiry.Sub(now)
	if remaining > 3*time.Second {
		remaining = 3 * time.Second
	}
	run, cancel := context.WithTimeout(parent, remaining)
	defer cancel()
	w.mu.Lock()
	if w.discovery.stopped {
		w.mu.Unlock()
		return out
	}
	w.discovery.cancel = cancel
	w.mu.Unlock()
	inventory, discoveryErr := w.discovery.run(run, source.endpoint())
	w.mu.Lock()
	w.discovery.cancel = nil
	current := w.now()
	stopped := w.discovery.stopped
	w.mu.Unlock()
	out.Observed = current.UTC().Truncate(time.Second).Format("2006-01-02T15:04:05Z")
	if stopped || parent.Err() != nil || !current.Before(expiry) {
		return out // No late or cancelled inventory is published or cached.
	}
	out.Reason = reason("source_unavailable")
	if discoveryErr == nil && run.Err() == nil {
		out.Status, out.Reason, out.Inventory = "observed", nil, &inventory
	}
	encoded, marshalErr := json.Marshal(out)
	if marshalErr != nil || len(encoded) > MaxFrame || validateHikvisionDiscoveryReply(encoded) != nil {
		out.Status, out.Reason, out.Inventory = "failed", reason("source_unavailable"), nil
		encoded, _ = json.Marshal(out)
	}
	w.mu.Lock()
	if w.discovery.stopped || parent.Err() != nil || !w.now().Before(expiry) {
		w.mu.Unlock()
		out.Status, out.Reason, out.Inventory = "failed", reason("authority_lost"), nil
		return out
	}
	w.discovery.receipts[g.Request] = hikvisionDiscoveryReceipt{payloadHash: hash(payload), reply: encoded}
	w.mu.Unlock()
	return out
}

func observationText(s string, required bool, maximum int) bool {
	if !utf8.ValidString(s) || len(s) > maximum || required && len(s) == 0 {
		return false
	}
	for _, r := range s {
		if unicode.IsControl(r) || unicode.Is(unicode.Cf, r) {
			return false
		}
	}
	return true
}

func validateHikvisionDiscoveryReply(raw []byte) error {
	if Closed(raw, "version", "operation", "request_uuid", "device_id", "context_sha256", "credential_binding_sha256", "observed_at", "status", "inventory", "reason_code") != nil {
		return ErrProtocol
	}
	var resultFields map[string]json.RawMessage
	_ = json.Unmarshal(raw, &resultFields)
	for key, value := range resultFields {
		if key != "inventory" && key != "reason_code" && bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return ErrProtocol
		}
	}
	var out hikvisionDiscoveryReply
	if json.Unmarshal(raw, &out) != nil || out.Version != 1 || out.Operation != hikvisionDiscoveryOperation || !uuid.MatchString(out.Request) {
		return ErrProtocol
	}
	if _, err := instant(out.Observed); err != nil {
		return ErrProtocol
	}
	// Invalid incoming authority has no device/context to echo; this is a denial
	// only, never a result that the central broker may commit as an observation.
	bound := out.Device > 0 && out.Device <= 9007199254740991 && digest.MatchString(out.ContextHash) && digest.MatchString(out.CredentialBinding)
	empty := out.Device == 0 && out.ContextHash == "" && out.CredentialBinding == ""
	if !bound && !empty {
		return ErrProtocol
	}
	if out.Status == "failed" {
		if out.Inventory != nil || out.Reason == nil || (*out.Reason != "source_unavailable" && *out.Reason != "authority_lost" && *out.Reason != "capacity_unavailable") {
			return ErrProtocol
		}
		return nil
	}
	if !bound || out.Status != "observed" || out.Reason != nil || out.Inventory == nil {
		return ErrProtocol
	}
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(raw, &fields)
	inventoryRaw := fields["inventory"]
	if Closed(inventoryRaw, "identity", "channels") != nil {
		return ErrProtocol
	}
	var inventoryFields map[string]json.RawMessage
	_ = json.Unmarshal(inventoryRaw, &inventoryFields)
	if Closed(inventoryFields["identity"], "model", "serial", "device_id", "firmware", "firmware_build") != nil {
		return ErrProtocol
	}
	var identityFields map[string]json.RawMessage
	_ = json.Unmarshal(inventoryFields["identity"], &identityFields)
	for _, value := range identityFields {
		if bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return ErrProtocol
		}
	}
	id := out.Inventory.Identity
	if !observationText(id.Model, true, 128) || !observationText(id.Serial, true, 128) || !observationText(id.DeviceID, true, 128) || !observationText(id.Firmware, true, 128) || !observationText(id.FirmwareBuild, false, 128) {
		return ErrProtocol
	}
	var channels []json.RawMessage
	if json.Unmarshal(inventoryFields["channels"], &channels) != nil || len(channels) == 0 || len(channels) > 256 || len(out.Inventory.Channels) != len(channels) {
		return ErrProtocol
	}
	seen := map[string]bool{}
	for i, channel := range out.Inventory.Channels {
		if Closed(channels[i], "id", "input_id", "name", "codec", "enabled", "video_enabled", "width", "height", "fps100", "audio_enabled") != nil || !hikvisionIdentifier.MatchString(channel.ID) || !hikvisionIdentifier.MatchString(channel.InputID) || seen[channel.ID] || !observationText(channel.Name, false, 128) || !observationText(channel.Codec, true, 32) || channel.Width < 1 || channel.Width > 16384 || channel.Height < 1 || channel.Height > 16384 || channel.FPS100 < 1 || channel.FPS100 > 12000 {
			return ErrProtocol
		}
		// Boolean null must not decode to a fabricated false. Only audio may be
		// unknown, as an explicit null in the normalized observation.
		var values map[string]json.RawMessage
		_ = json.Unmarshal(channels[i], &values)
		for key, value := range values {
			if key != "audio_enabled" && bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
				return ErrProtocol
			}
		}
		for _, key := range []string{"enabled", "video_enabled"} {
			if string(values[key]) != "true" && string(values[key]) != "false" {
				return ErrProtocol
			}
		}
		seen[channel.ID] = true
	}
	return nil
}

func validatePrivateWorkerReply(raw []byte) error {
	var header struct {
		Operation string `json:"operation"`
	}
	if json.Unmarshal(raw, &header) != nil {
		return ErrProtocol
	}
	if header.Operation == hikvisionRecorderDiscoveryOperation {
		return validateHikvisionRecorderDiscoveryReply(raw)
	}
	if header.Operation == hikvisionDiscoveryOperation {
		return validateHikvisionDiscoveryReply(raw)
	}
	return Closed(raw, "version", "request_uuid", "session_uuid", "generation", "status", "answer", "reason_code")
}
