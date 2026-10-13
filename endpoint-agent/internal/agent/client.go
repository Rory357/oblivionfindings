package agent

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var credentialPattern = regexp.MustCompile("^[a-f0-9]{64}$")
var uuidPattern = regexp.MustCompile("^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$")

func hexCredential(v string) bool { return credentialPattern.MatchString(v) }

type Client struct {
	base string
	key  ed25519.PublicKey
	http *http.Client
}
type Enrollment struct {
	AgentUUID string `json:"agent_uuid"`
	ServerKey string `json:"server_public_key"`
}
type Acknowledgement struct {
	SoftwareAccepted            []SoftwareAcceptance           `json:"software_observations_accepted,omitempty"`
	RestartAccepted             []RestartAcceptance            `json:"restart_observations_accepted,omitempty"`
	ServiceObservationsAccepted []ServiceObservationAcceptance `json:"service_action_observations_accepted,omitempty"`
	AgentUUID                   string                         `json:"agent_uuid"`
	Sequence                    uint64                         `json:"acknowledged_sequence"`
	ServerTime                  string                         `json:"server_time"`
	NextSeconds                 int                            `json:"next_heartbeat_seconds"`
}
type HTTPError struct{ Status int }

func (e *HTTPError) Error() string { return http.StatusText(e.Status) }

func NewClient(server, key string) (*Client, error) {
	u, err := url.Parse(server)
	if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.Opaque != "" || u.ForceQuery || u.RawQuery != "" || u.Fragment != "" || u.RawPath != "" || strings.ContainsAny(server, "?#") || (u.Path != "" && u.Path != "/") || strings.HasSuffix(u.Host, ":") {
		return nil, errors.New("server must be an HTTPS origin without credentials, path, query or fragment")
	}
	if u.Port() != "" {
		port, err := strconv.Atoi(u.Port())
		if err != nil || port < 1 || port > 65535 {
			return nil, errors.New("invalid server port")
		}
	}
	decoded, err := base64.StdEncoding.Strict().DecodeString(key)
	if err != nil || len(decoded) != ed25519.PublicKeySize {
		return nil, errors.New("invalid server signing key")
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.ResponseHeaderTimeout = 15 * time.Second
	return &Client{base: strings.TrimRight(server, "/"), key: decoded, http: &http.Client{
		Transport: transport, Timeout: 30 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}}, nil
}

// BeginEnrollment persists the client secret before contacting the server, so a lost reply is retryable.
func BeginEnrollment(store *Store, server, key, token string) (State, error) {
	return beginEnrollment(store, server, key, token, "")
}

func beginEnrollment(store *Store, server, key, token, expectedUUID string) (State, error) {
	if expectedUUID != "" && !uuidPattern.MatchString(expectedUUID) {
		return State{}, errors.New("invalid installation identity")
	}
	if _, err := NewClient(server, key); err != nil {
		return State{}, err
	}
	if !hexCredential(token) {
		return State{}, errors.New("invalid enrollment token")
	}
	if _, err := store.Load(); !osNotExist(err) {
		return State{}, errors.New("existing state requires explicit approved recovery")
	}
	random := make([]byte, 32)
	if _, err := rand.Read(random); err != nil {
		return State{}, err
	}
	state := State{Version: 1, ServerURL: server, ServerKey: key, Credential: hex.EncodeToString(random), EnrollmentToken: token, ExpectedAgentUUID: expectedUUID}
	if err := store.Save(state); err != nil {
		return State{}, err
	}
	return state, nil
}

func (c *Client) Enroll(ctx context.Context, store *Store, state *State, platform string) error {
	if state.AgentUUID != "" {
		return nil
	}
	if platform != "windows" {
		return errors.New("unsupported platform")
	}
	if !hexCredential(state.EnrollmentToken) || !hexCredential(state.Credential) {
		return errors.New("enrollment state is incomplete")
	}
	body, _ := json.Marshal(map[string]string{"credential": state.Credential, "platform": platform})
	var reply Enrollment
	if err := c.post(ctx, "/api/endpoints/agent/enrol", state.EnrollmentToken, body, &reply); err != nil {
		return err
	}
	if !uuidPattern.MatchString(reply.AgentUUID) || reply.ServerKey != state.ServerKey || (state.ExpectedAgentUUID != "" && reply.AgentUUID != state.ExpectedAgentUUID) {
		return errors.New("server identity did not match enrollment")
	}
	next := *state
	next.AgentUUID = reply.AgentUUID
	next.EnrollmentToken = ""
	if err := store.Save(next); err != nil {
		return err
	}
	*state = next
	return nil
}

func (c *Client) Heartbeat(ctx context.Context, state State, payload []byte) (Acknowledgement, error) {
	var ack Acknowledgement
	if !uuidPattern.MatchString(state.AgentUUID) {
		return ack, errors.New("agent is not enrolled")
	}
	if err := c.post(ctx, "/api/endpoints/agent/heartbeat", state.Credential, payload, &ack); err != nil {
		return ack, err
	}
	if ack.AgentUUID != state.AgentUUID || ack.Sequence != state.Sequence || ack.NextSeconds < 30 || ack.NextSeconds > 300 {
		return Acknowledgement{}, errors.New("invalid heartbeat acknowledgement")
	}
	if validateSoftwareACK(payload, ack.SoftwareAccepted) != nil {
		return Acknowledgement{}, errors.New("invalid software acknowledgement")
	}
	if validateRestartACK(payload, ack.RestartAccepted) != nil {
		return Acknowledgement{}, errors.New("invalid restart acknowledgement")
	}
	if _, err := acceptedServiceObservations(payload, ack.ServiceObservationsAccepted); err != nil {
		return Acknowledgement{}, errors.New("invalid service observation acknowledgement")
	}
	if _, err := time.Parse(time.RFC3339, ack.ServerTime); err != nil {
		return Acknowledgement{}, errors.New("invalid server time")
	}
	return ack, nil
}

func (c *Client) post(ctx context.Context, path, credential string, body []byte, reply any) error {
	if !hexCredential(credential) || len(body) > 1024*1024 {
		return errors.New("invalid agent request")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.base+path, bytes.NewReader(body))
	if err != nil {
		return errors.New("cannot prepare agent request")
	}
	req.Header.Set("Authorization", "Bearer "+credential)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	response, err := c.http.Do(req)
	if err != nil {
		return errors.New("agent connection failed")
	} // no URL, body, token or upstream detail in logs
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return &HTTPError{Status: response.StatusCode}
	}
	data, err := io.ReadAll(io.LimitReader(response.Body, 1024*1024+1))
	if err != nil || len(data) > 1024*1024 {
		return errors.New("invalid agent response size")
	}
	if path == "/api/endpoints/agent/heartbeat" && (uniqueServiceJournal(data) != nil || (!validateServiceObservationACKJSON(data) || !validateRestartACKJSON(data) || !validateSoftwareACKJSON(data))) {
		return errors.New("invalid service observation acknowledgement")
	}
	if err := json.Unmarshal(data, reply); err != nil {
		return errors.New("invalid agent response")
	}
	return nil
}
