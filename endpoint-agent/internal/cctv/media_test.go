package cctv

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"fmt"
	"github.com/bluenviron/gortsplib/v5"
	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/description"
	"github.com/bluenviron/gortsplib/v5/pkg/format"
	"github.com/bluenviron/gortsplib/v5/pkg/format/rtph264"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/pion/logging"
	"github.com/pion/turn/v5"
	"github.com/pion/webrtc/v4"
	"io"
	"math/big"
	"net"
	"os"
	"strconv"
	"testing"
	"time"
)

type cameraFixture struct{ stream *gortsplib.ServerStream }

func (h *cameraFixture) OnDescribe(*gortsplib.ServerHandlerOnDescribeCtx) (*base.Response, *gortsplib.ServerStream, error) {
	return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
}
func (h *cameraFixture) OnSetup(*gortsplib.ServerHandlerOnSetupCtx) (*base.Response, *gortsplib.ServerStream, error) {
	return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
}
func (h *cameraFixture) OnPlay(*gortsplib.ServerHandlerOnPlayCtx) (*base.Response, error) {
	return &base.Response{StatusCode: base.StatusOK}, nil
}
func TestRealRTSPSTCPH264ToRelayVideo(t *testing.T) {
	for _, protocol := range []string{"udp", "tcp"} {
		t.Run(protocol, func(t *testing.T) { realRTSPToRelayVideo(t, protocol) })
	}
}
func realRTSPToRelayVideo(t *testing.T, protocol string) {
	ctx, cancel := context.WithTimeout(t.Context(), 15*time.Second)
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
	_, port, _ := net.SplitHostPort(address)
	number, _ := strconv.Atoi(port)
	g := Grant{IP: "127.0.0.1", Port: number, ServerName: "nvr.test", Pin: hex.EncodeToString(pin[:])}
	source := Source{URL: "rtsps://nvr.test:" + port + "/fixture?enableSrtp"}
	session, answer, code := openMedia(ctx, g, source, Turn{URLs: []string{turnURL}, Username: "fixture", Credential: "private-fixture", RelayCIDRs: []string{"127.0.0.0/24"}, RelayPortMin: 32768, RelayPortMax: 65535}, Description{"offer", normalizeOwnAnswer(viewer.LocalDescription().SDP)}, roots, &settings)
	if code != "" {
		t.Fatal("real source", code)
	}
	defer session.Close()
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
			t.Fatal("no live media")
		case p := <-received:
			if len(p) == 0 {
				t.Fatal("empty")
			}
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
