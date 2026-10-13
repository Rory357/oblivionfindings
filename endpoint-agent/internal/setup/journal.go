package setup

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"
)

var noncePattern = regexp.MustCompile("^[a-f0-9]{32}$")

// The protected record still uses strict, bounded decoding: damaged records do
// not authorize installation recovery, a different destination or a downgrade.
func decodeJournal(data []byte, expected Journal) (Journal, error) {
	invalid := errors.New("installation record does not match this release and computer; approved repair or update is required")
	if len(data) > 32768 || !utf8.Valid(data) {
		return Journal{}, invalid
	}
	scan := json.NewDecoder(bytes.NewReader(data))
	token, err := scan.Token()
	if err != nil || token != json.Delim('{') {
		return Journal{}, invalid
	}
	names := map[string]bool{}
	for scan.More() {
		key, err := scan.Token()
		name, ok := key.(string)
		if err != nil || !ok || names[name] {
			return Journal{}, invalid
		}
		names[name] = true
		var value json.RawMessage
		if scan.Decode(&value) != nil {
			return Journal{}, invalid
		}
	}
	var j Journal
	var extra any
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&j) != nil || decoder.Decode(&extra) != io.EOF {
		return Journal{}, invalid
	}
	phases := map[string]bool{"planned": true, "staged": true, "enrolled": true, "service_registered": true, "tray_registered": true, "service_ready": true, "installed": true}
	failures := map[string]bool{"": true, "staging": true, "enrolment": true, "service_registration": true, "tray_registration": true, "service_start": true, "journal_write": true, "interrupted": true}
	prefix := fmt.Sprintf("r-%d-", expected.Revision)
	_, timeErr := time.Parse(time.RFC3339, j.UpdatedAt)
	if j.Schema != 1 || !noncePattern.MatchString(j.Attempt) || !strings.HasPrefix(j.Stage, prefix) || !noncePattern.MatchString(strings.TrimPrefix(j.Stage, prefix)) ||
		j.Version != expected.Version || j.Revision != expected.Revision || j.ManifestSHA256 != expected.ManifestSHA256 || j.Architecture != expected.Architecture ||
		j.Role != expected.Role || j.ServerURL != expected.ServerURL || j.AgentUUID != expected.AgentUUID || j.CollectorUUID != expected.CollectorUUID || j.CollectorSiteID != expected.CollectorSiteID || j.CollectorProfileHash != expected.CollectorProfileHash || !phases[j.Phase] || !failures[j.FailedStep] || timeErr != nil ||
		(j.RecoveryRequired && j.FailedStep == "") || (j.Phase == "installed" && (j.RecoveryRequired || j.FailedStep != "")) {
		return Journal{}, invalid
	}
	return j, nil
}
