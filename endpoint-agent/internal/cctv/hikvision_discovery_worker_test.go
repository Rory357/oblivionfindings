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

func discoveryInventoryFixture(t *testing.T) hikvisionInventory {
	t.Helper()
	identity, e := parseHikvisionIdentity([]byte(hikvisionIdentityFixture))
	if e != nil {
		t.Fatal(e)
	}
	channels, e := parseHikvisionChannels([]byte(hikvisionChannelsFixture(hikvisionChannelFixture)))
	if e != nil {
		t.Fatal(e)
	}
	return hikvisionInventory{Identity: identity, Channels: channels}
}
func signedDiscoveryWire(t *testing.T, key ed25519.PrivateKey, g hikvisionDiscoveryGrant, source []byte) []byte {
	t.Helper()
	raw, e := json.Marshal(g)
	if e != nil {
		t.Fatal(e)
	}
	envelope := Envelope{Payload: base64.StdEncoding.EncodeToString(raw), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, raw))}
	wire, e := json.Marshal(map[string]any{"version": 1, "operation": hikvisionDiscoveryOperation, "request_uuid": g.Request, "grant": envelope, "source": json.RawMessage(source)})
	if e != nil {
		t.Fatal(e)
	}
	return wire
}
func discoveryWorkerFixture(t *testing.T) (*Worker, hikvisionDiscoveryGrant, []byte, ed25519.PrivateKey, *atomic.Int32) {
	t.Helper()
	pub, key, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	now := time.Now().UTC().Truncate(time.Second)
	source := []byte(`{"allowed_ip":"127.0.0.1","allowed_port":443,"tls_server_name":"camera.example.test","tls_pin":"` + strings.Repeat("a", 64) + `","username":"fixture-isapi-user","password":"fixture-isapi-secret"}`)
	g := hikvisionDiscoveryGrant{Version: 1, Type: "collector_hikvision_discovery", Collector: "11111111-1111-4111-8111-111111111111", Site: 1, Device: 2, Scope: 3, Request: "44444444-4444-4444-8444-444444444444", ContextHash: strings.Repeat("b", 64), CredentialBinding: strings.Repeat("c", 64), SourceHash: hash(source), IP: "127.0.0.1", Port: 443, ServerName: "camera.example.test", Pin: strings.Repeat("a", 64), Issued: now.Format("2006-01-02T15:04:05Z"), Expires: now.Add(10 * time.Second).Format("2006-01-02T15:04:05Z")}
	w := New(nil)
	w.key, w.collector, w.site, w.configured = pub, g.Collector, g.Site, true
	w.now = func() time.Time { return now }
	t.Cleanup(w.Close)
	calls := &atomic.Int32{}
	inventory := discoveryInventoryFixture(t)
	w.discovery.run = func(context.Context, httpsTunnelSource) (hikvisionInventory, error) {
		calls.Add(1)
		return inventory, nil
	}
	return w, g, source, key, calls
}

func TestHikvisionSignedDiscoveryImmutableReplay(t *testing.T) {
	w, g, source, key, calls := discoveryWorkerFixture(t)
	raw := signedDiscoveryWire(t, key, g, source)
	original := w.DiscoverHikvision(t.Context(), raw)
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
	repeated := w.DiscoverHikvision(t.Context(), raw)
	same, _ := json.Marshal(repeated)
	if string(encoded) != string(same) || calls.Load() != 1 {
		t.Fatal("replay changed/reopened discovery")
	}
	for _, change := range []func(*hikvisionDiscoveryGrant){func(v *hikvisionDiscoveryGrant) { v.ContextHash = strings.Repeat("d", 64) }, func(v *hikvisionDiscoveryGrant) { v.CredentialBinding = strings.Repeat("d", 64) }, func(v *hikvisionDiscoveryGrant) { v.Device++ }, func(v *hikvisionDiscoveryGrant) { v.Scope++ }, func(v *hikvisionDiscoveryGrant) {
		v.Expires = later.Add(11 * time.Second).Format("2006-01-02T15:04:05Z")
	}} {
		changed := g
		change(&changed)
		denied := w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, changed, source))
		if denied.Status != "failed" || denied.Inventory != nil || calls.Load() != 1 {
			t.Fatal("used request identity changed")
		}
	}
	expiry, _ := instant(g.Expires)
	w.now = func() time.Time { return expiry }
	denied := w.DiscoverHikvision(t.Context(), raw)
	if denied.Inventory != nil || calls.Load() != 1 {
		t.Fatal("expired authority retrieved cached inventory")
	}
}

