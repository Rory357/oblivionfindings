package release

import (
	"bytes"
	"context"
	"crypto"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

type buildSigner struct {
	key   ed25519.PrivateKey
	calls int
	sign  func([]byte) ([]byte, error)
}

func (s *buildSigner) Public() crypto.PublicKey { return s.key.Public() }
func (s *buildSigner) Sign(_ io.Reader, message []byte, options crypto.SignerOpts) ([]byte, error) {
	s.calls++
	if options.HashFunc() != crypto.Hash(0) || !bytes.HasPrefix(message, []byte(SignatureDomain)) {
		return nil, errors.New("unexpected signing request")
	}
	if s.sign != nil {
		return s.sign(message)
	}
	return ed25519.Sign(s.key, message), nil
}

func buildFixture(t *testing.T) (string, string, BuildSpec, ed25519.PrivateKey, map[string][]byte) {
	t.Helper()
	root := t.TempDir()
	source := filepath.Join(root, "stage")
	if err := os.Mkdir(source, 0700); err != nil {
		t.Fatal(err)
	}
	data := map[string][]byte{
		"computer/oblivion-agent.exe":                       []byte("fixture agent, not executable"),
		"computer/obli.exe":                                 []byte("fixture tray, not executable"),
		"computer/runtime/Microsoft.WindowsDesktop.App.dll": []byte("fixture runtime bytes"),
		"computer/licenses/NOTICE.txt":                      []byte("fixture notice"),
	}
	for name, content := range data {
		filename := filepath.Join(source, filepath.FromSlash(name))
		if err := os.MkdirAll(filepath.Dir(filename), 0700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filename, content, 0600); err != nil {
			t.Fatal(err)
		}
	}
	spec := BuildSpec{Version: "1.2.3", Revision: 7, Architecture: "amd64", Roles: []string{"computer"},
		PublishedAt: time.Now().Add(-time.Hour).UTC().Format(time.RFC3339),
		ExpiresAt:   time.Now().Add(time.Hour).UTC().Format(time.RFC3339)}
	key := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{19}, 32))
	return source, filepath.Join(root, "release.zip"), spec, key, data
}
func buildTest(t *testing.T, ctx context.Context, source, output string, spec BuildSpec, signer crypto.Signer, key ed25519.PrivateKey, verify executableVerifier) (BuildResult, error) {
	t.Helper()
	return buildWindows(ctx, source, output, spec, signer, key.Public().(ed25519.PublicKey), time.Now(), verify)
}
func fixtureExecutable(string, string) error { return nil }
func assertNoBuildOutput(t *testing.T, output string) {
	t.Helper()
	if _, err := os.Lstat(output); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("failed build left a published output", err)
	}
	entries, err := os.ReadDir(filepath.Dir(output))
	if err != nil {
		t.Fatal(err)
	}
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), ".oblivion-release-") {
			t.Fatal("private build snapshot leaked")
		}
	}
}

