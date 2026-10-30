package agent

import (
	"context"
	"encoding/json"
)

type serviceActionHTTP struct {
	client     *Client
	credential string
}

func (t serviceActionHTTP) Poll(ctx context.Context) (*Envelope, error) {
	var raw json.RawMessage
	e := t.client.remoteRequestLimited(ctx, "GET", "/api/endpoints/agent/commands", t.credential, nil, &raw, 0, 32768)
	if e != nil {
		return nil, e
	}
	var fields map[string]json.RawMessage
	if json.Unmarshal(raw, &fields) != nil || len(fields) != 1 {
		return nil, errServiceAction
	}
	command, ok := fields["command"]
	if !ok {
		return nil, errServiceAction
	}
	if string(command) == "null" {
		return nil, nil
	}
	if !serviceClosedObject(command, []string{"payload", "signature"}) {
		return nil, errServiceAction
	}
	var envelope Envelope
	if json.Unmarshal(command, &envelope) != nil {
		return nil, errServiceAction
	}
	return &envelope, nil
}
func (t serviceActionHTTP) Claim(ctx context.Context, c ServiceCommand, hash, desktop, nonce string) (Envelope, error) {
	var reply Envelope
	request := struct {
		CommandUUID string `json:"command_uuid"`
		PayloadHash string `json:"payload_hash"`
		Consent     *struct {
			DesktopSession string `json:"desktop_session"`
			ConsentNonce   string `json:"consent_nonce"`
		} `json:"consent,omitempty"`
	}{CommandUUID: c.CommandUUID, PayloadHash: hash}
	if c.Policy.ConsentMode == "attended" {
		request.Consent = &struct {
			DesktopSession string `json:"desktop_session"`
			ConsentNonce   string `json:"consent_nonce"`
		}{desktop, nonce}
	}
	e := t.client.remoteRequestLimited(ctx, "POST", "/api/endpoints/agent/commands/"+c.AttemptUUID+"/claim", t.credential, request, &reply, 1024, 8192)
	return reply, e
}
func (t serviceActionHTTP) Result(ctx context.Context, c ServiceCommand, result ServiceResult) error {
	var reply struct {
		CommandUUID string `json:"command_uuid"`
		AttemptUUID string `json:"attempt_uuid"`
		PayloadHash string `json:"payload_hash"`
		Accepted    bool   `json:"accepted"`
	}
	e := t.client.remoteRequestLimited(ctx, "POST", "/api/endpoints/agent/commands/"+c.AttemptUUID+"/result", t.credential, result, &reply, 2048, 1024)
	if e != nil {
		return e
	}
	if !reply.Accepted || reply.CommandUUID != c.CommandUUID || reply.AttemptUUID != c.AttemptUUID || reply.PayloadHash != result.PayloadHash {
		return errServiceAction
	}
	return nil
}
