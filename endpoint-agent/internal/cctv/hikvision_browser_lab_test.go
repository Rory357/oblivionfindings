package cctv

import (
	"bufio"
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/bluenviron/gortsplib/v5"
	"github.com/bluenviron/gortsplib/v5/pkg/auth"
	"github.com/bluenviron/gortsplib/v5/pkg/description"
	"github.com/bluenviron/gortsplib/v5/pkg/format"
	"github.com/bluenviron/gortsplib/v5/pkg/format/rtph264"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/pion/logging"
	"github.com/pion/turn/v5"
	"github.com/pion/webrtc/v4"
)

// Opt-in fixture services only. The PHP application, signed HTTPS/mTLS client,
// real collector MediaLane and native Worker process the original browser SDP.
// Synthetic camera/trust roots and the bounded Redis-Lua fixture are explicit
// external dependencies; this does not witness hardware or installed services.
func TestHikvisionOriginalBrowserServices(t *testing.T) {
	root := os.Getenv("OBLI_HIKVISION_BROWSER_LAB")
	upstream := os.Getenv("OBLI_HIKVISION_BROWSER_UPSTREAM")
	if root == "" {
		t.Skip("explicit isolated original-browser laboratory only")
	}
	target, err := url.Parse(upstream)
	if err != nil || target.Scheme != "http" || target.Hostname() != "127.0.0.1" || target.Path != "" {
		t.Fatal("isolated loopback upstream required")
	}
	if err = os.MkdirAll(root, 0700); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(t.Context(), 20*time.Minute)
	defer cancel()
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	camera := &hikLiveCameraFixture{}
	rtsp := &gortsplib.Server{RTSPAddress: listener.Addr().String(), Listen: func(string, string) (net.Listener, error) { return listener, nil }, AuthMethods: []auth.VerifyMethod{auth.VerifyMethodDigestSHA256}, Handler: camera}
	if err = rtsp.Start(); err != nil {
		listener.Close()
		t.Fatal(err)
	}
	defer rtsp.Close()
	data, err := os.ReadFile("testdata/pattern.h264")
	if err != nil {
		t.Fatal(err)
	}
	var au h264.AnnexB
	if err = au.Unmarshal(data); err != nil {
		t.Fatal(err)
	}
	var sps, pps []byte
	for _, n := range au {
		if n[0]&31 == 7 {
			sps = n
		}
		if n[0]&31 == 8 {
			pps = n
		}
	}
	if len(sps) < 4 || len(pps) == 0 {
		t.Fatal("valid synthetic H264 required")
	}
	codec := &format.H264{PayloadTyp: 96, PacketizationMode: 1, SPS: sps, PPS: pps}
	media := &description.Media{Type: description.MediaTypeVideo, Control: "trackID=0", Formats: []format.Format{codec}}
	camera.stream = &gortsplib.ServerStream{Server: rtsp, Desc: &description.Session{Medias: []*description.Media{media}}}
	if err = camera.stream.Initialize(); err != nil {
		t.Fatal(err)
	}
	defer camera.stream.Close()
	tlsCamera := newHikLiveHTTPSFixture(t, listener.Addr().String(), "udp")
	// The shared focused helper bounds a bridge to ten seconds. This longer
	// opt-in browser fixture must outlive its independent original grant watch.
	tlsCamera.bridgeTimeout = 4 * time.Minute
	cameraHandler := tlsCamera.server.Config.Handler
	tlsCamera.server.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// A protected local marker controls only this synthetic external camera.
		// The original worker must still enforce its ordinary TLS/lease deadlines.
		if r.URL.Path == "/ISAPI/System/deviceInfo" {
			if _, e := os.Stat(filepath.Join(root, "slow-failing-probe")); e == nil {
				select {
				case <-r.Context().Done():
					return
				case <-time.After(2 * time.Second):
					http.Error(w, "synthetic bounded probe failure", http.StatusServiceUnavailable)
					return
				}
			}
		}
		cameraHandler.ServeHTTP(w, r)
	})
	logger := logging.NewDefaultLoggerFactory()
	logger.Writer = io.Discard
	turnSecret := browserLabToken(t)
	udp, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer udp.Close()
	relay, err := turn.NewServer(turn.ServerConfig{Realm: "fixture", LoggerFactory: logger, AuthHandler: func(a *turn.RequestAttributes) (string, []byte, bool) {
		expiry, _, ok := strings.Cut(a.Username, ":")
		seconds, e := strconv.ParseInt(expiry, 10, 64)
		if !ok || e != nil || seconds <= time.Now().Unix() || seconds > time.Now().Add(5*time.Minute).Unix() {
			return "", nil, false
		}
		mac := hmac.New(sha1.New, []byte(turnSecret))
		_, _ = mac.Write([]byte(a.Username))
		password := base64.StdEncoding.EncodeToString(mac.Sum(nil))
		return a.Username, turn.GenerateAuthKey(a.Username, "fixture", password), true
	}, PacketConnConfigs: []turn.PacketConnConfig{{PacketConn: udp, RelayAddressGenerator: &turn.RelayAddressGeneratorPortRange{RelayAddress: net.ParseIP("127.0.0.1"), Address: "127.0.0.1", MinPort: 32768, MaxPort: 65535}}}})
	if err != nil {
		t.Fatal(err)
	}
	defer relay.Close()
	encoder := &rtph264.Encoder{PayloadType: 96, PacketizationMode: 1}
	if err = encoder.Init(); err != nil {
		t.Fatal(err)
	}
	go func() {
		ticker := time.NewTicker(100 * time.Millisecond)
		defer ticker.Stop()
		var stamp uint32
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				packets, e := encoder.Encode(au)
				if e != nil {
					return
				}
				for _, packet := range packets {
					packet.Timestamp = stamp
					_ = camera.stream.WritePacketRTP(media, packet)
				}
				stamp += 9000
			}
		}
	}()
	certificates := browserLabCertificates(t, root)
	central, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	proxy := httputil.NewSingleHostReverseProxy(target)
	proxy.ErrorLog = nil
	var mediaObservation sync.Mutex
	centralWeb := &http.Server{ReadHeaderTimeout: 3 * time.Second, Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.TLS == nil || len(r.TLS.VerifiedChains) == 0 || len(r.TLS.PeerCertificates) < 1 || !strings.HasPrefix(r.URL.Path, "/api/monitoring/collectors/") {
			http.NotFound(w, r)
			return
		}
		sum := sha256.Sum256(r.TLS.PeerCertificates[0].Raw)
		r.Header.Del("X-Oblivion-Client-Certificate-Fingerprint")
		r.Header.Set("X-Oblivion-Client-Certificate-Fingerprint", hex.EncodeToString(sum[:]))
		browserLabMediaResultProxy(t, root, &mediaObservation, w, r, proxy)
	})}
	centralTLS := tls.NewListener(central, &tls.Config{Certificates: []tls.Certificate{certificates.server}, ClientCAs: certificates.roots, ClientAuth: tls.RequireAndVerifyClientCert, MinVersion: tls.VersionTLS12})
	go func() { _ = centralWeb.Serve(centralTLS) }()
	defer centralWeb.Close()
	redisListener, redisClosed := browserLabRedis(t, ctx)
	defer redisListener.Close()
	ipc, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ipc.Close()
	ipcToken := browserLabToken(t)
	settings := webrtc.SettingEngine{}
	settings.SetIncludeLoopbackCandidate(true)
	settings.SetIPFilter(func(ip net.IP) bool { return ip.IsLoopback() })
	settings.SetRemoteIPFilter(func(ip net.IP) bool { return ip.IsLoopback() })
	settings.SetNetworkTypes([]webrtc.NetworkType{webrtc.NetworkTypeUDP4})
	settings.LoggerFactory = logger
	worker := New(func(c context.Context, g Grant, s Source, r Turn, o Description) (Media, Description, string) {
		return openMedia(c, g, s, r, o, tlsCamera.roots, &settings)
	})
	worker.discovery.run = func(c context.Context, s httpsTunnelSource) (hikvisionInventory, error) {
		return discoverHikvision(c, s, tlsCamera.roots)
	}
	defer worker.Close()
	var outputMu, traceMu sync.Mutex
	var traces []map[string]any
	childClosed := make(chan struct{})
	var connection net.Conn
	var connectionMu sync.Mutex
	go func() {
		defer close(childClosed)
		conn, e := ipc.Accept()
		if e != nil {
			return
		}
		connectionMu.Lock()
		connection = conn
		connectionMu.Unlock()
		defer conn.Close()
		reader := bufio.NewReader(conn)
		token, e := reader.ReadString('\n')
		if e != nil || !hmac.Equal([]byte(strings.TrimSuffix(token, "\n")), []byte(ipcToken)) {
			return
		}
		worker.emit = func(r Reply) { outputMu.Lock(); defer outputMu.Unlock(); _ = WriteFrame(conn, r) }
		for {
			raw, e := ReadFrame(reader)
			if e != nil {
				return
			}
			m, e := decodeMessage(raw)
			if e != nil {
				clear(raw)
				return
			}
			var reply any
			if m.Operation == hikvisionDiscoveryOperation {
				reply = worker.DiscoverHikvision(ctx, raw)
			} else {
				reply = worker.Handle(ctx, raw)
			}
			entry := map[string]any{"operation": m.Operation, "request_uuid": m.Request, "session_uuid": m.Session, "generation": m.Generation}
			if live, ok := reply.(Reply); ok {
				entry["status"] = live.Status
				entry["reason_code"] = live.Reason
			}
			if m.Operation == "open" {
				var body struct {
					Grant Envelope    `json:"grant"`
					Offer Description `json:"offer"`
				}
				if json.Unmarshal(raw, &body) == nil {
					sum := sha256.Sum256([]byte(body.Offer.SDP))
					entry["original_offer_sha256"] = hex.EncodeToString(sum[:])
					decoded, _ := base64.StdEncoding.DecodeString(body.Grant.Payload)
					var grant map[string]any
					_ = json.Unmarshal(decoded, &grant)
					entry["signed_offer_sha256"] = grant["offer_sha256"]
					entry["signed_source_sha256"] = grant["source_sha256"]
					entry["session_uuid"] = grant["session_uuid"]
				}
			}
			traceMu.Lock()
			traces = append(traces, entry)
			encoded, _ := json.Marshal(traces)
			_ = os.WriteFile(filepath.Join(root, "native-trace.json"), encoded, 0600)
			traceMu.Unlock()
			clear(raw)
			outputMu.Lock()
			e = WriteFrame(conn, reply)
			outputMu.Unlock()
			if e != nil {
				return
			}
		}
	}()
	// The native simulator starts only after the schema owner has warmed the app;
	// none of its ephemeral certificate/key files are served by public/.
	_, redisPort, _ := net.SplitHostPort(redisListener.Addr().String())
	rp, _ := strconv.Atoi(redisPort)
	metadata := map[string]any{"backend_origin": upstream, "central_origin": "https://" + central.Addr().String(), "central_pin": certificates.pin, "ca_file": filepath.Join(root, "ca.pem"), "client_cert": filepath.Join(root, "client.pem"), "client_key": filepath.Join(root, "client-key.pem"), "client_fingerprint": certificates.fingerprint, "ipc_address": ipc.Addr().String(), "redis_port": rp, "turn_url": "turn:" + udp.LocalAddr().String() + "?transport=udp", "camera": map[string]any{"allowed_ip": tlsCamera.source.IP, "allowed_port": tlsCamera.source.Port, "tls_server_name": tlsCamera.source.ServerName, "tls_pin": tlsCamera.source.Pin}}
	private, _ := json.Marshal(map[string]string{"ipc_token": ipcToken, "turn_secret": turnSecret})
	if err = os.WriteFile(filepath.Join(root, "native-private.json"), private, 0600); err != nil {
		t.Fatal(err)
	}
	public, _ := json.Marshal(metadata)
	if err = os.WriteFile(filepath.Join(root, "native-metadata.json"), public, 0600); err != nil {
		t.Fatal(err)
	}
	ticker := time.NewTicker(200 * time.Millisecond)
	defer ticker.Stop()
	var requested bool
	for !requested {
		select {
		case <-ctx.Done():
			t.Error("original-browser fixture deadline")
			requested = true
		case <-ticker.C:
			_, e := os.Stat(filepath.Join(root, "stop-native"))
			requested = e == nil
		}
	}
	worker.Close()
	cancel()
	_ = ipc.Close()
	connectionMu.Lock()
	if connection != nil {
		_ = connection.Close()
	}
	connectionMu.Unlock()
	select {
	case <-childClosed:
	case <-time.After(3 * time.Second):
		t.Error("owned native private connection did not close")
	}
	_ = redisListener.Close()
	select {
	case <-redisClosed:
	case <-time.After(time.Second):
		t.Error("owned replay fixture did not close")
	}
	traceMu.Lock()
	proof := map[string]any{"native_closed": true, "rtsp_digest_requests": camera.digest.Load(), "operations": traces}
	traceMu.Unlock()
	finished, _ := json.Marshal(proof)
	_ = os.WriteFile(filepath.Join(root, "native-closed.json"), finished, 0600)
	if camera.digest.Load() < 2 {
		t.Error("original browser did not reach authenticated RTSP camera")
	}
}

