package cctv

import (
	"bufio"
	"context"
	"crypto/md5"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/headers"
)

// External published vectors, not expected values computed by the implementation.
// https://www.rfc-editor.org/rfc/rfc7616.html#section-3.9.1
func TestHTTPDigestRFC7616Vectors(t *testing.T) {
	for algorithm, expected := range map[string]string{
		"MD5":     "8ca523f5e9506fed4657c9700eebdbec",
		"SHA-256": "753927fa0e85d155564e2e272a28d1802ca10daf4496794697cf8db5856cb6c1",
	} {
		t.Run(algorithm, func(t *testing.T) {
			challenge := httpDigestChallenge{"http-auth@example.org", "7ypf/xlj9XXwfDPEoM4URrv/xwf94BcCAzFZH4GiTo0v", "FQhe/qaU925kfnzjCev0ciny7QMkPqMAFRtzCUYo5tdS", algorithm}
			value := httpDigestAuthorization(challenge, httpsTunnelSource{Username: "Mufasa", Password: "Circle of Life", Path: "/dir/index.html"}, "GET", "00000001", "f2/wE4q74E6zIJEtWaHKaf5wv/H5QzzpXusqGemxURZJ")
			var parsed headers.Authorization
			if parsed.Unmarshal(base.HeaderValue{value}) != nil || parsed.Response != expected || parsed.URI != "/dir/index.html" {
				t.Fatal("published Digest vector mismatch")
			}
		})
	}
}

