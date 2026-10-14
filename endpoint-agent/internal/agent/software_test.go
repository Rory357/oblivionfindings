package agent

import (
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"
)

type fakeSoftwareTransport struct {
	command         Envelope
	private         ed25519.PrivateKey
	clock           func() time.Time
	claims, results int
	claimError      error
	resultError     error
	claimHook       func()
	data            []byte
	lastResult      SoftwareResult
}

func (f *fakeSoftwareTransport) Poll(context.Context) (softwarePoll, error) {
	return softwarePoll{&f.command}, nil
}
func (f *fakeSoftwareTransport) Artifact(_ context.Context, c SoftwareCommand, file *os.File) error {
	_, e := file.Write(f.data)
	return e
}
func (f *fakeSoftwareTransport) Claim(_ context.Context, c SoftwareCommand, hash, boot, desktop, nonce string) (Envelope, error) {
	f.claims++
	if f.claimHook != nil {
		f.claimHook()
	}
	if f.claimError != nil {
		return Envelope{}, f.claimError
	}
	now := f.clock()
	x := SoftwareClaim{Version: 1, Type: "endpoint_software_claim", AgentUUID: c.AgentUUID, CommandUUID: c.CommandUUID, AttemptUUID: c.AttemptUUID, AssignmentFingerprint: c.AssignmentFingerprint, PayloadHash: hash, PolicyVersion: c.Policy.Version, ConsentMode: c.Policy.ConsentMode, ArtifactSHA256: c.Parameters.SHA256, BootID: boot, IssuedAt: now.UTC().Format(time.RFC3339), ExpiresAt: now.Add(30 * time.Second).UTC().Format(time.RFC3339)}
	if c.Policy.ConsentMode == "attended" {
		x.DesktopSession = &desktop
		x.ConsentNonce = &nonce
	}
	return signSoftware(f.private, x), nil
}
func (f *fakeSoftwareTransport) Result(_ context.Context, _ SoftwareCommand, x SoftwareResult) error {
	f.results++
	f.lastResult = x
	return f.resultError
}

type fakeSoftwareChild struct {
	code   uint32
	done   bool
	closed int
}

func (c *fakeSoftwareChild) Exit() (uint32, bool, error) { return c.code, c.done, nil }
func (c *fakeSoftwareChild) Close() error                { c.closed++; return nil }

type fakeSoftwareExecutor struct {
	child        *fakeSoftwareChild
	calls        int
	reason       string
	state        string
	version      *string
	busy         bool
	prepare      func()
	startError   error
	enteredError bool
}

