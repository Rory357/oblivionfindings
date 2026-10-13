package cctv

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func recorderInventoryFixture(t *testing.T) hikvisionRecorderInventory {
	t.Helper()
	identity, e := parseHikvisionIdentity([]byte(hikvisionRecorderIdentityFixture))
	if e != nil {
		t.Fatal(e)
	}
	channels, e := parseHikvisionRecorderChannels([]byte(hikvisionChannelsFixture(hikvisionRecorderChannelFixture)))
	if e != nil {
		t.Fatal(e)
	}
	inputs, e := parseHikvisionRecorderInputs([]byte(hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture)))
	if e != nil {
		t.Fatal(e)
	}
	return hikvisionRecorderInventory{Identity: identity, Channels: channels, Inputs: inputs, ResponseSHA256: map[string]string{"device_info": hash([]byte(hikvisionRecorderIdentityFixture)), "streaming_proxy": hash([]byte(hikvisionChannelsFixture(hikvisionRecorderChannelFixture))), "input_status": hash([]byte(hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture)))}}
}

func recorderWorkerFixture(t *testing.T) (*Worker, hikvisionRecorderDiscoveryGrant, []byte, ed25519.PrivateKey, *atomic.Int32) {
	t.Helper()
	w, old, source, key, calls := discoveryWorkerFixture(t)
	bytes, _ := json.Marshal(old)
	var g hikvisionRecorderDiscoveryGrant
	if json.Unmarshal(bytes, &g) != nil {
		t.Fatal("fixture")
	}
	g.Version, g.Type, g.Capability, g.SourceKind, g.ResourceSet = 2, hikvisionRecorderDiscoveryType, hikvisionRecorderDiscoveryCapability, "recorder", hikvisionRecorderDiscoveryResourceSet
	inventory := recorderInventoryFixture(t)
	w.discovery.runRecorder = func(context.Context, httpsTunnelSource) (hikvisionRecorderInventory, error) {
		calls.Add(1)
		return inventory, nil
	}
	return w, g, source, key, calls
}

func signedRecorderDiscoveryWire(t *testing.T, key ed25519.PrivateKey, g hikvisionRecorderDiscoveryGrant, source []byte) []byte {
	t.Helper()
	raw, e := json.Marshal(g)
	if e != nil {
		t.Fatal(e)
	}
	envelope := Envelope{Payload: base64.StdEncoding.EncodeToString(raw), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, raw))}
	wire, e := json.Marshal(map[string]any{"version": 2, "operation": hikvisionRecorderDiscoveryOperation, "request_uuid": g.Request, "grant": envelope, "source": json.RawMessage(source)})
	if e != nil {
		t.Fatal(e)
	}
	return wire
}
func TestHikvisionRecorderSignedDiscoveryImmutableReplay(t *testing.T) {
	w, g, source, key, calls := recorderWorkerFixture(t)
	raw := signedRecorderDiscoveryWire(t, key, g, source)
	original := w.DiscoverHikvisionRecorder(t.Context(), raw)
	if original.Status != "observed" || original.Inventory == nil || calls.Load() != 1 {
		t.Fatal("signed observation missing")
	}
	encoded, _ := json.Marshal(original)
	if validatePrivateWorkerReply(encoded) != nil {
		t.Fatal("closed IPC result rejected")
	}
	// A caller cannot mutate the stored observation by retaining a pointer.
	original.Inventory.Identity.Serial = "tampered-local-copy"
	later, _ := instant(g.Issued)
	w.now = func() time.Time { return later.Add(time.Second) }
	repeated := w.DiscoverHikvisionRecorder(t.Context(), raw)
	same, _ := json.Marshal(repeated)
	if string(encoded) != string(same) || calls.Load() != 1 {
		t.Fatal("replay changed/reopened discovery")
	}
	for _, change := range []func(*hikvisionRecorderDiscoveryGrant){func(v *hikvisionRecorderDiscoveryGrant) { v.ContextHash = strings.Repeat("d", 64) }, func(v *hikvisionRecorderDiscoveryGrant) { v.CredentialBinding = strings.Repeat("d", 64) }, func(v *hikvisionRecorderDiscoveryGrant) { v.Device++ }, func(v *hikvisionRecorderDiscoveryGrant) { v.Scope++ }, func(v *hikvisionRecorderDiscoveryGrant) {
		v.Expires = later.Add(11 * time.Second).Format("2006-01-02T15:04:05Z")
	}} {
		changed := g
		change(&changed)
		denied := w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key, changed, source))
		if denied.Status != "failed" || denied.Inventory != nil || calls.Load() != 1 {
			t.Fatal("used request identity changed")
		}
	}
	expiry, _ := instant(g.Expires)
	w.now = func() time.Time { return expiry }
	denied := w.DiscoverHikvisionRecorder(t.Context(), raw)
	if denied.Inventory != nil || calls.Load() != 1 {
		t.Fatal("expired authority retrieved cached inventory")
	}
}

