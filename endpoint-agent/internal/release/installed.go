package release

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"time"
)

// Digest identifies the exact publisher-signed manifest, not a re-encoded copy.
func (p *Package) Digest() string { return p.digest }
func (p *Package) CheckFresh(now time.Time) error {
	return validateManifest(p.manifest, p.manifest.Architecture, 0, now)
}

// VerifyExtracted verifies the complete staged tree before activation or retry.
// Windows callers additionally hold protected ancestors and check file ACLs.
func (p *Package) VerifyExtracted(directory string) error {
	info, err := os.Lstat(directory)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return ErrInvalid
	}
	root, err := os.OpenRoot(directory)
	if err != nil {
		return ErrInvalid
	}
	defer root.Close()
	records := make(map[string]File, len(p.manifest.Files))
	directories := map[string]bool{".": true}
	for _, record := range p.manifest.Files {
		records[record.Path] = record
		for parent := path.Dir(record.Path); parent != "."; parent = path.Dir(parent) {
			directories[parent] = true
		}
	}
	seen := 0
	err = fs.WalkDir(root.FS(), ".", func(name string, entry fs.DirEntry, walkErr error) error {
		if walkErr != nil || entry == nil || entry.Type()&os.ModeSymlink != 0 {
			return ErrInvalid
		}
		if entry.IsDir() {
			if !directories[name] {
				return ErrInvalid
			}
			return nil
		}
		record, ok := records[name]
		if !ok || !entry.Type().IsRegular() {
			return ErrInvalid
		}
		file, err := root.Open(filepath.FromSlash(name))
		if err != nil {
			return ErrInvalid
		}
		info, statErr := file.Stat()
		if statErr != nil || !info.Mode().IsRegular() || info.Size() != record.Size {
			file.Close()
			return ErrInvalid
		}
		hash := sha256.New()
		count, copyErr := io.Copy(hash, io.LimitReader(file, record.Size+1))
		closeErr := file.Close()
		if copyErr != nil || closeErr != nil || count != record.Size || hex.EncodeToString(hash.Sum(nil)) != record.SHA256 {
			return ErrInvalid
		}
		seen++
		return nil
	})
	if err != nil || seen != len(records) {
		return ErrInvalid
	}
	return nil
}
