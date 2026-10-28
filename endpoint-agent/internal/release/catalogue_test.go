package release

import (
	"bytes"
	"context"
	"crypto"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
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

type catalogueSigner struct {
	key   ed25519.PrivateKey
	calls int
	sign  func([]byte) ([]byte, error)
}

func (s *catalogueSigner) Public() crypto.PublicKey { return s.key.Public() }
func (s *catalogueSigner) Sign(_ io.Reader, message []byte, opt crypto.SignerOpts) ([]byte, error) {
	s.calls++
	if opt.HashFunc() != crypto.Hash(0) || !bytes.HasPrefix(message, []byte(CatalogueDomain)) {
		return nil, errors.New("wrong catalogue signing domain")
	}
	if s.sign != nil {
		return s.sign(message)
	}
	return ed25519.Sign(s.key, message), nil
}
func catalogueFixture(t *testing.T) (string, string, CatalogueSpec, ed25519.PrivateKey, ed25519.PrivateKey) {
	t.Helper()
	root, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	source := filepath.Join(root, "artifacts")
	if err := os.Mkdir(source, 0700); err != nil {
		t.Fatal(err)
	}
	manifest, contents, packageKey := fixture(t)
	zipPath := archiveFixture(t, manifestBytes(t, manifest), contents, packageKey, nil)
	zipBytes, err := os.ReadFile(zipPath)
	if err != nil {
		t.Fatal(err)
	}
	digest := sha256.Sum256(zipBytes)
	artifact := DownloadArtifact{ID: "windows-both-amd64", Platform: "windows", Architecture: "amd64", Roles: []string{"site", "computer"}, Version: manifest.Version, ReleaseRevision: manifest.Revision,
		Filename: "oblivion-1.2.3-amd64.zip", Size: int64(len(zipBytes)), SHA256: hex.EncodeToString(digest[:])}
	if err = os.WriteFile(filepath.Join(source, artifact.Filename), zipBytes, 0600); err != nil {
		t.Fatal(err)
	}
	spec := CatalogueSpec{Revision: 5, IssuedAt: time.Now().Add(-time.Minute).UTC().Format(time.RFC3339), ExpiresAt: time.Now().Add(time.Hour).UTC().Format(time.RFC3339), Artifacts: []DownloadArtifact{artifact}}
	key := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{31}, 32))
	return source, filepath.Join(root, "publication"), spec, key, packageKey
}
func buildCatalogueFixture(ctx context.Context, source, output string, spec CatalogueSpec, signer crypto.Signer, key, packageKey ed25519.PrivateKey) (CatalogueBuildResult, error) {
	return BuildCatalogue(ctx, source, output, spec, signer, key.Public().(ed25519.PublicKey), packageKey.Public().(ed25519.PublicKey), time.Now())
}
func signCatalogueFixture(t *testing.T, c DownloadCatalogue, key ed25519.PrivateKey, domain string) []byte {
	t.Helper()
	payload, err := json.Marshal(c)
	if err != nil {
		t.Fatal(err)
	}
	return signedCataloguePayload(t, payload, key, domain)
}
func signedCataloguePayload(t *testing.T, payload []byte, key ed25519.PrivateKey, domain string) []byte {
	t.Helper()
	data, err := encodeCatalogue(ed25519.Sign(key, append([]byte(domain), payload...)), payload)
	if err != nil {
		t.Fatal(err)
	}
	return data
}
func assertNoCatalogueOutput(t *testing.T, output string) {
	t.Helper()
	if _, err := os.Lstat(output); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("failed assembly retained a publication directory", err)
	}
}

