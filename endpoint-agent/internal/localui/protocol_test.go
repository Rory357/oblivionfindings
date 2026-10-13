package localui

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"testing"
	"time"

	"oblivion.local/endpoint-agent/internal/agent"
)

func frame(text string) []byte {
	data := []byte(text)
	out := make([]byte, 4+len(data))
	binary.LittleEndian.PutUint32(out, uint32(len(data)))
	copy(out[4:], data)
	return out
}
func TestLocalProtocolRejectsActionsAmbiguityAndUnboundedFrames(t *testing.T) {
	valid := frame(`{"version":1,"operation":"status"}`)
	if _, err := readRequest(bytes.NewReader(valid)); err != nil {
		t.Fatal(err)
	}
	for _, text := range []string{
		`{"version":1,"operation":"execute"}`,
		`{"version":1,"operation":"status","command":"whoami"}`,
		`{"version":1,"operation":"status","session_id":1}`,
		`{"version":1,"version":1,"operation":"status"}`,
		`{"version":1,"operation":"status","operation":"status"}`,
		`{"version":2,"operation":"status"}`, `{"version":1}`, `[]`,
		`{"version":1,"operation":"status"}{}`,
	} {
		if _, err := readRequest(bytes.NewReader(frame(text))); err == nil {
			t.Fatal("unsafe request accepted", text)
		}
	}
	for _, data := range [][]byte{{0, 0, 0, 0}, {255, 255, 255, 255}, valid[:len(valid)-1], valid[:2]} {
		if _, err := readRequest(bytes.NewReader(data)); err == nil {
			t.Fatal("invalid frame accepted")
		}
	}
}
func TestHelperChecksSessionBeforeReadingStatus(t *testing.T) {
	for _, who := range []identity{{}, {SID: "staff", LogonSID: "logon", SessionID: 0}, {SID: "staff", SessionID: 2}} {
		server, client := net.Pipe()
		called := false
		go serveConnection(server, func(net.Conn) (identity, error) { return who, nil }, func() agent.UIStatus { called = true; return agent.UIStatus{} })
		client.SetDeadline(time.Now().Add(time.Second))
		if _, err := client.Write(frame(`{"version":1,"operation":"status"}`)); err != nil {
			t.Fatal(err)
		}
		if _, err := client.Read(make([]byte, 1)); err == nil {
			t.Fatal("unidentified session received data")
		}
		client.Close()
		if called {
			t.Fatal("private status accessed before identity check")
		}
	}
}
func TestHelperResponseIsBoundedAndContainsOnlyAllowedStatus(t *testing.T) {
	server, client := net.Pipe()
	snapshot := agent.UIStatus{Protocol: 1, AgentVersion: "0.1.0-dev", ServerOrigin: "https://care.example.test", Connection: "connecting"}
	go serveConnection(server, func(net.Conn) (identity, error) { return identity{SID: "staff", LogonSID: "logon", SessionID: 1}, nil }, func() agent.UIStatus { return snapshot })
	defer client.Close()
	client.SetDeadline(time.Now().Add(time.Second))
	if _, err := client.Write(frame(`{"version":1,"operation":"status"}`)); err != nil {
		t.Fatal(err)
	}
	var size uint32
	if err := binary.Read(client, binary.LittleEndian, &size); err != nil || size > MaxResponseBytes {
		t.Fatal("invalid response length", err)
	}
	data := make([]byte, size)
	if _, err := io.ReadFull(client, data); err != nil {
		t.Fatal(err)
	}
	var actual agent.UIStatus
	if err := json.Unmarshal(data, &actual); err != nil || actual != snapshot {
		t.Fatal("status changed", err)
	}
	for _, field := range []string{"credential", "token", "software", "inventory", "chat", "api_key"} {
		if bytes.Contains(data, []byte(field)) {
			t.Fatal("private state field leaked", field)
		}
	}
	if _, err := client.Read(make([]byte, 1)); !errors.Is(err, io.EOF) {
		t.Fatal("one-request connection left open", err)
	}
}

func TestDiagnosticsRequireIdentityAndUseOnlyDedicatedProjection(t *testing.T) {
	for _, allowed := range []bool{false, true} {
		server, client := net.Pipe()
		called := false
		go serveConnection(server, func(net.Conn) (identity, error) {
			if !allowed {
				return identity{}, errors.New("denied")
			}
			return identity{SID: "user", LogonSID: "logon", SessionID: 1}, nil
		}, func() agent.UIStatus { t.Error("Diagnostics requested full status"); return agent.UIStatus{} }, func() agent.DiagnosticEnvelope { called = true; return agent.DiagnosticEnvelope{Protocol: 1} })
		client.SetDeadline(time.Now().Add(time.Second))
		if _, err := client.Write(frame("{\"version\":1,\"operation\":\"diagnostics\"}")); err != nil {
			t.Fatal(err)
		}
		var size uint32
		err := binary.Read(client, binary.LittleEndian, &size)
		if !allowed {
			if err == nil || called {
				t.Fatal("Unauthorised diagnostic read")
			}
		} else {
			if err != nil || !called || size > MaxResponseBytes {
				t.Fatal("Expected bounded diagnostic projection")
			}
			data := make([]byte, size)
			if _, err = io.ReadFull(client, data); err != nil {
				t.Fatal(err)
			}
			if string(data) != "{\"protocol\":1,\"available\":false}" {
				t.Fatal("Unexpected diagnostic envelope", string(data))
			}
		}
		client.Close()
	}
}