func (f *fakeSoftwareExecutor) Verify(file *os.File, c SoftwareCommand) string {
	if f.reason != "" {
		return f.reason
	}
	h := sha256.New()
	_, _ = file.Seek(0, 0)
	n, e := io.Copy(h, file)
	if e != nil || n != c.Parameters.SizeBytes || hex.EncodeToString(h.Sum(nil)) != c.Parameters.SHA256 {
		return "artifact_invalid"
	}
	return ""
}
func (f *fakeSoftwareExecutor) Start(_ *os.File, _ SoftwareCommand, persist func(softwareProcessIdentity) error, allowed func() bool) (softwareChild, bool, error) {
	f.calls++
	if f.prepare != nil {
		f.prepare()
	}
	if !allowed() {
		return nil, false, errors.New("denied")
	}
	if f.startError != nil {
		return nil, f.enteredError, f.startError
	}
	if e := persist(softwareProcessIdentity{123, 456}); e != nil {
		return nil, false, e
	}
	return f.child, true, nil
}
func (f *fakeSoftwareExecutor) Observe(string) (string, *string, error) {
	return f.state, f.version, nil
}
func (f *fakeSoftwareExecutor) Quiescent(*softwareProcessIdentity) (bool, error) { return !f.busy, nil }
func signSoftware(key ed25519.PrivateKey, v any) Envelope {
	b, _ := json.Marshal(v)
	return Envelope{base64.StdEncoding.EncodeToString(b), base64.StdEncoding.EncodeToString(ed25519.Sign(key, b))}
}
func softwareFixture(t *testing.T) (*SoftwareController, *fakeSoftwareExecutor, *fakeSoftwareTransport, *time.Time) {
	t.Helper()
	now := time.Date(2026, 10, 8, 0, 0, 0, 0, time.UTC)
	public, key, _ := ed25519.GenerateKey(nil)
	data := []byte("isolated fake MSI bytes")
	sum := sha256.Sum256(data)
	c := SoftwareCommand{Version: 1, Type: "endpoint_software_install", AgentUUID: "11111111-1111-4111-8111-111111111111", CommandUUID: "22222222-2222-4222-8222-222222222222", AttemptUUID: "33333333-3333-4333-8333-333333333333", AssignmentFingerprint: strings.Repeat("a", 64), Capability: "endpoint.software.install", Parameters: SoftwareParameters{PackageUUID: "44444444-4444-4444-8444-444444444444", PackageVersion: 1, SHA256: hex.EncodeToString(sum[:]), SizeBytes: int64(len(data)), SignerSHA256: strings.Repeat("b", 64), ProductCode: "{55555555-5555-4555-8555-555555555555}", ProductVersion: "4.2.1", Architecture: "amd64", DisplayName: "Approved tool", PublisherLabel: "Reviewed publisher"}, Policy: RestartPolicy{1, true, "attended"}, Technician: "Technician", Purpose: "Install approved tool", IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(time.Hour).Format(time.RFC3339)}
	store, e := OpenStore(t.TempDir())
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { store.Close() })
	journal, e := loadSoftwareJournal(store, c.AgentUUID)
	if e != nil {
		t.Fatal(e)
	}
	f := &fakeSoftwareExecutor{child: &fakeSoftwareChild{}, state: "absent"}
	http := &fakeSoftwareTransport{command: signSoftware(key, c), private: key, clock: func() time.Time { return now }, data: data}
	r := newSoftwareController(func(RemotePeer) bool { return true }, f)
	r.journal = journal
	r.agent = c.AgentUUID
	r.origin = "https://localhost"
	r.key = public
	r.boot = "66666666-6666-4666-8666-666666666666"
	r.bootAt = now
	r.ready = true
	r.clock = func() time.Time { return now }
	r.connected = func() bool { return true }
	r.transport = http
	t.Cleanup(func() {
		r.mu.Lock()
		r.closeArtifactLocked()
		if r.child != nil {
			r.child.Close()
			r.child = nil
		}
		if r.gateHeld {
			r.mutationGate.Unlock()
			r.gateHeld = false
		}
		r.mu.Unlock()
	})
	return r, f, http, &now
}
func softwareAllow(t *testing.T, r *SoftwareController) {
	t.Helper()
	hash, _ := CanonicalOriginHash(r.origin)
	peer := RemotePeer{"S-user", "S-logon", 1}
	v, e := r.Handle(peer, SoftwareDecision{Operation: "software_poll", OriginHash: hash})
	if e != nil || v.Status != "requested" || v.ConsentToken == "" {
		t.Fatal("requested", v, e)
	}
	_, e = r.Handle(peer, SoftwareDecision{Operation: "software_decide", OriginHash: hash, AttemptUUID: v.AttemptUUID, PayloadHash: v.PayloadHash, ConsentToken: v.ConsentToken, Decision: "allow"})
	if e != nil {
		t.Fatal(e)
	}
}
func TestSoftwareKnownExitAndIndependentEvidence(t *testing.T) {
	for _, code := range []uint32{0, 3010, 1641, 1618, 1603, 259} {
		t.Run(strconv.FormatUint(uint64(code), 10), func(t *testing.T) {
			r, f, h, now := softwareFixture(t)
			r.tick(t.Context())
			softwareAllow(t, r)
			r.tick(t.Context())
			if f.calls != 1 || h.claims != 1 || r.active.Phase != "running" || r.active.Process == nil {
				t.Fatal("launch")
			}
			*now = now.Add(2 * time.Hour)
			r.tick(t.Context())
			if r.active.Phase != "running" {
				t.Fatal("expired lease killed installer")
			}
			f.child.code = code
			f.child.done = true
			r.tick(t.Context())
			r.tick(t.Context())
			x := r.active.Result
			if !validSoftwareResult(*x, r.active.Command, r.active.Hash) || !x.MutationStarted || x.ExitCode == nil || *x.ExitCode != code || !r.active.Reported || h.results != 1 || !r.gateHeld {
				t.Fatal("known receipt", x)
			}
			version := "4.2.1"
			f.state = "installed"
			f.version = &version
			f.busy = true
			observed := now.Format(time.RFC3339)
			rows := r.observations(r.boot, observed)
			payload, _ := json.Marshal(map[string]any{"boot_id": r.boot, "software_observations": rows})
			a := SoftwareAcceptance{rows[0], "uncertain"}
			if validateSoftwareACK(payload, []SoftwareAcceptance{a}) == nil {
				t.Fatal("busy accepted")
			}
			f.busy = false
			if code == 1641 {
				r.boot = "77777777-7777-4777-8777-777777777777"
			}
			rows = r.observations(r.boot, observed)
			payload, _ = json.Marshal(map[string]any{"boot_id": r.boot, "software_observations": rows})
			a = SoftwareAcceptance{rows[0], "uncertain"}
			if code == 0 || code == 3010 {
				a.Status = "succeeded"
			}
			r.acknowledge(payload, []SoftwareAcceptance{a})
			if r.gateHeld || r.active.ObservationStatus != a.Status {
				t.Fatal("independent ACK not durable")
			}
			r.tick(t.Context())
			if f.calls != 1 || h.claims != 1 {
				t.Fatal("repeated")
			}
		})
	}
}
func TestSoftwareLostClaimAndConsentBoundary(t *testing.T) {
	t.Run("lost claim", func(t *testing.T) {
		r, f, h, _ := softwareFixture(t)
		r.tick(t.Context())
		softwareAllow(t, r)
		h.claimError = errors.New("lost")
		r.tick(t.Context())
		if r.active.Result.Status != "uncertain" || r.active.Result.ReasonCode != "state_unavailable" || r.active.Result.MutationStarted || r.active.Result.ExitCode != nil || r.active.Result.RebootRequired || !validSoftwareResult(*r.active.Result, r.active.Command, r.active.Hash) || f.calls != 0 {
			t.Fatal("unknown claim repeated")
		}
		r.tick(t.Context())
		if h.claims != 1 {
			t.Fatal("claim repeated")
		}
	})
	t.Run("lock during claim", func(t *testing.T) {
		r, f, h, _ := softwareFixture(t)
		r.tick(t.Context())
		softwareAllow(t, r)
		h.claimHook = r.Invalidate
		r.tick(t.Context())
		if f.calls != 0 || r.active.Result.MutationStarted {
			t.Fatal("revoked consent launched")
		}
	})
	t.Run("expiry during preparation", func(t *testing.T) {
		r, f, _, now := softwareFixture(t)
		r.tick(t.Context())
		softwareAllow(t, r)
		f.prepare = func() { *now = now.Add(time.Minute) }
		r.tick(t.Context())
		if r.active.Result.MutationStarted || r.gateHeld {
			t.Fatal("expired preparation launched")
		}
	})
	t.Run("gate busy", func(t *testing.T) {
		r, f, _, _ := softwareFixture(t)
		r.tick(t.Context())
		softwareAllow(t, r)
		r.mutationGate.Lock()
		r.tick(t.Context())
		r.mutationGate.Unlock()
		if f.calls != 0 || r.active.Result.MutationStarted {
			t.Fatal("shared mutation bypass")
		}
	})
}
func TestSoftwareCrashRecoveryAndJournalTampering(t *testing.T) {
	for _, phase := range []string{"claiming", "entered", "running"} {
		t.Run(phase, func(t *testing.T) {
			r, _, _, _ := softwareFixture(t)
			r.tick(t.Context())
			r.active.Phase = phase
			if phase == "running" {
				r.active.Process = &softwareProcessIdentity{123, 456}
			}
			if r.journal.save() != nil {
				t.Fatal("save")
			}
			j, e := loadSoftwareJournal(r.journal.store, r.agent)
			if e != nil {
				t.Fatal(e)
			}
			x := j.Entries[r.active.Command.AttemptUUID]
			if x.Phase != "terminal" || x.Result.Status != "uncertain" || x.Result.ReasonCode != "interrupted" || x.Result.MutationStarted != (phase != "claiming") || x.Result.ExitCode != nil || x.Result.RebootRequired || !validSoftwareResult(*x.Result, x.Command, x.Hash) {
				t.Fatal("unsafe crash recovery")
			}
		})
	}
	t.Run("missing boolean", func(t *testing.T) {
		r, _, _, _ := softwareFixture(t)
		r.tick(t.Context())
		_ = r.finishLocked(r.active, "failed", "operation_failed", false, nil, false)
		b, _ := json.Marshal(r.journal)
		b = []byte(strings.Replace(string(b), `"mutation_started":false,`, "", 1))
		if r.journal.store.atomicWrite("software-actions.json", b) != nil {
			t.Fatal("write")
		}
		if _, e := loadSoftwareJournal(r.journal.store, r.agent); e == nil {
			t.Fatal("accepted missing boolean")
		}
	})
}
func TestSoftwareCommandAndClaimClosedAuthority(t *testing.T) {
	r, _, h, now := softwareFixture(t)
	c, e := verifySoftwareCommand(h.command, r.key, r.agent, *now)
	if e != nil {
		t.Fatal(e)
	}
	var m map[string]any
	b, _ := base64.StdEncoding.DecodeString(h.command.Payload)
	_ = json.Unmarshal(b, &m)
	m["url"] = "https://foreign"
	if _, e := verifySoftwareCommand(signSoftware(h.private, m), r.key, r.agent, *now); e == nil {
		t.Fatal("unknown field")
	}
	for _, v := range []string{"4.2.1.0", "04.2.1", "256.0.0", "1.0.65536"} {
		if _, ok := softwareVersion(v); ok {
			t.Fatal("bad MSI version", v)
		}
	}
	eclaim, _ := h.Claim(t.Context(), c, serviceHash(h.command), r.boot, "77777777-7777-4777-8777-777777777777", strings.Repeat("c", 64))
	if _, e = verifySoftwareClaim(eclaim, r.key, c, strings.Repeat("d", 64), r.boot, "77777777-7777-4777-8777-777777777777", strings.Repeat("c", 64), *now); e == nil {
		t.Fatal("crosspayload claim")
	}
}
func TestSoftwarePreflightAndDecline(t *testing.T) {
	for _, reason := range []string{"signature_invalid", "product_mismatch", "architecture_mismatch", "artifact_invalid"} {
		t.Run(reason, func(t *testing.T) {
			r, f, h, _ := softwareFixture(t)
			f.reason = reason
			r.tick(t.Context())
			if r.active.Result == nil || r.active.Result.ReasonCode != reason || f.calls != 0 || h.claims != 0 {
				t.Fatal("preflight bypass")
			}
		})
	}
	t.Run("equal installed", func(t *testing.T) {
		r, f, h, _ := softwareFixture(t)
		v := "4.2.1"
		f.state = "installed"
		f.version = &v
		r.tick(t.Context())
		if r.active.Result.ReasonCode != "already_installed" || h.claims != 0 {
			t.Fatal("reinstall")
		}
	})
	t.Run("decline", func(t *testing.T) {
		r, f, h, _ := softwareFixture(t)
		r.tick(t.Context())
		origin, _ := CanonicalOriginHash(r.origin)
		peer := RemotePeer{"S-user", "S-logon", 1}
		v, e := r.Handle(peer, SoftwareDecision{Operation: "software_poll", OriginHash: origin})
		if e != nil {
			t.Fatal(e)
		}
		_, e = r.Handle(peer, SoftwareDecision{Operation: "software_decide", OriginHash: origin, AttemptUUID: v.AttemptUUID, PayloadHash: v.PayloadHash, ConsentToken: v.ConsentToken, Decision: "decline"})
		if e != nil || r.active.Result.Status != "declined" {
			t.Fatal("decline")
		}
		r.tick(t.Context())
		if h.claims != 0 || f.calls != 0 || !r.active.Reported {
			t.Fatal("decline executed")
		}
	})
}

