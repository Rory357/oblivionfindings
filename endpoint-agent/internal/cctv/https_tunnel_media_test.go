package cctv

import (
	"bufio"
	"context"
	"encoding/base64"
	"io"
	"net"
	"net/http"
	"os"
	"strconv"
	"testing"
	"time"

	"github.com/bluenviron/gortsplib/v5"
	"github.com/bluenviron/gortsplib/v5/pkg/auth"
	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/description"
	"github.com/bluenviron/gortsplib/v5/pkg/format"
	"github.com/bluenviron/gortsplib/v5/pkg/format/rtph264"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/pion/rtp"
)

// Test-only wrapper bridges the actual TLS/Digest GET and POST into a local
// independent RTSP server. Each padded base64 write is decoded as a stream of
// quartets, so multiple RTSP requests (and interleaved RTCP) are exercised.
func (f *digestHTTPSFixture) serveRTSPBridge(conn net.Conn, buffered *bufio.ReadWriter, request *http.Request) {
	if request.Method == "GET" {
		backend, err := net.DialTimeout("tcp", f.backend, time.Second)
		if err != nil {
			return
		}
		defer backend.Close()
		_ = backend.SetDeadline(time.Now().Add(3 * time.Second))
		if f.bridgeTimeout > 0 { // Live viewer needs time for both TURN peers.
			_ = backend.SetDeadline(time.Now().Add(f.bridgeTimeout))
			_ = conn.SetDeadline(time.Now().Add(f.bridgeTimeout))
		}
		f.mu.Lock()
		f.get, f.rtsp, f.getCookie = conn, backend, request.Header.Get("X-Sessioncookie")
		f.mu.Unlock()
		_, _ = io.WriteString(conn, "HTTP/1.1 200 OK\r\nContent-Type: application/x-rtsp-tunnelled\r\n\r\n")
		_, _ = io.Copy(conn, backend)
		return
	}
	f.mu.Lock()
	backend := f.rtsp
	f.mu.Unlock()
	if request.Method != "POST" || backend == nil || request.ContentLength != 30000 || request.Header.Get("Content-Type") != "application/x-rtsp-tunnelled" {
		return
	}
	if f.bridgeTimeout > 0 {
		_ = conn.SetDeadline(time.Now().Add(f.bridgeTimeout))
	}
	_, _ = io.WriteString(conn, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n")
	body := io.LimitReader(buffered.Reader, 30000)
	var quartet [4]byte
	var raw [3]byte
	for {
		if _, err := io.ReadFull(body, quartet[:]); err != nil {
			return
		}
		n, err := base64.StdEncoding.Decode(raw[:], quartet[:])
		if err != nil {
			return
		}
		if _, err = backend.Write(raw[:n]); err != nil {
			return
		}
	}
}

func TestAuthenticatedHTTPSRTSPTunnelActualH264AndRTSPDigest(t *testing.T) {
	for _, mode := range []string{"sha256", "md5"} {
		t.Run(mode, func(t *testing.T) {
			listener, err := net.Listen("tcp4", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			handler := &secureTunnelFixture{}
			server := &gortsplib.Server{RTSPAddress: listener.Addr().String(), Listen: func(string, string) (net.Listener, error) { return listener, nil }, AuthMethods: []auth.VerifyMethod{auth.VerifyMethodDigestSHA256}, Handler: handler}
			if err := server.Start(); err != nil {
				listener.Close()
				t.Fatal(err)
			}
			defer server.Close()
			raw, err := os.ReadFile("testdata/pattern.h264")
			if err != nil {
				t.Fatal(err)
			}
			var au h264.AnnexB
			if au.Unmarshal(raw) != nil {
				t.Fatal("H264 fixture")
			}
			videoFormat := &format.H264{PayloadTyp: 96, PacketizationMode: 1}
			for _, nal := range au {
				switch nal[0] & 31 {
				case 7:
					videoFormat.SPS = nal
				case 8:
					videoFormat.PPS = nal
				}
			}
			media := &description.Media{Type: description.MediaTypeVideo, Control: "trackID=0", Formats: []format.Format{videoFormat}}
			handler.stream = &gortsplib.ServerStream{Server: server, Desc: &description.Session{Medias: []*description.Media{media}}}
			if handler.stream.Initialize() != nil {
				t.Fatal("RTSP stream")
			}
			defer handler.stream.Close()
			fixture := newDigestHTTPSFixture(t, mode)
			fixture.backend = listener.Addr().String()
			lifetime, cancelLifetime := context.WithCancel(t.Context())
			defer cancelLifetime()
			protocol := gortsplib.ProtocolTCP
			host := net.JoinHostPort(fixture.source.ServerName, strconv.Itoa(fixture.source.Port))
			client := &gortsplib.Client{Scheme: "rtsps", Host: host, Protocol: &protocol, ReadTimeout: time.Second, WriteTimeout: time.Second, DialTLSContext: func(dial context.Context, network, target string) (net.Conn, error) {
				if network != "tcp" || target != host {
					return nil, ErrProtocol
				}
				return openAuthenticatedHTTPSRTSPTunnel(dial, lifetime, fixture.source, fixture.roots)
			}}
			client.OnResponse = func(response *base.Response) { delete(response.Header, "Location") }
			if client.Start() != nil {
				t.Fatal("RTSP client")
			}
			defer client.Close()
			uri, err := base.ParseURL("rtsps://media:fixture-secret@" + host + fixture.source.Path)
			if err != nil {
				t.Fatal("RTSP URI")
			}
			desc, _, err := client.Describe(uri)
			if err != nil {
				t.Fatal("RTSP describe through authenticated HTTPS", err)
			}
			if len(desc.Medias) != 1 || desc.Medias[0].Type != description.MediaTypeVideo {
				t.Fatal("unexpected media")
			}
			if err := client.SetupAll(desc.BaseURL, desc.Medias); err != nil {
				t.Fatal("RTSP setup", err)
			}
			received := make(chan *rtp.Packet, 1)
			client.OnPacketRTP(desc.Medias[0], desc.Medias[0].Formats[0], func(packet *rtp.Packet) {
				copyPacket := packet.Clone()
				select {
				case received <- copyPacket:
				default:
				}
			})
			if _, err := client.Play(nil); err != nil {
				t.Fatal("RTSP play", err)
			}
			encoder := &rtph264.Encoder{PayloadType: 96, PacketizationMode: 1}
			if encoder.Init() != nil {
				t.Fatal("H264 encoder")
			}
			packets, err := encoder.Encode(au)
			if err != nil {
				t.Fatal("H264 encode", err)
			}
			for _, packet := range packets {
				_ = handler.stream.WritePacketRTP(media, packet)
			}
			select {
			case packet := <-received:
				if packet.PayloadType != 96 || len(packet.Payload) == 0 {
					t.Fatal("H264 media payload missing")
				}
			case <-time.After(time.Second):
				t.Fatal("actual H264 media not received")
			}
			fixture.mu.Lock()
			getAuth, postAuth := fixture.authorized["GET"], fixture.authorized["POST"]
			cookie := fixture.getCookie
			fixture.mu.Unlock()
			if getAuth != 1 || postAuth != 1 || len(cookie) != 32 || handler.digest.Load() < 2 {
				t.Fatal("wrapper or RTSP Digest witness missing")
			}
			cancelLifetime()
			wait := make(chan error, 1)
			go func() { wait <- client.Wait() }()
			select {
			case err := <-wait:
				if err == nil {
					t.Fatal("closed tunnel did not fail RTSP session")
				}
			case <-time.After(time.Second):
				t.Fatal("media lease cancellation did not close client")
			}
			t.Log("synthetic TLS HTTP Digest on both wrapper connections, RTSP SHA256 Digest, H264 RTP and lifetime closure; no vendor hardware claim")
		})
	}
}
