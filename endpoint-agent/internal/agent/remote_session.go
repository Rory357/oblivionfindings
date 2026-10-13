package agent

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"oblivion.local/endpoint-agent/internal/rtc"
	"sync"
	"time"
)

// Only the authenticated OS pipe may supply this identity. Logon SID prevents
// a reused numeric Windows session from inheriting another user's consent.
type RemotePeer struct {
	SID, LogonSID string
	SessionID     uint32
}

func (p RemotePeer) valid() bool { return p.SID != "" && p.LogonSID != "" && p.SessionID != 0 }

type RemoteAction struct{ Operation, SessionUUID, ConsentToken, Decision string }
type RemoteView struct {
	Protocol     int    `json:"protocol"`
	ServerOrigin string `json:"server_origin"`
	Status       string `json:"status"`
	SessionUUID  string `json:"session_uuid,omitempty"`
	ConsentToken string `json:"consent_token,omitempty"`
	Technician   string `json:"technician,omitempty"`
	Purpose      string `json:"purpose,omitempty"`
	AccessMode   string `json:"access_mode,omitempty"`
	ExpiresAt    string `json:"expires_at,omitempty"`
}
type remoteClose struct {
	offer   RemoteOffer
	desktop string
	decline bool
}
type RemoteController struct {
	workerToken                        string
	workerUntil                        time.Time
	frameSequence                      uint64
	desktopInputs                      []rtc.Input
	media                              *remoteMediaAttempt
	mediaFactory                       remoteMediaFactory
	mu                                 sync.Mutex
	check                              func(RemotePeer) bool
	clock                              func() time.Time
	origin                             string
	transport                          RemoteTransport
	offer                              *RemoteOffer
	peer                               RemotePeer
	desktop, nonce, status             string
	revision                           uint64
	lastLocal, leaseUntil, nextNetwork time.Time
	stopped                            map[string]time.Time
	pendingClose                       *remoteClose
}

func NewRemoteController(check func(RemotePeer) bool) *RemoteController {
	return &RemoteController{check: check, clock: time.Now, status: "unavailable", stopped: map[string]time.Time{}}
}
func (r *RemoteController) bind(origin string, transport RemoteTransport) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.origin = origin
	r.transport = transport
	r.status = "idle"
	r.revision++
}
func remoteNonce() (string, error) {
	data := make([]byte, 32)
	if _, err := rand.Read(data); err != nil {
		return "", err
	}
	return hex.EncodeToString(data), nil
}
func remoteDesktop() (string, error) {
	var id [16]byte
	if _, err := rand.Read(id[:]); err != nil {
		return "", err
	}
	id[6] = (id[6] & 15) | 64
	id[8] = (id[8] & 63) | 128
	return fmt.Sprintf("%x-%x-%x-%x-%x", id[:4], id[4:6], id[6:8], id[8:10], id[10:]), nil
}