func TestSoftwareRebootNoticeRetention(t *testing.T) {
	r, f, _, now := softwareFixture(t)
	r.tick(t.Context())
	softwareAllow(t, r)
	r.tick(t.Context())
	f.child.code = 3010
	f.child.done = true
	r.tick(t.Context())
	r.tick(t.Context())
	version := "4.2.1"
	f.state = "installed"
	f.version = &version
	rows := r.observations(r.boot, now.Format(time.RFC3339))
	payload, _ := json.Marshal(map[string]any{"boot_id": r.boot, "software_observations": rows})
	r.acknowledge(payload, []SoftwareAcceptance{{rows[0], "succeeded"}})
	if r.gateHeld || !r.viewLocked().RebootRequired {
		t.Fatal("rebootnotice mistaken for physicalgate")
	}
	*now = now.Add(2 * time.Hour)
	if r.journal.retire(*now) != nil || len(r.journal.Entries) != 1 {
		t.Fatal("pendingreboot retired")
	}
	r.active = nil
	if !r.viewLocked().RebootRequired {
		t.Fatal("notice lost whenidle")
	}
	newboot := "77777777-7777-4777-8777-777777777777"
	rows = r.observations(newboot, now.Format(time.RFC3339))
	if len(rows) != 1 {
		t.Fatal("changedboot notsampled")
	}
	payload, _ = json.Marshal(map[string]any{"boot_id": newboot, "software_observations": rows})
	r.acknowledge(payload, []SoftwareAcceptance{{rows[0], "succeeded"}})
	if !r.journal.Entries[rows[0].AttemptUUID].RebootAcknowledged {
		t.Fatal("changedboot not persisted")
	}
	if r.journal.retire(*now) != nil || len(r.journal.Entries) != 0 {
		t.Fatal("eligible terminal notretired")
	}
	if _, e := os.Stat(r.journal.store.dir + string(os.PathSeparator) + "software-" + rows[0].AttemptUUID + ".msi"); !os.IsNotExist(e) {
		t.Fatal("retired artifact retained")
	}
	*now = now.Add(-2 * time.Hour)
	r.tick(t.Context())
	if len(r.journal.Entries) != 0 {
		t.Fatal("clockrollback resurrected command")
	}
}
func TestSoftwareRecoveryOnlyStartup(t *testing.T) {
	for _, phase := range []string{"requested", "running"} {
		t.Run(phase, func(t *testing.T) {
			r, f, h, _ := softwareFixture(t)
			r.tick(t.Context())
			r.closeArtifactLocked()
			r.active.Phase = phase
			if phase == "running" {
				r.active.Process = &softwareProcessIdentity{123, 456}
			}
			if r.journal.save() != nil {
				t.Fatal("save")
			}
			fallback := &Store{dir: r.journal.store.dir}
			fallback.writeProtected = nil
			r.journal.store.writeProtected = fallback.atomicWrite
			r.journal.store.validateProtected = func(string) error { return nil }
			public := base64.StdEncoding.EncodeToString(r.key)
			client, e := NewClient("https://localhost", public)
			if e != nil {
				t.Fatal(e)
			}
			client.http = &http.Client{Transport: softwareNoNetwork{}}
			recovered := newSoftwareController(func(RemotePeer) bool { return true }, f)
			recovered.executionEnabled = false
			ctx, cancel := context.WithCancel(t.Context())
			defer cancel()
			stop, e := recovered.bind(ctx, r.journal.store, client, State{AgentUUID: r.agent, Credential: strings.Repeat("a", 64)}, func() bool { return true })
			if e != nil {
				t.Fatal(e)
			}
			cancel()
			stop()
			if phase == "running" && !recovered.gateHeld {
				t.Fatal("startup didn't latch enteredprocess")
			}
			if phase == "requested" && recovered.gateHeld {
				t.Fatal("preAPI falsely latched")
			}
			if recovered.gateHeld {
				recovered.mutationGate.Unlock()
			}
			if h.claims != 0 || f.calls != 0 || recovered.available() {
				t.Fatal("disabled worker executed")
			}
		})
	}
}