func TestCatalogueBuildCopiesOnlyReviewedBytesAndRoundTrips(t *testing.T) {
	source, output, spec, key, packageKey := catalogueFixture(t)
	if err := os.WriteFile(filepath.Join(source, "not-selected.env"), []byte("fictional ignored data"), 0600); err != nil {
		t.Fatal(err)
	}
	original, _ := os.ReadFile(filepath.Join(source, spec.Artifacts[0].Filename))
	signer := &catalogueSigner{key: key, sign: func(message []byte) ([]byte, error) {
		if _, err := os.Lstat(filepath.Join(output, "downloads.json")); !errors.Is(err, os.ErrNotExist) {
			t.Fatal("catalogue enabled before verification finished")
		}
		if err := os.WriteFile(filepath.Join(source, spec.Artifacts[0].Filename), []byte("source changed after snapshot"), 0600); err != nil {
			t.Fatal(err)
		}
		return ed25519.Sign(key, message), nil
	}}
	result, err := buildCatalogueFixture(context.Background(), source, output, spec, signer, key, packageKey)
	if err != nil {
		t.Fatal(err)
	}
	encoded, err := os.ReadFile(filepath.Join(output, "downloads.json"))
	if err != nil {
		t.Fatal(err)
	}
	catalogue, err := VerifyCatalogue(encoded, key.Public().(ed25519.PublicKey), 5, time.Now())
	if err != nil || !reflect.DeepEqual(catalogue, result.Catalogue) || !reflect.DeepEqual(catalogue.Artifacts, spec.Artifacts) {
		t.Fatal("signed catalogue mismatch", err)
	}
	actual, _ := os.ReadFile(filepath.Join(output, spec.Artifacts[0].Filename))
	if !bytes.Equal(actual, original) {
		t.Fatal("mutable source bytes were published")
	}
	entries, _ := os.ReadDir(output)
	if len(entries) != 2 {
		t.Fatal("unselected source content was copied")
	}
	hash := sha256.Sum256(encoded)
	if result.Path != output || result.CatalogueSize != len(encoded) || result.CatalogueSHA256 != hex.EncodeToString(hash[:]) || signer.calls != 1 {
		t.Fatal("incorrect publication receipt")
	}
}

func TestCatalogueBuildSupportsLinuxSiteBytesAndExplicitEmptyWithdrawal(t *testing.T) {
	for _, empty := range []bool{false, true} {
		source, output, spec, key, packageKey := catalogueFixture(t)
		spec.Artifacts = []DownloadArtifact{}
		if !empty {
			data := []byte("explicit test-only Linux distribution bytes; no installation claim")
			hash := sha256.Sum256(data)
			a := DownloadArtifact{"linux-site-arm64", "linux", "arm64", []string{"site"}, "1.0.0", 2, "collector-arm64.tar.gz", int64(len(data)), hex.EncodeToString(hash[:])}
			spec.Artifacts = []DownloadArtifact{a}
			if err := os.WriteFile(filepath.Join(source, a.Filename), data, 0600); err != nil {
				t.Fatal(err)
			}
		}
		result, err := buildCatalogueFixture(context.Background(), source, output, spec, key, key, packageKey)
		if err != nil || len(result.Catalogue.Artifacts) != len(spec.Artifacts) {
			t.Fatal("valid catalogue assembly failed", err)
		}
		data, _ := os.ReadFile(filepath.Join(output, "downloads.json"))
		if _, err = VerifyCatalogue(data, key.Public().(ed25519.PublicKey), 5, time.Now()); err != nil {
			t.Fatal(err)
		}
	}
}

func TestCatalogueRefusesChangedMissingAndMislabelledPackagesBeforeSigning(t *testing.T) {
	for _, kind := range []string{"bytes", "missing", "size", "version", "revision", "roles", "architecture", "package key", "catalogue lifetime"} {
		t.Run(kind, func(t *testing.T) {
			source, output, spec, key, packageKey := catalogueFixture(t)
			signer := &catalogueSigner{key: key}
			a := &spec.Artifacts[0]
			switch kind {
			case "bytes":
				os.WriteFile(filepath.Join(source, a.Filename), bytes.Repeat([]byte{'X'}, int(a.Size)), 0600)
			case "missing":
				os.Remove(filepath.Join(source, a.Filename))
			case "size":
				a.Size++
			case "version":
				a.Version = "9.0.0"
			case "revision":
				a.ReleaseRevision--
			case "roles":
				a.Roles = []string{"computer"}
			case "architecture":
				a.Architecture = "arm64"
			case "package key":
				packageKey = ed25519.NewKeyFromSeed(bytes.Repeat([]byte{33}, 32))
			case "catalogue lifetime":
				spec.ExpiresAt = time.Now().Add(48 * time.Hour).UTC().Format(time.RFC3339)
			}
			if _, err := buildCatalogueFixture(context.Background(), source, output, spec, signer, key, packageKey); err == nil {
				t.Fatal("unapproved or misleading artifact accepted")
			}
			if signer.calls != 0 {
				t.Fatal("invalid package reached catalogue signing")
			}
			assertNoCatalogueOutput(t, output)
		})
	}
}

