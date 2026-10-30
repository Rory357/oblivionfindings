package agent

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestRemoteTransportSeparatesApprovalFromExplicitDesktopActivation(t *testing.T) {
	offer, lease, _ := remoteFixture()
	now := time.Now().UTC().Truncate(time.Second)
	offer.IssuedAt = now.Format(time.RFC3339)
	offer.ExpiresAt = now.Add(time.Minute).Format(time.RFC3339)
	lease.Status = "allowed"
	lease.IssuedAt = offer.IssuedAt
	lease.ExpiresAt = now.Add(15 * time.Second).Format(time.RFC3339)
	stage := "offer"
	activate := false
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Error("missing bounded credential")
		}
		if strings.Contains(r.URL.Path, "offer") {
			if r.Method != "GET" {
				t.Error("offer not GET")
			}
			json.NewEncoder(w).Encode(map[string]any{"offer": remoteEnvelope(t, offer)})
			return
		}
		if stage == "lease" {
			body, _ := io.ReadAll(r.Body)
			var data map[string]any
			json.Unmarshal(body, &data)
			if data["activate"] != activate || data["desktop_session"] != lease.DesktopSession {
				t.Error("consent attempted activation")
			}
			json.NewEncoder(w).Encode(map[string]any{"status": lease.Status, "lease": remoteEnvelope(t, lease)})
			return
		}
		w.Write([]byte(`{"uuid":"wrong","status":"allowed"}`))
	}))
	defer server.Close()
	public, _ := testKey()
	c := &Client{base: server.URL, key: public, http: server.Client()}
	transport := remoteTransport{client: c, agent: testAgentUUID, credential: strings.Repeat("a", 64)}
	got, err := transport.Offer(context.Background())
	if err != nil || got.SessionUUID != offer.SessionUUID {
		t.Fatal(err)
	}
	if transport.Decide(context.Background(), offer, lease.DesktopSession, "allow") == nil {
		t.Fatal("wrong decision identity accepted")
	}
	stage = "lease"
	actual, err := transport.Lease(context.Background(), offer, lease.DesktopSession, false)
	if err != nil || actual.Status != "allowed" {
		t.Fatal(err)
	}
	activate = true
	lease.Status = "active"
	actual, err = transport.Lease(context.Background(), offer, lease.DesktopSession, true)
	if err != nil || actual.Status != "active" {
		t.Fatal("explicit activation rejected", err)
	}
}
func TestRemoteJSONRejectsAmbiguousAndUnboundedReplies(t *testing.T) {
	for _, value := range []string{`{"offer":null,"offer":null}`, `{"offer":{"payload":"x","payload":"y"}}`, `{"offer":null}{}`, `[]`, `{"a":{"b":{"c":{"d":{"e":1}}}}}`} {
		if uniqueRemoteJSON([]byte(value)) == nil {
			t.Fatal("ambiguous reply accepted")
		}
	}
	if uniqueRemoteJSON([]byte(`{"offer":null}`)) != nil {
		t.Fatal("empty offer rejected")
	}
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(strings.Repeat("x", 17000))) }))
	defer server.Close()
	public, _ := testKey()
	client := &Client{base: server.URL, key: public, http: server.Client()}
	var reply any
	if err := client.remoteRequest(context.Background(), "GET", "/api/endpoints/agent/remote/offer", strings.Repeat("a", 64), nil, &reply); err == nil || strings.Contains(err.Error(), "xxxxx") {
		t.Fatal("oversized reply leaked")
	}
}
