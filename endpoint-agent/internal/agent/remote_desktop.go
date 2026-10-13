package agent

import (
	"errors"
	"time"

	"oblivion.local/endpoint-agent/internal/rtc"
)

// DesktopAction is handled only after the pipe obtains the caller's OS identity.
// It cannot choose a Windows logon or assert a different desktop binding.
type DesktopAction struct {
	Version     int    `json:"version"`
	Operation   string `json:"operation"`
	SessionUUID string `json:"session_uuid"`
	WorkerToken string `json:"worker_token,omitempty"`
	Sequence    uint64 `json:"sequence,omitempty"`
	Width       int    `json:"width,omitempty"`
	Height      int    `json:"height,omitempty"`
	JPEG        []byte `json:"jpeg,omitempty"`
}
type DesktopView struct {
	Protocol       int              `json:"protocol"`
	Origin         string           `json:"server_origin"`
	Status         string           `json:"status"`
	SessionUUID    string           `json:"session_uuid"`
	DesktopSession string           `json:"desktop_session"`
	AccessMode     string           `json:"access_mode"`
	WorkerToken    string           `json:"worker_token"`
	ExpiresAt      string           `json:"expires_at"`
	Inputs         []map[string]any `json:"inputs"`
}

func (r *RemoteController) desktopReadyLocked(now time.Time) bool {
	return r.media != nil && r.media.ready && r.media.ctx.Err() == nil &&
		r.workerToken != "" && now.Before(r.workerUntil)
}
func (r *RemoteController) desktopViewLocked(takeInputs bool) DesktopView {
	status := "waiting"
	if r.workerToken != "" {
		status = "starting"
	}
	if r.status == "active" && r.desktopReadyLocked(r.clock()) {
		status = "active"
	}
	expiry := r.leaseUntil
	if !r.workerUntil.IsZero() && r.workerUntil.Before(expiry) {
		expiry = r.workerUntil
	}
	view := DesktopView{Protocol: 1, Origin: r.origin, Status: status, SessionUUID: r.offer.SessionUUID, DesktopSession: r.desktop,
		AccessMode: r.offer.AccessMode, WorkerToken: r.workerToken, ExpiresAt: expiry.UTC().Format(time.RFC3339), Inputs: []map[string]any{}}
	if takeInputs && status == "active" {
		count := len(r.desktopInputs)
		if count > 32 {
			count = 32
		}
		for _, event := range r.desktopInputs[:count] {
			view.Inputs = append(view.Inputs, desktopInputFields(event))
		}
		r.desktopInputs = r.desktopInputs[count:]
	}
	return view
}
func desktopInputFields(event rtc.Input) map[string]any {
	value := map[string]any{"version": event.Version, "sequence": event.Sequence, "type": event.Type}
	switch event.Type {
	case "move":
		value["x"] = event.X
		value["y"] = event.Y
	case "button_down", "button_up":
		value["x"] = event.X
		value["y"] = event.Y
		value["button"] = event.Button
	case "wheel":
		value["x"] = event.X
		value["y"] = event.Y
		value["delta"] = event.Delta
	case "key_down", "key_up":
		value["key"] = event.Key
	}
	return value
}
func (r *RemoteController) Desktop(peer RemotePeer, action DesktopAction) (DesktopView, error) {
	r.mu.Lock()
	r.expireLocked(r.clock())
	if !peer.valid() || r.offer == nil || r.peer != peer || action.SessionUUID != r.offer.SessionUUID ||
		(r.status != "allowed" && r.status != "active") || r.check == nil || !r.check(peer) || r.leaseUntil.IsZero() {
		r.mu.Unlock()
		return DesktopView{}, errRemote
	}
	if action.Operation == "desktop_open" {
		if r.media != nil && r.media.ready && r.workerToken == "" {
			token, err := remoteNonce()
			if err != nil {
				r.mu.Unlock()
				return DesktopView{}, errRemote
			}
			r.workerToken = token
			r.workerUntil = r.clock().Add(2 * time.Second)
			r.nextNetwork = time.Time{}
		}
		result := r.desktopViewLocked(false)
		r.mu.Unlock()
		return result, nil
	}
	if r.workerToken == "" || action.WorkerToken != r.workerToken || !r.desktopReadyLocked(r.clock()) {
		r.mu.Unlock()
		return DesktopView{}, errRemote
	}
	switch action.Operation {
	case "desktop_poll":
		r.workerUntil = r.clock().Add(2 * time.Second)
		result := r.desktopViewLocked(true)
		r.mu.Unlock()
		return result, nil
	case "desktop_frame":
		if r.status != "active" || action.Sequence == 0 || action.Sequence <= r.frameSequence || action.Sequence > 9007199254740991 ||
			len(action.JPEG) == 0 || len(action.JPEG) > rtc.MaxFrame || action.Width < 1 || action.Height < 1 {
			r.mu.Unlock()
			return DesktopView{}, errRemote
		}
		r.frameSequence = action.Sequence
		current := r.media
		connection := current.connection
		r.mu.Unlock()
		// SendFrame independently rechecks live authority before every chunk.
		err := connection.SendFrame(action.JPEG, action.Width, action.Height)
		if err != nil && !errors.Is(err, rtc.ErrBackpressure) {
			return DesktopView{}, errRemote
		}
		r.mu.Lock()
		defer r.mu.Unlock()
		r.expireLocked(r.clock())
		if r.media != current || r.offer == nil || !r.desktopReadyLocked(r.clock()) || r.status != "active" {
			return DesktopView{}, errRemote
		}
		return r.desktopViewLocked(false), nil
	default:
		r.mu.Unlock()
		return DesktopView{}, errRemote
	}
}
func (r *RemoteController) queueDesktopInput(attempt *remoteMediaAttempt, input rtc.Input) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.expireLocked(r.clock())
	if r.media != attempt || r.offer == nil || r.revision != attempt.revision || r.status != "active" ||
		r.offer.AccessMode != "control" || !r.desktopReadyLocked(r.clock()) {
		return errRemote
	}
	if len(r.desktopInputs) >= 128 {
		r.endLocked("stopped", r.clock())
		return errRemote
	}
	r.desktopInputs = append(r.desktopInputs, input)
	return nil
}
func (m *StatusMonitor) Desktop(peer RemotePeer, action DesktopAction) (DesktopView, error) {
	r := m.remoteController()
	if r == nil {
		return DesktopView{}, errRemote
	}
	return r.Desktop(peer, action)
}
