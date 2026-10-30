package localui

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"io"
	"net"
	"oblivion.local/endpoint-agent/internal/agent"
	"testing"
	"time"
)

func TestRemotePipeRejectsClaimedIdentityAndExtraDecisionFields(t *testing.T) {
	for _, value := range []string{
		`{"version":1,"operation":"remote_poll","session_id":2}`,
		`{"version":1,"operation":"remote_poll","decision":"allow"}`,
		`{"version":1,"operation":"remote_stop","session_uuid":null}`,
		`{"version":1,"operation":"remote_decide","session_uuid":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb","consent_token":"x","decision":"allow"}`,
		`{"version":1,"operation":"remote_poll","operation":"remote_stop"}`,
	} {
		if _, err := readRequest(bytes.NewReader(frame(value))); err == nil {
			t.Fatal("unsafe remote frame accepted")
		}
	}
}
func TestRemotePipePassesOnlyVerifiedOSIdentity(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	client.SetDeadline(time.Now().Add(time.Second))
	who := identity{SID: "actual-user", LogonSID: "actual-logon", SessionID: 8}
	go serveHelperConnection(server, func(net.Conn) (identity, error) { return who, nil }, nil, func(peer agent.RemotePeer, action agent.RemoteAction) (agent.RemoteView, error) {
		if peer.SID != who.SID || peer.LogonSID != who.LogonSID || peer.SessionID != who.SessionID || action.Operation != "remote_poll" {
			t.Error("pipe did not bind real OS peer")
		}
		return agent.RemoteView{Protocol: 1, ServerOrigin: "https://care.example.test", Status: "idle"}, nil
	})
	if _, err := client.Write(frame(`{"version":1,"operation":"remote_poll"}`)); err != nil {
		t.Fatal(err)
	}
	var size uint32
	if binary.Read(client, binary.LittleEndian, &size) != nil || size > MaxResponseBytes {
		t.Fatal("invalid reply")
	}
	data := make([]byte, size)
	if _, err := io.ReadFull(client, data); err != nil {
		t.Fatal(err)
	}
	var result agent.RemoteView
	if json.Unmarshal(data, &result) != nil || result.Status != "idle" {
		t.Fatal("remote response")
	}
}
