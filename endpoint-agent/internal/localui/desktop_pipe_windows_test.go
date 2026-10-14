package localui

import (
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"github.com/Microsoft/go-winio"
	"golang.org/x/sys/windows"
	"io"
	"net"
	"oblivion.local/endpoint-agent/internal/agent"
	"testing"
	"time"
)

func TestWindowsDesktopPipeChecksRealIdentificationWithoutCapturing(t *testing.T) {
	user, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		t.Fatal(err)
	}
	sid := user.User.Sid.String()
	nonce := make([]byte, 12)
	if _, err = rand.Read(nonce); err != nil {
		t.Fatal(err)
	}
	path := `\\.\pipe\Oblivion.Desktop.Test.` + hex.EncodeToString(nonce)
	listener, err := winio.ListenPipe(path, &winio.PipeConfig{SecurityDescriptor: "D:P(A;;GA;;;" + sid + ")", InputBufferSize: 4096, OutputBufferSize: 4096})
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() {
		done <- serveConnections(ctx, listener, 2, func(conn net.Conn) {
			serveDesktopConnection(conn, readIdentity, func(peer agent.RemotePeer, action agent.DesktopAction) (agent.DesktopView, error) {
				if peer.SID != sid || peer.LogonSID == "" || peer.SessionID == 0 || action.Operation != "desktop_open" {
					t.Error("incorrect OS desktop peer")
				}
				return agent.DesktopView{Protocol: 1, Status: "waiting", Inputs: []map[string]any{}}, nil
			})
		})
	}()
	for _, level := range []winio.PipeImpLevel{winio.PipeImpLevelIdentification, winio.PipeImpLevelAnonymous} {
		call, stop := context.WithTimeout(ctx, 3*time.Second)
		client, err := winio.DialPipeAccessImpLevel(call, path, 0x12019b, level)
		stop()
		if err != nil {
			t.Fatal(err)
		}
		client.SetDeadline(time.Now().Add(3 * time.Second))
		_, err = client.Write(frame(`{"version":1,"operation":"desktop_open","session_uuid":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"}`))
		if err != nil {
			client.Close()
			t.Fatal(err)
		}
		var size uint32
		readErr := binary.Read(client, binary.LittleEndian, &size)
		if level == winio.PipeImpLevelIdentification {
			if readErr != nil || size == 0 || size > MaxDesktopResponseBytes {
				client.Close()
				t.Fatal("identified desktop denied", readErr)
			}
			if _, err = io.ReadFull(client, make([]byte, size)); err != nil {
				client.Close()
				t.Fatal(err)
			}
		} else if readErr == nil {
			client.Close()
			t.Fatal("anonymous desktop reader accepted")
		}
		client.Close()
	}
	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("desktop listener did not close")
	}
}
