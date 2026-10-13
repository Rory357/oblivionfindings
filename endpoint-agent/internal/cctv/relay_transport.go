package cctv

import (
	"context"
	"crypto/tls"
	"encoding/binary"
	"net"
	"strconv"
	"sync"
	"time"

	"github.com/pion/stun/v4"
	"github.com/pion/transport/v5"
	"github.com/pion/transport/v5/stdnet"
)

// relayNet pins TURN server resolution for this peer and enforces peer tuples
// inside TURN messages, including ICE peer-reflexive candidates learned later.
type relayNet struct {
	transport.Net
	ctx       context.Context
	endpoints map[string]relayEndpoint
	policy    Turn
}
type relayEndpoint struct {
	ip     net.IP
	host   string
	port   int
	secure bool
	tcp    bool
}

func newRelayNet(ctx context.Context, policy Turn) (*relayNet, error) {
	base, err := stdnet.NewNet()
	if err != nil {
		return nil, ErrProtocol
	}
	n := &relayNet{Net: base, ctx: ctx, endpoints: map[string]relayEndpoint{}, policy: policy}
	for _, raw := range policy.URLs {
		uri, err := stun.ParseURI(raw)
		if err != nil || uri.Scheme != stun.SchemeTypeTURN && uri.Scheme != stun.SchemeTypeTURNS {
			return nil, ErrProtocol
		}
		// TURNS/UDP uses DTLS below the inspectable TURN frame boundary. It is not
		// enabled until an equivalent verified plaintext transport is supplied.
		if uri.Scheme == stun.SchemeTypeTURNS && uri.Proto != stun.ProtoTypeTCP {
			return nil, ErrProtocol
		}
		var ip net.IP
		if parsed := net.ParseIP(uri.Host); parsed != nil {
			ip = parsed
		} else {
			lookup, cancel := context.WithTimeout(ctx, 2*time.Second)
			addresses, e := net.DefaultResolver.LookupIPAddr(lookup, uri.Host)
			cancel()
			if e != nil || len(addresses) == 0 {
				return nil, ErrProtocol
			}
			ip = addresses[0].IP
		}
		key := net.JoinHostPort(uri.Host, strconv.Itoa(uri.Port))
		endpoint := relayEndpoint{ip: ip, host: uri.Host, port: uri.Port, secure: uri.Scheme == stun.SchemeTypeTURNS, tcp: uri.Proto == stun.ProtoTypeTCP}
		if prior, ok := n.endpoints[key]; ok && (prior.secure != endpoint.secure || prior.tcp != endpoint.tcp) {
			return nil, ErrProtocol
		}
		n.endpoints[key] = endpoint
	}
	return n, nil
}
func (n *relayNet) ResolveUDPAddr(network, address string) (*net.UDPAddr, error) {
	e, ok := n.endpoints[address]
	if !ok || e.tcp || e.secure {
		return nil, ErrProtocol
	}
	return &net.UDPAddr{IP: e.ip, Port: e.port}, nil
}
func (n *relayNet) ListenPacket(network, address string) (net.PacketConn, error) {
	if network != "udp4" && network != "udp6" && network != "udp" {
		return nil, ErrProtocol
	}
	c, e := n.Net.ListenPacket(network, address)
	if e != nil {
		return nil, e
	}
	return &relayPacket{PacketConn: c, net: n, guard: newTurnGuard(n.policy)}, nil
}
func (n *relayNet) ListenUDP(string, *net.UDPAddr) (transport.UDPConn, error) {
	return nil, ErrProtocol
}
func (n *relayNet) DialUDP(string, *net.UDPAddr, *net.UDPAddr) (transport.UDPConn, error) {
	return nil, ErrProtocol
}
func (n *relayNet) DialTCP(string, *net.TCPAddr, *net.TCPAddr) (transport.TCPConn, error) {
	return nil, ErrProtocol
}