func TestCatalogueRejectsBadMetadataAndUnsafeFilenamesBeforeCopy(t *testing.T) {
	for _, kind := range []string{"traversal", "device name", "case extension", "duplicate name", "duplicate id", "linux computer", "nil artifacts", "duplicate role", "zero size", "bad hash", "future", "expired", "revision", "many artifacts"} {
		t.Run(kind, func(t *testing.T) {
			source, output, spec, key, packageKey := catalogueFixture(t)
			a := &spec.Artifacts[0]
			switch kind {
			case "traversal":
				a.Filename = "../outside.zip"
			case "device name":
				a.Filename = "COM0.zip"
			case "case extension":
				a.Filename = "artifact.ZIP"
			case "duplicate name":
				b := *a
				b.ID = "another-id"
				b.Filename = strings.ToUpper(strings.TrimSuffix(a.Filename, ".zip")) + ".zip"
				spec.Artifacts = append(spec.Artifacts, b)
			case "duplicate id":
				spec.Artifacts = append(spec.Artifacts, *a)
			case "linux computer":
				a.Platform = "linux"
				a.Filename = "collector.tar.gz"
				a.Roles = []string{"computer"}
			case "nil artifacts":
				spec.Artifacts = nil
			case "duplicate role":
				a.Roles = []string{"computer", "computer"}
			case "zero size":
				a.Size = 0
			case "bad hash":
				a.SHA256 = strings.Repeat("A", 64)
			case "future":
				spec.IssuedAt = time.Now().Add(6 * time.Minute).UTC().Format(time.RFC3339)
			case "expired":
				spec.ExpiresAt = time.Now().Add(-time.Second).UTC().Format(time.RFC3339)
			case "revision":
				spec.Revision = 0
			case "many artifacts":
				for len(spec.Artifacts) <= 16 {
					spec.Artifacts = append(spec.Artifacts, *a)
				}
			}
			signer := &catalogueSigner{key: key}
			if _, err := buildCatalogueFixture(context.Background(), source, output, spec, signer, key, packageKey); err == nil || signer.calls != 0 {
				t.Fatal("invalid metadata reached publisher")
			}
			assertNoCatalogueOutput(t, output)
		})
	}
}

func TestCatalogueFailedSigningAndCancellationRemoveOnlyOwnedOutput(t *testing.T) {
	for _, kind := range []string{"signature", "signer error", "cancel before", "cancel after copy"} {
		t.Run(kind, func(t *testing.T) {
			source, output, spec, key, packageKey := catalogueFixture(t)
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			signer := &catalogueSigner{key: key}
			switch kind {
			case "signature":
				signer.sign = func([]byte) ([]byte, error) { return make([]byte, 64), nil }
			case "signer error":
				signer.sign = func([]byte) ([]byte, error) { return nil, errors.New("unavailable") }
			case "cancel before":
				cancel()
			case "cancel after copy":
				signer.sign = func(message []byte) ([]byte, error) { cancel(); return ed25519.Sign(key, message), nil }
			}
			if _, err := buildCatalogueFixture(ctx, source, output, spec, signer, key, packageKey); err == nil {
				t.Fatal("failed catalogue was accepted")
			}
			assertNoCatalogueOutput(t, output)
			if _, err := os.Stat(filepath.Join(source, spec.Artifacts[0].Filename)); err != nil {
				t.Fatal("cleanup removed source artifact")
			}
		})
	}
}

