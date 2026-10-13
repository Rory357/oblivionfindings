package agent

import (
	"sync"
	"time"
)

// UIStatus is an explicit allowlist, never a serialization of machine state or
// inventory. The local helper must never receive machine/provider credentials.
type UIStatus struct {
	Protocol           int    `json:"protocol"`
	AgentVersion       string `json:"agent_version"`
	ServerOrigin       string `json:"server_origin"`
	Connection         string `json:"connection"`
	LastAcknowledgedAt string `json:"last_acknowledged_at,omitempty"`
	AIAvailable        bool   `json:"ai_available"`
	TicketsAvailable   bool   `json:"tickets_available"`
	RemoteAvailable    bool   `json:"remote_available"`
}
type StatusMonitor struct {
	restarts       *RestartController
	software       *SoftwareController
	serviceActions *ServiceActionController
	mu             sync.RWMutex
	status         UIStatus
	diagnostics    []byte
	remote         *RemoteController
	ticketComputer func(string, string) error
}

func NewStatusMonitor(version string) *StatusMonitor {
	return &StatusMonitor{status: UIStatus{Protocol: 1, AgentVersion: version, Connection: "starting"}}
}
func (m *StatusMonitor) Snapshot() UIStatus { m.mu.RLock(); defer m.mu.RUnlock(); return m.status }
func (m *StatusMonitor) bind(server string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.status.ServerOrigin = server
	m.status.Connection = "connecting"
}
func (m *StatusMonitor) connection(value string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.status.Connection = value
	if value == "connected" {
		m.status.LastAcknowledgedAt = time.Now().UTC().Format(time.RFC3339)
	}
}

func (m *StatusMonitor) SetRemote(controller *RemoteController) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.remote = controller
}
func (m *StatusMonitor) Remote(peer RemotePeer, action RemoteAction) (RemoteView, error) {
	m.mu.RLock()
	r := m.remote
	m.mu.RUnlock()
	if r == nil {
		return RemoteView{Protocol: 1, Status: "unavailable"}, nil
	}
	return r.Handle(peer, action)
}
func (m *StatusMonitor) remoteConnected() bool {
	status := m.Snapshot()
	last, err := time.Parse(time.RFC3339, status.LastAcknowledgedAt)
	return err == nil && status.Connection == "connected" && time.Since(last) >= 0 && time.Since(last) < 180*time.Second
}

func (m *StatusMonitor) remoteController() *RemoteController {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.remote
}
