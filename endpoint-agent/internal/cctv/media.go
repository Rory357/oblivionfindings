package cctv

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"fmt"
	"github.com/bluenviron/gortsplib/v5"
	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/format"
	"github.com/bluenviron/gortsplib/v5/pkg/format/rtph264"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/pion/ice/v4"
	"github.com/pion/logging"
	"github.com/pion/rtp"
	"github.com/pion/sdp/v3"
	"github.com/pion/webrtc/v4"
	"github.com/pion/webrtc/v4/pkg/media"
	"io"
	"net"
	"net/url"
	"oblivion.local/endpoint-agent/internal/rtc"

	"strconv"
	"strings"
	"sync"
	"time"
)

type stream struct {
	mu     sync.Mutex
	closed bool
	cancel context.CancelFunc
	rtsp   *gortsplib.Client
	peer   *webrtc.PeerConnection
}

func (s *stream) Close() error {
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return nil
	}
	s.closed = true
	s.cancel()
	client, peer := s.rtsp, s.peer
	s.mu.Unlock()
	if client != nil {
		client.Close()
	}
	if peer != nil {
		_ = peer.Close()
	}
	return nil
}
func validateOffer(value string, turn Turn) error {
	if len(value) < 100 || len(value) > 32768 || !strings.HasPrefix(value, "v=0\r\n") || !strings.HasSuffix(value, "\r\n") {
		return ErrProtocol
	}
	var d sdp.SessionDescription
	if d.Unmarshal([]byte(value)) != nil || len(d.MediaDescriptions) != 1 {
		return ErrProtocol
	}
	m := d.MediaDescriptions[0]
	if m.MediaName.Media != "video" || strings.Join(m.MediaName.Protos, "/") != "UDP/TLS/RTP/SAVPF" {
		return ErrProtocol
	}
	recv := false
	maps := map[string]bool{}
	for _, a := range m.Attributes {
		if a.Key == "recvonly" {
			recv = true
		}
		if a.Key == "sendrecv" || a.Key == "sendonly" || a.Key == "inactive" {
			return ErrProtocol
		}
		if a.Key == "rtpmap" {
			p := strings.Fields(a.Value)
			if len(p) != 2 || p[1] != "H264/90000" {
				return ErrProtocol
			}
			maps[p[0]] = true
		}
	}
	if !recv || len(maps) != len(m.MediaName.Formats) || len(maps) == 0 {
		return ErrProtocol
	}
	for _, p := range m.MediaName.Formats {
		if !maps[p] {
			return ErrProtocol
		}
	}
	attrs := append(append([]sdp.Attribute{}, d.Attributes...), m.Attributes...)
	candidates, fingerprints := 0, 0
	for _, a := range attrs {
		if a.Key == "candidate" {
			v, e := ice.UnmarshalCandidate(a.Value)
			if e != nil || !allowedCandidate(v, turn) {
				return ErrProtocol
			}
			candidates++
		}
		if a.Key == "fingerprint" {
			if !strings.HasPrefix(a.Value, "sha-256 ") || len(a.Value) != 103 {
				return ErrProtocol
			}
			fingerprints++
		}
	}
	if candidates == 0 || fingerprints != 1 || validateFallbacks(d, turn) != nil {
		return ErrProtocol
	}
	return nil
}
func OpenMedia(parent context.Context, g Grant, source Source, turn Turn, offer Description) (Media, Description, string) {
	return openMedia(parent, g, source, turn, offer, nil, nil)
}
func openMedia(parent context.Context, g Grant, source Source, turn Turn, offer Description, roots *x509.CertPool, testSettings *webrtc.SettingEngine) (Media, Description, string) {
	fail := func(s string) (Media, Description, string) { return nil, Description{}, s }
	if validateOffer(offer.SDP, turn) != nil || len(turn.URLs) < 1 || len(turn.URLs) > 4 || len(turn.Username) < 1 || len(turn.Username) > 256 || len(turn.Credential) < 1 || len(turn.Credential) > 1024 {
		return fail("negotiation_failed")
	}
	for _, u := range turn.URLs {
		if !rtc.ValidRelayURL(u) {
			return fail("transport_unavailable")
		}
	}
	parsed, e := sourceURL(source, g)
	if e != nil {
		return fail("source_unavailable")
	}
	u, e := base.ParseURL(parsed.String())
	if e != nil {
		return fail("source_unavailable")
	}
	ctx, cancel := context.WithCancel(parent)
	s := &stream{cancel: cancel}
	cleanup := func(code string) (Media, Description, string) { _ = s.Close(); return fail(code) }
	transport := gortsplib.ProtocolTCP
	client := &gortsplib.Client{Scheme: u.Scheme, Host: u.Host, Protocol: &transport, ReadTimeout: 3 * time.Second, WriteTimeout: 3 * time.Second, TLSConfig: &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots, ServerName: g.ServerName, VerifyConnection: func(state tls.ConnectionState) error {
		if len(state.PeerCertificates) == 0 {
			return ErrProtocol
		}
		h := sha256.Sum256(state.PeerCertificates[0].Raw)
		if hex.EncodeToString(h[:]) != g.Pin {
			return ErrProtocol
		}
		return nil
	}}, DialContext: func(c context.Context, network, address string) (net.Conn, error) {
		if network != "tcp" {
			return nil, ErrProtocol
		}
		host, port, e := net.SplitHostPort(address)
		if e != nil || port != strconv.Itoa(g.Port) || (host != g.ServerName && host != g.IP) {
			return nil, ErrProtocol
		}
		return (&net.Dialer{Timeout: 3 * time.Second}).DialContext(c, "tcp", net.JoinHostPort(g.IP, port))
	}}
	if g.Type == hikvisionLiveType {
		private := source.Hikvision.endpoint()
		private.Path = u.Path
		inventory, probeErr := discoverHikvision(ctx, private, roots)
		if probeErr != nil || !hikvisionInventoryMatches(inventory, g.Hikvision) {
			return cleanup("source_unavailable")
		}
		u.User = url.UserPassword(private.Username, private.Password)
		client = &gortsplib.Client{Scheme: u.Scheme, Host: u.Host, Protocol: &transport, ReadTimeout: 3 * time.Second, WriteTimeout: 3 * time.Second, DialContext: func(dial context.Context, network, target string) (net.Conn, error) {
			if network != "tcp" || target != u.Host {
				return nil, ErrProtocol
			}
			return openAuthenticatedHTTPSRTSPTunnel(dial, ctx, private, roots)
		}}
	}
	// gortsplib follows DESCRIBE redirects by default; remove Location before its
	// redirect branch. No downgrade or same-origin redirect is authorized.
	client.OnTransportSwitch = func(error) {}
	client.OnPacketsLost = func(uint64) {}
	client.OnDecodeError = func(error) {}
	rtspChallenged := false
	client.OnResponse = func(r *base.Response) {
		if g.Type == hikvisionLiveType && r.StatusCode == base.StatusUnauthorized {
			challenge, err := selectHikvisionRTSPDigest(r.Header["WWW-Authenticate"])
			if err != nil {
				r.StatusCode = base.StatusForbidden
				delete(r.Header, "WWW-Authenticate")
			} else {
				r.Header["WWW-Authenticate"] = challenge
				rtspChallenged = true
			}
		}

		if r.StatusCode >= 300 && r.StatusCode < 400 {
			delete(r.Header, "Location")
			r.StatusCode = base.StatusForbidden
		}
	}
	s.rtsp = client
	if client.Start() != nil {
		return cleanup("source_unavailable")
	}
	go func() { <-ctx.Done(); _ = s.Close() }()
	desc, _, e := client.Describe(u)
	if e != nil || (g.Type == hikvisionLiveType && !rtspChallenged) {
		return cleanup("source_unavailable")
	}
	if desc.BaseURL == nil || (g.Type == hikvisionLiveType && !hikvisionControlTarget(desc.BaseURL, u, false)) || (g.Type != hikvisionLiveType && !sameAuthority(desc.BaseURL, u)) {
		return cleanup("source_unavailable")
	}
	for _, m := range desc.Medias {
		if g.Type == hikvisionLiveType && !hikvisionRawControl(m.Control, u) {
			return cleanup("source_unavailable")
		}
		target, e := m.URL(desc.BaseURL)
		if e != nil || (g.Type == hikvisionLiveType && !hikvisionControlTarget(target, u, true)) || (g.Type != hikvisionLiveType && !sameAuthority(target, u)) {
			return cleanup("source_unavailable")
		}
	}
	var h *format.H264
	video := desc.FindFormat(&h)
	if video == nil || video.IsBackChannel || h.PacketizationMode != 1 {
		return cleanup("unsupported_codec")
	}
	sourceSPS, sourcePPS := h.SafeParams()
	sourceSPS, sourcePPS = bytes.Clone(sourceSPS), bytes.Clone(sourcePPS)
	if len(sourceSPS) < 4 || len(sourcePPS) == 0 || len(sourceSPS) > 4096 || len(sourcePPS) > 4096 {
		return cleanup("unsupported_codec")
	}
	profile := fmt.Sprintf("%02x%02x%02x", sourceSPS[1], sourceSPS[2], sourceSPS[3])
	profile, profileOK := offerProfile(offer.SDP, profile)
	if !profileOK {
		return cleanup("unsupported_codec")
	}
	engine := &webrtc.MediaEngine{}
	capability := webrtc.RTPCodecCapability{MimeType: webrtc.MimeTypeH264, ClockRate: 90000, SDPFmtpLine: "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=" + profile, RTCPFeedback: []webrtc.RTCPFeedback{{Type: "nack"}, {Type: "nack", Parameter: "pli"}}}
	if engine.RegisterCodec(webrtc.RTPCodecParameters{RTPCodecCapability: capability, PayloadType: 102}, webrtc.RTPCodecTypeVideo) != nil {
		return cleanup("negotiation_failed")
	}
	settings := webrtc.SettingEngine{}
	if testSettings != nil {
		settings = *testSettings
	}
	logger := logging.NewDefaultLoggerFactory()
	logger.Writer = io.Discard
	settings.LoggerFactory = logger
	relayNetwork, relayErr := newRelayNet(ctx, turn)
	if relayErr != nil {
		return cleanup("transport_unavailable")
	}
	settings.SetNet(relayNetwork)
	settings.SetICEProxyDialer(relayNetwork)
	settings.SetRemoteIPFilter(func(ip net.IP) bool { return allowedRelayIP(ip, turn) })
	settings.SetNetworkTypes([]webrtc.NetworkType{webrtc.NetworkTypeUDP4, webrtc.NetworkTypeTCP4})
	api := webrtc.NewAPI(webrtc.WithMediaEngine(engine), webrtc.WithSettingEngine(settings))
	pc, e := api.NewPeerConnection(webrtc.Configuration{ICEServers: []webrtc.ICEServer{{URLs: turn.URLs, Username: turn.Username, Credential: turn.Credential}}, ICETransportPolicy: webrtc.ICETransportPolicyRelay})
	if e != nil {
		return cleanup("transport_unavailable")
	}
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		_ = pc.Close()
		return cleanup("lease_expired")
	}
	s.peer = pc
	s.mu.Unlock()
	track, e := webrtc.NewTrackLocalStaticSample(capability, "camera", "cctv")
	if e != nil {
		return cleanup("negotiation_failed")
	}
	sender, e := pc.AddTrack(track)
	if e != nil {
		return cleanup("negotiation_failed")
	}
	go func() {
		b := make([]byte, 1500)
		for {
			if _, _, e := sender.Read(b); e != nil {
				return
			}
		}
	}()
	pc.OnDataChannel(func(*webrtc.DataChannel) { go s.Close() })
	pc.OnConnectionStateChange(func(v webrtc.PeerConnectionState) {
		if v == webrtc.PeerConnectionStateFailed || v == webrtc.PeerConnectionStateDisconnected || v == webrtc.PeerConnectionStateClosed {
			go s.Close()
		}
	})
	if pc.SetRemoteDescription(webrtc.SessionDescription{Type: webrtc.SDPTypeOffer, SDP: offer.SDP}) != nil {
		return cleanup("negotiation_failed")
	}
	answer, e := pc.CreateAnswer(nil)
	if e != nil {
		return cleanup("negotiation_failed")
	}
	gather := webrtc.GatheringCompletePromise(pc)
	if pc.SetLocalDescription(answer) != nil {
		return cleanup("negotiation_failed")
	}
	select {
	case <-ctx.Done():
		return cleanup("lease_expired")
	case <-time.After(5 * time.Second):
		return cleanup("transport_unavailable")
	case <-gather:
	}
	if _, e = client.Setup(desc.BaseURL, video, 0, 0); e != nil {
		return cleanup("source_unavailable")
	}
	decoder, e := h.CreateDecoder()
	if e != nil {
		return cleanup("unsupported_codec")
	}
	type packet struct {
		p  *rtp.Packet
		at time.Time
	}
	queue := make(chan packet, 64)
	client.OnPacketRTP(video, h, func(p *rtp.Packet) {
		if len(p.Payload) > 65536 {
			go s.Close()
			return
		}
		v := packet{p.Clone(), time.Now()}
		select {
		case queue <- v:
		default:
			select {
			case <-queue:
			default:
			}
			select {
			case queue <- v:
			default:
			}
		}
	})
	go func() {
		var previous uint32
		first := true
		last := time.Now()
		timer := time.NewTicker(time.Second)
		defer timer.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-timer.C:
				if time.Since(last) > 5*time.Second {
					_ = s.Close()
					return
				}
			case v := <-queue:
				if time.Since(v.at) > 500*time.Millisecond {
					continue
				}
				au, e := decoder.Decode(v.p)
				if e == rtph264.ErrMorePacketsNeeded || e == rtph264.ErrNonStartingPacketAndNoPrevious {
					continue
				}
				if e != nil {
					continue
				}
				last = time.Now()
				key := h264.IsRandomAccess(au)
				if first && !key {
					continue
				}
				duration := time.Second / 30
				if !first {
					delta := v.p.Timestamp - previous
					if delta > 0 && delta < 90000 {
						duration = time.Duration(delta) * time.Second / 90000
					}
				}
				previous = v.p.Timestamp
				first = false
				if !s.forwardSample(track, au, sourceSPS, sourcePPS, key, duration) {
					return
				}
			}
		}
	}()
	if _, e = client.Play(nil); e != nil {
		return cleanup("source_unavailable")
	}
	local := pc.LocalDescription()
	if local != nil {
		local.SDP = normalizeOwnAnswer(local.SDP)
	}
	if local == nil || len(local.SDP) > 32768 || validateCandidates(local.SDP, turn) != nil {
		return cleanup("negotiation_failed")
	}
	return s, Description{"answer", local.SDP}, ""
}
func sameAuthority(a, b *base.URL) bool {
	return a != nil && a.Scheme == b.Scheme && a.Host == b.Host && a.User == nil && a.Fragment == ""
}
func offerProfile(value, profile string) (string, bool) {
	source, err := hex.DecodeString(profile)
	if err != nil || len(source) != 3 {
		return "", false
	}
	var d sdp.SessionDescription
	if d.Unmarshal([]byte(value)) != nil {
		return "", false
	}
	for _, m := range d.MediaDescriptions {
		for _, a := range m.Attributes {
			if a.Key != "fmtp" {
				continue
			}
			parts := strings.Split(a.Value, " ")
			if len(parts) < 2 {
				continue
			}
			values := map[string]string{}
			for _, x := range strings.Split(strings.Join(parts[1:], " "), ";") {
				k, v, ok := strings.Cut(strings.TrimSpace(x), "=")
				if ok {
					values[k] = v
				}
			}
			p := strings.ToLower(values["profile-level-id"])
			receiver, parseErr := hex.DecodeString(p)
			if parseErr == nil && len(receiver) == 3 && equivalentH264Profile(source, receiver) && values["packetization-mode"] == "1" && receiver[2] >= source[2] {
				// RFC6184 Table5 equivalent constraint patterns do not change coded media.
				// Advertise an offered representation so Pion's literal fmtp matcher agrees.
				// Level1b additionally depends on constraint_set3: preserve it exactly.
				if source[2] == 11 && source[1]&0x10 != receiver[1]&0x10 {
					continue
				}
				return p[:4] + profile[4:], true
			}
		}
	}
	return "", false
}

