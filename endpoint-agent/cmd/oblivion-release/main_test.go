package main

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"oblivion.local/endpoint-agent/internal/release"
)

func commandKey() (ed25519.PrivateKey, string, string) {
	seed := bytes.Repeat([]byte{27}, ed25519.SeedSize)
	key := ed25519.NewKeyFromSeed(seed)
	return key, base64.StdEncoding.EncodeToString(seed), base64.StdEncoding.EncodeToString(key.Public().(ed25519.PublicKey))
}

func TestSigningInputIsBoundedAndBoundToExpectedPublicKey(t *testing.T) {
	key, encoded, _ := commandKey()
	expected := key.Public().(ed25519.PublicKey)
	for _, ending := range []string{"", "\n", "\r\n"} {
		got, err := readSigner(strings.NewReader(encoded+ending), expected)
		if err != nil || !got.Equal(key) {
			t.Fatal("valid bounded signing input rejected", err)
		}
		clear(got)
	}
	other := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{28}, 32)).Public().(ed25519.PublicKey)
	if got, err := readSigner(strings.NewReader(encoded), other); err == nil || got != nil {
		t.Fatal("mismatched signing authority accepted")
	}
	for _, invalid := range []string{
		"", encoded + " ", encoded + "\n\n", encoded + "\r", strings.Repeat("A", 44),
		strings.Repeat("A", 1000), encoded[:20] + "!" + encoded[21:], encoded + "extra",
		base64.StdEncoding.EncodeToString(key),
	} {
		got, err := readSigner(strings.NewReader(invalid), expected)
		if err == nil || got != nil {
			t.Fatal("malformed or oversized signing input accepted")
		}
		if strings.Contains(err.Error(), encoded) {
			t.Fatal("error disclosed signing material")
		}
	}
	counted := &countingReader{reader: strings.NewReader(strings.Repeat("A", 1000))}
	if _, err := readSigner(counted, expected); err == nil || counted.count > 47 {
		t.Fatal("unbounded secret read")
	}
	if _, err := readSigner(failingReader{}, expected); err == nil {
		t.Fatal("failed input read accepted")
	}
}

type countingReader struct {
	reader io.Reader
	count  int
}

func (r *countingReader) Read(data []byte) (int, error) {
	n, e := r.reader.Read(data)
	r.count += n
	return n, e
}

type failingReader struct{}

func (failingReader) Read([]byte) (int, error) { return 0, errors.New("fixture error") }

func TestReleaseCommandHasNoUnsignedSecretFlagOrInstallerPath(t *testing.T) {
	_, _, public := commandKey()
	valid := []string{"build", "--stage", "stage", "--metadata", "metadata.json", "--output", "release.zip", "--publisher-key", public}
	if got, err := parseOptions(valid); err != nil || got.stage != "stage" {
		t.Fatal("valid operator arguments rejected")
	}
	for _, invalid := range [][]string{
		nil, {"install"}, {"build"}, append(append([]string{}, valid...), "--allow-unsigned"),
		append(append([]string{}, valid...), "--seed", "private"),
		append(append([]string{}, valid...), "--key-file", "secret.key"),
		append(append([]string{}, valid...), "--server", "https://care.example.test"),
		append(append([]string{}, valid...), "extra"),
		append(append([]string{}, valid[:len(valid)-1]...), public+"\n"),
	} {
		if _, err := parseOptions(invalid); err == nil {
			t.Fatal("unsupported release command was accepted")
		}
	}
}

func TestReleaseCommandUnsignedFailureDoesNotEmitReceiptOrPublish(t *testing.T) {
	_, encoded, public := commandKey()
	root := t.TempDir()
	stage := filepath.Join(root, "stage")
	if err := os.MkdirAll(filepath.Join(stage, "computer"), 0700); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"obli.exe", "oblivion-agent.exe"} {
		if err := os.WriteFile(filepath.Join(stage, "computer", name), []byte("unsigned fixture, not a Windows executable"), 0600); err != nil {
			t.Fatal(err)
		}
	}
	spec := release.BuildSpec{Version: "1.0.0", Revision: 1, Architecture: "amd64", Roles: []string{"computer"},
		PublishedAt: time.Now().Add(-time.Hour).UTC().Format(time.RFC3339), ExpiresAt: time.Now().Add(time.Hour).UTC().Format(time.RFC3339)}
	metadata := filepath.Join(root, "metadata.json")
	data, _ := json.Marshal(spec)
	if err := os.WriteFile(metadata, data, 0600); err != nil {
		t.Fatal(err)
	}
	destination := filepath.Join(root, "output.zip")
	var receipt bytes.Buffer
	err := run(context.Background(), []string{"build", "--stage", stage, "--metadata", metadata, "--output", destination, "--publisher-key", public}, strings.NewReader(encoded), &receipt)
	if err == nil || receipt.Len() != 0 {
		t.Fatal("unsigned payload reported success", err)
	}
	if strings.Contains(err.Error(), encoded) {
		t.Fatal("failed build leaked signer input")
	}
	if _, err = os.Lstat(destination); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("unsigned archive was published")
	}
	entries, _ := os.ReadDir(root)
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), ".oblivion-release-") {
			t.Fatal("failed command leaked build snapshot")
		}
	}
}

