package localui

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"net"
	"oblivion.local/endpoint-agent/internal/agent"
	"strings"
	"testing"
	"time"
)

func TestTicketComputerProtocolAndPeer(t *testing.T) {
	challenge := strings.Repeat("a", 64)
	hash := strings.Repeat("b", 64)
	request := `{"version":1,"operation":"confirm_ticket_computer","challenge":"` + challenge + `","expected_origin_hash":"` + hash + `"}`
	if _, e := readRequest(bytes.NewReader(frame(request))); e != nil {
		t.Fatal(e)
	}
	for _, bad := range []string{strings.Replace(request, challenge, strings.ToUpper(challenge), 1), strings.Replace(request, `"expected_origin_hash"`, `"url"`, 1), strings.Replace(request, `"version":1`, `"version":1,"staff_token":"secret"`, 1), strings.Replace(request, `"version":1`, `"version":1,"version":1`, 1), `{"version":1,"operation":"status","challenge":"` + challenge + `"}`} {
		if _, e := readRequest(bytes.NewReader(frame(bad))); e == nil {
			t.Fatal("unsafe proof request accepted")
		}
	}
	for _, allowed := range []bool{true, false} {
		server, client := net.Pipe()
		calls := 0
		done := make(chan struct{})
		go func() {
			defer close(done)
			serveHelperConnectionWithProof(server, func(net.Conn) (identity, error) {
				if !allowed {
					return identity{}, errors.New("denied")
				}
				return identity{SID: "S-user", LogonSID: "S-logon", SessionID: 1}, nil
			}, func() agent.UIStatus { return agent.UIStatus{Protocol: 1} }, nil, func(c, h string) agent.TicketComputerView {
				calls++
				if c != challenge || h != hash {
					t.Error("binding changed")
				}
				return agent.TicketComputerView{Protocol: 1, Status: "confirmed"}
			})
		}()
		_ = client.SetDeadline(time.Now().Add(time.Second))
		_, _ = client.Write(frame(request))
		var n uint32
		e := binary.Read(client, binary.LittleEndian, &n)
		if allowed {
			if e != nil || n > 4096 {
				t.Fatal("missing proof result")
			}
			data := make([]byte, n)
			_, _ = client.Read(data)
			var reply agent.TicketComputerView
			if json.Unmarshal(data, &reply) != nil || reply.Status != "confirmed" {
				t.Fatal("invalid proof result")
			}
		}
		client.Close()
		<-done
		if allowed && calls != 1 || !allowed && calls != 0 {
			t.Fatal("unverified peer reached endpoint proof")
		}
	}
}