func TestHikvisionRecorderSignedDiscoveryDeniedBeforeNetwork(t *testing.T) {
	cases := []struct {
		name   string
		change func(*Worker, *hikvisionRecorderDiscoveryGrant, *[]byte)
	}{
		{"camera_capability", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) {
			g.Capability = "cctv:hikvision:discovery:read_only"
		}},
		{"camera_kind", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.SourceKind = "direct_camera" }},
		{"extra_resources", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.ResourceSet = "all_isapi" }},
		{"camera_grant", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) {
			g.Type = "collector_hikvision_discovery"
		}},
		{"camera_version", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Version = 1 }},
		{"unconfigured", func(w *Worker, _ *hikvisionRecorderDiscoveryGrant, _ *[]byte) { w.configured = false }},
		{"foreign_collector", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) {
			g.Collector = "55555555-5555-4555-8555-555555555555"
		}},
		{"foreign_site", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Site++ }},
		{"wrong_kind", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Type = "collector_cctv_live" }},
		{"zero_device", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Device = 0 }},
		{"oversized_id", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Scope = 9007199254740992 }},
		{"bad_request", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Request = "foreign" }},
		{"bad_context", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) {
			g.ContextHash = strings.Repeat("A", 64)
		}},
		{"bad_credential_binding", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.CredentialBinding = "unknown" }},
		{"future", func(w *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) {
			g.Issued = w.now().Add(time.Second).Format("2006-01-02T15:04:05Z")
		}},
		{"expired", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Expires = g.Issued }},
		{"long_grant", func(w *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) {
			g.Expires = w.now().Add(16 * time.Second).Format("2006-01-02T15:04:05Z")
		}},
		{"dns_dial", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.IP = "camera.example.test" }},
		{"multicast", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.IP = "224.0.0.1" }},
		{"bad_tls_name", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.ServerName = "camera.test/foreign" }},
		{"bad_pin", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Pin = "missing" }},
		{"foreign_port", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, _ *[]byte) { g.Port++ }},
		{"hash_mismatch", func(_ *Worker, _ *hikvisionRecorderDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.ReplaceAll(string(*s), "fixture-isapi-secret", "changed-secret"))
		}},
		{"secret_control", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.ReplaceAll(string(*s), "fixture-isapi-secret", `secret\n`))
			g.SourceHash = hash(*s)
		}},
		{"unexpected_url", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.TrimSuffix(string(*s), "}") + `,"url":"https://foreign.test/"}`)
			g.SourceHash = hash(*s)
		}},
		{"duplicate_source", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.TrimSuffix(string(*s), "}") + `,"allowed_port":443}`)
			g.SourceHash = hash(*s)
		}},
		{"null_password", func(_ *Worker, g *hikvisionRecorderDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.ReplaceAll(string(*s), `"fixture-isapi-secret"`, `null`))
			g.SourceHash = hash(*s)
		}},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			w, g, source, key, calls := recorderWorkerFixture(t)
			c.change(w, &g, &source)
			result := w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key, g, source))
			if result.Status != "failed" || result.Inventory != nil || calls.Load() != 0 {
				t.Fatal("unsafe grant/source reached network")
			}
		})
	}
	t.Run("invalid_signature", func(t *testing.T) {
		w, g, s, _, calls := recorderWorkerFixture(t)
		_, foreign, _ := ed25519.GenerateKey(rand.Reader)
		if w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, foreign, g, s)).Inventory != nil || calls.Load() != 0 {
			t.Fatal("foreign signature accepted")
		}
	})
	for _, mode := range []string{"unknown_payload", "duplicate_payload", "null_payload", "noncanonical_base64", "message_request", "duplicate_message", "extra_offer"} {
		t.Run(mode, func(t *testing.T) {
			w, g, s, key, calls := recorderWorkerFixture(t)
			raw := signedRecorderDiscoveryWire(t, key, g, s)
			var m map[string]json.RawMessage
			_ = json.Unmarshal(raw, &m)
			var envelope Envelope
			_ = json.Unmarshal(m["grant"], &envelope)
			payload, _ := base64.StdEncoding.DecodeString(envelope.Payload)
			switch mode {
			case "unknown_payload":
				payload = []byte(strings.TrimSuffix(string(payload), "}") + `,"url":"https://foreign.test"}`)
			case "duplicate_payload":
				payload = []byte(strings.TrimSuffix(string(payload), "}") + `,"device_id":2}`)
			case "null_payload":
				payload = []byte(strings.ReplaceAll(string(payload), `"device_id":2`, `"device_id":null`))
			case "message_request":
				m["request_uuid"] = json.RawMessage(`"55555555-5555-4555-8555-555555555555"`)
			case "extra_offer":
				m["offer"] = json.RawMessage(`{"type":"offer","sdp":"forbidden"}`)
			}
			envelope.Payload = base64.StdEncoding.EncodeToString(payload)
			envelope.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(key, payload))
			if mode == "noncanonical_base64" {
				envelope.Payload = "\n" + envelope.Payload
			}
			m["grant"], _ = json.Marshal(envelope)
			raw, _ = json.Marshal(m)
			if mode == "duplicate_message" {
				raw = []byte(strings.TrimSuffix(string(raw), "}") + `,"operation":"discover_hikvision"}`)
			}
			if w.DiscoverHikvisionRecorder(t.Context(), raw).Inventory != nil || calls.Load() != 0 {
				t.Fatal("ambiguous message accepted")
			}
		})
	}
}

