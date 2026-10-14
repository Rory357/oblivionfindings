package release

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"testing"
	"time"
)

// Opt in with an explicit PHP executable from the development environment.
// Uses the actual PHP application reader, never a duplicated test decoder.
func TestCatalogueGeneratedByGoIsAcceptedAndEnforcedByPHP(t *testing.T) {
	php := os.Getenv("OBLI_CATALOGUE_PHP")
	if php == "" {
		t.Skip("set OBLI_CATALOGUE_PHP for real application-reader interoperability")
	}
	script, err := filepath.Abs("../../../tests/Support/endpoint-release-catalogue-reader.php")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = os.Stat(script); err != nil {
		t.Fatal(err)
	}
	runPHP := func(output, key, floor string) ([]byte, error) {
		t.Helper()
		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
		defer cancel()
		command := exec.CommandContext(ctx, php, script, output, key, floor)
		return command.CombinedOutput()
	}
	for _, empty := range []bool{false, true} {
		source, output, spec, key, packageKey := catalogueFixture(t)
		if empty {
			spec.Artifacts = []DownloadArtifact{}
		} else {
			linuxBytes := []byte("test-only Linux collector archive bytes; not an installable release")
			hash := sha256.Sum256(linuxBytes)
			linuxArtifact := DownloadArtifact{"linux-site-arm64", "linux", "arm64", []string{"site"}, "1.0.0", 2, "collector-arm64.tar.gz", int64(len(linuxBytes)), hex.EncodeToString(hash[:])}
			if err := os.WriteFile(filepath.Join(source, linuxArtifact.Filename), linuxBytes, 0600); err != nil {
				t.Fatal(err)
			}
			spec.Artifacts = append(spec.Artifacts, linuxArtifact)
		}
		result, err := buildCatalogueFixture(context.Background(), source, output, spec, key, key, packageKey)
		if err != nil {
			t.Fatal(err)
		}
		data, err := runPHP(output, catalogueFixturePublic(key), "5")
		if err != nil {
			t.Fatalf("PHP rejected generated catalogue: %v: %s", err, data)
		}
		var report struct {
			Revision  uint64             `json:"revision"`
			Artifacts []DownloadArtifact `json:"artifacts"`
		}
		if err = json.Unmarshal(data, &report); err != nil || report.Revision != 5 || !reflect.DeepEqual(report.Artifacts, result.Catalogue.Artifacts) {
			t.Fatal("application disagreed with generated metadata", err, string(data))
		}
		if data, err = runPHP(output, catalogueFixturePublic(key), "6"); err == nil {
			t.Fatal("application ignored revision floor", string(data))
		}
		if data, err = runPHP(output, catalogueFixturePublic(packageKey), "5"); err == nil {
			t.Fatal("application accepted wrong catalogue authority", string(data))
		}
		if !empty {
			filename := filepath.Join(output, spec.Artifacts[0].Filename)
			original, err := os.ReadFile(filename)
			if err != nil {
				t.Fatal(err)
			}
			original[len(original)/2] ^= 1
			if err = os.WriteFile(filename, original, 0600); err != nil {
				t.Fatal(err)
			}
			if data, err = runPHP(output, catalogueFixturePublic(key), "5"); err == nil {
				t.Fatal("application served altered published bytes", string(data))
			}
		}
	}
}
