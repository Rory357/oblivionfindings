package release

import (
	"context"
	"crypto"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"
)

type CatalogueBuildResult struct {
	Path            string            `json:"path"`
	CatalogueSHA256 string            `json:"catalogue_sha256"`
	CatalogueSize   int               `json:"catalogue_size"`
	Catalogue       DownloadCatalogue `json:"catalogue"`
}

// BuildCatalogue assembles a NEW offline publication directory. The caller
// separately controls deployment activation and the minimum accepted revision.
// packageKey independently identifies the authority for Windows ZIP contents.
// Linux collector packages are byte-hash-bound here, not installed or extracted.
func BuildCatalogue(ctx context.Context, source, output string, spec CatalogueSpec, signer crypto.Signer, catalogueKey, packageKey ed25519.PublicKey, now time.Time) (CatalogueBuildResult, error) {
	return buildCatalogue(ctx, source, output, spec, signer, catalogueKey, packageKey, now, verifyBuildExecutable)
}

func buildCatalogue(ctx context.Context, source, output string, spec CatalogueSpec, signer crypto.Signer, catalogueKey, packageKey ed25519.PublicKey, now time.Time, verify executableVerifier) (CatalogueBuildResult, error) {
	started := time.Now()
	currentTime := func() time.Time { return now.Add(time.Since(started)) }
	var result CatalogueBuildResult
	if err := ctx.Err(); err != nil {
		return result, err
	}
	catalogue := catalogueFromSpec(spec)
	if validateCatalogue(catalogue, 1, now) != nil || signer == nil || verify == nil || len(catalogueKey) != ed25519.PublicKeySize {
		return result, errors.New("invalid catalogue metadata or publisher authority")
	}
	public, ok := signer.Public().(ed25519.PublicKey)
	if !ok || !public.Equal(catalogueKey) {
		return result, errors.New("catalogue signer does not match the expected publisher")
	}
	for _, artifact := range catalogue.Artifacts {
		if artifact.Platform == "windows" && strings.HasSuffix(artifact.Filename, ".zip") && len(packageKey) != ed25519.PublicKeySize {
			return result, errors.New("Windows packages require an independently configured package public key")
		}
	}
	source, err := filepath.Abs(source)
	if err != nil {
		return result, ErrInvalid
	}
	output, err = filepath.Abs(output)
	if err != nil {
		return result, ErrInvalid
	}
	info, err := os.Lstat(source)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return result, errors.New("artifact source must be an ordinary directory")
	}
	canonicalSource, err := filepath.EvalSymlinks(source)
	if err != nil {
		return result, ErrInvalid
	}
	parentPath, err := filepath.EvalSymlinks(filepath.Dir(output))
	if err != nil {
		return result, errors.New("catalogue output parent must already exist")
	}
	relative, err := filepath.Rel(canonicalSource, parentPath)
	if err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(filepath.Separator)) {
		return result, errors.New("catalogue output must be outside the artifact source")
	}
	output = filepath.Join(parentPath, filepath.Base(output))
	sourceRoot, err := os.OpenRoot(source)
	if err != nil {
		return result, ErrInvalid
	}
	defer sourceRoot.Close()
	parent, err := os.OpenRoot(parentPath)
	if err != nil {
		return result, ErrInvalid
	}
	defer parent.Close()
	// Mkdir is exclusive: no existing release, even an empty directory, is reused.
	base := filepath.Base(output)
	if err = parent.Mkdir(base, 0700); err != nil {
		return result, errors.New("catalogue output already exists or cannot be created")
	}
	keep := false
	defer func() {
		if !keep {
			parent.RemoveAll(base)
		}
	}()
	destination, err := parent.OpenRoot(base)
	if err != nil {
		return result, ErrInvalid
	}
	defer destination.Close()
	var held []*os.File
	defer func() {
		for _, file := range held {
			file.Close()
		}
	}()
	for _, artifact := range catalogue.Artifacts {
		if err = ctx.Err(); err != nil {
			return result, err
		}
		if err = copyCatalogueArtifact(ctx, sourceRoot, destination, artifact); err != nil {
			return result, err
		}
		filename := filepath.Join(output, artifact.Filename)
		handle, openErr := openArchive(filename)
		if openErr != nil {
			return result, ErrInvalid
		}
		held = append(held, handle)
		if artifact.Platform == "windows" {
			if strings.HasSuffix(artifact.Filename, ".zip") {
				pack, openErr := Open(filename, packageKey, artifact.Architecture, artifact.ReleaseRevision, currentTime())
				if openErr != nil {
					return result, errors.New("a Windows archive failed publisher verification")
				}
				manifest := pack.Manifest()
				pack.Close()
				aRoles, mRoles := append([]string{}, artifact.Roles...), append([]string{}, manifest.Roles...)
				slices.Sort(aRoles)
				slices.Sort(mRoles)
				packageExpiry, parseErr := time.Parse(time.RFC3339, manifest.ExpiresAt)
				catalogueExpiry, _ := time.Parse(time.RFC3339, catalogue.ExpiresAt)
				if manifest.Version != artifact.Version || manifest.Revision != artifact.ReleaseRevision || !slices.Equal(aRoles, mRoles) ||
					parseErr != nil || catalogueExpiry.After(packageExpiry) {
					return result, errors.New("catalogue identity, roles or validity do not match the signed Windows package")
				}
			} else if err = verify(filename, artifact.Architecture); err != nil {
				return result, errors.New("a Windows installer failed architecture or Authenticode verification")
			}
		}
	}
	if err = ctx.Err(); err != nil {
		return result, err
	}
	if validateCatalogue(catalogue, 1, currentTime()) != nil {
		return result, errors.New("catalogue metadata expired before signing")
	}
	payload, err := json.Marshal(catalogue)
	if err != nil {
		return result, ErrInvalid
	}
	message := append([]byte(CatalogueDomain), payload...)
	signature, err := signer.Sign(rand.Reader, message, crypto.Hash(0))
	if err != nil || !ed25519.Verify(catalogueKey, message, signature) {
		return result, errors.New("catalogue publisher did not return a valid signature")
	}
	encoded, err := encodeCatalogue(signature, payload)
	if err != nil || len(encoded) > MaxCatalogueBytes {
		return result, ErrInvalid
	}
	if _, err = VerifyCatalogue(encoded, catalogueKey, catalogue.Revision, currentTime()); err != nil {
		return result, ErrInvalid
	}
	// Recheck held snapshot bytes before enabling this directory with a catalogue.
	// Windows handles also deny mutations while verification/signing is in flight.
	for i, artifact := range catalogue.Artifacts {
		if _, err = held[i].Seek(0, io.SeekStart); err != nil {
			return result, ErrInvalid
		}
		if err = checkCatalogueBytes(ctx, held[i], io.Discard, artifact); err != nil {
			return result, err
		}
	}
	if err = ctx.Err(); err != nil {
		return result, err
	}
	if validateCatalogue(catalogue, 1, currentTime()) != nil {
		return result, ErrInvalid
	}
	file, err := destination.OpenFile("downloads.json", os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return result, ErrInvalid
	}
	_, writeErr := file.Write(encoded)
	syncErr, closeErr := file.Sync(), file.Close()
	if writeErr != nil || syncErr != nil || closeErr != nil {
		return result, errors.New("could not commit the catalogue file")
	}
	hash := sha256.Sum256(encoded)
	keep = true
	result = CatalogueBuildResult{output, hex.EncodeToString(hash[:]), len(encoded), catalogue}
	return result, nil
}

