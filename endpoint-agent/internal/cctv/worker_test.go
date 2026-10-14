package cctv

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"io"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

type fakeMedia struct{ closed atomic.Bool }

func (f *fakeMedia) Close() error { f.closed.Store(true); return nil }
func fixture(t *testing.T) (*Worker, Grant, Message, ed25519.PrivateKey, *fakeMedia) {
	t.Helper()
	pub, key, _ := ed25519.GenerateKey(rand.Reader)
	now := time.Now().UTC().Truncate(time.Second)
	source := json.RawMessage(`{"url":"rtsps://nvr.test:7441/private?enableSrtp","username":null,"password":null}`)
	turn := json.RawMessage(`{"urls":["turn:relay.test:3478?transport=udp"],"username":"user","credential":"secret","expires_at":"` + now.Add(time.Minute).Format("2006-01-02T15:04:05Z") + `","relay_cidrs":["127.0.0.0/8"],"relay_port_min":1,"relay_port_max":65535}`)
	g := Grant{Version: 1, Type: "collector_cctv_live", Collector: "11111111-1111-4111-8111-111111111111", Site: 1, Camera: 2, Recorder: 3, Provider: "unifi", Console: "console", ProviderCamera: "camera", Session: "22222222-2222-4222-8222-222222222222", Negotiation: "33333333-3333-4333-8333-333333333333", Generation: 7, Revision: 1, SourceHash: hash(source), TurnHash: hash(turn), OfferHash: hash([]byte("fixture-offer")), Quality: "low", Codec: "H264", Audio: false, Scope: 4, IP: "192.0.2.1", Port: 7441, ServerName: "nvr.test", Pin: strings.Repeat("a", 64), Issued: now.Format("2006-01-02T15:04:05Z"), Expires: now.Add(10 * time.Second).Format("2006-01-02T15:04:05Z"), SessionExpires: now.Add(time.Minute).Format("2006-01-02T15:04:05Z")}
	f := &fakeMedia{}
	w := New(func(context.Context, Grant, Source, Turn, Description) (Media, Description, string) {
		return f, Description{"answer", "fixture-answer"}, ""
	})
	w.key, w.collector, w.site, w.configured = pub, g.Collector, g.Site, true
	m := Message{Version: 1, Operation: "open", Request: "44444444-4444-4444-8444-444444444444", Source: source, Turn: turn, Offer: Description{"offer", "fixture-offer"}}
	m.Grant = sign(key, g)
	return w, g, m, key, f
}
func sign(key ed25519.PrivateKey, g Grant) Envelope {
	b, _ := json.Marshal(g)
	return Envelope{base64.StdEncoding.EncodeToString(b), base64.StdEncoding.EncodeToString(ed25519.Sign(key, b))}
}
func wire(t *testing.T, m Message) []byte {
	t.Helper()
	var v map[string]any
	switch m.Operation {
	case "open":
		v = map[string]any{"version": 1, "operation": "open", "request_uuid": m.Request, "grant": m.Grant, "source": m.Source, "turn": m.Turn, "offer": m.Offer}
	case "renew":
		v = map[string]any{"version": 1, "operation": "renew", "request_uuid": m.Request, "grant": m.Grant}
	case "close":
		v = map[string]any{"version": 1, "operation": "close", "request_uuid": m.Request, "session_uuid": m.Session, "generation": m.Generation}
	}
	b, _ := json.Marshal(v)
	return b
}
func TestSignedScopeAndReplay(t *testing.T) {
	w, g, m, key, f := fixture(t)
	defer w.Close()
	v := w.Handle(t.Context(), wire(t, m))
	if v.Status != "ready" || v.Generation != 7 {
		t.Fatal(v)
	}
	deadline := w.sessions[g.Session].deadline
	v = w.Handle(t.Context(), wire(t, m))
	if v.Status != "ready" || w.sessions[g.Session].deadline != deadline {
		t.Fatal("replay extended")
	}
	m.Operation = "renew"
	g.Generation++
	g.Expires = g.Issued
	m.Grant = sign(key, g)
	if w.Handle(t.Context(), wire(t, m)).Status != "failed" {
		t.Fatal("expired renewal")
	}
	m = Message{Version: 1, Operation: "close", Request: m.Request, Session: g.Session, Generation: 7}
	if w.Handle(t.Context(), wire(t, m)).Status != "stopped" || !f.closed.Load() {
		t.Fatal("close")
	}
	_, old, original, _, _ := fixture(t)
	original.Grant = sign(key, old)
	if w.Handle(t.Context(), wire(t, original)).Status != "stopped" {
		t.Fatal("tombstone resurrected")
	}
}
func TestSignedFailureBeforeDial(t *testing.T) {
	for _, name := range []string{"source", "signature", "null", "foreign"} {
		t.Run(name, func(t *testing.T) {
			w, g, m, key, _ := fixture(t)
			defer w.Close()
			switch name {
			case "source":
				m.Source = json.RawMessage(`{"url":"rtsps://other.test:7441/secret","username":null,"password":null}`)
				g.SourceHash = hash(m.Source)
				m.Grant = sign(key, g)
			case "signature":
				m.Grant.Signature = base64.StdEncoding.EncodeToString(make([]byte, 64))
			case "foreign":
				g.Site = 9
				m.Grant = sign(key, g)
			case "null":
				b, _ := json.Marshal(g)
				b = bytes.Replace(b, []byte(`"audio":false`), []byte(`"audio":null`), 1)
				m.Grant = Envelope{base64.StdEncoding.EncodeToString(b), base64.StdEncoding.EncodeToString(ed25519.Sign(key, b))}
			}
			if w.Handle(t.Context(), wire(t, m)).Status != "failed" {
				t.Fatal("accepted", name)
			}
		})
	}
}
func TestEOFStopsActiveMedia(t *testing.T) {
	w, _, m, _, _ := fixture(t)
	_ = w
	mBytes := wire(t, m)
	if Closed(mBytes, "version", "operation", "request_uuid", "grant", "source", "turn", "offer") != nil {
		t.Fatal("frame")
	}
	var framed bytes.Buffer
	_ = WriteFrame(&framed, map[string]any{"version": 1, "operation": "configure", "collector_uuid": m.Request, "site_id": 1, "server_public_key": base64.StdEncoding.EncodeToString(make([]byte, 32))})
	var out bytes.Buffer
	if Serve(t.Context(), &framed, &out, nil) != nil {
		t.Fatal("EOF")
	}
	if _, e := ReadFrame(bytes.NewReader([]byte{0, 1, 0, 1})); e != ErrProtocol {
		t.Fatal("unbounded frame", e)
	}
	if Closed([]byte(`{"x":1,"x":2}`), "x") == nil {
		t.Fatal("duplicate")
	}
	if _, e := ReadFrame(bytes.NewReader(nil)); e != io.EOF {
		t.Fatal(e)
	}
}
func TestLeaseWatchdogStopsWhilePacketsCouldFlow(t *testing.T) {
	w, g, m, key, f := fixture(t)
	defer w.Close()
	now := time.Now().UTC().Truncate(time.Second)
	g.Issued = now.Format("2006-01-02T15:04:05Z")
	g.Expires = now.Add(time.Second).Format("2006-01-02T15:04:05Z")
	m.Grant = sign(key, g)
	if w.Handle(t.Context(), wire(t, m)).Status != "ready" {
		t.Fatal("open")
	}
	deadline := time.Now().Add(2 * time.Second)
	for !f.closed.Load() && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if !f.closed.Load() {
		t.Fatal("lease continued")
	}
}

