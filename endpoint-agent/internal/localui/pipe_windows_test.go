package localui

import (
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"io"
	"net"
	"testing"
	"time"

	"github.com/Microsoft/go-winio"
	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/agent"
)

func TestWindowsPipeUsesActualIdentificationTokenAndRejectsAnonymous(t *testing.T) {
	user, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		t.Fatal(err)
	}
	sid := user.User.Sid.String()
	nonce := make([]byte, 12)
	if _, err = rand.Read(nonce); err != nil {
		t.Fatal(err)
	}
	path := `\\.\pipe\Oblivion.Test.` + hex.EncodeToString(nonce)
	// Only the invoking test user can create/use this temporary fixture channel.
	listener, err := winio.ListenPipe(path, &winio.PipeConfig{SecurityDescriptor: "D:P(A;;GA;;;" + sid + ")", InputBufferSize: 1024, OutputBufferSize: 4096})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer listener.Close()
	identities := make(chan identity, 2)
	finished := make(chan error, 1)
	go func() {
		finished <- serve(ctx, listener, func(conn net.Conn) (identity, error) {
			who, err := readIdentity(conn)
			if err == nil {
				identities <- who
			}
			return who, err
		}, func() agent.UIStatus {
			return agent.UIStatus{Protocol: 1, AgentVersion: "test", Connection: "starting"}
		})
	}()
	for _, operation := range []string{"status", "diagnostics"} {
		for _, level := range []winio.PipeImpLevel{winio.PipeImpLevelIdentification, winio.PipeImpLevelAnonymous} {
			callContext, stop := context.WithTimeout(ctx, 3*time.Second)
			client, err := winio.DialPipeAccessImpLevel(callContext, path, 0x12019b, level)
			stop()
			if err != nil {
				t.Fatal(err)
			}
			client.SetDeadline(time.Now().Add(3 * time.Second))
			if _, err = client.Write(frame("{\"version\":1,\"operation\":\"" + operation + "\"}")); err != nil {
				client.Close()
				t.Fatal(err)
			}
			var size uint32
			readErr := binary.Read(client, binary.LittleEndian, &size)
			if level == winio.PipeImpLevelIdentification {
				if readErr != nil || size == 0 || size > MaxResponseBytes {
					client.Close()
					t.Fatal("identified Windows session rejected", readErr)
				}
				if _, err = io.ReadFull(client, make([]byte, size)); err != nil {
					t.Fatal(err)
				}
				select {
				case who := <-identities:
					if who.SID != sid || who.SessionID == 0 || who.LogonSID == "" {
						t.Fatal("wrong token identity")
					}
				case <-time.After(time.Second):
					t.Fatal("identity was not checked")
				}
			} else if readErr == nil {
				t.Fatal("anonymous client received status")
			}
			client.Close()
		}
	}
	cancel()
	select {
	case err := <-finished:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("pipe server did not stop")
	}
}
