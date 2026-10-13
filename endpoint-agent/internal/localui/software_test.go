package localui

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"io"
	"net"
	"oblivion.local/endpoint-agent/internal/agent"
	"strings"
	"testing"
)

func TestSoftwarePipeContractAndSingleDispatch(t *testing.T) {
	hash := strings.Repeat("a", 64)
	poll := `{"version":1,"operation":"software_poll","expected_origin_hash":"` + hash + `"}`
	if _, e := readRequest(bytes.NewReader(frame(poll))); e != nil {
		t.Fatal(e)
	}
	for _, decision := range []string{"allow", "decline"} {
		raw := `{"version":1,"operation":"software_decide","expected_origin_hash":"` + hash + `","attempt_uuid":"cccccccc-cccc-4ccc-8ccc-cccccccccccc","payload_hash":"` + hash + `","consent_token":"` + hash + `","decision":"` + decision + `"}`
		if _, e := readRequest(bytes.NewReader(frame(raw))); e != nil {
			t.Fatal(e)
		}
		for _, bad := range []string{strings.Replace(raw, `"decision":"`+decision+`"`, `"decision":"hidden"`, 1), strings.Replace(raw, `"version":1`, `"version":null`, 1), strings.Replace(raw, `"version":1`, `"version":1,"version":1`, 1), strings.Replace(raw, `"version":1`, `"version":1,"path":"C:/unsafe.msi"`, 1)} {
			if _, e := readRequest(bytes.NewReader(frame(bad))); e == nil {
				t.Fatal("unsafe install IPC")
			}
		}
	}
	client, server := net.Pipe()
	defer client.Close()
	done := make(chan struct{})
	calls := 0
	go func() {
		defer close(done)
		serveHelperConnectionWithSoftware(server, func(net.Conn) (identity, error) { return identity{"S-user", "S-logon", 1}, nil }, func() agent.UIStatus { return agent.UIStatus{} }, nil, nil, nil, nil, func(peer agent.RemotePeer, d agent.SoftwareDecision) (agent.SoftwareView, error) {
			calls++
			if peer.SessionID != 1 || d.OriginHash != hash {
				t.Error("OSpeer/origin lost")
			}
			return agent.SoftwareView{Protocol: 1, ServerOrigin: "https://localhost", Status: "staging", Purpose: strings.Repeat("<", 500), PackageName: strings.Repeat("😀", 200)}, nil
		})
	}()
	if _, e := client.Write(frame(poll)); e != nil {
		t.Fatal(e)
	}
	var n uint32
	if binary.Read(client, binary.LittleEndian, &n) != nil || n <= 4096 || n > 32768 {
		t.Fatal("software response bound", n)
	}
	b := make([]byte, n)
	if _, e := io.ReadFull(client, b); e != nil {
		t.Fatal(e)
	}
	var v agent.SoftwareView
	if json.Unmarshal(b, &v) != nil || v.Status != "staging" {
		t.Fatal("response")
	}
	<-done
	if calls != 1 {
		t.Fatal("helper dispatched twice")
	}
}
