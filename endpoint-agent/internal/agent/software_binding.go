package agent

import (
	"context"
	"encoding/json"
	"sort"
)

func (m *StatusMonitor) SetSoftware(r *SoftwareController) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.software = r
}
func (m *StatusMonitor) softwareController() *SoftwareController {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.software
}
func (m *StatusMonitor) Software(peer RemotePeer, d SoftwareDecision) (SoftwareView, error) {
	r := m.softwareController()
	if r == nil {
		return SoftwareView{}, errServiceAction
	}
	return r.Handle(peer, d)
}
func (r *SoftwareController) available() bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.ready && r.executionEnabled
}
func (r *SoftwareController) bind(ctx context.Context, s *Store, c *Client, state State, connected func() bool) (func(), error) {
	if s.writeProtected == nil || s.validateProtected == nil {
		return nil, errServiceAction
	}
	j, e := loadSoftwareJournal(s, state.AgentUUID)
	if e != nil {
		return nil, e
	}
	r.mu.Lock()
	r.journal = j
	r.mutationGate = &s.mutationGate
	r.agent = state.AgentUUID
	r.origin = c.base
	r.key = c.key
	r.transport = softwareHTTP{c, state.Credential}
	r.connected = connected
	for _, x := range j.Entries {
		if x.Result != nil && x.Result.MutationStarted && x.ObservationStatus == "" {
			if !r.gateHeld {
				if !r.mutationGate.TryLock() {
					r.mu.Unlock()
					return nil, errServiceAction
				}
				r.gateHeld = true
			}
			r.active = x
		}
		if x.Phase != "terminal" {
			if r.active != nil {
				r.mu.Unlock()
				return nil, errServiceAction
			}
			r.active = x
		}
	}
	r.ready = true
	r.mu.Unlock()
	s.softwareReady = r.available
	s.softwareObservations = r.observations
	worker, cancel := context.WithCancel(ctx)
	done := make(chan struct{})
	go func() { defer close(done); r.Run(worker) }()
	return func() { cancel(); <-done }, nil
}
func (r *SoftwareController) observations(boot, observed string) []SoftwareObservation {
	r.mu.Lock()
	r.boot = boot
	when, e := restartTime(observed)
	if e == nil {
		r.bootAt = when
	}
	var entries []*softwareEntry
	for _, x := range r.journal.Entries {
		if x.Phase == "terminal" && x.Result.MutationStarted && x.Reported && (x.ObservationStatus == "" || (x.Result.RebootRequired && !x.RebootAcknowledged && boot != x.BeforeBootID)) {
			copy := *x
			entries = append(entries, &copy)
		}
	}
	offset := r.observationCursor
	r.observationCursor += 8
	r.mu.Unlock()
	sort.Slice(entries, func(i, j int) bool { return entries[i].Command.AttemptUUID < entries[j].Command.AttemptUUID })
	var rows []SoftwareObservation
	for index := 0; index < len(entries); index++ {
		x := entries[(int(offset%uint64(len(entries)))+index)%len(entries)]
		if len(rows) == 8 {
			break
		}
		quiescent := false
		if boot != x.BeforeBootID && uuidPattern.MatchString(boot) {
			quiescent, e = r.executor.Quiescent(nil)
		} else if x.Process != nil {
			quiescent, e = r.executor.Quiescent(x.Process)
		}
		if e != nil {
			quiescent = false
		}
		state, version, err := r.executor.Observe(x.Command.Parameters.ProductCode)
		if err != nil {
			state = "unknown"
			version = nil
		}
		rows = append(rows, SoftwareObservation{x.Command.CommandUUID, x.Command.AttemptUUID, x.Command.Parameters.ProductCode, version, state, boot, quiescent, observed})
	}
	r.mu.Lock()
	r.lastObservations = rows
	r.mu.Unlock()
	return rows
}
func validateSoftwareACK(payload []byte, accepted []SoftwareAcceptance) error {
	if len(accepted) > 8 {
		return errServiceAction
	}
	var h struct {
		BootID string                `json:"boot_id"`
		Rows   []SoftwareObservation `json:"software_observations"`
	}
	if json.Unmarshal(payload, &h) != nil {
		return errServiceAction
	}
	seen := map[string]bool{}
	for _, a := range accepted {
		if !validSoftwareObservation(a.SoftwareObservation) || seen[a.AttemptUUID] || a.BootID != h.BootID || !a.TransactionQuiescent || (a.Status != "succeeded" && a.Status != "uncertain") || (a.State != "installed" && a.State != "advertised" && a.State != "absent") {
			return errServiceAction
		}
		seen[a.AttemptUUID] = true
		matched := false
		for _, o := range h.Rows {
			left, _ := json.Marshal(a.SoftwareObservation)
			right, _ := json.Marshal(o)
			if string(left) == string(right) {
				matched = true
			}
		}
		if !matched {
			return errServiceAction
		}
	}
	return nil
}
func validateSoftwareACKJSON(b []byte) bool {
	var root map[string]json.RawMessage
	if json.Unmarshal(b, &root) != nil {
		return false
	}
	v, ok := root["software_observations_accepted"]
	if !ok {
		return true
	}
	var rows []json.RawMessage
	if string(v) == "null" || json.Unmarshal(v, &rows) != nil || len(rows) > 8 {
		return false
	}
	for _, row := range rows {
		var m map[string]json.RawMessage
		if json.Unmarshal(row, &m) != nil || len(m) != 9 {
			return false
		}
		for _, n := range []string{"command_uuid", "attempt_uuid", "product_code", "product_version", "state", "boot_id", "transaction_quiescent", "observed_at", "status"} {
			if m[n] == nil || (n != "product_version" && string(m[n]) == "null") {
				return false
			}
		}
	}
	return true
}
func (r *SoftwareController) acknowledge(payload []byte, accepted []SoftwareAcceptance) {
	if validateSoftwareACK(payload, accepted) != nil {
		return
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	for _, a := range accepted {
		x := r.journal.Entries[a.AttemptUUID]
		if x == nil || x.Result == nil || !x.Result.MutationStarted || !x.Reported || x.Command.CommandUUID != a.CommandUUID || x.Command.Parameters.ProductCode != a.ProductCode {
			continue
		}
		if a.Status == "succeeded" && (x.Result.Status != "awaiting_observation" || a.State != "installed" || a.ProductVersion == nil || *a.ProductVersion != x.Command.Parameters.ProductVersion) {
			continue
		}
		if x.Result.ReasonCode == "restart_initiated" && a.BootID == x.BeforeBootID {
			continue
		}
		x.ObservationStatus = a.Status
		if x.Result.RebootRequired && a.BootID != x.BeforeBootID && (x.Result.Status != "awaiting_observation" || (a.State == "installed" && a.ProductVersion != nil && *a.ProductVersion == x.Command.Parameters.ProductVersion)) {
			x.RebootAcknowledged = true
		}
		if r.journal.save() != nil {
			r.ready = false
			return
		}
	}
	if r.gateHeld {
		pending := false
		for _, x := range r.journal.Entries {
			if x.Result != nil && x.Result.MutationStarted && x.ObservationStatus == "" {
				pending = true
			}
		}
		if !pending {
			r.mutationGate.Unlock()
			r.gateHeld = false
		}
	}
}

func validSoftwareObservation(o SoftwareObservation) bool {
	if !uuidPattern.MatchString(o.CommandUUID) || !uuidPattern.MatchString(o.AttemptUUID) || !softwareProductCode.MatchString(o.ProductCode) || !uuidPattern.MatchString(o.BootID) {
		return false
	}
	if _, e := restartTime(o.ObservedAt); e != nil {
		return false
	}
	switch o.State {
	case "installed", "advertised":
		if o.ProductVersion == nil {
			return false
		}
		_, ok := softwareVersion(*o.ProductVersion)
		return ok
	case "absent", "unknown":
		return o.ProductVersion == nil
	}
	return false
}