func TestHTTPDigestChallengesAreBoundedAndUnambiguous(t *testing.T) {
	good := `Digest realm="fixture",nonce="fixture-nonce",algorithm=SHA-256,qop="auth,auth-int",opaque="fixture-opaque"`
	for _, value := range []string{
		`Basic realm="fixture"`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-256,qop="auth-int"`,
		`Digest realm="fixture",nonce="fixture",nonce="other",algorithm=SHA-256,qop="auth"`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-256,qop="auth",userhash=true`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-256,qop="auth",charset=other`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-512-256,qop="auth"`,
		`Digest realm="fixture",nonce="",algorithm=SHA-256,qop="auth"`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-256,qop="auth",`,
		`Digest realm="fixture",nonce="fixture",algorithm=SHA-256,qop="auth", =ambiguous`,
		"Digest realm=\"fixture\",nonce=\"bad\r\nInjected: yes\",qop=\"auth\"",
	} {
		if _, err := selectHTTPDigest([]string{value}); err == nil {
			t.Fatalf("unsafe challenge accepted: %q", value)
		}
	}
	if _, err := selectHTTPDigest([]string{good, good, good, good, good}); err == nil {
		t.Fatal("challenge count unbounded")
	}
	challenge, err := selectHTTPDigest([]string{`Digest realm="fixture",nonce="fixture",algorithm=MD5,qop="auth"`, `Basic realm="fixture"`, good})
	if err != nil || challenge.algorithm != "SHA-256" || challenge.opaque != "fixture-opaque" {
		t.Fatal("strongest supported challenge not selected")
	}
}

type digestHTTPSFixture struct {
	bridgeTimeout     time.Duration
	server            *httptest.Server
	source            httpsTunnelSource
	roots             *x509.CertPool
	mode              string
	mu                sync.Mutex
	calls, authorized map[string]int
	get               net.Conn
	backend           string
	rtsp              net.Conn
	getCookie         string
	getDone           chan struct{}
	control           chan []byte
}

func fixtureHash(algorithm, value string) string {
	if algorithm == "SHA-256" {
		sum := sha256.Sum256([]byte(value))
		return hex.EncodeToString(sum[:])
	}
	sum := md5.Sum([]byte(value))
	return hex.EncodeToString(sum[:])
}

func newDigestHTTPSFixture(t *testing.T, mode string) *digestHTTPSFixture {
	t.Helper()
	fixture := &digestHTTPSFixture{mode: mode, calls: map[string]int{}, authorized: map[string]int{}, getDone: make(chan struct{}), control: make(chan []byte, 1)}
	fixture.server = httptest.NewUnstartedServer(http.HandlerFunc(fixture.serve))
	fixture.server.Config.ErrorLog = log.New(io.Discard, "", 0)
	fixture.server.StartTLS()
	parsed, err := url.Parse(fixture.server.URL)
	if err != nil {
		t.Fatal(err)
	}
	port, _ := strconv.Atoi(parsed.Port())
	pin := sha256.Sum256(fixture.server.Certificate().Raw)
	fixture.source = httpsTunnelSource{IP: parsed.Hostname(), ServerName: parsed.Hostname(), Pin: hex.EncodeToString(pin[:]), Port: port, Path: "/ISAPI/Streaming/channels/102", Username: "fixture-reader-auth", Password: "fixture-secret"}
	fixture.roots = x509.NewCertPool()
	fixture.roots.AddCert(fixture.server.Certificate())
	t.Cleanup(func() {
		fixture.mu.Lock()
		if fixture.rtsp != nil {
			_ = fixture.rtsp.Close()
		}
		if fixture.get != nil {
			_ = fixture.get.Close()
		}
		fixture.mu.Unlock()
		fixture.server.Close()
	})
	return fixture
}

func (f *digestHTTPSFixture) serve(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	f.calls[r.Method]++
	f.mu.Unlock()
	if r.URL.Path != f.source.Path || r.URL.RawQuery != "" || r.Host != net.JoinHostPort(f.source.ServerName, strconv.Itoa(f.source.Port)) {
		w.WriteHeader(404)
		return
	}
	if f.mode == "redirect" {
		w.Header().Set("Location", "https://foreign.example.test/steal")
		w.WriteHeader(302)
		return
	}
	if f.mode == "unchallenged" {
		w.Header().Set("Content-Type", "application/x-rtsp-tunnelled")
		w.WriteHeader(200)
		return
	}
	algorithm := "SHA-256"
	if f.mode == "md5" {
		algorithm = "MD5"
	}
	auth := r.Header.Get("Authorization")
	if auth == "" || f.mode == "repeat_401" || f.mode == "post_401" && r.Method == "POST" {
		challenge := `Digest realm="fixture",nonce="fixture-nonce",algorithm=` + algorithm + `,qop="auth",opaque="fixture-opaque"`
		if f.mode == "basic" {
			challenge = `Basic realm="fixture"`
		}
		if f.mode == "duplicate_nonce" {
			challenge += `,nonce="different"`
		}
		if f.mode == "huge_header" {
			challenge += `,extra="` + strings.Repeat("x", 8192) + `"`
		}
		w.Header().Set("WWW-Authenticate", challenge)
		w.Header().Set("Connection", "close")
		w.WriteHeader(401)
		return
	}
	var parsed headers.Authorization
	if parsed.Unmarshal(base.HeaderValue{auth}) != nil || parsed.Method != headers.AuthMethodDigest || parsed.Username != "fixture-reader-auth" || parsed.Realm != "fixture" || parsed.Nonce != "fixture-nonce" || parsed.URI != f.source.Path || parsed.Qop == nil || *parsed.Qop != "auth" || parsed.Cnonce == nil || parsed.Nc == nil || parsed.Opaque == nil || *parsed.Opaque != "fixture-opaque" {
		w.Header().Set("Connection", "close")
		w.WriteHeader(401)
		return
	}
	expectedNC := "00000001"
	if r.Method == "POST" {
		expectedNC = "00000002"
	}
	expected := fixtureHash(algorithm, fixtureHash(algorithm, "fixture-reader-auth:fixture:fixture-secret")+":fixture-nonce:"+expectedNC+":"+*parsed.Cnonce+":auth:"+fixtureHash(algorithm, r.Method+":"+f.source.Path))
	if *parsed.Nc != expectedNC || parsed.Response != expected {
		w.Header().Set("Connection", "close")
		w.WriteHeader(401)
		return
	}
	f.mu.Lock()
	f.authorized[r.Method]++
	if r.Method == "POST" && (f.getCookie == "" || r.Header.Get("X-Sessioncookie") != f.getCookie) {
		f.mu.Unlock()
		w.Header().Set("Connection", "close")
		w.WriteHeader(400)
		return
	}
	f.mu.Unlock()

	connection, buffered, err := w.(http.Hijacker).Hijack()
	if err != nil {
		return
	}
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(2 * time.Second))
	if f.mode == "post_no_response" && r.Method == "POST" {
		<-f.getDone
		return
	}
	if f.backend != "" {
		f.serveRTSPBridge(connection, buffered, r)
		return
	}
	if r.Method == "GET" {
		f.mu.Lock()
		f.get = connection
		f.getCookie = r.Header.Get("X-Sessioncookie")
		f.mu.Unlock()
		_, _ = io.WriteString(connection, "HTTP/1.1 200 OK\r\nContent-Type: application/x-rtsp-tunnelled\r\n\r\nRTSP-witness")
		select {
		case <-f.getDone:
		case <-time.After(2 * time.Second):
		}
		return
	}
	if r.Method != "POST" || r.ContentLength != 30000 || r.Header.Get("Content-Type") != "application/x-rtsp-tunnelled" {
		return
	}
	_, _ = io.WriteString(connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n")
	payload := make([]byte, len("OPTIONS camera RTSP/1.0\r\n\r\n"))
	if _, err = io.ReadFull(base64.NewDecoder(base64.StdEncoding, buffered.Reader), payload); err == nil {
		f.control <- payload
		f.mu.Lock()
		if f.get != nil {
			_, _ = io.WriteString(f.get, "media-witness")
		}
		f.mu.Unlock()
	}
	<-f.getDone
}

func TestAuthenticatedHTTPSRTSPTunnelBothConnectionsAndLifetime(t *testing.T) {
	for _, mode := range []string{"sha256", "md5"} {
		t.Run(mode, func(t *testing.T) {
			fixture := newDigestHTTPSFixture(t, mode)
			defer close(fixture.getDone)
			lifetime, cancelLifetime := context.WithCancel(context.Background())
			defer cancelLifetime()
			dial, cancelDial := context.WithTimeout(context.Background(), time.Second)
			tunnel, err := openAuthenticatedHTTPSRTSPTunnel(dial, lifetime, fixture.source, fixture.roots)
			cancelDial() // successful playback must outlive the library's short dial context.
			if err != nil {
				fixture.mu.Lock()
				getCalls, postCalls := fixture.calls["GET"], fixture.calls["POST"]
				getAuth, postAuth := fixture.authorized["GET"], fixture.authorized["POST"]
				fixture.mu.Unlock()
				t.Fatalf("authenticated tunnel unavailable: %v; GET %d/%d POST %d/%d", err, getAuth, getCalls, postAuth, postCalls)
			}
			defer tunnel.Close()
			_ = tunnel.SetDeadline(time.Now().Add(time.Second))
			first := make([]byte, len("RTSP-witness"))
			if _, err = io.ReadFull(tunnel, first); err != nil || string(first) != "RTSP-witness" {
				t.Fatal("GET stream missing", err)
			}
			control := []byte("OPTIONS camera RTSP/1.0\r\n\r\n")
			if n, err := tunnel.Write(control); err != nil || n != len(control) {
				t.Fatal("control write count", n, err)
			}
			select {
			case payload := <-fixture.control:
				if string(payload) != string(control) {
					t.Fatal("base64 body corruption")
				}
			case <-time.After(time.Second):
				t.Fatal("POST body missing")
			}
			media := make([]byte, len("media-witness"))
			if _, err = io.ReadFull(tunnel, media); err != nil || string(media) != "media-witness" {
				t.Fatal("GET media missing", err)
			}
			fixture.mu.Lock()
			calls, authorized := fixture.calls, fixture.authorized
			fixture.mu.Unlock()
			if calls["GET"] != 2 || calls["POST"] != 2 || authorized["GET"] != 1 || authorized["POST"] != 1 {
				t.Fatal("both wrapper connections not authenticated")
			}
			cancelLifetime()
			one := make([]byte, 1)
			if _, err = tunnel.Read(one); err == nil {
				t.Fatal("cancelled session still readable")
			}
		})
	}
}

func TestAuthenticatedHTTPSRTSPTunnelRejectsUnsafeWrappers(t *testing.T) {
	for _, mode := range []string{"basic", "duplicate_nonce", "huge_header", "redirect", "unchallenged", "repeat_401", "post_401", "wrong_password", "wrong_pin", "wrong_name", "untrusted", "wrong_channel"} {
		t.Run(mode, func(t *testing.T) {
			fixture := newDigestHTTPSFixture(t, mode)
			source, roots := fixture.source, fixture.roots
			if mode == "wrong_password" {
				source.Password = "wrong"
			}
			if mode == "wrong_pin" {
				source.Pin = strings.Repeat("0", 64)
			}
			if mode == "wrong_name" {
				source.ServerName = "foreign.example.test"
			}
			if mode == "wrong_channel" {
				source.Path = "/ISAPI/Streaming/channels/202"
			}
			if mode == "untrusted" {
				roots = x509.NewCertPool()
			}
			dial, cancel := context.WithTimeout(context.Background(), time.Second)
			defer cancel()
			tunnel, err := openAuthenticatedHTTPSRTSPTunnel(dial, context.Background(), source, roots)
			close(fixture.getDone)
			if err == nil || tunnel != nil {
				if tunnel != nil {
					tunnel.Close()
				}
				t.Fatal("unsafe wrapper accepted")
			}
			fixture.mu.Lock()
			count := fixture.calls["GET"] + fixture.calls["POST"]
			fixture.mu.Unlock()
			if count > 4 {
				t.Fatal("unbounded authentication retry")
			}
			if strings.Contains(err.Error(), source.Password) || strings.Contains(err.Error(), source.Username) || strings.Contains(err.Error(), source.Path) {
				t.Fatal("sensitive protocol error")
			}
		})
	}
}

func TestAuthenticatedHTTPSRTSPTunnelBoundsBeforeDialAndMissingPOST(t *testing.T) {
	fixture := newDigestHTTPSFixture(t, "post_no_response")
	source := fixture.source
	for _, mutate := range []func(*httpsTunnelSource){
		func(s *httpsTunnelSource) { s.Path = "https://foreign.example.test/steal" },
		func(s *httpsTunnelSource) { s.Path += "?secret=x" },
		func(s *httpsTunnelSource) { s.IP = "camera.example.test" },
		func(s *httpsTunnelSource) { s.Port = 0 },
		func(s *httpsTunnelSource) { s.Username = "bad\r\nheader" },
	} {
		candidate := source
		mutate(&candidate)
		if conn, err := openAuthenticatedHTTPSRTSPTunnel(context.Background(), context.Background(), candidate, fixture.roots); err == nil || conn != nil {
			t.Fatal("unbound source accepted")
		}
	}
	fixture.mu.Lock()
	calls := fixture.calls["GET"] + fixture.calls["POST"]
	fixture.mu.Unlock()
	if calls != 0 {
		t.Fatal("invalid source dialed")
	}
	dial, cancel := context.WithTimeout(context.Background(), 150*time.Millisecond)
	defer cancel()
	started := time.Now()
	conn, err := openAuthenticatedHTTPSRTSPTunnel(dial, context.Background(), source, fixture.roots)
	close(fixture.getDone)
	fixture.mu.Lock()
	postAuth := fixture.authorized["POST"]
	fixture.mu.Unlock()
	if postAuth != 1 {
		t.Fatal("missing-response test did not reach authenticated POST")
	}
	if err == nil || conn != nil || time.Since(started) > time.Second {
		t.Fatal("POST confirmation/deadline not enforced")
	}
}

func TestHTTPSRTSPTunnelResponseFraming(t *testing.T) {
	for _, header := range []string{
		"HTTP/1.0 200 OK\r\n\r\n",
		"HTTP/1.1 200 OK\n\n",
		"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
		"HTTP/1.1 200 OK\r\nContent-Length: 0\r\nContent-Length: 1\r\n\r\n",
		"HTTP/1.1 200 OK\r\nLong: " + strings.Repeat("x", 4096) + "\r\n\r\n",
		"HTTP/1.1 200 OK\r\n" + strings.Repeat("Field: "+strings.Repeat("x", 1000)+"\r\n", 9) + "\r\n",
	} {
		if _, err := readTunnelResponse(bufio.NewReaderSize(strings.NewReader(header), 4096)); err == nil {
			t.Fatal("unsupported or unbounded wrapper framing accepted")
		}
	}
}

func TestAuthenticatedHTTPSRTSPTunnelCloseDiscardsBufferedMedia(t *testing.T) {
	fixture := newDigestHTTPSFixture(t, "sha256")
	defer close(fixture.getDone)
	dial, cancel := context.WithTimeout(t.Context(), time.Second)
	defer cancel()
	conn, err := openAuthenticatedHTTPSRTSPTunnel(dial, t.Context(), fixture.source, fixture.roots)
	if err != nil {
		t.Fatal("tunnel unavailable", err)
	}
	one := make([]byte, 1)
	if _, err := io.ReadFull(conn, one); err != nil {
		t.Fatal("initial media unavailable", err)
	}
	if conn.(*authenticatedHTTPSRTSPTunnel).reader.Buffered() == 0 {
		t.Fatal("buffered-media case not exercised")
	}
	conn.Close()
	if n, err := conn.Read(one); n != 0 || err == nil {
		t.Fatal("closed session leaked buffered media")
	}
	if n, err := conn.Write([]byte("OPTIONS camera RTSP/1.0\r\n\r\n")); n != 0 || err == nil {
		t.Fatal("closed session accepted control")
	}
}

func TestAuthenticatedHTTPSRTSPTunnelBoundsTotalPOSTBody(t *testing.T) {
	left, right := net.Pipe()
	defer right.Close()
	lifetime, cancel := context.WithCancel(t.Context())
	defer cancel()
	tunnel := &authenticatedHTTPSRTSPTunnel{receive: left, send: left, reader: bufio.NewReader(left), cancel: cancel, lifetime: lifetime}
	defer tunnel.Close()
	received := make(chan int, 1)
	go func() {
		n, _ := io.Copy(io.Discard, right)
		received <- int(n)
	}()
	body := make([]byte, 22500) // exactly 30000 base64 bytes.
	if n, err := tunnel.Write(body); n != len(body) || err != nil {
		t.Fatal("bounded body failed", n, err)
	}
	if n, err := tunnel.Write([]byte("next")); n != 0 || err == nil {
		t.Fatal("declared POST body exceeded")
	}
	select {
	case n := <-received:
		if n != 30000 {
			t.Fatal("POST byte limit mismatch", n)
		}
	case <-time.After(time.Second):
		t.Fatal("overflow did not close tunnel")
	}
}
