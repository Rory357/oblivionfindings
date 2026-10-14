package agent

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"

	"strings"
	"sync"
	"testing"
	"time"
)

func serviceFixture() (ServiceCommand, time.Time) {
	now := time.Date(2026, 10, 8, 1, 0, 0, 0, time.UTC)
	return ServiceCommand{Version: 2, Type: "endpoint_service_command", AgentUUID: testAgentUUID, CommandUUID: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", AttemptUUID: "cccccccc-cccc-4ccc-8ccc-cccccccccccc", AssignmentFingerprint: strings.Repeat("a", 64), Capability: "endpoint.service.manage", Parameters: ServiceActionParameters{"Spooler", "restart"}, Policy: ServiceActionPolicy{1, true, "attended", strings.Repeat("b", 64)}, Technician: "Alex", Purpose: "Restore printing", IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(time.Minute).Format(time.RFC3339)}, now
}
func serviceClaimFixture(c ServiceCommand, hash, desktop, nonce string, now time.Time) ServiceClaim {
	claim := ServiceClaim{Version: 1, Type: "endpoint_service_claim", AgentUUID: c.AgentUUID, CommandUUID: c.CommandUUID, AttemptUUID: c.AttemptUUID, AssignmentFingerprint: c.AssignmentFingerprint, PayloadHash: hash, PolicyVersion: c.Policy.Version, CatalogueDigest: c.Policy.CatalogueDigest, ConsentMode: c.Policy.ConsentMode, IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(30 * time.Second).Format(time.RFC3339)}
	if c.Policy.ConsentMode == "attended" {
		claim.DesktopSession = &desktop
		claim.ConsentNonce = &nonce
	}
	return claim
}
func TestServiceActionSignedContract(t *testing.T) {
	c, now := serviceFixture()
	key, _ := testKey()
	envelope := remoteEnvelope(t, c)
	if _, e := verifyServiceCommand(envelope, key, c.AgentUUID, now); e != nil {
		t.Fatal(e)
	}
	hash := serviceHash(envelope)
	desktop := "dddddddd-dddd-4ddd-8ddd-dddddddddddd"
	nonce := strings.Repeat("c", 64)
	claim := serviceClaimFixture(c, hash, desktop, nonce, now)
	signed := remoteEnvelope(t, claim)
	if _, e := verifyServiceClaim(signed, key, c, hash, desktop, nonce, now); e != nil {
		t.Fatal(e)
	}
	if _, e := verifyServiceClaim(signed, key, c, hash, desktop, strings.Repeat("d", 64), now); e == nil {
		t.Fatal("different desktop intent accepted")
	}
	for _, mutate := range []func(*ServiceCommand){func(c *ServiceCommand) { c.AgentUUID = desktop }, func(c *ServiceCommand) { c.Policy.Enabled = false }, func(c *ServiceCommand) { c.Parameters.ServiceName = "OblivionAgent" }, func(c *ServiceCommand) { c.Parameters.Operation = "delete" }, func(c *ServiceCommand) { c.Parameters.ServiceName = "Spooler " }, func(c *ServiceCommand) { c.ExpiresAt = now.Format(time.RFC3339) }} {
		bad := c
		mutate(&bad)
		if _, e := verifyServiceCommand(remoteEnvelope(t, bad), key, c.AgentUUID, now); e == nil {
			t.Fatal("unsafe command accepted")
		}
	}
}

type fakeServiceHandle struct {
	state                       string
	disabled, driver, dependent bool
	calls                       []string
	fail                        bool
}

func (f *fakeServiceHandle) State() (string, error)      { return f.state, nil }
func (f *fakeServiceHandle) Config() (bool, bool, error) { return !f.driver, f.disabled, nil }
func (f *fakeServiceHandle) Dependents() (bool, error)   { return f.dependent, nil }
func (f *fakeServiceHandle) Start() error {
	f.calls = append(f.calls, "start")
	if f.fail {
		return errors.New("private")
	}
	f.state = "running"
	return nil
}
func (f *fakeServiceHandle) Stop() error {
	f.calls = append(f.calls, "stop")
	if f.fail {
		return errors.New("private")
	}
	f.state = "stopped"
	return nil
}
func (f *fakeServiceHandle) Close() error { return nil }

type fakeServiceBackend struct {
	handle *fakeServiceHandle
	names  []string
}

func (f *fakeServiceBackend) Open(name, operation string) (serviceHandle, error) {
	f.names = append(f.names, name)
	return f.handle, nil
}
func TestServiceActionSCMPolicyAndPartialRestart(t *testing.T) {
	for _, name := range []string{"Spooler", "Organisation App 2"} {
		h := &fakeServiceHandle{state: "running"}
		b := &fakeServiceBackend{handle: h}
		r := (boundedServiceExecutor{b}).Execute(context.Background(), ServiceActionParameters{name, "restart"}, func() bool { return true })
		if r.Status != "succeeded" || len(h.calls) != 2 || !r.MutationStarted {
			t.Fatal(r)
		}
	}
	h := &fakeServiceHandle{state: "running"}
	b := &fakeServiceBackend{handle: h}
	checks := 0
	r := (boundedServiceExecutor{b}).Execute(context.Background(), ServiceActionParameters{"Organisation App", "restart"}, func() bool { checks++; return checks == 1 })
	if r.Status != "uncertain" || r.AfterState != "stopped" || len(h.calls) != 1 || r.ReasonCode != "consent_lost" {
		t.Fatal(r)
	}
	for _, name := range serviceDeniedNames {
		r := (boundedServiceExecutor{b}).Execute(context.Background(), ServiceActionParameters{name, "stop"}, func() bool { return true })
		if r.MutationStarted {
			t.Fatal("critical service mutated")
		}
	}
	for _, h := range []*fakeServiceHandle{{state: "running", dependent: true}, {state: "stopped", disabled: true}, {state: "unknown"}, {state: "stopped", driver: true}} {
		b.handle = h
		r := (boundedServiceExecutor{b}).Execute(context.Background(), ServiceActionParameters{"AppService", "restart"}, func() bool { return true })
		if r.MutationStarted || len(h.calls) != 0 {
			t.Fatal(r)
		}
	}
}

type fakeServiceTransport struct {
	t               *testing.T
	envelope        Envelope
	command         ServiceCommand
	now             time.Time
	claims, results int
	failClaim       bool
}

func (f *fakeServiceTransport) Poll(context.Context) (*Envelope, error) { return &f.envelope, nil }
func (f *fakeServiceTransport) Claim(ctx context.Context, c ServiceCommand, hash, desktop, nonce string) (Envelope, error) {
	f.claims++
	if f.failClaim {
		return Envelope{}, errors.New("lost response")
	}
	return remoteEnvelope(f.t, serviceClaimFixture(c, hash, desktop, nonce, f.now)), nil
}
func (f *fakeServiceTransport) Result(context.Context, ServiceCommand, ServiceResult) error {
	f.results++
	return nil
}
func TestServiceActionConsentJournalReplayAndCrash(t *testing.T) {
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	defer store.Close()
	c, now := serviceFixture()
	journal, e := loadServiceJournal(store, c.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	backend := &fakeServiceBackend{handle: &fakeServiceHandle{state: "running"}}
	transport := &fakeServiceTransport{t: t, envelope: remoteEnvelope(t, c), command: c, now: now}
	key, _ := testKey()
	r := newServiceActionController(func(RemotePeer) bool { return true }, boundedServiceExecutor{backend})
	r.journal = journal
	r.key = key
	r.agent = c.AgentUUID
	r.origin = "https://localhost"
	r.transport = transport
	r.ready = true
	r.clock = func() time.Time { return now }
	r.tick(context.Background())
	origin, _ := CanonicalOriginHash(r.origin)
	peer := RemotePeer{"S-user", "S-logon", 1}
	v, e := r.Handle(peer, ServiceActionDecision{Operation: "service_action_poll", OriginHash: origin})
	if e != nil || v.Status != "requested" {
		t.Fatal(v, e)
	}
	_, e = r.Handle(peer, ServiceActionDecision{Operation: "service_action_decide", OriginHash: origin, AttemptUUID: c.AttemptUUID, PayloadHash: v.PayloadHash, ConsentToken: v.ConsentToken, Decision: "allow"})
	if e != nil {
		t.Fatal(e)
	}
	r.tick(context.Background())
	r.tick(context.Background())
	if transport.claims != 1 || len(backend.handle.calls) != 2 {
		t.Fatal("attempt repeated")
	}
	recovered, e := loadServiceJournal(store, c.AgentUUID)
	if e != nil || recovered.Entries[c.AttemptUUID].Result.Status != "succeeded" {
		t.Fatal(e)
	}
	entry := recovered.Entries[c.AttemptUUID]
	entry.Phase = "executing"
	entry.Result = nil
	entry.Reported = false
	entry.ReportedAt = ""
	entry.ObservationAcked = false
	if recovered.save() != nil {
		t.Fatal("save")
	}
	recovered, e = loadServiceJournal(store, c.AgentUUID)
	if e != nil || recovered.Entries[c.AttemptUUID].Result.Status != "uncertain" {
		t.Fatal("crash execution not uncertain", e)
	}
}
func TestServiceActionStickySessionWithdrawal(t *testing.T) {
	c, now := serviceFixture()
	r := newServiceActionController(func(RemotePeer) bool { return true }, nil)
	r.origin = "https://localhost"
	r.ready = true
	r.clock = func() time.Time { return now }
	r.active = &serviceJournalEntry{Command: c, Hash: strings.Repeat("a", 64), Phase: "prepared"}
	peer := RemotePeer{"S-user", "S-logon", 1}
	origin, _ := CanonicalOriginHash(r.origin)
	v, e := r.Handle(peer, ServiceActionDecision{Operation: "service_action_poll", OriginHash: origin})
	if e != nil {
		t.Fatal(e)
	}
	_, e = r.Handle(peer, ServiceActionDecision{Operation: "service_action_decide", OriginHash: origin, AttemptUUID: c.AttemptUUID, PayloadHash: v.PayloadHash, ConsentToken: v.ConsentToken, Decision: "allow"})
	if e != nil {
		t.Fatal(e)
	}
	r.InvalidateConsent()
	claim := serviceClaimFixture(c, v.PayloadHash, r.desktop, v.ConsentToken, now)
	if r.authorized(claim) {
		t.Fatal("unlock restored withdrawn consent")
	}
	var wg sync.WaitGroup
	for i := 0; i < 10; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, _ = r.Handle(peer, ServiceActionDecision{Operation: "service_action_poll", OriginHash: origin})
			r.InvalidateConsent()
		}()
	}
	wg.Wait()
}
func TestServiceActionRetirementAndIndependentReadback(t *testing.T) {
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	defer store.Close()
	c, now := serviceFixture()
	journal, e := loadServiceJournal(store, c.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	result := serviceResult(c, strings.Repeat("a", 64), "succeeded", "confirmed")
	result.BeforeState = "stopped"
	result.AfterState = "running"
	result.MutationStarted = true
	entry := &serviceJournalEntry{Command: c, Hash: result.PayloadHash, Phase: "terminal", Result: &result, Reported: true, ReportedAt: now.Format(time.RFC3339)}
	journal.Entries[c.AttemptUUID] = entry
	backend := &fakeServiceBackend{handle: &fakeServiceHandle{state: "stopped"}}
	r := newServiceActionController(func(RemotePeer) bool { return true }, boundedServiceExecutor{backend})
	r.journal = journal
	r.ready = true
	r.clock = func() time.Time { return now }
	r.origin = "https://localhost"
	r.agent = c.AgentUUID
	key, _ := testKey()
	r.key = key
	r.transport = &fakeServiceTransport{t: t, envelope: remoteEnvelope(t, c), command: c, now: now}
	now = now.Add(4 * time.Minute)
	if r.retire() != nil || journal.Entries[c.AttemptUUID] == nil {
		t.Fatal("unacknowledged readback was purged")
	}
	observations := r.observations(context.Background())
	if len(observations) != 1 || observations[0].State != "stopped" || observations[0].State == entry.Result.AfterState {
		t.Fatal("receipt state reused as observation")
	}
	body, _ := json.Marshal(struct {
		Observations []ServiceActionObservation `json:"service_action_observations"`
	}{observations})
	r.acknowledgeObservations(body, []ServiceObservationAcceptance{{observations[0].CommandUUID, observations[0].AttemptUUID, observations[0].ObservedAt}})
	if r.retire() != nil || journal.Entries[c.AttemptUUID] != nil || journal.RetiredBefore != c.ExpiresAt {
		t.Fatal("acknowledged expired retirement failed")
	}
	now = now.Add(-4 * time.Minute)
	r.tick(context.Background())
	if len(backend.handle.calls) != 0 || len(journal.Entries) != 0 {
		t.Fatal("clock rollback resurrected retired command")
	}
	recovered, e := loadServiceJournal(store, c.AgentUUID)
	if e != nil || recovered.RetiredBefore != c.ExpiresAt {
		t.Fatal("retirement watermark not durable", e)
	}
}
func TestServiceActionJournalRejectsClosedSchemaTampering(t *testing.T) {
	c, _ := serviceFixture()
	j := serviceJournal{Version: 1, AgentUUID: c.AgentUUID, Entries: map[string]*serviceJournalEntry{c.AttemptUUID: {Command: c, Hash: strings.Repeat("a", 64), Phase: "prepared"}}}
	data, _ := json.Marshal(j)
	for _, bad := range []string{strings.Replace(string(data), `"reported":false`, `"reported":null`, 1), strings.Replace(string(data), `"version":1,`, ``, 1), strings.Replace(string(data), `"phase":"prepared"`, `"phase":"prepared","phase":"executing"`, 1), strings.Replace(string(data), `"enabled":true`, `"enabled":null`, 1)} {
		var check serviceJournal
		if uniqueServiceJournal([]byte(bad)) == nil && decodeServiceJournal([]byte(bad), &check) == nil {
			t.Fatal("tampered journal accepted")
		}
	}
	r := serviceResult(c, strings.Repeat("a", 64), "succeeded", "already_in_state")
	r.AfterState = "running"
	r.BeforeState = "running"
	if validServiceResult(r, c, r.PayloadHash) {
		t.Fatal("restart no-op accepted")
	}
	r.ReasonCode = "confirmed"
	r.MutationStarted = true
	r.AfterState = "stopped"
	if validServiceResult(r, c, r.PayloadHash) {
		t.Fatal("wrong terminal state accepted")
	}
}
func TestServiceActionUnicodeProducerBound(t *testing.T) {
	c, now := serviceFixture()
	c.Technician = strings.Repeat("😀", 200)
	c.Purpose = strings.Repeat("😀", 499) + "<"
	raw, _ := json.Marshal(c)
	var ascii strings.Builder
	for _, r := range string(raw) {
		if r > 0xffff {
			value := r - 0x10000
			fmt.Fprintf(&ascii, `\u%04x\u%04x`, 0xd800+(value>>10), 0xdc00+(value&1023))
		} else {
			ascii.WriteRune(r)
		}
	}
	key, private := testKey()
	payload := []byte(ascii.String())
	envelope := Envelope{Payload: base64.StdEncoding.EncodeToString(payload), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(private, payload))}
	if len(payload) <= 4096 {
		t.Fatal("fixture did not exercise escaped producer bound")
	}
	if _, e := verifyServiceCommand(envelope, key, c.AgentUUID, now); e != nil {
		t.Fatal(e)
	}
}
func TestServiceActionClosedUnicodeAndPreMutationWriteFailure(t *testing.T) {
	c, now := serviceFixture()
	key, private := testKey()
	raw, _ := json.Marshal(c)
	for _, bad := range []string{strings.Replace(string(raw), `"purpose":"Restore printing"`, `"purpose":"\ud800"`, 1), strings.Replace(string(raw), `"operation":"restart"`, `"operation":"restart","operation":"stop"`, 1), strings.Replace(string(raw), `"enabled":true`, `"enabled":true,"shell":"private"`, 1)} {
		payload := []byte(bad)
		envelope := Envelope{Payload: base64.StdEncoding.EncodeToString(payload), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(private, payload))}
		if _, e := verifyServiceCommand(envelope, key, c.AgentUUID, now); e == nil {
			t.Fatal("ambiguous signed intent accepted")
		}
	}
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	defer store.Close()
	journal, e := loadServiceJournal(store, c.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	backend := &fakeServiceBackend{handle: &fakeServiceHandle{state: "running"}}
	transport := &fakeServiceTransport{t: t, envelope: remoteEnvelope(t, c), command: c, now: now}
	r := newServiceActionController(func(RemotePeer) bool { return true }, boundedServiceExecutor{backend})
	r.journal = journal
	r.key = key
	r.agent = c.AgentUUID
	r.origin = "https://localhost"
	r.transport = transport
	r.ready = true
	r.clock = func() time.Time { return now }
	r.tick(context.Background())
	origin, _ := CanonicalOriginHash(r.origin)
	peer := RemotePeer{"S-user", "S-logon", 1}
	v, _ := r.Handle(peer, ServiceActionDecision{Operation: "service_action_poll", OriginHash: origin})
	_, _ = r.Handle(peer, ServiceActionDecision{Operation: "service_action_decide", OriginHash: origin, AttemptUUID: c.AttemptUUID, PayloadHash: v.PayloadHash, ConsentToken: v.ConsentToken, Decision: "allow"})
	store.writeProtected = func(string, []byte) error { return errors.New("injected disk failure") }
	r.tick(context.Background())
	if len(backend.handle.calls) != 0 || r.available() {
		t.Fatal("mutation happened without durable executing intent")
	}
}
func TestServiceActionPendingHeartbeatCannotRetainWithdrawnCapability(t *testing.T) {
	now := time.Now()
	old := Heartbeat{ObservedAt: now.Format(time.RFC3339), ServiceActions: &ServiceActionCapability{Version: 1, InstalledService: true, ReleaseEnabled: true, Executor: "windows_scm", Operations: []string{"start", "stop", "restart"}}}
	if pendingHeartbeatReusable(old, false, now) {
		t.Fatal("withdrawn release capability advertised from pending report")
	}
	if !pendingHeartbeatReusable(old, true, now) {
		t.Fatal("valid immutable pending heartbeat discarded")
	}
	old.ServiceActions = nil
	if pendingHeartbeatReusable(old, true, now) {
		t.Fatal("new runtime capability incorrectly inherited absent evidence")
	}
	if !pendingHeartbeatReusable(old, false, now) {
		t.Fatal("ordinary heartbeat compatibility changed")
	}
}
func TestServiceActionObservationAcceptanceIsNotTransportACK(t *testing.T) {
	observation := ServiceActionObservation{CommandUUID: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", AttemptUUID: "cccccccc-cccc-4ccc-8ccc-cccccccccccc", ServiceName: "AppService", State: "running", ObservedAt: "2026-10-08T01:00:00Z"}
	payload, _ := json.Marshal(struct {
		Observations []ServiceActionObservation `json:"service_action_observations"`
	}{[]ServiceActionObservation{observation}})
	accepted, e := acceptedServiceObservations(payload, nil)
	if e != nil || len(accepted) != 0 {
		t.Fatal("ordinary transport ACK retired observation")
	}
	ack := ServiceObservationAcceptance{observation.CommandUUID, observation.AttemptUUID, observation.ObservedAt}
	accepted, e = acceptedServiceObservations(payload, []ServiceObservationAcceptance{ack})
	if e != nil || len(accepted) != 1 {
		t.Fatal(e)
	}
	wrong := ack
	wrong.ObservedAt = "2026-10-08T00:59:59Z"
	if _, e = acceptedServiceObservations(payload, []ServiceObservationAcceptance{wrong}); e == nil {
		t.Fatal("stale observation ACK accepted")
	}
	if _, e = acceptedServiceObservations(payload, []ServiceObservationAcceptance{ack, ack}); e == nil {
		t.Fatal("duplicate ACK accepted")
	}
	observation.State = "unknown"
	payload, _ = json.Marshal(struct {
		Observations []ServiceActionObservation `json:"service_action_observations"`
	}{[]ServiceActionObservation{observation}})
	if _, e = acceptedServiceObservations(payload, []ServiceObservationAcceptance{ack}); e == nil {
		t.Fatal("unknown observation acknowledged as eligible")
	}
}