func TestHikvisionSignedDiscoveryDeniedBeforeNetwork(t *testing.T) {
	cases := []struct {
		name   string
		change func(*Worker, *hikvisionDiscoveryGrant, *[]byte)
	}{
		{"unconfigured", func(w *Worker, _ *hikvisionDiscoveryGrant, _ *[]byte) { w.configured = false }},
		{"foreign_collector", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) {
			g.Collector = "55555555-5555-4555-8555-555555555555"
		}},
		{"foreign_site", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Site++ }},
		{"wrong_kind", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Type = "collector_cctv_live" }},
		{"zero_device", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Device = 0 }},
		{"oversized_id", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Scope = 9007199254740992 }},
		{"bad_request", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Request = "foreign" }},
		{"bad_context", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.ContextHash = strings.Repeat("A", 64) }},
		{"bad_credential_binding", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.CredentialBinding = "unknown" }},
		{"future", func(w *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) {
			g.Issued = w.now().Add(time.Second).Format("2006-01-02T15:04:05Z")
		}},
		{"expired", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Expires = g.Issued }},
		{"long_grant", func(w *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) {
			g.Expires = w.now().Add(16 * time.Second).Format("2006-01-02T15:04:05Z")
		}},
		{"dns_dial", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.IP = "camera.example.test" }},
		{"multicast", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.IP = "224.0.0.1" }},
		{"bad_tls_name", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.ServerName = "camera.test/foreign" }},
		{"bad_pin", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Pin = "missing" }},
		{"foreign_port", func(_ *Worker, g *hikvisionDiscoveryGrant, _ *[]byte) { g.Port++ }},
		{"hash_mismatch", func(_ *Worker, _ *hikvisionDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.ReplaceAll(string(*s), "fixture-isapi-secret", "changed-secret"))
		}},
		{"secret_control", func(_ *Worker, g *hikvisionDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.ReplaceAll(string(*s), "fixture-isapi-secret", `secret\n`))
			g.SourceHash = hash(*s)
		}},
		{"unexpected_url", func(_ *Worker, g *hikvisionDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.TrimSuffix(string(*s), "}") + `,"url":"https://foreign.test/"}`)
			g.SourceHash = hash(*s)
		}},
		{"duplicate_source", func(_ *Worker, g *hikvisionDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.TrimSuffix(string(*s), "}") + `,"allowed_port":443}`)
			g.SourceHash = hash(*s)
		}},
		{"null_password", func(_ *Worker, g *hikvisionDiscoveryGrant, s *[]byte) {
			*s = []byte(strings.ReplaceAll(string(*s), `"fixture-isapi-secret"`, `null`))
			g.SourceHash = hash(*s)
		}},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			w, g, source, key, calls := discoveryWorkerFixture(t)
			c.change(w, &g, &source)
			result := w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, g, source))
			if result.Status != "failed" || result.Inventory != nil || calls.Load() != 0 {
				t.Fatal("unsafe grant/source reached network")
			}
		})
	}
	t.Run("invalid_signature", func(t *testing.T) {
		w, g, s, _, calls := discoveryWorkerFixture(t)
		_, foreign, _ := ed25519.GenerateKey(rand.Reader)
		if w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, foreign, g, s)).Inventory != nil || calls.Load() != 0 {
			t.Fatal("foreign signature accepted")
		}
	})
	for _, mode := range []string{"unknown_payload", "duplicate_payload", "null_payload", "noncanonical_base64", "message_request", "duplicate_message", "extra_offer"} {
		t.Run(mode, func(t *testing.T) {
			w, g, s, key, calls := discoveryWorkerFixture(t)
			raw := signedDiscoveryWire(t, key, g, s)
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
			if w.DiscoverHikvision(t.Context(), raw).Inventory != nil || calls.Load() != 0 {
				t.Fatal("ambiguous message accepted")
			}
		})
	}
}

