package cctv

import (
	"context"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/bluenviron/gortsplib/v5"
	"github.com/bluenviron/gortsplib/v5/pkg/description"
	"github.com/bluenviron/gortsplib/v5/pkg/format"
	"github.com/bluenviron/gortsplib/v5/pkg/format/rtph264"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/pion/logging"
	"github.com/pion/turn/v5"
	"github.com/pion/webrtc/v4"
)

// Opt-in loopback fixture only: the real browser creates its own unchanged SDP.
// Its ephemeral synthetic camera and TURN never use a provider or host camera.
func TestCCTVBrowserLiveFixture(t *testing.T) {
	metadata := os.Getenv("OBLI_CCTV_BROWSER_FIXTURE")
	if metadata == "" {
		t.Skip("explicit isolated browser fixture only")
	}
	ctx, cancel := context.WithTimeout(t.Context(), 8*time.Minute)
	defer cancel()
	private, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	template := &x509.Certificate{SerialNumber: big.NewInt(1), DNSNames: []string{"nvr.test"}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
	der, _ := x509.CreateCertificate(rand.Reader, template, template, &private.PublicKey, private)
	key, _ := x509.MarshalECPrivateKey(private)
	cert, e := tls.X509KeyPair(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: key}))
	if e != nil {
		t.Fatal(e)
	}
	roots := x509.NewCertPool()
	parsed, _ := x509.ParseCertificate(der)
	roots.AddCert(parsed)
	pin := sha256.Sum256(der)
	listener, e := net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	address := listener.Addr().String()
	_ = listener.Close()
	handler := &cameraFixture{}
	server := &gortsplib.Server{RTSPAddress: address, TLSConfig: &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}, Handler: handler}
	if e = server.Start(); e != nil {
		t.Fatal(e)
	}
	defer server.Close()
	data, e := os.ReadFile("testdata/pattern.h264")
	if e != nil {
		t.Fatal(e)
	}
	var au h264.AnnexB
	e = au.Unmarshal(data)
	if e != nil {
		t.Fatal(e)
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
		t.Fatal("fixture H264")
	}
	f := &format.H264{PayloadTyp: 96, PacketizationMode: 1, SPS: sps, PPS: pps}
	m := &description.Media{Type: description.MediaTypeVideo, Control: "trackID=0", Formats: []format.Format{f}}
	handler.stream = &gortsplib.ServerStream{Server: server, Desc: &description.Session{Medias: []*description.Media{m}}}
	if e = handler.stream.Initialize(); e != nil {
		t.Fatal(e)
	}
	defer handler.stream.Close()
	relayListener, e := net.ListenPacket("udp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	logger := logging.NewDefaultLoggerFactory()
	logger.Writer = io.Discard
	relay, e := turn.NewServer(turn.ServerConfig{Realm: "fixture", LoggerFactory: logger, AuthHandler: func(a *turn.RequestAttributes) (string, []byte, bool) {
		return a.Username, turn.GenerateAuthKey(a.Username, "fixture", "private-fixture"), true
	}, PacketConnConfigs: []turn.PacketConnConfig{{PacketConn: relayListener, RelayAddressGenerator: &turn.RelayAddressGeneratorStatic{RelayAddress: net.ParseIP("127.0.0.1"), Address: "127.0.0.1"}}}})
	if e != nil {
		t.Fatal(e)
	}
	defer relay.Close()
	defer relayListener.Close()
	turnURL := fmt.Sprintf("turn:%s?transport=udp", relayListener.LocalAddr())
	settings := webrtc.SettingEngine{}
	settings.SetIncludeLoopbackCandidate(true)
	settings.SetIPFilter(func(ip net.IP) bool { return ip.IsLoopback() })
	now := time.Now().UTC().Truncate(time.Second)
	expires := now.Add(7 * time.Minute)
	turnConfig := Turn{URLs: []string{turnURL}, Username: "fixture", Credential: "private-fixture", ExpiresAt: expires.Format("2006-01-02T15:04:05Z"), RelayCIDRs: []string{"127.0.0.0/24"}, RelayPortMin: 32768, RelayPortMax: 65535}
	source := Source{}
	_, port, _ := net.SplitHostPort(address)
	source.URL = "rtsps://nvr.test:" + port + "/fixture?enableSrtp"
	sourceBytes, _ := json.Marshal(source)
	turnBytes, _ := json.Marshal(turnConfig)
	public, key, _ := ed25519.GenerateKey(rand.Reader)
	worker := New(func(c context.Context, g Grant, s Source, r Turn, o Description) (Media, Description, string) {
		return openMedia(c, g, s, r, o, roots, &settings)
	})
	defer worker.Close()
	collector := "11111111-1111-4111-8111-111111111111"
	worker.collector, worker.site, worker.key, worker.configured = collector, 1, public, true
	var mu sync.Mutex
	var current Grant
	var last Reply
	var stopped bool
	newUUID := func() string {
		b := make([]byte, 16)
		_, _ = rand.Read(b)
		b[6] = b[6]&15 | 64
		b[8] = b[8]&63 | 128
		return fmt.Sprintf("%x-%x-%x-%x-%x", b[:4], b[4:6], b[6:8], b[8:10], b[10:])
	}
	var startKey, startHash, offerHash string
	var withholdRenew, sourceStopped bool
	projection := func() map[string]any {
		stamp := time.Now().UTC().Truncate(time.Second).Format("2006-01-02T15:04:05Z")
		base := "/security-devices/cctv/live-sessions/" + current.Session
		var negotiation any
		if current.Negotiation != "" {
			negotiation = current.Negotiation
		}
		status := "requested"
		var answer any
		var why any
		if last.Status != "" {
			status = last.Status
			answer = last.Answer
			why = last.Reason
		}
		worker.mu.Lock()
		if s := worker.sessions[current.Session]; s != nil {
			status = s.reply.Status
			answer = s.reply.Answer
			why = s.reply.Reason
		}
		worker.mu.Unlock()
		return map[string]any{"actor_id": 7, "camera_id": 42, "session": map[string]any{"uuid": current.Session, "actor_id": 7, "camera_id": 42, "negotiation_uuid": negotiation, "generation": current.Generation, "status": status, "reason_code": why, "server_now": stamp, "authority_until": current.Expires, "session_expires_at": current.SessionExpires, "ice_expires_at": turnConfig.ExpiresAt, "transport": "webrtc", "video_codec": "H264", "audio_enabled": false, "ice_servers": []Turn{turnConfig}, "answer": answer, "status_href": base, "offer_href": base + "/offer", "renew_href": base + "/renew", "stop_href": base + "/stop"}}
	}
	handlerHTTP := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("Content-Type", "application/json")
		mu.Lock()
		defer mu.Unlock()
		if r.URL.Path == "/shutdown" {
			_ = json.NewEncoder(w).Encode(map[string]bool{"stopped": true})
			cancel()
			return
		}
		if r.URL.Path == "/fixture/source-stop" {
			sourceStopped = true
			_ = json.NewEncoder(w).Encode(map[string]bool{"stopped": true})
			return
		}
		if r.URL.Path == "/fixture/withhold-renew" {
			withholdRenew = true
			_ = json.NewEncoder(w).Encode(map[string]bool{"withholding": true})
			return
		}
		raw, e := io.ReadAll(io.LimitReader(r.Body, 65537))
		if e != nil || len(raw) > 65536 {
			w.WriteHeader(422)
			return
		}
		var body map[string]json.RawMessage
		if r.Method == "POST" && json.Unmarshal(raw, &body) != nil {
			w.WriteHeader(422)
			return
		}
		actor := 7
		if r.Method == "POST" {
			if json.Unmarshal(body["expected_actor_id"], &actor) != nil || actor != 7 {
				w.WriteHeader(403)
				return
			}
		} else if r.URL.Query().Get("expected_actor_id") != "7" {
			w.WriteHeader(403)
			return
		}
		if r.URL.Path == "/security-devices/devices/42/live-sessions" {
			if r.Method != "POST" || len(body) != 4 {
				w.WriteHeader(422)
				return
			}
			var key, purpose string
			var revision int
			if json.Unmarshal(body["request_key"], &key) != nil || json.Unmarshal(body["purpose"], &purpose) != nil || json.Unmarshal(body["source_revision"], &revision) != nil || !uuid.MatchString(key) || revision != 1 || len(purpose) < 10 || len(purpose) > 500 {
				w.WriteHeader(422)
				return
			}
			if key == startKey {
				if hash(raw) != startHash {
					w.WriteHeader(409)
					return
				}
				_ = json.NewEncoder(w).Encode(projection())
				return
			}
			if current.Session != "" && !stopped {
				worker.mu.Lock()
				previous := worker.sessions[current.Session]
				terminal := previous != nil && previous.terminal
				worker.mu.Unlock()
				expiry, _ := instant(current.Expires)
				if !terminal && expiry.After(time.Now()) {
					w.WriteHeader(409)
					return
				}
			}
			startKey, startHash = key, hash(raw)
			withholdRenew, sourceStopped = false, false
			stopped = false
			last = Reply{}
			offerHash = ""
			issued := time.Now().UTC().Truncate(time.Second)
			sessionExpiry := issued.Add(4 * time.Minute)
			if sessionExpiry.After(expires) {
				sessionExpiry = expires
			}
			turnConfig.ExpiresAt = sessionExpiry.Format("2006-01-02T15:04:05Z")
			turnBytes, _ = json.Marshal(turnConfig)
			number, _ := strconv.Atoi(port)
			current = Grant{Version: 1, Type: "collector_cctv_live", Collector: collector, Site: 1, Camera: 42, Recorder: 3, Provider: "unifi", Console: "fixture-console", ProviderCamera: "fixture-camera", Session: newUUID(), Negotiation: "", Generation: 1, Revision: 1, SourceHash: hash(sourceBytes), TurnHash: hash(turnBytes), OfferHash: "", Quality: "low", Codec: "H264", Audio: false, Scope: 4, IP: "127.0.0.1", Port: number, ServerName: "nvr.test", Pin: hex.EncodeToString(pin[:]), Issued: issued.Format("2006-01-02T15:04:05Z"), Expires: issued.Add(15 * time.Second).Format("2006-01-02T15:04:05Z"), SessionExpires: sessionExpiry.Format("2006-01-02T15:04:05Z")}
			_ = json.NewEncoder(w).Encode(projection())
			return
		}
		base := "/security-devices/cctv/live-sessions/" + current.Session
		if current.Session == "" || !strings.HasPrefix(r.URL.Path, base) {
			w.WriteHeader(404)
			return
		}
		if r.URL.Path == base && r.Method == "GET" {
			_ = json.NewEncoder(w).Encode(projection())
			return
		}
		var generation uint64
		if json.Unmarshal(body["expected_generation"], &generation) != nil || generation != current.Generation {
			w.WriteHeader(409)
			return
		}
		switch r.URL.Path {
		case base + "/offer":
			if len(body) != 4 || stopped {
				w.WriteHeader(409)
				return
			}
			var negotiation, sdp string
			if json.Unmarshal(body["negotiation_uuid"], &negotiation) != nil || !uuid.MatchString(negotiation) || json.Unmarshal(body["sdp"], &sdp) != nil || len(sdp) > 32768 {
				w.WriteHeader(422)
				return
			}
			if offerHash != "" {
				if current.Negotiation != negotiation || offerHash != hash([]byte(sdp)) {
					w.WriteHeader(409)
					return
				}
				_ = json.NewEncoder(w).Encode(projection())
				return
			}
			offerHash = hash([]byte(sdp))
			current.Negotiation = negotiation
			current.OfferHash = offerHash
			msg := Message{Version: 1, Operation: "open", Request: newUUID(), Grant: sign(key, current), Source: sourceBytes, Turn: turnBytes, Offer: Description{"offer", sdp}}
			last = worker.Handle(ctx, wire(t, msg))
			_ = json.NewEncoder(w).Encode(projection())
		case base + "/renew":
			if len(body) != 2 || stopped {
				w.WriteHeader(409)
				return
			}
			if withholdRenew {
				mu.Unlock()
				select {
				case <-ctx.Done():
				case <-r.Context().Done():
				}
				mu.Lock()
				return
			}
			current.Generation++
			issued := time.Now().UTC().Truncate(time.Second)
			current.Issued = issued.Format("2006-01-02T15:04:05Z")
			current.Expires = issued.Add(15 * time.Second).Format("2006-01-02T15:04:05Z")
			if offerHash != "" {
				last = worker.Handle(ctx, wire(t, Message{Version: 1, Operation: "renew", Request: newUUID(), Grant: sign(key, current)}))
			}
			_ = json.NewEncoder(w).Encode(projection())
		case base + "/stop":
			if len(body) != 2 {
				w.WriteHeader(422)
				return
			}
			stopped = true
			if offerHash != "" {
				last = worker.Handle(ctx, wire(t, Message{Version: 1, Operation: "close", Request: newUUID(), Session: current.Session, Generation: current.Generation}))
			} else {
				last.Status = "stopped"
			}
			_ = json.NewEncoder(w).Encode(projection())
		default:
			w.WriteHeader(404)
		}
	})
	httpListener, e := net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	web := &http.Server{Handler: handlerHTTP, ReadHeaderTimeout: 2 * time.Second}
	defer web.Close()
	go func() { _ = web.Serve(httpListener) }()
	info, _ := json.Marshal(map[string]any{"url": "http://" + httpListener.Addr().String(), "expires_at": expires.Format("2006-01-02T15:04:05Z"), "synthetic": true, "browser_offer_unchanged": true})
	if e = os.WriteFile(metadata, info, 0600); e != nil {
		t.Fatal(e)
	}
	t.Log("isolated browser media fixture ready", httpListener.Addr())
	encoder := &rtph264.Encoder{PayloadType: 96, PacketizationMode: 1}
	if encoder.Init() != nil {
		t.Fatal("encoder")
	}
	ticker := time.NewTicker(100 * time.Millisecond)
	defer ticker.Stop()
	timestamp := uint32(0)
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			mu.Lock()
			stoppedSource := sourceStopped
			mu.Unlock()
			if stoppedSource {
				continue
			}
			packets, e := encoder.Encode(au)
			if e != nil {
				t.Fatal(e)
			}
			for _, p := range packets {
				p.Timestamp = timestamp
				_ = handler.stream.WritePacketRTP(m, p)
			}
			timestamp += 9000
		}
	}
}
