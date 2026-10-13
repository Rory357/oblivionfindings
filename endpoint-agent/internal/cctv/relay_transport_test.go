package cctv

import (
	"encoding/binary"
	"net"
	"testing"
	"time"

	"github.com/pion/sdp/v3"
	"github.com/pion/stun/v4"
)

type fixturePeer struct {
	ip   net.IP
	port int
}

func (p fixturePeer) AddTo(m *stun.Message) error {
	return (&stun.XORMappedAddress{IP: p.ip, Port: p.port}).AddToAs(m, stun.AttrXORPeerAddress)
}
func turnPacket(t *testing.T, method stun.Method, ip string, port int, channel bool) []byte {
	t.Helper()
	m := stun.MustBuild(stun.TransactionID, stun.NewType(method, stun.ClassRequest), fixturePeer{net.ParseIP(ip), port})
	if channel {
		m.Add(stun.AttrChannelNumber, []byte{0x40, 0x01, 0, 0})
	}
	return m.Raw
}
func relayPolicy() Turn {
	return Turn{RelayCIDRs: []string{"192.0.2.0/24"}, RelayPortMin: 50000, RelayPortMax: 60000}
}
func TestTURNPeerTupleGuard(t *testing.T) {
	for _, method := range []stun.Method{stun.MethodSend, stun.MethodChannelBind} {
		for _, test := range []struct {
			ip   string
			port int
		}{{"192.0.2.10", 49999}, {"192.0.2.10", 60001}, {"198.51.100.10", 55000}} {
			guard := newTurnGuard(relayPolicy())
			if guard.allow(turnPacket(t, method, test.ip, test.port, method == stun.MethodChannelBind)) {
				t.Fatalf("allowed learned peer target %s:%d", test.ip, test.port)
			}
		}
	}
	g := newTurnGuard(relayPolicy())
	if !g.allow(turnPacket(t, stun.MethodCreatePermission, "192.0.2.10", 0, false)) {
		t.Fatal("TURN IP-only permission denied")
	}
	if g.allow(turnPacket(t, stun.MethodCreatePermission, "198.51.100.10", 0, false)) {
		t.Fatal("outside pool permission allowed")
	}
	data := []byte{0x40, 1, 0, 3, 1, 2, 3}
	if g.allow(data) {
		t.Fatal("unbound channel allowed")
	}
	if !g.allow(turnPacket(t, stun.MethodChannelBind, "192.0.2.10", 55000, true)) || !g.allow(data) {
		t.Fatal("approved channel denied")
	}
	binary.BigEndian.PutUint16(data[2:4], 10)
	if g.allow(data) {
		t.Fatal("truncated channel accepted")
	}
}

type countedPacket struct {
	writes int
	closed bool
}

func (c *countedPacket) WriteTo(b []byte, _ net.Addr) (int, error) { c.writes++; return len(b), nil }
func (c *countedPacket) ReadFrom([]byte) (int, net.Addr, error)    { return 0, nil, ErrProtocol }
func (c *countedPacket) Close() error                              { c.closed = true; return nil }
func (*countedPacket) LocalAddr() net.Addr                         { return &net.UDPAddr{IP: net.IPv4zero, Port: 55000} }
func (*countedPacket) SetDeadline(time.Time) error                 { return nil }
func (*countedPacket) SetReadDeadline(time.Time) error             { return nil }
func (*countedPacket) SetWriteDeadline(time.Time) error            { return nil }
func TestLearnedPeerOutsideRangeSendsNoPacket(t *testing.T) {
	underlying := &countedPacket{}
	network := &relayNet{policy: relayPolicy(), endpoints: map[string]relayEndpoint{"fixture": {ip: net.ParseIP("127.0.0.1"), port: 3478}}}
	c := &relayPacket{PacketConn: underlying, net: network, guard: newTurnGuard(relayPolicy())}
	// This is the TURN Send request Pion emits to a newly learned peer-reflexive
	// address. The trusted server address itself does not authorize its peer port.
	_, err := c.WriteTo(turnPacket(t, stun.MethodSend, "192.0.2.10", 22, false), &net.UDPAddr{IP: net.ParseIP("127.0.0.1"), Port: 3478})
	if err == nil || underlying.writes != 0 || !underlying.closed {
		t.Fatal("learned peer bypassed transport gate")
	}
}
func TestRelayPoolValidatesAllEntries(t *testing.T) {
	p := relayPolicy()
	p.RelayCIDRs = append(p.RelayCIDRs, "0.0.0.0/0")
	if allowedRelayIP(net.ParseIP("192.0.2.10"), p) {
		t.Fatal("later invalid pool ignored")
	}
}

func TestFallbackTargetsStayInsideSignedRelay(t *testing.T) {
	for _, caseValue := range []struct {
		address string
		port    int
		rtcp    string
		allowed bool
	}{
		{"0.0.0.0", 9, "9 IN IP4 0.0.0.0", true},
		{"192.0.2.10", 55000, "55000 IN IP4 192.0.2.10", true},
		{"192.0.2.10", 22, "55000 IN IP4 192.0.2.10", false},
		{"198.51.100.10", 55000, "55000 IN IP4 192.0.2.10", false},
		{"192.0.2.10", 55000, "22 IN IP4 192.0.2.10", false},
		{"192.0.2.10", 55000, "55000 IN IP4 198.51.100.10", false},
	} {
		media := &sdp.MediaDescription{MediaName: sdp.MediaName{Port: sdp.RangedPort{Value: caseValue.port}}, ConnectionInformation: &sdp.ConnectionInformation{NetworkType: "IN", AddressType: "IP4", Address: &sdp.Address{Address: caseValue.address}}, Attributes: []sdp.Attribute{{Key: "rtcp-mux"}, {Key: "rtcp", Value: caseValue.rtcp}}}
		if (validateFallbacks(sdp.SessionDescription{MediaDescriptions: []*sdp.MediaDescription{media}}, relayPolicy()) == nil) != caseValue.allowed {
			t.Fatal("fallback endpoint policy mismatch")
		}
	}
}
