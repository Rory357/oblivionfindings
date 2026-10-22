package agent

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"net/url"
	"os"
	"reflect"
	"strings"
	"testing"
	"time"
)

type scriptHTTPFixture struct {
	PublicKey    string `json:"public_key"`
	AgentUUID    string `json:"agent_uuid"`
	Now          string
	Command      Envelope
	Claim        Envelope
	Artifact     string
	ClaimRequest json.RawMessage `json:"claim_request"`
	Result       json.RawMessage
	Ack          json.RawMessage
	Header       json.RawMessage
	Receipt      json.RawMessage
	BodyBase64   string `json:"body_base64"`
	Name         string
}

func scriptLoadHTTPFixture(t *testing.T, env string, dst any) {
	t.Helper()
	path := os.Getenv(env)
	if path == "" {
		t.Skip("original PHP producer required: " + env)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if json.Unmarshal(raw, dst) != nil {
		t.Fatal("invalid original producer")
	}
}
func scriptTLSClient(t *testing.T, f scriptHTTPFixture, clock func() time.Time, handler http.HandlerFunc) (scriptHTTP, *httptest.Server) {
	t.Helper()
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+strings.Repeat("d", 64) || r.Header.Get("Accept-Encoding") != "identity" || r.Header.Get("Cookie") != "" || r.URL.RawQuery != "" {
			t.Error("private request boundary")
		}
		w.Header().Set("Content-Type", "application/json")
		handler(w, r)
	}))
	t.Cleanup(server.Close)
	client, err := NewClient(server.URL, f.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	client.http.Transport = server.Client().Transport
	return scriptHTTP{client: client, credential: strings.Repeat("d", 64), agentUUID: f.AgentUUID, clock: clock}, server
}
func scriptRawObject(t *testing.T, raw []byte) scriptObject {
	t.Helper()
	x, err := scriptDecode(raw, 32768)
	if err != nil {
		t.Fatal(err)
	}
	return x
}
func scriptEnvelopePayload(t *testing.T, e Envelope) scriptObject {
	t.Helper()
	raw, err := base64.StdEncoding.DecodeString(e.Payload)
	if err != nil {
		t.Fatal(err)
	}
	return scriptRawObject(t, raw)
}

func TestScriptTLSCommandLifecycle(t *testing.T) {
	var f scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_TRANSPORT_EXPORT", &f)
	at, err := time.Parse(time.RFC3339, f.Now)
	if err != nil {
		t.Fatal(err)
	}
	request := scriptRawObject(t, f.ClaimRequest)
	consent := request["consent"].(scriptObject)
	boot := scriptString(request["boot_id"])
	desktop := scriptString(consent["desktop_session"])
	nonce := scriptString(consent["consent_nonce"])
	artifact, _ := base64.StdEncoding.DecodeString(f.Artifact)
	command := scriptEnvelopePayload(t, f.Command)
	prefix := "/api/endpoints/agent/scripts/" + scriptString(command["attempt_uuid"])
	calls := 0
	leaseDelayed := false
	tr, _ := scriptTLSClient(t, f, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) {
		calls++
		body, _ := io.ReadAll(r.Body)
		switch r.URL.Path {
		case "/api/endpoints/agent/scripts":
			if r.Method != "GET" || len(body) != 0 {
				t.Error("poll body or method")
			}
			json.NewEncoder(w).Encode(map[string]any{"command": f.Command, "cancellation": nil})
		case prefix + "/artifact":
			if r.Method != "GET" || len(body) != 0 {
				t.Error("artifact body or method")
			}
			w.Header().Set("Content-Type", "application/octet-stream")
			w.Write(artifact)
		case prefix + "/claim":
			if r.Method != "POST" || r.Header.Get("Content-Type") != "application/json" || !reflect.DeepEqual(scriptRawObject(t, body), request) {
				t.Error("claim changed original input")
			}
			if leaseDelayed {
				at = at.Add(time.Hour)
			}
			json.NewEncoder(w).Encode(f.Claim)
		default:
			t.Error("unexpected route")
			w.WriteHeader(404)
		}
	})
	p, err := tr.Poll(t.Context())
	if err != nil || p.Command == nil || *p.Command != f.Command || p.Cancellation != nil {
		t.Fatal("original poll", err)
	}
	got, err := tr.Artifact(t.Context(), *p.Command)
	if err != nil || !bytes.Equal(got, artifact) {
		t.Fatal("exact artifact", err)
	}
	claim, err := tr.Claim(t.Context(), *p.Command, boot, &desktop, &nonce)
	if err != nil || claim != f.Claim {
		t.Fatal("original claim", err)
	}
	if calls != 3 {
		t.Fatal("unexpected request count")
	}
	bad := "bad"
	if _, err = tr.Claim(t.Context(), f.Command, boot, &desktop, &bad); err == nil || calls != 3 {
		t.Fatal("bad consent sent")
	}
	leaseDelayed = true
	if _, err = tr.Claim(t.Context(), f.Command, boot, &desktop, &nonce); err == nil {
		t.Fatal("lease expired in transit accepted")
	}
	previous := calls
	if _, err = tr.Artifact(t.Context(), f.Command); err == nil || calls != previous {
		t.Fatal("expired artifact requested")
	}
}

