package release

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func TestExecutableVerifierRejectsUnsignedDevelopmentBinaries(t *testing.T) {
	executable, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	if err = VerifyExecutable(executable, runtime.GOARCH); err == nil {
		t.Fatal("unsigned test executable was trusted")
	}
	other := "arm64"
	if runtime.GOARCH == "arm64" {
		other = "amd64"
	}
	if err = VerifyExecutable(executable, other); err == nil {
		t.Fatal("wrong executable architecture accepted")
	}
}
func TestExecutableVerifierRejectsNonExecutableContent(t *testing.T) {
	filename := filepath.Join(t.TempDir(), "fake.exe")
	if err := os.WriteFile(filename, []byte("not a Windows executable"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := VerifyExecutable(filename, runtime.GOARCH); err == nil {
		t.Fatal("non-executable package component accepted")
	}
}

func TestArchiveCannotBeReplacedWhileVerifiedPackageIsOpen(t *testing.T) {
	m, c, k := fixture(t)
	filename := archiveFixture(t, manifestBytes(t, m), c, k, nil)
	pack, err := openFixture(t, filename, k)
	if err != nil {
		t.Fatal(err)
	}
	if err = os.WriteFile(filename, []byte("replacement"), 0600); err == nil {
		pack.Close()
		t.Fatal("verified archive was writable")
	}
	renamed := filename + ".moved"
	if err = os.Rename(filename, renamed); err == nil {
		pack.Close()
		t.Fatal("verified archive was replaceable")
	}
	if err = pack.Close(); err != nil {
		t.Fatal(err)
	}
	if err = os.Rename(filename, renamed); err != nil {
		t.Fatal("archive handle remained locked")
	}
}
