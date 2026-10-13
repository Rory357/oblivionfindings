package agent

import (
	"context"
	"encoding/json"
)

func validateRestartACK(payload []byte, accepted []RestartAcceptance) error {
	if len(accepted) > 8 {
		return errServiceAction
	}
	var h struct {
		BootID       string               `json:"boot_id"`
		Observations []RestartObservation `json:"restart_observations"`
	}
	if json.Unmarshal(payload, &h) != nil {
		return errServiceAction
	}
	seen := map[string]bool{}
	for _, a := range accepted {
		if seen[a.AttemptUUID] || !uuidPattern.MatchString(a.BootID) || a.BootID != h.BootID || a.BeforeBootID == a.BootID || (a.Status != "succeeded" && a.Status != "uncertain") {
			return errServiceAction
		}
		seen[a.AttemptUUID] = true
		matched := false
		for _, o := range h.Observations {
			if a.CommandUUID == o.CommandUUID && a.AttemptUUID == o.AttemptUUID && a.BeforeBootID == o.BeforeBootID && a.BootID == o.BootID {
				matched = true
			}
		}
		if !matched {
			return errServiceAction
		}
	}
	return nil
}
func (m *StatusMonitor) SetRestarts(r *RestartController) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.restarts = r
}
func (m *StatusMonitor) restartController() *RestartController {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.restarts
}
func (m *StatusMonitor) Restart(peer RemotePeer, d RestartDecision) (RestartView, error) {
	r := m.restartController()
	if r == nil {
		return RestartView{}, errServiceAction
	}
	return r.Handle(peer, d)
}
func (r *RestartController) bind(ctx context.Context, store *Store, client *Client, state State, connected func() bool) (func(), error) {
	if store.writeProtected == nil || store.validateProtected == nil {
		return nil, errServiceAction
	}
	j, e := loadRestartJournal(store, state.AgentUUID)
	if e != nil {
		return nil, e
	}
	r.mu.Lock()
	r.journal = j
	r.mutationGate = &store.mutationGate
	r.agent = state.AgentUUID
	r.origin = client.base
	r.key = client.key
	r.transport = restartHTTP{client, state.Credential}
	r.connected = connected
	if !r.latchRestoredGateLocked() {
		r.mu.Unlock()
		return nil, errServiceAction
	}
	r.ready = true
	for _, x := range j.Entries {
		if x.Phase != "terminal" {
			if r.active != nil {
				r.ready = false
				r.mu.Unlock()
				return nil, errServiceAction
			}
			r.active = x
			r.acceptedSince = r.monotonic()
		}
	}
	r.mu.Unlock()
	store.restartReady = r.executionAvailable
	store.restartObservations = r.heartbeat
	worker, cancel := context.WithCancel(ctx)
	done := make(chan struct{})
	go func() { defer close(done); r.Run(worker) }()
	return func() { cancel(); <-done }, nil
}

func validateRestartACKJSON(raw []byte) bool {
	var root map[string]json.RawMessage
	if json.Unmarshal(raw, &root) != nil {
		return false
	}
	value, present := root["restart_observations_accepted"]
	if !present {
		return true
	}
	if string(value) == "null" {
		return false
	}
	var rows []json.RawMessage
	if json.Unmarshal(value, &rows) != nil || len(rows) > 8 {
		return false
	}
	for _, row := range rows {
		if !serviceClosedObject(row, []string{"command_uuid", "attempt_uuid", "before_boot_id", "boot_id", "status"}) {
			return false
		}
	}
	return true
}

// Restore the physical exclusion before any other mutation worker starts.
func (r *RestartController) latchRestoredGateLocked() bool {
	for _, x := range r.journal.Entries {
		if x.Phase == "terminal" && x.Result.MutationStarted && x.Result.ReasonCode != "restart_refused" && x.BootStatus == "" {
			if r.gateHeld {
				return true
			}
			if !r.mutationGate.TryLock() {
				return false
			}
			r.gateHeld = true
			return true
		}
	}
	return true
}

func (r *RestartController) executionAvailable() bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.ready && r.executionEnabled
}
