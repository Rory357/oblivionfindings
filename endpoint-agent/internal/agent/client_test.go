package agent

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func newTestStore(t *testing.T) *Store {
	t.Helper()
	s, err := OpenStore(filepath.Join(t.TempDir(), "private"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Close() })
	return s
}
func TestStateIsExclusiveAndPreservesLastCommittedIdentity(t *testing.T) {
	store := newTestStore(t)
	if second, err := OpenStore(store.dir); err == nil {
		second.Close()
		t.Fatal("two writers acquired agent state")
	}
	state := State{Version: 1, Credential: strings.Repeat("a", 64), AgentUUID: testAgentUUID, Sequence: 8, PendingHeartbeat: json.RawMessage("{\"sequence\":8}")}
	if err := store.Save(state); err != nil {
		t.Fatal(err)
	}
	loaded, err := store.Load()
	if err != nil || loaded.Sequence != 8 || loaded.AgentUUID != testAgentUUID {
		t.Fatalf("committed identity lost: %v", err)
	}
	if err := store.atomicWrite("../escape", []byte("bad")); err == nil {
		t.Fatal("state path escaped")
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(store.dir), "escape")); !os.IsNotExist(err) {
		t.Fatal("file escaped private directory")
	}
}
func TestEnrollmentLostReplyUsesTheSameCredentialAndErasesTokenAfterSuccess(t *testing.T) {
	store := newTestStore(t)
	pub, _ := testKey()
	key := base64.StdEncoding.EncodeToString(pub)
	token := strings.Repeat("b", 64)
	var firstCredential string
	calls := 0
	reply := false
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if r.URL.Path != "/api/endpoints/agent/enrol" || r.Header.Get("Authorization") != "Bearer "+token {
			t.Error("unexpected enrollment request")
		}
		var body map[string]string
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			t.Error(err)
		}
		if calls == 1 {
			firstCredential = body["credential"]
		} else if body["credential"] != firstCredential {
			t.Error("retry changed credential")
		}
		if !reply {
			w.WriteHeader(503)
			return
		}
		json.NewEncoder(w).Encode(Enrollment{AgentUUID: testAgentUUID, ServerKey: key})
	}))
	defer server.Close()
	state, err := BeginEnrollment(store, server.URL, key, token)
	if err != nil {
		t.Fatal(err)
	}
	client, _ := NewClient(server.URL, key)
	client.http.Transport = server.Client().Transport
	if err = client.Enroll(context.Background(), store, &state, "windows"); err == nil {
		t.Fatal("lost response was accepted")
	}
	recovered, err := store.Load()
	if err != nil {
		t.Fatal(err)
	}
	if recovered.Credential != firstCredential || recovered.EnrollmentToken != token || recovered.AgentUUID != "" {
		t.Fatal("retry identity was not durable")
	}
	reply = true
	if err = client.Enroll(context.Background(), store, &recovered, "windows"); err != nil {
		t.Fatal(err)
	}
	saved, _ := store.Load()
	if saved.EnrollmentToken != "" || saved.AgentUUID != testAgentUUID {
		t.Fatal("enrolment was not committed")
	}
	if _, err = BeginEnrollment(store, server.URL, key, token); err == nil {
		t.Fatal("existing identity silently replaced")
	}
}
func TestClientRejectsUnsafeOriginsAndDoesNotFollowCredentialRedirects(t *testing.T) {
	pub, _ := testKey()
	key := base64.StdEncoding.EncodeToString(pub)
	for _, url := range []string{"http://example.test", "https://user:secret@example.test", "https://example.test/?token=x", "https://example.test/#x", "https://example.test/path"} {
		if _, err := NewClient(url, key); err == nil {
			t.Errorf("unsafe origin accepted: %s", url)
		}
	}
	targetCalls := 0
	target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { targetCalls++ }))
	defer target.Close()
	origin := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, target.URL, http.StatusTemporaryRedirect)
	}))
	defer origin.Close()
	client, _ := NewClient(origin.URL, key)
	client.http.Transport = origin.Client().Transport
	var reply any
	err := client.post(context.Background(), "/api/endpoints/agent/heartbeat", strings.Repeat("a", 64), []byte("{}"), &reply)
	var status *HTTPError
	if !errors.As(err, &status) || status.Status != 307 || targetCalls != 0 {
		t.Fatalf("redirect leaked or was accepted: %v", err)
	}
}
func TestClientRejectsWrongIdentityAndNeverReturnsUpstreamSecrets(t *testing.T) {
	pub, _ := testKey()
	key := base64.StdEncoding.EncodeToString(pub)
	failed := false
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if failed {
			w.WriteHeader(403)
			w.Write([]byte("PRIVATE-UPSTREAM-SECRET"))
			return
		}
		json.NewEncoder(w).Encode(Acknowledgement{AgentUUID: "wrong", Sequence: 1, NextSeconds: 60, ServerTime: "2026-10-07T01:00:00Z"})
	}))
	defer server.Close()
	client, _ := NewClient(server.URL, key)
	client.http.Transport = server.Client().Transport
	state := State{AgentUUID: testAgentUUID, Credential: strings.Repeat("a", 64), Sequence: 1}
	if _, err := client.Heartbeat(context.Background(), state, []byte("{}")); err == nil {
		t.Fatal("wrong identity acknowledged")
	}
	failed = true
	if _, err := client.Heartbeat(context.Background(), state, []byte("{}")); err == nil || strings.Contains(err.Error(), "PRIVATE") {
		t.Fatal("unsafe error detail")
	}
}
func TestCorruptStateCannotBeReplacedByEnrollment(t *testing.T) {
	store := newTestStore(t)
	if err := store.atomicWrite("state.json", []byte("not JSON")); err != nil {
		t.Fatal(err)
	}
	pub, _ := testKey()
	if _, err := BeginEnrollment(store, "https://example.test", base64.StdEncoding.EncodeToString(pub), strings.Repeat("a", 64)); err == nil {
		t.Fatal("corrupt journal was silently replaced")
	}
}