func TestHigherGenerationOpenReusesOnlyCurrentImmutablePeer(t *testing.T) {
	w, g, m, key, f := fixture(t)
	defer w.Close()
	var calls int
	w.factory = func(context.Context, Grant, Source, Turn, Description) (Media, Description, string) {
		calls++
		return f, Description{"answer", "fixture-answer"}, ""
	}
	now, _ := instant(g.Issued)
	w.now = func() time.Time { return now }
	first := w.Handle(t.Context(), wire(t, m))
	old := m
	g.Generation++
	g.Expires = now.Add(12 * time.Second).Format("2006-01-02T15:04:05Z")
	m.Grant = sign(key, g)
	for _, field := range []string{"source", "turn", "offer"} {
		bad := m
		switch field {
		case "source":
			bad.Source = json.RawMessage(`{"url":"rtsps://other.test:7441/private","username":null,"password":null}`)
		case "turn":
			bad.Turn = json.RawMessage(`{}`)
		case "offer":
			bad.Offer.SDP = "different"
		}
		if w.Handle(t.Context(), wire(t, bad)).Status != "failed" || w.sessions[g.Session].grant.Generation != g.Generation-1 {
			t.Fatal("tamper updated authority", field)
		}
	}
	got := w.Handle(t.Context(), wire(t, m))
	if got.Status != "ready" || got.Generation != g.Generation || got.Answer == nil || *got.Answer != *first.Answer || calls != 1 || f.closed.Load() {
		t.Fatal("peer reopened or answer changed", got, calls)
	}
	deadline := w.sessions[g.Session].deadline
	if w.Handle(t.Context(), wire(t, m)).Status != "ready" || w.sessions[g.Session].deadline != deadline {
		t.Fatal("replay extended")
	}
	if w.Handle(t.Context(), wire(t, old)).Status != "failed" {
		t.Fatal("old generation accepted")
	}
	now = deadline
	g.Generation++
	g.Issued = now.Format("2006-01-02T15:04:05Z")
	g.Expires = now.Add(time.Second).Format("2006-01-02T15:04:05Z")
	m.Grant = sign(key, g)
	if w.Handle(t.Context(), wire(t, m)).Status != "failed" || calls != 1 {
		t.Fatal("expired peer revived")
	}
	w.mu.Lock()
	w.closeLocked(w.sessions[g.Session], "stopped", "stopped")
	w.mu.Unlock()
	if w.Handle(t.Context(), wire(t, m)).Status != "failed" || calls != 1 {
		t.Fatal("tombstone revived")
	}
}
