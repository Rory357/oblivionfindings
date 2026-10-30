package agent

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

const testAgentUUID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"

func testKey() (ed25519.PublicKey, ed25519.PrivateKey) {
	private := ed25519.NewKeyFromSeed([]byte(strings.Repeat("s", 32)))
	return private.Public().(ed25519.PublicKey), private
}
func signedFixture(t *testing.T, mutate func(*Command)) (Envelope, ed25519.PublicKey, time.Time) {
	t.Helper()
	pub, private := testKey()
	now := time.Date(2026, 10, 7, 1, 0, 0, 0, time.UTC)
	command := Command{Version: 1, AgentUUID: testAgentUUID, CommandUUID: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", AttemptUUID: "cccccccc-cccc-4ccc-8ccc-cccccccccccc", Capability: "endpoint.inventory.refresh", IssuedAt: now.Add(-time.Second).Format(time.RFC3339), ExpiresAt: now.Add(time.Minute).Format(time.RFC3339), Parameters: json.RawMessage("{}")}
	if mutate != nil {
		mutate(&command)
	}
	payload, err := json.Marshal(command)
	if err != nil {
		t.Fatal(err)
	}
	return Envelope{Payload: base64.StdEncoding.EncodeToString(payload), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(private, payload))}, pub, now
}
func TestVerifyCommandAcceptsOnlyBoundIdentityAndWindow(t *testing.T) {
	envelope, key, now := signedFixture(t, nil)
	command, err := VerifyCommand(envelope, key, testAgentUUID, now)
	if err != nil || command.Capability != "endpoint.inventory.refresh" {
		t.Fatalf("valid command rejected: %v", err)
	}
	cases := map[string]func(*Command){
		"wrong device":       func(c *Command) { c.AgentUUID = "ffffffff-ffff-4fff-8fff-ffffffffffff" },
		"expired":            func(c *Command) { c.ExpiresAt = now.Format(time.RFC3339) },
		"future issued":      func(c *Command) { c.IssuedAt = now.Add(time.Minute).Format(time.RFC3339) },
		"overlong validity":  func(c *Command) { c.ExpiresAt = now.Add(time.Hour).Format(time.RFC3339) },
		"unknown protocol":   func(c *Command) { c.Version = 2 },
		"unknown capability": func(c *Command) { c.Capability = "arbitrary.shell" },
		"missing attempt":    func(c *Command) { c.AttemptUUID = "" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			envelope, key, now := signedFixture(t, mutate)
			if _, err := VerifyCommand(envelope, key, testAgentUUID, now); err == nil {
				t.Fatal("unsafe command accepted")
			}
		})
	}
}
func TestVerifyCommandRejectsModifiedBytesAndWrongKeys(t *testing.T) {
	envelope, key, now := signedFixture(t, nil)
	payload, _ := base64.StdEncoding.DecodeString(envelope.Payload)
	envelope.Payload = base64.StdEncoding.EncodeToString(append(payload, ' '))
	if _, err := VerifyCommand(envelope, key, testAgentUUID, now); err == nil {
		t.Fatal("changed bytes accepted")
	}
	envelope, _, _ = signedFixture(t, nil)
	key[0] ^= 1
	if _, err := VerifyCommand(envelope, key, testAgentUUID, now); err == nil {
		t.Fatal("wrong key accepted")
	}
	if _, err := VerifyCommand(envelope, nil, testAgentUUID, now); err == nil {
		t.Fatal("missing key accepted")
	}
	envelope.Payload = strings.Repeat("a", 180001)
	if _, err := VerifyCommand(envelope, key, testAgentUUID, now); err == nil {
		t.Fatal("oversized envelope accepted")
	}
}
