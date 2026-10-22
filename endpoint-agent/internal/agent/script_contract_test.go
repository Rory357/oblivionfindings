package agent

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

// This test requires the original PHP contract + EndpointCommandSigner export.
// It does not execute scripts or claim catalogue/authority/installed acceptance.
func TestScriptPHPContractCorpus(t *testing.T) {
	path := os.Getenv("OBLI_SCRIPT_EXPORT")
	if path == "" {
		t.Skip("original PHP script contract export not supplied")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		PublicKey string
		Agent     string
		Now       string
		Runtime   string
		Artifact  string
	}
	var export map[string]json.RawMessage
	if json.Unmarshal(raw, &export) != nil {
		t.Fatal("invalid fixture")
	}
	_ = json.Unmarshal(export["public_key"], &f.PublicKey)
	_ = json.Unmarshal(export["agent_uuid"], &f.Agent)
	_ = json.Unmarshal(export["now"], &f.Now)
	_ = json.Unmarshal(export["runtime_raw"], &f.Runtime)
	_ = json.Unmarshal(export["artifact_raw"], &f.Artifact)
	key, err := base64.StdEncoding.DecodeString(f.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	now, err := time.Parse(time.RFC3339, f.Now)
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Name     string
		Accepted bool
		Envelope Envelope
	}
	if json.Unmarshal(export["cases"], &cases) != nil || len(cases) != 24 {
		t.Fatal("expected exact populated PHP corpus")
	}
	var original Envelope
	for _, tc := range cases {
		t.Run(tc.Name, func(t *testing.T) {
			command, err := verifyScriptCommand(tc.Envelope, ed25519.PublicKey(key), f.Agent, now)
			if (err == nil) != tc.Accepted {
				t.Fatalf("acceptance mismatch: expected %v, error %v", tc.Accepted, err)
			}
			if tc.Name == "populated_original_signer" && err == nil {
				original = tc.Envelope
				params := command["parameters"].(map[string]any)
				manifest := params["manifest"].(map[string]any)
				runtime, err := base64.StdEncoding.DecodeString(f.Runtime)
				if err != nil {
					t.Fatal(err)
				}
				artifact, err := base64.StdEncoding.DecodeString(f.Artifact)
				if err != nil {
					t.Fatal(err)
				}
				if !scriptRuntime(manifest, runtime) || !scriptArtifact(manifest, artifact) {
					t.Fatal("original runtime or artifact rejected")
				}
				if scriptRuntime(manifest, append(runtime, ' ')) || scriptArtifact(manifest, append(artifact, ' ')) {
					t.Fatal("changed bytes accepted")
				}
			}
		})
	}
	if original.Payload == "" {
		t.Fatal("positive original producer absent")
	}
	for _, tc := range []struct {
		name, agent string
		now         time.Time
	}{
		{"foreign_agent", "55555555-5555-4555-8555-555555555555", now},
		{"expired", f.Agent, now.Add(time.Hour)},
		{"not_yet_valid", f.Agent, now.Add(-time.Minute)},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if _, err := verifyScriptCommand(original, key, tc.agent, tc.now); err == nil {
				t.Fatal("identity/time denial missing")
			}
		})
	}
	damaged := original
	sig, _ := base64.StdEncoding.DecodeString(damaged.Signature)
	sig[0] ^= 1
	damaged.Signature = base64.StdEncoding.EncodeToString(sig)
	if _, err := verifyScriptCommand(damaged, key, f.Agent, now); err == nil {
		t.Fatal("wrong signature accepted")
	}
	if _, err := verifyScriptCommand(original, nil, f.Agent, now); err == nil {
		t.Fatal("absent trust key accepted")
	}
}