func TestCancelledCommandDoesNotReadSigningInput(t *testing.T) {
	_, _, public := commandKey()
	input := &countingReader{reader: strings.NewReader("must not read")}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	var output bytes.Buffer
	err := run(ctx, []string{"build", "--stage", "missing", "--metadata", "missing.json", "--output", "out.zip", "--publisher-key", public}, input, &output)
	if !errors.Is(err, context.Canceled) || input.count != 0 || output.Len() != 0 {
		t.Fatal("cancelled command continued")
	}
}

func TestCatalogueCommandAssemblesVerifiedBytesAndDoesNotOverwrite(t *testing.T) {
	key, encoded, public := commandKey()
	root := t.TempDir()
	source := filepath.Join(root, "source")
	if err := os.Mkdir(source, 0700); err != nil {
		t.Fatal(err)
	}
	artifactBytes := []byte("test-only collector distribution bytes; never executed")
	hash := sha256.Sum256(artifactBytes)
	artifact := release.DownloadArtifact{ID: "linux-site-amd64", Platform: "linux", Architecture: "amd64", Roles: []string{"site"},
		Version: "1.2.3", ReleaseRevision: 7, Filename: "collector.tar.gz", Size: int64(len(artifactBytes)), SHA256: hex.EncodeToString(hash[:])}
	if err := os.WriteFile(filepath.Join(source, artifact.Filename), artifactBytes, 0600); err != nil {
		t.Fatal(err)
	}
	spec := release.CatalogueSpec{Revision: 9, IssuedAt: time.Now().Add(-time.Minute).UTC().Format(time.RFC3339),
		ExpiresAt: time.Now().Add(time.Hour).UTC().Format(time.RFC3339), Artifacts: []release.DownloadArtifact{artifact}}
	metadata := filepath.Join(root, "catalogue-spec.json")
	specBytes, _ := json.Marshal(spec)
	if err := os.WriteFile(metadata, specBytes, 0600); err != nil {
		t.Fatal(err)
	}
	output := filepath.Join(root, "publication")
	args := []string{"catalogue", "--stage", source, "--metadata", metadata, "--output", output, "--publisher-key", public}
	var receipt bytes.Buffer
	if err := run(context.Background(), args, strings.NewReader(encoded), &receipt); err != nil {
		t.Fatal(err)
	}
	var result release.CatalogueBuildResult
	if err := json.Unmarshal(receipt.Bytes(), &result); err != nil || result.Catalogue.Revision != 9 {
		t.Fatal("invalid receipt", err)
	}
	data, err := os.ReadFile(filepath.Join(output, "downloads.json"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err = release.VerifyCatalogue(data, key.Public().(ed25519.PublicKey), 9, time.Now()); err != nil {
		t.Fatal(err)
	}
	copied, _ := os.ReadFile(filepath.Join(output, artifact.Filename))
	if !bytes.Equal(copied, artifactBytes) {
		t.Fatal("command changed artifact bytes")
	}
	receipt.Reset()
	if err = run(context.Background(), args, strings.NewReader(encoded), &receipt); err == nil || receipt.Len() != 0 {
		t.Fatal("command reused existing publication")
	}
	if preserved, _ := os.ReadFile(filepath.Join(output, "downloads.json")); !bytes.Equal(data, preserved) {
		t.Fatal("retry changed existing signed catalogue")
	}
}

func TestCatalogueCommandKeepsPackageTrustExplicitAndRejectsBadSpecBeforeSecretRead(t *testing.T) {
	_, encoded, public := commandKey()
	args := []string{"catalogue", "--stage", "stage", "--metadata", "metadata.json", "--output", "publication", "--publisher-key", public, "--package-key", public}
	if got, err := parseOptions(args); err != nil || got.command != "catalogue" || !bytes.Equal(got.publicKey, got.packageKey) {
		t.Fatal("explicit public-key arguments rejected", err)
	}
	for _, invalid := range [][]string{
		append(append([]string{}, args[:len(args)-1]...), public+"\n"),
		append(append([]string{}, args...), "--allow-unsigned"),
		append(append([]string{}, args...), "--seed", encoded),
	} {
		if _, err := parseOptions(invalid); err == nil {
			t.Fatal("unsafe catalogue option accepted")
		}
	}
	root := t.TempDir()
	metadata := filepath.Join(root, "metadata.json")
	if err := os.WriteFile(metadata, []byte("{\"revision\":1,\"Revision\":2}"), 0600); err != nil {
		t.Fatal(err)
	}
	input := &countingReader{reader: strings.NewReader(encoded)}
	var output bytes.Buffer
	err := run(context.Background(), []string{"catalogue", "--stage", root, "--metadata", metadata, "--output", filepath.Join(root, "unused"), "--publisher-key", public}, input, &output)
	if err == nil || input.count != 0 || output.Len() != 0 {
		t.Fatal("ambiguous metadata reached signing input")
	}
}
