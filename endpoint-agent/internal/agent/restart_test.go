package agent

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type fakeRestartExecutor struct {
	calls        int
	fail         bool
	prepare      func()
	prepareError bool
}

func (f *fakeRestartExecutor) Restart(allowed func() bool) (bool, error) {
	if f.prepare != nil {
		f.prepare()
	}
	if f.prepareError {
		return false, errors.New("preparation refused")
	}
	if !allowed() {
		return false, errServiceAction
	}
	f.calls++
	if f.fail {
		return true, errors.New("refused")
	}
	return true, nil
}

type fakeRestartTransport struct {
	t                      *testing.T
	r                      *RestartController
	command                Envelope
	cancel                 *Envelope
	polls, claims, results int
	claimHook              func()
	claimError             bool
	last                   RestartResult
}

func (f *fakeRestartTransport) Poll(context.Context) (restartPoll, error) {
	f.polls++
	return restartPoll{&f.command, f.cancel}, nil
}
func (f *fakeRestartTransport) Claim(_ context.Context, c RestartCommand, hash, boot, desktop, nonce, warning string) (Envelope, error) {
	f.claims++
	if f.claimHook != nil {
		f.claimHook()
	}
	if f.claimError {
		return Envelope{}, errors.New("lost")
	}
	now := f.r.clock()
	x := RestartClaim{Version: 1, Type: "endpoint_restart_claim", AgentUUID: c.AgentUUID, CommandUUID: c.CommandUUID, AttemptUUID: c.AttemptUUID, AssignmentFingerprint: c.AssignmentFingerprint, PayloadHash: hash, PolicyVersion: c.Policy.Version, ConsentMode: c.Policy.ConsentMode, BootID: boot, ExecuteAt: c.Parameters.ExecuteAt, IssuedAt: now.Format(time.RFC3339), ExpiresAt: c.ExpiresAt}
	if c.Policy.ConsentMode == "attended" {
		x.DesktopSession = &desktop
		x.ConsentNonce = &nonce
		x.WarningStartedAt = &warning
	}
	return remoteEnvelope(f.t, x), nil
}
func (f *fakeRestartTransport) Result(_ context.Context, c RestartCommand, x RestartResult) error {
	f.results++
	if !validRestartResult(x, c, x.PayloadHash) {
		f.t.Fatal("invalid result")
	}
	f.last = x
	return nil
}
func restartFixture(t *testing.T, mode string) (*RestartController, *fakeRestartTransport, *fakeRestartExecutor, *time.Time, *time.Time) {
	t.Helper()
	now := time.Date(2026, 10, 9, 1, 0, 0, 0, time.UTC)
	mono := now
	c := RestartCommand{Version: 1, Type: "endpoint_restart_schedule", AgentUUID: testAgentUUID, CommandUUID: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", AttemptUUID: "cccccccc-cccc-4ccc-8ccc-cccccccccccc", AssignmentFingerprint: strings.Repeat("a", 64), Capability: "endpoint.computer.restart", Parameters: RestartParameters{now.Add(60 * time.Second).Format(time.RFC3339), 60, false}, Policy: RestartPolicy{1, true, mode}, Technician: "Alex", Purpose: "Apply approved updates", IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(65 * time.Second).Format(time.RFC3339)}
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { store.Close() })
	j, e := loadRestartJournal(store, testAgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	exec := &fakeRestartExecutor{}
	r := newRestartController(func(RemotePeer) bool { return true }, exec)
	r.journal = j
	r.agent = testAgentUUID
	r.origin = "https://localhost"
	r.key, _ = testKey()
	r.clock = func() time.Time { return now }
	r.monotonic = func() time.Time { return mono }
	r.connected = func() bool { return true }
	r.ready = true
	r.boot = "dddddddd-dddd-4ddd-8ddd-dddddddddddd"
	r.bootAt = now
	f := &fakeRestartTransport{t: t, r: r, command: remoteEnvelope(t, c)}
	r.transport = f
	r.tick(t.Context())
	return r, f, exec, &now, &mono
}
func restartDecision(t *testing.T, r *RestartController, operation, decision string) RestartView {
	t.Helper()
	peer := RemotePeer{"S-user", "S-logon", 1}
	origin, _ := CanonicalOriginHash(r.origin)
	v, e := r.Handle(peer, RestartDecision{Operation: "restart_poll", OriginHash: origin})
	if e != nil {
		t.Fatal(e)
	}
	v, e = r.Handle(peer, RestartDecision{Operation: operation, OriginHash: origin, AttemptUUID: v.AttemptUUID, PayloadHash: v.PayloadHash, ConsentToken: v.ConsentToken, Decision: decision})
	if e != nil {
		t.Fatal(e)
	}
	return v
}
func advanceRestart(r *RestartController, now, mono *time.Time, seconds int) {
	for i := 0; i < seconds; i++ {
		*now = now.Add(time.Second)
		*mono = mono.Add(time.Second)
		r.tick(context.Background())
	}
}
func TestRestartAttendedCountdownAndImmutableRecovery(t *testing.T) {
	r, f, exec, now, mono := restartFixture(t, "attended")
	peer := RemotePeer{"S-user", "S-logon", 1}
	origin, _ := CanonicalOriginHash(r.origin)
	requested, e := r.Handle(peer, RestartDecision{Operation: "restart_poll", OriginHash: origin})
	if e != nil {
		t.Fatal(e)
	}
	exportRestartView(t, requested, ".requested.json")
	restartDecision(t, r, "restart_visible", "visible")
	countdown := restartDecision(t, r, "restart_decide", "allow")
	exportRestartView(t, countdown, ".countdown.json")
	for i := 0; i < 60; i++ {
		restartDecision(t, r, "restart_visible", "visible")
		advanceRestart(r, now, mono, 1)
	}
	if exec.calls != 1 || f.claims != 1 || r.active.Result.Status != "awaiting_boot" {
		t.Fatal("countdown not executed exactly once")
	}
	r.tick(t.Context())
	if !r.active.Reported {
		t.Fatal("result not acknowledged")
	}
	r.tick(t.Context())
	if exec.calls != 1 || f.claims != 1 {
		t.Fatal("replayed attempt executed")
	}
	// Actual local producer bytes are consumed by the native parser fixture when requested.
	if path := os.Getenv("OBLI_RESTART_LOCAL_VIEW"); path != "" {
		r.mu.Lock()
		v := r.viewLocked()
		r.mu.Unlock()
		b, _ := json.Marshal(v)
		if e := os.WriteFile(path, b, 0600); e != nil {
			t.Fatal(e)
		}
	}
	newBoot := "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee"
	observations := r.heartbeat(newBoot, now.Add(time.Second).Format(time.RFC3339))
	if len(observations) != 1 {
		t.Fatal("changed boot observation missing")
	}
	h := struct {
		BootID              string               `json:"boot_id"`
		RestartObservations []RestartObservation `json:"restart_observations"`
	}{newBoot, observations}
	payload, _ := json.Marshal(h)
	a := RestartAcceptance{r.active.Command.CommandUUID, r.active.Command.AttemptUUID, r.active.BeforeBootID, newBoot, "succeeded"}
	r.acknowledge(payload, []RestartAcceptance{a})
	if r.active.BootStatus != "succeeded" {
		t.Fatal("authenticated boot outcome missing")
	}
}
func TestRestartCancellationAndSessionLossNeverExecute(t *testing.T) {
	for _, scenario := range []string{"cancel", "hidden", "lock", "server_cancel", "claim_cancel", "lost_claim", "offline", "missed", "clock"} {
		t.Run(scenario, func(t *testing.T) {
			r, f, exec, now, mono := restartFixture(t, "attended")
			restartDecision(t, r, "restart_visible", "visible")
			restartDecision(t, r, "restart_decide", "allow")
			switch scenario {
			case "cancel":
				v := restartDecision(t, r, "restart_decide", "cancel")
				if v.Status != "cancelled" || v.CanCancel {
					t.Fatal("cancel not durable")
				}
			case "hidden":
				restartDecision(t, r, "restart_visible", "hidden")
			case "lock":
				r.InvalidateConsent()
			case "server_cancel":
				c := r.active.Command
				f.cancelPtr(t, c, r.active.Hash, *now)
			case "claim_cancel":
				f.claimHook = func() { restartDecision(t, r, "restart_decide", "cancel") }
			case "lost_claim":
				f.claimError = true
			case "offline":
				r.connected = func() bool { return false }
			case "missed":
				*now = now.Add(70 * time.Second)
				*mono = mono.Add(70 * time.Second)
			case "clock":
				*now = now.Add(-time.Minute)
			}
			for i := 0; i < 70; i++ {
				if scenario == "claim_cancel" || scenario == "lost_claim" || scenario == "offline" {
					if r.active.Phase != "terminal" {
						restartDecision(t, r, "restart_visible", "visible")
					}
				}
				advanceRestart(r, now, mono, 1)
			}
			if exec.calls != 0 {
				t.Fatal("invalid authority restarted")
			}
		})
	}
}
func (f *fakeRestartTransport) cancelPtr(t *testing.T, c RestartCommand, hash string, now time.Time) {
	e := remoteEnvelope(t, RestartCancellation{1, "endpoint_restart_cancel", c.AgentUUID, c.CommandUUID, c.AttemptUUID, c.AssignmentFingerprint, hash, now.Format(time.RFC3339), c.ExpiresAt})
	f.cancel = &e
}
func TestRestartCrashJournalCannotRepeatAPI(t *testing.T) {
	r, _, exec, now, _ := restartFixture(t, "unattended")
	x := r.active
	x.BeforeBootID = r.boot
	x.Phase = "api_entered"
	if r.journal.save() != nil {
		t.Fatal("save")
	}
	j, e := loadRestartJournal(r.journal.store, r.agent)
	if e != nil {
		t.Fatal(e)
	}
	x = j.Entries[x.Command.AttemptUUID]
	if x.Phase != "terminal" || x.Result.Status != "uncertain" || !x.Result.MutationStarted {
		t.Fatal("crash ambiguity lost")
	}
	r.journal = j
	r.active = x
	*now = now.Add(time.Minute)
	r.tick(t.Context())
	if exec.calls != 0 {
		t.Fatal("API repeated after crash")
	}
	b, e := os.ReadFile(filepath.Join(j.store.dir, "restart-actions.json"))
	if e != nil {
		t.Fatal(e)
	}
	var root map[string]any
	_ = json.Unmarshal(b, &root)
	entries := root["entries"].(map[string]any)
	row := entries[x.Command.AttemptUUID].(map[string]any)
	command := row["command"].(map[string]any)
	delete(command["parameters"].(map[string]any), "force_close")
	b, _ = json.Marshal(root)
	_ = os.WriteFile(filepath.Join(j.store.dir, "restart-actions.json"), b, 0600)
	if _, e = loadRestartJournal(j.store, r.agent); e == nil {
		t.Fatal("missing field at rest accepted")
	}
}
func TestRestartUnattendedRefusalAndBootACKBinding(t *testing.T) {
	r, f, exec, now, mono := restartFixture(t, "unattended")
	exec.fail = true
	advanceRestart(r, now, mono, 60)
	if exec.calls != 1 || f.claims != 1 || r.active.Result.Status != "failed" || r.active.Result.ReasonCode != "restart_refused" || !r.active.Result.MutationStarted {
		t.Fatal("refusal mislabeled")
	}
	if validateRestartACK([]byte(`{"boot_id":"eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee"}`), []RestartAcceptance{{r.active.Command.CommandUUID, r.active.Command.AttemptUUID, r.boot, "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee", "succeeded"}}) == nil {
		t.Fatal("unobserved boot ACK accepted")
	}
}

func TestRestartArmedCrashAndClaimClockJump(t *testing.T) {
	t.Run("armed_crash", func(t *testing.T) {
		r, f, exec, now, _ := restartFixture(t, "unattended")
		x := r.active
		x.Phase = "armed"
		x.BeforeBootID = r.boot
		if r.journal.save() != nil {
			t.Fatal("save")
		}
		j, e := loadRestartJournal(r.journal.store, r.agent)
		if e != nil {
			t.Fatal(e)
		}
		r.journal = j
		r.active = j.Entries[x.Command.AttemptUUID]
		if r.active.Result.Status != "uncertain" || r.active.Result.MutationStarted {
			t.Fatal("armed crash did not retain preAPI uncertainty")
		}
		*now = now.Add(time.Minute)
		r.tick(t.Context())
		if f.claims != 0 || exec.calls != 0 {
			t.Fatal("armed crash repeated claim/API")
		}
	})
	t.Run("claim_clockjump", func(t *testing.T) {
		r, f, exec, now, mono := restartFixture(t, "unattended")
		f.claimHook = func() { *now = now.Add(3 * time.Second) }
		advanceRestart(r, now, mono, 60)
		if exec.calls != 0 || r.active.Result.ReasonCode != "clock_changed" {
			t.Fatal("clock jump during claim reached API")
		}
	})
	t.Run("disk_before_api", func(t *testing.T) {
		r, f, exec, now, mono := restartFixture(t, "unattended")
		f.claimHook = func() { r.journal.store.writeProtected = func(string, []byte) error { return errors.New("disk") } }
		advanceRestart(r, now, mono, 60)
		if exec.calls != 0 || r.ready {
			t.Fatal("API entered without durable marker")
		}
	})
}
func TestRestartSignedContractRejectsUnboundScheduleAndClaim(t *testing.T) {
	r, f, _, now, _ := restartFixture(t, "attended")
	c := r.active.Command
	for _, mutate := range []func(*RestartCommand){func(c *RestartCommand) { c.Parameters.ForceClose = true }, func(c *RestartCommand) { c.Parameters.WarningSeconds = 59 }, func(c *RestartCommand) { c.Policy.Enabled = false }, func(c *RestartCommand) { c.Capability = "device.reboot" }, func(c *RestartCommand) { c.Parameters.ExecuteAt = now.Add(25 * time.Hour).Format(time.RFC3339) }, func(c *RestartCommand) { c.ExpiresAt = c.Parameters.ExecuteAt }} {
		bad := c
		mutate(&bad)
		if _, e := verifyRestartCommand(remoteEnvelope(t, bad), r.key, r.agent, *now); e == nil {
			t.Fatal("invalid signed schedule accepted")
		}
	}
	raw, _ := base64DecodeRestart(f.command.Payload)
	var m map[string]any
	_ = json.Unmarshal(raw, &m)
	delete(m["parameters"].(map[string]any), "force_close")
	if _, e := verifyRestartCommand(remoteEnvelope(t, m), r.key, r.agent, *now); e == nil {
		t.Fatal("missing false field accepted")
	}
}
func base64DecodeRestart(v string) ([]byte, error) { return base64.StdEncoding.DecodeString(v) }

func exportRestartView(t *testing.T, v RestartView, suffix string) {
	t.Helper()
	if path := os.Getenv("OBLI_RESTART_LOCAL_VIEW"); path != "" {
		b, _ := json.Marshal(v)
		if e := os.WriteFile(path+suffix, b, 0600); e != nil {
			t.Fatal(e)
		}
	}
}

func TestRestartWarningFloorAndDurableRetirement(t *testing.T) {
	r, _, exec, now, mono := restartFixture(t, "unattended")
	r.acceptedSince = r.monotonic().Add(10 * time.Second)
	advanceRestart(r, now, mono, 60)
	if exec.calls != 0 || r.active.Result.ReasonCode != "warning_incomplete" {
		t.Fatal("late schedule skipped warning")
	}
	r.tick(t.Context())
	x := r.active
	expiry, _ := restartTime(x.Command.ExpiresAt)
	*now = now.Add(5 * time.Minute)
	if r.journal.retire(*now) != nil {
		t.Fatal("retirement")
	}
	if len(r.journal.Entries) != 0 || r.journal.RetiredBefore != expiry.Format(time.RFC3339) {
		t.Fatal("terminal expiry watermark missing")
	}
	*now = expiry.Add(-time.Second)
	r.active = nil
	r.nextPoll = time.Time{}
	r.tick(t.Context())
	if len(r.journal.Entries) != 0 || exec.calls != 0 {
		t.Fatal("rollback revived retired schedule")
	}
}

func TestRestartSharedMutationGate(t *testing.T) {
	t.Run("SCM_busy_blocks_restart", func(t *testing.T) {
		r, _, exec, now, mono := restartFixture(t, "unattended")
		r.mutationGate.Lock()
		advanceRestart(r, now, mono, 60)
		if exec.calls != 0 || r.active.Result.ReasonCode != "operation_failed" {
			t.Fatal("restart bypassed SCM gate")
		}
		r.mutationGate.Unlock()
		if !r.mutationGate.TryLock() {
			t.Fatal("gate leaked on busy refusal")
		}
		r.mutationGate.Unlock()
	})
	t.Run("restart_blocks_SCM_until_exact_boot_ACK", func(t *testing.T) {
		r, _, exec, now, mono := restartFixture(t, "unattended")
		advanceRestart(r, now, mono, 60)
		if exec.calls != 1 || r.mutationGate.TryLock() {
			t.Fatal("restart awaitingboot did not latch gate")
		}
		newBoot := "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee"
		obs := r.heartbeat(newBoot, now.Add(time.Second).Format(time.RFC3339))
		payload, _ := json.Marshal(map[string]any{"boot_id": newBoot, "observed_at": now.Add(time.Second).Format(time.RFC3339), "restart_observations": obs})
		r.acknowledge(payload, nil)
		if r.mutationGate.TryLock() {
			t.Fatal("ordinary boot ACK released bound gate")
		}
		a := RestartAcceptance{r.active.Command.CommandUUID, r.active.Command.AttemptUUID, r.active.BeforeBootID, newBoot, "succeeded"}
		r.acknowledge(payload, []RestartAcceptance{a})
		if !r.mutationGate.TryLock() {
			t.Fatal("exact changedboot ACK retained physical mutation gate")
		}
		r.mutationGate.Unlock()
	})
	t.Run("known_refusal_releases", func(t *testing.T) {
		r, _, exec, now, mono := restartFixture(t, "unattended")
		exec.fail = true
		advanceRestart(r, now, mono, 60)
		if !r.mutationGate.TryLock() {
			t.Fatal("known refusal leaked gate")
		}
		r.mutationGate.Unlock()
	})
}
func TestRestartGateActuallyBlocksServiceExecutor(t *testing.T) {
	restart, _, _, restartNow, mono := restartFixture(t, "unattended")
	advanceRestart(restart, restartNow, mono, 60)
	c, now := serviceFixture()
	c.Policy.ConsentMode = "unattended"
	journal, e := loadServiceJournal(restart.journal.store, c.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	backend := &fakeServiceBackend{handle: &fakeServiceHandle{state: "running"}}
	transport := &fakeServiceTransport{t: t, envelope: remoteEnvelope(t, c), command: c, now: now}
	r := newServiceActionController(func(RemotePeer) bool { return true }, boundedServiceExecutor{backend})
	r.mutationGate = restart.mutationGate
	r.journal = journal
	r.key, _ = testKey()
	r.agent = c.AgentUUID
	r.origin = "https://localhost"
	r.transport = transport
	r.ready = true
	r.clock = func() time.Time { return now }
	r.tick(t.Context())
	r.tick(t.Context())
	if len(backend.handle.calls) != 0 || r.active.Result.ReasonCode != "service_busy" {
		t.Fatal("SCM executor bypassed pending reboot gate")
	}
}

type blockingRestartExecutor struct{ entered, release chan struct{} }

func (b *blockingRestartExecutor) Restart(allowed func() bool) (bool, error) {

	if !allowed() {
		return false, errServiceAction
	}
	close(b.entered)
	<-b.release
	return true, nil
}
func TestRestartCancellationAfterAPIEntryCannotClaimCancellation(t *testing.T) {
	r, _, _, now, mono := restartFixture(t, "unattended")
	peer := RemotePeer{"S-user", "S-logon", 1}
	origin, _ := CanonicalOriginHash(r.origin)
	view, e := r.Handle(peer, RestartDecision{Operation: "restart_poll", OriginHash: origin})
	if e != nil {
		t.Fatal(e)
	}
	block := &blockingRestartExecutor{make(chan struct{}), make(chan struct{})}
	r.executor = block
	advanceRestart(r, now, mono, 59)
	*now = now.Add(time.Second)
	*mono = mono.Add(time.Second)
	done := make(chan struct{})
	go func() { r.tick(context.Background()); close(done) }()
	select {
	case <-block.entered:
	case <-time.After(time.Second):
		close(block.release)
		t.Fatal("API not entered")
	}
	reply := make(chan RestartView, 1)
	go func() {
		v, _ := r.Handle(peer, RestartDecision{Operation: "restart_decide", OriginHash: origin, AttemptUUID: view.AttemptUUID, PayloadHash: view.PayloadHash, ConsentToken: view.ConsentToken, Decision: "cancel"})
		reply <- v
	}()
	close(block.release)
	select {
	case v := <-reply:
		if v.Status != "awaiting_boot" || !v.MutationStarted || v.CanCancel {
			t.Fatal("late cancellation falsely acknowledged")
		}
	case <-time.After(time.Second):
		t.Fatal("cancel did not finish")
	}
	<-done
}

func TestRestartRestoredJournalGateBeforeFirstHeartbeat(t *testing.T) {
	r, _, exec, _, _ := restartFixture(t, "unattended")
	x := r.active
	x.BeforeBootID = r.boot
	x.Phase = "api_entered"
	if r.journal.save() != nil {
		t.Fatal("save")
	}
	j, e := loadRestartJournal(r.journal.store, r.agent)
	if e != nil {
		t.Fatal(e)
	}
	r.journal = j
	r.active = j.Entries[x.Command.AttemptUUID]
	r.mu.Lock()
	latched := r.latchRestoredGateLocked()
	r.mu.Unlock()
	if !latched || r.mutationGate.TryLock() || exec.calls != 0 {
		t.Fatal("restored API uncertainty lacked preheartbeat exclusion")
	}
	r.acknowledge([]byte(`{"boot_id":"dddddddd-dddd-4ddd-8ddd-dddddddddddd","observed_at":"2026-10-09T01:00:00Z"}`), nil)
	if r.mutationGate.TryLock() {
		t.Fatal("same boot ordinary ACK released startup exclusion")
	}
}

func TestRestartReleaseWithdrawalKeepsRecoveryWithoutExecution(t *testing.T) {
	for _, phase := range []string{"countdown", "awaiting_boot"} {
		t.Run(phase, func(t *testing.T) {
			old, _, _, now, mono := restartFixture(t, "unattended")
			if phase == "awaiting_boot" {
				advanceRestart(old, now, mono, 60)
			}
			j, e := loadRestartJournal(old.journal.store, old.agent)
			if e != nil {
				t.Fatal(e)
			}
			exec := &fakeRestartExecutor{}
			r := newRestartController(func(RemotePeer) bool { return true }, exec)
			r.executionEnabled = false
			r.journal = j
			r.active = j.Entries[old.active.Command.AttemptUUID]
			r.agent = old.agent
			r.origin = old.origin
			r.key = old.key
			r.ready = true
			r.clock = func() time.Time { return *now }
			r.monotonic = func() time.Time { return *mono }
			r.connected = func() bool { return true }
			transport := &fakeRestartTransport{t: t, r: r, command: remoteEnvelope(t, r.active.Command)}
			r.transport = transport
			r.mu.Lock()
			latched := r.latchRestoredGateLocked()
			r.mu.Unlock()
			if !latched || r.executionAvailable() {
				t.Fatal("disabled release advertised execution")
			}
			r.tick(t.Context())
			if transport.polls != 0 || transport.claims != 0 || exec.calls != 0 || transport.results != 1 {
				t.Fatal("recovery-only worker dispatched new work")
			}
			if phase == "countdown" {
				if r.active.Result.ReasonCode != "policy_unavailable" || r.active.Result.MutationStarted {
					t.Fatal("old preAPI schedule executed under withdrawn release")
				}
			} else {
				if !r.gateHeld || r.mutationGate.TryLock() {
					t.Fatal("withdrawn release lost physical exclusion")
				}
				newBoot := "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee"
				obs := r.heartbeat(newBoot, now.Add(time.Second).Format(time.RFC3339))
				if len(obs) != 1 {
					t.Fatal("withdrawn release lost boot evidence")
				}
				body, _ := json.Marshal(map[string]any{"boot_id": newBoot, "observed_at": now.Add(time.Second).Format(time.RFC3339), "restart_observations": obs})
				ack := RestartAcceptance{r.active.Command.CommandUUID, r.active.Command.AttemptUUID, r.active.BeforeBootID, newBoot, "succeeded"}
				r.acknowledge(body, []RestartAcceptance{ack})
				if r.active.BootStatus != "succeeded" || r.gateHeld {
					t.Fatal("recovery-only accepted boot ACK not retained")
				}
			}
			r.tick(t.Context())
			if transport.polls != 0 || transport.claims != 0 || exec.calls != 0 {
				t.Fatal("replay enabled disabled execution")
			}
		})
	}
}

func TestRestartExecutorPreparationCannotCrossDeadline(t *testing.T) {
	r, _, exec, now, mono := restartFixture(t, "unattended")
	exec.prepare = func() { *now = now.Add(6 * time.Second); *mono = mono.Add(6 * time.Second) }
	advanceRestart(r, now, mono, 60)
	if exec.calls != 0 || r.active.Result.MutationStarted || r.active.Result.ReasonCode != "policy_unavailable" {
		t.Fatal("slow privilege preparation crossed signed API deadline")
	}
	if !r.mutationGate.TryLock() {
		t.Fatal("known preAPI denial retained physical gate")
	}
	r.mutationGate.Unlock()
}

func TestRestartPreparationFailureUsesPinnedNonmutatingReceipt(t *testing.T) {
	r, _, exec, now, mono := restartFixture(t, "unattended")
	exec.prepareError = true
	advanceRestart(r, now, mono, 60)
	if exec.calls != 0 || r.active.Result.Status != "failed" || r.active.Result.MutationStarted || r.active.Result.ReasonCode != "operation_failed" || !validRestartResult(*r.active.Result, r.active.Command, r.active.Hash) {
		t.Fatal("preparation error diverged from pinned PHP receipt")
	}
	if !r.mutationGate.TryLock() {
		t.Fatal("preparation error leaked gate")
	}
	r.mutationGate.Unlock()
	bad := *r.active.Result
	bad.ReasonCode = "restart_refused"
	if validRestartResult(bad, r.active.Command, r.active.Hash) {
		t.Fatal("false APIrefusal receipt accepted")
	}
}
