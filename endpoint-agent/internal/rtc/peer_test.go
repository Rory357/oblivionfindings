package rtc

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha1"
	"encoding/base64"
	"encoding/binary"
	"fmt"
	"image"
	"image/color"
	"image/jpeg"
	"io"
	"math/rand"
	"net"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/pion/logging"
	"github.com/pion/turn/v5"
	"github.com/pion/webrtc/v4"
)

func loopbackAPI() *webrtc.API {
	settings := webrtc.SettingEngine{}
	settings.SetIncludeLoopbackCandidate(true)
	settings.SetIPFilter(func(ip net.IP) bool { return ip.IsLoopback() })
	settings.SetRemoteIPFilter(func(ip net.IP) bool { return ip.IsLoopback() })
	settings.SetNetworkTypes([]webrtc.NetworkType{webrtc.NetworkTypeUDP4})
	settings.SetSCTPMaxMessageSize(8192)
	logger := logging.NewDefaultLoggerFactory()
	logger.Writer = io.Discard
	settings.LoggerFactory = logger
	return webrtc.NewAPI(webrtc.WithSettingEngine(settings))
}
func fixtureRelayPassword(username string) string {
	mac := hmac.New(sha1.New, []byte("loopback-only-fixture-secret-never-deploy"))
	_, _ = mac.Write([]byte(username))
	return base64.StdEncoding.EncodeToString(mac.Sum(nil))
}
func relayFixture(t *testing.T) []webrtc.ICEServer {
	t.Helper()
	listener, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	logger := logging.NewDefaultLoggerFactory()
	logger.Writer = io.Discard
	server, err := turn.NewServer(turn.ServerConfig{Realm: "obli.test", LoggerFactory: logger,
		AuthHandler: func(attributes *turn.RequestAttributes) (string, []byte, bool) {
			parts := strings.SplitN(attributes.Username, ":", 2)
			if len(parts) != 2 {
				return "", nil, false
			}
			expiry, err := strconv.ParseInt(parts[0], 10, 64)
			if err != nil || expiry <= time.Now().Unix() || !strings.HasPrefix(parts[1], "obli-") {
				return "", nil, false
			}
			return attributes.Username, turn.GenerateAuthKey(attributes.Username, "obli.test", fixtureRelayPassword(attributes.Username)), true
		},
		PacketConnConfigs: []turn.PacketConnConfig{{PacketConn: listener, RelayAddressGenerator: &turn.RelayAddressGeneratorStatic{RelayAddress: net.ParseIP("127.0.0.1"), Address: "127.0.0.1"}}},
	})
	if err != nil {
		listener.Close()
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = server.Close(); _ = listener.Close() })
	username := strconv.FormatInt(time.Now().Add(time.Minute).Unix(), 10) + ":obli-agent-" + strings.Repeat("a", 64)
	return []webrtc.ICEServer{{URLs: []string{fmt.Sprintf("turn:%s?transport=udp", listener.LocalAddr())}, Username: username, Credential: fixtureRelayPassword(username)}}
}
func rtcFixture(t *testing.T, mode string) (*Peer, *webrtc.DataChannel, chan []byte, chan Input, *atomic.Bool, *atomic.Bool) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	t.Cleanup(cancel)
	alive, active := &atomic.Bool{}, &atomic.Bool{}
	alive.Store(true)
	inputs := make(chan Input, 8)
	config := Config{SessionUUID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", DesktopSession: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", OfferUUID: "cccccccc-cccc-4ccc-8ccc-cccccccccccc", AccessMode: mode, Servers: relayFixture(t),
		Authorize: func(op string) bool { return alive.Load() && (op == "negotiate" || active.Load()) }, Input: func(input Input) error {
			select {
			case inputs <- input:
				return nil
			default:
				return ErrUnavailable
			}
		}}
	peer, err := newPeer(ctx, loopbackAPI(), config, true)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(peer.Close)
	viewerRelay := config.Servers[0]
	viewerRelay.Username = strings.Replace(viewerRelay.Username, ":obli-agent-", ":obli-technician-", 1)
	viewerRelay.Credential = fixtureRelayPassword(viewerRelay.Username)
	viewer, err := loopbackAPI().NewPeerConnection(webrtc.Configuration{ICEServers: []webrtc.ICEServer{viewerRelay}, ICETransportPolicy: webrtc.ICETransportPolicyRelay})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = viewer.Close() })
	opened := make(chan *webrtc.DataChannel, 1)
	frames := make(chan []byte, 150)
	viewer.OnDataChannel(func(channel *webrtc.DataChannel) {
		if channel.Label() != ChannelLabel || channel.Protocol() != ChannelLabel+"/"+config.SessionUUID+"/"+config.DesktopSession+"/"+config.OfferUUID {
			t.Error("Unbound channel")
		}
		channel.OnOpen(func() { opened <- channel })
		channel.OnMessage(func(message webrtc.DataChannelMessage) {
			if message.IsString {
				t.Error("Expected binary image chunks")
				return
			}
			select {
			case frames <- append([]byte(nil), message.Data...):
			default:
				t.Error("Unbounded frames")
			}
		})
	})
	offer, err := peer.Offer(ctx)
	if err != nil {
		t.Fatal("offer", err)
	}
	if validateDescription(strings.ReplaceAll(offer, " typ relay", " typ host"), true) == nil {
		t.Fatal("Non-relay candidate accepted")
	}
	if viewer.SetRemoteDescription(webrtc.SessionDescription{Type: webrtc.SDPTypeOffer, SDP: offer}) != nil {
		t.Fatal("viewer offer")
	}
	answer, err := viewer.CreateAnswer(nil)
	if err != nil {
		t.Fatal(err)
	}
	gathered := webrtc.GatheringCompletePromise(viewer)
	if viewer.SetLocalDescription(answer) != nil {
		t.Fatal("local answer")
	}
	select {
	case <-gathered:
	case <-ctx.Done():
		t.Fatal("answer gathering timeout")
	}
	if err = peer.ApplyAnswer(viewer.LocalDescription().SDP); err != nil {
		t.Fatal("answer", err)
	}
	select {
	case <-peer.Ready():
	case <-ctx.Done():
		t.Fatal("agent data channel timeout")
	}
	select {
	case channel := <-opened:
		return peer, channel, frames, inputs, alive, active
	case <-ctx.Done():
		t.Fatal("viewer data channel timeout")
	}
	return nil, nil, nil, nil, nil, nil
}
func fixtureJPEG(t *testing.T) []byte {
	t.Helper()
	picture := image.NewRGBA(image.Rect(0, 0, 128, 128))
	random := rand.New(rand.NewSource(5))
	for y := 0; y < 128; y++ {
		for x := 0; x < 128; x++ {
			picture.SetRGBA(x, y, color.RGBA{uint8(random.Intn(256)), uint8(random.Intn(256)), uint8(random.Intn(256)), 255})
		}
	}
	var bytes bytes.Buffer
	if jpeg.Encode(&bytes, picture, &jpeg.Options{Quality: 80}) != nil {
		t.Fatal("fixture encode")
	}
	return bytes.Bytes()
}
func awaitClosed(t *testing.T, peer *Peer) {
	t.Helper()
	deadline := time.After(2 * time.Second)
	tick := time.NewTicker(10 * time.Millisecond)
	defer tick.Stop()
	for {
		if peer.Closed() {
			return
		}
		select {
		case <-deadline:
			t.Fatal("RTC authority survived revocation")
		case <-tick.C:
		}
	}
}
func TestEncryptedRelayCarriesOnlyActiveFramesAndBoundedOrderedInput(t *testing.T) {
	peer, channel, frames, inputs, _, active := rtcFixture(t, "control")
	picture := fixtureJPEG(t)
	if peer.SendFrame(picture, 128, 128) == nil {
		t.Fatal("Capture sent before active authority")
	}
	active.Store(true)
	if err := peer.SendFrame(picture, 128, 128); err != nil {
		t.Fatal(err)
	}
	count := (len(picture) + ChunkSize - 1) / ChunkSize
	var received []byte
	for index := 0; index < count; index++ {
		select {
		case message := <-frames:
			if len(message) < FrameHeader || string(message[:4]) != "OBL1" || binary.BigEndian.Uint32(message[8:]) != uint32(len(picture)) || binary.BigEndian.Uint16(message[16:]) != uint16(index) || binary.BigEndian.Uint16(message[18:]) != uint16(count) {
				t.Fatal("Frame was not bounded and ordered")
			}
			received = append(received, message[FrameHeader:]...)
		case <-time.After(time.Second):
			t.Fatal("Frame did not arrive")
		}
	}
	if !bytes.Equal(received, picture) {
		t.Fatal("Image changed in encrypted transport")
	}
	input := `{"version":1,"sequence":1,"type":"move","x":32000,"y":42000}`
	if err := channel.SendText(input); err != nil {
		t.Fatal(err)
	}
	select {
	case got := <-inputs:
		if got.X != 32000 || got.Y != 42000 {
			t.Fatal("Input changed")
		}
	case <-time.After(time.Second):
		t.Fatal("Input missing")
	}
	if err := channel.SendText(input); err != nil {
		t.Fatal(err)
	}
	awaitClosed(t, peer)
	if peer.SendFrame(picture, 128, 128) == nil {
		t.Fatal("Frame sent after replay closed session")
	}
}
func TestRelayImmediatelyDeniesViewOnlyInputAndLostLease(t *testing.T) {
	t.Run("view-only", func(t *testing.T) {
		peer, channel, _, inputs, _, active := rtcFixture(t, "view")
		active.Store(true)
		_ = channel.SendText(`{"version":1,"sequence":1,"type":"key_down","key":65}`)
		awaitClosed(t, peer)
		select {
		case <-inputs:
			t.Fatal("View-only session executed input")
		default:
		}
	})
	t.Run("lost-lease", func(t *testing.T) {
		peer, _, _, _, alive, active := rtcFixture(t, "control")
		active.Store(true)
		alive.Store(false)
		if peer.SendFrame(fixtureJPEG(t), 128, 128) == nil {
			t.Fatal("Frame sent after lease withdrawal")
		}
		awaitClosed(t, peer)
	})
}
func TestInputProtocolRejectsCommandsDuplicatesAndUnexpectedFields(t *testing.T) {
	for _, input := range []string{
		`{"version":1,"sequence":1,"type":"move","x":0,"y":65535}`,
		`{"version":1,"sequence":2,"type":"key_up","key":65}`,
		`{"version":1,"sequence":3,"type":"wheel","delta":-120,"x":0,"y":0}`,
	} {
		if _, err := DecodeInput([]byte(input)); err != nil {
			t.Fatal("Valid input rejected", err)
		}
	}
	for _, input := range []string{
		`{"version":1,"sequence":1,"type":"execute","command":"cmd"}`,
		`{"version":1,"sequence":1,"type":"key_up","key":65,"key":66}`,
		`{"version":1,"sequence":0,"type":"key_up","key":65}`,
		`{"version":1,"sequence":9007199254740992,"type":"key_up","key":65}`,
		`{"version":1,"sequence":1,"type":"key_up","key":256}`,
		`{"version":1,"sequence":1,"type":"move","x":-1,"y":0}`,
		`{"version":1,"sequence":1,"type":"move","x":0.5,"y":0}`,
		`{"version":1,"sequence":1,"type":"move","x":0,"y":0,"extra":null}`,
		`{"version":1,"sequence":1,"type":"button_down","button":4,"x":0,"y":0}`,
	} {
		if _, err := DecodeInput([]byte(input)); err == nil {
			t.Fatal("Unsafe input accepted")
		}
	}
}
func TestProductionConstructorRequiresExplicitRelayAndPermission(t *testing.T) {
	if peer, err := New(context.Background(), Config{}); err == nil {
		peer.Close()
		t.Fatal("Unconfigured media opened")
	}
	if peer, err := New(context.Background(), Config{Servers: []webrtc.ICEServer{{URLs: []string{"stun:public.example.test"}, Username: "x", Credential: "x"}}}); err == nil {
		peer.Close()
		t.Fatal("Public STUN fallback accepted")
	}
}
