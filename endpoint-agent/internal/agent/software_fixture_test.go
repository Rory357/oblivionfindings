package agent

import (
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"
)

func TestSoftwareHTTPContractsAndProducerViews(t *testing.T) {
	r, f, wire, _ := softwareFixture(t)
	var c SoftwareCommand
	raw, _ := base64.StdEncoding.DecodeString(wire.command.Payload)
	_ = json.Unmarshal(raw, &c)
	c.Technician = strings.Repeat("😀", 200)
	c.Purpose = strings.Repeat("<", 500)
	c.Parameters.DisplayName = strings.Repeat("😀", 200)
	c.Parameters.PublisherLabel = strings.Repeat("😀", 200)
	wire.command = signSoftware(wire.private, c)
	polls, artifacts, claims, results := 0, 0, 0, 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Error("credential")
		}
		w.Header().Set("Content-Type", "application/json")
		switch req.URL.Path {
		case "/api/endpoints/agent/software":
			polls++
			_ = json.NewEncoder(w).Encode(softwarePoll{&wire.command})
		case "/api/endpoints/agent/software/" + c.AttemptUUID + "/artifact":
			artifacts++
			w.Header().Set("Content-Type", "application/octet-stream")
			_, _ = w.Write(wire.data)
		case "/api/endpoints/agent/software/" + c.AttemptUUID + "/claim":
			claims++
			var m map[string]json.RawMessage
			if json.NewDecoder(req.Body).Decode(&m) != nil || len(m) != 5 || m["command_uuid"] == nil || m["payload_hash"] == nil || m["artifact_sha256"] == nil || m["boot_id"] == nil || m["consent"] == nil {
				t.Error("claim closedkeys")
			}
			var consent struct {
				Desktop string `json:"desktop_session"`
				Nonce   string `json:"consent_nonce"`
			}
			_ = json.Unmarshal(m["consent"], &consent)
			e, _ := wire.Claim(req.Context(), c, r.active.Hash, r.boot, consent.Desktop, consent.Nonce)
			_ = json.NewEncoder(w).Encode(e)
		case "/api/endpoints/agent/software/" + c.AttemptUUID + "/result":
			results++
			var x SoftwareResult
			if json.NewDecoder(req.Body).Decode(&x) != nil || !validSoftwareResult(x, c, r.active.Hash) {
				t.Error("result")
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"command_uuid": c.CommandUUID, "attempt_uuid": c.AttemptUUID, "payload_hash": r.active.Hash, "accepted": true})
		default:
			t.Error("unknownroute")
			w.WriteHeader(404)
		}
	}))
	defer server.Close()
	client, e := NewClient(server.URL, base64.StdEncoding.EncodeToString(r.key))
	if e != nil {
		t.Fatal(e)
	}
	client.http = server.Client()
	r.transport = softwareHTTP{client, strings.Repeat("a", 64)}
	r.tick(t.Context())
	origin, _ := CanonicalOriginHash(r.origin)
	v, e := r.Handle(RemotePeer{"S-user", "S-logon", 1}, SoftwareDecision{Operation: "software_poll", OriginHash: origin})
	if e != nil || v.Status != "requested" {
		t.Fatal(v, e)
	}
	softwareExportView(t, ".requested.json", v)
	softwareAllow(t, r)
	r.tick(t.Context())
	r.mu.Lock()
	v = r.viewLocked()
	r.mu.Unlock()
	softwareExportView(t, ".installing.json", v)
	f.child.code = 3010
	f.child.done = true
	r.tick(t.Context())
	r.tick(t.Context())
	r.mu.Lock()
	v = r.viewLocked()
	r.mu.Unlock()
	softwareExportView(t, ".awaiting.json", v)
	version := "4.2.1"
	f.state = "installed"
	f.version = &version
	rows := r.observations(r.boot, r.clock().Format("2006-01-02T15:04:05Z"))
	payload, _ := json.Marshal(map[string]any{"boot_id": r.boot, "software_observations": rows})
	r.acknowledge(payload, []SoftwareAcceptance{{rows[0], "succeeded"}})
	r.mu.Lock()
	v = r.viewLocked()
	r.mu.Unlock()
	softwareExportView(t, "", v)
	if v.Status != "succeeded" || !v.RebootRequired || polls != 1 || artifacts != 1 || claims != 1 || results != 1 || f.calls != 1 {
		t.Fatal("HTTP once-onlyflow", v, polls, artifacts, claims, results)
	}
}
func softwareExportView(t *testing.T, suffix string, v SoftwareView) {
	t.Helper()
	raw, e := json.Marshal(v)
	if e != nil || len(raw) > 32768 {
		t.Fatal("producer bound")
	}
	var m map[string]json.RawMessage
	if json.Unmarshal(raw, &m) != nil || len(m) != 22 {
		t.Fatal("local schema")
	}
	if path := os.Getenv("OBLI_SOFTWARE_LOCAL_VIEW"); path != "" {
		if os.WriteFile(path+suffix, raw, 0600) != nil {
			t.Fatal("view export")
		}
	}
}

