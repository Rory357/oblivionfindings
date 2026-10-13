package agent

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type SoftwareView struct {
	Protocol        int    `json:"protocol"`
	ServerOrigin    string `json:"server_origin"`
	Status          string `json:"status"`
	CommandUUID     string `json:"command_uuid"`
	AttemptUUID     string `json:"attempt_uuid"`
	PayloadHash     string `json:"payload_hash"`
	ConsentToken    string `json:"consent_token"`
	PolicyVersion   int64  `json:"policy_version"`
	ConsentMode     string `json:"consent_mode"`
	Technician      string `json:"technician"`
	Purpose         string `json:"purpose"`
	PackageName     string `json:"package_name"`
	PackageVersion  int64  `json:"package_version"`
	ProductVersion  string `json:"product_version"`
	Publisher       string `json:"publisher"`
	Architecture    string `json:"architecture"`
	SizeBytes       int64  `json:"size_bytes"`
	ExpiresAt       string `json:"expires_at"`
	ReasonCode      string `json:"reason_code"`
	MutationStarted bool   `json:"mutation_started"`
	RebootRequired  bool   `json:"reboot_required"`
	CanDecline      bool   `json:"can_decline"`
}
type SoftwareDecision struct{ Operation, OriginHash, AttemptUUID, PayloadHash, ConsentToken, Decision string }
type softwareChild interface {
	Exit() (uint32, bool, error)
	Close() error
}
type softwareExecutor interface {
	Verify(*os.File, SoftwareCommand) string
	Start(*os.File, SoftwareCommand, func(softwareProcessIdentity) error, func() bool) (softwareChild, bool, error)
	Observe(string) (string, *string, error)
	Quiescent(*softwareProcessIdentity) (bool, error)
}
type SoftwareController struct {
	mu                      sync.Mutex
	mutationGate            *sync.Mutex
	gateHeld                bool
	executionEnabled, ready bool
	check                   func(RemotePeer) bool
	executor                softwareExecutor
	transport               softwareTransport
	journal                 *softwareJournal
	key                     ed25519.PublicKey
	agent, origin, boot     string
	bootAt                  time.Time
	clock                   func() time.Time
	monotonic               func() time.Time
	claimUntilMono          time.Time
	claimIssued             time.Time
	connected               func() bool
	active                  *softwareEntry
	peer                    RemotePeer
	desktop, nonce          string
	allowed                 bool
	nextPoll                time.Time
	artifact                *os.File
	child                   softwareChild
	lastObservations        []SoftwareObservation
	observationCursor       uint64
}