func TestHikvisionRecorderSignedDiscoveryFailureCapacityAndDeadline(t *testing.T) {
	t.Run("failed_replay", func(t *testing.T) {
		w, g, s, key, calls := recorderWorkerFixture(t)
		w.discovery.runRecorder = func(context.Context, httpsTunnelSource) (hikvisionRecorderInventory, error) {
			calls.Add(1)
			return recorderInventoryFixture(t), ErrProtocol
		}
		first := w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key, g, s))
		again := w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key, g, s))
		if first.Inventory != nil || first.Status != "failed" || !reflect.DeepEqual(first, again) || calls.Load() != 1 {
			t.Fatal("partial failure/retry changed")
		}
	})
	t.Run("capacity", func(t *testing.T) {
		w, g, s, key, calls := recorderWorkerFixture(t)
		for i := 0; i < 256; i++ {
			w.discovery.receipts[fmt.Sprint(i)] = hikvisionDiscoveryReceipt{}
		}
		reply := w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key, g, s))
		if reply.Reason == nil || *reply.Reason != "capacity_unavailable" || calls.Load() != 0 {
			t.Fatal("capacity silently evicted/reopened")
		}
	})
	for _, mode := range []string{"parent_cancel", "worker_close", "expiry_after_read"} {
		t.Run(mode, func(t *testing.T) {
			w, g, s, key, _ := recorderWorkerFixture(t)
			ctx, cancel := context.WithCancel(t.Context())
			defer cancel()
			started := make(chan struct{})
			done := make(chan hikvisionRecorderDiscoveryReply, 1)
			w.discovery.runRecorder = func(run context.Context, _ httpsTunnelSource) (hikvisionRecorderInventory, error) {
				close(started)
				if mode == "expiry_after_read" {
					expiry, _ := instant(g.Expires)
					w.mu.Lock()
					w.now = func() time.Time { return expiry }
					w.mu.Unlock()
				} else {
					<-run.Done()
				}
				return recorderInventoryFixture(t), nil
			}
			go func() { done <- w.DiscoverHikvisionRecorder(ctx, signedRecorderDiscoveryWire(t, key, g, s)) }()
			select {
			case <-started:
			case <-time.After(time.Second):
				t.Fatal("discovery did not begin")
			}
			if mode == "parent_cancel" {
				cancel()
			} else if mode == "worker_close" {
				w.Close()
			}
			select {
			case reply := <-done:
				if reply.Inventory != nil || len(w.discovery.receipts) != 0 {
					t.Fatal("late/cancelled inventory published")
				}
			case <-time.After(time.Second):
				t.Fatal("discovery did not close promptly")
			}
		})
	}
	t.Run("oversized_inventory", func(t *testing.T) {
		w, g, s, key, _ := recorderWorkerFixture(t)
		inventory := recorderInventoryFixture(t)
		sample := inventory.Channels[0]
		sample.Name = strings.Repeat("x", 128)
		inventory.Channels = nil
		for i := 1; i <= 256; i++ {
			channel := sample
			channel.ID = fmt.Sprint(i)
			inventory.Channels = append(inventory.Channels, channel)
		}
		w.discovery.runRecorder = func(context.Context, httpsTunnelSource) (hikvisionRecorderInventory, error) { return inventory, nil }
		reply := w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key, g, s))
		if reply.Inventory != nil || reply.Status != "failed" {
			t.Fatal("oversized inventory partially published")
		}
	})
}

