package release

import (
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"io"
	"regexp"
	"strings"
	"time"
)

const CatalogueDomain = "OBLIVION-DOWNLOADS-V1\n"
const MaxCatalogueBytes = 65536
const MaxCatalogueArtifacts = 16

var catalogueIDPattern = regexp.MustCompile("^[a-z0-9][a-z0-9-]{1,63}$")
var catalogueNamePattern = regexp.MustCompile("^[a-zA-Z0-9][a-zA-Z0-9_.-]*$")
var catalogueDatePattern = regexp.MustCompile(`^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$`)
var catalogueReservedPattern = regexp.MustCompile(`(?i)^(CON|PRN|AUX|NUL|CLOCK\$|COM[0-9]|LPT[0-9])(\.|$)`)

// DownloadArtifact describes exact approved distribution bytes, not an installed
// or enrolled device. The publisher verifies Size/SHA256 before signing.
type DownloadArtifact struct {
	ID              string   `json:"id"`
	Platform        string   `json:"platform"`
	Architecture    string   `json:"architecture"`
	Roles           []string `json:"roles"`
	Version         string   `json:"version"`
	ReleaseRevision uint64   `json:"release_revision"`
	Filename        string   `json:"filename"`
	Size            int64    `json:"size"`
	SHA256          string   `json:"sha256"`
}
type CatalogueSpec struct {
	Revision  uint64             `json:"revision"`
	IssuedAt  string             `json:"issued_at"`
	ExpiresAt string             `json:"expires_at"`
	Artifacts []DownloadArtifact `json:"artifacts"`
}
type DownloadCatalogue struct {
	Schema    int                `json:"schema"`
	Product   string             `json:"product"`
	Revision  uint64             `json:"revision"`
	IssuedAt  string             `json:"issued_at"`
	ExpiresAt string             `json:"expires_at"`
	Artifacts []DownloadArtifact `json:"artifacts"`
}

func ReadCatalogueSpec(reader io.Reader) (CatalogueSpec, error) {
	var spec CatalogueSpec
	data, err := io.ReadAll(io.LimitReader(reader, MaxCatalogueBytes+1))
	if err != nil || len(data) > MaxCatalogueBytes || !bytes.HasPrefix(bytes.TrimSpace(data), []byte("{")) || strictJSON(data, &spec) != nil || catalogueJSONShape(data, false) != nil {
		return spec, ErrInvalid
	}
	return spec, nil
}

func validateCatalogue(c DownloadCatalogue, minimumRevision uint64, now time.Time) error {
	issued, err1 := time.Parse(time.RFC3339, c.IssuedAt)
	expires, err2 := time.Parse(time.RFC3339, c.ExpiresAt)
	if c.Schema != 1 || c.Product != "oblivion-downloads" || minimumRevision < 1 || c.Revision < minimumRevision || c.Revision > 9007199254740991 ||
		!catalogueDatePattern.MatchString(c.IssuedAt) || !catalogueDatePattern.MatchString(c.ExpiresAt) || err1 != nil || err2 != nil ||
		issued.After(now.Add(5*time.Minute)) || !expires.After(now) || !expires.After(issued) || expires.Sub(issued) > 90*24*time.Hour ||
		c.Artifacts == nil || len(c.Artifacts) > MaxCatalogueArtifacts {
		return ErrInvalid
	}
	ids, names := map[string]bool{}, map[string]bool{}
	for _, a := range c.Artifacts {
		if !catalogueIDPattern.MatchString(a.ID) || ids[a.ID] || !catalogueSafeFilename(a.Filename) || names[strings.ToLower(a.Filename)] ||
			(a.Platform != "windows" && a.Platform != "linux") || (a.Architecture != "amd64" && a.Architecture != "arm64") ||
			len(a.Version) > 32 || !versionPattern.MatchString(a.Version) || a.ReleaseRevision < 1 || a.ReleaseRevision > 9007199254740991 ||
			a.Size < 1 || a.Size > MaxArchiveBytes || !digestPattern.MatchString(a.SHA256) || len(a.Roles) < 1 || len(a.Roles) > 2 {
			return ErrInvalid
		}
		roles := map[string]bool{}
		for _, role := range a.Roles {
			if (role != "computer" && role != "site") || roles[role] {
				return ErrInvalid
			}
			roles[role] = true
		}
		if a.Platform == "linux" {
			if len(a.Roles) != 1 || a.Roles[0] != "site" || !strings.HasSuffix(a.Filename, ".tar.gz") {
				return ErrInvalid
			}
		} else if !strings.HasSuffix(a.Filename, ".exe") && !strings.HasSuffix(a.Filename, ".zip") {
			return ErrInvalid
		}
		ids[a.ID] = true
		names[strings.ToLower(a.Filename)] = true
	}
	return nil
}
func catalogueSafeFilename(name string) bool {
	return len(name) <= 160 && catalogueNamePattern.MatchString(name) && !strings.Contains(name, "..") &&
		!strings.HasSuffix(name, ".") && !catalogueReservedPattern.MatchString(name)
}

