package agent

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/pion/webrtc/v4"
	"oblivion.local/endpoint-agent/internal/rtc"
)

type RemoteRelayGrant struct {
	Type                string   `json:"type"`
	Version             int      `json:"version"`
	AgentUUID           string   `json:"agent_uuid"`
	SessionUUID         string   `json:"session_uuid"`
	DesktopSession      string   `json:"desktop_session"`
	Role                string   `json:"role"`
	AccessMode          string   `json:"access_mode"`
	URLs                []string `json:"urls"`
	Username            string   `json:"username"`
	Credential          string   `json:"credential"`
	CredentialExpiresAt string   `json:"credential_expires_at"`
	IssuedAt            string   `json:"issued_at"`
	ExpiresAt           string   `json:"expires_at"`
}

func VerifyRemoteRelay(envelope Envelope, key ed25519.PublicKey, offer RemoteOffer, desktop string, now time.Time) (RemoteRelayGrant, error) {
	var relay RemoteRelayGrant
	fields := []string{"type", "version", "agent_uuid", "session_uuid", "desktop_session", "role", "access_mode", "urls", "username", "credential", "credential_expires_at", "issued_at", "expires_at"}
	if remotePayloadLimited(envelope, key, fields, &relay, 8192) != nil {
		return RemoteRelayGrant{}, errRemote
	}
	if relay.Type != "remote_relay" || relay.Version != 1 || relay.AgentUUID != offer.AgentUUID || relay.SessionUUID != offer.SessionUUID ||
		relay.DesktopSession != desktop || !uuidPattern.MatchString(desktop) || relay.Role != "agent" || relay.AccessMode != offer.AccessMode ||
		!remoteWindow(relay.IssuedAt, relay.ExpiresAt, now, 15*time.Second) || len(relay.URLs) < 1 || len(relay.URLs) > 4 {
		return RemoteRelayGrant{}, errRemote
	}
	for _, endpoint := range relay.URLs {
		if !rtc.ValidRelayURL(endpoint) {
			return RemoteRelayGrant{}, errRemote
		}
	}
	credentialExpiry, err := time.Parse("2006-01-02T15:04:05Z", relay.CredentialExpiresAt)
	grantExpiry, _ := time.Parse("2006-01-02T15:04:05Z", relay.ExpiresAt)
	if err != nil || !credentialExpiry.After(now) || credentialExpiry.Before(grantExpiry) || credentialExpiry.Sub(now) > 120*time.Minute {
		return RemoteRelayGrant{}, errRemote
	}
	prefix := strconv.FormatInt(credentialExpiry.Unix(), 10) + ":obli-agent-"
	if !strings.HasPrefix(relay.Username, prefix) || !hexCredential(strings.TrimPrefix(relay.Username, prefix)) {
		return RemoteRelayGrant{}, errRemote
	}
	credential, err := base64.StdEncoding.Strict().DecodeString(relay.Credential)
	if err != nil || len(credential) != 20 {
		return RemoteRelayGrant{}, errRemote
	}
	return relay, nil
}

func (g RemoteRelayGrant) servers() []webrtc.ICEServer {
	return []webrtc.ICEServer{{URLs: append([]string(nil), g.URLs...), Username: g.Username, Credential: g.Credential}}
}
func (t remoteTransport) Relay(ctx context.Context, offer RemoteOffer, desktop string) (RemoteRelayGrant, error) {
	if !uuidPattern.MatchString(offer.SessionUUID) || !uuidPattern.MatchString(desktop) {
		return RemoteRelayGrant{}, errRemote
	}
	var reply struct {
		Status string    `json:"status"`
		Relay  *Envelope `json:"relay"`
	}
	err := t.client.remoteRequest(ctx, http.MethodGet, "/api/endpoints/agent/remote/"+offer.SessionUUID+"/relay?desktop_session="+url.QueryEscape(desktop), t.credential, nil, &reply)
	if err != nil {
		return RemoteRelayGrant{}, err
	}
	if reply.Relay == nil || (reply.Status != "allowed" && reply.Status != "active") {
		return RemoteRelayGrant{}, errRemote
	}
	return VerifyRemoteRelay(*reply.Relay, t.client.key, offer, desktop, time.Now())
}
