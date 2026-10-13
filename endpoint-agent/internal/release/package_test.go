package release

import (
	"archive/zip"
	"bytes"
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
)

func fixture(t *testing.T) (Manifest, map[string][]byte, ed25519.PrivateKey) {
	t.Helper()
	key := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{9}, 32))
	content := map[string][]byte{
		"computer/oblivion-agent.exe": []byte("fixture agent, not executable"),
		"computer/obli.exe":           []byte("fixture tray, not executable"),
		"site/oblivion-collector.exe": []byte("fixture collector host, not executable"),
		"site/runtime/php.exe":        []byte("fixture runtime, not executable"),
		"site/bin/oblivion-collector": []byte("fixture collector"),
		"site/vendor/autoload.php":    []byte("fixture autoloader"),
	}
	for _, name := range SiteRuntimeFiles() {
		if _, exists := content[name]; !exists {
			content[name] = []byte("fixture runtime dependency")
		}
	}
	manifest := Manifest{Schema: 1, Product: "oblivion-windows", Version: "1.2.3", Revision: 7, Platform: "windows", Architecture: "amd64", Roles: []string{"computer", "site"}, PublishedAt: time.Now().Add(-time.Hour).UTC().Format(time.RFC3339), ExpiresAt: time.Now().Add(24 * time.Hour).UTC().Format(time.RFC3339)}
	for name, data := range content {
		hash := sha256.Sum256(data)
		manifest.Files = append(manifest.Files, File{Path: name, Size: int64(len(data)), SHA256: hex.EncodeToString(hash[:])})
	}
	return manifest, content, key
}
func archiveFixture(t *testing.T, payload []byte, content map[string][]byte, key ed25519.PrivateKey, extra func(*zip.Writer)) string {
	t.Helper()
	signed := envelope{Payload: base64.StdEncoding.EncodeToString(payload), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, append([]byte(SignatureDomain), payload...)))}
	encoded, _ := json.Marshal(signed)
	filename := filepath.Join(t.TempDir(), "release.zip")
	f, err := os.Create(filename)
	if err != nil {
		t.Fatal(err)
	}
	archive := zip.NewWriter(f)
	add := func(name string, data []byte) {
		w, err := archive.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err = w.Write(data); err != nil {
			t.Fatal(err)
		}
	}
	add("release.json", encoded)
	for name, data := range content {
		add(name, data)
	}
	if extra != nil {
		extra(archive)
	}
	if err = archive.Close(); err != nil {
		t.Fatal(err)
	}
	if err = f.Close(); err != nil {
		t.Fatal(err)
	}
	return filename
}
func manifestBytes(t *testing.T, m Manifest) []byte {
	t.Helper()
	data, err := json.Marshal(m)
	if err != nil {
		t.Fatal(err)
	}
	return data
}
func openFixture(t *testing.T, name string, key ed25519.PrivateKey) (*Package, error) {
	t.Helper()
	return Open(name, key.Public().(ed25519.PublicKey), "amd64", 7, time.Now())
}
func TestReleaseVerifiesAllRolesAndExtractsOnlyRecordedBytes(t *testing.T) {
	manifest, content, key := fixture(t)
	name := archiveFixture(t, manifestBytes(t, manifest), content, key, nil)
	pack, err := openFixture(t, name, key)
	if err != nil {
		t.Fatal(err)
	}
	defer pack.Close()
	if !pack.Supports("both") || pack.Supports("unknown") {
		t.Fatal("wrong installation role coverage")
	}
	copy := pack.Manifest()
	copy.Files[0].SHA256 = "modified"
	copy.Roles[0] = "other"
	if pack.Manifest().Files[0].SHA256 == "modified" || !pack.Supports("both") {
		t.Fatal("caller changed verified manifest")
	}
	stage := t.TempDir()
	if err = pack.ExtractNew(stage); err != nil {
		t.Fatal(err)
	}
	for name, expected := range content {
		data, err := os.ReadFile(filepath.Join(stage, filepath.FromSlash(name)))
		if err != nil || !bytes.Equal(data, expected) {
			t.Fatal("extracted content did not match manifest")
		}
	}
	if err = pack.ExtractNew(stage); err == nil {
		t.Fatal("extraction overwrote an existing stage")
	}
}
func TestReleaseRejectsAlteredMissingExtraAndUnsafeArchiveEntries(t *testing.T) {
	cases := map[string]func(map[string][]byte){
		"tampered":       func(c map[string][]byte) { c["computer/obli.exe"] = []byte("tampered") },
		"missing":        func(c map[string][]byte) { delete(c, "computer/obli.exe") },
		"extra":          func(c map[string][]byte) { c["computer/extra.exe"] = []byte("extra") },
		"traversal":      func(c map[string][]byte) { c["../escape"] = []byte("escape") },
		"absolute":       func(c map[string][]byte) { c["/outside"] = []byte("escape") },
		"stream":         func(c map[string][]byte) { c["computer/file:stream"] = []byte("escape") },
		"backslash":      func(c map[string][]byte) { c["computer\\escape"] = []byte("escape") },
		"device name":    func(c map[string][]byte) { c["computer/con.txt"] = []byte("escape") },
		"case collision": func(c map[string][]byte) { c["computer/OBLI.exe"] = []byte("ambiguous") },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			m, c, k := fixture(t)
			mutate(c)
			p, err := openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, nil), k)
			if err == nil {
				p.Close()
				t.Fatal("untrusted archive accepted")
			}
		})
	}
	for name, extra := range map[string]func(*zip.Writer){
		"duplicate": func(z *zip.Writer) { w, _ := z.Create("computer/obli.exe"); w.Write([]byte("duplicate")) },
		"symlink": func(z *zip.Writer) {
			h := &zip.FileHeader{Name: "computer/link"}
			h.SetMode(os.ModeSymlink | 0777)
			w, _ := z.CreateHeader(h)
			w.Write([]byte("../../escape"))
		},
		"directory": func(z *zip.Writer) { z.Create("computer/") },
	} {
		t.Run(name, func(t *testing.T) {
			m, c, k := fixture(t)
			p, err := openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, extra), k)
			if err == nil {
				p.Close()
				t.Fatal("unsafe entry accepted")
			}
		})
	}
}
func TestReleaseRejectsWrongKeyVersionArchitectureAndRollback(t *testing.T) {
	cases := map[string]func(*Manifest){
		"product":         func(m *Manifest) { m.Product = "other" },
		"schema":          func(m *Manifest) { m.Schema = 2 },
		"architecture":    func(m *Manifest) { m.Architecture = "arm64" },
		"platform":        func(m *Manifest) { m.Platform = "linux" },
		"rollback":        func(m *Manifest) { m.Revision = 6 },
		"version command": func(m *Manifest) { m.Version = "1.2.3; command" },
		"expired":         func(m *Manifest) { m.ExpiresAt = time.Now().Add(-time.Minute).Format(time.RFC3339) },
		"future":          func(m *Manifest) { m.PublishedAt = time.Now().Add(time.Hour).Format(time.RFC3339) },
		"overlong window": func(m *Manifest) { m.ExpiresAt = time.Now().Add(100 * 24 * time.Hour).Format(time.RFC3339) },
		"unknown role":    func(m *Manifest) { m.Roles = []string{"other"} },
		"duplicate role":  func(m *Manifest) { m.Roles = []string{"computer", "computer"} },
		"missing helper": func(m *Manifest) {
			for i, f := range m.Files {
				if f.Path == "computer/obli.exe" {
					m.Files = append(m.Files[:i], m.Files[i+1:]...)
					break
				}
			}
		},
		"oversize":         func(m *Manifest) { m.Files[0].Size = MaxFileBytes + 1 },
		"duplicate record": func(m *Manifest) { m.Files = append(m.Files, m.Files[0]) },
		"unsafe record":    func(m *Manifest) { m.Files[0].Path = "../outside" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			m, c, k := fixture(t)
			mutate(&m)
			p, err := openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, nil), k)
			if err == nil {
				p.Close()
				t.Fatal("invalid release accepted")
			}
		})
	}
	m, c, k := fixture(t)
	name := archiveFixture(t, manifestBytes(t, m), c, k, nil)
	wrong := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{3}, 32))
	if p, err := openFixture(t, name, wrong); err == nil {
		p.Close()
		t.Fatal("wrong publisher key accepted")
	}
	if p, err := Open(name, nil, "amd64", 0, time.Now()); err == nil {
		p.Close()
		t.Fatal("missing publisher authority accepted")
	}
}
func TestReleaseRejectsAmbiguousSignedJSON(t *testing.T) {
	m, c, k := fixture(t)
	data := manifestBytes(t, m)
	for _, payload := range [][]byte{
		[]byte(strings.Replace(string(data), "{", "{\"revision\":7,", 1)),
		[]byte(strings.Replace(string(data), "{", "{\"unknown\":true,", 1)),
		[]byte(strings.Replace(string(data), "\"sha256\":", "\"path\":\"computer/other.exe\",\"sha256\":", 1)),
		append(append([]byte{}, data...), []byte("{}")...),
	} {
		p, err := openFixture(t, archiveFixture(t, payload, c, k, nil), k)
		if err == nil {
			p.Close()
			t.Fatal("ambiguous signed manifest accepted")
		}
	}
}

