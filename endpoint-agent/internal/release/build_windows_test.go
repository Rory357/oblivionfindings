package release

import (
	"context"
	"crypto/ed25519"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestBuildKeepsVerifiedExecutablesLockedUntilArchiveIsWritten(t *testing.T) {
	source, output, spec, key, _ := buildFixture(t)
	lockedPath := ""
	signer := &buildSigner{key: key, sign: func(message []byte) ([]byte, error) {
		if lockedPath == "" {
			t.Fatal("no executable was verified")
		}
		if err := os.WriteFile(lockedPath, []byte("replaced"), 0600); err == nil {
			t.Fatal("verified executable was writable during signing")
		}
		if err := os.Rename(lockedPath, filepath.Join(filepath.Dir(lockedPath), "replaced.exe")); err == nil {
			t.Fatal("verified executable was replaceable during signing")
		}
		return ed25519.Sign(key, message), nil
	}}
	_, err := buildTest(t, context.Background(), source, output, spec, signer, key, func(filename, _ string) error {
		lockedPath = filename
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
}

func TestPublicBuildRejectsActualUnsignedDevelopmentExecutables(t *testing.T) {
	source, output, spec, key, _ := buildFixture(t)
	executable, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	content, err := os.ReadFile(executable)
	if err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"obli.exe", "oblivion-agent.exe"} {
		if err = os.WriteFile(filepath.Join(source, "computer", name), content, 0600); err != nil {
			t.Fatal(err)
		}
	}
	signer := &buildSigner{key: key}
	_, err = BuildWindows(context.Background(), source, output, spec, signer, key.Public().(ed25519.PublicKey), time.Now())
	if err == nil || signer.calls != 0 {
		t.Fatal("unsigned PE reached publisher signing", err)
	}
	assertNoBuildOutput(t, output)
}
