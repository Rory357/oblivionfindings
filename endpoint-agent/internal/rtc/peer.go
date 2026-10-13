// Package rtc carries approved screen frames and bounded input events. It has no
// access to Windows capture/input APIs or machine credentials.
package rtc

import (
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"image/jpeg"
	"io"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pion/logging"
	"github.com/pion/webrtc/v4"
)

const ChannelLabel = "obli.remote.v1"
const FrameHeader = 20
const ChunkSize = 4096
const MaxFrame = 512 * 1024

var ErrUnavailable = errors.New("remote media is unavailable")
var ErrBackpressure = errors.New("remote viewer is still receiving the previous frame")

type Config struct {
	SessionUUID, DesktopSession, OfferUUID, AccessMode string
	Servers                                            []webrtc.ICEServer
	// Authorize must recheck the current local consent, OS desktop and lease.
	// Negotiate permits setup only; view/control require a live active lease.
	Authorize func(operation string) bool
	Input     func(Input) error
}
type Peer struct {
	pc                   *webrtc.PeerConnection
	channel              *webrtc.DataChannel
	config               Config
	relayOnly            bool
	cancel               context.CancelFunc
	done                 <-chan struct{}
	closed               atomic.Bool
	closeOnce, readyOnce sync.Once
	ready                chan struct{}
	mu                   sync.Mutex
	frame                uint32
	answer               string
	inputSequence        uint64
	inputWindow          time.Time
	inputCount           int
}

