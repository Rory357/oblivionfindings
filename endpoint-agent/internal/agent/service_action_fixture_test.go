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

func TestServiceActionPHPFixture(t *testing.T) {
	path := os.Getenv("OBLI_ACTION_EXPORT")
	if path == "" {
		t.Skip("isolated PHP service action fixture not supplied")
	}
	data, e := os.ReadFile(path)
	if e != nil {
		t.Fatal(e)
	}
	var fixture struct {
		Command      Envelope `json:"command"`
		Claim        Envelope `json:"claim"`
		PublicKey    string   `json:"public_key"`
		AgentUUID    string   `json:"agent_uuid"`
		PayloadHash  string   `json:"payload_hash"`
		ClaimRequest struct {
			CommandUUID string `json:"command_uuid"`
			PayloadHash string `json:"payload_hash"`
			Consent     struct {
				DesktopSession string `json:"desktop_session"`
				ConsentNonce   string `json:"consent_nonce"`
			} `json:"consent"`
		} `json:"claim_request"`
		Result           ServiceResult   `json:"result"`
		HeartbeatPayload json.RawMessage `json:"heartbeat_payload"`
		HeartbeatACK     Acknowledgement `json:"heartbeat_ack"`
	}
	if json.Unmarshal(data, &fixture) != nil {
		t.Fatal("invalid isolated fixture")
	}
	key, e := base64.StdEncoding.Strict().DecodeString(fixture.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	raw, _ := base64.StdEncoding.Strict().DecodeString(fixture.Claim.Payload)
	var claim ServiceClaim
	if json.Unmarshal(raw, &claim) != nil {
		t.Fatal("invalid claim")
	}
	at, e := time.Parse(time.RFC3339, claim.IssuedAt)
	if e != nil {
		t.Fatal(e)
	}
	at = at.Add(time.Second)
	command, e := verifyServiceCommand(fixture.Command, ed25519.PublicKey(key), fixture.AgentUUID, at)
	if e != nil || serviceHash(fixture.Command) != fixture.PayloadHash {
		t.Fatal("PHP dispatch differs from native contract", e)
	}
	if _, e = verifyServiceClaim(fixture.Claim, ed25519.PublicKey(key), command, fixture.PayloadHash, fixture.ClaimRequest.Consent.DesktopSession, fixture.ClaimRequest.Consent.ConsentNonce, at); e != nil {
		t.Fatal(e)
	}
	claims, results := 0, 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Error("credential not endpoint scoped")
		}
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/api/endpoints/agent/commands":
			_ = json.NewEncoder(w).Encode(struct {
				Command Envelope `json:"command"`
			}{fixture.Command})
		case "/api/endpoints/agent/commands/" + command.AttemptUUID + "/claim":
			claims++
			var request map[string]any
			_ = json.NewDecoder(r.Body).Decode(&request)
			encoded, _ := json.Marshal(request)
			expected, _ := json.Marshal(fixture.ClaimRequest)
			var canonical map[string]any
			_ = json.Unmarshal(expected, &canonical)
			want, _ := json.Marshal(canonical)
			if string(encoded) != string(want) {
				t.Error("actual native claim changed immutable PHP intent")
			}
			_ = json.NewEncoder(w).Encode(fixture.Claim)
		case "/api/endpoints/agent/commands/" + command.AttemptUUID + "/result":
			results++
			var result ServiceResult
			if json.NewDecoder(r.Body).Decode(&result) != nil || !validServiceResult(result, command, fixture.PayloadHash) {
				t.Error("actual native result invalid")
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"command_uuid": command.CommandUUID, "attempt_uuid": command.AttemptUUID, "payload_hash": fixture.PayloadHash, "accepted": true})
		case "/api/endpoints/agent/heartbeat":
			var sent json.RawMessage
			if json.NewDecoder(r.Body).Decode(&sent) != nil {
				t.Error("invalid heartbeat request")
			}
			if _, err := acceptedServiceObservations(sent, fixture.HeartbeatACK.ServiceObservationsAccepted); err != nil {
				t.Error("PHP ACK differs from actual native heartbeat", err)
			}
			_ = json.NewEncoder(w).Encode(fixture.HeartbeatACK)
		default:
			t.Error("arbitrary route requested")
			w.WriteHeader(404)
		}
	}))
	defer server.Close()
	client, e := NewClient(server.URL, fixture.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	client.http = server.Client()
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	defer store.Close()
	journal, e := loadServiceJournal(store, command.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	backend := &fakeServiceBackend{handle: &fakeServiceHandle{state: "running"}}
	controller := newServiceActionController(func(RemotePeer) bool { return true }, boundedServiceExecutor{backend})
	controller.journal = journal
	controller.key = key
	controller.agent = command.AgentUUID
	controller.origin = "https://localhost"
	controller.ready = true
	controller.clock = func() time.Time { return at }
	controller.transport = serviceActionHTTP{client, strings.Repeat("a", 64)}
	controller.tick(t.Context())
	peer := RemotePeer{"S-user", "S-logon", 1}
	controller.peer = peer
	controller.desktop = fixture.ClaimRequest.Consent.DesktopSession
	controller.nonce = fixture.ClaimRequest.Consent.ConsentNonce
	origin, _ := CanonicalOriginHash(controller.origin)
	view, e := controller.Handle(peer, ServiceActionDecision{Operation: "service_action_poll", OriginHash: origin})
	if e != nil || view.Status != "requested" {
		t.Fatal(e)
	}
	viewBytes, _ := json.Marshal(view)
	if os.WriteFile(path+".localview.json", viewBytes, 0600) != nil {
		t.Fatal("cannot export local view fixture")
	}
	_, e = controller.Handle(peer, ServiceActionDecision{Operation: "service_action_decide", OriginHash: origin, AttemptUUID: command.AttemptUUID, PayloadHash: fixture.PayloadHash, ConsentToken: controller.nonce, Decision: "allow"})
	if e != nil {
		t.Fatal(e)
	}
	controller.tick(t.Context())
	controller.tick(t.Context())
	if claims != 1 || results != 1 || journal.Entries[command.AttemptUUID].Result.Status != "succeeded" {
		t.Fatal("signed fixture did not complete exactly once injected flow")
	}
	backend.handle.state = "stopped"
	at = at.Add(2 * time.Second)
	observations := controller.observations(t.Context())
	if len(observations) != 1 || observations[0].State != "stopped" || observations[0].State == journal.Entries[command.AttemptUUID].Result.AfterState {
		t.Fatal("readback reused result")
	}
	controller.tick(t.Context())
	if len(fixture.HeartbeatPayload) != 0 {
		var heartbeat struct {
			Sequence     uint64                     `json:"sequence"`
			Observations []ServiceActionObservation `json:"service_action_observations"`
		}
		if json.Unmarshal(fixture.HeartbeatPayload, &heartbeat) != nil || len(heartbeat.Observations) != 1 {
			t.Fatal("invalid PHP heartbeat fixture")
		}
		observation := heartbeat.Observations[0]
		at, e = time.Parse(time.RFC3339, observation.ObservedAt)
		if e != nil {
			t.Fatal(e)
		}
		backend.handle.state = observation.State
		measured := controller.observations(t.Context())
		if len(measured) != 1 || measured[0] != observation {
			t.Fatal("PHP heartbeat differs from independently measured native observation")
		}
		ack, err := client.Heartbeat(t.Context(), State{AgentUUID: command.AgentUUID, Credential: strings.Repeat("a", 64), Sequence: heartbeat.Sequence}, fixture.HeartbeatPayload)
		if err != nil || len(ack.ServiceObservationsAccepted) != 1 {
			t.Fatal("actual PHP heartbeat ACK rejected", err)
		}
		controller.acknowledgeObservations(fixture.HeartbeatPayload, ack.ServiceObservationsAccepted)
		controller.retire()
		if !journal.Entries[command.AttemptUUID].ObservationAcked {
			t.Fatal("eligible PHP measurement ACK was not durably retained")
		}
	}
	if claims != 1 {
		t.Fatal("fixture replay re-executed")
	}
}
