package agent

import "context"

func (m *StatusMonitor) SetServiceActions(controller *ServiceActionController) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.serviceActions = controller
}
func (m *StatusMonitor) ServiceAction(peer RemotePeer, decision ServiceActionDecision) (ServiceActionView, error) {
	m.mu.RLock()
	actions := m.serviceActions
	m.mu.RUnlock()
	if actions == nil {
		return ServiceActionView{}, errServiceAction
	}
	return actions.Handle(peer, decision)
}
func (m *StatusMonitor) serviceActionController() *ServiceActionController {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.serviceActions
}
func (r *ServiceActionController) available() bool { r.mu.Lock(); defer r.mu.Unlock(); return r.ready }
func (r *ServiceActionController) bind(ctx context.Context, store *Store, client *Client, state State, connected func() bool) (func(), error) {
	if store.writeProtected == nil || store.validateProtected == nil {
		return nil, errServiceAction
	}
	journal, e := loadServiceJournal(store, state.AgentUUID)
	if e != nil {
		return nil, e
	}
	r.mu.Lock()
	r.journal = journal
	r.mutationGate = &store.mutationGate
	r.origin = client.base
	r.transport = serviceActionHTTP{client, state.Credential}
	r.key = client.key
	r.agent = state.AgentUUID
	r.ready = true
	r.connected = connected
	r.mu.Unlock()
	store.serviceActionReady = r.available
	store.serviceActionObservations = r.observations
	actionCtx, cancel := context.WithCancel(ctx)
	done := make(chan struct{})
	go func() { defer close(done); r.Run(actionCtx) }()
	return func() { cancel(); <-done }, nil
}

type ServiceActionCapability struct {
	Version          int      `json:"version"`
	InstalledService bool     `json:"installed_service"`
	ReleaseEnabled   bool     `json:"release_enabled"`
	Executor         string   `json:"executor"`
	Operations       []string `json:"operations"`
}