func TestHikvisionSignedDiscoveryFailureCapacityAndDeadline(t *testing.T) {
	t.Run("failed_replay", func(t *testing.T) {
		w, g, s, key, calls := discoveryWorkerFixture(t)
		w.discovery.run = func(context.Context, httpsTunnelSource) (hikvisionInventory, error) {
			calls.Add(1)
			return discoveryInventoryFixture(t), ErrProtocol
		}
		first := w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, g, s))
		again := w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, g, s))
		if first.Inventory != nil || first.Status != "failed" || !reflect.DeepEqual(first, again) || calls.Load() != 1 {
			t.Fatal("partial failure/retry changed")
		}
	})
	t.Run("capacity", func(t *testing.T) {
		w, g, s, key, calls := discoveryWorkerFixture(t)
		for i := 0; i < 256; i++ {
			w.discovery.receipts[fmt.Sprint(i)] = hikvisionDiscoveryReceipt{}
		}
		reply := w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, g, s))
		if reply.Reason == nil || *reply.Reason != "capacity_unavailable" || calls.Load() != 0 {
			t.Fatal("capacity silently evicted/reopened")
		}
	})
	for _, mode := range []string{"parent_cancel", "worker_close", "expiry_after_read"} {
		t.Run(mode, func(t *testing.T) {
			w, g, s, key, _ := discoveryWorkerFixture(t)
			ctx, cancel := context.WithCancel(t.Context())
			defer cancel()
			started := make(chan struct{})
			done := make(chan hikvisionDiscoveryReply, 1)
			w.discovery.run = func(run context.Context, _ httpsTunnelSource) (hikvisionInventory, error) {
				close(started)
				if mode == "expiry_after_read" {
					expiry, _ := instant(g.Expires)
					w.mu.Lock()
					w.now = func() time.Time { return expiry }
					w.mu.Unlock()
				} else {
					<-run.Done()
				}
				return discoveryInventoryFixture(t), nil
			}
			go func() { done <- w.DiscoverHikvision(ctx, signedDiscoveryWire(t, key, g, s)) }()
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
		w, g, s, key, _ := discoveryWorkerFixture(t)
		inventory := discoveryInventoryFixture(t)
		sample := inventory.Channels[0]
		sample.Name = strings.Repeat("x", 128)
		inventory.Channels = nil
		for i := 1; i <= 256; i++ {
			channel := sample
			channel.ID = fmt.Sprint(i)
			inventory.Channels = append(inventory.Channels, channel)
		}
		w.discovery.run = func(context.Context, httpsTunnelSource) (hikvisionInventory, error) { return inventory, nil }
		reply := w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, g, s))
		if reply.Inventory != nil || reply.Status != "failed" {
			t.Fatal("oversized inventory partially published")
		}
	})
}

func TestHikvisionSignedDiscoveryActualTLSResources(t *testing.T) {
	endpoint, roots, fixture := newHikvisionISAPIFixture(t, "sha256")
	w, g, _, key, _ := discoveryWorkerFixture(t)
	source := hikvisionDiscoverySource{IP: endpoint.IP, Port: endpoint.Port, ServerName: endpoint.ServerName, Pin: endpoint.Pin, Username: endpoint.Username, Password: endpoint.Password}
	rawSource, _ := json.Marshal(source)
	g.IP, g.Port, g.ServerName, g.Pin, g.SourceHash = source.IP, source.Port, source.ServerName, source.Pin, hash(rawSource)
	w.discovery.run = func(ctx context.Context, source httpsTunnelSource) (hikvisionInventory, error) {
		return discoverHikvision(ctx, source, roots)
	}
	reply := w.DiscoverHikvision(t.Context(), signedDiscoveryWire(t, key, g, rawSource))
	if reply.Status != "observed" || reply.Inventory == nil || reply.Inventory.Identity.Serial != "fixture-camera-serial" {
		t.Fatal("signed TLS/Digest inventory missing")
	}
	fixture.mu.Lock()
	defer fixture.mu.Unlock()
	if len(fixture.requests) != 2 || fixture.authorized["/ISAPI/System/deviceInfo"] != 1 || fixture.authorized["/ISAPI/Streaming/channels"] != 1 {
		t.Fatal("unexpected outbound scope")
	}
}