// New requires centrally supplied TURN configuration. There is no automatic
// public STUN server or direct-connection fallback in the production constructor.
func New(ctx context.Context, config Config) (*Peer, error) {
	if len(config.Servers) == 0 || len(config.Servers) > 4 {
		return nil, ErrUnavailable
	}
	for _, server := range config.Servers {
		secret, ok := server.Credential.(string)
		if !ok || secret == "" || len(secret) > 512 || server.Username == "" || len(server.Username) > 256 || len(server.URLs) == 0 || len(server.URLs) > 4 {
			return nil, ErrUnavailable
		}
		for _, url := range server.URLs {
			if !ValidRelayURL(url) {
				return nil, ErrUnavailable
			}
		}
	}
	settings := webrtc.SettingEngine{}
	settings.SetSCTPMaxMessageSize(8192)
	settings.SetICETimeouts(5*time.Second, 10*time.Second, 2*time.Second)
	logger := logging.NewDefaultLoggerFactory()
	logger.Writer = io.Discard
	settings.LoggerFactory = logger
	return newPeer(ctx, webrtc.NewAPI(webrtc.WithSettingEngine(settings)), config, true)
}
func newPeer(ctx context.Context, api *webrtc.API, config Config, relay bool) (*Peer, error) {
	if !validID(config.SessionUUID) || !validID(config.DesktopSession) || !validID(config.OfferUUID) || (config.AccessMode != "view" && config.AccessMode != "control") || config.Authorize == nil || !config.Authorize("negotiate") || (config.AccessMode == "control" && config.Input == nil) {
		return nil, ErrUnavailable
	}
	policy := webrtc.ICETransportPolicyAll
	if relay {
		policy = webrtc.ICETransportPolicyRelay
	}
	pc, err := api.NewPeerConnection(webrtc.Configuration{ICEServers: config.Servers, ICETransportPolicy: policy})
	if err != nil {
		return nil, ErrUnavailable
	}
	lifetime, cancel := context.WithCancel(ctx)
	peer := &Peer{pc: pc, config: config, relayOnly: relay, cancel: cancel, done: lifetime.Done(), ready: make(chan struct{})}
	protocol := ChannelLabel + "/" + config.SessionUUID + "/" + config.DesktopSession + "/" + config.OfferUUID
	ordered := true
	channel, err := pc.CreateDataChannel(ChannelLabel, &webrtc.DataChannelInit{Ordered: &ordered, Protocol: &protocol})
	if err != nil {
		peer.Close()
		return nil, ErrUnavailable
	}
	peer.channel = channel
	channel.OnOpen(func() {
		if peer.permitted("negotiate") {
			peer.readyOnce.Do(func() { close(peer.ready) })
		} else {
			go peer.Close()
		}
	})
	channel.OnMessage(peer.receive)
	channel.OnError(func(error) { go peer.Close() })
	channel.OnClose(func() { go peer.Close() })
	pc.OnDataChannel(func(*webrtc.DataChannel) { go peer.Close() })
	pc.OnConnectionStateChange(func(state webrtc.PeerConnectionState) {
		if state == webrtc.PeerConnectionStateFailed || state == webrtc.PeerConnectionStateDisconnected || state == webrtc.PeerConnectionStateClosed {
			go peer.Close()
		}
	})
	go func() {
		defer peer.Close()
		ticker := time.NewTicker(100 * time.Millisecond)
		defer ticker.Stop()
		for {
			select {
			case <-lifetime.Done():
				return
			case <-ticker.C:
				if !peer.permitted("negotiate") {
					return
				}
			}
		}
	}()
	return peer, nil
}
func (p *Peer) permitted(operation string) bool {
	return !p.closed.Load() && p.config.Authorize(operation)
}
func (p *Peer) Ready() <-chan struct{} { return p.ready }
func (p *Peer) Done() <-chan struct{}  { return p.done }
func (p *Peer) Closed() bool           { return p.closed.Load() }
func (p *Peer) Close()                 { p.closeOnce.Do(func() { p.closed.Store(true); p.cancel(); _ = p.pc.Close() }) }
func (p *Peer) Offer(ctx context.Context) (string, error) {
	if !p.permitted("negotiate") {
		return "", ErrUnavailable
	}
	offer, err := p.pc.CreateOffer(nil)
	if err != nil {
		return "", ErrUnavailable
	}
	complete := webrtc.GatheringCompletePromise(p.pc)
	if p.pc.SetLocalDescription(offer) != nil {
		return "", ErrUnavailable
	}
	timer := time.NewTimer(8 * time.Second)
	defer timer.Stop()
	select {
	case <-p.done:
		return "", ErrUnavailable
	case <-ctx.Done():
		p.Close()
		return "", ErrUnavailable
	case <-timer.C:
		p.Close()
		return "", ErrUnavailable
	case <-complete:
	}
	local := p.pc.LocalDescription()
	if local == nil || !p.permitted("negotiate") || validateDescription(local.SDP, p.relayOnly) != nil {
		p.Close()
		return "", ErrUnavailable
	}
	return local.SDP, nil
}
func (p *Peer) ApplyAnswer(sdp string) error {
	if !p.permitted("negotiate") || validateDescription(sdp, p.relayOnly) != nil {
		return ErrUnavailable
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.answer != "" {
		if p.answer == sdp {
			return nil
		}
		return ErrUnavailable
	}
	if p.pc.SetRemoteDescription(webrtc.SessionDescription{Type: webrtc.SDPTypeAnswer, SDP: sdp}) != nil {
		return ErrUnavailable
	}
	p.answer = sdp
	return nil
}
func (p *Peer) SendFrame(data []byte, width, height int) error {
	if len(data) == 0 || len(data) > MaxFrame || width < 1 || height < 1 || width > 4096 || height > 4096 || width*height > 8*1024*1024 {
		return ErrUnavailable
	}
	dimensions, err := jpeg.DecodeConfig(bytes.NewReader(data))
	if err != nil || dimensions.Width != width || dimensions.Height != height {
		return ErrUnavailable
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	if !p.permitted("view") || p.channel.ReadyState() != webrtc.DataChannelStateOpen {
		return ErrUnavailable
	}
	if p.channel.BufferedAmount() > MaxFrame {
		return ErrBackpressure
	}
	if p.frame == ^uint32(0) {
		return ErrUnavailable
	}
	p.frame++
	chunks := (len(data) + ChunkSize - 1) / ChunkSize
	for index := 0; index < chunks; index++ {
		if !p.permitted("view") {
			return ErrUnavailable
		}
		if p.channel.BufferedAmount() > 2*MaxFrame {
			return ErrBackpressure
		}
		start := index * ChunkSize
		end := start + ChunkSize
		if end > len(data) {
			end = len(data)
		}
		message := make([]byte, FrameHeader+end-start)
		copy(message, "OBL1")
		binary.BigEndian.PutUint32(message[4:], p.frame)
		binary.BigEndian.PutUint32(message[8:], uint32(len(data)))
		binary.BigEndian.PutUint16(message[12:], uint16(width))
		binary.BigEndian.PutUint16(message[14:], uint16(height))
		binary.BigEndian.PutUint16(message[16:], uint16(index))
		binary.BigEndian.PutUint16(message[18:], uint16(chunks))
		copy(message[FrameHeader:], data[start:end])
		if p.channel.Send(message) != nil {
			return ErrUnavailable
		}
	}
	return nil
}
func (p *Peer) receive(message webrtc.DataChannelMessage) {
	if !message.IsString || p.config.AccessMode != "control" || !p.permitted("control") {
		go p.Close()
		return
	}
	input, err := DecodeInput(message.Data)
	if err != nil {
		go p.Close()
		return
	}
	p.mu.Lock()
	now := time.Now()
	if now.Sub(p.inputWindow) >= time.Second {
		p.inputWindow = now
		p.inputCount = 0
	}
	p.inputCount++
	if input.Sequence <= p.inputSequence || p.inputCount > 240 || !p.permitted("control") {
		p.mu.Unlock()
		go p.Close()
		return
	}
	p.inputSequence = input.Sequence
	err = p.config.Input(input)
	p.mu.Unlock()
	if err != nil {
		go p.Close()
	}
}
