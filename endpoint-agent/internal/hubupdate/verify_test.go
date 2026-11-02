package hubupdate

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"

	"oblivion.local/endpoint-agent/internal/release"
)

type tarEntry struct {
	name string
	kind byte
	mode int64
	body string
	link string
}

func tarFixture(t *testing.T, entries []tarEntry) string {
	t.Helper()
	var buffer bytes.Buffer
	zipped := gzip.NewWriter(&buffer)
	writer := tar.NewWriter(zipped)
	for _, e := range entries {
		header := &tar.Header{Name: e.name, Typeflag: e.kind, Mode: e.mode, Size: int64(len(e.body)), Linkname: e.link, Format: tar.FormatGNU}
		if e.kind != tar.TypeReg {
			header.Size = 0
		}
		if err := writer.WriteHeader(header); err != nil {
			t.Fatal(err)
		}
		if e.kind == tar.TypeReg {
			if _, err := writer.Write([]byte(e.body)); err != nil {
				t.Fatal(err)
			}
		}
	}
	if writer.Close() != nil || zipped.Close() != nil {
		t.Fatal("tar fixture")
	}
	name := filepath.Join(t.TempDir(), "oblivion-site-hub-linux-arm64.tar.gz")
	if err := os.WriteFile(name, buffer.Bytes(), 0600); err != nil {
		t.Fatal(err)
	}
	return name
}

func goodPackage() []tarEntry {
	top := "oblivion-site-hub-linux-arm64/"
	return []tarEntry{
		{name: top, kind: tar.TypeDir, mode: 0755},
		{name: top + "bin/", kind: tar.TypeDir, mode: 0777},
		{name: top + "bin/oblivion-collector", kind: tar.TypeReg, mode: 0775, body: "#!/usr/bin/env php"},
		{name: top + "vendor/autoload.php", kind: tar.TypeReg, mode: 0666, body: "<?php"},
		{name: top + "oblivion-site-collector", kind: tar.TypeReg, mode: 0755, body: "ELF"},
		{name: top + "VERSION", kind: tar.TypeReg, mode: 0644, body: "1.3.1"},
	}
}

func TestLinuxPackageUnpacksIntoAnEmptyReleaseFolder(t *testing.T) {
	stage := t.TempDir()
	if err := ExtractLinuxPackage(tarFixture(t, goodPackage()), stage, "arm64"); err != nil {
		t.Fatal(err)
	}
	if err := CheckLinuxTree(stage, "1.3.1"); err != nil {
		t.Fatal(err)
	}
	if CheckLinuxTree(stage, "1.3.2") == nil {
		t.Fatal("accepted a release that says it's another version")
	}
	if runtime.GOOS == "linux" {
		for name, want := range map[string]os.FileMode{"bin": 0755, "bin/oblivion-collector": 0755, "vendor/autoload.php": 0644} {
			info, err := os.Stat(filepath.Join(stage, filepath.FromSlash(name)))
			if err != nil || info.Mode().Perm() != want {
				t.Errorf("%s: %v %v", name, info.Mode(), err)
			}
		}
	}
	// Never into a folder that already has something in it.
	if ExtractLinuxPackage(tarFixture(t, goodPackage()), stage, "arm64") == nil {
		t.Fatal("unpacked over an existing release")
	}
}