func TestScriptTLSHistoricalResults(t *testing.T) {
	for _, env := range []string{"OBLI_SCRIPT_RESULT_EXPORT", "OBLI_SCRIPT_UNATTENDED_EXPORT"} {
		t.Run(env, func(t *testing.T) {
			var f scriptHTTPFixture
			scriptLoadHTTPFixture(t, env, &f)
			claim := scriptEnvelopePayload(t, f.Claim)
			at, _ := scriptInstant(claim["issued_at"])
			result := scriptRawObject(t, f.Result)
			command := scriptEnvelopePayload(t, f.Command)
			prefix := "/api/endpoints/agent/scripts/" + scriptString(command["attempt_uuid"])
			calls := 0
			tr, _ := scriptTLSClient(t, f, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) {
				calls++
				body, _ := io.ReadAll(r.Body)
				x := scriptRawObject(t, body)
				if r.URL.Path == prefix+"/result" {
					if !reflect.DeepEqual(x, result) {
						t.Error("changed result")
					}
					w.Write(f.Ack)
					return
				}
				if r.URL.Path != prefix+"/claim" {
					t.Error("unexpected route")
					w.WriteHeader(404)
					return
				}
				unattended := claim["consent_mode"] == "unattended"
				if (unattended && len(x) != 5) || (!unattended && len(x) != 6) || x["boot_id"] != claim["boot_id"] || x["payload_hash"] != claim["payload_hash"] {
					t.Error("claim binding")
				}
				json.NewEncoder(w).Encode(f.Claim)
			})
			var desktop, nonce *string
			if claim["consent_mode"] == "attended" {
				d := scriptString(claim["desktop_session"])
				n := scriptString(claim["consent_nonce"])
				desktop = &d
				nonce = &n
			}
			if _, err := tr.Claim(t.Context(), f.Command, scriptString(claim["boot_id"]), desktop, nonce); err != nil {
				t.Fatal(err)
			}
			at = at.Add(365 * 24 * time.Hour)
			if err := tr.Result(t.Context(), f.Command, result); err != nil {
				t.Fatal("historical result", err)
			}
			if err := tr.Result(t.Context(), f.Command, result); err != nil || calls != 3 {
				t.Fatal("lost response replay", err)
			}
			bad := scriptRawObject(t, f.Result)
			bad["payload_hash"] = strings.Repeat("f", 64)
			if err := tr.Result(t.Context(), f.Command, bad); err == nil || calls != 3 {
				t.Fatal("foreign result sent")
			}
		})
	}
}

func TestScriptTLSOriginalOutput(t *testing.T) {
	var fixtures []scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_OUTPUT_HTTP_EXPORT", &fixtures)
	if len(fixtures) != 10 {
		t.Fatal("missing native cases")
	}
	for _, f := range fixtures {
		t.Run(f.Name, func(t *testing.T) {
			receipt := scriptRawObject(t, f.Receipt)
			at, _ := scriptInstant(receipt["received_at"])
			body, _ := base64.StdEncoding.DecodeString(f.BodyBase64)
			result := scriptRawObject(t, f.Result)
			expected := scriptRawObject(t, f.Header)
			calls := 0
			tr, _ := scriptTLSClient(t, f, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) {
				calls++
				data, _ := io.ReadAll(r.Body)
				metadata, err := base64.StdEncoding.Strict().DecodeString(r.Header.Get("X-Endpoint-Script-Output"))
				if err != nil || r.Method != "POST" || r.URL.Path != "/api/endpoints/agent/scripts/"+scriptString(receipt["attempt_uuid"])+"/output" || r.Header.Get("Content-Type") != "application/octet-stream" || !bytes.Equal(data, body) || !reflect.DeepEqual(scriptRawObject(t, metadata), expected) {
					t.Error("exact private upload changed")
				}
				w.Write(f.Receipt)
			})
			for i := 0; i < 2; i++ {
				if _, err := tr.Output(t.Context(), f.Command, result, body); err != nil {
					t.Fatal("output receipt", err)
				}
				at = at.Add(365 * 24 * time.Hour)
			}
			if _, err := tr.Output(t.Context(), f.Command, result, append(append([]byte{}, body...), 0)); err == nil || calls != 2 {
				t.Fatal("changed bytes sent")
			}
		})
	}
}