// RFC6184 section8.1 Table5: compare coding-tool subprofiles, not ignored bits.
func equivalentH264Profile(a, b []byte) bool {
	if len(a) != 3 || len(b) != 3 {
		return false
	}
	category := func(v []byte) string {
		switch v[0] {
		case 0x42:
			if v[1]&0x4f == 0x40 {
				return "CB"
			}
			if v[1]&0x4f == 0 {
				return "B"
			}
		case 0x4d:
			if v[1]&0x8f == 0x80 {
				return "CB"
			}
			if v[1]&0xaf == 0 {
				return "M"
			}
		case 0x58:
			if v[1]&0xcf == 0xc0 {
				return "CB"
			}
			if v[1]&0xcf == 0x80 {
				return "B"
			}
			if v[1]&0xcf == 0 {
				return "E"
			}
		}
		return ""
	}
	left, right := category(a), category(b)
	if left != "" || right != "" {
		return left != "" && left == right
	}
	return a[0] == b[0] && a[1] == b[1]
}
func allowedRelayIP(ip net.IP, t Turn) bool {
	if ip == nil || len(t.RelayCIDRs) < 1 || len(t.RelayCIDRs) > 8 {
		return false
	}
	matched := false
	for _, s := range t.RelayCIDRs {
		_, cidr, e := net.ParseCIDR(s)
		if e != nil {
			return false
		}
		ones, bits := cidr.Mask.Size()
		if bits == 32 && ones < 24 || bits == 128 && ones < 120 {
			return false
		}
		if cidr.Contains(ip) {
			matched = true
		}
	}
	return matched
}
func allowedCandidate(c ice.Candidate, t Turn) bool {
	if c == nil || c.Type() != ice.CandidateTypeRelay || c.Component() != 1 || c.NetworkType() != ice.NetworkTypeUDP4 && c.NetworkType() != ice.NetworkTypeUDP6 || t.RelayPortMin < 1024 || t.RelayPortMax > 65535 || t.RelayPortMax < t.RelayPortMin || t.RelayPortMax-t.RelayPortMin > 32768 || c.Port() < t.RelayPortMin || c.Port() > t.RelayPortMax || !allowedRelayIP(net.ParseIP(c.Address()), t) {
		return false
	}
	if r := c.RelatedAddress(); r != nil && (r.Port != 0 || net.ParseIP(r.Address) == nil || !net.ParseIP(r.Address).IsUnspecified()) {
		return false
	}
	return true
}
func validateCandidates(value string, t Turn) error {
	var d sdp.SessionDescription
	if d.Unmarshal([]byte(value)) != nil {
		return ErrProtocol
	}
	count := 0
	attrs := append([]sdp.Attribute{}, d.Attributes...)
	for _, m := range d.MediaDescriptions {
		attrs = append(attrs, m.Attributes...)
	}
	for _, a := range attrs {
		if a.Key == "remote-candidates" {
			return ErrProtocol
		}
		if a.Key == "candidate" {
			c, e := ice.UnmarshalCandidate(a.Value)
			if e != nil || !allowedCandidate(c, t) {
				return ErrProtocol
			}
			count++
		}
	}
	if count == 0 {
		return ErrProtocol
	}
	return nil
}