func TestHikvisionRecorderDiscoveryPHPProducerSealedLeaseAndConsumer(t *testing.T) {
	php, producer := os.Getenv("OBLI_RECORDER_DISCOVERY_PHP_EXECUTABLE"), os.Getenv("OBLI_RECORDER_DISCOVERY_PHP_PRODUCER")
	if php == "" || producer == "" {
		t.Skip("actual PHP producer paths required")
	}
	if !filepath.IsAbs(php) || !filepath.IsAbs(producer) {
		t.Fatal("absolute fixture paths required")
	}
	endpoint, roots, _ := newHikvisionRecorderISAPIFixture(t, "normal")
	now := time.Now().UTC().Truncate(time.Second)
	input, _ := json.Marshal(map[string]any{"mode": "produce", "allowed_ip": endpoint.IP, "allowed_port": endpoint.Port, "tls_server_name": endpoint.ServerName, "tls_pin": endpoint.Pin, "issued_at": now.Format("2006-01-02T15:04:05Z")})
	ctx, cancel := context.WithTimeout(t.Context(), 10*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, php, "-f", producer)
	command.Stdin = strings.NewReader(string(input))
	output, err := command.Output()
	if err != nil {
		t.Fatal("PHP producer failed", err)
	}
	reader := strings.NewReader(string(output))
	configure, err := ReadFrame(reader)
	if err != nil {
		t.Fatal("actual configure", err)
	}
	request, err := ReadFrame(reader)
	if err != nil || reader.Len() != 0 {
		t.Fatal("actual producer framing", err)
	}
	w := New(nil)
	defer w.Close()
	w.now = func() time.Time { return now }
	w.discovery.runRecorder = func(ctx context.Context, s httpsTunnelSource) (hikvisionRecorderInventory, error) {
		return discoverHikvisionRecorder(ctx, s, roots)
	}
	if w.Handle(t.Context(), configure).Status != "requested" {
		t.Fatal("actual PHP key/config rejected")
	}
	reply := w.DiscoverHikvisionRecorder(t.Context(), request)
	if reply.Status != "observed" || reply.Inventory == nil {
		t.Fatal("original PHP signed source rejected")
	}
	repeat := w.DiscoverHikvisionRecorder(t.Context(), request)
	if !reflect.DeepEqual(reply, repeat) {
		t.Fatal("original PHP replay changed")
	}
	result, _ := json.Marshal(map[string]any{"mode": "consume", "result": reply, "issued_at": now.Format("2006-01-02T15:04:05Z")})
	command = exec.CommandContext(ctx, php, "-f", producer)
	command.Stdin = strings.NewReader(string(result))
	consumed, err := command.Output()
	if err != nil || string(consumed) != "accepted\n" {
		t.Fatal("actual PHP consumer rejected original Go result", err)
	}
	if path := os.Getenv("OBLI_RECORDER_DISCOVERY_PHP_EVIDENCE"); path != "" {
		evidence, _ := json.Marshal(map[string]any{"request_sha256": hash(request), "configure_sha256": hash(configure), "result": reply, "producer": producer, "synthetic": true, "hardware_acceptance": false})
		if os.WriteFile(path, evidence, 0600) != nil {
			t.Fatal("evidence write")
		}
	}
}

