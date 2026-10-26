package agent

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"mime"
	"net/http"
	"time"
)

// Prepared transport only. No agent loop advertises or starts a script executor.
// The controller must still bind assignment, installed runtime and OS consent.
type scriptHTTP struct {
	client     *Client
	credential string
	agentUUID  string
	clock      func() time.Time
}
type scriptPoll struct{ Command, Cancellation *Envelope }

func (t scriptHTTP) now() time.Time {
	if t.clock != nil {
		return t.clock()
	}
	return time.Now()
}
func scriptWireEnvelope(value any) (Envelope, error) {
	x, ok := scriptKeys(value, "payload", "signature")
	if !ok {
		return Envelope{}, errScriptContract
	}
	p, pok := x["payload"].(string)
	s, sok := x["signature"].(string)
	if !pok || !sok || p == "" || s == "" {
		return Envelope{}, errScriptContract
	}
	return Envelope{Payload: p, Signature: s}, nil
}
func (t scriptHTTP) original(envelope Envelope, fresh bool) (scriptObject, string, error) {
	if t.client == nil || t.client.http == nil || !hexCredential(t.credential) || !scriptUUID.MatchString(t.agentUUID) {
		return nil, "", errScriptContract
	}
	raw, err := scriptSignedRaw(envelope, t.client.key)
	if err != nil {
		return nil, "", errScriptContract
	}
	decoded, err := scriptDecode(raw, 32768)
	if err != nil {
		return nil, "", errScriptContract
	}
	at := t.now()
	if !fresh {
		var ok bool
		at, ok = scriptInstant(decoded["issued_at"])
		if !ok {
			return nil, "", errScriptContract
		}
	}
	command, err := verifyScriptCommand(envelope, t.client.key, t.agentUUID, at)
	if err != nil {
		return nil, "", errScriptContract
	}
	sum := sha256.Sum256(raw)
	return command, hex.EncodeToString(sum[:]), nil
}
func (t scriptHTTP) Poll(ctx context.Context) (scriptPoll, error) {
	raw, err := t.request(ctx, "GET", "/api/endpoints/agent/scripts", nil, "", "", 49152, "application/json")
	if err != nil {
		return scriptPoll{}, err
	}
	x, err := scriptDecode(raw, 49152)
	if err != nil {
		return scriptPoll{}, errScriptContract
	}
	_, ok := scriptKeys(x, "command", "cancellation")
	if !ok || (x["command"] != nil && x["cancellation"] != nil) {
		return scriptPoll{}, errScriptContract
	}
	p := scriptPoll{}
	if x["command"] != nil {
		e, err := scriptWireEnvelope(x["command"])
		if err != nil {
			return p, err
		}
		if _, _, err = t.original(e, true); err != nil {
			return p, err
		}
		p.Command = &e
	}
	if x["cancellation"] != nil {
		e, err := scriptWireEnvelope(x["cancellation"])
		if err != nil {
			return scriptPoll{}, err
		}
		data, err := scriptSignedRaw(e, t.client.key)
		if err != nil {
			return scriptPoll{}, errScriptContract
		}
		stop, err := scriptDecode(data, 4096)
		if err != nil {
			return scriptPoll{}, errScriptContract
		}
		_, ok := scriptKeys(stop, "version", "type", "agent_uuid", "command_uuid", "attempt_uuid", "assignment_fingerprint", "payload_hash", "issued_at", "expires_at")
		issued, a := scriptInstant(stop["issued_at"])
		expires, b := scriptInstant(stop["expires_at"])
		if !ok || !scriptInt(stop["version"], 1, 1) || stop["type"] != "endpoint_script_cancel" || stop["agent_uuid"] != t.agentUUID ||
			!scriptUUID.MatchString(scriptString(stop["command_uuid"])) || !scriptUUID.MatchString(scriptString(stop["attempt_uuid"])) ||
			!scriptDigest(stop["assignment_fingerprint"]) || !scriptDigest(stop["payload_hash"]) || !a || !b ||
			issued.After(t.now().Add(time.Second)) || !expires.After(t.now()) || !expires.After(issued) || expires.Sub(issued) > time.Hour {
			return scriptPoll{}, errScriptContract
		}
		// Must be matched to the durable original before it can affect local work.
		p.Cancellation = &e
	}
	return p, nil
}
func (t scriptHTTP) Cancellation(original, stop Envelope) (scriptObject, error) {
	command, hash, err := t.original(original, false)
	if err != nil {
		return nil, err
	}
	return verifyScriptCancellation(stop, t.client.key, command, hash, t.now())
}
func (t scriptHTTP) Artifact(ctx context.Context, original Envelope) ([]byte, error) {
	command, _, err := t.original(original, true)
	if err != nil {
		return nil, err
	}
	manifest := command["parameters"].(scriptObject)["manifest"].(scriptObject)
	raw, err := t.request(ctx, "GET", "/api/endpoints/agent/scripts/"+scriptString(command["attempt_uuid"])+"/artifact", nil, "", "", int(scriptInteger(manifest["size_bytes"])), "application/octet-stream")
	if err != nil {
		return nil, err
	}
	if !scriptArtifact(manifest, raw) {
		return nil, errScriptContract
	}
	if _, _, err = t.original(original, true); err != nil {
		return nil, err
	}
	// The caller owns protected staging; bytes are never executed or written here.
	return raw, nil
}
func (t scriptHTTP) Claim(ctx context.Context, original Envelope, boot string, desktop, nonce *string) (Envelope, error) {
	command, hash, err := t.original(original, true)
	if err != nil {
		return Envelope{}, err
	}
	if !scriptUUID.MatchString(boot) {
		return Envelope{}, errScriptContract
	}
	manifest := command["parameters"].(scriptObject)["manifest"].(scriptObject)
	payload := scriptObject{"command_uuid": command["command_uuid"], "payload_hash": hash, "artifact_sha256": manifest["sha256"], "runtime_manifest_sha256": manifest["runtime_manifest_sha256"], "boot_id": boot}
	if command["policy"].(scriptObject)["consent_mode"] == "attended" {
		if desktop == nil || nonce == nil || !scriptUUID.MatchString(*desktop) || !scriptDigest(*nonce) {
			return Envelope{}, errScriptContract
		}
		payload["consent"] = scriptObject{"desktop_session": *desktop, "consent_nonce": *nonce}
	} else if desktop != nil || nonce != nil {
		return Envelope{}, errScriptContract
	}
	body, err := json.Marshal(payload)
	if err != nil || len(body) > 8192 {
		return Envelope{}, errScriptContract
	}
	raw, err := t.request(ctx, "POST", "/api/endpoints/agent/scripts/"+scriptString(command["attempt_uuid"])+"/claim", body, "application/json", "", 8192, "application/json")
	if err != nil {
		return Envelope{}, err
	}
	x, err := scriptDecode(raw, 8192)
	if err != nil {
		return Envelope{}, errScriptContract
	}
	envelope, err := scriptWireEnvelope(x)
	if err != nil {
		return Envelope{}, err
	}
	if _, err = verifyScriptClaim(envelope, t.client.key, command, hash, boot, desktop, nonce, t.now()); err != nil {
		return Envelope{}, errScriptContract
	}
	return envelope, nil
}
func (t scriptHTTP) Result(ctx context.Context, original Envelope, result scriptObject) error {
	command, hash, err := t.original(original, false)
	if err != nil {
		return err
	}
	if !validScriptResult(result, command, hash) {
		return errScriptContract
	}
	body, err := json.Marshal(result)
	if err != nil || len(body) > 8192 {
		return errScriptContract
	}
	raw, err := t.request(ctx, "POST", "/api/endpoints/agent/scripts/"+scriptString(command["attempt_uuid"])+"/result", body, "application/json", "", 1024, "application/json")
	if err != nil {
		return err
	}
	ack, err := scriptDecode(raw, 1024)
	if err != nil {
		return errScriptContract
	}
	_, ok := scriptKeys(ack, "command_uuid", "attempt_uuid", "payload_hash", "accepted")
	if !ok || ack["accepted"] != true || ack["command_uuid"] != command["command_uuid"] || ack["attempt_uuid"] != command["attempt_uuid"] || ack["payload_hash"] != hash {
		return errScriptContract
	}
	return nil
}
func (t scriptHTTP) Output(ctx context.Context, original Envelope, result scriptObject, body []byte) (scriptObject, error) {
	command, hash, err := t.original(original, false)
	if err != nil {
		return nil, err
	}
	body = append([]byte(nil), body...)
	header, err := scriptOutputUpload(result, command, hash, body)
	if err != nil {
		return nil, err
	}
	metadata, err := json.Marshal(header)
	if err != nil || len(metadata) > 1024 {
		return nil, errScriptContract
	}
	raw, err := t.request(ctx, "POST", "/api/endpoints/agent/scripts/"+scriptString(command["attempt_uuid"])+"/output", body, "application/octet-stream", base64.StdEncoding.EncodeToString(metadata), 4096, "application/json")
	if err != nil {
		return nil, err
	}
	return verifyScriptOutputReceipt(raw, result, command, hash, t.now())
}
func (t scriptHTTP) request(ctx context.Context, method, path string, body []byte, contentType, outputHeader string, limit int, responseType string) ([]byte, error) {
	if t.client == nil || t.client.http == nil || !hexCredential(t.credential) || !scriptUUID.MatchString(t.agentUUID) || limit < 0 || limit > 262144 || len(body) > 65536 {
		return nil, errScriptContract
	}
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, method, t.client.base+path, bytes.NewReader(body))
	if err != nil {
		return nil, errScriptContract
	}
	req.Header.Set("Authorization", "Bearer "+t.credential)
	req.Header.Set("Accept", responseType)
	req.Header.Set("Accept-Encoding", "identity")
	if contentType != "" {
		req.Header.Set("Content-Type", contentType)
	}
	if outputHeader != "" {
		req.Header.Set("X-Endpoint-Script-Output", outputHeader)
	}
	client := *t.client.http
	client.Jar = nil
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	response, err := client.Do(req)
	if err != nil {
		return nil, errScriptContract
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return nil, &HTTPError{Status: response.StatusCode}
	}
	media, _, err := mime.ParseMediaType(response.Header.Get("Content-Type"))
	if err != nil || media != responseType || response.Uncompressed || response.Header.Get("Content-Encoding") != "" || response.ContentLength > int64(limit) {
		return nil, errScriptContract
	}
	raw, err := io.ReadAll(io.LimitReader(response.Body, int64(limit+1)))
	if err != nil || len(raw) > limit {
		return nil, errScriptContract
	}
	return raw, nil
}
