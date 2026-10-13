package collectorhost

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"golang.org/x/sys/windows"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// Executes the actual collector application/crypto/file writer with only its
// network transport substituted. It neither installs a service nor contacts a server.
func TestActualPHPEnrollmentUsesPrivateInputAndConfirmsCommittedIdentity(t *testing.T) {
	php := os.Getenv("OBLI_COLLECTOR_PHP")
	if php == "" {
		t.Skip("set OBLI_COLLECTOR_PHP to a PHP 8.4+ development executable")
	}
	fixtures := t.TempDir()
	identity := writePHPIdentity(t, fixtures)
	state := t.TempDir()
	p := validBootstrap()
	// Match the server's six-digit Carbon ISO timestamp.
	p.ExpiresAt = time.Now().Add(15 * time.Minute).UTC().Format("2006-01-02T15:04:05.000000Z")
	p.CollectorUUID = identity["collector_id"].(string)
	p.ServerURL = identity["central_url"].(string)
	p.TLSPin = identity["tls_public_key_pin"].(string)
	p.ServerKey = identity["central_signing_public_key"].(string)
	certificate, err := os.ReadFile(identity["client_certificate_file"].(string))
	if err != nil {
		t.Fatal(err)
	}
	key, err := os.ReadFile(identity["client_private_key_file"].(string))
	if err != nil {
		t.Fatal(err)
	}
	digest := sha256.Sum256([]byte(p.Token))
	fixture := map[string]any{"origin": p.ServerURL, "pin": p.TLSPin, "token_hash": hex.EncodeToString(digest[:]), "revoked": false,
		"payload": map[string]any{"version": 2, "collector_id": p.CollectorUUID, "site_id": p.SiteID, "central_signing_public_key": p.ServerKey,
			"client_certificate": string(certificate), "client_private_key": string(key), "client_certificate_fingerprint": identity["client_certificate_fingerprint"],
			"client_certificate_expires_at": time.Now().Add(time.Hour).UTC().Format(time.RFC3339), "enrolment_expires_at": p.ExpiresAt, "acknowledged_source_sequence": 0}}
	fixturePath := filepath.Join(fixtures, "payload.json")
	save := func() {
		data, _ := json.Marshal(fixture)
		if err := os.WriteFile(fixturePath, data, 0600); err != nil {
			t.Fatal(err)
		}
	}
	save()
	script, err := filepath.Abs("../../../collector/tests/native-enrolment-fixture.php")
	if err != nil {
		t.Fatal(err)
	}
	windir, err := windows.GetWindowsDirectory()
	if err != nil {
		t.Fatal(err)
	}
	call := enrollmentCall(invocation{executable: php, directory: filepath.Dir(php),
		environment: []string{"SystemRoot=" + windir, "TEMP=" + fixtures, "TMP=" + fixtures, "OBLI_TEST_PAYLOAD_FILE=" + fixturePath},
		arguments:   phpArguments(filepath.Dir(php), script, state, "enrol-recoverable")}, state, p)
	defer clear(call.input)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if code, err := runChild(ctx, call); err != nil || code != 0 {
		diagnostic, _ := os.ReadFile(fixturePath + ".output")
		t.Fatal("real enrolment bridge failed", code, err, string(diagnostic))
	}
	identityPath := filepath.Join(state, "collector.identity.json")
	before, err := os.ReadFile(identityPath)
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(before, []byte(p.Token)) {
		t.Fatal("raw grant was persisted")
	}
	if code, err := runChild(ctx, call); err != nil || code != 0 {
		t.Fatal("same identity confirmation failed", code, err)
	}
	after, err := os.ReadFile(identityPath)
	if err != nil || !bytes.Equal(before, after) {
		t.Fatal("confirmation replaced identity", err)
	}
	fixture["revoked"] = true
	save()
	if code, err := runChild(ctx, call); err != nil || code == 0 {
		t.Fatal("revoked grant appeared confirmed", code, err)
	}
	after, err = os.ReadFile(identityPath)
	if err != nil || !bytes.Equal(before, after) {
		t.Fatal("refusal damaged identity", err)
	}
	calls, err := os.ReadFile(fixturePath + ".calls")
	if err != nil || bytes.Count(calls, []byte("request\n")) != 3 {
		t.Fatal("completed enrolment skipped current authority", err)
	}
	productionScript, err := filepath.Abs("../../../collector/bin/oblivion-collector")
	if err != nil {
		t.Fatal(err)
	}
	local := invocation{executable: php, directory: filepath.Dir(php), environment: []string{"SystemRoot=" + windir},
		arguments: phpArguments(filepath.Dir(php), productionScript, state, "runtime-check")}
	if code, err := runChild(ctx, local); err != nil || code != 0 {
		t.Fatal("committed identity failed real runtime check", code, err)
	}
}
