package agent

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
)

func checkRepairIdentity(state State, uuid, server string) error {
	if !uuidPattern.MatchString(uuid) || state.AgentUUID != uuid || state.ServerURL != server ||
		state.EnrollmentToken != "" || state.ExpectedAgentUUID != "" && state.ExpectedAgentUUID != uuid {
		return errors.New("computer identity does not match the installed record")
	}
	if _, err := NewClient(state.ServerURL, state.ServerKey); err != nil {
		return errors.New("computer authority requires approved recovery")
	}
	return nil
}

func repairIdentityBinding(state State) string {
	data, _ := json.Marshal(struct {
		Version                       int
		UUID, Server, Key, Credential string
	}{state.Version, state.AgentUUID, state.ServerURL, state.ServerKey, state.Credential})
	defer clear(data)
	digest := sha256.Sum256(append([]byte("OBLIVION-REPAIR-IDENTITY-V1\n"), data...))
	return hex.EncodeToString(digest[:])
}
