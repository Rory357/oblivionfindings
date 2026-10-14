package agent

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"time"
)

type Envelope struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}
type Command struct {
	Version     int             `json:"version"`
	AgentUUID   string          `json:"agent_uuid"`
	CommandUUID string          `json:"command_uuid"`
	AttemptUUID string          `json:"attempt_uuid"`
	Capability  string          `json:"capability"`
	IssuedAt    string          `json:"issued_at"`
	ExpiresAt   string          `json:"expires_at"`
	Parameters  json.RawMessage `json:"parameters"`
}

// VerifyCommand authenticates the original bytes, not re-serialised JSON.
func VerifyCommand(envelope Envelope, key ed25519.PublicKey, agentUUID string, now time.Time) (Command, error) {
	var command Command
	if len(envelope.Payload) > 180000 || len(envelope.Signature) > 100 {
		return command, errors.New("oversized command envelope")
	}
	payload, err := base64.StdEncoding.Strict().DecodeString(envelope.Payload)
	signature, sigErr := base64.StdEncoding.Strict().DecodeString(envelope.Signature)
	if err != nil || sigErr != nil || len(payload) > 131072 || len(key) != ed25519.PublicKeySize || !ed25519.Verify(key, payload, signature) {
		return command, errors.New("command signature is invalid")
	}
	if err = json.Unmarshal(payload, &command); err != nil {
		return Command{}, errors.New("command payload is invalid")
	}
	issued, issueErr := time.Parse(time.RFC3339, command.IssuedAt)
	expires, expiryErr := time.Parse(time.RFC3339, command.ExpiresAt)
	if command.Version != 1 || command.AgentUUID != agentUUID || !uuidPattern.MatchString(command.AgentUUID) || !uuidPattern.MatchString(command.CommandUUID) || !uuidPattern.MatchString(command.AttemptUUID) ||
		issueErr != nil || expiryErr != nil || !expires.After(now) || issued.After(now.Add(30*time.Second)) || !expires.After(issued) || expires.Sub(issued) > 15*time.Minute {
		return Command{}, errors.New("command identity or validity window is invalid")
	}
	switch command.Capability {
	case "endpoint.inventory.refresh", "endpoint.task.run", "endpoint.service.restart", "device.reboot":
	default:
		return Command{}, errors.New("unsupported endpoint command")
	}
	return command, nil
}
