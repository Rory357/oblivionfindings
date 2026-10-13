package agent

import (
	"bytes"
	"encoding/json"
	"io"
	"time"
	"unicode/utf8"
)

func uniqueServiceJournal(data []byte) error {
	if !utf8.Valid(data) || !serviceUnicodeJSON(data) {
		return errServiceAction
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	var walk func(int) error
	walk = func(depth int) error {
		if depth > 8 {
			return errServiceAction
		}
		token, e := decoder.Token()
		if e != nil {
			return errServiceAction
		}
		delimiter, container := token.(json.Delim)
		if !container {
			return nil
		}
		if delimiter == '{' {
			seen := map[string]bool{}
			for decoder.More() {
				key, e := decoder.Token()
				name, ok := key.(string)
				if e != nil || !ok || seen[name] {
					return errServiceAction
				}
				seen[name] = true
				if walk(depth+1) != nil {
					return errServiceAction
				}
			}
			end, e := decoder.Token()
			if e != nil || end != json.Delim('}') {
				return errServiceAction
			}
			return nil
		}
		if delimiter == '[' {
			for decoder.More() {
				if walk(depth+1) != nil {
					return errServiceAction
				}
			}
			end, e := decoder.Token()
			if e != nil || end != json.Delim(']') {
				return errServiceAction
			}
			return nil
		}
		return errServiceAction
	}
	if walk(0) != nil {
		return errServiceAction
	}
	if _, e := decoder.Token(); e != io.EOF {
		return errServiceAction
	}
	return nil
}
func decodeServiceJournal(data []byte, j *serviceJournal) error {
	if !closedJournalStructure(data) {
		return errServiceAction
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if decoder.Decode(j) != nil {
		return errServiceAction
	}
	return nil
}
func validServiceResult(r ServiceResult, c ServiceCommand, hash string) bool {
	if r.CommandUUID != c.CommandUUID || r.PayloadHash != hash || r.ServiceName != c.Parameters.ServiceName || r.Operation != c.Parameters.Operation {
		return false
	}
	if r.BeforeState != "running" && r.BeforeState != "stopped" && r.BeforeState != "unknown" {
		return false
	}
	if r.AfterState != "running" && r.AfterState != "stopped" && r.AfterState != "unknown" {
		return false
	}
	switch r.Status {
	case "succeeded":
		target := "running"
		if r.Operation == "stop" {
			target = "stopped"
		}
		if r.AfterState != target {
			return false
		}
		if r.ReasonCode == "already_in_state" {
			return !r.MutationStarted && r.BeforeState == r.AfterState && r.Operation != "restart"
		}
		return r.ReasonCode == "confirmed" && r.MutationStarted
	case "declined":
		return !r.MutationStarted && r.ReasonCode == "declined"
	case "expired":
		return !r.MutationStarted && r.ReasonCode == "expired"
	case "failed":
		if r.MutationStarted {
			return false
		}
	case "uncertain":
	default:
		return false
	}
	for _, reason := range []string{"policy_unavailable", "consent_lost", "service_unavailable", "service_busy", "dependent_services", "unsupported_service", "disabled_service", "operation_failed", "operation_timeout", "interrupted", "state_unavailable"} {
		if r.ReasonCode == reason {
			return true
		}
	}
	return false
}

func closedJournalStructure(data []byte) bool {
	var root map[string]json.RawMessage
	if json.Unmarshal(data, &root) != nil || !serviceClosedObject(data, []string{"version", "agent_uuid", "retired_before", "entries"}) {
		return false
	}
	var entries map[string]json.RawMessage
	if json.Unmarshal(root["entries"], &entries) != nil || entries == nil {
		return false
	}
	var retired string
	if json.Unmarshal(root["retired_before"], &retired) != nil {
		return false
	}
	if retired != "" {
		if _, e := time.Parse("2006-01-02T15:04:05Z", retired); e != nil {
			return false
		}
	}
	for _, raw := range entries {
		var entry map[string]json.RawMessage
		if json.Unmarshal(raw, &entry) != nil || len(entry) != 7 {
			return false
		}
		result, ok := entry["result"]
		if !ok {
			return false
		}
		delete(entry, "result")
		other, _ := json.Marshal(entry)
		if !serviceClosedObject(other, []string{"command", "hash", "phase", "reported", "observation_acked", "reported_at"}) {
			return false
		}
		// Seven named non-result fields? Closed structure below is authoritative.
		var command map[string]json.RawMessage
		if json.Unmarshal(entry["command"], &command) != nil || !serviceClosedObject(entry["command"], []string{"version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "capability", "parameters", "policy", "technician", "purpose", "issued_at", "expires_at"}) || !serviceClosedObject(command["parameters"], []string{"service_name", "operation"}) || !serviceClosedObject(command["policy"], []string{"version", "enabled", "consent_mode", "catalogue_digest"}) {
			return false
		}
		if string(result) != "null" && !serviceClosedObject(result, []string{"command_uuid", "payload_hash", "status", "operation", "service_name", "before_state", "after_state", "mutation_started", "reason_code"}) {
			return false
		}
	}
	return true
}
func validStoredServiceCommand(c ServiceCommand) bool {
	issued, e := time.Parse("2006-01-02T15:04:05Z", c.IssuedAt)
	expires, x := time.Parse("2006-01-02T15:04:05Z", c.ExpiresAt)
	return e == nil && x == nil && expires.After(issued) && expires.Sub(issued) <= 15*time.Minute && c.Version == 2 && c.Type == "endpoint_service_command" && uuidPattern.MatchString(c.AgentUUID) && uuidPattern.MatchString(c.CommandUUID) && uuidPattern.MatchString(c.AttemptUUID) && hexCredential(c.AssignmentFingerprint) && c.Capability == "endpoint.service.manage" && permittedServiceName(c.Parameters.ServiceName) && serviceOperation(c.Parameters.Operation) && c.Policy.Version > 0 && c.Policy.Version <= 9007199254740991 && c.Policy.Enabled && hexCredential(c.Policy.CatalogueDigest) && (c.Policy.ConsentMode == "attended" || c.Policy.ConsentMode == "unattended") && remoteLabel(c.Technician, 1, 200) && remoteLabel(c.Purpose, 1, 500)
}
