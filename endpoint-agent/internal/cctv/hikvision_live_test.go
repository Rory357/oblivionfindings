package cctv

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func hikLiveMap(t *testing.T, source json.RawMessage, turn json.RawMessage, offer string) map[string]any {
	t.Helper()
	inventory := discoveryInventoryFixture(t)
	var private hikvisionDiscoverySource
	if json.Unmarshal(source, &private) != nil {
		t.Fatal("source fixture")
	}
	now := time.Now().UTC().Truncate(time.Second)
	return map[string]any{
		"version": 1, "type": hikvisionLiveType, "capability": hikvisionLiveCapability,
		"collector_uuid": "11111111-1111-4111-8111-111111111111", "site_id": 1, "camera_id": 2, "recorder_device_id": nil,
		"provider": "hikvision", "source_kind": "direct_camera", "source_id": 4, "source_revision": 1,
		"source_fingerprint": strings.Repeat("a", 64), "binding_sha256": strings.Repeat("b", 64),
		"credential_binding_sha256": strings.Repeat("c", 64), "support_sha256": strings.Repeat("d", 64),
		"discovery_result_sha256": strings.Repeat("e", 64), "identity": inventory.Identity, "channel": inventory.Channels[0],
		"transport_profile": hikvisionLiveProfile, "session_uuid": "22222222-2222-4222-8222-222222222222",
		"negotiation_uuid": "33333333-3333-4333-8333-333333333333", "generation": 1,
		"source_sha256": hash(source), "turn_sha256": hash(turn), "offer_sha256": hash([]byte(offer)),
		"quality": "selected_channel", "video_codec": "H264", "audio": false, "discovery_scope_id": 3,
		"allowed_ip": private.IP, "allowed_port": private.Port, "tls_server_name": private.ServerName, "tls_pin": private.Pin,
		"issued_at": now.Format("2006-01-02T15:04:05Z"), "expires_at": now.Add(10 * time.Second).Format("2006-01-02T15:04:05Z"),
		"session_expires_at": now.Add(time.Minute).Format("2006-01-02T15:04:05Z"),
	}
}
func signHikLive(key ed25519.PrivateKey, fields map[string]any) Envelope {
	raw, _ := json.Marshal(fields)
	return Envelope{Payload: base64.StdEncoding.EncodeToString(raw), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, raw))}
}
func hikLiveWorkerFixture(t *testing.T) (*Worker, map[string]any, Message, ed25519.PrivateKey, *fakeMedia, *atomic.Int32) {
	t.Helper()
	w, _, m, key, media := fixture(t)
	m.Source = json.RawMessage(`{"allowed_ip":"127.0.0.1","allowed_port":443,"tls_server_name":"camera.example.test","tls_pin":"` + strings.Repeat("a", 64) + `","username":"fixture-reader-auth","password":"fixture-secret"}`)
	g := hikLiveMap(t, m.Source, m.Turn, m.Offer.SDP)
	m.Grant = signHikLive(key, g)
	calls := &atomic.Int32{}
	w.factory = func(context.Context, Grant, Source, Turn, Description) (Media, Description, string) {
		calls.Add(1)
		return media, Description{"answer", "fixture-answer"}, ""
	}
	return w, g, m, key, media, calls
}
func TestHikvisionLiveDistinctAuthorityAndPrivateSource(t *testing.T) {
	for _, name := range []string{"type", "capability", "site", "provider", "kind", "recorder", "source_id", "profile", "quality", "codec", "audio", "endpoint", "pin", "source", "credential", "unknown", "null", "generation", "future", "expired", "absolute", "identity_null", "channel_disabled", "channel_codec", "channel_input", "channel_audio", "duplicate"} {
		t.Run(name, func(t *testing.T) {
			w, g, m, key, _, calls := hikLiveWorkerFixture(t)
			defer w.Close()
			switch name {
			case "type":
				g["type"] = "collector_hikvision_discovery"
			case "capability":
				g["capability"] = "cctv:hikvision:discovery:read_only"
			case "site":
				g["site_id"] = 9
			case "provider":
				g["provider"] = "unifi"
			case "kind":
				g["source_kind"] = "recorder_camera"
			case "recorder":
				g["recorder_device_id"] = 3
			case "source_id":
				g["source_id"] = 0
			case "profile":
				g["transport_profile"] = "rtsp_plain"
			case "quality":
				g["quality"] = "low"
			case "codec":
				g["video_codec"] = "H265"
			case "audio":
				g["audio"] = true
			case "endpoint":
				g["allowed_ip"] = "localhost"
			case "pin":
				g["tls_pin"] = strings.Repeat("z", 64)
			case "source":
				m.Source = json.RawMessage(`{"url":"rtsp://camera.example.test:443/Streaming/channels/102","username":"fixture-reader-auth","password":"fixture-secret"}`)
				g["source_sha256"] = hash(m.Source)
			case "credential":
				m.Source = json.RawMessage(strings.Replace(string(m.Source), "fixture-secret", "bad\\npassword", 1))
				g["source_sha256"] = hash(m.Source)
			case "unknown":
				g["fallback_url"] = "rtsp://foreign.test/steal"
			case "null":
				g["audio"] = nil
			case "generation":
				g["generation"] = 9007199254740992
			case "future":
				g["issued_at"] = time.Now().Add(time.Hour).UTC().Format("2006-01-02T15:04:05Z")
			case "expired":
				g["expires_at"] = g["issued_at"]
			case "absolute":
				g["session_expires_at"] = time.Now().Add(2 * time.Hour).UTC().Format("2006-01-02T15:04:05Z")
			case "identity_null":
				g["identity"] = nil
			case "channel_disabled", "channel_codec", "channel_input", "channel_audio":
				raw, _ := json.Marshal(g["channel"])
				var c map[string]any
				_ = json.Unmarshal(raw, &c)
				switch name {
				case "channel_disabled":
					c["enabled"] = false
				case "channel_codec":
					c["codec"] = "H.265"
				case "channel_input":
					c["input_id"] = "01"
				case "channel_audio":
					c["audio_enabled"] = "false"
				}
				g["channel"] = c
			}
			m.Grant = signHikLive(key, g)
			if name == "duplicate" {
				raw, _ := base64.StdEncoding.DecodeString(m.Grant.Payload)
				raw = append([]byte(`{"audio":false,`), raw[1:]...)
				m.Grant = Envelope{base64.StdEncoding.EncodeToString(raw), base64.StdEncoding.EncodeToString(ed25519.Sign(key, raw))}
			}
			if got := w.Handle(t.Context(), wire(t, m)); got.Status != "failed" || calls.Load() != 0 {
				t.Fatalf("unsafe grant/source reached factory: %s", name)
			}
		})
	}
}
func TestHikvisionLivePeerReplayRenewAndRetainedStop(t *testing.T) {
	w, g, m, key, media, calls := hikLiveWorkerFixture(t)
	defer w.Close()
	first := w.Handle(t.Context(), wire(t, m))
	if first.Status != "ready" || calls.Load() != 1 {
		t.Fatal("valid peer not opened")
	}
	deadline := w.sessions[first.Session].deadline
	if replay := w.Handle(t.Context(), wire(t, m)); replay.Status != "ready" || w.sessions[first.Session].deadline != deadline || calls.Load() != 1 {
		t.Fatal("replay reopened/extended peer")
	}
	for _, field := range []string{"source_id", "source_fingerprint", "binding_sha256", "credential_binding_sha256", "support_sha256", "discovery_result_sha256", "source_revision", "negotiation_uuid", "source_sha256", "turn_sha256", "offer_sha256", "session_expires_at", "identity", "channel"} {
		t.Run(field, func(t *testing.T) {
			changed := map[string]any{}
			for k, v := range g {
				changed[k] = v
			}
			changed["generation"] = 2
			switch field {
			case "source_id", "source_revision":
				changed[field] = 8
			case "negotiation_uuid":
				changed[field] = "55555555-5555-4555-8555-555555555555"
			case "session_expires_at":
				changed[field] = time.Now().Add(50 * time.Second).UTC().Format("2006-01-02T15:04:05Z")
			case "identity":
				id := changed[field].(hikvisionIdentity)
				id.Firmware = "changed"
				changed[field] = id
			case "channel":
				c := changed[field].(hikvisionChannel)
				c.ID = "101"
				changed[field] = c
			default:
				changed[field] = strings.Repeat("f", 64)
			}
			renew := Message{Version: 1, Operation: "renew", Request: m.Request, Grant: signHikLive(key, changed)}
			if got := w.Handle(t.Context(), wire(t, renew)); got.Status != "failed" || w.sessions[first.Session].deadline != deadline || calls.Load() != 1 {
				t.Fatal("changed peer renewed")
			}
		})
	}
	g["generation"] = 2
	g["expires_at"] = time.Now().UTC().Truncate(time.Second).Add(12 * time.Second).Format("2006-01-02T15:04:05Z")
	m.Operation = "renew"
	m.Grant = signHikLive(key, g)
	if got := w.Handle(t.Context(), wire(t, m)); got.Status != "ready" || got.Generation != 2 || calls.Load() != 1 {
		t.Fatal("unchanged renewal unavailable")
	}
	stop := Message{Version: 1, Operation: "close", Request: m.Request, Session: first.Session, Generation: 2}
	if w.Handle(t.Context(), wire(t, stop)).Status != "stopped" || !media.closed.Load() {
		t.Fatal("stop did not close")
	}
	m.Operation = "open"
	m.Grant = signHikLive(key, g)
	if got := w.Handle(t.Context(), wire(t, m)); got.Status != "stopped" || calls.Load() != 1 {
		t.Fatal("terminal peer reopened")
	}
}
func TestHikvisionLiveExactControlTargetAndFreshInventory(t *testing.T) {
	selected, _ := base.ParseURL("rtsp://fixture-reader-auth:fixture-secret@camera.example.test:443/Streaming/channels/102")
	for _, target := range []string{"rtsp://camera.example.test:443/Streaming/channels/102/trackID=0", "rtsp://camera.example.test:443/Streaming/channels/102/trackID=123"} {
		u, _ := base.ParseURL(target)
		if !hikvisionControlTarget(u, selected, true) {
			t.Fatal("selected control denied")
		}
	}
	for _, target := range []string{"rtsp://foreign.test:443/Streaming/channels/102/trackID=0", "rtsp://camera.example.test:443/Streaming/channels/101/trackID=0", "rtsp://camera.example.test:443/Streaming/channels/102/trackID=0?redirect=1", "rtsp://other:secret@camera.example.test:443/Streaming/channels/102/trackID=0", "rtsp://camera.example.test:443/Streaming/channels/102/../101/trackID=0", "rtsp://camera.example.test:443/Streaming/channels/102/%74rackID=0", "rtsp://camera.example.test:443/Streaming/channels/102/trackID=1000"} {
		u, _ := base.ParseURL(target)
		if hikvisionControlTarget(u, selected, true) {
			t.Fatal("foreign/ambiguous control accepted")
		}
	}
	w, _, m, _, _, _ := hikLiveWorkerFixture(t)
	defer w.Close()
	g, e := verify(m.Grant, w.key, w.collector, w.site, time.Now())
	if e != nil {
		t.Fatal(e)
	}
	inventory := discoveryInventoryFixture(t)
	if !hikvisionInventoryMatches(inventory, g.Hikvision) {
		t.Fatal("original inventory lost")
	}
	inventory.Identity.Serial = "replacement"
	if hikvisionInventoryMatches(inventory, g.Hikvision) {
		t.Fatal("replacement identity allowed")
	}
	inventory = discoveryInventoryFixture(t)
	inventory.Channels[0].ID = "101"
	if hikvisionInventoryMatches(inventory, g.Hikvision) {
		t.Fatal("unselected channel allowed")
	}
	inventory = discoveryInventoryFixture(t)
	inventory.Channels[0].AudioEnabled = nil
	if hikvisionInventoryMatches(inventory, g.Hikvision) {
		t.Fatal("unknown audio fabricated as disabled")
	}
}

