package cctv

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"

	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/bluenviron/gortsplib/v5"
	"github.com/bluenviron/gortsplib/v5/pkg/auth"
	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/description"
	"github.com/bluenviron/gortsplib/v5/pkg/format"
	"github.com/bluenviron/gortsplib/v5/pkg/format/rtph264"
	"github.com/bluenviron/gortsplib/v5/pkg/liberrors"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/pion/rtp"
)

type secureTunnelFixture struct {
	stream   *gortsplib.ServerStream
	redirect bool
	digest   atomic.Int32
}

func (h *secureTunnelFixture) authorize(c *gortsplib.ServerConn, r *base.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/ISAPI/Streaming/channels/102") {
		return false
	}
	if !c.VerifyCredentials(r, "media", "fixture-secret") {
		return false
	}
	if len(r.Header["Authorization"]) != 1 || !strings.HasPrefix(r.Header["Authorization"][0], "Digest ") {
		return false
	}
	h.digest.Add(1)
	return true
}
func (h *secureTunnelFixture) OnDescribe(c *gortsplib.ServerHandlerOnDescribeCtx) (*base.Response, *gortsplib.ServerStream, error) {
	if !h.authorize(c.Conn, c.Request) {
		return &base.Response{StatusCode: base.StatusUnauthorized}, nil, liberrors.ErrServerAuth{}
	}
	if h.redirect {
		return &base.Response{StatusCode: base.StatusMovedPermanently, Header: base.Header{"Location": base.HeaderValue{"rtsps://foreign.test:443/steal"}}}, nil, nil
	}
	return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
}
func (h *secureTunnelFixture) OnSetup(c *gortsplib.ServerHandlerOnSetupCtx) (*base.Response, *gortsplib.ServerStream, error) {
	if !h.authorize(c.Conn, c.Request) {
		return &base.Response{StatusCode: base.StatusUnauthorized}, nil, liberrors.ErrServerAuth{}
	}
	return &base.Response{StatusCode: base.StatusOK}, h.stream, nil
}
func (h *secureTunnelFixture) OnPlay(*gortsplib.ServerHandlerOnPlayCtx) (*base.Response, error) {
	return &base.Response{StatusCode: base.StatusOK}, nil
}

type tunnelWitness struct {
	sync.Mutex
	get, post, cookie string
	writes            int
}
type witnessedTunnelConn struct {
	net.Conn
	witness *tunnelWitness
	first   bool
}

