package agent

import (
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type softwareProcessIdentity struct {
	PID     uint32 `json:"pid"`
	Created uint64 `json:"created"`
}
type softwareEntry struct {
	Command            SoftwareCommand          `json:"command"`
	Hash               string                   `json:"hash"`
	Phase              string                   `json:"phase"`
	BeforeBootID       string                   `json:"before_boot_id"`
	Process            *softwareProcessIdentity `json:"process"`
	Result             *SoftwareResult          `json:"result"`
	Reported           bool                     `json:"reported"`
	ReportedAt         string                   `json:"reported_at"`
	RebootAcknowledged bool                     `json:"reboot_acknowledged"`
	ObservationStatus  string                   `json:"observation_status"`
}
type softwareJournal struct {
	Version       int                       `json:"version"`
	AgentUUID     string                    `json:"agent_uuid"`
	RetiredBefore string                    `json:"retired_before"`
	Entries       map[string]*softwareEntry `json:"entries"`
	store         *Store
}

func loadSoftwareJournal(s *Store, agent string) (*softwareJournal, error) {
	j := &softwareJournal{Version: 1, AgentUUID: agent, Entries: map[string]*softwareEntry{}, store: s}
	name := filepath.Join(s.dir, "software-actions.json")
	if rejectLink(name) != nil {
		return nil, errServiceAction
	}
	f, e := os.Open(name)
	if os.IsNotExist(e) {
		return j, nil
	}
	if e != nil {
		return nil, e
	}
	defer f.Close()
	if s.validateProtected != nil && s.validateProtected("software-actions.json") != nil {
		return nil, errServiceAction
	}
	b, e := io.ReadAll(io.LimitReader(f, 2*1024*1024+1))
	if e != nil || len(b) > 2*1024*1024 || uniqueServiceJournal(b) != nil || !serviceClosedObject(b, []string{"version", "agent_uuid", "retired_before", "entries"}) || json.Unmarshal(b, j) != nil || j.Version != 1 || j.AgentUUID != agent || j.Entries == nil || len(j.Entries) > 256 {
		return nil, errServiceAction
	}
	j.store = s
	var root map[string]json.RawMessage
	_ = json.Unmarshal(b, &root)
	var rows map[string]json.RawMessage
	_ = json.Unmarshal(root["entries"], &rows)
	if j.RetiredBefore != "" {
		if _, e := restartTime(j.RetiredBefore); e != nil {
			return nil, errServiceAction
		}
	}
	for id, x := range j.Entries {
		var m map[string]json.RawMessage
		_ = json.Unmarshal(rows[id], &m)
		if len(m) != 10 || x == nil || id != x.Command.AttemptUUID || x.Command.AgentUUID != agent || !validSoftwareCommand(x.Command) || !softwareCommandShape(m["command"]) || !hexCredential(x.Hash) || !uuidPattern.MatchString(x.BeforeBootID) {
			return nil, errServiceAction
		}
		for _, n := range []string{"command", "hash", "phase", "before_boot_id", "reported", "reported_at", "observation_status", "reboot_acknowledged"} {
			if m[n] == nil || string(m[n]) == "null" {
				return nil, errServiceAction
			}
		}
		if m["process"] == nil || m["result"] == nil {
			return nil, errServiceAction
		}
		if x.Process != nil && (!serviceClosedObject(m["process"], []string{"pid", "created"}) || x.Process.PID == 0 || x.Process.Created == 0) {
			return nil, errServiceAction
		}
		if x.Phase == "terminal" {
			if x.Result == nil || !validStoredSoftwareResult(m["result"]) || !validSoftwareResult(*x.Result, x.Command, x.Hash) || x.Result.BootID != x.BeforeBootID {
				return nil, errServiceAction
			}
		} else {
			if x.Result != nil || x.Reported || x.ObservationStatus != "" {
				return nil, errServiceAction
			}
			switch x.Phase {
			case "staging", "requested":
				x.Phase = "staging"
			case "claiming", "entered", "running":
				started := x.Phase != "claiming"
				x.Phase = "terminal"
				x.Result = &SoftwareResult{CommandUUID: x.Command.CommandUUID, PayloadHash: x.Hash, Status: "uncertain", MutationStarted: started, ReasonCode: "interrupted", BootID: x.BeforeBootID}
			default:
				return nil, errServiceAction
			}
		}
		if x.Reported {
			if _, e := restartTime(x.ReportedAt); e != nil {
				return nil, errServiceAction
			}
		} else if x.ReportedAt != "" {
			return nil, errServiceAction
		}
		if x.ObservationStatus != "" && x.ObservationStatus != "succeeded" && x.ObservationStatus != "uncertain" {
			return nil, errServiceAction
		}
		if x.RebootAcknowledged && (x.Result == nil || !x.Result.RebootRequired) {
			return nil, errServiceAction
		}
		if x.ObservationStatus != "" && (x.Result == nil || !x.Result.MutationStarted) {
			return nil, errServiceAction
		}
	}
	if e = f.Close(); e != nil {
		return nil, e
	}
	if e = j.save(); e != nil {
		return nil, e
	}
	if e = j.cleanupArtifacts(); e != nil {
		return nil, e
	}
	return j, nil
}
func validStoredSoftwareResult(b []byte) bool {
	var m map[string]json.RawMessage
	if json.Unmarshal(b, &m) != nil || len(m) != 8 {
		return false
	}
	for _, n := range []string{"command_uuid", "payload_hash", "status", "mutation_started", "reason_code", "reboot_required", "boot_id"} {
		if m[n] == nil || string(m[n]) == "null" {
			return false
		}
	}
	return m["exit_code"] != nil
}
func (j *softwareJournal) save() error {
	b, e := json.Marshal(j)
	if e != nil || len(b) > 2*1024*1024 {
		return errServiceAction
	}
	return j.store.atomicWrite("software-actions.json", b)
}
func (j *softwareJournal) retire(now time.Time) error {
	changed := false
	watermark, _ := restartTime(j.RetiredBefore)
	for id, x := range j.Entries {
		end, _ := restartTime(x.Command.ExpiresAt)
		reported, _ := restartTime(x.ReportedAt)
		if x.Phase != "terminal" || !x.Reported || now.Before(end.Add(30*time.Second)) || now.Before(reported.Add(180*time.Second)) || (x.Result.MutationStarted && x.ObservationStatus == "") || (x.Result.RebootRequired && !x.RebootAcknowledged) {
			continue
		}
		delete(j.Entries, id)
		if end.After(watermark) {
			watermark = end
		}
		changed = true
	}
	if changed {
		j.RetiredBefore = watermark.UTC().Format(time.RFC3339)
		if e := j.save(); e != nil {
			return e
		}
		return j.cleanupArtifacts()
	}
	return nil
}

// Only private MSI filenames from retired attempts are removed, after the durable retirement watermark.
func (j *softwareJournal) cleanupArtifacts() error {
	items, e := os.ReadDir(j.store.dir)
	if e != nil {
		return e
	}
	count := 0
	for _, item := range items {
		name := item.Name()
		if !strings.HasPrefix(name, "software-") || !strings.HasSuffix(name, ".msi") {
			continue
		}
		id := strings.TrimSuffix(strings.TrimPrefix(name, "software-"), ".msi")
		if !uuidPattern.MatchString(id) {
			return errServiceAction
		}
		count++
		if count > 256 {
			return errServiceAction
		}
		if j.Entries[id] != nil {
			continue
		}
		path := filepath.Join(j.store.dir, name)
		if rejectLink(path) != nil {
			return errServiceAction
		}
		if j.store.validateProtected != nil && j.store.validateProtected(name) != nil {
			return errServiceAction
		}
		if e = os.Remove(path); e != nil {
			return e
		}
	}
	return nil
}
