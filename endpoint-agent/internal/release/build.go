package release

import (
	"archive/zip"
	"bytes"
	"context"
	"crypto"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"
)

// BuildSpec describes publisher metadata, never a deployment or an enrolment
// credential. Hashes and the complete file inventory come from the staged bytes.
type BuildSpec struct {
	Version      string   `json:"version"`
	Revision     uint64   `json:"revision"`
	Architecture string   `json:"architecture"`
	Roles        []string `json:"roles"`
	PublishedAt  string   `json:"published_at"`
	ExpiresAt    string   `json:"expires_at"`
}

type BuildResult struct {
	Path     string   `json:"path"`
	Size     int64    `json:"size"`
	SHA256   string   `json:"sha256"`
	Manifest Manifest `json:"manifest"`
}

// ReadBuildSpec accepts only the documented metadata object, with no duplicate
// or unknown fields. A spec is not an authority to change the publisher key.
func ReadBuildSpec(reader io.Reader) (BuildSpec, error) {
	var spec BuildSpec
	data, err := io.ReadAll(io.LimitReader(reader, 4097))
	if err != nil || len(data) > 4096 || !bytes.HasPrefix(bytes.TrimSpace(data), []byte("{")) || strictJSON(data, &spec) != nil {
		return spec, errors.New("invalid release metadata")
	}
	return spec, nil
}

// BuildWindows is publisher tooling, not an endpoint operation. Source and
// output parents must be dedicated, access-controlled release workspaces.
// The OS verifier cannot be replaced by a command-line option or build spec.
func BuildWindows(ctx context.Context, source, output string, spec BuildSpec, signer crypto.Signer, trustedKey ed25519.PublicKey, now time.Time) (BuildResult, error) {
	return buildWindows(ctx, source, output, spec, signer, trustedKey, now, verifyBuildExecutable)
}

type executableVerifier func(string, string) error