func TestHikvisionDiscoveryPrivateServeAndClosedReplies(t *testing.T) {
	w, g, s, key, _ := discoveryWorkerFixture(t)
	raw := signedDiscoveryWire(t, key, g, s)
	if _, err := decodeMessage(raw); err != nil {
		t.Fatal("supervisor rejected discovery frame")
	}
	reply := w.DiscoverHikvision(t.Context(), raw)
	encoded, _ := json.Marshal(reply)
	if validatePrivateWorkerReply(encoded) != nil {
		t.Fatal("supervisor rejected observation")
	}
	changes := []string{
		strings.ReplaceAll(string(encoded), `"firmware_build":"fixture-build"`, `"firmware_build":null`),
		strings.ReplaceAll(string(encoded), `"enabled":true`, `"enabled":null`),
		strings.ReplaceAll(string(encoded), `"name":"Entry camera"`, `"name":null`),
		strings.ReplaceAll(string(encoded), `"audio_enabled":false`, `"audio_enabled":"false"`),
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
	badSource := []byte(strings.ReplaceAll(string(s), "fixture-isapi-secret", `bad\nsecret`))
	g.SourceHash = hash(badSource)
	g.Issued = time.Now().UTC().Truncate(time.Second).Format("2006-01-02T15:04:05Z")
	g.Expires = time.Now().UTC().Truncate(time.Second).Add(10 * time.Second).Format("2006-01-02T15:04:05Z")
	input := signedDiscoveryWire(t, key, g, badSource)
	var framed any
	_ = json.Unmarshal(input, &framed)
	if WriteFrame(inboundW, framed) != nil {
		t.Fatal("discovery pipe")
	}
	response, err := ReadFrame(outboundR)
	if err != nil || validatePrivateWorkerReply(response) != nil {
		t.Fatal("Serve discovery reply", err)
	}
	var result hikvisionDiscoveryReply
	_ = json.Unmarshal(response, &result)
	if result.Operation != hikvisionDiscoveryOperation || result.Status != "failed" || result.Inventory != nil {
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

func TestHikvisionDiscoveryPHPProducerSealedLeaseAndConsumer(t *testing.T) {
	php, producer := os.Getenv("OBLI_DISCOVERY_PHP_EXECUTABLE"), os.Getenv("OBLI_DISCOVERY_PHP_PRODUCER")
	if php == "" || producer == "" {
		t.Skip("actual PHP producer paths required")
	}
	if !filepath.IsAbs(php) || !filepath.IsAbs(producer) {
		t.Fatal("absolute fixture paths required")
	}
	endpoint, roots, _ := newHikvisionISAPIFixture(t, "sha256")
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
	w.discovery.run = func(ctx context.Context, s httpsTunnelSource) (hikvisionInventory, error) {
		return discoverHikvision(ctx, s, roots)
	}
	if w.Handle(t.Context(), configure).Status != "requested" {
		t.Fatal("actual PHP key/config rejected")
	}
	reply := w.DiscoverHikvision(t.Context(), request)
	if reply.Status != "observed" || reply.Inventory == nil {
		t.Fatal("original PHP signed source rejected")
	}
	repeat := w.DiscoverHikvision(t.Context(), request)
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
	if path := os.Getenv("OBLI_DISCOVERY_PHP_EVIDENCE"); path != "" {
		evidence, _ := json.Marshal(map[string]any{"request_sha256": hash(request), "configure_sha256": hash(configure), "result": reply, "producer": producer, "synthetic": true, "hardware_acceptance": false})
		if os.WriteFile(path, evidence, 0600) != nil {
			t.Fatal("evidence write")
		}
	}
}

func TestHikvisionDiscoveryDoesNotBlockLiveLeaseWatchdog(t *testing.T) {
	w, live, message, key, media := fixture(t)
	defer w.Close()
	if w.Handle(t.Context(), wire(t, message)).Status != "ready" {
		t.Fatal("existing live fixture")
	}
	now := time.Now().UTC().Truncate(time.Second)
	source := []byte(`{"allowed_ip":"127.0.0.1","allowed_port":443,"tls_server_name":"camera.example.test","tls_pin":"` + strings.Repeat("a", 64) + `","username":"fixture-isapi-user","password":"fixture-isapi-secret"}`)
	g := hikvisionDiscoveryGrant{Version: 1, Type: "collector_hikvision_discovery", Collector: live.Collector, Site: live.Site, Device: 2, Scope: 3, Request: "88888888-8888-4888-8888-888888888888", ContextHash: strings.Repeat("b", 64), CredentialBinding: strings.Repeat("c", 64), SourceHash: hash(source), IP: "127.0.0.1", Port: 443, ServerName: "camera.example.test", Pin: strings.Repeat("a", 64), Issued: now.Format("2006-01-02T15:04:05Z"), Expires: now.Add(10 * time.Second).Format("2006-01-02T15:04:05Z")}
	started := make(chan struct{})
	w.discovery.run = func(ctx context.Context, _ httpsTunnelSource) (hikvisionInventory, error) {
		close(started)
		<-ctx.Done()
		return hikvisionInventory{}, ErrProtocol
	}
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	done := make(chan hikvisionDiscoveryReply, 1)
	raw := signedDiscoveryWire(t, key, g, source)
	go func() { done <- w.DiscoverHikvision(ctx, raw) }()
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