type browserLabCerts struct {
	server           tls.Certificate
	roots            *x509.CertPool
	pin, fingerprint string
}

func browserLabCertificates(t *testing.T, root string) browserLabCerts {
	t.Helper()
	caKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	caTemplate := &x509.Certificate{SerialNumber: big.NewInt(11), Subject: pkix.Name{CommonName: "Synthetic browser laboratory CA"}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature}
	caDER, e := x509.CreateCertificate(rand.Reader, caTemplate, caTemplate, &caKey.PublicKey, caKey)
	if e != nil {
		t.Fatal(e)
	}
	ca, _ := x509.ParseCertificate(caDER)
	roots := x509.NewCertPool()
	roots.AddCert(ca)
	_ = os.WriteFile(filepath.Join(root, "ca.pem"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER}), 0600)
	issue := func(serial int64, client bool) (tls.Certificate, *x509.Certificate, []byte, []byte) {
		key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		cert := &x509.Certificate{SerialNumber: big.NewInt(serial), NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}}
		if client {
			cert.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}
		}
		der, e := x509.CreateCertificate(rand.Reader, cert, ca, &key.PublicKey, caKey)
		if e != nil {
			t.Fatal(e)
		}
		rawKey, _ := x509.MarshalECPrivateKey(key)
		certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
		keyPEM := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: rawKey})
		pair, e := tls.X509KeyPair(certPEM, keyPEM)
		if e != nil {
			t.Fatal(e)
		}
		parsed, _ := x509.ParseCertificate(der)
		return pair, parsed, certPEM, keyPEM
	}
	server, parsed, _, _ := issue(12, false)
	_, client, clientPEM, clientKey := issue(13, true)
	_ = os.WriteFile(filepath.Join(root, "client.pem"), clientPEM, 0600)
	_ = os.WriteFile(filepath.Join(root, "client-key.pem"), clientKey, 0600)
	pin := sha256.Sum256(parsed.RawSubjectPublicKeyInfo)
	fingerprint := sha256.Sum256(client.Raw)
	return browserLabCerts{server, roots, "sha256//" + base64.StdEncoding.EncodeToString(pin[:]), hex.EncodeToString(fingerprint[:])}
}
func browserLabToken(t *testing.T) string {
	t.Helper()
	value := make([]byte, 32)
	if _, e := rand.Read(value); e != nil {
		t.Fatal(e)
	}
	return hex.EncodeToString(value)
}