func buildWindows(ctx context.Context, source, output string, spec BuildSpec, signer crypto.Signer, trustedKey ed25519.PublicKey, now time.Time, verify executableVerifier) (BuildResult, error) {
	started := time.Now()
	currentTime := func() time.Time { return now.Add(time.Since(started)) }
	var result BuildResult
	if err := ctx.Err(); err != nil {
		return result, err
	}
	if signer == nil || verify == nil || len(trustedKey) != ed25519.PublicKeySize {
		return result, errors.New("an Ed25519 publisher signer and matching public key are required")
	}
	public, ok := signer.Public().(ed25519.PublicKey)
	if !ok || !public.Equal(trustedKey) {
		return result, errors.New("publisher signer does not match the expected release authority")
	}
	source, err := filepath.Abs(source)
	if err != nil {
		return result, ErrInvalid
	}
	output, err = filepath.Abs(output)
	if err != nil || !strings.EqualFold(filepath.Ext(output), ".zip") {
		return result, errors.New("output must be a new ZIP outside the staging tree")
	}
	// Resolve existing directory links for the containment check. The actual
	// source root is separately opened and anchored below.
	sourceCanonical, err := filepath.EvalSymlinks(source)
	if err != nil {
		return result, errors.New("cannot open release staging tree")
	}
	parentCanonical, err := filepath.EvalSymlinks(filepath.Dir(output))
	if err != nil {
		return result, errors.New("release output parent must already exist")
	}
	output = filepath.Join(parentCanonical, filepath.Base(output))
	relative, err := filepath.Rel(sourceCanonical, parentCanonical)
	if err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(filepath.Separator)) {
		return result, errors.New("release output must be outside the staging tree")
	}
	if _, err = os.Lstat(output); !errors.Is(err, os.ErrNotExist) {
		return result, errors.New("release output already exists or cannot be inspected")
	}
	info, err := os.Lstat(source)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return result, errors.New("release staging root must be an ordinary directory")
	}
	sourceRoot, err := os.OpenRoot(source)
	if err != nil {
		return result, errors.New("cannot anchor release staging tree")
	}
	defer sourceRoot.Close()
	parent, err := os.OpenRoot(parentCanonical)
	if err != nil {
		return result, errors.New("cannot anchor release output directory")
	}
	defer parent.Close()
	// This fresh snapshot is never published. The controlled output directory
	// supplies the Windows ACL boundary; mode bits alone do not claim that.
	snapshot, err := os.MkdirTemp(parentCanonical, ".oblivion-release-")
	if err != nil {
		return result, errors.New("cannot create release snapshot")
	}
	defer parent.RemoveAll(filepath.Base(snapshot))
	snapshotRoot, err := os.OpenRoot(snapshot)
	if err != nil {
		return result, ErrInvalid
	}
	defer snapshotRoot.Close()
	manifest := Manifest{Schema: 1, Product: "oblivion-windows", Platform: "windows",
		Version: spec.Version, Revision: spec.Revision, Architecture: spec.Architecture,
		Roles: append([]string{}, spec.Roles...), PublishedAt: spec.PublishedAt, ExpiresAt: spec.ExpiresAt}
	records, err := snapshotFiles(ctx, sourceRoot, snapshotRoot)
	if err != nil {
		return result, err
	}
	manifest.Files = records
	if validateManifest(manifest, spec.Architecture, 0, now) != nil {
		return result, errors.New("staging tree or release metadata does not meet the installer contract")
	}
	var executableHandles []*os.File
	defer func() {
		for _, handle := range executableHandles {
			handle.Close()
		}
	}()
	for _, record := range records {
		if err = ctx.Err(); err != nil {
			return result, err
		}
		if strings.EqualFold(path.Ext(record.Path), ".exe") {
			handle, openErr := openArchive(filepath.Join(snapshot, filepath.FromSlash(record.Path)))
			if openErr != nil {
				return result, ErrInvalid
			}
			executableHandles = append(executableHandles, handle)
			if err = verify(filepath.Join(snapshot, filepath.FromSlash(record.Path)), spec.Architecture); err != nil {
				return result, errors.New("a staged Windows executable failed architecture or Authenticode verification")
			}
		}
	}
	if validateManifest(manifest, spec.Architecture, 0, currentTime()) != nil {
		return result, errors.New("release metadata expired before signing")
	}
	payload, err := json.Marshal(manifest)
	if err != nil {
		return result, ErrInvalid
	}
	if err = ctx.Err(); err != nil {
		return result, err
	}
	message := append([]byte(SignatureDomain), payload...)
	signature, err := signer.Sign(rand.Reader, message, crypto.Hash(0))
	if err != nil || !ed25519.Verify(trustedKey, message, signature) {
		return result, errors.New("publisher could not produce a valid release signature")
	}
	encoded, err := json.Marshal(envelope{base64.StdEncoding.EncodeToString(payload), base64.StdEncoding.EncodeToString(signature)})
	if err != nil || len(encoded) > int(MaxManifestBytes) {
		return result, ErrInvalid
	}
	archiveName := filepath.Join(snapshot, "release.zip")
	if err = writeBuildArchive(ctx, snapshotRoot, archiveName, records, encoded); err != nil {
		return result, err
	}
	// Verify with the independent installer reader, including every archive
	// byte, before making an output path visible.
	pack, err := Open(archiveName, trustedKey, spec.Architecture, spec.Revision, currentTime())
	if err != nil {
		return result, errors.New("built archive failed installer verification")
	}
	if err = pack.Close(); err != nil {
		return result, err
	}
	file, err := openArchive(archiveName)
	if err != nil {
		return result, ErrInvalid
	}
	hash := sha256.New()
	size, hashErr := io.Copy(hash, &buildContextReader{ctx, io.LimitReader(file, MaxArchiveBytes+1)})
	closeErr := file.Close()
	if hashErr != nil || closeErr != nil || size <= 0 || size > MaxArchiveBytes {
		return result, ErrInvalid
	}
	if err = ctx.Err(); err != nil {
		return result, err
	}
	if validateManifest(manifest, spec.Architecture, 0, currentTime()) != nil {
		return result, errors.New("release metadata expired before output creation")
	}
	// Same-volume hard linking is atomic and refuses an existing destination;
	// unlike Rename it cannot overwrite an earlier release on any platform.
	// The private staging link is then removed, leaving one published link.
	if err = os.Link(archiveName, output); err != nil {
		return result, errors.New("cannot create release output without overwriting an existing file")
	}
	if err = snapshotRoot.Remove("release.zip"); err != nil {
		// Only remove the output we just linked, never a pre-existing release.
		parent.Remove(filepath.Base(output))
		return result, errors.New("cannot finish release output; no successful build was reported")
	}
	result = BuildResult{Path: output, Size: size, SHA256: hex.EncodeToString(hash.Sum(nil)), Manifest: manifest}
	return result, nil
}