func TestScriptPHPRuntimePaths(t *testing.T) {
	path := os.Getenv("OBLI_SCRIPT_EXPORT")
	if path == "" {
		t.Skip("original PHP runtime corpus not supplied")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var root struct {
		Cases []struct {
			Name     string
			Accepted bool
			Manifest json.RawMessage
			Raw      string
		} `json:"runtime_cases"`
	}
	if json.Unmarshal(raw, &root) != nil || len(root.Cases) != 7 {
		t.Fatal("expected seven original runtime path cases")
	}
	for _, row := range root.Cases {
		t.Run(row.Name, func(t *testing.T) {
			m, err := scriptDecode(row.Manifest, 32768)
			if err != nil {
				t.Fatal(err)
			}
			body, err := base64.StdEncoding.DecodeString(row.Raw)
			if err != nil || scriptRuntime(m, body) != row.Accepted {
				t.Fatal("runtime path interpretation differs from PHP")
			}
		})
	}
}

func TestScriptJSONArraysDuplicatesAndBounds(t *testing.T) {
	valid := []byte("{\"list\":[{\"a\":1},{\"a\":2}],\"empty\":[],\"object\":{}}")
	if _, err := scriptDecode(valid, len(valid)); err != nil {
		t.Fatal("valid nested arrays rejected", err)
	}
	for _, raw := range []string{
		"{\"a\":1,\"a\":2}", "{\"a\":1,\"\\u0061\":2}",
		"{\"x\":[{\"a\":1,\"\\u0061\":2}]}",
		"{\"x\":\"\\ud800\"}", "{\"x\":\"\\udc00\"}",
		"{\"x\":\"" + string([]byte{0xff}) + "\"}", "{}{}", "null", "[]",
		"{\"x\":" + strings.Repeat("[", 35) + "0" + strings.Repeat("]", 35) + "}",
	} {
		if _, err := scriptDecode([]byte(raw), 32768); err == nil {
			t.Fatalf("invalid JSON accepted: %q", raw)
		}
	}
	if _, err := scriptDecode(valid, len(valid)-1); err == nil {
		t.Fatal("budget bypass")
	}
	if uniqueRemoteJSON(valid) == nil {
		t.Fatal("existing remote object-only boundary was unexpectedly broadened")
	}
}

func TestScriptSchemaRejectsLooseTypes(t *testing.T) {
	for _, required := range []string{"true", "false"} {
		for _, kind := range []string{"true", "false", "0", "1", "null", "[]", "{}", "1.5"} {
			raw := []byte("{\"schema\":[{\"name\":\"Flag\",\"type\":" + kind + ",\"required\":" + required + ",\"constraints\":{}}],\"inputs\":{}}")
			object, err := scriptDecode(raw, 32768)
			if err != nil {
				t.Fatal("fixture syntax", err)
			}
			if scriptSchema(object["schema"]) || scriptInputs(object["schema"], object["inputs"]) {
				t.Fatal("non-string type accepted", kind, required)
			}
		}
	}
}

func TestScriptPHPClaimCancellationAndResultCorpus(t *testing.T) {
	path := os.Getenv("OBLI_SCRIPT_EXPORT")
	if path == "" {
		t.Skip("original PHP script receipt export not supplied")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var root map[string]json.RawMessage
	if json.Unmarshal(raw, &root) != nil {
		t.Fatal("fixture")
	}
	var public string
	_ = json.Unmarshal(root["public_key"], &public)
	key, err := base64.StdEncoding.DecodeString(public)
	if err != nil {
		t.Fatal(err)
	}
	var agent string
	_ = json.Unmarshal(root["agent_uuid"], &agent)
	var rows []map[string]json.RawMessage
	if json.Unmarshal(root["receipts"], &rows) != nil || len(rows) != 80 {
		t.Fatal("expected 80 original PHP receipt cases")
	}
	originalTime, _ := time.Parse(time.RFC3339, "2026-10-20T01:00:01Z")
	for _, row := range rows {
		text := func(name string) string { var v string; _ = json.Unmarshal(row[name], &v); return v }
		var expected bool
		_ = json.Unmarshal(row["accepted"], &expected)
		t.Run(text("kind")+"_"+text("name"), func(t *testing.T) {
			var dispatch Envelope
			if json.Unmarshal(row["command"], &dispatch) != nil {
				t.Fatal("dispatch fixture")
			}
			c, err := verifyScriptCommand(dispatch, key, agent, originalTime)
			if err != nil {
				t.Fatal("original command not accepted", err)
			}
			decoded, _ := base64.StdEncoding.DecodeString(dispatch.Payload)
			sum := sha256.Sum256(decoded)
			hash := hex.EncodeToString(sum[:])
			if hash != text("payload_hash") {
				t.Fatal("receipt context changed")
			}
			accepted := false
			switch text("kind") {
			case "claim":
				var e Envelope
				_ = json.Unmarshal(row["envelope"], &e)
				var desktop, nonce *string
				_ = json.Unmarshal(row["desktop_session"], &desktop)
				_ = json.Unmarshal(row["consent_nonce"], &nonce)
				now, err := time.Parse(time.RFC3339, text("now"))
				if err != nil {
					t.Fatal(err)
				}
				claim, err := verifyScriptClaim(e, key, c, hash, text("boot_id"), desktop, nonce, now)
				accepted = err == nil
				if accepted {
					again, err := verifyScriptClaim(e, key, c, hash, text("boot_id"), desktop, nonce, now.Add(time.Second))
					if err != nil || claim["expires_at"] != again["expires_at"] {
						t.Fatal("replay renewed or lost original lease")
					}
					if _, err := verifyScriptClaim(e, key, c, hash, text("boot_id"), desktop, nonce, now.Add(30*time.Second)); err == nil {
						t.Fatal("expired claim accepted")
					}
				}
			case "cancel":
				var e Envelope
				_ = json.Unmarshal(row["envelope"], &e)
				now, err := time.Parse(time.RFC3339, text("now"))
				if err != nil {
					t.Fatal(err)
				}
				_, err = verifyScriptCancellation(e, key, c, hash, now)
				accepted = err == nil
			case "result":
				value, err := scriptDecode(row["value"], 32768)
				accepted = err == nil && validScriptResult(value, c, hash)
			default:
				t.Fatal("unknown fixture kind")
			}
			if accepted != expected {
				t.Fatalf("expected accepted=%v, actual=%v", expected, accepted)
			}
		})
	}
}
