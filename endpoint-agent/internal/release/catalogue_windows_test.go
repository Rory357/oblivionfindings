package release

import (
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"
)

func TestCataloguePublishesUnsignedWindowsInstallerWithSignedManifestAndRejectsWrongArchitecture(t *testing.T) {
	source, output, spec, key, packageKey := catalogueFixture(t)
	filename, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	bytes, err := os.ReadFile(filename)
	if err != nil {
		t.Fatal(err)
	}
	hash := sha256.Sum256(bytes)
	other := "arm64"
	if runtime.GOARCH == "arm64" {
		other = "amd64"
	}
	wrongArch := DownloadArtifact{"windows-computer-setup", "windows", other, []string{"computer"}, "1.2.3", 7, "oblivion-setup.exe", int64(len(bytes)), hex.EncodeToString(hash[:])}
	spec.Artifacts = []DownloadArtifact{wrongArch}
	if err = os.WriteFile(filepath.Join(source, wrongArch.Filename), bytes, 0600); err != nil {
		t.Fatal(err)
	}
	mismatchSigner := &catalogueSigner{key: key}
	if _, err = BuildCatalogue(context.Background(), source, output, spec, mismatchSigner, key.Public().(ed25519.PublicKey), packageKey.Public().(ed25519.PublicKey), time.Now()); err == nil || mismatchSigner.calls != 0 {
		t.Fatal("wrong-architecture PE installer was advertised as accepted", err)
	}
	assertNoCatalogueOutput(t, output)

	valid := wrongArch
	valid.Architecture = runtime.GOARCH
	spec.Artifacts = []DownloadArtifact{valid}
	signer := &catalogueSigner{key: key}
	result, err := BuildCatalogue(context.Background(), source, output, spec, signer, key.Public().(ed25519.PublicKey), packageKey.Public().(ed25519.PublicKey), time.Now())
	if err != nil || signer.calls != 1 || result.CatalogueSHA256 == "" {
		t.Fatal("unsigned PE installer failed signed catalogue build", err)
	}
}

func TestCatalogueHoldsAllVerifiedArtifactsAgainstWritesUntilCommit(t *testing.T) {
	source, output, spec, key, packageKey := catalogueFixture(t)
	signer := &catalogueSigner{key: key, sign: func(message []byte) ([]byte, error) {
		filename := filepath.Join(output, spec.Artifacts[0].Filename)
		if err := os.WriteFile(filename, []byte("substituted after verification"), 0600); err == nil {
			t.Fatal("verified artifact could be changed during signing")
		}
		if err := os.Rename(filename, filepath.Join(output, "renamed.zip")); err == nil {
			t.Fatal("verified artifact could be replaced during signing")
		}
		return ed25519.Sign(key, message), nil
	}}
	if _, err := BuildCatalogue(context.Background(), source, output, spec, signer, key.Public().(ed25519.PublicKey), packageKey.Public().(ed25519.PublicKey), time.Now()); err != nil {
		t.Fatal(err)
	}
}