func TestCatalogueCannotReplaceExistingOutputOrWriteIntoSource(t *testing.T) {
	source, output, spec, key, packageKey := catalogueFixture(t)
	if err := os.Mkdir(output, 0700); err != nil {
		t.Fatal(err)
	}
	sentinel := filepath.Join(output, "existing.txt")
	if err := os.WriteFile(sentinel, []byte("preserve existing release"), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := buildCatalogueFixture(context.Background(), source, output, spec, key, key, packageKey); err == nil {
		t.Fatal("existing publication was reused")
	}
	if actual, _ := os.ReadFile(sentinel); string(actual) != "preserve existing release" {
		t.Fatal("existing output changed")
	}
	if _, err := buildCatalogueFixture(context.Background(), source, filepath.Join(source, "new-output"), spec, key, key, packageKey); err == nil {
		t.Fatal("published within source tree")
	}
	assertNoCatalogueOutput(t, filepath.Join(source, "new-output"))
}

func TestCatalogueReaderRejectsAmbiguousNoncanonicalAndUntrustedEnvelopes(t *testing.T) {
	_, _, spec, key, _ := catalogueFixture(t)
	c := catalogueFromSpec(spec)
	public := key.Public().(ed25519.PublicKey)
	valid := signCatalogueFixture(t, c, key, CatalogueDomain)
	if _, err := VerifyCatalogue(valid, public, 5, time.Now()); err != nil {
		t.Fatal(err)
	}
	if _, err := VerifyCatalogue(valid, public, 6, time.Now()); err == nil {
		t.Fatal("revision floor ignored")
	}
	other := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{32}, 32)).Public().(ed25519.PublicKey)
	if _, err := VerifyCatalogue(valid, other, 5, time.Now()); err == nil {
		t.Fatal("wrong publisher accepted")
	}
	payload, _ := json.Marshal(c)
	cases := [][]byte{
		signCatalogueFixture(t, c, key, SignatureDomain), []byte(strings.Repeat("x", MaxCatalogueBytes+1)),
		signedCataloguePayload(t, bytes.Replace(payload, []byte("\"schema\":1"), []byte("\"schema\":1,\"schema\":1"), 1), key, CatalogueDomain),
		signedCataloguePayload(t, bytes.Replace(payload, []byte("\"schema\":1"), []byte("\"Schema\":1"), 1), key, CatalogueDomain),
		signedCataloguePayload(t, bytes.Replace(payload, []byte("\"filename\":"), []byte("\"Filename\":"), 1), key, CatalogueDomain),
		bytes.Replace(valid, []byte("\"payload\":"), []byte("\"Payload\":"), 1),
	}
	var signed envelope
	json.Unmarshal(valid, &signed)
	signed.Payload = signed.Payload[:4] + "\n" + signed.Payload[4:]
	noncanonical, _ := json.Marshal(signed)
	cases = append(cases, noncanonical)
	for _, data := range cases {
		if _, err := VerifyCatalogue(data, public, 5, time.Now()); err == nil {
			t.Fatal("ambiguous or untrusted catalogue accepted")
		}
	}
}

func TestCatalogueSpecReaderRequiresExactCompleteBoundedObject(t *testing.T) {
	_, _, spec, _, _ := catalogueFixture(t)
	data, _ := json.Marshal(spec)
	if got, err := ReadCatalogueSpec(bytes.NewReader(data)); err != nil || !reflect.DeepEqual(got, spec) {
		t.Fatal("valid spec rejected", err)
	}
	for _, invalid := range []string{
		"null", "[]", "{}", string(data) + "{}", strings.Repeat(" ", MaxCatalogueBytes+1),
		strings.Replace(string(data), "\"revision\":5", "\"revision\":5,\"revision\":5", 1),
		strings.Replace(string(data), "\"revision\":5", "\"Revision\":5", 1),
		strings.Replace(string(data), "\"size\":", "\"Size\":", 1),
		strings.Replace(string(data), "\"revision\":5", "\"revision\":5,\"server\":\"https://wrong.test\"", 1),
	} {
		if _, err := ReadCatalogueSpec(strings.NewReader(invalid)); err == nil {
			t.Fatal("invalid spec accepted")
		}
	}
}

func TestCatalogueWindowsZipRequiresIndependentPackageAuthority(t *testing.T) {
	source, output, spec, key, _ := catalogueFixture(t)
	signer := &catalogueSigner{key: key}
	_, err := BuildCatalogue(context.Background(), source, output, spec, signer, key.Public().(ed25519.PublicKey), nil, time.Now())
	if err == nil || signer.calls != 0 {
		t.Fatal("package authority was silently inherited")
	}
	assertNoCatalogueOutput(t, output)
}

// Retain this public-key encoding helper for the cross-language fixture; no
// private signing material is sent to the PHP consumer.
func catalogueFixturePublic(key ed25519.PrivateKey) string {
	return base64.StdEncoding.EncodeToString(key.Public().(ed25519.PublicKey))
}