func TestHikvisionRecorderSharedReceiptIdentityCannotBecomeCameraWork(t *testing.T) {
	w, g, s, key, calls := recorderWorkerFixture(t)
	first := w.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key, g, s))
	if first.Inventory == nil {
		t.Fatal("recorder observation missing")
	}
	bytes, _ := json.Marshal(g)
	var camera hikvisionDiscoveryGrant
	_ = json.Unmarshal(bytes, &camera)
	camera.Version, camera.Type = 1, "collector_hikvision_discovery"
	if w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, camera, s)).Inventory != nil || calls.Load() != 1 {
		t.Fatal("same identity changed schema/reopened network")
	}
	w2, camera2, s2, key2, calls2 := discoveryWorkerFixture(t)
	if w2.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key2, camera2, s2)).Inventory == nil {
		t.Fatal("camera observation missing")
	}
	g.Collector, g.Site, g.Device, g.Scope, g.Request, g.ContextHash, g.CredentialBinding, g.SourceHash, g.IP, g.Port, g.ServerName, g.Pin, g.Issued, g.Expires = camera2.Collector, camera2.Site, camera2.Device, camera2.Scope, camera2.Request, camera2.ContextHash, camera2.CredentialBinding, camera2.SourceHash, camera2.IP, camera2.Port, camera2.ServerName, camera2.Pin, camera2.Issued, camera2.Expires
	w2.discovery.runRecorder = func(context.Context, httpsTunnelSource) (hikvisionRecorderInventory, error) {
		calls2.Add(1)
		return recorderInventoryFixture(t), nil
	}
	if w2.DiscoverHikvisionRecorder(t.Context(), signedRecorderDiscoveryWire(t, key2, g, s2)).Inventory != nil || calls2.Load() != 1 {
		t.Fatal("camera receipt converted to recorder work")
	}
}

func TestHikvisionRecorderDiscoveryPrivateServeAndClosedReplies(t *testing.T) {
	w, g, s, key, _ := recorderWorkerFixture(t)
	raw := signedRecorderDiscoveryWire(t, key, g, s)
	if _, err := decodeMessage(raw); err != nil {
		t.Fatal("supervisor rejected discovery frame")
	}
	reply := w.DiscoverHikvisionRecorder(t.Context(), raw)
	encoded, _ := json.Marshal(reply)
	if validatePrivateWorkerReply(encoded) != nil {
		t.Fatal("supervisor rejected observation")
	}
	changes := []string{
		strings.ReplaceAll(string(encoded), `"firmware_build":"fixture-build"`, `"firmware_build":null`),
		strings.ReplaceAll(string(encoded), `"enabled":true`, `"enabled":null`),
		strings.ReplaceAll(string(encoded), `"name":"Synthetic recorder input"`, `"name":null`),
		strings.ReplaceAll(string(encoded), `"audio_enabled":null`, `"audio_enabled":"false"`),
		strings.TrimSuffix(string(encoded), "}") + `,"private_url":"https://secret.test"}`,
	}
	for _, bad := range changes {
		if bad == string(encoded) {
			t.Fatal("denial fixture did not change current result")
		}
		if validatePrivateWorkerReply([]byte(bad)) == nil {
			t.Fatal("ambiguous/private result accepted", bad)
		}
	}
	// Real private Serve dispatch, with an invalid source that must fail before
	// network. Pipe lifetime remains open until the result is consumed.
	inboundR, inboundW := io.Pipe()
	outboundR, outboundW := io.Pipe()
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- Serve(ctx, inboundR, outboundW, nil) }()
	configure := map[string]any{"version": 1, "operation": "configure", "collector_uuid": g.Collector, "site_id": g.Site, "server_public_key": base64.StdEncoding.EncodeToString(key.Public().(ed25519.PublicKey))}
	if WriteFrame(inboundW, configure) != nil {
		t.Fatal("configure pipe")
	}
	if _, err := ReadFrame(outboundR); err != nil {
		t.Fatal(err)
	}
	healthRequest := "77777777-7777-4777-8777-777777777777"
	if WriteFrame(inboundW, map[string]any{"version": 1, "operation": "health_hikvision_recorder_discovery", "request_uuid": healthRequest}) != nil {
		t.Fatal("recorder profile pipe")
	}
	health, he := ReadFrame(outboundR)
	var hr Reply
	_ = json.Unmarshal(health, &hr)
	if he != nil || validatePrivateWorkerReply(health) != nil || hr.Status != "requested" || hr.Request != healthRequest {
		t.Fatal("configured recorder profile unavailable")
	}
	badSource := []byte(strings.ReplaceAll(string(s), "fixture-isapi-secret", `bad\nsecret`))
	g.SourceHash = hash(badSource)
	g.Issued = time.Now().UTC().Truncate(time.Second).Format("2006-01-02T15:04:05Z")
	g.Expires = time.Now().UTC().Truncate(time.Second).Add(10 * time.Second).Format("2006-01-02T15:04:05Z")
	input := signedRecorderDiscoveryWire(t, key, g, badSource)
	var framed any
	_ = json.Unmarshal(input, &framed)
	if WriteFrame(inboundW, framed) != nil {
		t.Fatal("discovery pipe")
	}
	response, err := ReadFrame(outboundR)
	if err != nil || validatePrivateWorkerReply(response) != nil {
		t.Fatal("Serve discovery reply", err)
	}
	var result hikvisionRecorderDiscoveryReply
	_ = json.Unmarshal(response, &result)
	if result.Operation != hikvisionRecorderDiscoveryOperation || result.Status != "failed" || result.Inventory != nil {
		t.Fatal("wrong Serve dispatch")
	}
	cancel()
	_ = inboundW.Close()
	_ = outboundR.Close()
	_ = inboundR.Close()
	_ = outboundW.Close()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Serve shutdown")
	}
}

