package agent

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"sync"
	"time"
)

const scriptJournalName = "script-actions.json"
const scriptJournalGuard = "script-actions.guard.json"
const scriptJournalLimit = 16 * 1024 * 1024

type scriptProcessIdentity struct {
	PID     uint32 `json:"pid"`
	Created string `json:"created"`
}
type scriptRecord struct {
	Original   Envelope               `json:"original"`
	Hash       string                 `json:"hash"`
	Phase      string                 `json:"phase"`
	BootID     string                 `json:"boot_id"`
	Desktop    *string                `json:"desktop_session"`
	Nonce      *string                `json:"consent_nonce"`
	Claim      *Envelope              `json:"claim"`
	JobID      string                 `json:"job_id"`
	Process    *scriptProcessIdentity `json:"process"`
	Result     json.RawMessage        `json:"result"`
	ReportedAt string                 `json:"reported_at"`
}
type scriptJournalState struct {
	Version       int                      `json:"version"`
	AgentUUID     string                   `json:"agent_uuid"`
	Guard         string                   `json:"guard"`
	Revision      uint64                   `json:"revision"`
	RetiredBefore string                   `json:"retired_before"`
	Entries       map[string]*scriptRecord `json:"entries"`
}
type scriptJournal struct {
	mu       sync.Mutex
	store    *Store
	key      ed25519.PublicKey
	state    scriptJournalState
	diskHash string
	failed   bool
	clock    func() time.Time
}

func scriptHash(raw []byte) string { sum := sha256.Sum256(raw); return hex.EncodeToString(sum[:]) }
func (j *scriptJournal) now() time.Time {
	if j.clock != nil {
		return j.clock().UTC().Truncate(time.Second)
	}
	return time.Now().UTC().Truncate(time.Second)
}
func scriptJournalRead(s *Store, name string, limit int) ([]byte, error) {
	if s == nil || rejectLink(filepath.Join(s.dir, name)) != nil {
		return nil, errScriptContract
	}
	f, err := os.Open(filepath.Join(s.dir, name))
	if err != nil {
		return nil, errScriptContract
	}
	defer f.Close()
	if s.validateProtected != nil && s.validateProtected(name) != nil {
		return nil, errScriptContract
	}
	raw, err := io.ReadAll(io.LimitReader(f, int64(limit+1)))
	if err != nil || len(raw) > limit {
		return nil, errScriptContract
	}
	return raw, nil
}

