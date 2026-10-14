package agent

import (
	"context"
	"time"

	"oblivion.local/endpoint-agent/internal/rtc"
)

type remoteMediaTransport interface {
	Relay(context.Context, RemoteOffer, string) (RemoteRelayGrant, error)
	PublishMedia(context.Context, RemoteOffer, string, string, string) error
	MediaAnswer(context.Context, RemoteOffer, string, string) (*RemoteMediaAnswer, error)
}
type remoteMediaPeer interface {
	Offer(context.Context) (string, error)
	ApplyAnswer(string) error
	Ready() <-chan struct{}
	Done() <-chan struct{}
	Closed() bool
	Close()
	SendFrame([]byte, int, int) error
}
type remoteMediaAttempt struct {
	offer            RemoteOffer
	desktop, offerID string
	revision         uint64
	ctx              context.Context
	cancel           context.CancelFunc
	done             chan struct{}
	connection       remoteMediaPeer
	ready            bool
	relayUntil       time.Time
}
type remoteMediaFactory func(context.Context, rtc.Config) (remoteMediaPeer, error)

func newRemoteMedia(ctx context.Context, config rtc.Config) (remoteMediaPeer, error) {
	return rtc.New(ctx, config)
}

// runMedia never performs negotiation on the lease-renewal goroutine. The
// controller cancels this lifetime synchronously when local consent is lost.
func (r *RemoteController) runMedia(ctx context.Context, connected func() bool) {
	ticker := time.NewTicker(100 * time.Millisecond)
	defer ticker.Stop()
	defer func() {
		r.mu.Lock()
		current := r.media
		if current != nil {
			current.cancel()
		}
		r.mu.Unlock()
		if current != nil {
			<-current.done
		}
	}()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			r.mediaStep(ctx, connected())
		}
	}
}
func (r *RemoteController) mediaStep(ctx context.Context, connected bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.expireLocked(r.clock())
	if !connected && r.offer != nil {
		r.endLocked("unavailable", r.clock())
	}
	if r.media != nil {
		select {
		case <-r.media.done:
			r.media = nil
		default:
			return
		}
	}
	if ctx.Err() != nil || !connected || r.status != "allowed" || r.offer == nil || r.leaseUntil.IsZero() {
		return
	}
	transport, ok := r.transport.(remoteMediaTransport)
	if !ok {
		return
	}
	offerID, err := remoteDesktop()
	if err != nil {
		r.endLocked("stopped", r.clock())
		return
	}
	lifetime, cancel := context.WithCancel(ctx)
	attempt := &remoteMediaAttempt{offer: *r.offer, desktop: r.desktop, offerID: offerID, revision: r.revision, ctx: lifetime, cancel: cancel, done: make(chan struct{})}
	factory := r.mediaFactory
	if factory == nil {
		factory = newRemoteMedia
	}
	r.media = attempt
	go r.negotiateMedia(attempt, transport, factory)
}
func (r *RemoteController) mediaPermitted(attempt *remoteMediaAttempt, operation string) bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.expireLocked(r.clock())
	if attempt.ctx.Err() != nil || r.media != attempt || r.revision != attempt.revision || r.offer == nil ||
		r.offer.SessionUUID != attempt.offer.SessionUUID || r.desktop != attempt.desktop || r.leaseUntil.IsZero() ||
		(!attempt.relayUntil.IsZero() && !r.clock().Before(attempt.relayUntil)) {
		return false
	}
	switch operation {
	case "negotiate":
		return r.status == "allowed" || r.status == "active"
	case "view":
		return r.status == "active" && r.desktopReadyLocked(r.clock())
	case "control":
		return r.status == "active" && r.desktopReadyLocked(r.clock()) && attempt.offer.AccessMode == "control"
	}
	return false
}
func (r *RemoteController) negotiateMedia(attempt *remoteMediaAttempt, transport remoteMediaTransport, factory remoteMediaFactory) {
	defer func() {
		attempt.cancel()
		if attempt.connection != nil {
			attempt.connection.Close()
		}
		r.mu.Lock()
		if r.media == attempt && r.revision == attempt.revision && r.offer != nil {
			r.endLocked("stopped", r.clock())
		}
		r.mu.Unlock()
		close(attempt.done)
	}()
	// The entire setup is bounded, independently of ongoing signed lease renewal.
	setup, finishSetup := context.WithTimeout(attempt.ctx, 30*time.Second)
	defer finishSetup()
	grant, err := transport.Relay(setup, attempt.offer, attempt.desktop)
	if err != nil || !r.mediaPermitted(attempt, "negotiate") {
		return
	}
	credentialExpiry, err := time.Parse(time.RFC3339, grant.CredentialExpiresAt)
	r.mu.Lock()
	verifiedAt := r.clock()
	if err != nil || !credentialExpiry.After(verifiedAt) || credentialExpiry.Sub(verifiedAt) > 120*time.Minute {
		r.mu.Unlock()
		return
	}
	attempt.relayUntil = verifiedAt.Add(credentialExpiry.Sub(verifiedAt))
	r.mu.Unlock()
	connection, err := factory(attempt.ctx, rtc.Config{
		SessionUUID: attempt.offer.SessionUUID, DesktopSession: attempt.desktop, OfferUUID: attempt.offerID,
		AccessMode: attempt.offer.AccessMode, Servers: grant.servers(),
		Authorize: func(operation string) bool { return r.mediaPermitted(attempt, operation) },
		Input:     func(input rtc.Input) error { return r.queueDesktopInput(attempt, input) },
	})
	if err != nil {
		return
	}
	attempt.connection = connection
	if !r.mediaPermitted(attempt, "negotiate") {
		return
	}
	sdp, err := connection.Offer(setup)
	if err != nil || !r.mediaPermitted(attempt, "negotiate") {
		return
	}
	if transport.PublishMedia(setup, attempt.offer, attempt.desktop, attempt.offerID, sdp) != nil {
		return
	}
	var answer *RemoteMediaAnswer
	for {
		if !r.mediaPermitted(attempt, "negotiate") {
			return
		}
		answer, err = transport.MediaAnswer(setup, attempt.offer, attempt.desktop, attempt.offerID)
		if err != nil {
			return
		}
		if answer != nil {
			break
		}
		if waitFor(setup, 500*time.Millisecond) != nil {
			return
		}
	}
	if !r.mediaPermitted(attempt, "negotiate") || connection.ApplyAnswer(answer.SDP) != nil {
		return
	}
	select {
	case <-setup.Done():
		return
	case <-connection.Done():
		return
	case <-connection.Ready():
	}
	if connection.Closed() || !r.mediaPermitted(attempt, "negotiate") {
		return
	}
	r.mu.Lock()
	if r.media == attempt && r.revision == attempt.revision && attempt.ctx.Err() == nil {
		attempt.ready = true
	}
	r.mu.Unlock()
	finishSetup()
	ticker := time.NewTicker(100 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-attempt.ctx.Done():
			return
		case <-ticker.C:
			if connection.Closed() || !r.mediaPermitted(attempt, "negotiate") {
				return
			}
		}
	}
}