// External Redis protocol fixture, not a deployed Redis acceptance witness.
// Implements only the original framework add-Lua script, atomically, on this
// laboratory nonce prefix. No arbitrary Lua, commands, keyspace or persistence.
func browserLabRedis(t *testing.T, ctx context.Context) (net.Listener, <-chan struct{}) {
	t.Helper()
	listener, e := net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	closed := make(chan struct{})
	var mu sync.Mutex
	entries := map[string]time.Time{}
	go func() {
		defer close(closed)
		for {
			connection, e := listener.Accept()
			if e != nil {
				return
			}
			go func(conn net.Conn) {
				defer conn.Close()
				reader := bufio.NewReader(conn)
				for {
					if ctx.Err() != nil {
						return
					}
					_ = conn.SetReadDeadline(time.Now().Add(3 * time.Second))
					args, e := browserLabRESP(reader)
					if e != nil {
						return
					}
					out := "-ERR unsupported laboratory command\r\n"
					switch strings.ToUpper(args[0]) {
					case "SELECT":
						if len(args) == 2 && args[1] == "0" {
							out = "+OK\r\n"
						}
					case "PING":
						out = "+PONG\r\n"
					case "EVAL":
						if len(args) == 6 && strings.TrimSpace(args[1]) == "return redis.call('exists',KEYS[1])<1 and redis.call('setex',KEYS[1],ARGV[2],ARGV[1])" && args[2] == "1" && strings.HasPrefix(args[3], "hiklab_nonce_monitoring:collector:request:") {
							seconds, e := strconv.Atoi(args[5])
							if e == nil && seconds > 0 && seconds <= 600 {
								mu.Lock()
								if expiry, exists := entries[args[3]]; exists && time.Now().Before(expiry) {
									out = "$-1\r\n"
								} else if len(entries) < 10000 {
									entries[args[3]] = time.Now().Add(time.Duration(seconds) * time.Second)
									out = "+OK\r\n"
								}
								mu.Unlock()
							}
						}
					}
					if _, e = io.WriteString(conn, out); e != nil {
						return
					}
				}
			}(connection)
		}
	}()
	return listener, closed
}
func browserLabRESP(reader *bufio.Reader) ([]string, error) {
	line, e := reader.ReadString('\n')
	if e != nil || len(line) > 16 || !strings.HasPrefix(line, "*") {
		return nil, fmt.Errorf("bounded fixture RESP required")
	}
	count, e := strconv.Atoi(strings.TrimSpace(line[1:]))
	if e != nil || count < 1 || count > 16 {
		return nil, fmt.Errorf("bounded fixture RESP required")
	}
	args := make([]string, count)
	for i := range args {
		line, e = reader.ReadString('\n')
		if e != nil || len(line) > 16 || !strings.HasPrefix(line, "$") {
			return nil, fmt.Errorf("bounded fixture RESP required")
		}
		size, e := strconv.Atoi(strings.TrimSpace(line[1:]))
		if e != nil || size < 0 || size > 8192 {
			return nil, fmt.Errorf("bounded fixture RESP required")
		}
		raw := make([]byte, size+2)
		if _, e = io.ReadFull(reader, raw); e != nil || string(raw[size:]) != "\r\n" {
			return nil, fmt.Errorf("bounded fixture RESP required")
		}
		args[i] = string(raw[:size])
	}
	return args, nil
}