func TestBuildRoundTripsThroughInstallerAndPreservesRuntimeFiles(t *testing.T) {
	source, output, spec, key, data := buildFixture(t)
	var verified []string
	result, err := buildTest(t, context.Background(), source, output, spec, key, key, func(name, architecture string) error {
		if strings.HasPrefix(name, source+string(filepath.Separator)) || architecture != "amd64" {
			t.Fatal("verified mutable source or wrong architecture")
		}
		verified = append(verified, filepath.Base(name))
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(verified, []string{"obli.exe", "oblivion-agent.exe"}) {
		t.Fatal("not all executable bytes were verified", verified)
	}
	archive, err := os.ReadFile(output)
	if err != nil {
		t.Fatal(err)
	}
	digest := sha256.Sum256(archive)
	if result.Size != int64(len(archive)) || result.SHA256 != hex.EncodeToString(digest[:]) || result.Path != output {
		t.Fatal("wrong output receipt")
	}
	pack, err := Open(output, key.Public().(ed25519.PublicKey), "amd64", 7, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	defer pack.Close()
	extracted := t.TempDir()
	if err = pack.ExtractNew(extracted); err != nil {
		t.Fatal(err)
	}
	for name, expected := range data {
		actual, err := os.ReadFile(filepath.Join(extracted, filepath.FromSlash(name)))
		if err != nil || !bytes.Equal(actual, expected) {
			t.Fatal("lost or altered payload bytes", name, err)
		}
	}
	if !reflect.DeepEqual(pack.Manifest(), result.Manifest) {
		t.Fatal("receipt differs from signed manifest")
	}
}

func TestBuildUsesSnapshotWhenOriginalStagingFilesChange(t *testing.T) {
	source, output, spec, key, data := buildFixture(t)
	_, err := buildTest(t, context.Background(), source, output, spec, key, key, func(string, string) error {
		for name := range data {
			if err := os.WriteFile(filepath.Join(source, filepath.FromSlash(name)), []byte("changed after snapshot"), 0600); err != nil {
				t.Fatal(err)
			}
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	pack, err := Open(output, key.Public().(ed25519.PublicKey), "amd64", 7, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	defer pack.Close()
	target := t.TempDir()
	if err = pack.ExtractNew(target); err != nil {
		t.Fatal(err)
	}
	for name, expected := range data {
		actual, _ := os.ReadFile(filepath.Join(target, filepath.FromSlash(name)))
		if !bytes.Equal(actual, expected) {
			t.Fatal("source mutation reached signed artifact")
		}
	}
}

func TestPublicBuilderRejectsUnsignedPayloadBeforeSigning(t *testing.T) {
	source, output, spec, key, _ := buildFixture(t)
	signer := &buildSigner{key: key}
	if _, err := BuildWindows(context.Background(), source, output, spec, signer, key.Public().(ed25519.PublicKey), time.Now()); err == nil {
		t.Fatal("public builder accepted unsigned fixture executable")
	}
	if signer.calls != 0 {
		t.Fatal("signed a manifest after executable verification failed")
	}
	assertNoBuildOutput(t, output)
}

func TestBuildRejectsIncompleteUnsafeAndOversizedStaging(t *testing.T) {
	tests := map[string]func(string){
		"missing tray":    func(source string) { os.Remove(filepath.Join(source, "computer", "obli.exe")) },
		"empty tray":      func(source string) { os.WriteFile(filepath.Join(source, "computer", "obli.exe"), nil, 0600) },
		"undeclared root": func(source string) { os.WriteFile(filepath.Join(source, "profile.json"), []byte("{}"), 0600) },
		"overlong path": func(source string) {
			os.WriteFile(filepath.Join(source, "computer", strings.Repeat("x", 215)+".txt"), []byte("x"), 0600)
		},
		"oversized file": func(source string) {
			f, err := os.OpenFile(filepath.Join(source, "computer", "oversized.bin"), os.O_CREATE|os.O_WRONLY|os.O_EXCL, 0600)
			if err != nil {
				t.Fatal(err)
			}
			defer f.Close()
			if err = f.Truncate(MaxFileBytes + 1); err != nil {
				t.Fatal(err)
			}
		},
	}
	for name, mutate := range tests {
		t.Run(name, func(t *testing.T) {
			source, output, spec, key, _ := buildFixture(t)
			mutate(source)
			signer := &buildSigner{key: key}
			if _, err := buildTest(t, context.Background(), source, output, spec, signer, key, fixtureExecutable); err == nil {
				t.Fatal("unsafe source accepted")
			}
			if signer.calls != 0 {
				t.Fatal("unsafe source reached signing authority")
			}
			assertNoBuildOutput(t, output)
		})
	}
}

func TestBuildRefusesInvalidMetadataAndAuthority(t *testing.T) {
	for _, invalid := range []string{"expiry", "revision", "version", "architecture", "role", "key"} {
		t.Run(invalid, func(t *testing.T) {
			source, output, spec, key, _ := buildFixture(t)
			signer := &buildSigner{key: key}
			switch invalid {
			case "expiry":
				spec.ExpiresAt = time.Now().Add(-time.Minute).UTC().Format(time.RFC3339)
			case "revision":
				spec.Revision = 0
			case "version":
				spec.Version = "dev"
			case "architecture":
				spec.Architecture = "x86"
			case "role":
				spec.Roles = []string{"site"}
			case "key":
				signer.key = ed25519.NewKeyFromSeed(bytes.Repeat([]byte{21}, 32))
			}
			if _, err := buildTest(t, context.Background(), source, output, spec, signer, key, fixtureExecutable); err == nil {
				t.Fatal("invalid authority or metadata accepted")
			}
			if signer.calls != 0 {
				t.Fatal("invalid metadata or key reached signing authority")
			}
			assertNoBuildOutput(t, output)
		})
	}
}

func TestBuildNeverOverwritesExistingOrRacingOutput(t *testing.T) {
	for _, race := range []bool{false, true} {
		t.Run(map[bool]string{false: "existing", true: "appeared during build"}[race], func(t *testing.T) {
			source, output, spec, key, _ := buildFixture(t)
			sentinel := []byte("previous independent release")
			signer := &buildSigner{key: key}
			if race {
				signer.sign = func(message []byte) ([]byte, error) {
					if err := os.WriteFile(output, sentinel, 0600); err != nil {
						t.Fatal(err)
					}
					return ed25519.Sign(key, message), nil
				}
			} else if err := os.WriteFile(output, sentinel, 0600); err != nil {
				t.Fatal(err)
			}
			if _, err := buildTest(t, context.Background(), source, output, spec, signer, key, fixtureExecutable); err == nil {
				t.Fatal("existing output was replaced")
			}
			actual, _ := os.ReadFile(output)
			if !bytes.Equal(actual, sentinel) {
				t.Fatal("changed pre-existing output")
			}
		})
	}
}

func TestBuildRejectsOutputInsideStaging(t *testing.T) {
	source, _, spec, key, _ := buildFixture(t)
	output := filepath.Join(source, "computer", "release.zip")
	if _, err := buildTest(t, context.Background(), source, output, spec, key, key, fixtureExecutable); err == nil {
		t.Fatal("recursive packaging allowed")
	}
	assertNoBuildOutput(t, output)
}

func TestBuildInvalidSignatureAndPostSnapshotMutationLeaveNoOutput(t *testing.T) {
	for _, kind := range []string{"bad signature", "signer error", "runtime mutation"} {
		t.Run(kind, func(t *testing.T) {
			source, output, spec, key, _ := buildFixture(t)
			snapshot := ""
			signer := &buildSigner{key: key}
			signer.sign = func(message []byte) ([]byte, error) {
				switch kind {
				case "bad signature":
					return make([]byte, ed25519.SignatureSize), nil
				case "signer error":
					return nil, errors.New("signing unavailable")
				case "runtime mutation":
					err := os.WriteFile(filepath.Join(snapshot, "computer/runtime/Microsoft.WindowsDesktop.App.dll"), []byte("changed after signing inventory"), 0600)
					if err != nil {
						t.Fatal(err)
					}
				}
				return ed25519.Sign(key, message), nil
			}
			_, err := buildTest(t, context.Background(), source, output, spec, signer, key, func(name, _ string) error {
				snapshot = filepath.Dir(filepath.Dir(name))
				return nil
			})
			if err == nil {
				t.Fatal("failed authority or altered snapshot was published")
			}
			assertNoBuildOutput(t, output)
		})
	}
}

func TestCancelledBuildDoesNotPublish(t *testing.T) {
	for _, afterSign := range []bool{false, true} {
		source, output, spec, key, _ := buildFixture(t)
		ctx, cancel := context.WithCancel(context.Background())
		signer := &buildSigner{key: key}
		if afterSign {
			signer.sign = func(message []byte) ([]byte, error) { cancel(); return ed25519.Sign(key, message), nil }
		} else {
			cancel()
		}
		if _, err := buildTest(t, ctx, source, output, spec, signer, key, fixtureExecutable); err == nil {
			t.Fatal("cancelled build published")
		}
		cancel()
		assertNoBuildOutput(t, output)
	}
}

func TestBuildMetadataIsStrictAndBounded(t *testing.T) {
	_, _, spec, _, _ := buildFixture(t)
	encoded, _ := json.Marshal(spec)
	if got, err := ReadBuildSpec(bytes.NewReader(encoded)); err != nil || !reflect.DeepEqual(got, spec) {
		t.Fatal("valid metadata rejected", err)
	}
	for _, invalid := range []string{
		string(encoded) + "{}", strings.Replace(string(encoded), "\"version\":", "\"publisher_key\":\"replacement\",\"version\":", 1),
		strings.Replace(string(encoded), "\"revision\":7", "\"revision\":6,\"revision\":7", 1),
		strings.Repeat(" ", 4097), "{", "[]", "null",
	} {
		if _, err := ReadBuildSpec(strings.NewReader(invalid)); err == nil {
			t.Fatal("ambiguous or oversized metadata accepted")
		}
	}
}

func TestBuildArchiveWriterEnforcesLimitWithoutPartialOvershoot(t *testing.T) {
	var output bytes.Buffer
	writer := &buildLimitWriter{writer: &output, remaining: 5}
	if n, err := writer.Write([]byte("1234")); n != 4 || err != nil {
		t.Fatal("bounded write failed")
	}
	if n, err := writer.Write([]byte("56")); n != 0 || err == nil || output.String() != "1234" {
		t.Fatal("archive limit was exceeded")
	}
}
