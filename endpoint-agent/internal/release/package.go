// Package release verifies offline release archives using a publisher key supplied
// by the signed installer, never a key from a package or enrolment profile.
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
	"path"
	"path/filepath"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"
)

const (
	MaxArchiveBytes  int64 = 512 * 1024 * 1024
	MaxExpandedBytes int64 = 1024 * 1024 * 1024
	MaxFileBytes     int64 = 256 * 1024 * 1024
	MaxManifestBytes int64 = 2 * 1024 * 1024
	MaxFiles               = 8192
	SignatureDomain        = "OBLIVION-RELEASE-V1\n"
)

var ErrInvalid = errors.New("release package is invalid or not trusted")
var versionPattern = regexp.MustCompile("^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$")
var digestPattern = regexp.MustCompile("^[a-f0-9]{64}$")
var pathPattern = regexp.MustCompile("^[A-Za-z0-9_./-]+$")
var reservedPathPattern = regexp.MustCompile("^(COM|LPT)[0-9]$")

type File struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
}
type Manifest struct {
	Schema       int      `json:"schema"`
	Product      string   `json:"product"`
	Version      string   `json:"version"`
	Revision     uint64   `json:"revision"`
	Platform     string   `json:"platform"`
	Architecture string   `json:"architecture"`
	Roles        []string `json:"roles"`
	PublishedAt  string   `json:"published_at"`
	ExpiresAt    string   `json:"expires_at"`
	Files        []File   `json:"files"`
}
type envelope struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}
type Package struct {
	manifest Manifest
	digest   string
	file     *os.File
	entries  map[string]*zip.File
}

func (p *Package) Close() error { return p.file.Close() }
func (p *Package) Manifest() Manifest {
	m := p.manifest
	m.Roles = append([]string{}, m.Roles...)
	m.Files = append([]File{}, m.Files...)
	return m
}

func Open(filename string, trustedKey ed25519.PublicKey, architecture string, minimumRevision uint64, now time.Time) (*Package, error) {
	file, err := openArchive(filename)
	if err != nil {
		return nil, ErrInvalid
	}
	keep := false
	defer func() {
		if !keep {
			file.Close()
		}
	}()
	info, err := file.Stat()
	if err != nil || !info.Mode().IsRegular() || info.Size() <= 0 || info.Size() > MaxArchiveBytes {
		return nil, ErrInvalid
	}
	archive, err := zip.NewReader(file, info.Size())
	if err != nil || len(archive.File) < 2 || len(archive.File) > MaxFiles+1 {
		return nil, ErrInvalid
	}
	entries := map[string]*zip.File{}
	casings := map[string]string{}
	for _, entry := range archive.File {
		if !safePath(entry.Name) || !recordPathCasing(casings, entry.Name) || !entry.Mode().IsRegular() || entry.UncompressedSize64 > uint64(MaxFileBytes) || entry.Flags&1 != 0 {
			return nil, ErrInvalid
		}
		if _, exists := entries[entry.Name]; exists {
			return nil, ErrInvalid
		}
		entries[entry.Name] = entry
	}
	manifestFile, ok := entries["release.json"]
	if !ok || manifestFile.UncompressedSize64 > uint64(MaxManifestBytes) {
		return nil, ErrInvalid
	}
	data, err := readEntry(manifestFile, MaxManifestBytes)
	if err != nil {
		return nil, ErrInvalid
	}
	var signed envelope
	if strictJSON(data, &signed) != nil {
		return nil, ErrInvalid
	}
	payload, err := base64.StdEncoding.Strict().DecodeString(signed.Payload)
	if err != nil || len(payload) > int(MaxManifestBytes) {
		return nil, ErrInvalid
	}
	signature, err := base64.StdEncoding.Strict().DecodeString(signed.Signature)
	if err != nil || len(trustedKey) != ed25519.PublicKeySize || len(signature) != ed25519.SignatureSize ||
		!ed25519.Verify(trustedKey, append([]byte(SignatureDomain), payload...), signature) {
		return nil, ErrInvalid
	}
	var manifest Manifest
	if strictJSON(payload, &manifest) != nil || validateManifest(manifest, architecture, minimumRevision, now) != nil {
		return nil, ErrInvalid
	}
	if len(entries) != len(manifest.Files)+1 {
		return nil, ErrInvalid
	}
	for _, record := range manifest.Files {
		entry, exists := entries[record.Path]
		if !exists || entry.UncompressedSize64 != uint64(record.Size) {
			return nil, ErrInvalid
		}
		if err = verifyEntry(entry, record, io.Discard); err != nil {
			return nil, ErrInvalid
		}
	}
	keep = true
	digest := sha256.Sum256(payload)
	return &Package{manifest: manifest, digest: hex.EncodeToString(digest[:]), file: file, entries: entries}, nil
}

