package agent

import (
	"encoding/json"
)

type ServiceObservationAcceptance struct {
	CommandUUID string `json:"command_uuid"`
	AttemptUUID string `json:"attempt_uuid"`
	ObservedAt  string `json:"observed_at"`
}

func validateServiceObservationACKJSON(raw []byte) bool {
	var root map[string]json.RawMessage
	if json.Unmarshal(raw, &root) != nil {
		return false
	}
	value, present := root["service_action_observations_accepted"]
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
		if !serviceClosedObject(row, []string{"command_uuid", "attempt_uuid", "observed_at"}) {
			return false
		}
	}
	return true
}
func acceptedServiceObservations(payload []byte, accepted []ServiceObservationAcceptance) ([]ServiceActionObservation, error) {
	if len(accepted) == 0 {
		return nil, nil
	}
	if len(accepted) > 8 {
		return nil, errServiceAction
	}
	var heartbeat struct {
		Observations []ServiceActionObservation `json:"service_action_observations"`
	}
	if json.Unmarshal(payload, &heartbeat) != nil || len(heartbeat.Observations) > 8 {
		return nil, errServiceAction
	}
	var result []ServiceActionObservation
	seen := map[string]bool{}
	for _, ack := range accepted {
		if seen[ack.AttemptUUID] {
			return nil, errServiceAction
		}
		seen[ack.AttemptUUID] = true
		matched := false
		for _, observation := range heartbeat.Observations {
			if observation.State != "unknown" && observation.CommandUUID == ack.CommandUUID && observation.AttemptUUID == ack.AttemptUUID && observation.ObservedAt == ack.ObservedAt {
				result = append(result, observation)
				matched = true
				break
			}
		}
		if !matched {
			return nil, errServiceAction
		}
	}
	return result, nil
}
