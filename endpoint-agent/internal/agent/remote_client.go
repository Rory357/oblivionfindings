package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"time"
)

// Remote calls have short independent deadlines. Private upstream errors never
// reach the interactive helper.
func (c *Client) remoteRequest(ctx context.Context, method, path, credential string, payload any, reply any) error {
	return c.remoteRequestLimited(ctx, method, path, credential, payload, reply, 8192, 16384)
}
func (c *Client) remoteRequestLimited(ctx context.Context, method, path, credential string, payload any, reply any, requestLimit, responseLimit int) error {
	if !hexCredential(credential) {
		return errRemote
	}
	var body []byte
	if payload != nil {
		var err error
		body, err = json.Marshal(payload)
		if err != nil || len(body) > requestLimit {
			return errRemote
		}
	}
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, method, c.base+path, bytes.NewReader(body))
	if err != nil {
		return errRemote
	}
	req.Header.Set("Authorization", "Bearer "+credential)
	req.Header.Set("Accept", "application/json")
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	response, err := c.http.Do(req)
	if err != nil {
		return errRemote
	}
	defer response.Body.Close()
	if response.StatusCode != 200 {
		return &HTTPError{Status: response.StatusCode}
	}
	data, err := io.ReadAll(io.LimitReader(response.Body, int64(responseLimit+1)))
	if err != nil || len(data) > responseLimit {
		return errRemote
	}
	if uniqueRemoteJSON(data) != nil {
		return errRemote
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if decoder.Decode(reply) != nil {
		return errRemote
	}
	return nil
}
func uniqueRemoteJSON(data []byte) error {
	d := json.NewDecoder(bytes.NewReader(data))
	var walk func(int) error
	walk = func(depth int) error {
		if depth > 4 {
			return errRemote
		}
		v, e := d.Token()
		if e != nil {
			return errRemote
		}
		if delimiter, ok := v.(json.Delim); ok {
			if delimiter != '{' {
				return errRemote
			}
			seen := map[string]bool{}
			for d.More() {
				key, e := d.Token()
				name, ok := key.(string)
				if e != nil || !ok || seen[name] {
					return errRemote
				}
				seen[name] = true
				if walk(depth+1) != nil {
					return errRemote
				}
			}
			if end, e := d.Token(); e != nil || end != json.Delim('}') {
				return errRemote
			}
		}
		return nil
	}
	if walk(0) != nil {
		return errRemote
	}
	if _, err := d.Token(); err != io.EOF {
		return errRemote
	}
	return nil
}

type RemoteTransport interface {
	Offer(context.Context) (*RemoteOffer, error)
	Decide(context.Context, RemoteOffer, string, string) error
	Lease(context.Context, RemoteOffer, string, bool) (RemoteLease, error)
	Stop(context.Context, string) error
}
type remoteTransport struct {
	client            *Client
	agent, credential string
}

func (t remoteTransport) Offer(ctx context.Context) (*RemoteOffer, error) {
	var reply struct {
		Offer *Envelope `json:"offer"`
	}
	if err := t.client.remoteRequest(ctx, http.MethodGet, "/api/endpoints/agent/remote/offer", t.credential, nil, &reply); err != nil {
		return nil, err
	}
	if reply.Offer == nil {
		return nil, nil
	}
	offer, err := VerifyRemoteOffer(*reply.Offer, t.client.key, t.agent, time.Now())
	if err != nil {
		return nil, err
	}
	return &offer, nil
}

type remoteDecisionReply struct {
	UUID             string  `json:"uuid"`
	DeviceID         int     `json:"device_id"`
	Status           string  `json:"status"`
	AccessMode       string  `json:"access_mode"`
	ConsentMode      string  `json:"consent_mode"`
	Recording        bool    `json:"recording"`
	RequestExpiresAt string  `json:"request_expires_at"`
	SessionExpiresAt string  `json:"session_expires_at"`
	StartedAt        *string `json:"started_at"`
	EndedAt          *string `json:"ended_at"`
	EndReason        *string `json:"end_reason"`
}

func (t remoteTransport) Decide(ctx context.Context, offer RemoteOffer, desktop, decision string) error {
	if !uuidPattern.MatchString(offer.SessionUUID) || !uuidPattern.MatchString(desktop) || (decision != "allow" && decision != "decline") {
		return errRemote
	}
	var reply remoteDecisionReply
	err := t.client.remoteRequest(ctx, http.MethodPost, "/api/endpoints/agent/remote/"+offer.SessionUUID+"/decision", t.credential, map[string]string{"decision": decision, "challenge": offer.Challenge, "desktop_session": desktop}, &reply)
	if err != nil {
		return err
	}
	expected := "allowed"
	if decision == "decline" {
		expected = "declined"
	}
	if reply.UUID != offer.SessionUUID || reply.Status != expected || reply.AccessMode != offer.AccessMode || reply.ConsentMode != offer.ConsentMode || reply.Recording {
		return errRemote
	}
	return nil
}
func (t remoteTransport) Lease(ctx context.Context, offer RemoteOffer, desktop string, activate bool) (RemoteLease, error) {
	if !uuidPattern.MatchString(offer.SessionUUID) || !uuidPattern.MatchString(desktop) {
		return RemoteLease{}, errRemote
	}
	var reply struct {
		Status string    `json:"status"`
		Lease  *Envelope `json:"lease"`
	}
	// Activation is requested only for a ready channel and a live OS-bound desktop worker.
	err := t.client.remoteRequest(ctx, http.MethodPost, "/api/endpoints/agent/remote/"+offer.SessionUUID+"/lease", t.credential, map[string]any{"desktop_session": desktop, "activate": activate}, &reply)
	if err != nil {
		return RemoteLease{}, err
	}
	if reply.Lease == nil {
		return RemoteLease{}, errRemote
	}
	lease, err := VerifyRemoteLease(*reply.Lease, t.client.key, offer, desktop, time.Now())
	expected := "allowed"
	if activate {
		expected = "active"
	}
	if err != nil || reply.Status != lease.Status || lease.Status != expected {
		return RemoteLease{}, errRemote
	}
	return lease, nil
}
func (t remoteTransport) Stop(ctx context.Context, id string) error {
	if !uuidPattern.MatchString(id) {
		return errRemote
	}
	var reply struct {
		UUID   string `json:"uuid"`
		Status string `json:"status"`
	}
	if err := t.client.remoteRequest(ctx, http.MethodPost, "/api/endpoints/agent/remote/"+id+"/stop", t.credential, map[string]any{}, &reply); err != nil {
		var status *HTTPError
		if errors.As(err, &status) && (status.Status == 401 || status.Status == 403 || status.Status == 404) {
			return nil
		}
		return err
	}
	if reply.UUID != id || (reply.Status != "stopped" && reply.Status != "declined" && reply.Status != "expired") {
		return errRemote
	}
	return nil
}
