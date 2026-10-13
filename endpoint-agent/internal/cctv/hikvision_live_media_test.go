package cctv

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"github.com/bluenviron/gortsplib/v5"
	"github.com/bluenviron/gortsplib/v5/pkg/auth"
	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/description"
	"github.com/bluenviron/gortsplib/v5/pkg/format"
	"github.com/bluenviron/gortsplib/v5/pkg/format/rtph264"
	"github.com/bluenviron/gortsplib/v5/pkg/headers"
	"github.com/bluenviron/gortsplib/v5/pkg/liberrors"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/pion/logging"
	"github.com/pion/turn/v5"
	"github.com/pion/webrtc/v4"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

type hikLiveCameraFixture struct {
	stream *gortsplib.ServerStream
	digest atomic.Int32
	mode   string
}

func (h *hikLiveCameraFixture) authorize(c *gortsplib.ServerConn, r *base.Request) bool {
	if r.URL.Path != "/Streaming/channels/102" && r.URL.Path != "/Streaming/channels/102/trackID=0" {
		return false
	}
	if !c.VerifyCredentials(r, "fixture-reader-auth", "fixture-secret") || len(r.Header["Authorization"]) != 1 || !strings.HasPrefix(r.Header["Authorization"][0], "Digest ") {
		return false
	}
	h.digest.Add(1)
	return true
}
func (h *hikLiveCameraFixture) OnDescribe(c *gortsplib.ServerHandlerOnDescribeCtx) (*base.Response, *gortsplib.ServerStream, error) {
	if h.mode == "rtsp_basic" {
		return &base.Response{StatusCode: base.StatusUnauthorized, Header: base.Header{"WWW-Authenticate": {`Basic realm="fixture"`}}}, nil, nil
	}
	if h.mode == "rtsp_unchallenged" {
		return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
	}
	if !h.authorize(c.Conn, c.Request) {
		return &base.Response{StatusCode: base.StatusUnauthorized}, nil, liberrors.ErrServerAuth{}
	}
	if h.mode == "redirect" {
		return &base.Response{StatusCode: base.StatusMovedPermanently, Header: base.Header{"Location": {"rtsp://foreign.test/Streaming/channels/102"}}}, nil, nil
	}
	if h.mode == "sdp_channel" || h.mode == "sdp_foreign" {
		body, err := h.stream.Desc.Marshal()
		if err != nil {
			return &base.Response{StatusCode: base.StatusInternalServerError}, nil, err
		}
		// Send the actual hostile control, bypassing the library's automatic
		// trackID replacement in its normal stream response.
		return &base.Response{StatusCode: base.StatusOK, Body: body}, nil, nil
	}
	return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
}
func (h *hikLiveCameraFixture) OnSetup(c *gortsplib.ServerHandlerOnSetupCtx) (*base.Response, *gortsplib.ServerStream, error) {
	if h.mode == "rtsp_basic" {
		return &base.Response{StatusCode: base.StatusUnauthorized, Header: base.Header{"WWW-Authenticate": {`Basic realm="fixture"`}}}, nil, nil
	}
	if h.mode == "rtsp_unchallenged" {
		return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
	}
	if !h.authorize(c.Conn, c.Request) {
		return &base.Response{StatusCode: base.StatusUnauthorized}, nil, liberrors.ErrServerAuth{}
	}
	return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
}
func (h *hikLiveCameraFixture) OnPlay(*gortsplib.ServerHandlerOnPlayCtx) (*base.Response, error) {
	return &base.Response{StatusCode: base.StatusOK}, nil
}
func newHikLiveHTTPSFixture(t *testing.T, backend, mode string) *digestHTTPSFixture {
	t.Helper()
	f := &digestHTTPSFixture{mode: "sha256", calls: map[string]int{}, authorized: map[string]int{}, getDone: make(chan struct{}), control: make(chan []byte, 1), backend: backend, bridgeTimeout: 10 * time.Second}
	f.server = httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/ISAPI/System/deviceInfo" && r.URL.Path != "/ISAPI/Streaming/channels" {
			f.serve(w, r)
			return
		}
		if r.Method != "GET" || r.URL.RawQuery != "" || r.Host != net.JoinHostPort(f.source.ServerName, strconv.Itoa(f.source.Port)) {
			w.WriteHeader(404)
			return
		}
		if mode == "blocked_probe" {
			<-r.Context().Done()
			return
		}
		var a headers.Authorization
		if a.Unmarshal(base.HeaderValue{r.Header.Get("Authorization")}) != nil || a.Method != headers.AuthMethodDigest || a.Username != "fixture-reader-auth" || a.Realm != "isapi-fixture" || a.Nonce != "isapi-nonce" || a.URI != r.URL.Path || a.Cnonce == nil || a.Nc == nil || *a.Nc != "00000001" || a.Qop == nil || *a.Qop != "auth" {
			w.Header().Set("WWW-Authenticate", `Digest realm="isapi-fixture",nonce="isapi-nonce",algorithm=SHA-256,qop="auth"`)
			w.WriteHeader(401)
			return
		}
		expected := fixtureHash("SHA-256", fixtureHash("SHA-256", "fixture-reader-auth:isapi-fixture:fixture-secret")+":isapi-nonce:00000001:"+*a.Cnonce+":auth:"+fixtureHash("SHA-256", "GET:"+r.URL.Path))
		if a.Response != expected {
			w.WriteHeader(401)
			return
		}
		f.mu.Lock()
		f.authorized[r.URL.Path]++
		f.mu.Unlock()
		body := hikvisionIdentityFixture
		if r.URL.Path == "/ISAPI/Streaming/channels" {
			body = hikvisionChannelsFixture(hikvisionChannelFixture)
		}
		switch mode {
		case "replacement":
			body = strings.Replace(body, "fixture-camera-serial", "replacement-serial", 1)
		case "firmware":
			body = strings.Replace(body, "fixture-version", "different-firmware", 1)
		case "channel":
			body = strings.Replace(body, "<id>102</id>", "<id>101</id>", 1)
		case "codec":
			body = strings.Replace(body, "H.264", "H.265", 1)
		case "audio":
			body = strings.Replace(body, "<Audio><enabled>false</enabled></Audio>", "", 1)
		}
		w.Header().Set("Content-Type", "application/xml")
		_, _ = io.WriteString(w, body)
	}))
	f.server.Config.ErrorLog = log.New(io.Discard, "", 0)
	f.server.StartTLS()
	u, _ := url.Parse(f.server.URL)
	port, _ := strconv.Atoi(u.Port())
	pin := sha256.Sum256(f.server.Certificate().Raw)
	f.source = httpsTunnelSource{IP: u.Hostname(), ServerName: u.Hostname(), Pin: hex.EncodeToString(pin[:]), Port: port, Path: "/Streaming/channels/102", Username: "fixture-reader-auth", Password: "fixture-secret"}
	f.roots = x509.NewCertPool()
	f.roots.AddCert(f.server.Certificate())
	t.Cleanup(func() {
		f.mu.Lock()
		if f.rtsp != nil {
			_ = f.rtsp.Close()
		}
		if f.get != nil {
			_ = f.get.Close()
		}
		f.mu.Unlock()
		f.server.Close()
	})
	return f
}
func runHikLivePHP(t *testing.T, input map[string]any) []byte {
	t.Helper()
	executable, fixture := os.Getenv("OBLI_HIKVISION_LIVE_PHP_EXECUTABLE"), os.Getenv("OBLI_HIKVISION_LIVE_PHP_PRODUCER")
	if executable == "" || fixture == "" {
		t.Fatal("original PHP live fixture was requested without explicit runtime/producer")
	}
	raw, e := json.Marshal(input)
	if e != nil {
		t.Fatal(e)
	}
	cmd := exec.CommandContext(t.Context(), executable, fixture)
	cmd.Stdin = bytes.NewReader(raw)
	output, e := cmd.CombinedOutput()
	if e != nil {
		t.Fatalf("original PHP live producer/consumer unavailable: %s", output)
	}
	return output
}
func TestHikvisionLiveActualTLSProbeDigestTunnelRelayH264(t *testing.T) {
	for _, mode := range []string{"udp", "tcp", "replacement", "firmware", "channel", "codec", "audio", "rtsp_basic", "rtsp_unchallenged", "redirect", "sdp_channel", "sdp_foreign", "blocked_probe"} {
		t.Run(mode, func(t *testing.T) { realHikLiveToRelay(t, mode, false) })
	}
}
func TestHikvisionLiveOriginalPHPSealedProducerRelayAndConsumer(t *testing.T) {
	if os.Getenv("OBLI_HIKVISION_LIVE_PHP_PRODUCER") == "" {
		t.Skip("opt-in original PHP runtime witness")
	}
	for _, mode := range []string{"udp", "tcp"} {
		t.Run(mode, func(t *testing.T) { realHikLiveToRelay(t, mode, true) })
	}
}
func realHikLiveToRelay(t *testing.T, mode string, php bool) {
	ctx, cancel := context.WithTimeout(t.Context(), 15*time.Second)
	defer cancel()
	listener, e := net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	handler := &hikLiveCameraFixture{mode: mode}
	server := &gortsplib.Server{RTSPAddress: listener.Addr().String(), Listen: func(string, string) (net.Listener, error) { return listener, nil }, AuthMethods: []auth.VerifyMethod{auth.VerifyMethodDigestSHA256}, Handler: handler}
	if e = server.Start(); e != nil {
		listener.Close()
		t.Fatal(e)
	}
	defer server.Close()
	protocol := "udp"
	if mode == "tcp" {
		protocol = "tcp"
	}
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
	relayTCP, e := net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	defer relayTCP.Close()
	relay, e := turn.NewServer(turn.ServerConfig{Realm: "fixture", LoggerFactory: logger, ListenerConfigs: []turn.ListenerConfig{{Listener: relayTCP, RelayAddressGenerator: &turn.RelayAddressGeneratorStatic{RelayAddress: net.ParseIP("127.0.0.1"), Address: "127.0.0.1"}}}, AuthHandler: func(a *turn.RequestAttributes) (string, []byte, bool) {
		return a.Username, turn.GenerateAuthKey(a.Username, "fixture", "private-fixture"), true
	}, PacketConnConfigs: []turn.PacketConnConfig{{PacketConn: relayListener, RelayAddressGenerator: &turn.RelayAddressGeneratorStatic{RelayAddress: net.ParseIP("127.0.0.1"), Address: "127.0.0.1"}}}})
	if e != nil {
		t.Fatal(e)
	}
	defer relay.Close()
	defer relayListener.Close()
	turnAddress := relayListener.LocalAddr().String()
	if protocol == "tcp" {
		turnAddress = relayTCP.Addr().String()
	}
	turnURL := fmt.Sprintf("turn:%s?transport=%s", turnAddress, protocol)
	settings := webrtc.SettingEngine{}
	settings.SetIncludeLoopbackCandidate(true)
	settings.SetIPFilter(func(ip net.IP) bool { return ip.IsLoopback() })
	settings.SetRemoteIPFilter(func(ip net.IP) bool { return ip.IsLoopback() })
	settings.SetNetworkTypes([]webrtc.NetworkType{webrtc.NetworkTypeUDP4})
	settings.LoggerFactory = logger
	engine := &webrtc.MediaEngine{}
	profile := fmt.Sprintf("%02x%02x%02x", sps[1], sps[2], sps[3])
	if e = engine.RegisterCodec(webrtc.RTPCodecParameters{RTPCodecCapability: webrtc.RTPCodecCapability{MimeType: webrtc.MimeTypeH264, ClockRate: 90000, SDPFmtpLine: "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=" + profile}, PayloadType: 102}, webrtc.RTPCodecTypeVideo); e != nil {
		t.Fatal(e)
	}
	viewer, e := webrtc.NewAPI(webrtc.WithSettingEngine(settings), webrtc.WithMediaEngine(engine)).NewPeerConnection(webrtc.Configuration{ICETransportPolicy: webrtc.ICETransportPolicyRelay, ICEServers: []webrtc.ICEServer{{URLs: []string{turnURL}, Username: "fixture", Credential: "private-fixture"}}})
	if e != nil {
		t.Fatal(e)
	}
	defer viewer.Close()
	if _, e = viewer.AddTransceiverFromKind(webrtc.RTPCodecTypeVideo, webrtc.RTPTransceiverInit{Direction: webrtc.RTPTransceiverDirectionRecvonly}); e != nil {
		t.Fatal(e)
	}
	received := make(chan []byte, 1)
	viewer.OnTrack(func(track *webrtc.TrackRemote, _ *webrtc.RTPReceiver) {
		p, _, e := track.ReadRTP()
		if e == nil {
			received <- p.Payload
		}
	})
	offer, e := viewer.CreateOffer(nil)
	if e != nil {
		t.Fatal(e)
	}
	gather := webrtc.GatheringCompletePromise(viewer)
	if viewer.SetLocalDescription(offer) != nil {
		t.Fatal("offer")
	}
	select {
	case <-ctx.Done():
		t.Fatal("gather")
	case <-gather:
	}
	fixture := newHikLiveHTTPSFixture(t, listener.Addr().String(), mode)
	if mode == "sdp_channel" {
		m.Control = "rtsp://" + net.JoinHostPort(fixture.source.ServerName, strconv.Itoa(fixture.source.Port)) + "/Streaming/channels/101/trackID=0"
	}
	if mode == "sdp_foreign" {
		m.Control = "rtsp://foreign.example.test/Streaming/channels/102/trackID=0"
	}
	now := time.Now().UTC().Truncate(time.Second)
	sourceRaw, _ := json.Marshal(hikvisionDiscoverySource{IP: fixture.source.IP, Port: fixture.source.Port, ServerName: fixture.source.ServerName, Pin: fixture.source.Pin, Username: fixture.source.Username, Password: fixture.source.Password})
	turnMaterial := Turn{URLs: []string{turnURL}, Username: "fixture", Credential: "private-fixture", ExpiresAt: now.Add(time.Minute).Format("2006-01-02T15:04:05Z"), RelayCIDRs: []string{"127.0.0.0/24"}, RelayPortMin: 32768, RelayPortMax: 65535}
	turnRaw, _ := json.Marshal(turnMaterial)
	// The synthetic Pion viewer removes its component-2/related-address metadata before submission. This is not an original browser offer; PHP and native keep these submitted bytes unchanged.
	offerSDP := normalizeOwnAnswer(viewer.LocalDescription().SDP)
	fields := hikLiveMap(t, sourceRaw, turnRaw, offerSDP)
	raw, _ := json.Marshal(fields)
	g, e := verifyHikvisionLivePayload(raw, "11111111-1111-4111-8111-111111111111", 1, time.Now())
	if e != nil {
		t.Fatal("live grant", e)
	}
	source, e := decodeMediaSource(sourceRaw, g)
	if e != nil {
		t.Fatal("source", e)
	}
	var session Media
	var answer Description
	var code string
	var reply Reply
	var input map[string]any
	var worker *Worker
	if php {
		inventory := discoveryInventoryFixture(t)
		input = map[string]any{"mode": "produce", "issued_at": now.Format("2006-01-02T15:04:05Z"), "identity": inventory.Identity, "channel": inventory.Channels[0], "source": json.RawMessage(sourceRaw), "turn": turnMaterial, "offer_sdp": offerSDP}
		frames := bytes.NewReader(runHikLivePHP(t, input))
		configure, e := ReadFrame(frames)
		if e != nil {
			t.Fatal("original configure")
		}
		open, e := ReadFrame(frames)
		if e != nil || frames.Len() != 0 {
			t.Fatal("original open")
		}
		worker = New(func(parent context.Context, grant Grant, source Source, turn Turn, offer Description) (Media, Description, string) {
			return openMedia(parent, grant, source, turn, offer, fixture.roots, &settings)
		})
		defer worker.Close()
		if worker.Handle(ctx, configure).Status != "requested" {
			t.Fatal("original configure rejected")
		}
		reply = worker.Handle(ctx, open)
		if reply.Status != "ready" || reply.Answer == nil {
			t.Fatalf("original producer not ready: %s", reply.Status)
		}
		answer = *reply.Answer
	} else {
		if mode == "blocked_probe" {
			short, cancelShort := context.WithTimeout(ctx, 150*time.Millisecond)
			defer cancelShort()
			session, answer, code = openMedia(short, g, source, turnMaterial, Description{"offer", offerSDP}, fixture.roots, &settings)
		} else {
			session, answer, code = openMedia(ctx, g, source, turnMaterial, Description{"offer", offerSDP}, fixture.roots, &settings)
		}
		if mode != "udp" && mode != "tcp" {
			if session != nil {
				_ = session.Close()
				t.Fatal("denied source unexpectedly opened")
			}
			if code != "source_unavailable" {
				t.Fatalf("denial unavailable: %s", code)
			}
			fixture.mu.Lock()
			count := fixture.authorized["GET"] + fixture.authorized["POST"]
			fixture.mu.Unlock()
			if mode != "redirect" && mode != "sdp_channel" && mode != "sdp_foreign" && mode != "rtsp_basic" && mode != "rtsp_unchallenged" && count != 0 {
				t.Fatal("changed observation reached tunnel")
			}
			return
		}
		if code != "" {
			t.Fatal("real Hikvision source unavailable", code)
		}
		defer session.Close()
	}
	if viewer.SetRemoteDescription(webrtc.SessionDescription{Type: webrtc.SDPTypeAnswer, SDP: answer.SDP}) != nil {
		t.Fatal("answer")
	}
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
			t.Fatalf("no live media; viewer ICE %s, authenticated RTSP requests %d", viewer.ICEConnectionState(), handler.digest.Load())
		case p := <-received:
			if len(p) == 0 {
				t.Fatal("empty")
			}
			fixture.mu.Lock()
			authenticated := fixture.authorized["/ISAPI/System/deviceInfo"] == 1 && fixture.authorized["/ISAPI/Streaming/channels"] == 1 && fixture.authorized["GET"] == 1 && fixture.authorized["POST"] == 1
			fixture.mu.Unlock()
			if !authenticated || handler.digest.Load() < 2 {
				t.Fatal("fresh probe or both Digest layers missing")
			}
			if php {
				input["mode"] = "consume"
				input["result"] = reply
				if string(runHikLivePHP(t, input)) != "accepted\n" {
					t.Fatal("original consumer rejected actual answer")
				}
				stop := Message{Version: 1, Operation: "close", Request: reply.Request, Session: reply.Session, Generation: reply.Generation}
				if worker.Handle(ctx, wire(t, stop)).Status != "stopped" {
					t.Fatal("original peer did not stop")
				}
			}
			t.Log("synthetic pinned TLS, fresh ISAPI identity/channel, both HTTP Digest connections, RTSP Digest, TURN and received H264; no browser or hardware acceptance")
			return
		case <-ticker.C:
			packets, e := encoder.Encode(au)
			if e != nil {
				t.Fatal(e)
			}
			for _, p := range packets {
				p.Timestamp = timestamp
				if e = handler.stream.WritePacketRTP(m, p); e != nil {
					t.Fatal(e)
				}
			}
			timestamp += 9000
		}
	}
}