func TestScriptTLSCancellation(t *testing.T) {
	var f struct {
		scriptHTTPFixture
		First, Renewed Envelope
		FirstTime      string `json:"first_time"`
		RenewedTime    string `json:"renewed_time"`
	}
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_CANCEL_EXPORT", &f)
	at, _ := time.Parse(time.RFC3339, f.FirstTime)
	stop := f.First
	tr, _ := scriptTLSClient(t, f.scriptHTTPFixture, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]any{"command": nil, "cancellation": stop})
	})
	for i := 0; i < 2; i++ {
		p, err := tr.Poll(t.Context())
		if err != nil || p.Command != nil || p.Cancellation == nil {
			t.Fatal(err)
		}
		if _, err = tr.Cancellation(f.Command, *p.Cancellation); err != nil {
			t.Fatal("historical stop binding", err)
		}
		stop = f.Renewed
		at, _ = time.Parse(time.RFC3339, f.RenewedTime)
	}
	var other scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_TRANSPORT_EXPORT", &other)
	if _, err := tr.Cancellation(other.Command, stop); err == nil {
		t.Fatal("stop bound to unrelated original")
	}
}

func TestScriptTLSPrivateFailureBoundaries(t *testing.T) {
	var f scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_TRANSPORT_EXPORT", &f)
	at, _ := time.Parse(time.RFC3339, f.Now)
	poll, _ := json.Marshal(map[string]any{"command": f.Command, "cancellation": nil})
	for _, tc := range []struct {
		name, body, encoding, media string
		status                      int
	}{
		{"idle", `{"command":null,"cancellation":null}`, "", "application/json", 200},
		{"extra", `{"command":null,"cancellation":null,"extra":1}`, "", "application/json", 200},
		{"duplicate", `{"command":null,"command":null,"cancellation":null}`, "", "application/json", 200},
		{"trailing", `{"command":null,"cancellation":null} {}`, "", "application/json", 200},
		{"missing", `{"command":null}`, "", "application/json", 200},
		{"large", strings.Repeat("x", 49153), "", "application/json", 200},
		{"compressed", string(poll), "gzip", "application/json", 200},
		{"wrong media", string(poll), "", "text/html", 200},
		{"unauthorized", "PRIVATE-UPSTREAM-SECRET", "", "application/json", 401},
		{"conflict", "PRIVATE-UPSTREAM-SECRET", "", "application/json", 409},
		{"expired output", "PRIVATE-UPSTREAM-SECRET", "", "application/json", 410},
		{"unavailable", "PRIVATE-UPSTREAM-SECRET", "", "application/json", 503},
	} {
		t.Run(tc.name, func(t *testing.T) {
			tr, _ := scriptTLSClient(t, f, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", tc.media)
				if tc.encoding != "" {
					w.Header().Set("Content-Encoding", tc.encoding)
				}
				w.WriteHeader(tc.status)
				io.WriteString(w, tc.body)
			})
			_, err := tr.Poll(t.Context())
			if tc.name == "idle" {
				if err != nil {
					t.Fatal(err)
				}
				return
			}
			if err == nil || strings.Contains(err.Error(), "PRIVATE") {
				t.Fatal("private or invalid response accepted", err)
			}
			if tc.status != 200 {
				var status *HTTPError
				if !errors.As(err, &status) || status.Status != tc.status {
					t.Fatal("lost safe HTTP status")
				}
			}
		})
	}
	t.Run("redirect cookies and cancellation", func(t *testing.T) {
		targetCalls := 0
		target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { targetCalls++ }))
		defer target.Close()
		originCalls := 0
		tr, server := scriptTLSClient(t, f, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) {
			originCalls++
			w.Header().Set("Location", target.URL)
			w.WriteHeader(307)
		})
		jar, _ := cookiejar.New(nil)
		u, _ := url.Parse(server.URL)
		jar.SetCookies(u, []*http.Cookie{{Name: "private", Value: "forbidden"}})
		tr.client.http.Jar = jar
		followed := 0
		tr.client.http.CheckRedirect = func(*http.Request, []*http.Request) error { followed++; return nil }
		if _, err := tr.Poll(t.Context()); err == nil || targetCalls != 0 || followed != 0 || originCalls != 1 {
			t.Fatal("redirect followed")
		}
		ctx, cancel := context.WithCancel(t.Context())
		cancel()
		if _, err := tr.Poll(ctx); err == nil || originCalls != 1 {
			t.Fatal("cancelled request sent")
		}
		invalid := tr
		invalid.credential = "bad"
		if _, err := invalid.Poll(t.Context()); err == nil || originCalls != 1 {
			t.Fatal("invalid credential sent")
		}
	})
}

