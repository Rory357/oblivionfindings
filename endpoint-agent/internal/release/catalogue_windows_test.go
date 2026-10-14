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

func TestCatalogueCannotPublishActualUnsignedWindowsInstaller(t *testing.T) {
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
	a := DownloadArtifact{"windows-computer-setup", "windows", runtime.GOARCH, []string{"computer"}, "1.2.3", 7, "oblivion-setup.exe", int64(len(bytes)), hex.EncodeToString(hash[:])}
	spec.Artifacts = []DownloadArtifact{a}
	if err = os.WriteFile(filepath.Join(source, a.Filename), bytes, 0600); err != nil {
		t.Fatal(err)
	}
	signer := &catalogueSigner{key: key}
	if _, err = buildCatalogueFixture(context.Background(), source, output, spec, signer, key, packageKey); err == nil || signer.calls != 0 {
		t.Fatal("unsigned PE was advertised as accepted", err)
	}
	assertNoCatalogueOutput(t, output)
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
