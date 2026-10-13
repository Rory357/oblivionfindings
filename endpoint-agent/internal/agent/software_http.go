package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"time"
)

type softwarePoll struct {
	Command *Envelope `json:"command"`
}
type softwareTransport interface {
	Poll(context.Context) (softwarePoll, error)
	Artifact(context.Context, SoftwareCommand, *os.File) error
	Claim(context.Context, SoftwareCommand, string, string, string, string) (Envelope, error)
	Result(context.Context, SoftwareCommand, SoftwareResult) error
}
type softwareHTTP struct {
	client     *Client
	credential string
}

func (t softwareHTTP) Poll(ctx context.Context) (softwarePoll, error) {
	var raw json.RawMessage
	var p softwarePoll
	e := t.client.remoteRequestLimited(ctx, "GET", "/api/endpoints/agent/software", t.credential, nil, &raw, 0, 32768)
	if e != nil {
		return p, e
	}
	if !serviceClosedObject(raw, []string{"command"}) { // command is deliberately nullable
		var m map[string]json.RawMessage
		if uniqueRemoteJSON(raw) != nil || json.Unmarshal(raw, &m) != nil || len(m) != 1 || string(m["command"]) != "null" {
			return p, errServiceAction
		}
	}
	if json.Unmarshal(raw, &p) != nil {
		return p, errServiceAction
	}
	if p.Command != nil {
		var m map[string]json.RawMessage
		_ = json.Unmarshal(raw, &m)
		if !serviceClosedObject(m["command"], []string{"payload", "signature"}) {
			return p, errServiceAction
		}
	}
	return p, nil
}
func (t softwareHTTP) Artifact(ctx context.Context, c SoftwareCommand, dst *os.File) error {
	req, e := http.NewRequestWithContext(ctx, "GET", t.client.base+"/api/endpoints/agent/software/"+c.AttemptUUID+"/artifact", nil)
	if e != nil {
		return e
	}
	req.Header.Set("Authorization", "Bearer "+t.credential)
	req.Header.Set("Accept", "application/octet-stream")
	req.Header.Set("Accept-Encoding", "identity")
	downloadClient := *t.client.http
	downloadClient.Timeout = 5 * time.Minute
	reply, e := downloadClient.Do(req)
	if e != nil {
		return e
	}
	defer reply.Body.Close()
	if reply.StatusCode != 200 || reply.Header.Get("Content-Encoding") != "" || (reply.ContentLength >= 0 && reply.ContentLength != c.Parameters.SizeBytes) {
		return errServiceAction
	}
	h := sha256.New()
	n, e := io.Copy(io.MultiWriter(dst, h), io.LimitReader(reply.Body, c.Parameters.SizeBytes+1))
	if e != nil || n != c.Parameters.SizeBytes || hex.EncodeToString(h.Sum(nil)) != c.Parameters.SHA256 {
		return errServiceAction
	}
	return dst.Sync()
}
func (t softwareHTTP) Claim(ctx context.Context, c SoftwareCommand, hash, boot, desktop, nonce string) (Envelope, error) {
	var e Envelope
	m := map[string]any{"command_uuid": c.CommandUUID, "payload_hash": hash, "artifact_sha256": c.Parameters.SHA256, "boot_id": boot}
	if c.Policy.ConsentMode == "attended" {
		m["consent"] = map[string]string{"desktop_session": desktop, "consent_nonce": nonce}
	}
	err := t.client.remoteRequestLimited(ctx, "POST", "/api/endpoints/agent/software/"+c.AttemptUUID+"/claim", t.credential, m, &e, 1024, 8192)
	return e, err
}
func (t softwareHTTP) Result(ctx context.Context, c SoftwareCommand, x SoftwareResult) error {
	var raw json.RawMessage
	e := t.client.remoteRequestLimited(ctx, "POST", "/api/endpoints/agent/software/"+c.AttemptUUID+"/result", t.credential, x, &raw, 2048, 1024)
	if e != nil {
		return e
	}
	var a struct {
		CommandUUID string `json:"command_uuid"`
		AttemptUUID string `json:"attempt_uuid"`
		PayloadHash string `json:"payload_hash"`
		Accepted    bool   `json:"accepted"`
	}
	if !serviceClosedObject(raw, []string{"command_uuid", "attempt_uuid", "payload_hash", "accepted"}) || json.Unmarshal(raw, &a) != nil || a.CommandUUID != c.CommandUUID || a.AttemptUUID != c.AttemptUUID || a.PayloadHash != x.PayloadHash || !a.Accepted {
		return errServiceAction
	}
	return nil
}
