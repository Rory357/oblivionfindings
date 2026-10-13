package collectorhost

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"golang.org/x/sys/windows"
	"math/big"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// Opt in to the installed PHP development runtime. This executes only the
// local startup command with generated identity files, never a collection cycle.
func TestActualPHPStartupUsesTheFixedExtensionContract(t *testing.T) {
	php := os.Getenv("OBLI_COLLECTOR_PHP")
	if php == "" {
		t.Skip("set OBLI_COLLECTOR_PHP to a PHP 8.4+ development executable")
	}
	root := t.TempDir()
	identity := writePHPIdentity(t, root)
	before := map[string][]byte{}
	for _, name := range []string{"collector.identity.json", "collector.crt.pem", "collector.key.pem"} {
		data, err := os.ReadFile(filepath.Join(root, name))
		if err != nil {
			t.Fatal(err)
		}
		before[name] = data
	}
	if err := checkRepairIdentity(before["collector.identity.json"], identity["collector_id"].(string), 9, identity["central_url"].(string)); err != nil {
		t.Fatal(err)
	}
	script, err := filepath.Abs("../../../collector/bin/oblivion-collector")
	if err != nil {
		t.Fatal(err)
	}
	windir, err := windows.GetWindowsDirectory()
	if err != nil || windir == "" {
		t.Fatal("Windows test environment missing SystemRoot")
	}
	call := invocation{executable: php, directory: filepath.Dir(php), environment: []string{"SystemRoot=" + windir, "TEMP=" + root, "TMP=" + root},
		arguments: phpArguments(filepath.Dir(php), script, root, "runtime-check")}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	code, err := runChild(ctx, call)
	if err != nil || code != 0 {
		t.Fatal("real PHP local startup contract failed", code, err)
	}

	for name, original := range before {
		after, e := os.ReadFile(filepath.Join(root, name))
		if e != nil || !bytes.Equal(after, original) {
			t.Fatal("runtime repair check changed identity", name, e)
		}
	}
	// A present PHP executable is not sufficient when a required module is absent.
	for index, arg := range call.arguments {
		if arg == "extension=php_sockets.dll" {
			call.arguments = append(call.arguments[:index-1], call.arguments[index+1:]...)
			break
		}
	}
	code, err = runChild(ctx, call)
	if err != nil || code == 0 {
		t.Fatal("missing required extension appeared ready", code, err)
	}
}

func writePHPIdentity(t *testing.T, root string) map[string]any {
	t.Helper()
	uuid := "2df1d87c-2d04-4e57-80ab-8a15f39c944d"
	tlsKey, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "oblivion-collector-" + uuid}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour)}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &tlsKey.PublicKey, tlsKey)
	if err != nil {
		t.Fatal(err)
	}
	_, secret, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	public, _, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	fingerprint := sha256.Sum256(der)
	certificate := filepath.Join(root, "collector.crt.pem")
	private := filepath.Join(root, "collector.key.pem")
	for name, bytes := range map[string][]byte{
		certificate: pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}),
		private:     pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(tlsKey)}),
	} {
		if err = os.WriteFile(name, bytes, 0600); err != nil {
			t.Fatal(err)
		}
	}
	identity := map[string]any{
		"collector_id": uuid, "site_id": 9, "central_url": "https://central.example.test", "tls_public_key_pin": "sha256//" + base64.StdEncoding.EncodeToString(make([]byte, 32)),
		"central_signing_public_key": base64.StdEncoding.EncodeToString(public), "request_signing_secret_key": base64.StdEncoding.EncodeToString(secret),
		"state_directory": root, "client_certificate_file": certificate, "client_private_key_file": private, "client_certificate_fingerprint": hex.EncodeToString(fingerprint[:]),
		"enrolment_token_hash": hex.EncodeToString(fingerprint[:]), "enrolment_expires_at": time.Now().Add(-time.Hour).UTC().Format(time.RFC3339),
	}
	bytes, _ := json.Marshal(identity)
	if err = os.WriteFile(filepath.Join(root, "collector.identity.json"), bytes, 0600); err != nil {
		t.Fatal(err)
	}

	return identity
}
