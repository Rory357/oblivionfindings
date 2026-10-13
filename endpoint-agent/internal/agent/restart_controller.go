package agent

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"sync"
	"time"
)

type RestartView struct {
	Protocol         int    `json:"protocol"`
	ServerOrigin     string `json:"server_origin"`
	Status           string `json:"status"`
	CommandUUID      string `json:"command_uuid"`
	AttemptUUID      string `json:"attempt_uuid"`
	PayloadHash      string `json:"payload_hash"`
	ConsentToken     string `json:"consent_token"`
	PolicyVersion    int64  `json:"policy_version"`
	ConsentMode      string `json:"consent_mode"`
	Technician       string `json:"technician"`
	Purpose          string `json:"purpose"`
	ExecuteAt        string `json:"execute_at"`
	ExpiresAt        string `json:"expires_at"`
	WarningSeconds   int    `json:"warning_seconds"`
	RemainingSeconds int    `json:"remaining_seconds"`
	ReasonCode       string `json:"reason_code"`
	MutationStarted  bool   `json:"mutation_started"`
	CanCancel        bool   `json:"can_cancel"`
}
type RestartDecision struct{ Operation, OriginHash, AttemptUUID, PayloadHash, ConsentToken, Decision string }

// Restart is injected in tests. The Windows implementation is reachable only behind installed/release gates.
type restartExecutor interface {
	Restart(func() bool) (bool, error)
}
type RestartController struct {
	mutationGate            *sync.Mutex
	gateHeld                bool
	mu                      sync.Mutex
	journal                 *restartJournal
	active                  *restartEntry
	transport               restartTransport
	executor                restartExecutor
	key                     []byte
	agent, origin           string
	ready                   bool
	executionEnabled        bool
	check                   func(RemotePeer) bool
	connected               func() bool
	clock, monotonic        func() time.Time
	peer                    RemotePeer
	desktop, nonce          string
	allowed                 bool
	visibleAt, visibleSince time.Time
	warning                 string
	acceptedSince           time.Time
	lastWall, lastMono      time.Time
	nextPoll                time.Time
	boot                    string
	bootAt                  time.Time
}

