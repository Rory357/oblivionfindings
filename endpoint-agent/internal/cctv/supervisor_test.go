package cctv

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestMain(m *testing.M) {
	if os.Getenv("OBLI_TEST_MEDIA_CHILD") == "1" {
		if len(os.Args) > 1 && os.Args[1] == "-n" {
			if os.Getenv("OBLI_TEST_MEDIA_DISCOVERY") == "1" {
				discoverySupervisorFixtureLane()
				os.Exit(0)
			}
			configure := map[string]any{"version": 1, "operation": "configure", "collector_uuid": "11111111-1111-4111-8111-111111111111", "site_id": 1, "server_public_key": base64.StdEncoding.EncodeToString(make([]byte, 32))}
			if WriteFrame(os.Stdout, configure) != nil {
				os.Exit(2)
			}
			raw, e := ReadFrame(os.Stdin)
			if e != nil {
				os.Exit(3)
			}
			var reply Reply
			if json.Unmarshal(raw, &reply) != nil || reply.Status != "requested" {
				os.Exit(4)
			}
			if os.Getenv("OBLI_TEST_MEDIA_STALL") == "1" {
				select {}
			}
			os.Exit(0)
		}
		_ = Serve(context.Background(), os.Stdin, os.Stdout, nil)
		os.Exit(0)
	}
	os.Exit(m.Run())
}
func TestSupervisorClosesPeerWhenLaneEnds(t *testing.T) {
	executable, e := os.Executable()
	if e != nil {
		t.Fatal(e)
	}
	directory := t.TempDir()
	identity := filepath.Join(directory, "identity.json")
	script := filepath.Join(directory, "collector.php")
	for _, p := range []string{identity, script} {
		if os.WriteFile(p, []byte("isolated"), 0600) != nil {
			t.Fatal("fixture")
		}
	}
	ctx, cancel := context.WithTimeout(t.Context(), 8*time.Second)
	defer cancel()
	started := time.Now()
	err := Supervise(ctx, executable, script, executable, identity, directory, append(os.Environ(), "OBLI_TEST_MEDIA_CHILD=1"))
	if err != ErrSupervisor || time.Since(started) > 5*time.Second {
		t.Fatal("lane EOF did not close both children", err)
	}
}
func TestSupervisorCancellationClosesBothChildren(t *testing.T) {
	executable, e := os.Executable()
	if e != nil {
		t.Fatal(e)
	}
	directory := t.TempDir()
	identity := filepath.Join(directory, "identity.json")
	script := filepath.Join(directory, "collector.php")
	ctx, cancel := context.WithTimeout(t.Context(), 350*time.Millisecond)
	defer cancel()
	started := time.Now()
	err := Supervise(ctx, executable, script, executable, identity, directory, append(os.Environ(), "OBLI_TEST_MEDIA_CHILD=1", "OBLI_TEST_MEDIA_STALL=1"))
	if err != nil || time.Since(started) > 4*time.Second {
		t.Fatal("cancel did not close both children", err)
	}
}

type pausedFrameWriter struct {
	started chan struct{}
	release chan struct{}
	copied  chan []byte
}

func (w *pausedFrameWriter) Write(b []byte) (int, error) {
	close(w.started)
	<-w.release
	w.copied <- append([]byte{}, b...)
	return len(b), nil
}
func TestCancelledWriteOwnsCallerBuffer(t *testing.T) {
	writer := &pausedFrameWriter{make(chan struct{}), make(chan struct{}), make(chan []byte, 1)}
	input := []byte("private-fixture-material")
	ctx, cancel := context.WithCancel(t.Context())
	cancel()
	if writeBytes(ctx, writer, input) != ErrSupervisor {
		t.Fatal("cancelled write accepted")
	}
	clear(input)
	select {
	case <-writer.started:
	case <-time.After(time.Second):
		t.Fatal("owned write missing")
	}
	close(writer.release)
	select {
	case actual := <-writer.copied:
		if string(actual[4:]) != "private-fixture-material" {
			t.Fatal("caller wipe changed in-flight frame")
		}
	case <-time.After(time.Second):
		t.Fatal("owned write not released")
	}
}