func copyCatalogueArtifact(ctx context.Context, source, destination *os.Root, artifact DownloadArtifact) error {
	info, err := source.Lstat(artifact.Filename)
	if err != nil || !info.Mode().IsRegular() || info.Size() != artifact.Size {
		return errors.New("an approved artifact is missing or has changed size")
	}
	input, err := source.Open(artifact.Filename)
	if err != nil {
		return ErrInvalid
	}
	defer input.Close()
	actual, err := input.Stat()
	if err != nil || !actual.Mode().IsRegular() || actual.Size() != artifact.Size || !os.SameFile(info, actual) {
		return ErrInvalid
	}
	output, err := destination.OpenFile(artifact.Filename, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return ErrInvalid
	}
	copyErr := checkCatalogueBytes(ctx, input, output, artifact)
	syncErr, closeErr := output.Sync(), output.Close()
	if copyErr != nil || syncErr != nil || closeErr != nil {
		return errors.New("artifact bytes do not match the approved release inventory")
	}
	return nil
}
func checkCatalogueBytes(ctx context.Context, input io.Reader, output io.Writer, artifact DownloadArtifact) error {
	hash := sha256.New()
	size, err := io.Copy(io.MultiWriter(output, hash), &buildContextReader{ctx, io.LimitReader(input, artifact.Size+1)})
	if err != nil || size != artifact.Size || hex.EncodeToString(hash.Sum(nil)) != artifact.SHA256 {
		return ErrInvalid
	}
	return nil
}
