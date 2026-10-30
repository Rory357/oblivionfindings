package agent

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

func relayGrantFixture(offer RemoteOffer, desktop string, now time.Time) RemoteRelayGrant {
	expiry := now.Add(30 * time.Minute)
	return RemoteRelayGrant{Type: "remote_relay", Version: 1, AgentUUID: offer.AgentUUID, SessionUUID: offer.SessionUUID, DesktopSession: desktop, Role: "agent", AccessMode: offer.AccessMode,
		URLs: []string{"turns:relay.example.test:5349?transport=tcp"}, Username: strconv.FormatInt(expiry.Unix(), 10) + ":obli-agent-" + strings.Repeat("b", 64),
		Credential: base64.StdEncoding.EncodeToString([]byte(strings.Repeat("c", 20))), CredentialExpiresAt: expiry.Format(time.RFC3339),
		IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(15 * time.Second).Format(time.RFC3339)}
}
func TestRelaySignatureBindsDesktopRoleDestinationsAndLifetime(t *testing.T) {
	offer, lease, now := remoteFixture()
	grant := relayGrantFixture(offer, lease.DesktopSession, now)
	key, _ := testKey()
	got, err := VerifyRemoteRelay(remoteEnvelope(t, grant), key, offer, lease.DesktopSession, now)
	if err != nil || got.Username != grant.Username {
		t.Fatal("Valid relay rejected", err)
	}
	for label, modify := range map[string]func(*RemoteRelayGrant){
		"type":               func(g *RemoteRelayGrant) { g.Type = "remote_support_offer" },
		"agent":              func(g *RemoteRelayGrant) { g.AgentUUID = lease.DesktopSession },
		"session":            func(g *RemoteRelayGrant) { g.SessionUUID = lease.DesktopSession },
		"desktop":            func(g *RemoteRelayGrant) { g.DesktopSession = offer.SessionUUID },
		"role":               func(g *RemoteRelayGrant) { g.Role = "technician" },
		"mode":               func(g *RemoteRelayGrant) { g.AccessMode = "control" },
		"expired":            func(g *RemoteRelayGrant) { g.ExpiresAt = now.Format(time.RFC3339) },
		"long-grant":         func(g *RemoteRelayGrant) { g.ExpiresAt = now.Add(time.Minute).Format(time.RFC3339) },
		"credential-expired": func(g *RemoteRelayGrant) { g.CredentialExpiresAt = now.Format(time.RFC3339) },
		"credential-long":    func(g *RemoteRelayGrant) { g.CredentialExpiresAt = now.Add(121 * time.Minute).Format(time.RFC3339) },
		"identity-expiry":    func(g *RemoteRelayGrant) { g.Username = "123:obli-agent-" + strings.Repeat("b", 64) },
		"identity-role":      func(g *RemoteRelayGrant) { g.Username = strings.ReplaceAll(g.Username, "agent", "technician") },
		"credential":         func(g *RemoteRelayGrant) { g.Credential = "invalid" },
		"no-server":          func(g *RemoteRelayGrant) { g.URLs = nil },
		"stun":               func(g *RemoteRelayGrant) { g.URLs = []string{"stun:public.example.test:3478"} },
		"too-many":           func(g *RemoteRelayGrant) { g.URLs = make([]string, 5) },
	} {
		t.Run(label, func(t *testing.T) {
			bad := grant
			modify(&bad)
			if _, err := VerifyRemoteRelay(remoteEnvelope(t, bad), key, offer, lease.DesktopSession, now); err == nil {
				t.Fatal("unsafe relay accepted")
			}
		})
	}
}
func TestRelayHTTPFetchUsesPinnedDeploymentAndVerifiesSignedBinding(t *testing.T) {
	offer, lease, _ := remoteFixture()
	now := time.Now().UTC().Truncate(time.Second)
	grant := relayGrantFixture(offer, lease.DesktopSession, now)
	envelope := remoteEnvelope(t, grant)
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "GET" || r.URL.Path != "/api/endpoints/agent/remote/"+offer.SessionUUID+"/relay" || r.URL.Query().Get("desktop_session") != lease.DesktopSession ||
			r.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Error("wrong relay destination or identity")
		}
		json.NewEncoder(w).Encode(map[string]any{"status": "allowed", "relay": envelope})
	}))
	defer server.Close()
	key, _ := testKey()
	transport := remoteTransport{client: &Client{base: server.URL, key: key, http: server.Client()}, agent: offer.AgentUUID, credential: strings.Repeat("a", 64)}
	actual, err := transport.Relay(context.Background(), offer, lease.DesktopSession)
	if err != nil || actual.Credential != grant.Credential {
		t.Fatal("relay transport failed", err)
	}
}
