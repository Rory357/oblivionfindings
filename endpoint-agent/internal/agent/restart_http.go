package agent

import (
	"context"
	"encoding/json"
)

type restartPoll struct {
	Command      *Envelope `json:"command"`
	Cancellation *Envelope `json:"cancellation"`
}
type restartTransport interface {
	Poll(context.Context) (restartPoll, error)
	Claim(context.Context, RestartCommand, string, string, string, string, string) (Envelope, error)
	Result(context.Context, RestartCommand, RestartResult) error
}
type restartHTTP struct {
	client     *Client
	credential string
}

func (t restartHTTP) Poll(ctx context.Context) (restartPoll, error) {
	var raw json.RawMessage
	var p restartPoll
	e := t.client.remoteRequestLimited(ctx, "GET", "/api/endpoints/agent/restarts", t.credential, nil, &raw, 0, 32768)
	if e != nil {
		return p, e
	}
	var m map[string]json.RawMessage
	if uniqueRemoteJSON(raw) != nil || json.Unmarshal(raw, &m) != nil || len(m) != 2 || m["command"] == nil || m["cancellation"] == nil || json.Unmarshal(raw, &p) != nil {
		return p, errServiceAction
	}
	for _, n := range []string{"command", "cancellation"} {
		if string(m[n]) != "null" && !serviceClosedObject(m[n], []string{"payload", "signature"}) {
			return p, errServiceAction
		}
	}
	return p, nil
}
func (t restartHTTP) Claim(ctx context.Context, c RestartCommand, hash, boot, desktop, nonce, warning string) (Envelope, error) {
	var e Envelope
	request := map[string]any{"command_uuid": c.CommandUUID, "payload_hash": hash, "boot_id": boot, "warning_started_at": nil}
	if c.Policy.ConsentMode == "attended" {
		request["warning_started_at"] = warning
		request["consent"] = map[string]string{"desktop_session": desktop, "consent_nonce": nonce}
	}
	err := t.client.remoteRequestLimited(ctx, "POST", "/api/endpoints/agent/restarts/"+c.AttemptUUID+"/claim", t.credential, request, &e, 1024, 8192)
	return e, err
}
func (t restartHTTP) Result(ctx context.Context, c RestartCommand, result RestartResult) error {
	var raw json.RawMessage
	e := t.client.remoteRequestLimited(ctx, "POST", "/api/endpoints/agent/restarts/"+c.AttemptUUID+"/result", t.credential, result, &raw, 2048, 1024)
	if e != nil {
		return e
	}
	var ack struct {
		CommandUUID string `json:"command_uuid"`
		AttemptUUID string `json:"attempt_uuid"`
		PayloadHash string `json:"payload_hash"`
		Accepted    bool   `json:"accepted"`
	}
	if !serviceClosedObject(raw, []string{"command_uuid", "attempt_uuid", "payload_hash", "accepted"}) || json.Unmarshal(raw, &ack) != nil || ack.CommandUUID != c.CommandUUID || ack.AttemptUUID != c.AttemptUUID || ack.PayloadHash != result.PayloadHash || !ack.Accepted {
		return errServiceAction
	}
	return nil
}