// Handle never waits for an upstream call. Stop revokes local approval first.
func (r *RemoteController) Handle(peer RemotePeer, action RemoteAction) (RemoteView, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if !peer.valid() {
		return RemoteView{}, errRemote
	}
	now := r.clock()
	// Stop remains usable if the OS has just locked; it cannot grant authority.
	if action.Operation == "remote_stop" {
		if r.offer == nil || r.offer.SessionUUID != action.SessionUUID || r.peer != peer {
			return RemoteView{}, errRemote
		}
		r.endLocked("stopped", now)
		return r.viewLocked(peer), nil
	}
	r.expireLocked(now)
	if action.Operation != "remote_poll" && action.Operation != "remote_decide" {
		return RemoteView{}, errRemote
	}
	if r.check == nil || !r.check(peer) {
		if r.peer == peer {
			r.endLocked("stopped", now)
		}
		return RemoteView{}, errRemote
	}
	if r.offer == nil {
		if action.Operation != "remote_poll" {
			return RemoteView{}, errRemote
		}
		return r.viewLocked(peer), nil
	}
	if !r.peer.valid() {
		if action.Operation != "remote_poll" {
			return RemoteView{}, errRemote
		}
		nonce, err := remoteNonce()
		if err != nil {
			return RemoteView{}, err
		}
		desktop, err := remoteDesktop()
		if err != nil {
			return RemoteView{}, err
		}
		r.peer = peer
		r.nonce = nonce
		r.desktop = desktop
		r.revision++
	}
	if r.peer != peer {
		return RemoteView{}, errRemote
	}
	r.lastLocal = now
	if action.Operation == "remote_decide" {
		if r.status != "requested" || action.SessionUUID != r.offer.SessionUUID || action.ConsentToken != r.nonce || (action.Decision != "allow" && action.Decision != "decline") {
			return RemoteView{}, errRemote
		}
		r.nonce = ""
		r.status = "waiting"
		r.nextNetwork = time.Time{}
		r.revision++
		if action.Decision == "decline" {
			r.endLocked("declined", now)
		}
	}
	return r.viewLocked(peer), nil
}
func (r *RemoteController) viewLocked(peer RemotePeer) RemoteView {
	v := RemoteView{Protocol: 1, ServerOrigin: r.origin, Status: r.status}
	if r.offer != nil && r.peer == peer {
		v.SessionUUID = r.offer.SessionUUID
		v.Technician = r.offer.TechnicianName
		v.Purpose = r.offer.Purpose
		v.AccessMode = r.offer.AccessMode
		v.ExpiresAt = r.offer.ExpiresAt
		if r.status == "allowed" || r.status == "active" {
			v.ExpiresAt = r.leaseUntil.UTC().Format(time.RFC3339)
		}
		if r.status == "requested" {
			v.ConsentToken = r.nonce
		}
	} else if r.offer != nil {
		v.Status = "idle"
	}
	return v
}
func (r *RemoteController) endLocked(status string, now time.Time) {
	if r.media != nil {
		r.media.cancel()
	}
	if r.offer != nil {
		r.stopped[r.offer.SessionUUID] = now.Add(3 * time.Minute)
		r.pendingClose = &remoteClose{offer: *r.offer, desktop: r.desktop, decline: status == "declined"}
	}
	r.workerToken = ""
	r.workerUntil = time.Time{}
	r.frameSequence = 0
	r.desktopInputs = nil
	r.offer = nil
	r.peer = RemotePeer{}
	r.desktop = ""
	r.nonce = ""
	r.leaseUntil = time.Time{}
	r.status = status
	r.revision++
}
func (r *RemoteController) expireLocked(now time.Time) {
	if r.offer == nil {
		return
	}
	expires, _ := time.Parse(time.RFC3339, r.offer.ExpiresAt)
	if ((r.status == "requested" || r.status == "waiting") && !now.Before(expires)) ||
		(r.peer.valid() && (now.Sub(r.lastLocal) > 6*time.Second || r.check == nil || !r.check(r.peer))) ||
		(!r.workerUntil.IsZero() && !now.Before(r.workerUntil)) ||
		(!r.leaseUntil.IsZero() && !now.Before(r.leaseUntil)) {
		r.endLocked("stopped", now)
	}
}
func (r *RemoteController) close() {
	r.mu.Lock()
	r.endLocked("unavailable", r.clock())
	transport := r.transport
	pending := r.pendingClose
	r.mu.Unlock()
	if transport != nil && pending != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		_ = transport.Stop(ctx, pending.offer.SessionUUID)
	}
}