func newRestartController(check func(RemotePeer) bool, executor restartExecutor) *RestartController {
	return &RestartController{mutationGate: &sync.Mutex{}, executionEnabled: true, check: check, executor: executor, clock: time.Now, monotonic: time.Now}
}
func (r *RestartController) available() bool { r.mu.Lock(); defer r.mu.Unlock(); return r.ready }
func (r *RestartController) InvalidateConsent() {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.allowed = false
	r.visibleAt = time.Time{}
	r.visibleSince = time.Time{}
	r.warning = ""
	r.peer = RemotePeer{}
	r.desktop = ""
	r.nonce = ""
}
func (r *RestartController) viewLocked() RestartView {
	v := RestartView{Protocol: 1, ServerOrigin: r.origin, Status: "idle"}
	x := r.active
	if x == nil {
		return v
	}
	c := x.Command
	v.CommandUUID = c.CommandUUID
	v.AttemptUUID = c.AttemptUUID
	v.PayloadHash = x.Hash
	v.PolicyVersion = c.Policy.Version
	v.ConsentMode = c.Policy.ConsentMode
	v.Technician = c.Technician
	v.Purpose = c.Purpose
	v.ExecuteAt = c.Parameters.ExecuteAt
	v.ExpiresAt = c.ExpiresAt
	v.WarningSeconds = c.Parameters.WarningSeconds
	at, _ := restartTime(v.ExecuteAt)
	left := at.Sub(r.clock())
	if left > 0 {
		v.RemainingSeconds = int((left + time.Second - 1) / time.Second)
	}
	if x.Phase == "terminal" {
		v.Status = x.Result.Status
		v.ReasonCode = x.Result.ReasonCode
		v.MutationStarted = x.Result.MutationStarted
		if x.BootStatus != "" {
			v.Status = x.BootStatus
			if x.BootStatus == "succeeded" {
				v.ReasonCode = "boot_observed"
			}
		}
	} else if x.Phase == "api_entered" {
		v.Status = "awaiting_result"
		v.MutationStarted = true
	} else {
		v.Status = "requested"
		if r.allowed || c.Policy.ConsentMode == "unattended" {
			v.Status = "countdown"
		}
		v.ConsentToken = r.nonce
		v.CanCancel = true
	}
	return v
}
func (r *RestartController) Handle(peer RemotePeer, a RestartDecision) (RestartView, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	hash, e := CanonicalOriginHash(r.origin)
	if e != nil || a.OriginHash != hash || !r.ready || !peer.valid() || r.check == nil || !r.check(peer) {
		return RestartView{}, errServiceAction
	}
	x := r.active
	if x == nil {
		return r.viewLocked(), nil
	}
	if x.Phase == "terminal" || x.Phase == "api_entered" {
		if a.Operation != "restart_poll" && (a.AttemptUUID != x.Command.AttemptUUID || a.PayloadHash != x.Hash) {
			return RestartView{}, errServiceAction
		}
		return r.viewLocked(), nil
	}
	if r.peer != peer {
		if r.allowed {
			return RestartView{}, errServiceAction
		}
		r.peer = peer
		r.desktop = ""
		r.nonce = ""
		r.visibleSince = time.Time{}
		r.visibleAt = time.Time{}
		r.warning = ""
	}
	if r.nonce == "" {
		b := make([]byte, 32)
		if _, e = rand.Read(b); e != nil {
			return RestartView{}, e
		}
		r.nonce = hex.EncodeToString(b)
		r.desktop = bootUUID(r.nonce)
	}
	if a.Operation == "restart_poll" {
		return r.viewLocked(), nil
	}
	if a.AttemptUUID != x.Command.AttemptUUID || a.PayloadHash != x.Hash || a.ConsentToken != r.nonce {
		return RestartView{}, errServiceAction
	}
	switch a.Operation {
	case "restart_visible":
		if a.Decision == "hidden" {
			r.allowed = false
			r.visibleAt = time.Time{}
			r.visibleSince = time.Time{}
			r.warning = ""
		} else if a.Decision == "visible" {
			now := r.monotonic()
			if r.visibleSince.IsZero() || now.Sub(r.visibleAt) > 5*time.Second {
				r.visibleSince = now
				r.warning = r.clock().UTC().Format(time.RFC3339)
				r.allowed = false
			}
			r.visibleAt = now
		} else {
			return RestartView{}, errServiceAction
		}
	case "restart_decide":
		switch a.Decision {
		case "allow":
			if r.visibleSince.IsZero() || r.monotonic().Sub(r.visibleAt) > 5*time.Second {
				return RestartView{}, errServiceAction
			}
			r.allowed = true
		case "decline", "cancel":
			status := "declined"
			if a.Decision == "cancel" {
				status = "cancelled"
			}
			if e = r.finishLocked(x, status, status, false); e != nil {
				return RestartView{}, e
			}
		default:
			return RestartView{}, errServiceAction
		}
	default:
		return RestartView{}, errServiceAction
	}
	return r.viewLocked(), nil
}
func (r *RestartController) finishLocked(x *restartEntry, status, reason string, started bool) error {
	if x.Phase == "terminal" {
		return nil
	}
	boot := r.boot
	if x.BeforeBootID != "" {
		boot = x.BeforeBootID
	}
	if !uuidPattern.MatchString(boot) {
		return errServiceAction
	}
	x.BeforeBootID = boot
	x.Phase = "terminal"
	x.Result = &RestartResult{x.Command.CommandUUID, x.Hash, status, started, reason, boot}
	if r.journal.save() != nil {
		r.ready = false
		return errServiceAction
	}
	return nil
}
func (r *RestartController) attendedLocked(c RestartCommand) bool {
	return r.allowed && r.peer.valid() && r.check(r.peer) && !r.visibleSince.IsZero() && r.monotonic().Sub(r.visibleAt) <= 5*time.Second && r.monotonic().Sub(r.visibleSince) >= time.Duration(c.Parameters.WarningSeconds)*time.Second
}
func (r *RestartController) Run(ctx context.Context) {
	defer func() { r.mu.Lock(); r.ready = false; r.mu.Unlock() }()
	for ctx.Err() == nil {
		r.tick(ctx)
		if waitFor(ctx, 200*time.Millisecond) != nil {
			return
		}
	}
}
func (r *RestartController) tick(ctx context.Context) {
	r.mu.Lock()
	if !r.ready {
		r.mu.Unlock()
		return
	}
	now, mono := r.clock(), r.monotonic()
	if !r.executionEnabled && r.active != nil && r.active.Phase != "terminal" && r.active.Phase != "api_entered" {
		_ = r.finishLocked(r.active, "failed", "policy_unavailable", false)
	}
	x := r.active
	if x != nil && x.Phase != "terminal" && x.Phase != "api_entered" && !r.lastWall.IsZero() {
		drift := now.Sub(r.lastWall) - mono.Sub(r.lastMono)
		if drift > 2*time.Second || drift < -2*time.Second || mono.Sub(r.lastMono) > 10*time.Second {
			_ = r.finishLocked(x, "failed", "clock_changed", false)
		}
	}
	r.lastWall = now
	r.lastMono = mono
	if r.journal.retire(now) != nil {
		r.ready = false
		r.mu.Unlock()
		return
	}
	if r.active != nil && r.journal.Entries[r.active.Command.AttemptUUID] == nil {
		r.active = nil
	}
	var pending []*restartEntry
	for _, entry := range r.journal.Entries {
		if entry.Phase == "terminal" && !entry.Reported {
			pending = append(pending, entry)
		}
	}
	r.mu.Unlock()
	for _, entry := range pending {
		call, cancel := context.WithTimeout(ctx, 3*time.Second)
		e := r.transport.Result(call, entry.Command, *entry.Result)
		cancel()
		if e == nil {
			r.mu.Lock()
			entry.Reported = true
			entry.ReportedAt = r.clock().UTC().Format(time.RFC3339)
			if r.journal.save() != nil {
				r.ready = false
			}
			r.mu.Unlock()
		}
	}
	r.mu.Lock()
	x = r.active
	now = r.clock()
	poll := r.executionEnabled && !now.Before(r.nextPoll) && uuidPattern.MatchString(r.boot)
	if poll {
		r.nextPoll = now.Add(2 * time.Second)
	}
	r.mu.Unlock()
	if poll {
		call, cancel := context.WithTimeout(ctx, 3*time.Second)
		p, e := r.transport.Poll(call)
		cancel()
		if e == nil {
			r.mu.Lock()
			x = r.active
			if x != nil && x.Phase != "terminal" && x.Phase != "api_entered" && p.Cancellation != nil && verifyRestartCancellation(*p.Cancellation, r.key, x.Command, x.Hash, r.clock()) {
				_ = r.finishLocked(x, "cancelled", "cancelled", false)
			}
			r.mu.Unlock()
			if p.Command != nil {
				c, e := verifyRestartCommand(*p.Command, r.key, r.agent, r.clock())
				if e == nil {
					r.mu.Lock()
					expiry, _ := restartTime(c.ExpiresAt)
					watermark, _ := restartTime(r.journal.RetiredBefore)
					if expiry.After(watermark) {
						hash := serviceHash(*p.Command)
						old := r.journal.Entries[c.AttemptUUID]
						if old != nil {
							if old.Hash != hash {
								r.ready = false
							} else if r.active == nil || r.active.Phase == "terminal" {
								r.active = old
							}
						} else if len(r.journal.Entries) < 256 && (r.active == nil || r.active.Phase == "terminal") {
							entry := &restartEntry{Command: c, Hash: hash, Phase: "countdown", BeforeBootID: r.boot}
							r.journal.Entries[c.AttemptUUID] = entry
							if r.journal.save() != nil {
								r.ready = false
							} else {
								r.active = entry
								r.acceptedSince = r.monotonic()
								r.allowed = false
								r.peer = RemotePeer{}
								r.nonce = ""
								r.visibleSince = time.Time{}
								r.visibleAt = time.Time{}
							}
						}
					}
					r.mu.Unlock()
				}
			}
		}
	}
	r.mu.Lock()
	x = r.active
	if !r.ready || !r.executionEnabled || x == nil || x.Phase == "terminal" || x.Phase == "api_entered" {
		r.mu.Unlock()
		return
	}
	c := x.Command
	at, _ := restartTime(c.Parameters.ExecuteAt)
	now = r.clock()
	if now.Before(at) {
		r.mu.Unlock()
		return
	}
	if !now.Before(at.Add(5 * time.Second)) {
		_ = r.finishLocked(x, "expired", "expired", false)
		r.mu.Unlock()
		return
	}
	if r.connected == nil || !r.connected() {
		_ = r.finishLocked(x, "failed", "offline", false)
		r.mu.Unlock()
		return
	}
	if !uuidPattern.MatchString(r.boot) || r.clock().Sub(r.bootAt) > 360*time.Second {
		r.mu.Unlock()
		return
	}
	if c.Policy.ConsentMode == "unattended" && (r.acceptedSince.IsZero() || r.monotonic().Sub(r.acceptedSince) < time.Duration(c.Parameters.WarningSeconds)*time.Second) {
		_ = r.finishLocked(x, "failed", "warning_incomplete", false)
		r.mu.Unlock()
		return
	}
	if c.Policy.ConsentMode == "attended" && !r.attendedLocked(c) {
		_ = r.finishLocked(x, "failed", "warning_incomplete", false)
		r.mu.Unlock()
		return
	}
	x.Phase = "armed"
	x.BeforeBootID = r.boot
	if r.journal.save() != nil {
		r.ready = false
		r.mu.Unlock()
		return
	}
	boot, desktop, nonce, warning := r.boot, r.desktop, r.nonce, r.warning
	hash := x.Hash
	r.mu.Unlock()
	call, cancel := context.WithTimeout(ctx, 3*time.Second)
	envelope, e := r.transport.Claim(call, c, hash, boot, desktop, nonce, warning)
	cancel()
	r.mu.Lock()
	defer r.mu.Unlock()
	if x.Phase == "terminal" {
		return
	}
	if e != nil {
		_ = r.finishLocked(x, "uncertain", "state_unavailable", false)
		return
	}
	drift := r.clock().Sub(r.lastWall) - r.monotonic().Sub(r.lastMono)
	if drift > 2*time.Second || drift < -2*time.Second || r.monotonic().Sub(r.lastMono) > 10*time.Second {
		_ = r.finishLocked(x, "failed", "clock_changed", false)
		return
	}
	claim, e := verifyRestartClaim(envelope, r.key, c, hash, boot, desktop, nonce, warning, r.clock())
	expires, _ := restartTime(claim.ExpiresAt)
	if e != nil || !r.ready || ctx.Err() != nil || r.clock().Before(at) || !r.clock().Before(at.Add(5*time.Second)) || !expires.After(r.clock()) || r.boot != boot || !r.connected() || (c.Policy.ConsentMode == "attended" && !r.attendedLocked(c)) {
		_ = r.finishLocked(x, "uncertain", "policy_unavailable", false)
		return
	}
	// Local cancel and API entry share this lock. No network occurs under it.
	if !r.mutationGate.TryLock() {
		_ = r.finishLocked(x, "failed", "operation_failed", false)
		return
	}
	r.gateHeld = true
	defer func() {
		if r.gateHeld && x.Phase == "terminal" && x.Result.ReasonCode == "restart_refused" {
			r.mutationGate.Unlock()
			r.gateHeld = false
		}
	}()
	x.Phase = "api_entered"
	x.BeforeBootID = boot
	if r.journal.save() != nil {
		r.ready = false
		return
	}
	entered, err := r.executor.Restart(func() bool {
		if ctx.Err() != nil || !r.ready || !r.executionEnabled || !r.connected() || r.boot != boot {
			return false
		}
		if c.Policy.ConsentMode == "attended" && !r.attendedLocked(c) {
			return false
		}
		wall, moment := r.clock(), r.monotonic()
		drift := wall.Sub(r.lastWall) - moment.Sub(r.lastMono)
		return drift <= 2*time.Second && drift >= -2*time.Second && moment.Sub(r.lastMono) <= 10*time.Second && !wall.Before(at) && wall.Before(at.Add(5*time.Second)) && expires.After(wall)
	})
	if !entered {
		r.mutationGate.Unlock()
		r.gateHeld = false
		reason := "operation_failed"
		if err == errServiceAction {
			reason = "policy_unavailable"
		}
		_ = r.finishLocked(x, "failed", reason, false)
	} else if err != nil {
		_ = r.finishLocked(x, "failed", "restart_refused", true)
	} else {
		_ = r.finishLocked(x, "awaiting_boot", "restart_requested", true)
	}
}
func (r *RestartController) heartbeat(boot, at string) []RestartObservation {
	r.mu.Lock()
	defer r.mu.Unlock()
	when, e := restartTime(at)
	if e != nil || !uuidPattern.MatchString(boot) {
		return nil
	}
	var rows []RestartObservation
	for _, x := range r.journal.Entries {
		if len(rows) == 8 {
			break
		}
		if x.Phase == "terminal" && x.Result.MutationStarted && x.Result.ReasonCode != "restart_refused" && x.BootStatus == "" && x.BeforeBootID != boot {
			rows = append(rows, RestartObservation{x.Command.CommandUUID, x.Command.AttemptUUID, x.BeforeBootID, boot, at})
		}
	}
	_ = when // Execution boot identity is updated only by an accepted heartbeat.
	return rows
}
func (r *RestartController) acknowledge(payload []byte, ack []RestartAcceptance) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if validateRestartACK(payload, ack) != nil {
		return
	}
	for _, a := range ack {
		if x := r.journal.Entries[a.AttemptUUID]; x != nil && x.Phase == "terminal" && x.Result.MutationStarted && (a.Status != "succeeded" || x.Result.ReasonCode == "restart_requested") && x.Command.CommandUUID == a.CommandUUID && x.BeforeBootID == a.BeforeBootID && a.BootID != a.BeforeBootID {
			x.BootStatus = a.Status
		}
	}
	if r.journal.save() != nil {
		r.ready = false
	}
	var heartbeat struct {
		BootID     string `json:"boot_id"`
		ObservedAt string `json:"observed_at"`
	}
	if json.Unmarshal(payload, &heartbeat) == nil {
		if at, e := restartTime(heartbeat.ObservedAt); e == nil && uuidPattern.MatchString(heartbeat.BootID) {
			blocked := false
			for _, x := range r.journal.Entries {
				if x.Phase == "terminal" && x.Result.MutationStarted && x.Result.ReasonCode != "restart_refused" && (x.BeforeBootID == heartbeat.BootID || x.BootStatus == "") {
					blocked = true
				}
			}
			if blocked && !r.gateHeld {
				if r.mutationGate.TryLock() {
					r.gateHeld = true
				} else {
					r.ready = false
				}
			} else if !blocked && r.gateHeld {
				r.mutationGate.Unlock()
				r.gateHeld = false
			}
			r.boot = heartbeat.BootID
			r.bootAt = at
		}
	}
}