func (c *witnessedTunnelConn) Write(p []byte) (int, error) {
	c.witness.Lock()
	defer c.witness.Unlock()
	if !c.first {
		c.first = true
		s := string(p)
		var cookie string
		for _, line := range strings.Split(s, "\r\n") {
			if strings.HasPrefix(line, "X-Sessioncookie: ") {
				cookie = strings.TrimPrefix(line, "X-Sessioncookie: ")
			}
		}
		if strings.HasPrefix(s, "GET ") {
			c.witness.get = s
			c.witness.cookie = cookie
		} else if strings.HasPrefix(s, "POST ") {
			c.witness.post = s
			if cookie != c.witness.cookie {
				return 0, ErrProtocol
			}
		} else {
			return 0, ErrProtocol
		}
	} else {
		c.witness.writes++
	}
	return c.Conn.Write(p)
}
func TestSyntheticHikvisionHTTPSRTSPTunnel(t *testing.T) {
	for _, mode := range []string{"h264", "wrong_pin", "wrong_name", "untrusted_cert", "wrong_channel", "wrong_password", "redirect"} {
		t.Run(mode, func(t *testing.T) {
			key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
			template := &x509.Certificate{SerialNumber: big.NewInt(9), DNSNames: []string{"hikvision.test"}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
			der, _ := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
			kb, _ := x509.MarshalECPrivateKey(key)
			cert, e := tls.X509KeyPair(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}))
			if e != nil {
				t.Fatal(e)
			}
			roots := x509.NewCertPool()
			parsed, _ := x509.ParseCertificate(der)
			roots.AddCert(parsed)
			pin := sha256.Sum256(der)
			l, e := net.Listen("tcp4", "127.0.0.1:0")
			if e != nil {
				t.Fatal(e)
			}
			address := l.Addr().String()
			l.Close()
			h := &secureTunnelFixture{redirect: mode == "redirect"}
			server := &gortsplib.Server{RTSPAddress: address, TLSConfig: &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}, AuthMethods: []auth.VerifyMethod{auth.VerifyMethodDigestSHA256}, Handler: h}
			if e = server.Start(); e != nil {
				t.Fatal(e)
			}
			defer server.Close()
			raw, e := os.ReadFile("testdata/pattern.h264")
			if e != nil {
				t.Fatal(e)
			}
			var au h264.AnnexB
			if au.Unmarshal(raw) != nil {
				t.Fatal("H264 fixture")
			}
			f := &format.H264{PayloadTyp: 96, PacketizationMode: 1}
			for _, n := range au {
				switch n[0] & 31 {
				case 7:
					f.SPS = n
				case 8:
					f.PPS = n
				}
			}
			media := &description.Media{Type: description.MediaTypeVideo, Control: "trackID=0", Formats: []format.Format{f}}
			h.stream = &gortsplib.ServerStream{Server: server, Desc: &description.Session{Medias: []*description.Media{media}}}
			if h.stream.Initialize() != nil {
				t.Fatal("stream")
			}
			defer h.stream.Close()
			config := &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots, ServerName: "hikvision.test", VerifyConnection: func(s tls.ConnectionState) error {
				actual := sha256.Sum256(s.PeerCertificates[0].Raw)
				if actual != pin || mode == "wrong_pin" {
					return ErrProtocol
				}
				return nil
			}}
			if mode == "wrong_name" {
				config.ServerName = "foreign.test"
			}
			if mode == "untrusted_cert" {
				config.RootCAs = x509.NewCertPool()
			}
			var witness tunnelWitness
			var dials atomic.Int32
			protocol := gortsplib.ProtocolTCP
			c := &gortsplib.Client{Scheme: "rtsps", Host: "hikvision.test:" + strings.Split(address, ":")[1], Tunnel: gortsplib.TunnelHTTP, Protocol: &protocol, ReadTimeout: time.Second, WriteTimeout: time.Second, DialTLSContext: func(ctx context.Context, network, target string) (net.Conn, error) {
				if target != "hikvision.test:"+strings.Split(address, ":")[1] || network != "tcp" {
					return nil, ErrProtocol
				}
				dials.Add(1)
				conn, e := (&tls.Dialer{NetDialer: &net.Dialer{Timeout: time.Second}, Config: config}).DialContext(ctx, "tcp", address)
				if e != nil {
					return nil, e
				}
				return &witnessedTunnelConn{Conn: conn, witness: &witness}, nil
			}}
			c.OnResponse = func(r *base.Response) { delete(r.Header, "Location") }
			if c.Start() != nil {
				t.Fatal("client")
			}
			defer c.Close()
			path := "102"
			password := "fixture-secret"
			if mode == "wrong_channel" {
				path = "202"
			}
			if mode == "wrong_password" {
				password = "wrong"
			}
			u, e := base.ParseURL("rtsps://media:" + password + "@" + c.Host + "/ISAPI/Streaming/channels/" + path)
			if e != nil {
				t.Fatal(e)
			}
			desc, _, e := c.Describe(u)
			if mode != "h264" {
				if e == nil {
					t.Fatal("unsafe tunnel accepted", mode)
				}
				if dials.Load() > 2 {
					t.Fatal("redirect dial")
				}
				return
			}
			if e != nil {
				t.Fatal(e)
			}
			if len(desc.Medias) != 1 || desc.Medias[0].Type != description.MediaTypeVideo {
				t.Fatal("media identity")
			}
			if e = c.SetupAll(desc.BaseURL, desc.Medias); e != nil {
				t.Fatal(e)
			}
			received := make(chan bool, 1)
			c.OnPacketRTP(desc.Medias[0], desc.Medias[0].Formats[0], func(p *rtp.Packet) {
				if len(p.Payload) > 0 {
					select {
					case received <- true:
					default:
					}
				}
			})
			if _, e = c.Play(nil); e != nil {
				t.Fatal(e)
			}
			encoder := &rtph264.Encoder{PayloadType: 96, PacketizationMode: 1}
			if encoder.Init() != nil {
				t.Fatal("encoder")
			}
			packets, e := encoder.Encode(au)
			if e != nil {
				t.Fatal(e)
			}
			for _, p := range packets {
				h.stream.WritePacketRTP(media, p)
			}
			select {
			case <-received:
			case <-time.After(2 * time.Second):
				t.Fatal("no H264 tunnel media")
			}
			witness.Lock()
			defer witness.Unlock()
			if dials.Load() != 2 || h.digest.Load() < 2 || len(witness.cookie) != 32 || !strings.HasPrefix(witness.get, "GET /ISAPI/Streaming/channels/102 HTTP/1.1") || !strings.HasPrefix(witness.post, "POST /ISAPI/Streaming/channels/102 HTTP/1.1") || !strings.Contains(witness.post, "Content-Type: application/x-rtsp-tunnelled") || witness.writes == 0 {
				t.Fatal("two-connection tunnel/auth witness")
			}
			t.Log("synthetic HTTPS two-connection tunnel, SHA256 RTSP Digest and H264 received; no hardware claim")
		})
	}
}