// Creation is an explicit fresh-install operation. Loading never initializes a
// missing journal. The guard is committed first; partial creation fails closed.
func createScriptJournal(s *Store, agent string, key ed25519.PublicKey, clock func() time.Time) (*scriptJournal, error) {
	if s == nil || !scriptUUID.MatchString(agent) || len(key) != ed25519.PublicKeySize {
		return nil, errScriptContract
	}
	s.scriptJournalGate.Lock()
	defer s.scriptJournalGate.Unlock()
	for _, name := range []string{scriptJournalName, scriptJournalGuard} {
		if _, err := os.Lstat(filepath.Join(s.dir, name)); !os.IsNotExist(err) {
			return nil, errScriptContract
		}
	}
	token := make([]byte, 32)
	if _, err := rand.Read(token); err != nil {
		return nil, errScriptContract
	}
	j := &scriptJournal{store: s, key: append(ed25519.PublicKey(nil), key...), clock: clock, state: scriptJournalState{Version: 1, AgentUUID: agent, Guard: hex.EncodeToString(token), Revision: 1, Entries: map[string]*scriptRecord{}}}
	guard, _ := json.Marshal(map[string]any{"version": 1, "agent_uuid": agent, "guard": j.state.Guard})
	if s.atomicWrite(scriptJournalGuard, guard) != nil {
		return nil, errScriptContract
	}
	raw, _ := json.Marshal(j.state)
	if s.atomicWrite(scriptJournalName, raw) != nil {
		return nil, errScriptContract
	}
	j.diskHash = scriptHash(raw)
	return j, nil
}
func loadScriptJournal(s *Store, agent string, key ed25519.PublicKey, clock func() time.Time) (*scriptJournal, error) {
	if s == nil || !scriptUUID.MatchString(agent) || len(key) != ed25519.PublicKeySize {
		return nil, errScriptContract
	}
	s.scriptJournalGate.Lock()
	defer s.scriptJournalGate.Unlock()
	raw, err := scriptJournalRead(s, scriptJournalName, scriptJournalLimit)
	if err != nil {
		return nil, err
	}
	j := &scriptJournal{store: s, key: append(ed25519.PublicKey(nil), key...), clock: clock}
	state, err := j.decode(raw, agent)
	if err != nil {
		return nil, err
	}
	j.state = state
	j.diskHash = scriptHash(raw)
	if !j.guardMatches() {
		return nil, errScriptContract
	}
	changed := false
	next := j.clone()
	for _, r := range next.Entries {
		if r.Phase == "prepared" || r.Phase == "terminal" {
			continue
		}
		entered := r.JobID != ""
		reason := "interrupted"
		if r.Phase == "claiming" {
			reason = "state_unavailable"
		}
		command, _, _ := j.command(r.Original, false)
		result := scriptObject{"command_uuid": command["command_uuid"], "payload_hash": r.Hash, "status": "uncertain", "mutation_started": entered, "reason_code": reason, "exit_code": nil, "boot_id": r.BootID, "output_sha256": nil, "output_bytes": json.Number("0"), "output_truncated": false}
		r.Result, _ = json.Marshal(result)
		r.Phase = "terminal"
		changed = true
	}
	// The store gate is already held; recovery is durable before a worker sees it.
	if changed {
		if err = j.commitLocked(next); err != nil {
			return nil, err
		}
	}
	return j, nil
}
func (j *scriptJournal) command(e Envelope, fresh bool) (scriptObject, string, error) {
	raw, err := scriptSignedRaw(e, j.key)
	if err != nil {
		return nil, "", errScriptContract
	}
	x, err := scriptDecode(raw, 32768)
	if err != nil {
		return nil, "", errScriptContract
	}
	at, ok := scriptInstant(x["issued_at"])
	if !ok {
		return nil, "", errScriptContract
	}
	if fresh {
		at = j.now()
	}
	command, err := verifyScriptCommand(e, j.key, j.state.AgentUUID, at)
	return command, scriptHash(raw), err
}
func scriptConsent(command scriptObject, desktop, nonce *string) bool {
	if command["policy"].(scriptObject)["consent_mode"] == "unattended" {
		return desktop == nil && nonce == nil
	}
	return desktop != nil && nonce != nil && scriptUUID.MatchString(*desktop) && scriptDigest(*nonce)
}
func (j *scriptJournal) decode(raw []byte, agent string) (scriptJournalState, error) {
	var state scriptJournalState
	root, err := scriptDecode(raw, scriptJournalLimit)
	if err != nil {
		return state, errScriptContract
	}
	_, ok := scriptKeys(root, "version", "agent_uuid", "guard", "revision", "retired_before", "entries")
	rows, rowsOK := root["entries"].(scriptObject)
	if !ok || !scriptInt(root["version"], 1, 1) || root["agent_uuid"] != agent || !scriptDigest(root["guard"]) || !scriptInt(root["revision"], 1, 9007199254740991) || !rowsOK || len(rows) > 256 {
		return state, errScriptContract
	}
	if _, ok := root["retired_before"].(string); !ok {
		return state, errScriptContract
	}
	if root["retired_before"] != "" {
		if _, ok := scriptInstant(root["retired_before"]); !ok {
			return state, errScriptContract
		}
	}
	if json.Unmarshal(raw, &state) != nil {
		return state, errScriptContract
	}
	// command() binds the installation during decoding as well as later writes.
	old := j.state.AgentUUID
	j.state.AgentUUID = agent
	defer func() { j.state.AgentUUID = old }()
	for id, r := range state.Entries {
		x, ok := scriptKeys(rows[id], "original", "hash", "phase", "boot_id", "desktop_session", "consent_nonce", "claim", "job_id", "process", "result", "reported_at")
		if !ok || r == nil || !scriptUUID.MatchString(id) || !scriptUUID.MatchString(r.BootID) {
			return state, errScriptContract
		}
		for _, name := range []string{"hash", "phase", "boot_id", "job_id", "reported_at"} {
			if _, ok := x[name].(string); !ok {
				return state, errScriptContract
			}
		}
		if _, err := scriptWireEnvelope(x["original"]); err != nil {
			return state, err
		}
		command, hash, err := j.command(r.Original, false)
		if err != nil || id != command["attempt_uuid"] || r.Hash != hash {
			return state, errScriptContract
		}
		if bytes.Equal(r.Result, []byte("null")) {
			r.Result = nil
		}
		if (r.Desktop != nil || r.Nonce != nil) && !scriptConsent(command, r.Desktop, r.Nonce) {
			return state, errScriptContract
		}
		if r.Claim != nil {
			if _, err := scriptWireEnvelope(x["claim"]); err != nil {
				return state, err
			}
			data, err := scriptSignedRaw(*r.Claim, j.key)
			if err != nil {
				return state, errScriptContract
			}
			claim, err := scriptDecode(data, 8192)
			if err != nil {
				return state, err
			}
			at, ok := scriptInstant(claim["issued_at"])
			if !ok {
				return state, errScriptContract
			}
			if _, err = verifyScriptClaim(*r.Claim, j.key, command, hash, r.BootID, r.Desktop, r.Nonce, at); err != nil {
				return state, errScriptContract
			}
		}
		if r.JobID != "" && (!scriptUUID.MatchString(r.JobID) || r.Claim == nil) {
			return state, errScriptContract
		}
		if r.Process != nil {
			p, ok := scriptKeys(x["process"], "pid", "created")
			created, err := strconv.ParseUint(r.Process.Created, 10, 64)
			if !ok || !scriptInt(p["pid"], 1, 4294967295) || r.Process.PID == 0 || err != nil || created == 0 || strconv.FormatUint(created, 10) != r.Process.Created || r.JobID == "" {
				return state, errScriptContract
			}
		}
		if r.Phase == "terminal" {
			result, err := scriptDecode(r.Result, 8192)
			if err != nil || !validScriptResult(result, command, hash) || result["boot_id"] != r.BootID || result["mutation_started"] != (r.JobID != "") {
				return state, errScriptContract
			}
			if result["mutation_started"] == true && result["status"] != "uncertain" && r.Process == nil {
				return state, errScriptContract
			}
			if r.ReportedAt != "" {
				if _, ok := scriptInstant(r.ReportedAt); !ok {
					return state, errScriptContract
				}
			}
		} else {
			if r.Result != nil || r.ReportedAt != "" {
				return state, errScriptContract
			}
			switch r.Phase {
			case "prepared":
				if r.Claim != nil || r.Desktop != nil || r.Nonce != nil || r.JobID != "" || r.Process != nil {
					return state, errScriptContract
				}
			case "claiming":
				if r.Claim != nil || r.JobID != "" || r.Process != nil || !scriptConsent(command, r.Desktop, r.Nonce) {
					return state, errScriptContract
				}
			case "claimed":
				if r.Claim == nil || r.JobID != "" || r.Process != nil {
					return state, errScriptContract
				}
			case "entered":
				if r.Claim == nil || r.JobID == "" || r.Process != nil {
					return state, errScriptContract
				}
			case "suspended", "running":
				if r.Claim == nil || r.JobID == "" || r.Process == nil {
					return state, errScriptContract
				}
			default:
				return state, errScriptContract
			}
		}
	}
	return state, nil
}
func (j *scriptJournal) clone() scriptJournalState {
	raw, _ := json.Marshal(j.state)
	var next scriptJournalState
	_ = json.Unmarshal(raw, &next)
	return next
}
func (j *scriptJournal) guardMatches() bool {
	raw, err := scriptJournalRead(j.store, scriptJournalGuard, 1024)
	if err != nil {
		return false
	}
	g, err := scriptDecode(raw, 1024)
	if err != nil {
		return false
	}
	_, ok := scriptKeys(g, "version", "agent_uuid", "guard")
	return ok && scriptInt(g["version"], 1, 1) && g["agent_uuid"] == j.state.AgentUUID && g["guard"] == j.state.Guard
}
func (j *scriptJournal) current() bool {
	if j.failed || !j.guardMatches() {
		j.failed = true
		return false
	}
	raw, err := scriptJournalRead(j.store, scriptJournalName, scriptJournalLimit)
	if err != nil || scriptHash(raw) != j.diskHash {
		j.failed = true
		return false
	}
	return true
}
func (j *scriptJournal) commitLocked(next scriptJournalState) error {
	if !j.current() || j.state.Revision >= 9007199254740991 {
		return errScriptContract
	}
	next.Revision = j.state.Revision + 1
	raw, err := json.Marshal(next)
	if err != nil || len(raw) > scriptJournalLimit {
		return errScriptContract
	}
	checked, err := j.decode(raw, j.state.AgentUUID)
	if err != nil {
		return errScriptContract
	}
	// Any write failure is ambiguous, including an error after the rename.
	// This instance cannot grant another transition until a durable reload.
	if j.store.atomicWrite(scriptJournalName, raw) != nil {
		j.failed = true
		return errScriptContract
	}
	j.state = checked
	j.diskHash = scriptHash(raw)
	return nil
}
func (j *scriptJournal) change(operation func(*scriptJournalState) error) error {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.store.scriptJournalGate.Lock()
	defer j.store.scriptJournalGate.Unlock()
	if !j.current() {
		return errScriptContract
	}
	next := j.clone()
	if err := operation(&next); err != nil {
		return err
	}
	before, _ := json.Marshal(j.state)
	after, _ := json.Marshal(next)
	if bytes.Equal(before, after) {
		return nil
	}
	return j.commitLocked(next)
}
func (j *scriptJournal) Prepare(original Envelope, boot string) error {
	return j.change(func(next *scriptJournalState) error {
		command, hash, err := j.command(original, false)
		if err != nil || !scriptUUID.MatchString(boot) {
			return errScriptContract
		}
		id := scriptString(command["attempt_uuid"])
		if old := next.Entries[id]; old != nil {
			if old.Hash != hash || old.Original != original {
				return errScriptContract
			}
			return nil
		}
		if _, _, err = j.command(original, true); err != nil || len(next.Entries) >= 256 {
			return errScriptContract
		}
		end, _ := scriptInstant(command["expires_at"])
		watermark, _ := scriptInstant(next.RetiredBefore)
		if !watermark.IsZero() && !end.After(watermark) {
			return errScriptContract
		}
		next.Entries[id] = &scriptRecord{Original: original, Hash: hash, Phase: "prepared", BootID: boot}
		return nil
	})
}
func (j *scriptJournal) BeginClaim(id, boot string, desktop, nonce *string) error {
	return j.change(func(next *scriptJournalState) error {
		r := next.Entries[id]
		if r == nil || r.Phase != "prepared" || !scriptUUID.MatchString(boot) {
			return errScriptContract
		}
		command, _, err := j.command(r.Original, true)
		if err != nil || !scriptConsent(command, desktop, nonce) {
			return errScriptContract
		}
		r.Phase = "claiming"
		r.BootID = boot
		if desktop != nil {
			value := *desktop
			r.Desktop = &value
		}
		if nonce != nil {
			value := *nonce
			r.Nonce = &value
		}
		return nil
	})
}
func (j *scriptJournal) AcceptClaim(id string, claim Envelope) error {
	return j.change(func(next *scriptJournalState) error {
		r := next.Entries[id]
		if r == nil || r.Phase != "claiming" {
			return errScriptContract
		}
		command, hash, err := j.command(r.Original, true)
		if err != nil {
			return err
		}
		if _, err = verifyScriptClaim(claim, j.key, command, hash, r.BootID, r.Desktop, r.Nonce, j.now()); err != nil {
			return errScriptContract
		}
		r.Claim = &claim
		r.Phase = "claimed"
		return nil
	})
}
func (j *scriptJournal) Enter(id, job string) error {
	return j.change(func(next *scriptJournalState) error {
		r := next.Entries[id]
		if r == nil || r.Phase != "claimed" || !scriptUUID.MatchString(job) {
			return errScriptContract
		}
		command, hash, err := j.command(r.Original, true)
		if err != nil {
			return err
		}
		if _, err = verifyScriptClaim(*r.Claim, j.key, command, hash, r.BootID, r.Desktop, r.Nonce, j.now()); err != nil {
			return errScriptContract
		}
		r.Phase = "entered"
		r.JobID = job
		return nil
	})
}
func (j *scriptJournal) Suspended(id string, pid uint32, created uint64) error {
	return j.change(func(next *scriptJournalState) error {
		r := next.Entries[id]
		if r == nil || r.Phase != "entered" || pid == 0 || created == 0 {
			return errScriptContract
		}
		r.Process = &scriptProcessIdentity{PID: pid, Created: strconv.FormatUint(created, 10)}
		r.Phase = "suspended"
		return nil
	})
}
func (j *scriptJournal) Running(id string) error {
	return j.change(func(next *scriptJournalState) error {
		r := next.Entries[id]
		if r == nil || r.Phase != "suspended" {
			return errScriptContract
		}
		command, hash, err := j.command(r.Original, true)
		if err != nil {
			return err
		}
		if _, err = verifyScriptClaim(*r.Claim, j.key, command, hash, r.BootID, r.Desktop, r.Nonce, j.now()); err != nil {
			return errScriptContract
		}
		r.Phase = "running"
		return nil
	})
}
func (j *scriptJournal) Finish(id string, result scriptObject) error {
	raw, err := json.Marshal(result)
	if err != nil || len(raw) > 8192 {
		return errScriptContract
	}
	normalized, err := scriptDecode(raw, 8192)
	if err != nil {
		return errScriptContract
	}
	raw, _ = json.Marshal(normalized)
	return j.change(func(next *scriptJournalState) error {
		r := next.Entries[id]
		if r == nil {
			return errScriptContract
		}
		command, hash, err := j.command(r.Original, false)
		if err != nil || !validScriptResult(normalized, command, hash) || normalized["boot_id"] != r.BootID || normalized["mutation_started"] != (r.JobID != "") {
			return errScriptContract
		}
		if r.Phase == "terminal" {
			existing, err := scriptDecode(r.Result, 8192)
			if err != nil {
				return err
			}
			old, _ := json.Marshal(existing)
			if !bytes.Equal(old, raw) {
				return errScriptContract
			}
			return nil
		}
		r.Result = append(json.RawMessage(nil), raw...)
		r.Phase = "terminal"
		return nil
	})
}
func (j *scriptJournal) Acknowledge(id string, raw []byte) error {
	ack, err := scriptDecode(raw, 1024)
	if err != nil {
		return errScriptContract
	}
	_, ok := scriptKeys(ack, "command_uuid", "attempt_uuid", "payload_hash", "accepted")
	if !ok || ack["accepted"] != true {
		return errScriptContract
	}
	return j.change(func(next *scriptJournalState) error {
		r := next.Entries[id]
		if r == nil || r.Phase != "terminal" {
			return errScriptContract
		}
		command, _, err := j.command(r.Original, false)
		if err != nil || ack["command_uuid"] != command["command_uuid"] || ack["attempt_uuid"] != id || ack["payload_hash"] != r.Hash {
			return errScriptContract
		}
		if r.ReportedAt == "" {
			r.ReportedAt = j.now().Format("2006-01-02T15:04:05Z")
		}
		return nil
	})
}
func (j *scriptJournal) Retire() error {
	return j.change(func(next *scriptJournalState) error {
		watermark, _ := scriptInstant(next.RetiredBefore)
		for id, r := range next.Entries {
			if r.Phase != "terminal" || r.ReportedAt == "" || r.JobID != "" {
				continue
			}
			result, err := scriptDecode(r.Result, 8192)
			if err != nil {
				return err
			}
			if result["mutation_started"] != false || result["status"] == "uncertain" {
				continue
			}
			command, _, err := j.command(r.Original, false)
			if err != nil {
				return err
			}
			end, _ := scriptInstant(command["expires_at"])
			reported, _ := scriptInstant(r.ReportedAt)
			if j.now().Before(end.Add(30*time.Second)) || j.now().Before(reported.Add(180*time.Second)) {
				continue
			}
			delete(next.Entries, id)
			if end.After(watermark) {
				watermark = end
			}
		}
		if !watermark.IsZero() {
			next.RetiredBefore = watermark.Format("2006-01-02T15:04:05Z")
		}
		return nil
	})
}
func (j *scriptJournal) Snapshot() (map[string]*scriptRecord, bool, error) {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.store.scriptJournalGate.Lock()
	defer j.store.scriptJournalGate.Unlock()
	if !j.current() {
		return nil, true, errScriptContract
	}
	next := j.clone()
	held := false
	for _, r := range next.Entries {
		if r.Phase == "prepared" {
			continue
		}
		if r.Phase != "terminal" {
			held = true
			continue
		}
		result, err := scriptDecode(r.Result, 8192)
		if err != nil {
			return nil, true, errScriptContract
		}
		held = held || result["mutation_started"] == true || result["status"] == "uncertain"
	}
	return next.Entries, held, nil
}