func discoverySupervisorFixtureLane() {
	key := ed25519.NewKeyFromSeed(make([]byte, 32))
	collector := "11111111-1111-4111-8111-111111111111"
	configure := map[string]any{"version": 1, "operation": "configure", "collector_uuid": collector, "site_id": 1, "server_public_key": base64.StdEncoding.EncodeToString(key.Public().(ed25519.PublicKey))}
	if WriteFrame(os.Stdout, configure) != nil {
		os.Exit(21)
	}
	if _, err := ReadFrame(os.Stdin); err != nil {
		os.Exit(22)
	}
	source := json.RawMessage(`{"allowed_ip":"127.0.0.1","allowed_port":443,"tls_server_name":"camera.test","tls_pin":"` + strings.Repeat("a", 64) + `","username":"fixture","password":"invalid\ncredential"}`)
	now := time.Now().UTC().Truncate(time.Second)
	grant := hikvisionDiscoveryGrant{Version: 1, Type: "collector_hikvision_discovery", Collector: collector, Site: 1, Device: 2, Scope: 3, Request: "44444444-4444-4444-8444-444444444444", ContextHash: strings.Repeat("b", 64), CredentialBinding: strings.Repeat("c", 64), SourceHash: hash(source), IP: "127.0.0.1", Port: 443, ServerName: "camera.test", Pin: strings.Repeat("a", 64), Issued: now.Format("2006-01-02T15:04:05Z"), Expires: now.Add(10 * time.Second).Format("2006-01-02T15:04:05Z")}
	raw, _ := json.Marshal(grant)
	envelope := Envelope{Payload: base64.StdEncoding.EncodeToString(raw), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, raw))}
	if WriteFrame(os.Stdout, map[string]any{"version": 1, "operation": hikvisionDiscoveryOperation, "request_uuid": grant.Request, "grant": envelope, "source": source}) != nil {
		os.Exit(23)
	}
	reply, err := ReadFrame(os.Stdin)
	if err != nil || validatePrivateWorkerReply(reply) != nil {
		os.Exit(24)
	}
	var result hikvisionDiscoveryReply
	_ = json.Unmarshal(reply, &result)
	if result.Operation != hikvisionDiscoveryOperation || result.Request != grant.Request || result.Device != 2 || result.Status != "failed" || result.Inventory != nil {
		os.Exit(25)
	}
	if os.WriteFile(os.Getenv("OBLI_TEST_MEDIA_DISCOVERY_MARKER"), []byte("private discovery dispatched and denied before network"), 0600) != nil {
		os.Exit(26)
	}
}

func TestSupervisorPassesClosedHikvisionDiscoveryAndReply(t *testing.T) {
	executable, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	directory := t.TempDir()
	marker := filepath.Join(directory, "private-discovery-proof.txt")
	identity, script := filepath.Join(directory, "identity.json"), filepath.Join(directory, "collector.php")
	for _, path := range []string{identity, script} {
		if os.WriteFile(path, []byte("synthetic"), 0600) != nil {
			t.Fatal("fixture write")
		}
	}
	ctx, cancel := context.WithTimeout(t.Context(), 8*time.Second)
	defer cancel()
	err = Supervise(ctx, executable, script, executable, identity, directory, append(os.Environ(), "OBLI_TEST_MEDIA_CHILD=1", "OBLI_TEST_MEDIA_DISCOVERY=1", "OBLI_TEST_MEDIA_DISCOVERY_MARKER="+marker))
	proof, readErr := os.ReadFile(marker)
	if err != ErrSupervisor || readErr != nil || string(proof) != "private discovery dispatched and denied before network" {
		t.Fatal("closed discovery did not cross supervised private pipes", err, readErr)
	}
}
