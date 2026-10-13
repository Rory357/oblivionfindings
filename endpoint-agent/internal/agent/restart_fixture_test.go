package agent

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"
)

func TestRestartPHPFixture(t *testing.T) {
	path := os.Getenv("OBLI_RESTART_EXPORT")
	if path == "" {
		t.Skip("isolated PHP restart fixture not supplied")
	}
	data, e := os.ReadFile(path)
	if e != nil {
		t.Fatal(e)
	}
	var f struct {
		Command          Envelope        `json:"command"`
		Claim            Envelope        `json:"claim"`
		Cancellation     *Envelope       `json:"cancellation"`
		PublicKey        string          `json:"public_key"`
		AgentUUID        string          `json:"agent_uuid"`
		PayloadHash      string          `json:"payload_hash"`
		ClaimRequest     json.RawMessage `json:"claim_request"`
		Result           RestartResult   `json:"result"`
		HeartbeatPayload json.RawMessage `json:"heartbeat_payload"`
		HeartbeatACK     Acknowledgement `json:"heartbeat_ack"`
	}
	if json.Unmarshal(data, &f) != nil {
		t.Fatal("invalid fixture")
	}
	key, e := base64.StdEncoding.Strict().DecodeString(f.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	raw, e := base64.StdEncoding.Strict().DecodeString(f.Claim.Payload)
	if e != nil {
		t.Fatal(e)
	}
	var claim RestartClaim
	if json.Unmarshal(raw, &claim) != nil {
		t.Fatal("claim shape")
	}
	var c RestartCommand
	raw, e = base64.StdEncoding.Strict().DecodeString(f.Command.Payload)
	if e != nil || json.Unmarshal(raw, &c) != nil {
		t.Fatal("command shape")
	}
	at, e := restartTime(c.Parameters.ExecuteAt)
	if e != nil {
		t.Fatal(e)
	}
	now := at.Add(-time.Duration(c.Parameters.WarningSeconds) * time.Second)
	if claim.WarningStartedAt != nil {
		now, e = restartTime(*claim.WarningStartedAt)
		if e != nil {
			t.Fatal(e)
		}
	}
	mono := now
	if _, e = verifyRestartCommand(f.Command, ed25519.PublicKey(key), f.AgentUUID, now); e != nil || serviceHash(f.Command) != f.PayloadHash {
		t.Fatal("PHP signed schedule rejected", e)
	}
	desktop, nonce, warning := "", "", ""
	if claim.DesktopSession != nil {
		desktop = *claim.DesktopSession
	}
	if claim.ConsentNonce != nil {
		nonce = *claim.ConsentNonce
	}
	if claim.WarningStartedAt != nil {
		warning = *claim.WarningStartedAt
	}
	if _, e = verifyRestartClaim(f.Claim, key, c, f.PayloadHash, claim.BootID, desktop, nonce, warning, at); e != nil {
		t.Fatal("PHP signed claim rejected", e)
	}
	if f.Cancellation != nil && !verifyRestartCancellation(*f.Cancellation, key, c, f.PayloadHash, at) {
		t.Fatal("PHP signed cancellation rejected")
	}
	claims, results, heartbeats := 0, 0, 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Error("wrong credential scope")
		}
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/api/endpoints/agent/restarts":
			_ = json.NewEncoder(w).Encode(restartPoll{Command: &f.Command})
		case "/api/endpoints/agent/restarts/" + c.AttemptUUID + "/claim":
			claims++
			var got, want map[string]any
			_ = json.NewDecoder(r.Body).Decode(&got)
			_ = json.Unmarshal(f.ClaimRequest, &want)
			g, _ := json.Marshal(got)
			v, _ := json.Marshal(want)
			if string(g) != string(v) {
				t.Error("native claim differs from immutable PHP fixture")
			}
			_ = json.NewEncoder(w).Encode(f.Claim)
		case "/api/endpoints/agent/restarts/" + c.AttemptUUID + "/result":
			results++
			var got RestartResult
			if json.NewDecoder(r.Body).Decode(&got) != nil || got != f.Result {
				t.Error("native result differs from PHP receipt")
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"command_uuid": c.CommandUUID, "attempt_uuid": c.AttemptUUID, "payload_hash": f.PayloadHash, "accepted": true})
		case "/api/endpoints/agent/heartbeat":
			heartbeats++
			var got json.RawMessage
			if json.NewDecoder(r.Body).Decode(&got) != nil || validateRestartACK(got, f.HeartbeatACK.RestartAccepted) != nil {
				t.Error("PHP boot acceptance differs from sent observation")
			}
			_ = json.NewEncoder(w).Encode(f.HeartbeatACK)
		default:
			t.Error("arbitrary route")
			w.WriteHeader(404)
		}
	}))
	defer server.Close()
	client, e := NewClient(server.URL, f.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	client.http = server.Client()
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	defer store.Close()
	journal, e := loadRestartJournal(store, f.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	executor := &fakeRestartExecutor{}
	r := newRestartController(func(RemotePeer) bool { return true }, executor)
	r.journal = journal
	r.agent = f.AgentUUID
	r.origin = "https://localhost"
	r.key = key
	r.ready = true
	r.boot = claim.BootID
	r.bootAt = now
	r.clock = func() time.Time { return now }
	r.monotonic = func() time.Time { return mono }
	r.connected = func() bool { return true }
	r.transport = restartHTTP{client, strings.Repeat("a", 64)}
	r.tick(t.Context())
	peer := RemotePeer{"S-user", "S-logon", 1}
	if c.Policy.ConsentMode == "attended" {
		r.peer = peer
		r.desktop = desktop
		r.nonce = nonce
		origin, _ := CanonicalOriginHash(r.origin)
		requested, err := r.Handle(peer, RestartDecision{Operation: "restart_poll", OriginHash: origin})
		if err != nil {
			t.Fatal(err)
		}
		writeRestartFixtureView(t, path+".localview.json.requested.json", requested)
		restartDecision(t, r, "restart_visible", "visible")
		countdown := restartDecision(t, r, "restart_decide", "allow")
		writeRestartFixtureView(t, path+".localview.json.countdown.json", countdown)
	}
	for now.Before(at) {
		if c.Policy.ConsentMode == "attended" {
			restartDecision(t, r, "restart_visible", "visible")
		}
		now = now.Add(time.Second)
		mono = mono.Add(time.Second)
		r.tick(t.Context())
	}
	r.tick(t.Context())
	if claims != 1 || results != 1 || executor.calls != 1 || r.active.Result.Status != "awaiting_boot" {
		t.Fatal("actual signed fixture failed once-only flow")
	}
	r.mu.Lock()
	view := r.viewLocked()
	r.mu.Unlock()
	bytes, _ := json.Marshal(view)
	if os.WriteFile(path+".localview.json", bytes, 0600) != nil {
		t.Fatal("localview export")
	}
	if len(f.HeartbeatPayload) > 0 {
		var h struct {
			BootID       string               `json:"boot_id"`
			ObservedAt   string               `json:"observed_at"`
			Sequence     uint64               `json:"sequence"`
			Observations []RestartObservation `json:"restart_observations"`
		}
		if json.Unmarshal(f.HeartbeatPayload, &h) != nil {
			t.Fatal("heartbeat fixture")
		}
		measured := r.heartbeat(h.BootID, h.ObservedAt)
		if len(measured) != 1 || len(h.Observations) != 1 || measured[0] != h.Observations[0] {
			t.Fatal("changed boot projection differs")
		}
		ack, e := client.Heartbeat(t.Context(), State{AgentUUID: f.AgentUUID, Credential: strings.Repeat("a", 64), Sequence: h.Sequence}, f.HeartbeatPayload)
		if e != nil || len(ack.RestartAccepted) != 1 {
			t.Fatal("actual PHP boot ACK rejected", e)
		}
		r.acknowledge(f.HeartbeatPayload, ack.RestartAccepted)
		r.mu.Lock()
		confirmed := r.viewLocked()
		r.mu.Unlock()
		writeRestartFixtureView(t, path+".confirmed.localview.json", confirmed)
		if r.active.BootStatus != "succeeded" || heartbeats != 1 {
			t.Fatal("boot result not durable")
		}
	}
	r.tick(t.Context())
	if executor.calls != 1 || claims != 1 {
		t.Fatal("restart repeated after boot evidence")
	}
}

func writeRestartFixtureView(t *testing.T, path string, v RestartView) {
	t.Helper()
	b, e := json.Marshal(v)
	if e != nil || os.WriteFile(path, b, 0600) != nil {
		t.Fatal("cannot export actual local view")
	}
}
func TestRestartPHPCancellationFixture(t *testing.T) {
	path := os.Getenv("OBLI_RESTART_EXPORT")
	if path == "" {
		t.Skip("isolated PHP restart fixture not supplied")
	}
	data, e := os.ReadFile(path + ".cancel")
	if e != nil {
		t.Fatal(e)
	}
	var f struct {
		Command      Envelope        `json:"command"`
		Cancellation Envelope        `json:"cancellation"`
		PublicKey    string          `json:"public_key"`
		AgentUUID    string          `json:"agent_uuid"`
		PayloadHash  string          `json:"payload_hash"`
		BootID       string          `json:"boot_id"`
		Result       RestartResult   `json:"result"`
		ACK          json.RawMessage `json:"result_ack"`
	}
	if json.Unmarshal(data, &f) != nil {
		t.Fatal("invalid cancellation fixture")
	}
	key, e := base64.StdEncoding.Strict().DecodeString(f.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	raw, e := base64.StdEncoding.Strict().DecodeString(f.Cancellation.Payload)
	var cancellation RestartCancellation
	if e != nil || json.Unmarshal(raw, &cancellation) != nil {
		t.Fatal("cancellation shape")
	}
	now, e := restartTime(cancellation.IssuedAt)
	if e != nil {
		t.Fatal(e)
	}
	c, e := verifyRestartCommand(f.Command, key, f.AgentUUID, now)
	if e != nil || serviceHash(f.Command) != f.PayloadHash || !verifyRestartCancellation(f.Cancellation, key, c, f.PayloadHash, now) {
		t.Fatal("original signed cancellation binding rejected", e)
	}
	polls, claims, results := 0, 0, 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Error("wrong credential scope")
		}
		w.Header().Set("Content-Type", "application/json")
		switch req.URL.Path {
		case "/api/endpoints/agent/restarts":
			polls++
			if polls == 1 {
				_ = json.NewEncoder(w).Encode(restartPoll{Command: &f.Command})
			} else {
				_ = json.NewEncoder(w).Encode(restartPoll{Cancellation: &f.Cancellation})
			}
		case "/api/endpoints/agent/restarts/" + c.AttemptUUID + "/claim":
			claims++
			t.Error("cancelled attempt sought execution authority")
			w.WriteHeader(409)
		case "/api/endpoints/agent/restarts/" + c.AttemptUUID + "/result":
			results++
			var got RestartResult
			if json.NewDecoder(req.Body).Decode(&got) != nil || got != f.Result {
				t.Error("cancelled result differs from canonical receipt")
			}
			_, _ = w.Write(f.ACK)
		default:
			t.Error("unexpected cancellation route")
			w.WriteHeader(404)
		}
	}))
	defer server.Close()
	client, e := NewClient(server.URL, f.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	client.http = server.Client()
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	defer store.Close()
	journal, e := loadRestartJournal(store, f.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	executor := &fakeRestartExecutor{}
	r := newRestartController(func(RemotePeer) bool { return true }, executor)
	r.journal = journal
	r.agent = f.AgentUUID
	r.origin = "https://localhost"
	r.key = key
	r.ready = true
	r.executionEnabled = true
	r.boot = f.BootID
	r.bootAt = now
	r.clock = func() time.Time { return now }
	r.monotonic = func() time.Time { return now }
	r.connected = func() bool { return true }
	r.transport = restartHTTP{client, strings.Repeat("a", 64)}
	r.tick(t.Context())
	if r.active == nil {
		t.Fatal("original signed schedule not accepted")
	}
	now = now.Add(2 * time.Second)
	r.tick(t.Context())
	r.tick(t.Context())
	if r.active.Result == nil || *r.active.Result != f.Result || !r.active.Reported || claims != 0 || executor.calls != 0 || results != 1 {
		t.Fatal("matching signed cancellation failed durable no-execution flow")
	}
	r.mu.Lock()
	view := r.viewLocked()
	r.mu.Unlock()
	writeRestartFixtureView(t, path+".cancel.localview.json", view)
	now = now.Add(2 * time.Second)
	r.tick(t.Context())
	if claims != 0 || executor.calls != 0 || results != 1 {
		t.Fatal("cancelled replay executed or resubmitted")
	}
}