// ExtractNew writes only inside an empty, already protected staging directory.
// It never replaces a file. Every entry is hashed again while being written.
// On failure the caller owns the incomplete stage; nothing is activated.
func (p *Package) ExtractNew(directory string) error {
	root, err := os.OpenRoot(directory)
	if err != nil {
		return ErrInvalid
	}
	defer root.Close()
	entries, err := os.ReadDir(directory)
	if err != nil || len(entries) != 0 {
		return errors.New("release stage must be empty")
	}
	for _, record := range p.manifest.Files {
		parent := path.Dir(record.Path)
		if parent != "." {
			if err = root.MkdirAll(filepath.FromSlash(parent), 0700); err != nil {
				return ErrInvalid
			}
		}
		output, err := root.OpenFile(filepath.FromSlash(record.Path), os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
		if err != nil {
			return ErrInvalid
		}
		verifyErr := verifyEntry(p.entries[record.Path], record, output)
		syncErr := output.Sync()
		closeErr := output.Close()
		if verifyErr != nil || syncErr != nil || closeErr != nil {
			return ErrInvalid
		}
	}
	return nil
}
func (p *Package) Supports(role string) bool {
	if role == "both" {
		return p.Supports("computer") && p.Supports("site")
	}
	for _, supported := range p.manifest.Roles {
		if role == supported {
			return true
		}
	}
	return false
}
func validateManifest(m Manifest, architecture string, minimumRevision uint64, now time.Time) error {
	published, err1 := time.Parse(time.RFC3339, m.PublishedAt)
	expires, err2 := time.Parse(time.RFC3339, m.ExpiresAt)
	if m.Schema != 1 || m.Product != "oblivion-windows" || m.Platform != "windows" ||
		(architecture != "amd64" && architecture != "arm64") || m.Architecture != architecture ||
		!versionPattern.MatchString(m.Version) || len(m.Version) > 32 || m.Revision == 0 || m.Revision > 9007199254740991 || m.Revision < minimumRevision ||
		err1 != nil || err2 != nil || published.After(now.Add(5*time.Minute)) || !expires.After(now) || !expires.After(published) || expires.Sub(published) > 90*24*time.Hour ||
		len(m.Roles) < 1 || len(m.Roles) > 2 || len(m.Files) < 1 || len(m.Files) > MaxFiles {
		return ErrInvalid
	}
	roles := map[string]bool{}
	for _, role := range m.Roles {
		if (role != "computer" && role != "site") || roles[role] {
			return ErrInvalid
		}
		roles[role] = true
	}
	files := map[string]bool{}
	casings := map[string]string{}
	sizes := map[string]int64{}
	var total int64
	for _, file := range m.Files {
		if !safePath(file.Path) || !recordPathCasing(casings, file.Path) || file.Path == "release.json" || file.Size < 0 || file.Size > MaxFileBytes || !digestPattern.MatchString(file.SHA256) || files[file.Path] {
			return ErrInvalid
		}
		component := strings.Split(file.Path, "/")[0]
		if !roles[component] || !strings.Contains(file.Path, "/") {
			return ErrInvalid
		}
		files[file.Path] = true
		sizes[file.Path] = file.Size
		total += file.Size
		if total > MaxExpandedBytes {
			return ErrInvalid
		}
	}
	required := map[string][]string{
		"computer": {"computer/oblivion-agent.exe", "computer/obli.exe"},
		"site":     SiteRuntimeFiles(),
	}
	for role := range roles {
		for _, name := range required[role] {
			if !files[name] || sizes[name] == 0 {
				return ErrInvalid
			}
		}
	}
	// A file cannot also be the parent of another file.
	for name := range files {
		for parent := path.Dir(name); parent != "."; parent = path.Dir(parent) {
			if files[parent] {
				return ErrInvalid
			}
		}
	}
	return nil
}

// Keep exact publisher casing for Windows runtime DLLs, but reject conflicting
// spellings of any file or directory on a case-insensitive filesystem.
func recordPathCasing(casings map[string]string, name string) bool {
	for part := name; part != "."; part = path.Dir(part) {
		folded := strings.ToLower(part)
		if previous, exists := casings[folded]; exists && previous != part {
			return false
		}
		casings[folded] = part
	}
	return true
}
func safePath(name string) bool {
	if len(name) == 0 || len(name) > 220 || !pathPattern.MatchString(name) || path.Clean(name) != name || strings.HasPrefix(name, "/") {
		return false
	}
	for _, part := range strings.Split(name, "/") {
		if part == "" || part == "." || part == ".." || strings.HasSuffix(part, ".") {
			return false
		}
		stem := strings.ToUpper(strings.SplitN(part, ".", 2)[0])
		if stem == "CON" || stem == "PRN" || stem == "AUX" || stem == "NUL" || stem == "CLOCK$" || reservedPathPattern.MatchString(stem) {
			return false
		}
	}
	return true
}
func readEntry(file *zip.File, maximum int64) ([]byte, error) {
	reader, err := file.Open()
	if err != nil {
		return nil, err
	}
	defer reader.Close()
	data, err := io.ReadAll(io.LimitReader(reader, maximum+1))
	if err != nil || int64(len(data)) > maximum {
		return nil, ErrInvalid
	}
	return data, nil
}
func verifyEntry(file *zip.File, record File, output io.Writer) error {
	reader, err := file.Open()
	if err != nil {
		return ErrInvalid
	}
	defer reader.Close()
	hash := sha256.New()
	count, err := io.Copy(io.MultiWriter(output, hash), io.LimitReader(reader, record.Size+1))
	if err != nil || count != record.Size || hex.EncodeToString(hash.Sum(nil)) != record.SHA256 {
		return ErrInvalid
	}
	return nil
}

// Duplicate names (at every object depth) are rejected before decoding a signed
// payload so other tooling cannot interpret the same bytes differently.
func strictJSON(data []byte, destination any) error {
	if !utf8.Valid(data) {
		return ErrInvalid
	}
	scanner := json.NewDecoder(bytes.NewReader(data))
	scanner.UseNumber()
	var value func(int) error
	value = func(depth int) error {
		if depth > 16 {
			return ErrInvalid
		}
		token, err := scanner.Token()
		if err != nil {
			return err
		}
		switch token {
		case json.Delim('{'):
			names := map[string]bool{}
			for scanner.More() {
				key, err := scanner.Token()
				name, ok := key.(string)
				if err != nil || !ok || names[name] {
					return ErrInvalid
				}
				names[name] = true
				if err = value(depth + 1); err != nil {
					return err
				}
			}
			token, err = scanner.Token()
			if err != nil || token != json.Delim('}') {
				return ErrInvalid
			}
		case json.Delim('['):
			for scanner.More() {
				if err = value(depth + 1); err != nil {
					return err
				}
			}
			token, err = scanner.Token()
			if err != nil || token != json.Delim(']') {
				return ErrInvalid
			}
		}
		return nil
	}
	if err := value(0); err != nil {
		return err
	}
	if _, err := scanner.Token(); err != io.EOF {
		return ErrInvalid
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		return ErrInvalid
	}
	return nil
}

// ProtectedTarget is implemented by the Windows installation directory writer,
// which creates each file with final ownership and ACLs rather than fixing them
// after extraction. No entry outside the signed file list is passed to it.
type ProtectedTarget interface {
	WriteFile(string, func(io.Writer) error) error
}

func (p *Package) ExtractTo(target ProtectedTarget) error {
	for _, record := range p.manifest.Files {
		if err := target.WriteFile(record.Path, func(writer io.Writer) error {
			return verifyEntry(p.entries[record.Path], record, writer)
		}); err != nil {
			return err
		}
	}
	return nil
}

// SiteRuntimeFiles is the minimum payload for the fixed Windows PHP host.
// All transitive DLLs, source, vendor and licence files must also be inventoried.
func SiteRuntimeFiles() []string {
	return []string{"site/oblivion-collector.exe", "site/oblivion-media.exe", "site/runtime/php.exe", "site/bin/oblivion-collector", "site/vendor/autoload.php",
		"site/runtime/cacert.pem", "site/runtime/ext/php_curl.dll", "site/runtime/ext/php_openssl.dll", "site/runtime/ext/php_sodium.dll", "site/runtime/ext/php_sockets.dll"}
}