func TestSoftwareClaimCannotExtendByClockRollback(t *testing.T) {
	for _, mode := range []string{"rollback", "frozenwall"} {
		t.Run(mode, func(t *testing.T) {
			r, f, _, now := softwareFixture(t)
			mono := *now
			r.monotonic = func() time.Time { return mono }
			r.tick(t.Context())
			softwareAllow(t, r)
			f.prepare = func() {
				if mode == "rollback" {
					*now = now.Add(-5 * time.Second)
				} else {
					mono = mono.Add(31 * time.Second)
				}
			}
			r.tick(t.Context())
			if r.active.Result == nil || r.active.Result.MutationStarted || r.gateHeld {
				t.Fatal("clock extended launchlease")
			}
		})
	}
}

type softwareNoNetwork struct{}

func (softwareNoNetwork) RoundTrip(*http.Request) (*http.Response, error) {
	return nil, context.Canceled
}
func TestSoftwareGateActuallyBlocksOtherExecutors(t *testing.T) {
	t.Run("SCM", func(t *testing.T) {
		software, _, _, _ := softwareFixture(t)
		software.tick(t.Context())
		softwareAllow(t, software)
		software.tick(t.Context())
		c, now := serviceFixture()
		c.Policy.ConsentMode = "unattended"
		journal, e := loadServiceJournal(software.journal.store, c.AgentUUID)
		if e != nil {
			t.Fatal(e)
		}
		backend := &fakeServiceBackend{handle: &fakeServiceHandle{state: "running"}}
		transport := &fakeServiceTransport{t: t, envelope: remoteEnvelope(t, c), command: c, now: now}
		r := newServiceActionController(func(RemotePeer) bool { return true }, boundedServiceExecutor{backend})
		r.mutationGate = software.mutationGate
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
			t.Fatal("SCM bypassed activeMSI")
		}
	})
	t.Run("restart", func(t *testing.T) {
		software, _, _, _ := softwareFixture(t)
		software.tick(t.Context())
		softwareAllow(t, software)
		software.tick(t.Context())
		r, _, executor, now, mono := restartFixture(t, "unattended")
		r.mutationGate = software.mutationGate
		advanceRestart(r, now, mono, 60)
		if executor.calls != 0 || r.active.Result.ReasonCode != "operation_failed" {
			t.Fatal("restart bypassed activeMSI")
		}
	})
}

