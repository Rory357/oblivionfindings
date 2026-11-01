package hubupdate

import (
	"archive/tar"
	"compress/gzip"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"io"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"

	"oblivion.local/endpoint-agent/internal/release"
)

// The publisher's public keys, stamped into the build by CI:
//
//	-X oblivion.local/endpoint-agent/internal/hubupdate.PackagePublicKey=<base64>
//	-X oblivion.local/endpoint-agent/internal/hubupdate.ReleaseListPublicKey=<base64>
//
// PackagePublicKey checks Windows release packages (OBLIVION-RELEASE-V1). Linux packages
// aren't signed themselves; the signed release list (OBLIVION-DOWNLOADS-V1) pins their exact
// bytes, so Linux hubs check that with ReleaseListPublicKey. Without a key, nothing installs.
var PackagePublicKey = ""
var ReleaseListPublicKey = ""

var errNotTarget = errors.New("the release isn't the one this hub was told to install")

func publicKey(encoded string) (ed25519.PublicKey, error) {
	key, err := base64.StdEncoding.Strict().DecodeString(encoded)
	if err != nil || len(key) != ed25519.PublicKeySize {
		return nil, ErrNoReleaseKey
	}
	return ed25519.PublicKey(key), nil
}

// OpenWindowsPackage checks a downloaded Windows release package with the existing release
// rules (publisher signature, manifest, expiry, exact bytes of every file) and that it is
// the target, with the site role. minimumRevision refuses anything older than what's running.
// The caller extracts it, checks the extracted tree, and checks each program with
// release.VerifyExecutable.
func OpenWindowsPackage(download string, target Target, arch string, minimumRevision uint64, now time.Time) (*release.Package, error) {
	key, err := publicKey(PackagePublicKey)
	if err != nil {
		return nil, err
	}
	pack, err := release.Open(download, key, arch, minimumRevision, now)
	if err != nil {
		return nil, err
	}
	m := pack.Manifest()
	if m.Version != target.Version || m.Revision != target.ReleaseRevision || !pack.Supports("site") {
		pack.Close()
		return nil, errNotTarget
	}
	return pack, nil
}

// CheckLinuxRelease checks a downloaded Linux package against the publisher-signed release
// list: the list's signature and expiry, that it names exactly this file for this machine,
// and that the downloaded bytes match its size and SHA-256.
func CheckLinuxRelease(download string, target Target, artifact Artifact, arch string, now time.Time) error {
	key, err := publicKey(ReleaseListPublicKey)
	if err != nil {
		return err
	}
	list, err := base64.StdEncoding.Strict().DecodeString(target.ReleaseList)
	if err != nil || len(list) == 0 {
		return release.ErrInvalid
	}
	catalogue, err := release.VerifyCatalogue(list, key, 1, now)
	if err != nil {
		return err
	}
	var named *release.DownloadArtifact
	for i := range catalogue.Artifacts {
		if catalogue.Artifacts[i].ID == artifact.ID {
			named = &catalogue.Artifacts[i]
		}
	}
	if named == nil || named.Platform != "linux" || named.Architecture != arch || artifact.Architecture != arch ||
		len(named.Roles) != 1 || named.Roles[0] != "site" ||
		named.Version != target.Version || named.ReleaseRevision != target.ReleaseRevision ||
		named.Filename != artifact.Filename || named.Size != artifact.Size || named.SHA256 != artifact.SHA256 {
		return errNotTarget
	}
	return checkBytes(download, named.Size, named.SHA256)
}

