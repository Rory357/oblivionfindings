package release

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestReleaseDigestBindsExactPayloadAndRechecksExpiry(t *testing.T) {
	m, c, k := fixture(t)
	data := manifestBytes(t, m)
	pack, err := openFixture(t, archiveFixture(t, data, c, k, nil), k)
	if err != nil {
		t.Fatal(err)
	}
	defer pack.Close()
	expected := sha256.Sum256(data)
	if pack.Digest() != hex.EncodeToString(expected[:]) {
		t.Fatal("digest did not bind exact signed manifest")
	}
	if pack.CheckFresh(time.Now()) != nil {
		t.Fatal("fresh verified package rejected")
	}
	expiry, _ := time.Parse(time.RFC3339, m.ExpiresAt)
	if pack.CheckFresh(expiry) == nil {
		t.Fatal("expired package accepted during activation")
	}
}
func TestExtractedReleaseRejectsChangedIncompleteAndAdditionalContent(t *testing.T) {
	m, c, k := fixture(t)
	pack, err := openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, nil), k)
	if err != nil {
		t.Fatal(err)
	}
	defer pack.Close()
	cases := map[string]func(string) error{
		"same size tamper": func(dir string) error {
			data := append([]byte{}, c["computer/obli.exe"]...)
			data[0] ^= 1
			return os.WriteFile(filepath.Join(dir, "computer", "obli.exe"), data, 0600)
		},
		"truncation": func(dir string) error {
			return os.WriteFile(filepath.Join(dir, "computer", "obli.exe"), []byte("short"), 0600)
		},
		"missing": func(dir string) error { return os.Remove(filepath.Join(dir, "computer", "obli.exe")) },
		"extra file": func(dir string) error {
			return os.WriteFile(filepath.Join(dir, "computer", "unexpected.dll"), []byte("extra"), 0600)
		},
		"extra directory": func(dir string) error { return os.Mkdir(filepath.Join(dir, "unexpected"), 0700) },
		"directory instead of file": func(dir string) error {
			file := filepath.Join(dir, "computer", "obli.exe")
			if err := os.Remove(file); err != nil {
				return err
			}
			return os.Mkdir(file, 0700)
		},
	}
	for name, alter := range cases {
		t.Run(name, func(t *testing.T) {
			dir := t.TempDir()
			if err := pack.ExtractNew(dir); err != nil {
				t.Fatal(err)
			}
			if err := pack.VerifyExtracted(dir); err != nil {
				t.Fatal("original stage rejected", err)
			}
			if err := alter(dir); err != nil {
				t.Fatal(err)
			}
			if err := pack.VerifyExtracted(dir); err == nil {
				t.Fatal("changed release accepted")
			}
		})
	}
	if pack.VerifyExtracted(t.TempDir()) == nil {
		t.Fatal("empty stage accepted")
	}
}

func TestReleaseAcceptsRuntimeCasingButRejectsAmbiguousDirectories(t *testing.T) {
	m, c, k := fixture(t)
	add := func(name string) {
		data := []byte("runtime fixture")
		c[name] = data
		hash := sha256.Sum256(data)
		m.Files = append(m.Files, File{Path: name, Size: int64(len(data)), SHA256: hex.EncodeToString(hash[:])})
	}
	add("computer/PresentationNative_cor3.dll")
	p, err := openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, nil), k)
	if err != nil {
		t.Fatal("normal Windows runtime filename rejected", err)
	}
	p.Close()
	add("computer/Runtime/one.dll")
	add("computer/runtime/two.dll")
	if p, err = openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, nil), k); err == nil {
		p.Close()
		t.Fatal("ambiguous directory casing accepted")
	}
}

// A hub that updated itself runs from a folder holding only site/. Repair checks that folder
// against the site part of a full package, just as strictly.
func TestExtractedPartsVerifyOnlyTheNamedPartExactly(t *testing.T) {
	m, c, k := fixture(t)
	pack, err := openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, nil), k)
	if err != nil {
		t.Fatal(err)
	}
	defer pack.Close()
	siteOnly := func(t *testing.T) string {
		dir := t.TempDir()
		if err := pack.ExtractNew(dir); err != nil {
			t.Fatal(err)
		}
		if err := os.RemoveAll(filepath.Join(dir, "computer")); err != nil {
			t.Fatal(err)
		}
		return dir
	}
	dir := siteOnly(t)
	if err := pack.VerifyExtractedParts(dir, "site"); err != nil {
		t.Fatal("exact site folder rejected", err)
	}
	if pack.VerifyExtracted(dir) == nil {
		t.Fatal("a site-only folder passed the full check")
	}
	if pack.VerifyExtractedParts(dir, "computer") == nil || pack.VerifyExtractedParts(dir) == nil || pack.VerifyExtractedParts(dir, "missing") == nil || pack.VerifyExtractedParts(dir, "site/bin") == nil {
		t.Fatal("wrong or empty part list accepted")
	}
	for name, alter := range map[string]func(string) error{
		"tampered": func(d string) error {
			data := append([]byte{}, c["site/bin/oblivion-collector"]...)
			data[0] ^= 1
			return os.WriteFile(filepath.Join(d, "site", "bin", "oblivion-collector"), data, 0600)
		},
		"missing":       func(d string) error { return os.Remove(filepath.Join(d, "site", "vendor", "autoload.php")) },
		"extra in site": func(d string) error { return os.WriteFile(filepath.Join(d, "site", "extra.dll"), []byte("x"), 0600) },
		"other part present": func(d string) error {
			if err := os.Mkdir(filepath.Join(d, "computer"), 0700); err != nil {
				return err
			}
			return os.WriteFile(filepath.Join(d, "computer", "obli.exe"), c["computer/obli.exe"], 0600)
		},
	} {
		t.Run(name, func(t *testing.T) {
			d := siteOnly(t)
			if err := alter(d); err != nil {
				t.Fatal(err)
			}
			if pack.VerifyExtractedParts(d, "site") == nil {
				t.Fatal("changed site folder accepted")
			}
		})
	}
}
