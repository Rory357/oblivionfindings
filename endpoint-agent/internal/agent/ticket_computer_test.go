package agent

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"strings"
	"testing"
	"time"
)

type ticketComputerRoundTrip func(*http.Request) (*http.Response, error)

func (f ticketComputerRoundTrip) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func ticketComputerEnvelope(t *testing.T, key ed25519.PrivateKey, ack ticketComputerAck) Envelope {
	t.Helper()
	data, e := json.Marshal(ack)
	if e != nil {
		t.Fatal(e)
	}
	return Envelope{Payload: base64.StdEncoding.EncodeToString(data), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, data))}
}
func TestTicketComputerSignedBinding(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	now := time.Unix(1800000000, 0)
	challenge := strings.Repeat("a", 64)
	sum := sha256.Sum256([]byte(challenge))
	ack := ticketComputerAck{Type: "obli_ticket_computer_context", ChallengeHash: hex.EncodeToString(sum[:]), AgentUUID: "11111111-1111-4111-8111-111111111111", Status: "confirmed", IssuedAt: now.Unix(), ExpiresAt: now.Unix() + 30}
	if verifyTicketComputerAck(ticketComputerEnvelope(t, priv, ack), pub, ack.AgentUUID, challenge, now) != nil {
		t.Fatal("valid proof rejected")
	}
	for name, edit := range map[string]func(*ticketComputerAck){"type": func(a *ticketComputerAck) { a.Type = "remote_lease" }, "agent": func(a *ticketComputerAck) { a.AgentUUID = "22222222-2222-4222-8222-222222222222" }, "challenge": func(a *ticketComputerAck) { a.ChallengeHash = strings.Repeat("b", 64) }, "status": func(a *ticketComputerAck) { a.Status = "pending" }, "future": func(a *ticketComputerAck) { a.IssuedAt += 6 }, "expired": func(a *ticketComputerAck) { a.ExpiresAt = now.Unix() }, "lifetime": func(a *ticketComputerAck) { a.ExpiresAt++ }} {
		t.Run(name, func(t *testing.T) {
			bad := ack
			edit(&bad)
			if verifyTicketComputerAck(ticketComputerEnvelope(t, priv, bad), pub, ack.AgentUUID, challenge, now) == nil {
				t.Fatal("unbound proof accepted")
			}
		})
	}
	env := ticketComputerEnvelope(t, priv, ack)
	env.Signature = base64.StdEncoding.EncodeToString(make([]byte, 64))
	if verifyTicketComputerAck(env, pub, ack.AgentUUID, challenge, now) == nil {
		t.Fatal("bad signature")
	}
}
func TestTicketComputerOriginBeforeTransmission(t *testing.T) {
	pub, _, _ := ed25519.GenerateKey(rand.Reader)
	c, e := NewClient("https://CARE.example.test:443/", base64.StdEncoding.EncodeToString(pub))
	if e != nil {
		t.Fatal(e)
	}
	calls := 0
	c.http.Transport = ticketComputerRoundTrip(func(r *http.Request) (*http.Response, error) {
		calls++
		return &http.Response{StatusCode: 500, Body: io.NopCloser(strings.NewReader(""))}, nil
	})
	hash, _ := CanonicalOriginHash("https://other.example.test")
	if c.confirmTicketComputer(context.Background(), strings.Repeat("c", 64), "11111111-1111-4111-8111-111111111111", strings.Repeat("a", 64), hash) == nil || calls != 0 {
		t.Fatal("challenge sent to wrong origin")
	}
	hash, _ = CanonicalOriginHash("https://care.example.test")
	_ = c.confirmTicketComputer(context.Background(), strings.Repeat("c", 64), "11111111-1111-4111-8111-111111111111", strings.Repeat("a", 64), hash)
	if calls != 1 {
		t.Fatal("same origin not sent")
	}
	for _, pair := range [][2]string{{"https://care.example.test:443/", "https://CARE.example.test"}, {"https://[2001:0db8::1]:443", "https://[2001:db8::1]"}} {
		a, e := CanonicalOriginHash(pair[0])
		b, f := CanonicalOriginHash(pair[1])
		if e != nil || f != nil || a != b {
			t.Fatal("canonical origins differ", pair)
		}
	}
}