func snapshotFiles(ctx context.Context, source, destination *os.Root) ([]File, error) {
	var records []File
	var total int64
	entries := 0
	casings := map[string]string{}
	err := fs.WalkDir(source.FS(), ".", func(name string, entry fs.DirEntry, walkErr error) error {
		if err := ctx.Err(); err != nil {
			return err
		}
		if walkErr != nil || entry == nil {
			return errors.New("cannot read release staging tree")
		}
		if name == "." {
			return nil
		}
		entries++
		if entries > MaxFiles*2 || !safePath(name) || !recordPathCasing(casings, name) || entry.Type()&os.ModeSymlink != 0 {
			return errors.New("release staging tree contains an unsafe path or too many entries")
		}
		info, err := source.Lstat(filepath.FromSlash(name))
		if err != nil || info.Mode()&os.ModeSymlink != 0 {
			return ErrInvalid
		}
		if info.IsDir() {
			if entry.Type() != fs.ModeDir {
				return ErrInvalid
			}
			return destination.Mkdir(filepath.FromSlash(name), 0700)
		}
		if !info.Mode().IsRegular() || len(records) >= MaxFiles || info.Size() < 0 || info.Size() > MaxFileBytes || total+info.Size() > MaxExpandedBytes {
			return errors.New("release staging tree contains an unsupported file or exceeds size limits")
		}
		record, err := snapshotFile(ctx, source, destination, name, info)
		if err != nil {
			return err
		}
		total += record.Size
		records = append(records, record)
		return nil
	})
	if err != nil {
		return nil, err
	}
	return records, nil
}

func snapshotFile(ctx context.Context, source, destination *os.Root, name string, expected fs.FileInfo) (File, error) {
	var record File
	input, err := source.Open(filepath.FromSlash(name))
	if err != nil {
		return record, ErrInvalid
	}
	defer input.Close()
	actual, err := input.Stat()
	if err != nil || !actual.Mode().IsRegular() || !os.SameFile(expected, actual) || expected.Size() != actual.Size() {
		return record, ErrInvalid
	}
	output, err := destination.OpenFile(filepath.FromSlash(name), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return record, ErrInvalid
	}
	hash := sha256.New()
	count, copyErr := io.Copy(io.MultiWriter(output, hash), &buildContextReader{ctx, io.LimitReader(input, expected.Size()+1)})
	syncErr := output.Sync()
	closeErr := output.Close()
	if copyErr != nil || syncErr != nil || closeErr != nil || count != expected.Size() {
		return record, errors.New("staged release file changed or could not be copied")
	}
	return File{Path: name, Size: count, SHA256: hex.EncodeToString(hash.Sum(nil))}, nil
}

func writeBuildArchive(ctx context.Context, snapshot *os.Root, filename string, records []File, manifest []byte) error {
	file, err := os.OpenFile(filename, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return ErrInvalid
	}
	archive := zip.NewWriter(&buildLimitWriter{writer: file, remaining: MaxArchiveBytes})
	add := func(name string, reader io.Reader) error {
		header := &zip.FileHeader{Name: name, Method: zip.Deflate}
		header.SetMode(0600)
		writer, err := archive.CreateHeader(header)
		if err != nil {
			return err
		}
		_, err = io.Copy(writer, &buildContextReader{ctx, reader})
		return err
	}
	buildErr := add("release.json", strings.NewReader(string(manifest)))
	for _, record := range records {
		if buildErr != nil {
			break
		}
		input, openErr := snapshot.Open(filepath.FromSlash(record.Path))
		if openErr != nil {
			buildErr = openErr
			break
		}
		hash := sha256.New()
		buildErr = add(record.Path, io.TeeReader(io.LimitReader(input, record.Size+1), hash))
		input.Close()
		if hex.EncodeToString(hash.Sum(nil)) != record.SHA256 {
			buildErr = ErrInvalid
		}
	}
	zipErr := archive.Close()
	syncErr := file.Sync()
	closeErr := file.Close()
	if buildErr != nil || zipErr != nil || syncErr != nil || closeErr != nil {
		return errors.New("could not assemble the verified release archive")
	}
	return nil
}

type buildContextReader struct {
	ctx    context.Context
	reader io.Reader
}

func (r *buildContextReader) Read(buffer []byte) (int, error) {
	if err := r.ctx.Err(); err != nil {
		return 0, err
	}
	return r.reader.Read(buffer)
}

type buildLimitWriter struct {
	writer    io.Writer
	remaining int64
}

func (w *buildLimitWriter) Write(data []byte) (int, error) {
	if int64(len(data)) > w.remaining {
		return 0, errors.New("release archive exceeds size limit")
	}
	n, err := w.writer.Write(data)
	w.remaining -= int64(n)
	return n, err
}
