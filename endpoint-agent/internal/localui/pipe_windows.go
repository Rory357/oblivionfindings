package localui

import (
	"context"
	"errors"
	"net"
	"runtime"
	"sync"
	"unsafe"

	"github.com/Microsoft/go-winio"
	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/agent"
)

const PipePath = `\\.\pipe\Oblivion.Obli.v1`

// Explicit interactive-user rights omit FILE_CREATE_PIPE_INSTANCE (bit 4).
// Remote access is also rejected by go-winio's native pipe creation.
const PipeSDDL = "D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;0x12019b;;;IU)"

var impersonateClient = windows.NewLazySystemDLL("advapi32.dll").NewProc("ImpersonateNamedPipeClient")

type Server struct {
	listener net.Listener
	done     chan error
}

func Start(ctx context.Context, snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), diagnostics ...func() agent.DiagnosticEnvelope) (*Server, error) {
	return StartWithTicketProof(ctx, snapshot, remote, nil, diagnostics...)
}
func StartWithTicketProof(ctx context.Context, snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, diagnostics ...func() agent.DiagnosticEnvelope) (*Server, error) {
	return StartWithServiceActions(ctx, snapshot, remote, proof, nil, diagnostics...)
}
func StartWithServiceActions(ctx context.Context, snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, actions func(agent.RemotePeer, agent.ServiceActionDecision) (agent.ServiceActionView, error), diagnostics ...func() agent.DiagnosticEnvelope) (*Server, error) {
	return StartWithRestarts(ctx, snapshot, remote, proof, actions, nil, diagnostics...)
}
func StartWithRestarts(ctx context.Context, snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, actions func(agent.RemotePeer, agent.ServiceActionDecision) (agent.ServiceActionView, error), restarts func(agent.RemotePeer, agent.RestartDecision) (agent.RestartView, error), diagnostics ...func() agent.DiagnosticEnvelope) (*Server, error) {
	return StartWithSoftware(ctx, snapshot, remote, proof, actions, restarts, nil, diagnostics...)
}
func StartWithSoftware(ctx context.Context, snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, actions func(agent.RemotePeer, agent.ServiceActionDecision) (agent.ServiceActionView, error), restarts func(agent.RemotePeer, agent.RestartDecision) (agent.RestartView, error), software func(agent.RemotePeer, agent.SoftwareDecision) (agent.SoftwareView, error), diagnostics ...func() agent.DiagnosticEnvelope) (*Server, error) {
	user, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil || !user.User.Sid.IsWellKnown(windows.WinLocalSystemSid) {
		return nil, errors.New("local helper server must run inside the installed SYSTEM service")
	}
	listener, err := winio.ListenPipe(PipePath, &winio.PipeConfig{SecurityDescriptor: PipeSDDL, InputBufferSize: MaxRequestBytes + 4, OutputBufferSize: MaxResponseBytes + 4})
	if err != nil {
		return nil, errors.New("local helper channel could not be secured")
	}
	server := &Server{listener: listener, done: make(chan error, 1)}
	go func() {
		server.done <- serveConnections(ctx, listener, 8, func(conn net.Conn) {
			serveHelperConnectionWithSoftware(conn, readIdentity, snapshot, remote, proof, actions, restarts, software, diagnostics...)
		})
	}()
	return server, nil
}
func (s *Server) Close()             { _ = s.listener.Close() }
func (s *Server) Done() <-chan error { return s.done }
func serve(ctx context.Context, listener net.Listener, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, diagnostics ...func() agent.DiagnosticEnvelope) error {
	return serveWithRemote(ctx, listener, authorize, snapshot, nil, diagnostics...)
}
func serveWithRemote(ctx context.Context, listener net.Listener, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), diagnostics ...func() agent.DiagnosticEnvelope) error {
	return serveConnections(ctx, listener, 8, func(connection net.Conn) {
		serveHelperConnection(connection, authorize, snapshot, remote, diagnostics...)
	})
}
func serveConnections(ctx context.Context, listener net.Listener, limit int, handle func(net.Conn)) error {
	stopped := make(chan struct{})
	defer close(stopped)
	go func() {
		select {
		case <-ctx.Done():
			listener.Close()
		case <-stopped:
		}
	}()
	slots := make(chan struct{}, limit)
	var workers sync.WaitGroup
	defer workers.Wait()
	for {
		connection, err := listener.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return errors.New("local helper channel stopped")
		}
		select {
		case slots <- struct{}{}:
			workers.Add(1)
			go func() {
				defer workers.Done()
				defer func() { <-slots }()
				handle(connection)
			}()
		default:
			connection.Close()
		}
	}
}

func readIdentity(conn net.Conn) (identity, error) {
	invalid := errors.New("helper Windows session identity could not be verified")
	descriptor, ok := conn.(interface{ Fd() uintptr })
	if !ok {
		return identity{}, invalid
	}
	runtime.LockOSThread()
	success, _, _ := impersonateClient.Call(descriptor.Fd())
	if success == 0 {
		runtime.UnlockOSThread()
		return identity{}, invalid
	}
	defer func() {
		// Never return an impersonating thread to Go's scheduler.
		if windows.RevertToSelf() != nil {
			panic("Windows helper identity could not be released")
		}
		runtime.UnlockOSThread()
	}()
	var token windows.Token
	thread, err := windows.GetCurrentThread()
	if err != nil {
		return identity{}, invalid
	}
	if windows.OpenThreadToken(thread, windows.TOKEN_QUERY, true, &token) != nil {
		return identity{}, invalid
	}
	defer token.Close()
	var level, session, size uint32
	if windows.GetTokenInformation(token, windows.TokenImpersonationLevel, (*byte)(unsafe.Pointer(&level)), 4, &size) != nil || level != windows.SecurityIdentification {
		return identity{}, invalid
	}
	if windows.GetTokenInformation(token, windows.TokenSessionId, (*byte)(unsafe.Pointer(&session)), 4, &size) != nil || session == 0 {
		return identity{}, invalid
	}
	user, err := token.GetTokenUser()
	if err != nil {
		return identity{}, invalid
	}
	groups, err := token.GetTokenGroups()
	if err != nil {
		return identity{}, invalid
	}
	interactive := false
	logon := ""
	for _, group := range groups.AllGroups() {
		if group.Attributes&windows.SE_GROUP_ENABLED == 0 || group.Attributes&windows.SE_GROUP_USE_FOR_DENY_ONLY != 0 {
			continue
		}
		if group.Sid.IsWellKnown(windows.WinInteractiveSid) {
			interactive = true
		}
		if group.Attributes&windows.SE_GROUP_LOGON_ID == windows.SE_GROUP_LOGON_ID {
			logon = group.Sid.String()
		}
	}
	if !interactive || logon == "" {
		return identity{}, invalid
	}
	return identity{SID: user.User.Sid.String(), LogonSID: logon, SessionID: session}, nil
}
