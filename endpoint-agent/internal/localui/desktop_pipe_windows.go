package localui

import (
	"context"
	"errors"
	"net"

	"github.com/Microsoft/go-winio"
	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/agent"
)

const DesktopPipePath = `\\.\pipe\Oblivion.Obli.Desktop.v1`

// Desktop traffic has a separate two-slot listener so image uploads cannot
// consume the status/consent/Stop channel's connection slots.
func StartDesktop(ctx context.Context, desktop func(agent.RemotePeer, agent.DesktopAction) (agent.DesktopView, error)) (*Server, error) {
	user, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil || !user.User.Sid.IsWellKnown(windows.WinLocalSystemSid) {
		return nil, errors.New("desktop channel requires the installed SYSTEM service")
	}
	listener, err := winio.ListenPipe(DesktopPipePath, &winio.PipeConfig{SecurityDescriptor: PipeSDDL, InputBufferSize: 65536, OutputBufferSize: MaxDesktopResponseBytes + 4})
	if err != nil {
		return nil, errors.New("desktop channel could not be secured")
	}
	server := &Server{listener: listener, done: make(chan error, 1)}
	go func() {
		server.done <- serveConnections(ctx, listener, 2, func(conn net.Conn) { serveDesktopConnection(conn, readIdentity, desktop) })
	}()
	return server, nil
}