// Observe original signed bytes and optionally lose one accepted result response.
// This exists only in the opt-in loopback laboratory; the real broker is unchanged.
func browserLabMediaResultProxy(t *testing.T, root string, observation *sync.Mutex, w http.ResponseWriter, r *http.Request, proxy *httputil.ReverseProxy) {
	if r.URL.Path != "/api/monitoring/collectors/media/results" {
		proxy.ServeHTTP(w, r)
		return
	}
	original, err := io.ReadAll(io.LimitReader(r.Body, 2097153))
	_ = r.Body.Close()
	if err != nil || len(original) > 2097152 {
		t.Error("Owned media observation request exceeded its bound")
		http.Error(w, "Owned media observation unavailable", http.StatusBadRequest)
		return
	}
	r.Body = io.NopCloser(bytes.NewReader(original))
	var identity struct {
		RequestKey string `json:"request_key"`
	}
	if json.Unmarshal(original, &identity) != nil {
		t.Error("Owned media observation request was invalid")
		http.Error(w, "Owned media observation unavailable", http.StatusBadRequest)
		return
	}
	originalHash := sha256.Sum256(original)
	nonceHash := sha256.Sum256([]byte(r.Header.Get("X-Oblivion-Collector-Nonce")))
	lost := fmt.Errorf("owned accepted media response loss")
	requestProxy := *proxy
	requestProxy.ModifyResponse = func(response *http.Response) error {
		body, readErr := io.ReadAll(io.LimitReader(response.Body, 2097153))
		_ = response.Body.Close()
		if readErr != nil || len(body) > 2097152 {
			t.Error("Owned media observation response exceeded its bound")
			return fmt.Errorf("owned media observation unavailable")
		}
		response.Body = io.NopCloser(bytes.NewReader(body))
		responseHash := sha256.Sum256(body)
		observation.Lock()
		defer observation.Unlock()
		loseResponse := false
		marker := filepath.Join(root, "lose-next-media-result-response")
		if response.StatusCode == http.StatusOK {
			if _, statErr := os.Stat(marker); statErr == nil {
				if renameErr := os.Rename(marker, filepath.Join(root, "lost-media-result-response")); renameErr != nil {
					t.Error("Owned response loss marker could not be claimed")
				} else {
					hijacker, ok := w.(http.Hijacker)
					if !ok {
						t.Error("Owned response loss requires HTTP1")
					} else {
						conn, _, hijackErr := hijacker.Hijack()
						if hijackErr != nil {
							t.Error("Owned response loss could not close the connection")
						} else {
							_ = conn.Close()
							loseResponse = true
						}
					}
				}
			}
		}
		record := map[string]any{"at": time.Now().UTC().Format(time.RFC3339Nano), "path": r.URL.Path, "request_key": identity.RequestKey, "original_request_sha256": hex.EncodeToString(originalHash[:]), "nonce_sha256": hex.EncodeToString(nonceHash[:]), "original_response_sha256": hex.EncodeToString(responseHash[:]), "upstream_status": response.StatusCode, "lost_response": loseResponse}
		encoded, encodeErr := json.Marshal(record)
		file, openErr := os.OpenFile(filepath.Join(root, "media-response-observations.jsonl"), os.O_WRONLY|os.O_CREATE|os.O_APPEND, 0600)
		if encodeErr != nil || openErr != nil {
			t.Error("Owned response observation could not be saved")
		} else {
			if _, writeErr := file.Write(append(encoded, '\n')); writeErr != nil {
				t.Error("Owned response observation could not be saved")
			}
			_ = file.Close()
		}
		if loseResponse {
			return lost
		}
		return nil
	}
	requestProxy.ErrorHandler = func(writer http.ResponseWriter, request *http.Request, failure error) {
		if failure != lost {
			http.Error(writer, "Owned central transport unavailable", http.StatusBadGateway)
		}
	}
	requestProxy.ServeHTTP(w, r)
}
