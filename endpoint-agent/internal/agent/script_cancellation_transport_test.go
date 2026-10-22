package agent

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

// Original PHP HTTP stop envelopes. No network, process, or native job is run.
func TestScriptOriginalHTTPCancellation(t *testing.T) {
	path := scriptFixturePath("OBLI_SCRIPT_CANCEL_EXPORT")
	if path == "" {
		t.Skip("original script HTTP cancellation export not supplied")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		PublicKey   string `json:"public_key"`
		AgentUUID   string `json:"agent_uuid"`
		Command     Envelope
		First       Envelope
		Renewed     Envelope
		FirstTime   string `json:"first_time"`
		RenewedTime string `json:"renewed_time"`
		Receipt     struct {
			CommandUUID string `json:"command_uuid"`
			AttemptUUID string `json:"attempt_uuid"`
			RequestKey  string `json:"request_key"`
			Disposition string
		}
	}
	if json.Unmarshal(raw, &f) != nil {
		t.Fatal("invalid original HTTP fixture")
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
		t.Fatal("original dispatch invalid", err)
	}
	sum := sha256.Sum256(payload)
	hash := hex.EncodeToString(sum[:])
	firstTime, err := time.Parse(time.RFC3339, f.FirstTime)
	if err != nil {
		t.Fatal(err)
	}
	renewedTime, err := time.Parse(time.RFC3339, f.RenewedTime)
	if err != nil {
		t.Fatal(err)
	}
	first, err := verifyScriptCancellation(f.First, key, command, hash, firstTime)
	if err != nil {
		t.Fatal("original stop message rejected", err)
	}
	renewed, err := verifyScriptCancellation(f.Renewed, key, command, hash, renewedTime)
	if err != nil {
		t.Fatal("renewed stop message rejected", err)
	}
	if first["payload_hash"] != renewed["payload_hash"] || first["attempt_uuid"] != renewed["attempt_uuid"] ||
		first["expires_at"] == renewed["expires_at"] || f.First == f.Renewed {
		t.Fatal("stop renewal changed intent or failed to renew delivery")
	}
	if f.Receipt.CommandUUID != command["command_uuid"] || f.Receipt.AttemptUUID != command["attempt_uuid"] ||
		f.Receipt.Disposition != "cancellation_requested" || !scriptUUID.MatchString(f.Receipt.RequestKey) {
		t.Fatal("operator receipt lost canonical identity")
	}
	if _, err := verifyScriptCommand(f.Command, key, f.AgentUUID, firstTime); err == nil {
		t.Fatal("stop delivery renewed expired execution authority")
	}
	if _, err := verifyScriptCommand(f.Renewed, key, f.AgentUUID, renewedTime); err == nil {
		t.Fatal("stop envelope accepted as an execution grant")
	}
	for _, name := range []string{"expired_stop", "future_stop", "foreign_hash", "foreign_attempt", "foreign_agent", "invalid_signature"} {
		t.Run(name, func(t *testing.T) {
			copyCommand := make(scriptObject, len(command))
			for k, v := range command {
				copyCommand[k] = v
			}
			envelope, digest, at := f.Renewed, hash, renewedTime
			switch name {
			case "expired_stop":
				envelope = f.First
			case "future_stop":
				at = firstTime
			case "foreign_hash":
				digest = strings.Repeat("f", 64)
			case "foreign_attempt":
				copyCommand["attempt_uuid"] = "11111111-1111-4111-8111-111111111111"
			case "foreign_agent":
				copyCommand["agent_uuid"] = "11111111-1111-4111-8111-111111111111"
			case "invalid_signature":
				envelope.Signature = base64.StdEncoding.EncodeToString(make([]byte, 64))
			}
			if _, err := verifyScriptCancellation(envelope, key, copyCommand, digest, at); err == nil {
				t.Fatal("invalid stop delivery accepted")
			}
		})
	}
}
