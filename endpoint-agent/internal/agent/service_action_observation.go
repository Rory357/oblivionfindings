package agent

import (
	"context"

	"sort"
	"time"
)

type ServiceActionObservation struct {
	CommandUUID string `json:"command_uuid"`
	AttemptUUID string `json:"attempt_uuid"`
	ServiceName string `json:"service_name"`
	State       string `json:"state"`
	ObservedAt  string `json:"observed_at"`
}

func (r *ServiceActionController) observations(ctx context.Context) []ServiceActionObservation {
	r.mu.Lock()
	var pending []ServiceCommand
	acked := map[string]bool{}
	if r.ready {
		for _, entry := range r.journal.Entries {
			if entry.Phase == "terminal" && entry.Reported && entry.Result.MutationStarted {
				reportedAt, _ := time.Parse(time.RFC3339, entry.ReportedAt)
				if r.clock().After(reportedAt.Add(time.Second)) {
					pending = append(pending, entry.Command)
					acked[entry.Command.AttemptUUID] = entry.ObservationAcked
				}
			}
		}
	}
	r.mu.Unlock()
	sort.Slice(pending, func(i, j int) bool {
		if acked[pending[i].AttemptUUID] != acked[pending[j].AttemptUUID] {
			return !acked[pending[i].AttemptUUID]
		}
		return pending[i].AttemptUUID < pending[j].AttemptUUID
	})
	if len(pending) > 8 {
		pending = pending[:8]
	}
	var result []ServiceActionObservation
	backend, ok := r.executor.(boundedServiceExecutor)
	if !ok {
		return result
	}
	for _, command := range pending {
		if ctx.Err() != nil {
			return result
		}
		state := "unknown"
		service, e := backend.backend.Open(command.Parameters.ServiceName, "observe")
		if e == nil {
			if current, e := service.State(); e == nil && (current == "running" || current == "stopped") {
				state = current
			}
			service.Close()
		}
		result = append(result, ServiceActionObservation{command.CommandUUID, command.AttemptUUID, command.Parameters.ServiceName, state, r.clock().UTC().Format(time.RFC3339)})
	}
	return result
}
func (r *ServiceActionController) acknowledgeObservations(payload []byte, accepted []ServiceObservationAcceptance) {
	observations, e := acceptedServiceObservations(payload, accepted)
	if e != nil {
		return
	}
	for _, observation := range observations {
		select {
		case r.observationAcks <- observation:
		default:
		}
	}
}
func (r *ServiceActionController) retire() error {
	r.mu.Lock()
	defer r.mu.Unlock()
	changed := false
	for {
		select {
		case observation := <-r.observationAcks:
			if entry := r.journal.Entries[observation.AttemptUUID]; entry != nil && entry.Command.CommandUUID == observation.CommandUUID && entry.Command.Parameters.ServiceName == observation.ServiceName && entry.Phase == "terminal" && entry.Reported {
				entry.ObservationAcked = true
				changed = true
			}
		default:
			goto drained
		}
	}
drained:
	watermark, _ := time.Parse(time.RFC3339, r.journal.RetiredBefore)
	for id, entry := range r.journal.Entries {
		expires, e := time.Parse(time.RFC3339, entry.Command.ExpiresAt)
		if e != nil {
			return errServiceAction
		}
		if entry.Phase == "terminal" && entry.Reported && (!entry.Result.MutationStarted || entry.ObservationAcked) && r.clock().After(expires.Add(30*time.Second)) && pastReportedRetention(entry.ReportedAt, r.clock()) {
			if expires.After(watermark) {
				watermark = expires
			}
			delete(r.journal.Entries, id)
			changed = true
		}
	}
	if changed {
		if !watermark.IsZero() {
			r.journal.RetiredBefore = watermark.UTC().Format(time.RFC3339)
		}
		if r.journal.save() != nil {
			return errServiceAction
		}
	}
	return nil
}

func pastReportedRetention(value string, now time.Time) bool {
	at, e := time.Parse(time.RFC3339, value)
	return e == nil && now.After(at.Add(180*time.Second))
}
