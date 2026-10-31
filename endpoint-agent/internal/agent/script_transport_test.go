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

// Original authenticated PHP HTTP responses, with synthetic approved dispatch state.
// This validates bytes and leases; it neither contacts a server nor starts a process.
func TestScriptOriginalHTTPTransport(t *testing.T) {
	path := scriptFixturePath("OBLI_SCRIPT_TRANSPORT_EXPORT")
	if path == "" {
		t.Skip("original script HTTP transport export not supplied")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		PublicKey string `json:"public_key"`
		AgentUUID string `json:"agent_uuid"`
		Now       string
		Command   Envelope
		Claim     Envelope
		Artifact  string
		Input     struct {
			PayloadHash string `json:"payload_hash"`
			BootID      string `json:"boot_id"`
			Consent     struct {
				Desktop string `json:"desktop_session"`
				Nonce   string `json:"consent_nonce"`
			}
		} `json:"claim_request"`
	}
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	key, err := base64.StdEncoding.DecodeString(f.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	now, err := time.Parse(time.RFC3339, f.Now)
	if err != nil {
		t.Fatal(err)
	}
	command, err := verifyScriptCommand(f.Command, key, f.AgentUUID, now)
	if err != nil {
		t.Fatal("original HTTP dispatch rejected", err)
	}
	bytes, _ := base64.StdEncoding.DecodeString(f.Command.Payload)
	sum := sha256.Sum256(bytes)
	hash := hex.EncodeToString(sum[:])
	if hash != f.Input.PayloadHash {
		t.Fatal("original payload hash changed")
	}
	artifact, err := base64.StdEncoding.DecodeString(f.Artifact)
	if err != nil {
		t.Fatal(err)
	}
	manifest := command["parameters"].(map[string]any)["manifest"].(map[string]any)
	if !scriptArtifact(manifest, artifact) || scriptArtifact(manifest, append(artifact, ' ')) {
		t.Fatal("exact private artifact binding failed")
	}
	claim, err := verifyScriptClaim(f.Claim, key, command, hash, f.Input.BootID, &f.Input.Consent.Desktop, &f.Input.Consent.Nonce, now)
	if err != nil {
		t.Fatal("original HTTP claim rejected", err)
	}
	again, err := verifyScriptClaim(f.Claim, key, command, hash, f.Input.BootID, &f.Input.Consent.Desktop, &f.Input.Consent.Nonce, now.Add(time.Second))
	if err != nil || again["expires_at"] != claim["expires_at"] {
		t.Fatal("claim retry lost its original lease")
	}
	foreign := "11111111-1111-4111-8111-111111111111"
	for _, tc := range []struct {
		name, boot, desktop, nonce string
		at                         time.Time
	}{
		{"expired", f.Input.BootID, f.Input.Consent.Desktop, f.Input.Consent.Nonce, now.Add(30 * time.Second)},
		{"foreign_boot", foreign, f.Input.Consent.Desktop, f.Input.Consent.Nonce, now},
		{"foreign_desktop", f.Input.BootID, foreign, f.Input.Consent.Nonce, now},
		{"foreign_nonce", f.Input.BootID, f.Input.Consent.Desktop, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", now},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if _, err := verifyScriptClaim(f.Claim, key, command, hash, tc.boot, &tc.desktop, &tc.nonce, tc.at); err == nil {
				t.Fatal("changed or expired execution context accepted")
			}
		})
	}
}