// The tunnel's HTTP wrapper does not authenticate a 401 challenge. RTSP Digest
// success above therefore does not establish compatibility with an HTTP-Digest
// protected vendor tunnel endpoint.
func TestSyntheticHTTPSRTSPTunnelRejectsHTTPChallengeAndMalformedFraming(t *testing.T) {
	for _, mode := range []string{"http_digest", "malformed"} {
		t.Run(mode, func(t *testing.T) {
			var calls atomic.Int32
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				calls.Add(1)
				if r.URL.Path != "/ISAPI/Streaming/channels/102" || (r.Method != "GET" && r.Method != "POST") || r.Header.Get("Authorization") != "" {
					t.Error("unexpected wrapper request")
				}
				if mode == "http_digest" {
					w.Header().Set("WWW-Authenticate", `Digest realm="fixture",nonce="fixture",algorithm=SHA-256,qop="auth"`)
					w.WriteHeader(401)
					return
				}
				conn, _, e := w.(http.Hijacker).Hijack()
				if e != nil {
					return
				}
				defer conn.Close()
				conn.SetDeadline(time.Now().Add(time.Second))
				conn.Write([]byte("HTTP/1.1 200 OK\r\n\r\nnot-an-RTSP-response\r\n\r\n"))
			}))
			defer server.Close()
			parsed, e := url.Parse(server.URL)
			if e != nil {
				t.Fatal(e)
			}
			roots := x509.NewCertPool()
			roots.AddCert(server.Certificate())
			protocol := gortsplib.ProtocolTCP
			c := &gortsplib.Client{Scheme: "rtsps", Host: parsed.Host, Tunnel: gortsplib.TunnelHTTP, Protocol: &protocol, TLSConfig: &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots}, ReadTimeout: time.Second, WriteTimeout: time.Second}
			if c.Start() != nil {
				t.Fatal("client")
			}
			defer c.Close()
			u, _ := base.ParseURL("rtsps://media:fixture-secret@" + parsed.Host + "/ISAPI/Streaming/channels/102")
			if _, _, e = c.Describe(u); e == nil {
				t.Fatal("invalid tunnel accepted")
			}
			if mode == "http_digest" && calls.Load() != 1 {
				t.Fatal("wrapper silently retried authentication")
			}
		})
	}
}
