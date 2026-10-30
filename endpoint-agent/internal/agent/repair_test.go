package agent

import (
	"encoding/base64"
	"encoding/json"
	"reflect"
	"strings"
	"testing"
)

func TestRepairIdentityInspectionPreservesPendingDataAndRequiresExistingAuthority(t *testing.T) {
	original := State{Version: 1, AgentUUID: "01234567-89ab-4cde-8f01-23456789abcd", ServerURL: "https://care.example.test", ServerKey: base64.StdEncoding.EncodeToString(make([]byte, 32)), Credential: strings.Repeat("c", 64), Sequence: 42, PendingHeartbeat: json.RawMessage(`{"sequence":43}`)}
	state := original
	if err := checkRepairIdentity(state, original.AgentUUID, original.ServerURL); err != nil || !reflect.DeepEqual(state, original) {
		t.Fatal("valid identity changed or refused", err)
	}
	for _, change := range []func(*State){func(s *State) { s.AgentUUID = "" }, func(s *State) { s.ServerURL = "https://other.example.test" }, func(s *State) { s.ServerKey = "invalid" }, func(s *State) { s.EnrollmentToken = "new grant" }, func(s *State) { s.ExpectedAgentUUID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa" }} {
		state = original
		change(&state)
		if err := checkRepairIdentity(state, original.AgentUUID, original.ServerURL); err == nil {
			t.Fatal("repair permitted reassignment or incomplete enrolment")
		}
	}
}

func TestRepairConfirmationIgnoresHeartbeatsButBindsCredentialAndAuthority(t *testing.T) {
	state := State{Version: 1, AgentUUID: "01234567-89ab-4cde-8f01-23456789abcd", ServerURL: "https://care.example.test", ServerKey: "original-key", Credential: strings.Repeat("a", 64)}
	digest := repairIdentityBinding(state)
	state.Sequence = 999
	state.PendingHeartbeat = json.RawMessage(`{"sequence":1000}`)
	if repairIdentityBinding(state) != digest {
		t.Fatal("routine report invalidated installed connection review")
	}
	for _, change := range []func(*State){func(v *State) { v.Credential = strings.Repeat("b", 64) }, func(v *State) { v.ServerKey = "changed" }, func(v *State) { v.AgentUUID = "different" }, func(v *State) { v.ServerURL = "https://other.example.test" }} {
		changed := state
		change(&changed)
		if repairIdentityBinding(changed) == digest {
			t.Fatal("changed authority retained confirmation")
		}
	}
}
