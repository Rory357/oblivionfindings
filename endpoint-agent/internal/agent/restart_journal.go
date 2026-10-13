package agent

import (
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"time"
)

type restartEntry struct {
	Command      RestartCommand `json:"command"`
	Hash         string         `json:"hash"`
	Phase        string         `json:"phase"`
	BeforeBootID string         `json:"before_boot_id"`
	Result       *RestartResult `json:"result"`
	Reported     bool           `json:"reported"`
	ReportedAt   string         `json:"reported_at"`
	BootStatus   string         `json:"boot_status"`
}
type restartJournal struct {
	Version       int                      `json:"version"`
	AgentUUID     string                   `json:"agent_uuid"`
	RetiredBefore string                   `json:"retired_before"`
	Entries       map[string]*restartEntry `json:"entries"`
	store         *Store
}

func loadRestartJournal(store *Store, agent string) (*restartJournal, error) {
	j := &restartJournal{Version: 1, AgentUUID: agent, Entries: map[string]*restartEntry{}, store: store}
	name := filepath.Join(store.dir, "restart-actions.json")
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
	if store.validateProtected != nil && store.validateProtected("restart-actions.json") != nil {
		f.Close()
		return nil, errServiceAction
	}
	b, e := io.ReadAll(io.LimitReader(f, 2*1024*1024+1))
	ce := f.Close()
	if e != nil || ce != nil || len(b) > 2*1024*1024 || uniqueServiceJournal(b) != nil || !serviceClosedObject(b, []string{"version", "agent_uuid", "retired_before", "entries"}) || json.Unmarshal(b, j) != nil || j.Version != 1 || j.AgentUUID != agent || j.Entries == nil || len(j.Entries) > 256 {
		return nil, errServiceAction
	}
	var root map[string]json.RawMessage
	_ = json.Unmarshal(b, &root)
	var rows map[string]json.RawMessage
	_ = json.Unmarshal(root["entries"], &rows)
	for id, x := range j.Entries {
		if x == nil || !serviceClosedObject(rows[id], []string{"command", "hash", "phase", "before_boot_id", "result", "reported", "reported_at", "boot_status"}) { // result intentionally nullable
			var fields map[string]json.RawMessage
			_ = json.Unmarshal(rows[id], &fields)
			if len(fields) != 8 || fields["result"] == nil {
				return nil, errServiceAction
			}
			for _, n := range []string{"command", "hash", "phase", "before_boot_id", "reported", "reported_at", "boot_status"} {
				if fields[n] == nil || string(fields[n]) == "null" {
					return nil, errServiceAction
				}
			}
		}
		if x == nil || !validStoredRestartJSON(rows[id]) || !validRestartCommand(x.Command) || x.Command.AgentUUID != agent || id != x.Command.AttemptUUID || !hexCredential(x.Hash) || (x.BeforeBootID != "" && !uuidPattern.MatchString(x.BeforeBootID)) || (x.Phase != "countdown" && x.Phase != "armed" && x.Phase != "api_entered" && x.Phase != "terminal") || x.BootStatus != "" && x.BootStatus != "succeeded" && x.BootStatus != "uncertain" {
			return nil, errServiceAction
		}
		if x.Phase == "terminal" {
			if x.Result == nil || !validRestartResult(*x.Result, x.Command, x.Hash) || x.BeforeBootID != x.Result.BootID {
				return nil, errServiceAction
			}
		} else {
			if x.Result != nil || x.Reported || x.BootStatus != "" {
				return nil, errServiceAction
			}
			if x.Phase == "api_entered" || x.Phase == "armed" {
				if !uuidPattern.MatchString(x.BeforeBootID) {
					return nil, errServiceAction
				}
				entered := x.Phase == "api_entered"
				x.Phase = "terminal"
				x.Result = &RestartResult{x.Command.CommandUUID, x.Hash, "uncertain", entered, "interrupted", x.BeforeBootID}
			} else {
				x.Phase = "countdown"
			}
		}
		if x.BootStatus != "" && (x.Result == nil || !x.Result.MutationStarted || x.BootStatus == "succeeded" && x.Result.ReasonCode != "restart_requested") {
			return nil, errServiceAction
		}
		if x.Reported {
			if _, e = restartTime(x.ReportedAt); e != nil {
				return nil, errServiceAction
			}
		} else if x.ReportedAt != "" {
			return nil, errServiceAction
		}
	}
	if j.RetiredBefore != "" {
		if _, e = restartTime(j.RetiredBefore); e != nil {
			return nil, errServiceAction
		}
	}
	if e = j.save(); e != nil {
		return nil, e
	}
	return j, nil
}
func (j *restartJournal) save() error {
	b, e := json.Marshal(j)
	if e != nil || len(b) > 2*1024*1024 {
		return errServiceAction
	}
	return j.store.atomicWrite("restart-actions.json", b)
}
func (j *restartJournal) retire(now time.Time) error {
	changed := false
	watermark, _ := restartTime(j.RetiredBefore)
	for id, x := range j.Entries {
		expiry, _ := restartTime(x.Command.ExpiresAt)
		reported, _ := restartTime(x.ReportedAt)
		if x.Phase == "terminal" && x.Reported && now.After(expiry.Add(30*time.Second)) && now.After(reported.Add(180*time.Second)) && (!x.Result.MutationStarted || x.Result.ReasonCode == "restart_refused" || x.BootStatus != "") {
			delete(j.Entries, id)
			changed = true
			if expiry.After(watermark) {
				watermark = expiry
			}
		}
	}
	if changed {
		j.RetiredBefore = watermark.UTC().Format(time.RFC3339)
		return j.save()
	}
	return nil
}

func validStoredRestartJSON(raw json.RawMessage) bool {
	if !serviceUnicodeJSON(raw) {
		return false
	}
	var row map[string]json.RawMessage
	_ = json.Unmarshal(raw, &row)
	var c map[string]json.RawMessage
	_ = json.Unmarshal(row["command"], &c)
	if !serviceClosedObject(row["command"], []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "capability", "parameters", "policy", "technician", "purpose", "issued_at", "expires_at"}) || !serviceClosedObject(c["parameters"], []string{"execute_at", "warning_seconds", "force_close"}) || !serviceClosedObject(c["policy"], []string{"version", "enabled", "consent_mode"}) {
		return false
	}
	return string(row["result"]) == "null" || serviceClosedObject(row["result"], []string{"command_uuid", "payload_hash", "status", "mutation_started", "reason_code", "boot_id"})
}
