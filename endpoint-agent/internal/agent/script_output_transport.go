package agent

import (
	"crypto/sha256"
	"encoding/hex"
	"time"
	"unicode/utf8"
)

// Output upload is separate from result acknowledgement and carries exact private
// bytes. This builder grants no execution and performs no network or disk I/O.
func scriptOutputUpload(result, command scriptObject, hash string, body []byte) (scriptObject, error) {
	if !validScriptResult(result, command, hash) || result["mutation_started"] != true || !utf8.Valid(body) ||
		int64(len(body)) != scriptInteger(result["output_bytes"]) {
		return nil, errScriptContract
	}
	if len(body) != 0 {
		sum := sha256.Sum256(body)
		if hex.EncodeToString(sum[:]) != result["output_sha256"] {
			return nil, errScriptContract
		}
	}
	return scriptObject{"command_uuid": command["command_uuid"], "payload_hash": hash,
		"sha256": result["output_sha256"], "size_bytes": result["output_bytes"], "truncated": result["output_truncated"]}, nil
}

// A historical accepted receipt acknowledges the upload, even after retention
// expiry. It is never evidence that content remains available or a job stopped.
func verifyScriptOutputReceipt(raw []byte, result, command scriptObject, hash string, now time.Time) (scriptObject, error) {
	receipt, err := scriptDecode(raw, 4096)
	if err != nil || !validScriptResult(result, command, hash) || result["mutation_started"] != true {
		return nil, errScriptContract
	}
	_, ok := scriptKeys(receipt, "command_uuid", "attempt_uuid", "payload_hash", "sha256", "size_bytes", "truncated", "received_at", "expires_at", "accepted")
	if !ok || receipt["accepted"] != true || receipt["command_uuid"] != command["command_uuid"] ||
		receipt["attempt_uuid"] != command["attempt_uuid"] || receipt["payload_hash"] != hash ||
		receipt["sha256"] != result["output_sha256"] || receipt["size_bytes"] != result["output_bytes"] || receipt["truncated"] != result["output_truncated"] {
		return nil, errScriptContract
	}
	received, receivedOK := scriptInstant(receipt["received_at"])
	expires, expiresOK := scriptInstant(receipt["expires_at"])
	issued, issuedOK := scriptInstant(command["issued_at"])
	parameters, _ := command["parameters"].(scriptObject)
	manifest, _ := parameters["manifest"].(scriptObject)
	policy, _ := manifest["execution_policy"].(scriptObject)
	retention := time.Duration(scriptInteger(policy["output_retention_hours"])) * time.Hour
	if !receivedOK || !expiresOK || !issuedOK || received.Before(issued) || received.After(now.Add(time.Second)) ||
		!expires.After(received) || expires.After(received.Add(retention)) {
		return nil, errScriptContract
	}
	return receipt, nil
}
