package cctv

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"net/netip"

	"time"
)

// This grant authorizes only three fixed read-only ISAPI resources. It cannot
// activate a live source or establish a canonical channel/camera assignment.
type hikvisionRecorderDiscoveryGrant struct {
	Capability        string `json:"capability"`
	SourceKind        string `json:"source_kind"`
	ResourceSet       string `json:"resource_set"`
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

type hikvisionRecorderDiscoveryReply struct {
	Version           int                         `json:"version"`
	Operation         string                      `json:"operation"`
	Request           string                      `json:"request_uuid"`
	Device            int64                       `json:"device_id"`
	ContextHash       string                      `json:"context_sha256"`
	CredentialBinding string                      `json:"credential_binding_sha256"`
	Observed          string                      `json:"observed_at"`
	Status            string                      `json:"status"`
	Inventory         *hikvisionRecorderInventory `json:"inventory"`
	Reason            *string                     `json:"reason_code"`
}

func verifyHikvisionRecorderDiscovery(e Envelope, key ed25519.PublicKey, collector string, site int64, now time.Time) (hikvisionRecorderDiscoveryGrant, []byte, error) {
	var g hikvisionRecorderDiscoveryGrant
	b, err := base64.StdEncoding.Strict().DecodeString(e.Payload)
	signature, signatureErr := base64.StdEncoding.Strict().DecodeString(e.Signature)
	if err != nil || signatureErr != nil || len(b) > 8192 || base64.StdEncoding.EncodeToString(b) != e.Payload || base64.StdEncoding.EncodeToString(signature) != e.Signature || len(key) != ed25519.PublicKeySize || !ed25519.Verify(key, b, signature) {
		return g, nil, ErrProtocol
	}
	if Closed(b, "version", "type", "capability", "source_kind", "resource_set", "collector_uuid", "site_id", "device_id", "discovery_scope_id", "request_uuid", "context_sha256", "credential_binding_sha256", "source_sha256", "allowed_ip", "allowed_port", "tls_server_name", "tls_pin", "issued_at", "expires_at") != nil || json.Unmarshal(b, &g) != nil {
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
	if g.Version != 2 || g.Type != hikvisionRecorderDiscoveryType || g.Capability != hikvisionRecorderDiscoveryCapability || g.SourceKind != "recorder" || g.ResourceSet != hikvisionRecorderDiscoveryResourceSet || g.Collector != collector || g.Site != site || g.Site <= 0 || g.Site > 9007199254740991 || g.Device <= 0 || g.Device > 9007199254740991 || g.Scope <= 0 || g.Scope > 9007199254740991 || !uuid.MatchString(g.Request) || !digest.MatchString(g.ContextHash) || !digest.MatchString(g.CredentialBinding) || !digest.MatchString(g.SourceHash) || !endpoint.validEndpoint() || ie != nil || xe != nil || issued.After(now) || !expiry.After(now) || expiry.After(issued.Add(15*time.Second)) {
		return g, nil, ErrProtocol
	}
	return g, b, nil
}

func recorderDiscoveryFailure(request string, now time.Time) hikvisionRecorderDiscoveryReply {
	return hikvisionRecorderDiscoveryReply{Version: 2, Operation: hikvisionRecorderDiscoveryOperation, Request: request, Observed: now.UTC().Truncate(time.Second).Format("2006-01-02T15:04:05Z"), Status: "failed", Reason: reason("authority_lost")}
}

func (w *Worker) DiscoverHikvisionRecorder(parent context.Context, raw []byte) hikvisionRecorderDiscoveryReply {
	m, err := decodeMessage(raw)
	w.discovery.serial.Lock()
	defer w.discovery.serial.Unlock()
	w.mu.Lock()
	now := w.now()
	out := recorderDiscoveryFailure(m.Request, now)
	if err != nil || m.Operation != hikvisionRecorderDiscoveryOperation || !w.configured || w.discovery.stopped || parent.Err() != nil {
		w.mu.Unlock()
		return out
	}
	g, payload, err := verifyHikvisionRecorderDiscovery(m.Grant, w.key, w.collector, w.site, now)
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
		var cached hikvisionRecorderDiscoveryReply
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
	inventory, discoveryErr := w.discovery.runRecorder(run, source.endpoint())
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
	if marshalErr != nil || len(encoded) > MaxFrame || validateHikvisionRecorderDiscoveryReply(encoded) != nil {
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

func recorderNonNull(raw []byte, nullable ...string) bool {
	var fields map[string]json.RawMessage
	if json.Unmarshal(raw, &fields) != nil {
		return false
	}
	allowed := map[string]bool{}
	for _, name := range nullable {
		allowed[name] = true
	}
	for name, value := range fields {
		if !allowed[name] && bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return false
		}
	}
	return true
}

func validateHikvisionRecorderDiscoveryReply(raw []byte) error {
	if len(raw) > MaxFrame || Closed(raw, "version", "operation", "request_uuid", "device_id", "context_sha256", "credential_binding_sha256", "observed_at", "status", "inventory", "reason_code") != nil || !recorderNonNull(raw, "inventory", "reason_code") {
		return ErrProtocol
	}
	var out hikvisionRecorderDiscoveryReply
	if json.Unmarshal(raw, &out) != nil || out.Version != 2 || out.Operation != hikvisionRecorderDiscoveryOperation || !uuid.MatchString(out.Request) {
		return ErrProtocol
	}
	if _, err := instant(out.Observed); err != nil {
		return ErrProtocol
	}
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
	if Closed(inventoryRaw, "identity", "channels", "inputs", "response_sha256") != nil || !recorderNonNull(inventoryRaw) {
		return ErrProtocol
	}
	var inventoryFields map[string]json.RawMessage
	_ = json.Unmarshal(inventoryRaw, &inventoryFields)
	if Closed(inventoryFields["identity"], "model", "serial", "device_id", "firmware", "firmware_build") != nil || !recorderNonNull(inventoryFields["identity"]) {
		return ErrProtocol
	}
	id := out.Inventory.Identity
	if !observationText(id.Model, true, 128) || !observationText(id.Serial, true, 128) || !observationText(id.DeviceID, true, 128) || !observationText(id.Firmware, true, 128) || !observationText(id.FirmwareBuild, false, 128) {
		return ErrProtocol
	}
	var channels []json.RawMessage
	if json.Unmarshal(inventoryFields["channels"], &channels) != nil || len(channels) == 0 || len(channels) > 256 || len(channels) != len(out.Inventory.Channels) {
		return ErrProtocol
	}
	seen := map[string]bool{}
	for i, channel := range out.Inventory.Channels {
		if Closed(channels[i], "id", "input_id", "input_kind", "name", "codec", "enabled", "video_enabled", "width", "height", "fps100", "audio_enabled") != nil || !recorderNonNull(channels[i], "audio_enabled") || !hikvisionIdentifier.MatchString(channel.ID) || !hikvisionIdentifier.MatchString(channel.InputID) || seen[channel.ID] || (channel.InputKind != "digital_proxy" && channel.InputKind != "local_video") || !observationText(channel.Name, false, 128) || !observationText(channel.Codec, true, 32) || channel.Width < 1 || channel.Width > 16384 || channel.Height < 1 || channel.Height > 16384 || channel.FPS100 < 0 || channel.FPS100 > 12000 {
			return ErrProtocol
		}
		seen[channel.ID] = true
	}
	var inputs []json.RawMessage
	if json.Unmarshal(inventoryFields["inputs"], &inputs) != nil || len(inputs) > 256 || len(inputs) != len(out.Inventory.Inputs) {
		return ErrProtocol
	}
	seen = map[string]bool{}
	for i, input := range out.Inventory.Inputs {
		if Closed(inputs[i], "id", "proxy_protocol", "addressing_type", "address", "management_port", "source_input_id", "online", "camera_identity", "association") != nil || !recorderNonNull(inputs[i], "address", "management_port", "source_input_id", "online", "camera_identity") || !hikvisionIdentifier.MatchString(input.ID) || seen[input.ID] || !observationText(input.ProxyProtocol, true, 32) || (input.AddressingType != "ipaddress" && input.AddressingType != "hostname") || input.Association != "unverified" || !bytes.Equal(bytes.TrimSpace(input.CameraIdentity), []byte("null")) {
			return ErrProtocol
		}
		if input.Address != nil {
			if !observationText(*input.Address, true, 253) {
				return ErrProtocol
			}
			if input.AddressingType == "ipaddress" {
				addr, err := netip.ParseAddr(*input.Address)
				if err != nil || addr.Zone() != "" {
					return ErrProtocol
				}
			} else if !recorderHostname.MatchString(*input.Address) {
				return ErrProtocol
			}
		}
		if input.ManagementPort != nil && (*input.ManagementPort < 0 || *input.ManagementPort > 65535) {
			return ErrProtocol
		}
		if input.SourceInputID != nil && !recorderSourceInputID.MatchString(*input.SourceInputID) {
			return ErrProtocol
		}
		seen[input.ID] = true
	}
	if Closed(inventoryFields["response_sha256"], "device_info", "streaming_proxy", "input_status") != nil {
		return ErrProtocol
	}
	for _, name := range []string{"device_info", "streaming_proxy", "input_status"} {
		if !digest.MatchString(out.Inventory.ResponseSHA256[name]) {
			return ErrProtocol
		}
	}
	return nil
}