func TestHikvisionRecorderDiscoveryDoesNotBlockLiveLeaseWatchdog(t *testing.T) {
	w, live, message, key, media := fixture(t)
	defer w.Close()
	if w.Handle(t.Context(), wire(t, message)).Status != "ready" {
		t.Fatal("existing live fixture")
	}
	now := time.Now().UTC().Truncate(time.Second)
	source := []byte(`{"allowed_ip":"127.0.0.1","allowed_port":443,"tls_server_name":"camera.example.test","tls_pin":"` + strings.Repeat("a", 64) + `","username":"fixture-isapi-user","password":"fixture-isapi-secret"}`)
	g := hikvisionRecorderDiscoveryGrant{Version: 2, Type: hikvisionRecorderDiscoveryType, Capability: hikvisionRecorderDiscoveryCapability, SourceKind: "recorder", ResourceSet: hikvisionRecorderDiscoveryResourceSet, Collector: live.Collector, Site: live.Site, Device: 2, Scope: 3, Request: "88888888-8888-4888-8888-888888888888", ContextHash: strings.Repeat("b", 64), CredentialBinding: strings.Repeat("c", 64), SourceHash: hash(source), IP: "127.0.0.1", Port: 443, ServerName: "camera.example.test", Pin: strings.Repeat("a", 64), Issued: now.Format("2006-01-02T15:04:05Z"), Expires: now.Add(10 * time.Second).Format("2006-01-02T15:04:05Z")}
	started := make(chan struct{})
	w.discovery.runRecorder = func(ctx context.Context, _ httpsTunnelSource) (hikvisionRecorderInventory, error) {
		close(started)
		<-ctx.Done()
		return hikvisionRecorderInventory{}, ErrProtocol
	}
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	done := make(chan hikvisionRecorderDiscoveryReply, 1)
	raw := signedRecorderDiscoveryWire(t, key, g, source)
	go func() { done <- w.DiscoverHikvisionRecorder(ctx, raw) }()
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("discovery did not start")
	}
	w.mu.Lock()
	w.sessions[live.Session].deadline = time.Now().Add(60 * time.Millisecond)
	w.mu.Unlock()
	timer := time.NewTimer(500 * time.Millisecond)
	defer timer.Stop()
	tick := time.NewTicker(10 * time.Millisecond)
	defer tick.Stop()
	for !media.closed.Load() {
		select {
		case <-timer.C:
			t.Fatal("discovery blocked live expiry")
		case <-tick.C:
		}
	}
	cancel()
	select {
	case result := <-done:
		if result.Inventory != nil {
			t.Fatal("cancelled inventory")
		}
	case <-time.After(time.Second):
		t.Fatal("discovery cancellation")
	}
}
