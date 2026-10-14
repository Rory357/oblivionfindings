package setup

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestJournalRetryIsBoundToExactReleaseDeviceAndDestination(t *testing.T) {
	expected := newJournal()
	expected.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	encode := func(j Journal) []byte {
		b, err := json.Marshal(j)
		if err != nil {
			t.Fatal(err)
		}
		return b
	}
	original := encode(expected)
	if got, err := decodeJournal(original, expected); err != nil || got != expected {
		t.Fatal("valid durable receipt rejected", err)
	}
	changes := map[string]func(*Journal){
		"server":                  func(j *Journal) { j.ServerURL = "https://other.example.test" },
		"computer":                func(j *Journal) { j.AgentUUID = "different" },
		"manifest":                func(j *Journal) { j.ManifestSHA256 = strings.Repeat("1", 64) },
		"revision":                func(j *Journal) { j.Revision-- },
		"version":                 func(j *Journal) { j.Version = "1.2.4" },
		"architecture":            func(j *Journal) { j.Architecture = "arm64" },
		"role":                    func(j *Journal) { j.Role = "both" },
		"stage traversal":         func(j *Journal) { j.Stage = "../other" },
		"stage other revision":    func(j *Journal) { j.Stage = "r-6-" + strings.Repeat("a", 32) },
		"attempt":                 func(j *Journal) { j.Attempt = "not-a-nonce" },
		"phase":                   func(j *Journal) { j.Phase = "anything" },
		"time":                    func(j *Journal) { j.UpdatedAt = "" },
		"installed with recovery": func(j *Journal) { j.Phase = "installed"; j.RecoveryRequired = true; j.FailedStep = "service_start" },
	}
	for name, change := range changes {
		t.Run(name, func(t *testing.T) {
			j := expected
			change(&j)
			if _, err := decodeJournal(encode(j), expected); err == nil {
				t.Fatal("changed binding accepted")
			}
		})
	}
	for _, data := range [][]byte{
		append(append([]byte{}, original...), []byte("{}")...),
		[]byte(strings.Replace(string(original), "{", "{\"schema\":1,", 1)),
		[]byte(strings.Replace(string(original), "{", "{\"unexpected\":true,", 1)),
		[]byte(strings.Repeat(" ", 32769)),
		original[:len(original)-1],
	} {
		if _, err := decodeJournal(data, expected); err == nil {
			t.Fatal("ambiguous or damaged journal accepted")
		}
	}
}