func TestLinuxPackageRefusesLinksEscapesAndOtherPackages(t *testing.T) {
	top := "oblivion-site-hub-linux-arm64/"
	cases := map[string][]tarEntry{
		"symlink":       {{name: top + "bin/php", kind: tar.TypeSymlink, link: "/usr/bin/php"}},
		"hard link":     {{name: top + "VERSION2", kind: tar.TypeLink, link: top + "VERSION"}},
		"device":        {{name: top + "null", kind: tar.TypeChar}},
		"parent escape": {{name: top + "../etc/cron.d/x", kind: tar.TypeReg, mode: 0644, body: "x"}},
		"absolute":      {{name: "/etc/passwd", kind: tar.TypeReg, mode: 0644, body: "x"}},
		"other top":     {{name: "oblivion-site-hub-linux-amd64/VERSION", kind: tar.TypeReg, mode: 0644, body: "x"}},
		"no top":        {{name: "VERSION", kind: tar.TypeReg, mode: 0644, body: "x"}},
		"backslash":     {{name: top + "bin\\..\\..\\x", kind: tar.TypeReg, mode: 0644, body: "x"}},
	}
	for name, entries := range cases {
		stage := t.TempDir()
		if err := ExtractLinuxPackage(tarFixture(t, append(goodPackage(), entries...)), stage, "arm64"); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	notGzip := filepath.Join(t.TempDir(), "plain.tar.gz")
	_ = os.WriteFile(notGzip, []byte("not a package"), 0600)
	if ExtractLinuxPackage(notGzip, t.TempDir(), "arm64") == nil {
		t.Fatal("accepted a file that isn't a package")
	}
}

// signedReleaseList is the publisher's release list (OBLIVION-DOWNLOADS-V1) naming one
// Linux package.
func signedReleaseList(t *testing.T, key ed25519.PrivateKey, file string, change func(*release.DownloadArtifact)) (string, Artifact) {
	t.Helper()
	data, err := os.ReadFile(file)
	if err != nil {
		t.Fatal(err)
	}
	digest := sha256.Sum256(data)
	named := release.DownloadArtifact{ID: "linux-site-hub-arm64", Platform: "linux", Architecture: "arm64", Roles: []string{"site"}, Version: "1.3.1",
		ReleaseRevision: 41, Filename: "oblivion-site-hub-linux-arm64.tar.gz", Size: int64(len(data)), SHA256: hex.EncodeToString(digest[:])}
	artifact := Artifact{ID: named.ID, Architecture: named.Architecture, Filename: named.Filename, Size: named.Size, SHA256: named.SHA256}
	if change != nil {
		change(&named)
	}
	now := time.Now().UTC()
	payload, err := json.Marshal(release.DownloadCatalogue{Schema: 1, Product: "oblivion-downloads", Revision: 41,
		IssuedAt: now.Add(-time.Hour).Format("2006-01-02T15:04:05Z"), ExpiresAt: now.Add(80 * 24 * time.Hour).Format("2006-01-02T15:04:05Z"),
		Artifacts: []release.DownloadArtifact{named}})
	if err != nil {
		t.Fatal(err)
	}
	signature := ed25519.Sign(key, append([]byte(release.CatalogueDomain), payload...))
	envelope, err := json.Marshal(map[string]string{"payload": base64.StdEncoding.EncodeToString(payload), "signature": base64.StdEncoding.EncodeToString(signature)})
	if err != nil {
		t.Fatal(err)
	}
	return base64.StdEncoding.EncodeToString(envelope), artifact
}

func withReleaseListKey(t *testing.T, key ed25519.PrivateKey) {
	t.Helper()
	old := ReleaseListPublicKey
	ReleaseListPublicKey = base64.StdEncoding.EncodeToString(key.Public().(ed25519.PublicKey))
	t.Cleanup(func() { ReleaseListPublicKey = old })
}

func TestLinuxReleaseIsCheckedAgainstThePublishersReleaseList(t *testing.T) {
	key := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{7}, 32))
	withReleaseListKey(t, key)
	file := tarFixture(t, goodPackage())
	list, artifact := signedReleaseList(t, key, file, nil)
	target := Target{Version: "1.3.1", ReleaseRevision: 41, Artifacts: []Artifact{artifact}, ReleaseList: list}
	if err := CheckLinuxRelease(file, target, artifact, "arm64", time.Now()); err != nil {
		t.Fatal(err)
	}

	// The bytes changed after the list was signed.
	tampered := filepath.Join(t.TempDir(), "tampered.tar.gz")
	data, _ := os.ReadFile(file)
	data[len(data)-1] ^= 1
	_ = os.WriteFile(tampered, data, 0600)
	if CheckLinuxRelease(tampered, target, artifact, "arm64", time.Now()) == nil {
		t.Fatal("accepted changed bytes")
	}
	// Signed by someone else.
	stranger, _ := signedReleaseList(t, ed25519.NewKeyFromSeed(bytes.Repeat([]byte{8}, 32)), file, nil)
	if CheckLinuxRelease(file, Target{Version: "1.3.1", ReleaseRevision: 41, ReleaseList: stranger}, artifact, "arm64", time.Now()) == nil {
		t.Fatal("accepted a release list signed with another key")
	}
	// The server says one thing, the signed list another.
	for name, change := range map[string]func(*release.DownloadArtifact){
		"digest":   func(a *release.DownloadArtifact) { a.SHA256 = hex.EncodeToString(make([]byte, 32)) },
		"version":  func(a *release.DownloadArtifact) { a.Version = "1.3.0" },
		"revision": func(a *release.DownloadArtifact) { a.ReleaseRevision = 40 },
		"arch":     func(a *release.DownloadArtifact) { a.Architecture = "amd64"; a.ID = "linux-site-hub-amd64" },
	} {
		other, _ := signedReleaseList(t, key, file, change)
		if CheckLinuxRelease(file, Target{Version: "1.3.1", ReleaseRevision: 41, ReleaseList: other}, artifact, "arm64", time.Now()) == nil {
			t.Errorf("%s: accepted a mismatch with the signed list", name)
		}
	}
	// An expired list.
	if CheckLinuxRelease(file, target, artifact, "arm64", time.Now().Add(100*24*time.Hour)) == nil {
		t.Fatal("accepted an expired release list")
	}
	// No list at all.
	if CheckLinuxRelease(file, Target{Version: "1.3.1", ReleaseRevision: 41}, artifact, "arm64", time.Now()) == nil {
		t.Fatal("accepted a release without the signed list")
	}
}

func TestNoReleaseKeyMeansNothingInstalls(t *testing.T) {
	oldList, oldPackage := ReleaseListPublicKey, PackagePublicKey
	ReleaseListPublicKey, PackagePublicKey = "", ""
	t.Cleanup(func() { ReleaseListPublicKey, PackagePublicKey = oldList, oldPackage })
	file := tarFixture(t, goodPackage())
	if err := CheckLinuxRelease(file, Target{}, Artifact{}, "arm64", time.Now()); !errors.Is(err, ErrNoReleaseKey) {
		t.Fatal(err)
	}
	if _, err := OpenWindowsPackage(file, Target{}, "amd64", 0, time.Now()); !errors.Is(err, ErrNoReleaseKey) {
		t.Fatal(err)
	}
}