type recordingTarget struct {
	content   map[string][]byte
	calls     int
	failAfter int
}

func (r *recordingTarget) WriteFile(name string, write func(io.Writer) error) error {
	r.calls++
	if r.failAfter > 0 && r.calls > r.failAfter {
		return errors.New("target storage unavailable")
	}
	var buffer bytes.Buffer
	if err := write(&buffer); err != nil {
		return err
	}
	r.content[name] = buffer.Bytes()
	return nil
}
func TestProtectedExtractionStopsOnStorageFailure(t *testing.T) {
	m, c, k := fixture(t)
	pack, err := openFixture(t, archiveFixture(t, manifestBytes(t, m), c, k, nil), k)
	if err != nil {
		t.Fatal(err)
	}
	defer pack.Close()
	target := &recordingTarget{content: map[string][]byte{}, failAfter: 1}
	if err = pack.ExtractTo(target); err == nil || target.calls != 2 || len(target.content) != 1 {
		t.Fatal("storage failure did not stop extraction")
	}
	complete := &recordingTarget{content: map[string][]byte{}}
	if err = pack.ExtractTo(complete); err != nil {
		t.Fatal(err)
	}
	if len(complete.content) != len(c) {
		t.Fatal("incorrect component count")
	}
	for name, data := range c {
		if !bytes.Equal(data, complete.content[name]) {
			t.Fatal("protected writer received different bytes")
		}
	}
}
