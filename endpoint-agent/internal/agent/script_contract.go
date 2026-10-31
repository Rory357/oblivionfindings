package agent

// Script contract validation does not publish a catalogue entry or enable an
// executor. The canonical queue, current authority and installed gates remain required.
import (
	"bytes"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"
)

var errScriptContract = errors.New("invalid_script_contract")
var scriptUUID = regexp.MustCompile("^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
var scriptVersion = regexp.MustCompile("^7\\.(0|[1-9][0-9]{0,2})\\.(0|[1-9][0-9]{0,3})$")
var scriptName = regexp.MustCompile("^[A-Za-z][A-Za-z0-9_]{0,31}$")
var scriptPath = regexp.MustCompile("^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$")
var scriptReservedPath = regexp.MustCompile("(?i)^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(\\.|$)")

type scriptObject = map[string]any

func scriptDecode(raw []byte, maximum int) (scriptObject, error) {
	if len(raw) == 0 || len(raw) > maximum || !serviceUnicodeJSON(raw) || scriptUniqueJSON(raw) != nil {
		return nil, errScriptContract
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var object scriptObject
	if decoder.Decode(&object) != nil || object == nil {
		return nil, errScriptContract
	}
	return object, nil
}
func scriptKeys(value any, keys ...string) (scriptObject, bool) {
	object, ok := value.(map[string]any)
	if !ok || len(object) != len(keys) {
		return nil, false
	}
	for _, key := range keys {
		if _, ok := object[key]; !ok {
			return nil, false
		}
	}
	return object, true
}
func scriptString(value any) string { s, _ := value.(string); return s }
func scriptInt(value any, min, max int64) bool {
	number, ok := value.(json.Number)
	if !ok {
		return false
	}
	v, err := strconv.ParseInt(string(number), 10, 64)
	return err == nil && v >= min && v <= max
}
func scriptInteger(value any) int64 {
	v, _ := strconv.ParseInt(string(value.(json.Number)), 10, 64)
	return v
}
func scriptLabel(value any, min, max int) bool {
	s, ok := value.(string)
	if !ok || !utf8.ValidString(s) || utf8.RuneCountInString(s) < min || utf8.RuneCountInString(s) > max {
		return false
	}
	for _, r := range s {
		if r == utf8.RuneError || unicode.Is(unicode.Cc, r) || unicode.Is(unicode.Cf, r) {
			return false
		}
	}
	return true
}
func scriptDigest(value any) bool { s, ok := value.(string); return ok && hexCredential(s) }
func scriptSchema(value any) bool {
	rows, ok := value.([]any)
	if !ok || len(rows) > 16 {
		return false
	}
	seen := map[string]bool{}
	reserved := "|verbose|debug|erroraction|warningaction|informationaction|progressaction|errorvariable|warningvariable|informationvariable|outvariable|outbuffer|pipelinevariable|whatif|confirm|"
	for _, row := range rows {
		p, ok := scriptKeys(row, "name", "type", "required", "constraints")
		if !ok || !scriptName.MatchString(scriptString(p["name"])) {
			return false
		}
		name := strings.ToLower(scriptString(p["name"]))
		if seen[name] || strings.Contains(reserved, "|"+name+"|") {
			return false
		}
		seen[name] = true
		if _, ok := p["required"].(bool); !ok {
			return false
		}
		switch p["type"] {
		case "boolean":
			if _, ok := scriptKeys(p["constraints"]); !ok {
				return false
			}
		case "integer":
			c, ok := scriptKeys(p["constraints"], "minimum", "maximum")
			if !ok || !scriptInt(c["minimum"], -9007199254740991, 9007199254740991) || !scriptInt(c["maximum"], scriptInteger(c["minimum"]), 9007199254740991) {
				return false
			}
		case "string":
			c, ok := scriptKeys(p["constraints"], "min_length", "max_length")
			if !ok || !scriptInt(c["min_length"], 0, 1024) || !scriptInt(c["max_length"], scriptInteger(c["min_length"]), 1024) {
				return false
			}
		case "enum":
			c, ok := scriptKeys(p["constraints"], "values")
			if !ok {
				return false
			}
			choices, ok := c["values"].([]any)
			if !ok || len(choices) < 1 || len(choices) > 32 {
				return false
			}
			unique := map[string]bool{}
			for _, v := range choices {
				if !scriptLabel(v, 1, 1024) || unique[scriptString(v)] {
					return false
				}
				unique[scriptString(v)] = true
			}
		default:
			return false
		}
	}
	return true
}
func scriptInputs(schema any, value any) bool {
	if !scriptSchema(schema) {
		return false
	}
	inputs, ok := value.(map[string]any)
	encoded, err := json.Marshal(value)
	// Go's HTML escaping is not the PHP canonical input encoding.
	var buf bytes.Buffer
	encoder := json.NewEncoder(&buf)
	encoder.SetEscapeHTML(false)
	if encoder.Encode(value) != nil {
		return false
	}
	encoded = bytes.TrimSuffix(buf.Bytes(), []byte("\n"))
	if !ok || err != nil || len(encoded) > 8192 {
		return false
	}
	known := map[string]bool{}
	for _, row := range schema.([]any) {
		p := row.(map[string]any)
		name := p["name"].(string)
		known[name] = true
		v, exists := inputs[name]
		if !exists {
			if p["required"].(bool) {
				return false
			}
			continue
		}
		c := p["constraints"].(map[string]any)
		switch p["type"] {
		case "boolean":
			if _, ok := v.(bool); !ok {
				return false
			}
		case "integer":
			if !scriptInt(v, scriptInteger(c["minimum"]), scriptInteger(c["maximum"])) {
				return false
			}
		case "string":
			if !scriptLabel(v, int(scriptInteger(c["min_length"])), int(scriptInteger(c["max_length"]))) {
				return false
			}
		case "enum":
			text, ok := v.(string)
			if !ok {
				return false
			}
			found := false
			for _, choice := range c["values"].([]any) {
				found = found || choice.(string) == text
			}
			if !found {
				return false
			}
		default:
			return false
		}
	}
	for k := range inputs {
		if !known[k] {
			return false
		}
	}
	return true
}
func scriptPolicy(value any) bool {
	p, ok := scriptKeys(value, "runtime_seconds", "output_bytes", "output_retention_hours", "network_mode", "timeout_mode", "success_exit_codes", "expected_observation_schema")
	if !ok || !scriptInt(p["runtime_seconds"], 1, 900) || !scriptInt(p["output_bytes"], 0, 65536) || !scriptInt(p["output_retention_hours"], 1, 168) || p["network_mode"] != "reviewed_network" || (p["timeout_mode"] != "report_only" && p["timeout_mode"] != "terminate_owned_job") {
		return false
	}
	codes, ok := p["success_exit_codes"].([]any)
	if !ok || len(codes) < 1 || len(codes) > 16 {
		return false
	}
	seen := map[int64]bool{}
	for _, c := range codes {
		if !scriptInt(c, 0, 4294967295) || seen[scriptInteger(c)] {
			return false
		}
		seen[scriptInteger(c)] = true
	}
	return p["expected_observation_schema"] == nil || scriptDigest(p["expected_observation_schema"])
}
func scriptManifest(value any) bool {
	m, ok := scriptKeys(value, "script_uuid", "script_version", "name", "publisher_label", "sha256", "size_bytes", "interpreter", "interpreter_version", "runtime_manifest_sha256", "wrapper_sha256", "architecture", "parameter_schema", "execution_policy")
	return ok && scriptUUID.MatchString(scriptString(m["script_uuid"])) && scriptInt(m["script_version"], 1, 1) && scriptLabel(m["name"], 1, 200) && scriptLabel(m["publisher_label"], 1, 200) && scriptDigest(m["sha256"]) && scriptInt(m["size_bytes"], 1, 262144) && m["interpreter"] == "powershell7" && scriptVersion.MatchString(scriptString(m["interpreter_version"])) && scriptDigest(m["runtime_manifest_sha256"]) && scriptDigest(m["wrapper_sha256"]) && (m["architecture"] == "amd64" || m["architecture"] == "arm64") && scriptSchema(m["parameter_schema"]) && scriptPolicy(m["execution_policy"])
}
func scriptArtifact(m scriptObject, raw []byte) bool {
	sum := sha256.Sum256(raw)
	return scriptManifest(m) && int64(len(raw)) == scriptInteger(m["size_bytes"]) && utf8.Valid(raw) && !bytes.HasPrefix(raw, []byte{0xef, 0xbb, 0xbf}) && !bytes.ContainsRune(raw, 0) && hex.EncodeToString(sum[:]) == m["sha256"]
}
func scriptRuntime(m scriptObject, raw []byte) bool {
	if !scriptManifest(m) {
		return false
	}
	sum := sha256.Sum256(raw)
	if hex.EncodeToString(sum[:]) != m["runtime_manifest_sha256"] {
		return false
	}
	r, err := scriptDecode(raw, 1048576)
	if err != nil {
		return false
	}
	_, ok := scriptKeys(r, "version", "interpreter", "interpreter_version", "architecture", "entrypoint", "files")
	if !ok || !scriptInt(r["version"], 1, 1) || r["interpreter"] != m["interpreter"] || r["interpreter_version"] != m["interpreter_version"] || r["architecture"] != m["architecture"] || r["entrypoint"] != "pwsh.exe" {
		return false
	}
	files, ok := r["files"].([]any)
	if !ok || len(files) < 2 || len(files) > 4096 {
		return false
	}
	prior := ""
	paths := make(map[string]bool, len(files))
	var total int64
	entry, wrapper := false, false
	for _, row := range files {
		f, ok := scriptKeys(row, "path", "size_bytes", "sha256")
		if !ok || !scriptInt(f["size_bytes"], 1, 536870912) || !scriptDigest(f["sha256"]) {
			return false
		}
		path := scriptString(f["path"])
		key := strings.ToLower(path)
		if len(path) > 240 || !scriptPath.MatchString(path) || prior >= key {
			return false
		}
		for _, part := range strings.Split(path, "/") {
			if part == "." || part == ".." || strings.HasSuffix(part, ".") || scriptReservedPath.MatchString(part) {
				return false
			}
		}
		for parent := key; strings.Contains(parent, "/"); {
			parent = parent[:strings.LastIndexByte(parent, '/')]
			if paths[parent] {
				return false
			}
		}
		paths[key] = true
		prior = key
		total += scriptInteger(f["size_bytes"])
		if total > 2147483648 {
			return false
		}
		entry = entry || path == "pwsh.exe"
		wrapper = wrapper || (path == "obli-script-wrapper.ps1" && f["sha256"] == m["wrapper_sha256"])
	}
	return entry && wrapper
}
func scriptInstant(value any) (time.Time, bool) {
	s, ok := value.(string)
	if !ok || len(s) != 20 || strings.HasPrefix(s, "0000") {
		return time.Time{}, false
	}
	t, err := time.Parse("2006-01-02T15:04:05Z", s)
	return t, err == nil && t.Format("2006-01-02T15:04:05Z") == s
}
func verifyScriptCommand(envelope Envelope, key ed25519.PublicKey, agent string, now time.Time) (scriptObject, error) {
	raw, err := scriptSignedRaw(envelope, key)
	if err != nil {
		return nil, errScriptContract
	}
	c, err := scriptDecode(raw, 32768)
	if err != nil {
		return nil, err
	}
	_, ok := scriptKeys(c, "version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "capability", "parameters", "policy", "technician", "purpose", "issued_at", "expires_at")
	if !ok || !scriptInt(c["version"], 1, 1) || c["type"] != "endpoint_script_run" || c["capability"] != "endpoint.script.run" || c["agent_uuid"] != agent || !scriptUUID.MatchString(scriptString(c["agent_uuid"])) || !scriptUUID.MatchString(scriptString(c["command_uuid"])) || !scriptUUID.MatchString(scriptString(c["attempt_uuid"])) || !scriptDigest(c["assignment_fingerprint"]) || !scriptLabel(c["technician"], 1, 200) || !scriptLabel(c["purpose"], 1, 500) {
		return nil, errScriptContract
	}
	p, ok := scriptKeys(c["parameters"], "script_uuid", "script_version", "manifest", "inputs")
	if !ok || !scriptManifest(p["manifest"]) {
		return nil, errScriptContract
	}
	m := p["manifest"].(map[string]any)
	if p["script_uuid"] != m["script_uuid"] || p["script_version"] != m["script_version"] || !scriptInputs(m["parameter_schema"], p["inputs"]) {
		return nil, errScriptContract
	}
	policy, ok := scriptKeys(c["policy"], "version", "enabled", "consent_mode")
	if !ok || !scriptInt(policy["version"], 1, 9007199254740991) || policy["enabled"] != true || (policy["consent_mode"] != "attended" && policy["consent_mode"] != "unattended") {
		return nil, errScriptContract
	}
	issued, a := scriptInstant(c["issued_at"])
	expires, b := scriptInstant(c["expires_at"])
	if !a || !b || !expires.After(issued) || expires.Sub(issued) > time.Hour || issued.After(now.Add(5*time.Second)) || !expires.After(now) {
		return nil, errScriptContract
	}
	return c, nil
}

func scriptUniqueJSON(data []byte) error {
	d := json.NewDecoder(bytes.NewReader(data))
	d.UseNumber()
	var walk func(int) error
	walk = func(depth int) error {
		if depth >= 32 {
			return errScriptContract
		}
		token, err := d.Token()
		if err != nil {
			return errScriptContract
		}
		delimiter, container := token.(json.Delim)
		if !container {
			return nil
		}
		switch delimiter {
		case '{':
			seen := map[string]bool{}
			for d.More() {
				key, err := d.Token()
				name, ok := key.(string)
				if err != nil || !ok || seen[name] {
					return errScriptContract
				}
				seen[name] = true
				if walk(depth+1) != nil {
					return errScriptContract
				}
			}
			if end, err := d.Token(); err != nil || end != json.Delim('}') {
				return errScriptContract
			}
		case '[':
			for d.More() {
				if walk(depth+1) != nil {
					return errScriptContract
				}
			}
			if end, err := d.Token(); err != nil || end != json.Delim(']') {
				return errScriptContract
			}
		default:
			return errScriptContract
		}
		return nil
	}
	if walk(0) != nil {
		return errScriptContract
	}
	if _, err := d.Token(); err != io.EOF {
		return errScriptContract
	}
	return nil
}

func scriptSignedRaw(e Envelope, key ed25519.PublicKey) ([]byte, error) {
	const limit = 32768
	if len(e.Payload) > ((limit+2)/3)*4 || len(e.Signature) > 100 {
		return nil, errScriptContract
	}
	raw, err := base64.StdEncoding.Strict().DecodeString(e.Payload)
	sig, se := base64.StdEncoding.Strict().DecodeString(e.Signature)
	if err != nil || se != nil || len(raw) > limit || len(key) != ed25519.PublicKeySize || !ed25519.Verify(key, raw, sig) {
		return nil, errScriptContract
	}
	return raw, nil
}

func scriptBinding(x, c scriptObject, hash, kind string) bool {
	if !scriptInt(x["version"], 1, 1) || x["type"] != kind || !scriptDigest(hash) || x["payload_hash"] != hash {
		return false
	}
	for _, name := range []string{"agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint"} {
		if scriptString(x[name]) == "" || scriptString(x[name]) != scriptString(c[name]) {
			return false
		}
	}
	return true
}

// c must be the original verified dispatch; claims do not replace dispatch or OS consent.
func verifyScriptClaim(envelope Envelope, key ed25519.PublicKey, c scriptObject, hash, boot string, desktop, nonce *string, now time.Time) (scriptObject, error) {
	raw, err := scriptSignedRaw(envelope, key)
	if err != nil {
		return nil, err
	}
	x, err := scriptDecode(raw, 4096)
	if err != nil {
		return nil, err
	}
	_, ok := scriptKeys(x, "version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "payload_hash", "policy_version", "consent_mode", "artifact_sha256", "runtime_manifest_sha256", "boot_id", "desktop_session", "consent_nonce", "issued_at", "expires_at")
	if !ok || !scriptBinding(x, c, hash, "endpoint_script_claim") {
		return nil, errScriptContract
	}
	p, ok := scriptKeys(c["parameters"], "script_uuid", "script_version", "manifest", "inputs")
	if !ok || !scriptManifest(p["manifest"]) {
		return nil, errScriptContract
	}
	m := p["manifest"].(map[string]any)
	policy, ok := scriptKeys(c["policy"], "version", "enabled", "consent_mode")
	if !ok {
		return nil, errScriptContract
	}
	if !scriptUUID.MatchString(boot) || x["boot_id"] != boot || !scriptInt(x["policy_version"], 1, 9007199254740991) || x["policy_version"] != policy["version"] || x["consent_mode"] != policy["consent_mode"] || x["artifact_sha256"] != m["sha256"] || x["runtime_manifest_sha256"] != m["runtime_manifest_sha256"] {
		return nil, errScriptContract
	}
	if policy["consent_mode"] == "attended" {
		if desktop == nil || nonce == nil || !scriptUUID.MatchString(*desktop) || !scriptDigest(*nonce) || x["desktop_session"] != *desktop || x["consent_nonce"] != *nonce {
			return nil, errScriptContract
		}
	} else if policy["consent_mode"] != "unattended" || desktop != nil || nonce != nil || x["desktop_session"] != nil || x["consent_nonce"] != nil {
		return nil, errScriptContract
	}
	issued, a := scriptInstant(x["issued_at"])
	expires, b := scriptInstant(x["expires_at"])
	start, d := scriptInstant(c["issued_at"])
	end, e := scriptInstant(c["expires_at"])
	if !a || !b || !d || !e || issued.Before(start) || issued.After(now.Add(time.Second)) || !expires.After(now) || !expires.After(issued) || expires.Sub(issued) > 30*time.Second || expires.After(end) {
		return nil, errScriptContract
	}
	return x, nil
}
func verifyScriptCancellation(envelope Envelope, key ed25519.PublicKey, c scriptObject, hash string, now time.Time) (scriptObject, error) {
	raw, err := scriptSignedRaw(envelope, key)
	if err != nil {
		return nil, err
	}
	x, err := scriptDecode(raw, 4096)
	if err != nil {
		return nil, err
	}
	_, ok := scriptKeys(x, "version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "payload_hash", "issued_at", "expires_at")
	if !ok || !scriptBinding(x, c, hash, "endpoint_script_cancel") {
		return nil, errScriptContract
	}
	issued, a := scriptInstant(x["issued_at"])
	expires, b := scriptInstant(x["expires_at"])
	start, d := scriptInstant(c["issued_at"])
	if !a || !b || !d || issued.Before(start) || issued.After(now.Add(time.Second)) || !expires.After(now) || !expires.After(issued) || expires.Sub(issued) > time.Hour {
		return nil, errScriptContract
	}
	return x, nil
}
func validScriptResult(x, c scriptObject, hash string) bool {
	_, ok := scriptKeys(x, "command_uuid", "payload_hash", "status", "mutation_started", "reason_code", "exit_code", "boot_id", "output_sha256", "output_bytes", "output_truncated")
	if !ok || !scriptDigest(hash) || scriptString(x["command_uuid"]) == "" || scriptString(x["command_uuid"]) != scriptString(c["command_uuid"]) || x["payload_hash"] != hash || !scriptUUID.MatchString(scriptString(x["boot_id"])) {
		return false
	}
	started, s := x["mutation_started"].(bool)
	truncated, t := x["output_truncated"].(bool)
	status, sok := x["status"].(string)
	reason, rok := x["reason_code"].(string)
	p, ok := scriptKeys(c["parameters"], "script_uuid", "script_version", "manifest", "inputs")
	if !s || !t || !sok || !rok || !ok || !scriptManifest(p["manifest"]) {
		return false
	}
	policy := p["manifest"].(map[string]any)["execution_policy"].(map[string]any)
	if !scriptInt(x["output_bytes"], 0, scriptInteger(policy["output_bytes"])) {
		return false
	}
	count := scriptInteger(x["output_bytes"])
	if (count == 0 && x["output_sha256"] != nil) || (count > 0 && !scriptDigest(x["output_sha256"])) || (truncated && !started) {
		return false
	}
	if x["exit_code"] != nil && (!started || !scriptInt(x["exit_code"], 0, 4294967295)) {
		return false
	}
	if !started && (count != 0 || x["output_sha256"] != nil || truncated) {
		return false
	}
	if status == "declined" || status == "cancelled" || status == "expired" {
		return !started && x["exit_code"] == nil && reason == status
	}
	if status == "uncertain" {
		if reason == "interrupted" || reason == "state_unavailable" {
			return x["exit_code"] == nil
		}
		return started && (reason == "timed_out" || reason == "cancellation_requested")
	}
	if !started {
		if status != "failed" || x["exit_code"] != nil {
			return false
		}
		switch reason {
		case "artifact_invalid", "interpreter_unavailable", "input_invalid", "policy_unavailable", "consent_lost", "operation_failed":
			return true
		}
		return false
	}
	if x["exit_code"] == nil {
		return false
	}
	success := false
	for _, code := range policy["success_exit_codes"].([]any) {
		success = success || scriptInteger(code) == scriptInteger(x["exit_code"])
	}
	if !success {
		return status == "failed" && reason == "exit_failed"
	}
	expected := "completed"
	if policy["expected_observation_schema"] != nil {
		expected = "awaiting_observation"
	}
	return status == expected && reason == "completed"
}