func TestHikvisionLiveRTSPDigestCannotFallBack(t *testing.T) {
	good := `Digest realm="fixture",nonce="fixture-nonce",algorithm=SHA-256`
	for _, bad := range []string{
		`Basic realm="fixture"`,
		`Digest realm="fixture",nonce="fixture",nonce="other",algorithm=MD5`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-512-256`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-256,qop="auth-int"`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-256,userhash=true`,
		`Digest realm="",nonce="fixture",algorithm=SHA-256`,
	} {
		if _, e := selectHikvisionRTSPDigest(base.HeaderValue{bad}); e == nil {
			t.Fatal("unsafe RTSP challenge accepted")
		}
	}
	if _, e := selectHikvisionRTSPDigest(base.HeaderValue{good, good, good, good, good}); e == nil {
		t.Fatal("challenge list unbounded")
	}
	selected, e := selectHikvisionRTSPDigest(base.HeaderValue{`Basic realm="fixture"`, `Digest realm="fixture",nonce="fixture",algorithm=MD5`, good})
	if e != nil || len(selected) != 1 || selected[0] != good {
		t.Fatal("strongest explicit Digest not selected")
	}
}
func TestHikvisionLiveLeaseAndStopDuringBlockedSource(t *testing.T) {
	for _, operation := range []string{"expiry", "stop"} {
		t.Run(operation, func(t *testing.T) {
			w, g, m, key, media, _ := hikLiveWorkerFixture(t)
			defer w.Close()
			g["expires_at"] = time.Now().UTC().Truncate(time.Second).Add(2 * time.Second).Format("2006-01-02T15:04:05Z")
			m.Grant = signHikLive(key, g)
			started := make(chan struct{})
			done := make(chan Reply, 1)
			w.factory = func(ctx context.Context, _ Grant, _ Source, _ Turn, _ Description) (Media, Description, string) {
				close(started)
				<-ctx.Done()
				return media, Description{"answer", "late-answer"}, ""
			}
			go func() { done <- w.Handle(t.Context(), wire(t, m)) }()
			select {
			case <-started:
			case <-time.After(time.Second):
				t.Fatal("source did not start")
			}
			expected := "expired"
			if operation == "stop" {
				expected = "stopped"
				stop := Message{Version: 1, Operation: "close", Request: m.Request, Session: g["session_uuid"].(string), Generation: 1}
				if got := w.Handle(t.Context(), wire(t, stop)); got.Status != "stopped" {
					t.Fatal("stop blocked on source")
				}
			}
			select {
			case got := <-done:
				if got.Status != expected || got.Answer != nil || !media.closed.Load() {
					t.Fatal("late source escaped stopped/expired lease")
				}
			case <-time.After(3 * time.Second):
				t.Fatal("authority expiry did not cancel blocked source")
			}
		})
	}
}