func checkBytes(filename string, size int64, digest string) error {
	file, err := os.Open(filename)
	if err != nil {
		return release.ErrInvalid
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil || !info.Mode().IsRegular() || info.Size() != size {
		return release.ErrInvalid
	}
	hash := sha256.New()
	count, err := io.Copy(hash, io.LimitReader(file, size+1))
	if err != nil || count != size || hex.EncodeToString(hash.Sum(nil)) != digest {
		return release.ErrInvalid
	}
	return nil
}

// Limits for unpacking a Linux package. The launcher refuses to start from a folder with
// more than 16384 entries, so the package has to fit inside that.
const (
	maxTarEntries    = 16000
	maxTarFileBytes  = 256 * 1024 * 1024
	maxTarTotalBytes = 1024 * 1024 * 1024
)

// ExtractLinuxPackage unpacks a Linux hub package (oblivion-site-hub-linux-<arch>.tar.gz) into
// an empty release folder. Everything sits under one top folder, which is dropped. Only
// plain folders and files are accepted: links, devices, absolute paths and ".." are refused.
// Folders get 0755 and files keep their executable bit at most (0755 or 0644), so nothing
// is group or world writable.
func ExtractLinuxPackage(archive, directory, arch string) error {
	entries, err := os.ReadDir(directory)
	if err != nil || len(entries) != 0 {
		return errors.New("the release folder must be empty")
	}
	root, err := os.OpenRoot(directory)
	if err != nil {
		return release.ErrInvalid
	}
	defer root.Close()
	file, err := os.Open(archive)
	if err != nil {
		return release.ErrInvalid
	}
	defer file.Close()
	unzipped, err := gzip.NewReader(file)
	if err != nil {
		return release.ErrInvalid
	}
	defer unzipped.Close()
	reader := tar.NewReader(unzipped)
	top := "oblivion-site-hub-linux-" + arch
	count := 0
	var total int64
	for {
		header, err := reader.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return release.ErrInvalid
		}
		count++
		if count > maxTarEntries {
			return release.ErrInvalid
		}
		name, ok := packagePath(header.Name, top)
		if !ok {
			return release.ErrInvalid
		}
		switch header.Typeflag {
		case tar.TypeDir:
			if name == "" {
				continue
			}
			if root.MkdirAll(filepath.FromSlash(name), 0755) != nil || root.Chmod(filepath.FromSlash(name), 0755) != nil {
				return release.ErrInvalid
			}
		case tar.TypeReg:
			total += header.Size
			if name == "" || header.Size < 0 || header.Size > maxTarFileBytes || total > maxTarTotalBytes {
				return release.ErrInvalid
			}
			if parent := path.Dir(name); parent != "." && root.MkdirAll(filepath.FromSlash(parent), 0755) != nil {
				return release.ErrInvalid
			}
			mode := os.FileMode(0644)
			if header.Mode&0100 != 0 {
				mode = 0755
			}
			if writeEntry(root, name, mode, reader, header.Size) != nil {
				return release.ErrInvalid
			}
		default:
			return release.ErrInvalid
		}
	}
}

// packagePath strips the package's top folder and refuses anything that could escape it.
func packagePath(name, top string) (string, bool) {
	name = strings.TrimSuffix(name, "/")
	if strings.ContainsAny(name, "\\:\x00") || strings.HasPrefix(name, "/") {
		return "", false
	}
	if name == top {
		return "", true
	}
	rest, found := strings.CutPrefix(name, top+"/")
	if !found || rest == "" || path.Clean(rest) != rest {
		return "", false
	}
	for _, part := range strings.Split(rest, "/") {
		if part == "" || part == "." || part == ".." {
			return "", false
		}
	}
	return rest, true
}

func writeEntry(root *os.Root, name string, mode os.FileMode, reader io.Reader, size int64) error {
	output, err := root.OpenFile(filepath.FromSlash(name), os.O_CREATE|os.O_EXCL|os.O_WRONLY, mode)
	if err != nil {
		return err
	}
	count, copyErr := io.Copy(output, io.LimitReader(reader, size+1))
	chmodErr := output.Chmod(mode) // the service's umask would otherwise trim it
	syncErr := output.Sync()
	closeErr := output.Close()
	if copyErr != nil || count != size {
		return release.ErrInvalid
	}
	return errors.Join(chmodErr, syncErr, closeErr)
}

// CheckLinuxTree checks that an unpacked Linux release has the files the launcher needs and
// says it is the target version.
func CheckLinuxTree(directory, version string) error {
	for _, name := range []string{"oblivion-site-collector", "bin/oblivion-collector", "vendor/autoload.php", "VERSION"} {
		info, err := os.Lstat(filepath.Join(directory, filepath.FromSlash(name)))
		if err != nil || !info.Mode().IsRegular() || info.Size() == 0 {
			return errors.New("the release is missing " + name)
		}
	}
	stamped, err := os.ReadFile(filepath.Join(directory, "VERSION"))
	if err != nil || strings.TrimSpace(string(stamped)) != version {
		return errNotTarget
	}
	return nil
}