// Step is serial on the service loop. Concurrent local Stop or changed logon
// invalidates an in-flight response, so it cannot restore consent.
func (r *RemoteController) Step(ctx context.Context, connected bool) {
	r.mu.Lock()
	now := r.clock()
	r.expireLocked(now)
	for id, expiry := range r.stopped {
		if !now.Before(expiry) {
			delete(r.stopped, id)
		}
	}
	if !connected {
		r.endLocked("unavailable", now)
	}
	transport := r.transport
	if transport == nil {
		r.mu.Unlock()
		return
	}
	pending := r.pendingClose
	if pending != nil {
		r.mu.Unlock()
		var err error
		if pending.decline {
			err = transport.Decide(ctx, pending.offer, pending.desktop, "decline")
		}
		if !pending.decline || err != nil {
			err = transport.Stop(ctx, pending.offer.SessionUUID)
		}
		r.mu.Lock()
		if r.pendingClose == pending && err == nil {
			r.pendingClose = nil
		}
		r.mu.Unlock()
		return
	}
	if !connected || now.Before(r.nextNetwork) {
		r.mu.Unlock()
		return
	}
	revision := r.revision
	status := r.status
	desktop := r.desktop
	activate := r.desktopReadyLocked(now)
	var offer RemoteOffer
	if r.offer != nil {
		offer = *r.offer
	}
	r.nextNetwork = now.Add(2 * time.Second)
	r.mu.Unlock()
	if offer.SessionUUID == "" || status == "requested" {
		current, err := transport.Offer(ctx)
		r.mu.Lock()
		defer r.mu.Unlock()
		if revision != r.revision {
			return
		}
		if err != nil {
			if r.offer != nil {
				r.endLocked("stopped", r.clock())
			} else {
				r.status = "unavailable"
				r.nextNetwork = r.clock().Add(30 * time.Second)
			}
			return
		}
		if current == nil {
			if r.offer != nil {
				r.endLocked("stopped", r.clock())
			} else {
				r.status = "idle"
			}
			return
		}
		// Unattended equipment needs the separate desktop/media worker integration.
		// Never silently convert it into an attended approval or implicit consent.
		if current.ConsentMode != "attended" {
			r.stopped[current.SessionUUID] = r.clock().Add(3 * time.Minute)
			r.pendingClose = &remoteClose{offer: *current}
			r.status = "unavailable"
			return
		}
		if _, stopped := r.stopped[current.SessionUUID]; stopped {
			r.pendingClose = &remoteClose{offer: *current}
			return
		}
		if r.offer != nil {
			old := *r.offer
			old.IssuedAt = current.IssuedAt
			if old != *current {
				r.endLocked("stopped", r.clock())
			}
			return
		}
		r.offer = current
		r.status = "requested"
		r.revision++
		return
	}
	if status == "waiting" {
		err := transport.Decide(ctx, offer, desktop, "allow")
		r.mu.Lock()
		if revision != r.revision {
			r.mu.Unlock()
			return
		}
		if err != nil {
			r.endLocked("stopped", r.clock())
			r.mu.Unlock()
			return
		}
		r.mu.Unlock()
	}
	lease, err := transport.Lease(ctx, offer, desktop, activate)
	r.mu.Lock()
	defer r.mu.Unlock()
	if revision != r.revision {
		return
	}
	r.expireLocked(r.clock())
	if revision != r.revision {
		return
	}
	expectedStatus := "allowed"
	if activate {
		expectedStatus = "active"
	}
	if err != nil || lease.Status != expectedStatus {
		r.endLocked("stopped", r.clock())
		return
	}
	expiry, err := time.Parse(time.RFC3339, lease.ExpiresAt)
	if err != nil || !expiry.After(r.clock()) || expiry.Sub(r.clock()) > 15*time.Second {
		r.endLocked("stopped", r.clock())
		return
	}
	r.status = lease.Status
	// Keep a monotonic deadline after validating the signed wall-clock expiry.
	verifiedAt := r.clock()
	r.leaseUntil = verifiedAt.Add(expiry.Sub(verifiedAt))
}
func (r *RemoteController) Run(ctx context.Context, connected func() bool) {
	mediaCtx, cancelMedia := context.WithCancel(ctx)
	mediaDone := make(chan struct{})
	go func() { defer close(mediaDone); r.runMedia(mediaCtx, connected) }()
	defer func() { r.close(); cancelMedia(); <-mediaDone }()
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			r.Step(ctx, connected())
		}
	}
}
