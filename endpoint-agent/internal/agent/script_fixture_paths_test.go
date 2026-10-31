package agent

import (
	"os"
	"path/filepath"
)

// committedScriptFixtures maps each PHP producer export the script tests read
// to the copy tracked in the repository's tests/fixtures folder. Without this
// fallback the tests skipped silently unless someone set the variable, so a
// plain `go test ./...` never exercised the journal or the transport.
var committedScriptFixtures = map[string]string{
	"OBLI_SCRIPT_EXPORT":             "endpoint-script-contract.json",
	"OBLI_SCRIPT_TRANSPORT_EXPORT":   "endpoint-script-http.json",
	"OBLI_SCRIPT_CANCEL_EXPORT":      "endpoint-script-cancellation-http.json",
	"OBLI_SCRIPT_OUTPUT_HTTP_EXPORT": "endpoint-script-output-http.json",
	"OBLI_SCRIPT_RESULT_EXPORT":      "endpoint-script-result-http-attended.json",
	"OBLI_SCRIPT_UNATTENDED_EXPORT":  "endpoint-script-result-http-unattended.json",
}

// scriptFixturePath returns the export named by env when it is set (so a
// fresh PHP producer can still be tested), otherwise the committed copy.
// It returns "" only when neither exists.
func scriptFixturePath(env string) string {
	if path := os.Getenv(env); path != "" {
		return path
	}
	name, ok := committedScriptFixtures[env]
	if !ok {
		return ""
	}
	// go test runs with the package folder (endpoint-agent/internal/agent)
	// as the working directory.
	path := filepath.Join("..", "..", "..", "tests", "fixtures", name)
	if _, err := os.Stat(path); err != nil {
		return ""
	}
	return path
}