func TestSoftwarePHPFixture(t *testing.T) {
	softwarePHPFixture(t, os.Getenv("OBLI_SOFTWARE_EXPORT"), false)
}
func TestSoftwarePHPRebootFixture(t *testing.T) {
	softwarePHPFixture(t, os.Getenv("OBLI_SOFTWARE_REBOOT_EXPORT"), true)
}
func softwarePHPFixture(t *testing.T, path string, reboot bool) {

	if path == "" {
		t.Skip("isolated signed PHP software fixture not supplied")
	}
	b, e := os.ReadFile(path)
	if e != nil {
		t.Fatal(e)
	}
	var f struct {
		Command          Envelope        `json:"command"`
		Claim            Envelope        `json:"claim"`
		PublicKey        string          `json:"public_key"`
		AgentUUID        string          `json:"agent_uuid"`
		PayloadHash      string          `json:"payload_hash"`
		ClaimRequest     json.RawMessage `json:"claim_request"`
		Result           SoftwareResult  `json:"result"`
		Receipt          SoftwareResult  `json:"receipt"`
		ChangedPayload   json.RawMessage `json:"changed_boot_payload"`
		ChangedACK       Acknowledgement `json:"changed_boot_ack"`
		ResultACK        json.RawMessage `json:"result_ack"`
		HeartbeatPayload json.RawMessage `json:"heartbeat_payload"`
		HeartbeatACK     Acknowledgement `json:"heartbeat_ack"`
		Artifact         string          `json:"artifact_base64"`
	}
	if json.Unmarshal(b, &f) != nil {
		t.Fatal("fixture")
	}
	if reboot {
		f.Result = f.Receipt
		if f.Result.ExitCode == nil || *f.Result.ExitCode != 3010 || !f.Result.RebootRequired {
			t.Fatal("not an original 3010 scenario")
		}
	}
	key, e := base64.StdEncoding.Strict().DecodeString(f.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	raw, e := base64.StdEncoding.Strict().DecodeString(f.Claim.Payload)
	var claim SoftwareClaim
	if e != nil || json.Unmarshal(raw, &claim) != nil {
		t.Fatal("claim")
	}
	now, e := restartTime(claim.IssuedAt)
	if e != nil {
		t.Fatal(e)
	}
	c, e := verifySoftwareCommand(f.Command, key, f.AgentUUID, now)
	if e != nil || serviceHash(f.Command) != f.PayloadHash {
		t.Fatal("actual signed command rejected", e)
	}
	artifact, e := base64.StdEncoding.Strict().DecodeString(f.Artifact)
	if e != nil || int64(len(artifact)) != c.Parameters.SizeBytes {
		t.Fatal("artifact fixture")
	}
	desktop, nonce := "", ""
	if claim.DesktopSession != nil {
		desktop = *claim.DesktopSession
	}
	if claim.ConsentNonce != nil {
		nonce = *claim.ConsentNonce
	}
	if _, e = verifySoftwareClaim(f.Claim, key, c, f.PayloadHash, claim.BootID, desktop, nonce, now); e != nil {
		t.Fatal("actual signed claim rejected", e)
	}
	claims, results, heartbeats := 0, 0, 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Error("endpointcredential")
		}
		w.Header().Set("Content-Type", "application/json")
		switch req.URL.Path {
		case "/api/endpoints/agent/software":
			_ = json.NewEncoder(w).Encode(softwarePoll{&f.Command})
		case "/api/endpoints/agent/software/" + c.AttemptUUID + "/artifact":
			w.Header().Set("Content-Type", "application/octet-stream")
			_, _ = w.Write(artifact)
		case "/api/endpoints/agent/software/" + c.AttemptUUID + "/claim":
			claims++
			var got, want map[string]any
			_ = json.NewDecoder(req.Body).Decode(&got)
			_ = json.Unmarshal(f.ClaimRequest, &want)
			x, _ := json.Marshal(got)
			y, _ := json.Marshal(want)
			if string(x) != string(y) {
				t.Error("claim differs from actual fixture")
			}
			_ = json.NewEncoder(w).Encode(f.Claim)
		case "/api/endpoints/agent/software/" + c.AttemptUUID + "/result":
			results++
			var got SoftwareResult
			if json.NewDecoder(req.Body).Decode(&got) != nil {
				t.Error("resultdecode")
			}
			x, _ := json.Marshal(got)
			y, _ := json.Marshal(f.Result)
			if string(x) != string(y) {
				t.Error("result differs from canonical receipt")
			}
			_, _ = w.Write(f.ResultACK)
		case "/api/endpoints/agent/heartbeat":
			heartbeats++
			var got json.RawMessage
			_ = json.NewDecoder(req.Body).Decode(&got)
			response := f.HeartbeatACK
			if reboot && heartbeats == 2 {
				response = f.ChangedACK
			}
			if validateSoftwareACK(got, response.SoftwareAccepted) != nil {
				t.Error("ACK differs from sent readback")
			}
			_ = json.NewEncoder(w).Encode(response)
		default:
			t.Error("arbitraryroute")
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
	journal, e := loadSoftwareJournal(store, f.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	executor := &fakeSoftwareExecutor{child: &fakeSoftwareChild{}, state: "absent"}
	r := newSoftwareController(func(RemotePeer) bool { return true }, executor)
	r.journal = journal
	r.agent = f.AgentUUID
	r.origin = "https://localhost"
	r.key = key
	r.ready = true
	r.boot = claim.BootID
	r.bootAt = now
	r.clock = func() time.Time { return now }
	r.connected = func() bool { return true }
	r.transport = softwareHTTP{client, strings.Repeat("a", 64)}
	defer func() {
		r.mu.Lock()
		r.closeArtifactLocked()
		if r.child != nil {
			r.child.Close()
		}
		if r.gateHeld {
			r.mutationGate.Unlock()
		}
		r.mu.Unlock()
	}()
	r.tick(t.Context())
	origin, _ := CanonicalOriginHash(r.origin)
	peer := RemotePeer{"S-user", "S-logon", 1}
	if c.Policy.ConsentMode == "attended" {
		r.peer = peer
		r.desktop = desktop
		r.nonce = nonce
		v, e := r.Handle(peer, SoftwareDecision{Operation: "software_poll", OriginHash: origin})
		if e != nil {
			t.Fatal(e)
		}
		writeSoftwareActualView(t, path+".localview.json.requested.json", v)
		_, e = r.Handle(peer, SoftwareDecision{Operation: "software_decide", OriginHash: origin, AttemptUUID: v.AttemptUUID, PayloadHash: v.PayloadHash, ConsentToken: v.ConsentToken, Decision: "allow"})
		if e != nil {
			t.Fatal(e)
		}
	}
	r.tick(t.Context())
	r.mu.Lock()
	view := r.viewLocked()
	r.mu.Unlock()
	writeSoftwareActualView(t, path+".localview.json.installing.json", view)
	if executor.calls != 1 || claims != 1 || f.Result.ExitCode == nil {
		t.Fatal("once-only actual launch")
	}
	executor.child.code = *f.Result.ExitCode
	executor.child.done = true
	r.tick(t.Context())
	r.tick(t.Context())
	r.mu.Lock()
	view = r.viewLocked()
	r.mu.Unlock()
	writeSoftwareActualView(t, path+".localview.json.awaiting.json", view)
	if results != 1 || !r.active.Reported {
		t.Fatal("actual receipt ACK")
	}
	var h struct {
		BootID     string                `json:"boot_id"`
		ObservedAt string                `json:"observed_at"`
		Sequence   uint64                `json:"sequence"`
		Rows       []SoftwareObservation `json:"software_observations"`
	}
	if json.Unmarshal(f.HeartbeatPayload, &h) != nil || len(h.Rows) != 1 {
		t.Fatal("actual heartbeat")
	}
	executor.state = h.Rows[0].State
	executor.version = h.Rows[0].ProductVersion
	rows := r.observations(h.BootID, h.ObservedAt)
	x, _ := json.Marshal(rows)
	y, _ := json.Marshal(h.Rows)
	if string(x) != string(y) {
		t.Fatal("independent actual observation differs")
	}
	ack, e := client.Heartbeat(t.Context(), State{AgentUUID: f.AgentUUID, Credential: strings.Repeat("a", 64), Sequence: h.Sequence}, f.HeartbeatPayload)
	if e != nil {
		t.Fatal("actual heartbeat ACK", e)
	}
	r.acknowledge(f.HeartbeatPayload, ack.SoftwareAccepted)
	r.mu.Lock()
	view = r.viewLocked()
	r.mu.Unlock()
	writeSoftwareActualView(t, path+".localview.json", view)
	if reboot && !view.RebootRequired {
		t.Fatal("3010 notice lost after first product ACK")
	}
	if r.gateHeld || view.Status != "succeeded" || heartbeats != 1 {
		t.Fatal("actual independent acceptance")
	}
	r.tick(t.Context())
	if executor.calls != 1 || claims != 1 {
		t.Fatal("actual command repeated")
	}
	if reboot {
		if json.Unmarshal(f.ChangedPayload, &h) != nil || len(h.Rows) != 1 || h.BootID == claim.BootID {
			t.Fatal("missing independent changed boot")
		}
		executor.state, executor.version = h.Rows[0].State, h.Rows[0].ProductVersion
		rows = r.observations(h.BootID, h.ObservedAt)
		x, _ = json.Marshal(rows)
		y, _ = json.Marshal(h.Rows)
		if string(x) != string(y) {
			t.Fatal("changed-boot independently measured rows differ")
		}
		ack, e = client.Heartbeat(t.Context(), State{AgentUUID: f.AgentUUID, Credential: strings.Repeat("a", 64), Sequence: h.Sequence}, f.ChangedPayload)
		if e != nil {
			t.Fatal("changed-boot actual ACK", e)
		}
		r.acknowledge(f.ChangedPayload, ack.SoftwareAccepted)
		r.mu.Lock()
		view = r.viewLocked()
		r.mu.Unlock()
		if view.RebootRequired || !r.active.RebootAcknowledged || r.gateHeld || view.Status != "succeeded" || heartbeats != 2 {
			t.Fatal("exact later boot did not clear only notice", view)
		}
		writeSoftwareActualView(t, path+".localview.json.changed-boot.json", view)
	}
}
func writeSoftwareActualView(t *testing.T, path string, v SoftwareView) {
	t.Helper()
	b, e := json.Marshal(v)
	if e != nil || len(b) > 32768 || os.WriteFile(path, b, 0600) != nil {
		t.Fatal("actual view export")
	}
}
