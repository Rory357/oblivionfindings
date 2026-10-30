package agent

import (
	"context"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type ServiceResult struct {
	CommandUUID     string `json:"command_uuid"`
	PayloadHash     string `json:"payload_hash"`
	Status          string `json:"status"`
	Operation       string `json:"operation"`
	ServiceName     string `json:"service_name"`
	BeforeState     string `json:"before_state"`
	AfterState      string `json:"after_state"`
	MutationStarted bool   `json:"mutation_started"`
	ReasonCode      string `json:"reason_code"`
}
type serviceJournalEntry struct {
	Command          ServiceCommand `json:"command"`
	Hash             string         `json:"hash"`
	Phase            string         `json:"phase"`
	Result           *ServiceResult `json:"result"`
	Reported         bool           `json:"reported"`
	ObservationAcked bool           `json:"observation_acked"`
	ReportedAt       string         `json:"reported_at"`
}
type serviceJournal struct {
	Version       int    `json:"version"`
	RetiredBefore string `json:"retired_before"`
	store         *Store
	AgentUUID     string                          `json:"agent_uuid"`
	Entries       map[string]*serviceJournalEntry `json:"entries"`
}

func loadServiceJournal(store *Store, agent string) (*serviceJournal, error) {
	j := &serviceJournal{Version: 1, store: store, AgentUUID: agent, Entries: map[string]*serviceJournalEntry{}}
	name := filepath.Join(store.dir, "service-actions.json")
	if rejectLink(name) != nil {
		return nil, errServiceAction
	}
	if store.validateProtected != nil {
		if _, e := os.Stat(name); e == nil {
			if store.validateProtected("service-actions.json") != nil {
				return nil, errServiceAction
			}
		}
	}
	file, e := os.Open(name)
	if os.IsNotExist(e) {
		return j, nil
	}
	if e != nil {
		return nil, errServiceAction
	}
	defer file.Close()
	data, e := io.ReadAll(io.LimitReader(file, 2*1024*1024+1))
	if closeErr := file.Close(); e == nil {
		e = closeErr
	}
	if os.IsNotExist(e) {
		return j, nil
	}
	if e != nil || len(data) > 2*1024*1024 || uniqueServiceJournal(data) != nil || decodeServiceJournal(data, j) != nil || j.Version != 1 || j.AgentUUID != agent || j.Entries == nil || len(j.Entries) > 256 {
		return nil, errServiceAction
	}
	for id, entry := range j.Entries {
		if entry == nil || !validStoredServiceCommand(entry.Command) || id != entry.Command.AttemptUUID || entry.Command.AgentUUID != agent || !uuidPattern.MatchString(id) || !hexCredential(entry.Hash) || (entry.Phase != "prepared" && entry.Phase != "executing" && entry.Phase != "terminal") || (entry.Phase == "terminal" && (entry.Result == nil || !validServiceResult(*entry.Result, entry.Command, entry.Hash))) {
			return nil, errServiceAction
		}
		if entry.Reported {
			if _, err := time.Parse("2006-01-02T15:04:05Z", entry.ReportedAt); err != nil {
				return nil, errServiceAction
			}
		} else if entry.ReportedAt != "" || entry.ObservationAcked {
			return nil, errServiceAction
		}
		if entry.Phase != "terminal" && (entry.Result != nil || entry.Reported || entry.ObservationAcked) {
			return nil, errServiceAction
		}
		if entry.Phase != "terminal" {
			wasExecuting := entry.Phase == "executing"
			entry.Phase = "terminal"
			result := serviceResult(entry.Command, entry.Hash, "uncertain", "interrupted")
			result.MutationStarted = wasExecuting
			entry.Result = &result
			entry.Reported = false
		}
	}
	if j.save() != nil {
		return nil, errServiceAction
	}
	return j, nil
}
func (j *serviceJournal) save() error {
	data, e := json.Marshal(j)
	if e != nil || len(data) > 2*1024*1024 {
		return errServiceAction
	}
	return j.store.atomicWrite("service-actions.json", data)
}
func serviceResult(c ServiceCommand, hash, status, reason string) ServiceResult {
	return ServiceResult{CommandUUID: c.CommandUUID, PayloadHash: hash, Status: status, Operation: c.Parameters.Operation, ServiceName: c.Parameters.ServiceName, BeforeState: "unknown", AfterState: "unknown", ReasonCode: reason}
}

type ServiceActionView struct {
	Protocol        int    `json:"protocol"`
	ServerOrigin    string `json:"server_origin"`
	Status          string `json:"status"`
	CommandUUID     string `json:"command_uuid"`
	AttemptUUID     string `json:"attempt_uuid"`
	PayloadHash     string `json:"payload_hash"`
	ConsentToken    string `json:"consent_token"`
	PolicyVersion   int64  `json:"policy_version"`
	Technician      string `json:"technician"`
	Purpose         string `json:"purpose"`
	ServiceName     string `json:"service_name"`
	Operation       string `json:"operation"`
	ExpiresAt       string `json:"expires_at"`
	ReasonCode      string `json:"reason_code"`
	MutationStarted bool   `json:"mutation_started"`
}
type ServiceActionDecision struct{ Operation, OriginHash, AttemptUUID, PayloadHash, ConsentToken, Decision string }
type serviceExecutor interface {
	Execute(context.Context, ServiceActionParameters, func() bool) ServiceResult
}
type serviceActionTransport interface {
	Poll(context.Context) (*Envelope, error)
	Claim(context.Context, ServiceCommand, string, string, string) (Envelope, error)
	Result(context.Context, ServiceCommand, ServiceResult) error
}
type ServiceActionController struct {
	observationAcks          chan ServiceActionObservation
	nextPoll                 time.Time
	mutationGate             *sync.Mutex
	mu                       sync.Mutex
	connected                func() bool
	origin                   string
	clock                    func() time.Time
	check                    func(RemotePeer) bool
	executor                 serviceExecutor
	transport                serviceActionTransport
	journal                  *serviceJournal
	key                      []byte
	agent                    string
	active                   *serviceJournalEntry
	peer                     RemotePeer
	desktop, nonce, decision string
	lastLocal                time.Time
	ready                    bool
}

func newServiceActionController(check func(RemotePeer) bool, executor serviceExecutor) *ServiceActionController {
	return &ServiceActionController{mutationGate: &sync.Mutex{}, observationAcks: make(chan ServiceActionObservation, 64), clock: time.Now, check: check, executor: executor}
}
func (r *ServiceActionController) Handle(peer RemotePeer, a ServiceActionDecision) (ServiceActionView, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	hash, e := CanonicalOriginHash(r.origin)
	if e != nil || a.OriginHash != hash || !peer.valid() || r.check == nil || !r.check(peer) || !r.ready {
		return ServiceActionView{}, errServiceAction
	}
	v := ServiceActionView{Protocol: 1, ServerOrigin: r.origin, Status: "idle"}
	entry := r.active
	if entry == nil {
		return v, nil
	}
	c := entry.Command
	if c.Policy.ConsentMode == "attended" && r.peer.valid() && r.peer != peer {
		return v, errServiceAction
	}
	if a.Operation == "service_action_decide" && (a.AttemptUUID != c.AttemptUUID || a.PayloadHash != entry.Hash || a.ConsentToken != r.nonce) {
		return v, errServiceAction
	}
	v.CommandUUID = c.CommandUUID
	v.AttemptUUID = c.AttemptUUID
	v.PayloadHash = entry.Hash
	v.PolicyVersion = c.Policy.Version
	v.Technician = c.Technician
	v.Purpose = c.Purpose
	v.ServiceName = c.Parameters.ServiceName
	v.Operation = c.Parameters.Operation
	v.ExpiresAt = c.ExpiresAt
	if entry.Phase == "terminal" {
		v.Status = entry.Result.Status
		v.ReasonCode = entry.Result.ReasonCode
		v.MutationStarted = entry.Result.MutationStarted
		return v, nil
	}
	if c.Policy.ConsentMode != "attended" || r.decision != "" {
		if r.peer == peer && r.decision == "allow" {
			r.lastLocal = r.clock()
		}
		v.MutationStarted = entry.Phase == "executing"
		v.Status = "awaiting_result"
		return v, nil
	}
	expires, _ := time.Parse(time.RFC3339, c.ExpiresAt)
	if !expires.After(r.clock()) {
		v.Status = "expired"
		v.ReasonCode = "expired"
		return v, nil
	}
	if r.peer != peer {
		if r.peer.valid() {
			return ServiceActionView{}, errServiceAction
		}
		r.peer = peer
		r.desktop, e = remoteDesktop()
		if e != nil {
			return v, e
		}
		r.nonce, e = remoteNonce()
		if e != nil {
			return v, e
		}
	}
	if a.Operation == "service_action_decide" {
		if a.AttemptUUID != c.AttemptUUID || a.PayloadHash != entry.Hash || a.ConsentToken != r.nonce || (a.Decision != "allow" && a.Decision != "decline") {
			return v, errServiceAction
		}
		r.decision = a.Decision
		v.MutationStarted = entry.Phase == "executing"
		v.Status = "awaiting_result"
	} else {
		v.Status = "requested"
		v.ConsentToken = r.nonce
	}
	r.lastLocal = r.clock()
	return v, nil
}
func (r *ServiceActionController) authorized(claim ServiceClaim) bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	expiry, _ := time.Parse(time.RFC3339, claim.ExpiresAt)
	if !expiry.After(r.clock()) {
		return false
	}
	if claim.ConsentMode == "unattended" {
		return r.ready
	}
	return r.ready && r.decision == "allow" && r.peer.valid() && r.check(r.peer) && r.clock().Sub(r.lastLocal) < 10*time.Second
}
func (r *ServiceActionController) Run(ctx context.Context) {
	defer func() { r.mu.Lock(); r.ready = false; r.mu.Unlock() }()
	for ctx.Err() == nil {
		if r.connected == nil || r.connected() {
			r.tick(ctx)
		}
		if waitFor(ctx, time.Second) != nil {
			return
		}
	}
}
func (r *ServiceActionController) tick(ctx context.Context) {
	// The single worker owns journal writes; UI accesses active state under the mutex.
	if r.retire() != nil {
		r.disable()
		return
	}
	for _, entry := range r.journal.Entries {
		if ctx.Err() != nil {
			return
		}
		if entry.Phase == "terminal" && !entry.Reported {
			if r.transport.Result(ctx, entry.Command, *entry.Result) == nil {
				r.mu.Lock()
				entry.Reported = true
				entry.ReportedAt = r.clock().UTC().Format(time.RFC3339)
				r.mu.Unlock()
				if r.journal.save() != nil {
					r.disable()
					return
				}
			}
		}
	}
	r.mu.Lock()
	entry := r.active
	decision := r.decision
	desktop, nonce := r.desktop, r.nonce
	ready := r.ready
	r.mu.Unlock()
	if !ready {
		return
	}
	if entry == nil || entry.Phase == "terminal" {
		if r.clock().Before(r.nextPoll) {
			return
		}
		r.nextPoll = r.clock().Add(5 * time.Second)
		envelope, e := r.transport.Poll(ctx)
		if e != nil || envelope == nil {
			return
		}
		c, e := verifyServiceCommand(*envelope, r.key, r.agent, r.clock())
		if e != nil {
			return
		}
		expiry, _ := time.Parse(time.RFC3339, c.ExpiresAt)
		watermark, _ := time.Parse(time.RFC3339, r.journal.RetiredBefore)
		if !expiry.After(watermark) {
			return
		}
		hash := serviceHash(*envelope)
		if old := r.journal.Entries[c.AttemptUUID]; old != nil {
			if old.Hash != hash {
				r.disable()
				return
			}
			r.mu.Lock()
			r.active = old
			r.mu.Unlock()
			return
		}
		if len(r.journal.Entries) >= 256 {
			r.disable()
			return
		}
		entry = &serviceJournalEntry{Command: c, Hash: hash, Phase: "prepared"}
		r.mu.Lock()
		r.journal.Entries[c.AttemptUUID] = entry
		r.mu.Unlock()
		if r.journal.save() != nil {
			r.disable()
			return
		}
		r.mu.Lock()
		r.active = entry
		r.peer = RemotePeer{}
		r.desktop = ""
		r.nonce = ""
		r.decision = ""
		r.mu.Unlock()
		return
	}
	c := entry.Command
	expiry, _ := time.Parse(time.RFC3339, c.ExpiresAt)
	if !expiry.After(r.clock()) {
		r.finish(entry, serviceResult(c, entry.Hash, "expired", "expired"))
		return
	}
	if decision == "consent_lost" {
		r.finish(entry, serviceResult(c, entry.Hash, "failed", "consent_lost"))
		return
	}
	if decision == "decline" {
		r.finish(entry, serviceResult(c, entry.Hash, "declined", "declined"))
		return
	}
	if c.Policy.ConsentMode == "attended" {
		r.mu.Lock()
		valid := r.decision == "allow" && r.peer.valid() && r.check(r.peer) && r.clock().Sub(r.lastLocal) < 10*time.Second
		r.mu.Unlock()
		if !valid {
			return
		}
	}
	envelope, e := r.transport.Claim(ctx, c, entry.Hash, desktop, nonce)
	if e != nil {
		r.finish(entry, serviceResult(c, entry.Hash, "uncertain", "state_unavailable"))
		return
	}
	claim, e := verifyServiceClaim(envelope, r.key, c, entry.Hash, desktop, nonce, r.clock())
	if e != nil {
		r.finish(entry, serviceResult(c, entry.Hash, "uncertain", "policy_unavailable"))
		return
	}
	if !r.authorized(claim) {
		r.finish(entry, serviceResult(c, entry.Hash, "uncertain", "consent_lost"))
		return
	}
	r.mu.Lock()
	if !r.mutationGate.TryLock() {
		r.mu.Unlock()
		r.finish(entry, serviceResult(c, entry.Hash, "failed", "service_busy"))
		return
	}
	defer r.mutationGate.Unlock()
	entry.Phase = "executing"
	r.mu.Unlock()
	if r.journal.save() != nil {
		r.disable()
		return
	}
	execution, cancel := context.WithTimeout(ctx, 30*time.Second)
	result := r.executor.Execute(execution, c.Parameters, func() bool { return ctx.Err() == nil && r.authorized(claim) })
	cancel()
	result.CommandUUID = c.CommandUUID
	result.PayloadHash = entry.Hash
	result.ServiceName = c.Parameters.ServiceName
	result.Operation = c.Parameters.Operation
	r.finish(entry, result)
}
func (r *ServiceActionController) disable() { r.mu.Lock(); r.ready = false; r.mu.Unlock() }
func (r *ServiceActionController) finish(entry *serviceJournalEntry, result ServiceResult) {
	if !validServiceResult(result, entry.Command, entry.Hash) {
		result = serviceResult(entry.Command, entry.Hash, "uncertain", "state_unavailable")
		result.MutationStarted = true
	}
	r.mu.Lock()
	entry.Result = &result
	entry.Phase = "terminal"
	entry.Reported = false
	r.mu.Unlock()
	if r.journal.save() != nil {
		r.disable()
	}
}

func (r *ServiceActionController) InvalidateConsent() {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.active != nil && r.active.Phase != "terminal" && r.active.Command.Policy.ConsentMode == "attended" && r.peer.valid() {
		r.decision = "consent_lost"
		r.nonce = ""
	}
}