func TestTicketComputerConcurrencyAndRelease(t *testing.T) {
	pub, _, _ := ed25519.GenerateKey(rand.Reader)
	c, e := NewClient("https://care.example.test", base64.StdEncoding.EncodeToString(pub))
	if e != nil {
		t.Fatal(e)
	}
	entered := make(chan struct{}, 2)
	release := make(chan struct{})
	c.http.Transport = ticketComputerRoundTrip(func(r *http.Request) (*http.Response, error) {
		entered <- struct{}{}
		select {
		case <-release:
		case <-r.Context().Done():
		}
		return &http.Response{StatusCode: 500, Body: io.NopCloser(strings.NewReader(""))}, nil
	})
	m := NewStatusMonitor("test")
	unbind := m.bindTicketComputer(context.Background(), c, "11111111-1111-4111-8111-111111111111", strings.Repeat("c", 64))
	hash, _ := CanonicalOriginHash(c.base)
	done := make(chan struct{}, 2)
	for i := 0; i < 2; i++ {
		go func() { _ = m.TicketComputer(strings.Repeat("a", 64), hash); done <- struct{}{} }()
	}
	for i := 0; i < 2; i++ {
		select {
		case <-entered:
		case <-time.After(time.Second):
			t.Fatal("proof did not start")
		}
	}
	if m.TicketComputer(strings.Repeat("a", 64), hash).Status != "unavailable" {
		t.Fatal("concurrency bound missing")
	}
	snapshot := make(chan struct{})
	go func() { _ = m.Snapshot(); close(snapshot) }()
	select {
	case <-snapshot:
	case <-time.After(time.Second):
		t.Fatal("network held monitor lock")
	}
	unbind()
	if m.TicketComputer(strings.Repeat("a", 64), hash).Status != "unavailable" {
		t.Fatal("ended service retained proof")
	}
	close(release)
	for i := 0; i < 2; i++ {
		<-done
	}
}

func TestTicketComputerHTTPContract(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	c, e := NewClient("https://care.example.test", base64.StdEncoding.EncodeToString(pub))
	if e != nil {
		t.Fatal(e)
	}
	challenge := strings.Repeat("a", 64)
	credential := strings.Repeat("c", 64)
	agentUUID := "11111111-1111-4111-8111-111111111111"
	hash, _ := CanonicalOriginHash(c.base)
	sum := sha256.Sum256([]byte(challenge))
	now := time.Now().Unix()
	env := ticketComputerEnvelope(t, priv, ticketComputerAck{Type: "obli_ticket_computer_context", ChallengeHash: hex.EncodeToString(sum[:]), AgentUUID: agentUUID, Status: "confirmed", IssuedAt: now, ExpiresAt: now + 30})
	data, _ := json.Marshal(env)
	c.http.Transport = ticketComputerRoundTrip(func(r *http.Request) (*http.Response, error) {
		if r.Method != "POST" || r.URL.String() != "https://care.example.test/api/endpoints/agent/obli/ticket-computer-context" || r.Header.Get("Authorization") != "Bearer "+credential {
			t.Fatal("endpoint authority/route changed")
		}
		body, _ := io.ReadAll(r.Body)
		var fields map[string]string
		if json.Unmarshal(body, &fields) != nil || len(fields) != 1 || fields["challenge"] != challenge {
			t.Fatal("private staff intent reached agent request")
		}
		if deadline, ok := r.Context().Deadline(); !ok || time.Until(deadline) > 3*time.Second {
			t.Fatal("proof deadline missing")
		}
		return &http.Response{StatusCode: 200, Body: io.NopCloser(bytes.NewReader(data))}, nil
	})
	if c.confirmTicketComputer(context.Background(), credential, agentUUID, challenge, hash) != nil {
		t.Fatal("valid HTTP proof rejected")
	}
	data = []byte(`{"payload":"` + env.Payload + `","signature":"` + env.Signature + `","signature":"` + env.Signature + `"}`)
	if c.confirmTicketComputer(context.Background(), credential, agentUUID, challenge, hash) == nil {
		t.Fatal("duplicate envelope accepted")
	}
	data = make([]byte, 2049)
	if c.confirmTicketComputer(context.Background(), credential, agentUUID, challenge, hash) == nil {
		t.Fatal("oversized envelope accepted")
	}
}

func TestTicketComputerCrossLanguageOriginVectors(t *testing.T) {
	vectors := [][2]string{
		{"https://CARE.example.test:443/", "66df9086fbf476a6682b5c64b8262d40bb3ae707173fb1cecb8696262c2eee4c"},
		{"https://care.example.test:8443", "26d4f5e46890c3da01549551a20137e45648032f13c54613f9db32eef65db6fb"},
		{"https://[2001:0db8::1]:443", "860ce09858baa550b66ed4e109262effbdcafd53f2f4a4bdb7d7e0f9de58377d"},
		{"https://[2001:db8:0:0:0:0:0:1]", "860ce09858baa550b66ed4e109262effbdcafd53f2f4a4bdb7d7e0f9de58377d"},
		{"https://[::ffff:192.0.2.1]", "e8ea0fd14ab4f3ad6793631ef09d5674320d4f9e8261f3430c6ea5a12eff97dd"},
		{"https://[::ffff:c000:201]:443/", "e8ea0fd14ab4f3ad6793631ef09d5674320d4f9e8261f3430c6ea5a12eff97dd"},
		{"https://[::192.0.2.1]", "3b0518bf9668466e58152daee95ab851d5409855c9c3e118a6bf696a30974a22"},
	}
	for _, v := range vectors {
		actual, e := CanonicalOriginHash(v[0])
		if e != nil || actual != v[1] {
			t.Fatal("origin differs from native vector", v[0], e)
		}
	}
}