func newSoftwareController(check func(RemotePeer) bool, executor softwareExecutor) *SoftwareController {
	return &SoftwareController{check: check, executor: executor, clock: time.Now, monotonic: time.Now, mutationGate: &sync.Mutex{}, executionEnabled: true}
}
func (r *SoftwareController) Handle(peer RemotePeer, d SoftwareDecision) (SoftwareView, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	origin, e := CanonicalOriginHash(r.origin)
	if e != nil || origin != d.OriginHash || !r.ready || !peer.valid() || !r.check(peer) {
		return SoftwareView{}, errServiceAction
	}
	if r.peer != peer {
		r.invalidateLocked()
		r.peer = peer
	}
	if d.Operation == "software_poll" {
		if r.active != nil && r.active.Phase == "requested" && r.active.Command.Policy.ConsentMode == "attended" && !r.allowed && r.nonce == "" {
			bytes := make([]byte, 32)
			if _, e = rand.Read(bytes); e != nil {
				return SoftwareView{}, e
			}
			r.nonce = hex.EncodeToString(bytes)
			r.desktop = bootUUID(r.nonce)
		}
		return r.viewLocked(), nil
	}
	x := r.active
	if d.Operation != "software_decide" || x == nil || x.Phase != "requested" || r.allowed || d.AttemptUUID != x.Command.AttemptUUID || d.PayloadHash != x.Hash || d.ConsentToken != r.nonce || r.nonce == "" {
		return SoftwareView{}, errServiceAction
	}
	expires, _ := restartTime(x.Command.ExpiresAt)
	if !expires.After(r.clock()) {
		_ = r.finishLocked(x, "expired", "expired", false, nil, false)
		return r.viewLocked(), nil
	}
	switch d.Decision {
	case "allow":
		r.allowed = true
	case "decline":
		if e = r.finishLocked(x, "declined", "declined", false, nil, false); e != nil {
			return SoftwareView{}, e
		}
	default:
		return SoftwareView{}, errServiceAction
	}
	return r.viewLocked(), nil
}
func (r *SoftwareController) invalidateLocked() {
	r.allowed = false
	r.nonce = ""
	r.desktop = ""
	r.peer = RemotePeer{}
}
func (r *SoftwareController) Invalidate() { r.mu.Lock(); defer r.mu.Unlock(); r.invalidateLocked() }
func (r *SoftwareController) viewLocked() SoftwareView {
	v := SoftwareView{Protocol: 1, ServerOrigin: r.origin, Status: "idle"}
	x := r.active
	if x == nil {
		for _, a := range r.journal.Entries {
			if a.Result != nil && a.Result.RebootRequired && !a.RebootAcknowledged {
				x = a
				break
			}
		}
	}
	if x == nil {
		return v
	}
	c, p := x.Command, x.Command.Parameters
	v.CommandUUID = c.CommandUUID
	v.AttemptUUID = c.AttemptUUID
	v.PayloadHash = x.Hash
	v.PolicyVersion = c.Policy.Version
	v.ConsentMode = c.Policy.ConsentMode
	v.Technician = c.Technician
	v.Purpose = c.Purpose
	v.PackageName = p.DisplayName
	v.PackageVersion = p.PackageVersion
	v.ProductVersion = p.ProductVersion
	v.Publisher = p.PublisherLabel
	v.Architecture = p.Architecture
	v.SizeBytes = p.SizeBytes
	v.ExpiresAt = c.ExpiresAt
	switch x.Phase {
	case "staging":
		v.Status = "staging"
		v.ReasonCode = "staging"
	case "requested":
		v.Status = "requested"
		v.ReasonCode = "ready"
		v.ConsentToken = r.nonce
		v.CanDecline = !r.allowed
	case "claiming", "entered", "running":
		v.Status = "installing"
		v.ReasonCode = "installing"
		v.MutationStarted = x.Phase != "claiming"
	case "terminal":
		v.Status = x.Result.Status
		v.ReasonCode = x.Result.ReasonCode
		v.MutationStarted = x.Result.MutationStarted
		v.RebootRequired = x.Result.RebootRequired && !x.RebootAcknowledged
		if x.ObservationStatus == "succeeded" {
			v.Status = "succeeded"
			if x.RebootAcknowledged {
				v.ReasonCode = "installed"
			}
		} else if x.ObservationStatus == "uncertain" && x.Result.Status == "awaiting_observation" {
			v.Status = "uncertain"
			v.ReasonCode = "state_unavailable"
		}
	}
	if x.Phase == "requested" && c.Policy.ConsentMode == "unattended" {
		v.Status = "installing"
		v.ReasonCode = "installing"
		v.ConsentToken = ""
		v.CanDecline = false
	}
	if r.allowed {
		v.ConsentToken = ""
		v.CanDecline = false
		if x.Phase == "requested" {
			v.Status = "installing"
			v.ReasonCode = "installing"
		}
	}
	return v
}
func (r *SoftwareController) finishLocked(x *softwareEntry, status, reason string, started bool, exit *uint32, reboot bool) error {
	x.Phase = "terminal"
	x.Result = &SoftwareResult{CommandUUID: x.Command.CommandUUID, PayloadHash: x.Hash, Status: status, MutationStarted: started, ReasonCode: reason, ExitCode: exit, RebootRequired: reboot, BootID: x.BeforeBootID}
	r.invalidateLocked()
	if e := r.journal.save(); e != nil {
		r.ready = false
		return e
	}
	if !started {
		r.closeArtifactLocked()
		if r.gateHeld {
			r.mutationGate.Unlock()
			r.gateHeld = false
		}
	}
	return nil
}
func (r *SoftwareController) closeArtifactLocked() {
	if r.artifact != nil {
		_ = r.artifact.Close()
		r.artifact = nil
	}
}
func (r *SoftwareController) Run(ctx context.Context) {
	defer func() {
		r.mu.Lock()
		defer r.mu.Unlock()
		r.ready = false
		r.closeArtifactLocked()
		if r.child != nil {
			_ = r.child.Close()
			r.child = nil
		}
	}()
	for ctx.Err() == nil {
		r.tick(ctx)
		if waitFor(ctx, 250*time.Millisecond) != nil {
			return
		}
	}
}
func (r *SoftwareController) tick(ctx context.Context) {
	r.mu.Lock()
	if !r.ready {
		r.mu.Unlock()
		return
	}
	if r.journal.retire(r.clock()) != nil {
		r.ready = false
		r.mu.Unlock()
		return
	}
	x := r.active
	if x != nil && r.journal.Entries[x.Command.AttemptUUID] == nil {
		r.active = nil
		x = nil
	}
	if x != nil && x.Phase != "terminal" && x.Phase != "running" && x.Phase != "entered" {
		expires, _ := restartTime(x.Command.ExpiresAt)
		if !r.executionEnabled {
			_ = r.finishLocked(x, "failed", "policy_unavailable", false, nil, false)
		} else if !expires.After(r.clock()) {
			_ = r.finishLocked(x, "expired", "expired", false, nil, false)
		}
	}
	var pending []*softwareEntry
	for _, a := range r.journal.Entries {
		if a.Phase == "terminal" && !a.Reported {
			pending = append(pending, a)
		}
	}
	poll := r.executionEnabled && (r.active == nil || r.active.Phase == "terminal") && !r.gateHeld && !r.clock().Before(r.nextPoll) && uuidPattern.MatchString(r.boot)
	if poll {
		r.nextPoll = r.clock().Add(2 * time.Second)
	}
	r.mu.Unlock()
	for _, a := range pending {
		call, cancel := context.WithTimeout(ctx, 3*time.Second)
		e := r.transport.Result(call, a.Command, *a.Result)
		cancel()
		if e == nil {
			r.mu.Lock()
			a.Reported = true
			a.ReportedAt = r.clock().UTC().Format(time.RFC3339)
			if r.journal.save() != nil {
				r.ready = false
			}
			r.mu.Unlock()
		}
	}
	if poll {
		call, cancel := context.WithTimeout(ctx, 3*time.Second)
		p, e := r.transport.Poll(call)
		cancel()
		if e == nil && p.Command != nil {
			c, e := verifySoftwareCommand(*p.Command, r.key, r.agent, r.clock())
			if e == nil {
				r.mu.Lock()
				watermark, _ := restartTime(r.journal.RetiredBefore)
				end, _ := restartTime(c.ExpiresAt)
				if r.ready && r.executionEnabled && !r.gateHeld && r.journal.Entries[c.AttemptUUID] == nil && len(r.journal.Entries) < 256 && end.After(watermark) && uuidPattern.MatchString(r.boot) {
					x = &softwareEntry{Command: c, Hash: serviceHash(*p.Command), Phase: "staging", BeforeBootID: r.boot}
					r.journal.Entries[c.AttemptUUID] = x
					r.active = x
					r.invalidateLocked()
					if r.journal.save() != nil {
						r.ready = false
					}
				}
				r.mu.Unlock()
			}
		}
	}
	r.mu.Lock()
	x = r.active
	if !r.ready || x == nil {
		r.mu.Unlock()
		return
	}
	phase := x.Phase
	r.mu.Unlock()
	if phase == "staging" {
		r.stage(ctx, x)
		return
	}
	if phase == "running" {
		r.mu.Lock()
		child := r.child
		r.mu.Unlock()
		if child == nil {
			return
		}
		code, done, e := child.Exit()
		if e != nil {
			r.mu.Lock()
			_ = r.finishLocked(x, "uncertain", "state_unavailable", true, nil, false)
			r.mu.Unlock()
			return
		}
		if done {
			_ = child.Close()
			r.mu.Lock()
			r.child = nil
			r.closeArtifactLocked()
			status, reason, reboot := "failed", "installer_failed", false
			switch code {
			case 0:
				status, reason = "awaiting_observation", "installed"
			case 3010:
				status, reason, reboot = "awaiting_observation", "installed_restart_required", true
			case 1641:
				status, reason, reboot = "uncertain", "restart_initiated", true
			case 1618:
				reason = "installer_busy"
			}
			_ = r.finishLocked(x, status, reason, true, &code, reboot)
			r.mu.Unlock()
		}
		return
	}
	r.mu.Lock()
	if x.Phase != "requested" || !r.executionEnabled || (x.Command.Policy.ConsentMode == "attended" && !r.allowed) {
		r.mu.Unlock()
		return
	}
	c, hash, boot, desktop, nonce := x.Command, x.Hash, r.boot, r.desktop, r.nonce
	if r.connected == nil || !r.connected() || !uuidPattern.MatchString(boot) || r.clock().Sub(r.bootAt) > 360*time.Second || (c.Policy.ConsentMode == "attended" && (!r.peer.valid() || !r.check(r.peer))) {
		_ = r.finishLocked(x, "failed", "consent_lost", false, nil, false)
		r.mu.Unlock()
		return
	}
	x.Phase = "claiming"
	x.BeforeBootID = boot
	if r.journal.save() != nil {
		r.ready = false
		r.mu.Unlock()
		return
	}
	r.mu.Unlock()
	call, cancel := context.WithTimeout(ctx, 3*time.Second)
	envelope, e := r.transport.Claim(call, c, hash, boot, desktop, nonce)
	cancel()
	r.mu.Lock()
	defer r.mu.Unlock()
	if e != nil {
		_ = r.finishLocked(x, "uncertain", "state_unavailable", false, nil, false)
		return
	}
	claim, e := verifySoftwareClaim(envelope, r.key, c, hash, boot, desktop, nonce, r.clock())
	end, _ := restartTime(claim.ExpiresAt)
	r.claimIssued, _ = restartTime(claim.IssuedAt)
	r.claimUntilMono = r.monotonic().Add(end.Sub(r.clock()))
	if e != nil || !r.launchAllowedLocked(ctx, c, boot, end) {
		_ = r.finishLocked(x, "failed", "policy_unavailable", false, nil, false)
		return
	}
	if !r.mutationGate.TryLock() {
		_ = r.finishLocked(x, "failed", "operation_failed", false, nil, false)
		return
	}
	r.gateHeld = true
	x.Phase = "entered"
	if r.journal.save() != nil {
		r.ready = false
		return
	}
	child, entered, e := r.executor.Start(r.artifact, c, func(id softwareProcessIdentity) error { x.Process = &id; return r.journal.save() }, func() bool { return r.launchAllowedLocked(ctx, c, boot, end) })
	if e != nil {
		if entered {
			_ = r.finishLocked(x, "uncertain", "state_unavailable", true, nil, false)
		} else {
			_ = r.finishLocked(x, "failed", "operation_failed", false, nil, false)
		}
		return
	}
	if !entered || child == nil {
		_ = r.finishLocked(x, "uncertain", "state_unavailable", true, nil, false)
		return
	}
	r.child = child
	x.Phase = "running"
	if r.journal.save() != nil {
		r.ready = false
	}
}
func (r *SoftwareController) launchAllowedLocked(ctx context.Context, c SoftwareCommand, boot string, lease time.Time) bool {
	end, _ := restartTime(c.ExpiresAt)
	return ctx.Err() == nil && r.ready && r.executionEnabled && r.connected() != false && r.boot == boot && lease.After(r.clock()) && !r.claimIssued.After(r.clock().Add(time.Second)) && r.monotonic().Before(r.claimUntilMono) && end.After(r.clock()) && (c.Policy.ConsentMode != "attended" || (r.allowed && r.peer.valid() && r.check(r.peer)))
}
func (r *SoftwareController) stage(ctx context.Context, x *softwareEntry) {
	name := "software-" + x.Command.AttemptUUID + ".msi"
	path := filepath.Join(r.journal.store.dir, name)
	if rejectLink(path) != nil {
		r.stageFailed(x, "artifact_invalid")
		return
	}
	if _, e := os.Stat(path); e == nil {
		if r.journal.store.validateProtected != nil && r.journal.store.validateProtected(name) != nil {
			r.stageFailed(x, "artifact_invalid")
			return
		}
	} else {
		f, e := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
		if e != nil {
			r.stageFailed(x, "artifact_unavailable")
			return
		}
		call, cancel := context.WithTimeout(ctx, 5*time.Minute)
		e = r.transport.Artifact(call, x.Command, f)
		cancel()
		closeError := f.Close()
		if e != nil || closeError != nil {
			_ = os.Remove(path)
			r.stageFailed(x, "artifact_unavailable")
			return
		}
	}
	f, e := softwareLockArtifact(path)
	if e != nil {
		r.stageFailed(x, "artifact_invalid")
		return
	}
	if r.journal.store.validateProtected != nil && r.journal.store.validateProtected(name) != nil {
		f.Close()
		r.stageFailed(x, "artifact_invalid")
		return
	}
	reason := r.executor.Verify(f, x.Command)
	if reason != "" {
		f.Close()
		r.stageFailed(x, reason)
		return
	}
	state, version, e := r.executor.Observe(x.Command.Parameters.ProductCode)
	if e != nil || state == "unknown" {
		f.Close()
		r.stageFailed(x, "operation_failed")
		return
	}
	if state == "installed" && version != nil && compareSoftwareVersion(*version, x.Command.Parameters.ProductVersion) >= 0 {
		f.Close()
		reason = "already_installed"
		if compareSoftwareVersion(*version, x.Command.Parameters.ProductVersion) > 0 {
			reason = "downgrade_denied"
		}
		r.stageFailed(x, reason)
		return
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	if !r.ready || x.Phase != "staging" || !r.executionEnabled {
		f.Close()
		return
	}
	expiry, _ := restartTime(x.Command.ExpiresAt)
	if !expiry.After(r.clock()) {
		f.Close()
		_ = r.finishLocked(x, "expired", "expired", false, nil, false)
		return
	}
	r.artifact = f
	x.Phase = "requested"
	if r.journal.save() != nil {
		r.ready = false
		r.closeArtifactLocked()
	}
}
func (r *SoftwareController) stageFailed(x *softwareEntry, reason string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if x.Phase == "staging" {
		_ = r.finishLocked(x, "failed", reason, false, nil, false)
	}
}