// Pion's TURN proxy hook supplies already-protected plaintext framing even for
// TURNS; normal certificate verification retains the original server name.
func (n *relayNet) Dial(network, address string) (net.Conn, error) {
	e, ok := n.endpoints[address]
	if !ok || !e.tcp || network != "tcp" && network != "tcp4" && network != "tcp6" {
		return nil, ErrProtocol
	}
	d := net.Dialer{Timeout: 3 * time.Second}
	c, err := d.DialContext(n.ctx, "tcp", net.JoinHostPort(e.ip.String(), strconv.Itoa(e.port)))
	if err != nil {
		return nil, ErrProtocol
	}
	if e.secure {
		secured := tls.Client(c, &tls.Config{MinVersion: tls.VersionTLS12, ServerName: e.host})
		deadline, cancel := context.WithTimeout(n.ctx, 3*time.Second)
		err = secured.HandshakeContext(deadline)
		cancel()
		if err != nil {
			_ = c.Close()
			return nil, ErrProtocol
		}
		c = secured
	}
	return &relayStream{Conn: c, guard: newTurnGuard(n.policy)}, nil
}
func (n *relayNet) server(addr net.Addr) bool {
	a, ok := addr.(*net.UDPAddr)
	if !ok {
		return false
	}
	for _, e := range n.endpoints {
		if !e.tcp && !e.secure && e.port == a.Port && e.ip.Equal(a.IP) {
			return true
		}
	}
	return false
}

type relayPacket struct {
	net.PacketConn
	net   *relayNet
	guard *turnGuard
}

func (c *relayPacket) WriteTo(p []byte, a net.Addr) (int, error) {
	if !c.net.server(a) || !c.guard.allow(p) {
		_ = c.Close()
		return 0, ErrProtocol
	}
	return c.PacketConn.WriteTo(p, a)
}

type relayStream struct {
	net.Conn
	guard *turnGuard
}

func (c *relayStream) Write(p []byte) (int, error) {
	if !c.guard.allow(p) {
		_ = c.Close()
		return 0, ErrProtocol
	}
	return c.Conn.Write(p)
}

type turnGuard struct {
	mu       sync.Mutex
	policy   Turn
	channels map[uint16]bool
}

func newTurnGuard(t Turn) *turnGuard { return &turnGuard{policy: t, channels: map[uint16]bool{}} }
func (g *turnGuard) allow(packet []byte) bool {
	g.mu.Lock()
	defer g.mu.Unlock()
	if len(packet) < 4 || len(packet) > 65536 {
		return false
	}
	kind := binary.BigEndian.Uint16(packet[:2])
	if kind >= 0x4000 && kind <= 0x7fff {
		size := int(binary.BigEndian.Uint16(packet[2:4]))
		return size <= len(packet)-4 && len(packet)-4-size <= 3 && g.channels[kind]
	}
	if len(packet) < 20 || !stun.IsMessage(packet) {
		return false
	}
	m := &stun.Message{Raw: packet}
	if m.Decode() != nil || int(binary.BigEndian.Uint16(packet[2:4]))+20 != len(packet) {
		return false
	}
	peers := 0
	for _, attr := range m.Attributes {
		if attr.Type != stun.AttrXORPeerAddress {
			continue
		}
		peers++
		one := &stun.Message{TransactionID: m.TransactionID, Attributes: []stun.RawAttribute{attr}}
		var peer stun.XORMappedAddress
		if peer.GetFromAs(one, stun.AttrXORPeerAddress) != nil || !allowedRelayIP(peer.IP, g.policy) {
			return false
		}
		// CREATE-PERMISSION authorizes an IP, not a port, and Pion emits port zero.
		if m.Type.Method != stun.MethodCreatePermission && (peer.Port < g.policy.RelayPortMin || peer.Port > g.policy.RelayPortMax) {
			return false
		}
	}
	if m.Type.Method == stun.MethodSend || m.Type.Method == stun.MethodChannelBind || m.Type.Method == stun.MethodCreatePermission {
		if peers < 1 || peers > 8 {
			return false
		}
	}
	if m.Type.Method == stun.MethodChannelBind {
		raw, e := m.Get(stun.AttrChannelNumber)
		if e != nil || len(raw) != 4 || peers != 1 {
			return false
		}
		channel := binary.BigEndian.Uint16(raw[:2])
		if channel < 0x4000 || channel > 0x7fff || len(g.channels) >= 64 && !g.channels[channel] {
			return false
		}
		g.channels[channel] = true
	}
	return true
}
