package agent

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func profileFixture() Bootstrap {
	pub, _ := testKey()
	return Bootstrap{Version: 1, Platform: "windows", ServerURL: "https://care.example.nz", ServerKey: base64.StdEncoding.EncodeToString(pub), AgentUUID: testAgentUUID, Token: strings.Repeat("b", 64), ExpiresAt: time.Now().Add(15 * time.Minute).UTC().Format(time.RFC3339Nano), Organisation: "Pilot Care", DeviceLabel: "Reception PC", DeviceUID: "PILOT-01"}
}
func profileBytes(t *testing.T, p Bootstrap) []byte {
	t.Helper()
	data, err := json.Marshal(p)
	if err != nil {
		t.Fatal(err)
	}
	return data
}
func TestBootstrapRejectsAmbiguousExpiredAndUnboundedProfiles(t *testing.T) {
	profile := profileFixture()
	data := profileBytes(t, profile)
	parsed, err := ReadBootstrap(bytes.NewReader(data), time.Now())
	if err != nil || parsed != profile {
		t.Fatalf("valid profile failed: %v", err)
	}
	malformed := [][]byte{
		[]byte("[]"), []byte("{"),
		append(append([]byte{}, data...), []byte(" null")...),
		[]byte(strings.Repeat(" ", MaxBootstrapBytes+1)),
		[]byte(strings.Replace(string(data), "{", "{\"version\":1,", 1)),
		[]byte(strings.Replace(string(data), "{", "{\"unknown\":true,", 1)),
		append([]byte{0xff}, data...),
	}
	for i, input := range malformed {
		if _, err := ReadBootstrap(bytes.NewReader(input), time.Now()); err == nil {
			t.Errorf("malformed case %d accepted", i)
		}
	}
	cases := map[string]func(*Bootstrap){
		"old version":     func(p *Bootstrap) { p.Version = 2 },
		"Linux":           func(p *Bootstrap) { p.Platform = "linux" },
		"expired":         func(p *Bootstrap) { p.ExpiresAt = time.Now().Add(-time.Second).Format(time.RFC3339Nano) },
		"too long":        func(p *Bootstrap) { p.ExpiresAt = time.Now().Add(time.Hour).Format(time.RFC3339Nano) },
		"bad expiry":      func(p *Bootstrap) { p.ExpiresAt = "tomorrow" },
		"http":            func(p *Bootstrap) { p.ServerURL = "http://care.example.nz" },
		"path":            func(p *Bootstrap) { p.ServerURL += "/other" },
		"duplicate slash": func(p *Bootstrap) { p.ServerURL += "//" },
		"empty fragment":  func(p *Bootstrap) { p.ServerURL += "#" },
		"empty port":      func(p *Bootstrap) { p.ServerURL += ":" },
		"empty query":     func(p *Bootstrap) { p.ServerURL += "?" },
		"port zero":       func(p *Bootstrap) { p.ServerURL += ":0" },
		"port overflow":   func(p *Bootstrap) { p.ServerURL += ":65536" },
		"credentials":     func(p *Bootstrap) { p.ServerURL = "https://user:secret@care.example.nz" },
		"bad key":         func(p *Bootstrap) { p.ServerKey = "bad" },
		"bad target":      func(p *Bootstrap) { p.AgentUUID = "other-device" },
		"bad token":       func(p *Bootstrap) { p.Token = "short" },
		"empty label":     func(p *Bootstrap) { p.DeviceLabel = "" },
		"terminal escape": func(p *Bootstrap) { p.Organisation = "Care\x1b[31m" },
		"bidi override":   func(p *Bootstrap) { p.DeviceLabel = "Care\u202e" },
		"long label":      func(p *Bootstrap) { p.DeviceUID = strings.Repeat("A", 301) },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			p := profile
			mutate(&p)
			if _, err := ReadBootstrap(bytes.NewReader(profileBytes(t, p)), time.Now()); err == nil {
				t.Fatal("invalid profile accepted")
			}
		})
	}
}
func TestBootstrapKeepsPendingIdentityAndRejectsRepointing(t *testing.T) {
	store := newTestStore(t)
	profile := profileFixture()
	original, err := PrepareBootstrap(store, profile)
	if err != nil {
		t.Fatal(err)
	}
	retry, err := PrepareBootstrap(store, profile)
	if err != nil || retry.Credential != original.Credential || retry.ExpectedAgentUUID != profile.AgentUUID {
		t.Fatal("pending identity changed")
	}
	for _, mutate := range []func(*Bootstrap){
		func(p *Bootstrap) { p.ServerURL = "https://different.example.nz" },
		func(p *Bootstrap) { p.Token = strings.Repeat("c", 64) },
		func(p *Bootstrap) { p.AgentUUID = "cccccccc-cccc-4ccc-8ccc-cccccccccccc" },
	} {
		changed := profile
		mutate(&changed)
		if _, err := PrepareBootstrap(store, changed); err == nil {
			t.Fatal("existing installation repointed")
		}
	}
	saved, err := store.Load()
	if err != nil || saved.Credential != original.Credential || saved.ServerURL != original.ServerURL {
		t.Fatal("failed profile changed saved state")
	}
	saved.AgentUUID = profile.AgentUUID
	saved.EnrollmentToken = ""
	if err = store.Save(saved); err != nil {
		t.Fatal(err)
	}
	if _, err = PrepareBootstrap(store, profile); err == nil {
		t.Fatal("enrolled installation was replaced")
	}
}
func TestBootstrapRequiresTheExpectedServerReplyIdentity(t *testing.T) {
	profile := profileFixture()
	replyUUID := "cccccccc-cccc-4ccc-8ccc-cccccccccccc"
	calls := 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		json.NewEncoder(w).Encode(Enrollment{AgentUUID: replyUUID, ServerKey: profile.ServerKey})
	}))
	defer server.Close()
	profile.ServerURL = server.URL
	store := newTestStore(t)
	state, err := PrepareBootstrap(store, profile)
	if err != nil {
		t.Fatal(err)
	}
	originalCredential := state.Credential
	client, _ := NewClient(profile.ServerURL, profile.ServerKey)
	client.http.Transport = server.Client().Transport
	if err = client.Enroll(context.Background(), store, &state, "linux"); err == nil || calls != 0 {
		t.Fatal("Linux computer sent an enrolment")
	}
	if err = client.Enroll(context.Background(), store, &state, "windows"); err == nil {
		t.Fatal("wrong computer identity accepted")
	}
	pending, _ := store.Load()
	if pending.AgentUUID != "" || pending.EnrollmentToken != profile.Token || pending.Credential != originalCredential {
		t.Fatal("rejected reply altered enrolment state")
	}
	replyUUID = profile.AgentUUID
	if err = client.Enroll(context.Background(), store, &pending, "windows"); err != nil {
		t.Fatal(err)
	}
	saved, _ := store.Load()
	if saved.AgentUUID != profile.AgentUUID || saved.EnrollmentToken != "" || saved.Credential != originalCredential {
		t.Fatal("enrolment not committed correctly")
	}
}
func TestStateRejectsTrailingAndOversizedData(t *testing.T) {
	store := newTestStore(t)
	state := State{Version: 1, Credential: strings.Repeat("a", 64)}
	data, _ := json.Marshal(state)
	for _, suffix := range []string{" {}", strings.Repeat(" ", 2*1024*1024) + "X"} {
		if err := store.atomicWrite("state.json", append(data, []byte(suffix)...)); err != nil {
			t.Fatal(err)
		}
		if _, err := store.Load(); err == nil {
			t.Fatal("invalid saved state accepted")
		}
	}
}