// VerifyCatalogue shares the strict public protocol with the Laravel download
// reader. It verifies signed metadata only; serving bytes requires their hashes.
func VerifyCatalogue(data []byte, trustedKey ed25519.PublicKey, minimumRevision uint64, now time.Time) (DownloadCatalogue, error) {
	var c DownloadCatalogue
	var signed envelope
	if len(data) > MaxCatalogueBytes || len(trustedKey) != ed25519.PublicKeySize || strictJSON(data, &signed) != nil || exactCatalogueKeys(data, "payload", "signature") != nil {
		return c, ErrInvalid
	}
	payload, err1 := base64.StdEncoding.Strict().DecodeString(signed.Payload)
	signature, err2 := base64.StdEncoding.Strict().DecodeString(signed.Signature)
	if err1 != nil || err2 != nil || len(payload) > MaxCatalogueBytes || len(signature) != ed25519.SignatureSize ||
		base64.StdEncoding.EncodeToString(payload) != signed.Payload || base64.StdEncoding.EncodeToString(signature) != signed.Signature ||
		!ed25519.Verify(trustedKey, append([]byte(CatalogueDomain), payload...), signature) || strictJSON(payload, &c) != nil || catalogueJSONShape(payload, true) != nil ||
		validateCatalogue(c, minimumRevision, now) != nil {
		return DownloadCatalogue{}, ErrInvalid
	}
	return c, nil
}

func catalogueFromSpec(spec CatalogueSpec) DownloadCatalogue {
	var artifacts []DownloadArtifact
	if spec.Artifacts != nil {
		artifacts = make([]DownloadArtifact, len(spec.Artifacts))
		for i, artifact := range spec.Artifacts {
			artifact.Roles = append([]string{}, artifact.Roles...)
			artifacts[i] = artifact
		}
	}
	return DownloadCatalogue{1, "oblivion-downloads", spec.Revision, spec.IssuedAt, spec.ExpiresAt, artifacts}
}

func encodeCatalogue(signature []byte, payload []byte) ([]byte, error) {
	return json.Marshal(envelope{base64.StdEncoding.EncodeToString(payload), base64.StdEncoding.EncodeToString(signature)})
}

func exactCatalogueKeys(data []byte, names ...string) error {
	var object map[string]json.RawMessage
	if json.Unmarshal(data, &object) != nil || object == nil || len(object) != len(names) {
		return ErrInvalid
	}
	for _, name := range names {
		if _, exists := object[name]; !exists {
			return ErrInvalid
		}
	}
	return nil
}
func catalogueJSONShape(data []byte, signed bool) error {
	names := []string{"revision", "issued_at", "expires_at", "artifacts"}
	if signed {
		names = append(names, "schema", "product")
	}
	if exactCatalogueKeys(data, names...) != nil {
		return ErrInvalid
	}
	var object map[string]json.RawMessage
	if json.Unmarshal(data, &object) != nil {
		return ErrInvalid
	}
	var artifacts []json.RawMessage
	if json.Unmarshal(object["artifacts"], &artifacts) != nil || artifacts == nil {
		return ErrInvalid
	}
	for _, artifact := range artifacts {
		if exactCatalogueKeys(artifact, "id", "platform", "architecture", "roles", "version", "release_revision", "filename", "size", "sha256") != nil {
			return ErrInvalid
		}
	}
	return nil
}