func TestSoftwareAdvertisedVersionProjection(t *testing.T) {
	for _, row := range [][2]string{{"0", "0.0.0"}, {"67239937", "4.2.1"}, {"4294967295", "255.255.65535"}} {
		v, ok := softwarePackedVersion(row[0])
		if !ok || v != row[1] {
			t.Fatal("packed MSI advertisedversion", row, v)
		}
	}
	for _, bad := range []string{"-1", "4294967296", "04", "4.2.1"} {
		if _, ok := softwarePackedVersion(bad); ok {
			t.Fatal("invalidpackedversion")
		}
	}
}

func TestSoftware3010ChangedBootRequiresExactInstalledProduct(t *testing.T) {
	for _, state := range []string{"absent", "advertised", "wrong_version"} {
		for _, initialSuccess := range []bool{false, true} {
			name := state
			if initialSuccess {
				name += "_after_success"
			}
			t.Run(name, func(t *testing.T) {
				r, f, _, now := softwareFixture(t)
				r.tick(t.Context())
				softwareAllow(t, r)
				r.tick(t.Context())
				f.child.code = 3010
				f.child.done = true
				r.tick(t.Context())
				r.tick(t.Context())
				desired := "4.2.1"
				f.state = "installed"
				f.version = &desired
				if initialSuccess {
					rows := r.observations(r.boot, now.Format(time.RFC3339))
					payload, _ := json.Marshal(map[string]any{"boot_id": r.boot, "software_observations": rows})
					r.acknowledge(payload, []SoftwareAcceptance{{rows[0], "succeeded"}})
				}
				newboot := "77777777-7777-4777-8777-777777777777"
				switch state {
				case "absent":
					f.state = "absent"
					f.version = nil
				case "advertised":
					f.state = "advertised"
				case "wrong_version":
					other := "4.2.0"
					f.version = &other
				}
				rows := r.observations(newboot, now.Add(time.Second).Format(time.RFC3339))
				if len(rows) != 1 {
					t.Fatal("pending notice not observed")
				}
				payload, _ := json.Marshal(map[string]any{"boot_id": newboot, "software_observations": rows})
				r.acknowledge(payload, []SoftwareAcceptance{{rows[0], "uncertain"}})
				if r.gateHeld || r.active.RebootAcknowledged || !r.viewLocked().RebootRequired {
					t.Fatal("quiescence erased product-bound restart notice")
				}
				*now = now.Add(2 * time.Hour)
				if r.journal.retire(*now) != nil || len(r.journal.Entries) != 1 {
					t.Fatal("unproved notice retired")
				}
				f.state = "installed"
				f.version = &desired
				rows = r.observations(newboot, now.Format(time.RFC3339))
				if len(rows) != 1 {
					t.Fatal("exact product not resampled")
				}
				payload, _ = json.Marshal(map[string]any{"boot_id": newboot, "software_observations": rows})
				r.acknowledge(payload, []SoftwareAcceptance{{rows[0], "succeeded"}})
				v := r.viewLocked()
				if !r.active.RebootAcknowledged || v.RebootRequired || v.Status != "succeeded" || v.ReasonCode != "installed" {
					t.Fatal("exact changedboot product did not clear notice")
				}
			})
		}
	}
}
func TestSoftware1641ChangedBootRemainsUncertain(t *testing.T) {
	r, f, _, now := softwareFixture(t)
	r.tick(t.Context())
	softwareAllow(t, r)
	r.tick(t.Context())
	f.child.code = 1641
	f.child.done = true
	r.tick(t.Context())
	r.tick(t.Context())
	f.state = "absent"
	f.version = nil
	boot := "77777777-7777-4777-8777-777777777777"
	rows := r.observations(boot, now.Add(time.Second).Format(time.RFC3339))
	payload, _ := json.Marshal(map[string]any{"boot_id": boot, "software_observations": rows})
	r.acknowledge(payload, []SoftwareAcceptance{{rows[0], "uncertain"}})
	v := r.viewLocked()
	if r.gateHeld || !r.active.RebootAcknowledged || v.RebootRequired || v.Status != "uncertain" || v.ReasonCode != "restart_initiated" {
		t.Fatal("1641 falsely attributed successful installation")
	}
}