// This changes only our own generated SDP. It does not modify signed offers:
// RTCP mux uses component1; related-address metadata grants no dial authority.
func normalizeOwnAnswer(value string) string {
	lines := strings.Split(value, "\r\n")
	out := make([]string, 0, len(lines))
	for _, line := range lines {
		if strings.HasPrefix(line, "a=candidate:") {
			parts := strings.Fields(line)
			if len(parts) > 2 && parts[1] == "2" {
				continue
			}
			for i := 0; i+1 < len(parts); i++ {
				if parts[i] == "raddr" {
					parts[i+1] = "0.0.0.0"
				}
				if parts[i] == "rport" {
					parts[i+1] = "0"
				}
			}
			line = strings.Join(parts, " ")
		}
		out = append(out, line)
	}
	return strings.Join(out, "\r\n")
}
func validateFallbacks(d sdp.SessionDescription, policy Turn) error {
	tuple := func(address string, port int) bool {
		ip := net.ParseIP(address)
		if ip == nil {
			return false
		}
		if ip.IsUnspecified() {
			return port == 9
		}
		return allowedRelayIP(ip, policy) && port >= policy.RelayPortMin && port <= policy.RelayPortMax
	}
	valid := func(c *sdp.ConnectionInformation) bool {
		return c == nil || c.NetworkType == "IN" && c.Address != nil && net.ParseIP(c.Address.Address) != nil && (net.ParseIP(c.Address.Address).IsUnspecified() || allowedRelayIP(net.ParseIP(c.Address.Address), policy))
	}
	if !valid(d.ConnectionInformation) {
		return ErrProtocol
	}
	for _, m := range d.MediaDescriptions {
		connection := m.ConnectionInformation
		if connection == nil {
			connection = d.ConnectionInformation
		}
		if !valid(connection) {
			return ErrProtocol
		}
		address := "0.0.0.0"
		if connection != nil {
			address = connection.Address.Address
		}
		if !tuple(address, m.MediaName.Port.Value) {
			return ErrProtocol
		}
		mux := false
		for _, a := range m.Attributes {
			if a.Key == "rtcp-mux" {
				mux = true
			}
			if a.Key == "rtcp" {
				p := strings.Fields(a.Value)
				if len(p) != 4 || p[1] != "IN" || p[2] != "IP4" && p[2] != "IP6" {
					return ErrProtocol
				}
				port, e := strconv.Atoi(p[0])
				if e != nil || !tuple(p[3], port) {
					return ErrProtocol
				}
			}
			if a.Key == "remote-candidates" {
				return ErrProtocol
			}
		}
		if !mux {
			return ErrProtocol
		}
	}
	return nil
}

type sampleWriter interface{ WriteSample(media.Sample) error }

func (s *stream) forwardSample(writer sampleWriter, au [][]byte, sps, pps []byte, key bool, duration time.Duration) bool {
	size := 0
	for _, n := range au {
		if len(n) == 0 || n[0]&31 == 7 && !bytes.Equal(n, sps) || n[0]&31 == 8 && !bytes.Equal(n, pps) {
			_ = s.Close()
			return false
		}
		size += len(n) + 4
	}
	if key {
		size += len(sps) + len(pps) + 8
		au = append([][]byte{sps, pps}, au...)
	}
	if size > 4194304 {
		_ = s.Close()
		return false
	}
	data := make([]byte, 0, size)
	for _, n := range au {
		data = append(data, 0, 0, 0, 1)
		data = append(data, n...)
	}
	s.mu.Lock()
	closed := s.closed
	s.mu.Unlock()
	if closed {
		return false
	}
	if writer.WriteSample(media.Sample{Data: data, Duration: duration}) != nil {
		_ = s.Close()
		return false
	}
	return true
}
