package agent

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"testing"
	"time"
)

// Reads an original PHP HTTP receipt after withdrawal and lease expiry.
// No endpoint, script interpreter, output upload, or network is exercised.
func TestScriptOriginalHTTPResult(t *testing.T) {
	for _, fixture := range []struct{ name, env string }{
		{"attended", "OBLI_SCRIPT_RESULT_EXPORT"},
		{"unattended", "OBLI_SCRIPT_UNATTENDED_EXPORT"},
	} {
		t.Run(fixture.name, func(t *testing.T) {
			path := scriptFixturePath(fixture.env)
			if path == "" {
				t.Skip("original script HTTP result export not supplied")
			}
			checkScriptOriginalHTTPResult(t, path)
		})
	}
}

func checkScriptOriginalHTTPResult(t *testing.T, path string) {
	t.Helper()
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		PublicKey string `json:"public_key"`
		AgentUUID string `json:"agent_uuid"`
		Command   Envelope
		Claim     Envelope
		Result    json.RawMessage
		Ack       struct {
			CommandUUID string `json:"command_uuid"`
			AttemptUUID string `json:"attempt_uuid"`
			PayloadHash string `json:"payload_hash"`
			Accepted    bool
		}
	}
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	key, err := base64.StdEncoding.DecodeString(f.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	payload, err := base64.StdEncoding.DecodeString(f.Command.Payload)
	if err != nil {
		t.Fatal(err)
	}
	stored, err := scriptDecode(payload, 32768)
	if err != nil {
		t.Fatal(err)
	}
	issued, err := time.Parse(time.RFC3339, scriptString(stored["issued_at"]))
	if err != nil {
		t.Fatal(err)
	}
	command, err := verifyScriptCommand(f.Command, key, f.AgentUUID, issued)
	if err != nil {
		t.Fatal("original signed dispatch rejected", err)
	}
	sum := sha256.Sum256(payload)
	hash := hex.EncodeToString(sum[:])
	result, err := scriptDecode(f.Result, 8192)
	if err != nil || !validScriptResult(result, command, hash) {
		t.Fatal("original accepted result violates native contract", err)
	}
	if !f.Ack.Accepted || f.Ack.CommandUUID != command["command_uuid"] ||
		f.Ack.AttemptUUID != command["attempt_uuid"] || f.Ack.PayloadHash != hash {
		t.Fatal("HTTP acknowledgement changed original attempt identity")
	}
	claimBytes, err := base64.StdEncoding.DecodeString(f.Claim.Payload)
	if err != nil {
		t.Fatal(err)
	}
	claim, err := scriptDecode(claimBytes, 8192)
	if err != nil {
		t.Fatal(err)
	}
	claimTime, err := time.Parse(time.RFC3339, scriptString(claim["issued_at"]))
	if err != nil {
		t.Fatal(err)
	}
	var desktop, nonce *string
	if value, ok := claim["desktop_session"].(string); ok {
		desktop = &value
	}
	if value, ok := claim["consent_nonce"].(string); ok {
		nonce = &value
	}
	if _, err := verifyScriptClaim(f.Claim, key, command, hash, scriptString(result["boot_id"]),
		desktop, nonce, claimTime); err != nil {
		t.Fatal("historical receipt lost original claim binding", err)
	}
	if _, err := verifyScriptClaim(f.Claim, key, command, hash, scriptString(result["boot_id"]),
		desktop, nonce, claimTime.Add(2*time.Hour)); err == nil {
		t.Fatal("historical receipt renewed execution authority")
	}
	result["status"] = "cancelled"
	if validScriptResult(result, command, hash) {
		t.Fatal("entered operation falsely accepted as cancelled")
	}
}
