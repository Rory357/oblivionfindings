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
	"time"
)

func TestDesktopPipeRejectsSpoofedIdentityReplayShapeAndUnboundedData(t *testing.T) {
	valid := `{"version":1,"operation":"desktop_poll","session_uuid":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb","worker_token":"` + strings.Repeat("a", 64) + `"}`
	for _, body := range []string{strings.Replace(valid, `"version":1`, `"version":1,"version":1`, 1), strings.Replace(valid, `"version":1`, `"version":1,"session_id":2`, 1),
		strings.Replace(valid, "desktop_poll", "remote_stop", 1), strings.Replace(valid, strings.Repeat("a", 64), "bad", 1), strings.Replace(valid, `"worker_token"`, `"credential"`, 1),
		`{"version":1,"operation":"desktop_frame","session_uuid":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb","worker_token":"` + strings.Repeat("a", 64) + `","sequence":1,"width":0,"height":1,"jpeg":"AQ=="}`} {
		if _, err := readDesktopRequest(bytes.NewReader(frame(body))); err == nil {
			t.Fatal("unsafe desktop request accepted")
		}
	}
	oversized := new(bytes.Buffer)
	binary.Write(oversized, binary.LittleEndian, uint32(MaxDesktopRequestBytes+1))
	if _, err := readDesktopRequest(oversized); err == nil {
		t.Fatal("oversized desktop request")
	}
	if _, err := readRequest(bytes.NewReader(frame(valid))); err == nil {
		t.Fatal("desktop operation accepted on consent channel")
	}
}
func TestDesktopPipeUsesVerifiedOSPeerAndKeepsInputZeroCoordinates(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	client.SetDeadline(time.Now().Add(time.Second))
	go serveDesktopConnection(server, func(net.Conn) (identity, error) {
		return identity{SID: "os-user", LogonSID: "os-logon", SessionID: 7}, nil
	},
		func(peer agent.RemotePeer, request agent.DesktopAction) (agent.DesktopView, error) {
			if peer.SID != "os-user" || peer.LogonSID != "os-logon" || peer.SessionID != 7 || request.Operation != "desktop_open" {
				t.Error("desktop identity not from OS")
			}
			return agent.DesktopView{Protocol: 1, Status: "waiting", Inputs: []map[string]any{}}, nil
		})
	client.Write(frame(`{"version":1,"operation":"desktop_open","session_uuid":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"}`))
	var size uint32
	if binary.Read(client, binary.LittleEndian, &size) != nil || size > MaxDesktopResponseBytes {
		t.Fatal("desktop reply bounds")
	}
	data := make([]byte, size)
	if _, err := io.ReadFull(client, data); err != nil {
		t.Fatal(err)
	}
	var result agent.DesktopView
	if json.Unmarshal(data, &result) != nil || result.Status != "waiting" {
		t.Fatal("desktop reply")
	}
}