func TestScriptTLSRejectsChangedMaterialAndReceipts(t *testing.T) {
	var f scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_TRANSPORT_EXPORT", &f)
	at, _ := time.Parse(time.RFC3339, f.Now)
	input := scriptRawObject(t, f.ClaimRequest)
	consent := input["consent"].(scriptObject)
	desktop := scriptString(consent["desktop_session"])
	nonce := scriptString(consent["consent_nonce"])
	artifact, _ := base64.StdEncoding.DecodeString(f.Artifact)
	for _, kind := range []string{"artifact bytes", "claim signature", "poll signature", "both poll kinds", "foreign agent", "expired dispatch"} {
		t.Run(kind, func(t *testing.T) {
			bad := f.Claim
			bad.Signature = strings.Repeat("A", 88)
			tr, _ := scriptTLSClient(t, f, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) {
				switch kind {
				case "artifact bytes":
					w.Header().Set("Content-Type", "application/octet-stream")
					changed := append([]byte{}, artifact...)
					changed[0] ^= 1
					w.Write(changed)
				case "claim signature":
					json.NewEncoder(w).Encode(bad)
				default:
					command := f.Command
					var cancel any
					if kind == "poll signature" {
						command.Signature = bad.Signature
					}
					if kind == "both poll kinds" {
						cancel = f.Claim
					}
					json.NewEncoder(w).Encode(map[string]any{"command": command, "cancellation": cancel})
				}
			})
			var err error
			switch kind {
			case "artifact bytes":
				_, err = tr.Artifact(t.Context(), f.Command)
			case "claim signature":
				_, err = tr.Claim(t.Context(), f.Command, scriptString(input["boot_id"]), &desktop, &nonce)
			default:
				if kind == "foreign agent" {
					tr.agentUUID = "11111111-1111-4111-8111-111111111111"
				}
				if kind == "expired dispatch" {
					tr.clock = func() time.Time { return at.Add(time.Hour) }
				}
				_, err = tr.Poll(t.Context())
			}
			if err == nil {
				t.Fatal("changed material accepted")
			}
		})
	}
	var resultFixture scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_RESULT_EXPORT", &resultFixture)
	tr, _ := scriptTLSClient(t, resultFixture, func() time.Time { return at.Add(24 * time.Hour) }, func(w http.ResponseWriter, r *http.Request) {
		ack := scriptRawObject(t, resultFixture.Ack)
		ack["accepted"] = false
		json.NewEncoder(w).Encode(ack)
	})
	if err := tr.Result(t.Context(), resultFixture.Command, scriptRawObject(t, resultFixture.Result)); err == nil {
		t.Fatal("false result acknowledgement accepted")
	}
	var outputs []scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_OUTPUT_HTTP_EXPORT", &outputs)
	out := outputs[0]
	receipt := scriptRawObject(t, out.Receipt)
	received, _ := scriptInstant(receipt["received_at"])
	output, _ := scriptTLSClient(t, out, func() time.Time { return received }, func(w http.ResponseWriter, r *http.Request) {
		receipt["attempt_uuid"] = "11111111-1111-4111-8111-111111111111"
		json.NewEncoder(w).Encode(receipt)
	})
	body, _ := base64.StdEncoding.DecodeString(out.BodyBase64)
	if _, err := output.Output(t.Context(), out.Command, scriptRawObject(t, out.Result), body); err == nil {
		t.Fatal("foreign output receipt accepted")
	}
}

func TestScriptTLSInFlightCancellation(t *testing.T) {
	var f scriptHTTPFixture
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_TRANSPORT_EXPORT", &f)
	at, _ := time.Parse(time.RFC3339, f.Now)
	entered := make(chan struct{})
	released := make(chan struct{})
	tr, _ := scriptTLSClient(t, f, func() time.Time { return at }, func(w http.ResponseWriter, r *http.Request) { close(entered); <-r.Context().Done(); close(released) })
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	done := make(chan error, 1)
	go func() { _, err := tr.Poll(ctx); done <- err }()
	select {
	case <-entered:
	case <-time.After(time.Second):
		t.Fatal("request did not arrive")
	}
	cancel()
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("cancelled request succeeded")
		}
	case <-time.After(time.Second):
		t.Fatal("request ignored cancellation")
	}
	select {
	case <-released:
	case <-time.After(time.Second):
		t.Fatal("server connection not cancelled")
	}
}
