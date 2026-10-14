package main

import (
	"archive/zip"
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/setup"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

func reviewFixture(t *testing.T) (string, string, agent.Bootstrap) {
	t.Helper()
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	old := publisherPublicKey
	publisherPublicKey = base64.StdEncoding.EncodeToString(public)
	t.Cleanup(func() { publisherPublicKey = old })
	root := t.TempDir()
	archive := filepath.Join(root, "release.zip")
	profile := filepath.Join(root, "computer.json")
	now := time.Now()
	m := release.Manifest{Schema: 1, Product: "oblivion-windows", Version: "1.2.3", Revision: 7, Platform: "windows", Architecture: runtime.GOARCH, Roles: []string{"computer"}, PublishedAt: now.Add(-time.Minute).UTC().Format(time.RFC3339), ExpiresAt: now.Add(time.Hour).UTC().Format(time.RFC3339)}
	content := []byte("fixture bytes, not an executable")
	sum := sha256.Sum256(content)
	for _, name := range []string{"computer/oblivion-agent.exe", "computer/obli.exe"} {
		m.Files = append(m.Files, release.File{Path: name, Size: int64(len(content)), SHA256: hex.EncodeToString(sum[:])})
	}
	payload, _ := json.Marshal(m)
	signed, _ := json.Marshal(map[string]string{"payload": base64.StdEncoding.EncodeToString(payload), "signature": base64.StdEncoding.EncodeToString(ed25519.Sign(private, append([]byte(release.SignatureDomain), payload...)))})
	f, err := os.Create(archive)
	if err != nil {
		t.Fatal(err)
	}
	z := zip.NewWriter(f)
	e, _ := z.Create("release.json")
	e.Write(signed)
	for _, record := range m.Files {
		e, _ = z.Create(record.Path)
		e.Write(content)
	}
	if z.Close() != nil || f.Close() != nil {
		t.Fatal("archive failed")
	}
	p := agent.Bootstrap{Version: 1, Platform: "windows", ServerURL: "https://care.example.test", ServerKey: base64.StdEncoding.EncodeToString(public), AgentUUID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", Token: strings.Repeat("a", 64), ExpiresAt: now.Add(10 * time.Minute).UTC().Format(time.RFC3339), Organisation: "Example Care", DeviceLabel: "Reception PC", DeviceUID: "RECEPTION-01"}
	body, _ := json.Marshal(p)
	if os.WriteFile(profile, body, 0600) != nil {
		t.Fatal("profile failed")
	}
	return archive, profile, p
}
func TestReviewUsesRealSignedArchiveAndDoesNotExposeSecrets(t *testing.T) {
	archive, profile, p := reviewFixture(t)
	var output bytes.Buffer
	args := []string{"review", "--package", archive, "--bootstrap", profile, "--role", "computer"}
	if err := run(args, &output); err != nil {
		t.Fatal(err)
	}
	var review reviewDetails
	if json.Unmarshal(output.Bytes(), &review) != nil || review.Schema != 1 || review.Computer != p.DeviceLabel || review.ServerURL != p.ServerURL || review.ReviewDigest == "" {
		t.Fatal("invalid review", output.String())
	}
	if strings.Contains(output.String(), p.Token) || strings.Contains(output.String(), p.ServerKey) || strings.Contains(output.String(), "enrolment_token") {
		t.Fatal("review disclosed private material")
	}
	// Same validated package, but changed profile since the user reviewed it:
	p.DeviceLabel = "Changed reception"
	data, _ := json.Marshal(p)
	os.WriteFile(profile, data, 0600)
	output.Reset()
	err := run([]string{"install", "--package", archive, "--bootstrap", profile, "--role", "computer", "--confirm-server", p.ServerURL, "--review-digest", review.ReviewDigest}, &output)
	if err == nil || !strings.Contains(err.Error(), "changed after review") || output.Len() != 0 {
		t.Fatal("changed profile reached activation", err)
	}
}
func TestReviewDigestBindsEveryProfileAndRelease(t *testing.T) {
	_, _, computer := reviewFixture(t)
	site := collectorhost.Bootstrap{Organisation: computer.Organisation, SiteLabel: "Main office", ExpiresAt: computer.ExpiresAt, DeploymentURL: computer.ServerURL, ServerURL: "https://collectors.example.test", Token: "secret-a"}
	p := setup.Profiles{Role: "both", Computer: &computer, Collector: &site}
	m := release.Manifest{Version: "1.2.3", Revision: 7, Architecture: runtime.GOARCH, ExpiresAt: time.Now().Add(time.Hour).Format(time.RFC3339)}
	original, err := reviewFor(m, strings.Repeat("a", 64), p)
	if err != nil {
		t.Fatal(err)
	}
	for _, change := range []func(){func() { site.Token = "secret-b" }, func() { site.SiteID++ }, func() { computer.Token = strings.Repeat("b", 64) }, func() { computer.ServerKey = strings.Repeat("b", 44) }} {
		change()
		next, err := reviewFor(m, strings.Repeat("a", 64), p)
		if err != nil || next.ReviewDigest == original.ReviewDigest {
			t.Fatal("profile edit not bound", err)
		}
		original = next
	}
	changed, _ := reviewFor(m, strings.Repeat("b", 64), p)
	if changed.ReviewDigest == original.ReviewDigest {
		t.Fatal("release not bound")
	}
	site.Organisation = "Changed"
	if _, err := reviewFor(m, strings.Repeat("a", 64), p); err == nil {
		t.Fatal("mismatched labels accepted")
	}
}
func TestControllerDisconnectAndAnyInputCancel(t *testing.T) {
	for _, value := range []string{"", "cancel\n", "unexpected"} {
		ctx, cancel := context.WithCancel(context.Background())
		cancelOnControllerInput(strings.NewReader(value), cancel)
		if ctx.Err() == nil {
			t.Fatal("controller exit did not cancel")
		}
	}
	reader, writer := io.Pipe()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go cancelOnControllerInput(reader, cancel)
	select {
	case <-ctx.Done():
		t.Fatal("open controller pipe cancelled")
	case <-time.After(20 * time.Millisecond):
	}
	writer.Close()
	select {
	case <-ctx.Done():
	case <-time.After(time.Second):
		t.Fatal("EOF did not cancel")
	}
	reader.Close()
}
func TestControlledInstallRequiresDigestAndReviewIsReadOnly(t *testing.T) {
	for _, args := range [][]string{
		{"install", "--package", "x.zip", "--bootstrap", "p.json", "--confirm-server", "https://care.example.test", "--control-stdin"},
		{"review", "--package", "x.zip", "--bootstrap", "p.json", "--control-stdin"},
		{"review", "--package", "x.zip", "--bootstrap", "p.json", "--confirm-server", "https://other.example.test"},
		{"install", "--package", "x.zip", "--bootstrap", "p.json", "--confirm-server", "https://care.example.test", "--review-digest", "wrong"},
	} {
		if _, err := parseOptions(args); err == nil {
			t.Fatal("unapproved flags accepted")
		}
	}
}

func TestExportRealReviewForDesktopProtocol(t *testing.T) {
	destination := os.Getenv("OBLI_SETUP_REVIEW_EXPORT")
	if destination == "" {
		t.Skip("desktop contract export not requested")
	}
	archive, profile, _ := reviewFixture(t)
	var output bytes.Buffer
	if err := run([]string{"review", "--package", archive, "--bootstrap", profile, "--role", "computer"}, &output); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(destination, output.Bytes(), 0600); err != nil {
		t.Fatal(err)
	}
}
