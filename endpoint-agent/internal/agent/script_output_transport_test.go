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

func TestScriptOriginalHTTPOutput(t *testing.T) {
	path := scriptFixturePath("OBLI_SCRIPT_OUTPUT_HTTP_EXPORT")
	if path == "" {
		t.Skip("original private output HTTP export not supplied")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var fixtures []struct {
		Name       string
		PublicKey  string `json:"public_key"`
		AgentUUID  string `json:"agent_uuid"`
		Command    Envelope
		Header     json.RawMessage
		Result     json.RawMessage
		BodyBase64 string `json:"body_base64"`
		Receipt    json.RawMessage
	}
	if json.Unmarshal(raw, &fixtures) != nil || len(fixtures) != 10 {
		t.Fatal("missing original output cases")
	}
	for _, f := range fixtures {
		t.Run(f.Name, func(t *testing.T) {
			key, err := base64.StdEncoding.DecodeString(f.PublicKey)
			if err != nil {
				t.Fatal(err)
			}
			payload, err := base64.StdEncoding.DecodeString(f.Command.Payload)
			if err != nil {
				t.Fatal(err)
			}
			original, err := scriptDecode(payload, 32768)
			if err != nil {
				t.Fatal(err)
			}
			issued, ok := scriptInstant(original["issued_at"])
			if !ok {
				t.Fatal("invalid dispatch time")
			}
			command, err := verifyScriptCommand(f.Command, key, f.AgentUUID, issued)
			if err != nil {
				t.Fatal(err)
			}
			sum := sha256.Sum256(payload)
			hash := hex.EncodeToString(sum[:])
			result, err := scriptDecode(f.Result, 8192)
			if err != nil {
				t.Fatal(err)
			}
			body, err := base64.StdEncoding.DecodeString(f.BodyBase64)
			if err != nil {
				t.Fatal(err)
			}
			header, err := scriptOutputUpload(result, command, hash, body)
			if err != nil {
				t.Fatal("original downloaded bytes rejected", err)
			}
			expected, err := scriptDecode(f.Header, 1024)
			if err != nil {
				t.Fatal(err)
			}
			for _, k := range []string{"command_uuid", "payload_hash", "sha256", "size_bytes", "truncated"} {
				if header[k] != expected[k] {
					t.Fatalf("header changed %s", k)
				}
			}
			receipt, err := scriptDecode(f.Receipt, 4096)
			if err != nil {
				t.Fatal(err)
			}
			received, ok := scriptInstant(receipt["received_at"])
			if !ok {
				t.Fatal("invalid receipt time")
			}
			if _, err = verifyScriptOutputReceipt(f.Receipt, result, command, hash, received); err != nil {
				t.Fatal("original upload receipt rejected", err)
			}
			if _, err = verifyScriptOutputReceipt(f.Receipt, result, command, hash, received.Add(365*24*time.Hour)); err != nil {
				t.Fatal("historical receipt must survive expiry", err)
			}
			if _, err = scriptOutputUpload(result, command, hash, append(append([]byte{}, body...), 'x')); err == nil {
				t.Fatal("changed content accepted")
			}
			if _, err = scriptOutputUpload(result, command, hash, []byte{0xff}); err == nil {
				t.Fatal("invalid UTF-8 accepted")
			}
			for _, change := range []scriptObject{
				{"accepted": false}, {"accepted": "true"}, {"truncated": nil}, {"size_bytes": "0"},
				{"attempt_uuid": "00000000-0000-4000-8000-000000000000"}, {"payload_hash": "foreign"}, {"extra": true},
				{"expires_at": receipt["received_at"]}, {"expires_at": received.Add(169 * time.Hour).Format("2006-01-02T15:04:05Z")},
				{"received_at": received.Add(2 * time.Second).Format("2006-01-02T15:04:05Z")},
			} {
				altered := scriptObject{}
				for k, v := range receipt {
					altered[k] = v
				}
				for k, v := range change {
					altered[k] = v
				}
				data, _ := json.Marshal(altered)
				if _, err = verifyScriptOutputReceipt(data, result, command, hash, received); err == nil {
					t.Fatalf("altered receipt accepted: %v", change)
				}
			}
		})
	}
}
