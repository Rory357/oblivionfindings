package agent

import (
	"context"
	"errors"
	"sync/atomic"
	"testing"
	"time"

	"oblivion.local/endpoint-agent/internal/rtc"
)

type runtimeTransportFixture struct {
	*fakeRemote
	relay         RemoteRelayGrant
	relayFailure  bool
	answerEntered chan struct{}
	releaseAnswer chan struct{}
	published     atomic.Int32
}

func (f *runtimeTransportFixture) Relay(context.Context, RemoteOffer, string) (RemoteRelayGrant, error) {
	if f.relayFailure {
		return RemoteRelayGrant{}, errors.New("relay unavailable")
	}
	return f.relay, nil
}
func (f *runtimeTransportFixture) PublishMedia(context.Context, RemoteOffer, string, string, string) error {
	f.published.Add(1)
	return nil
}
func (f *runtimeTransportFixture) MediaAnswer(ctx context.Context, offer RemoteOffer, desktop, id string) (*RemoteMediaAnswer, error) {
	if f.answerEntered != nil {
		close(f.answerEntered)
		// Deliberately emulate a late transport reply that ignored cancellation.
		<-f.releaseAnswer
	}
	return &RemoteMediaAnswer{SDP: "fixture answer"}, nil
}

type runtimePeerFixture struct {
	frameFn   func() error
	ready     chan struct{}
	entered   chan struct{}
	holdOffer bool
	holdReady bool
	done      chan struct{}
	closed    atomic.Bool
	applied   atomic.Int32
}

func (p *runtimePeerFixture) Offer(ctx context.Context) (string, error) {
	close(p.entered)
	if p.holdOffer {
		<-ctx.Done()
		return "", ctx.Err()
	}
	return "fixture offer", nil
}
func (p *runtimePeerFixture) ApplyAnswer(string) error {
	p.applied.Add(1)
	if !p.holdReady {
		close(p.ready)
	}
	return nil
}
func (p *runtimePeerFixture) Ready() <-chan struct{} { return p.ready }
func (p *runtimePeerFixture) Closed() bool           { return p.closed.Load() }
func (p *runtimePeerFixture) Close() {
	if p.closed.CompareAndSwap(false, true) {
		close(p.done)
	}
}
func (p *runtimePeerFixture) Done() <-chan struct{} { return p.done }
func (p *runtimePeerFixture) SendFrame([]byte, int, int) error {
	if p.frameFn != nil {
		return p.frameFn()
	}
	return errors.New("no capture in fixture")
}

func mediaControllerFixture(t *testing.T, holdOffer bool) (*RemoteController, *runtimeTransportFixture, *runtimePeerFixture, *time.Time, RemotePeer, <-chan rtc.Config) {
	t.Helper()
	r, f, now, peer := localRemoteFixture(t)
	v := pollRemote(t, r, peer)
	if _, err := answerRemote(r, peer, v, "allow"); err != nil {
		t.Fatal(err)
	}
	r.Step(context.Background(), true)
	transport := &runtimeTransportFixture{fakeRemote: f, relay: relayGrantFixture(*f.offer, r.desktop, *now)}
	connection := &runtimePeerFixture{ready: make(chan struct{}), entered: make(chan struct{}), done: make(chan struct{}), holdOffer: holdOffer}
	configs := make(chan rtc.Config, 1)
	r.transport = transport
	r.mediaFactory = func(ctx context.Context, config rtc.Config) (remoteMediaPeer, error) {
		configs <- config
		return connection, nil
	}
	t.Cleanup(func() {
		r.mu.Lock()
		if r.offer != nil {
			r.endLocked("stopped", r.clock())
		}
		attempt := r.media
		r.mu.Unlock()
		if attempt != nil {
			waitMedia(t, attempt.done)
		}
	})
	return r, transport, connection, now, peer, configs
}
func waitMedia(t *testing.T, signal <-chan struct{}) {
	t.Helper()
	select {
	case <-signal:
	case <-time.After(2 * time.Second):
		t.Fatal("media did not settle")
	}
}
func currentMedia(r *RemoteController) *remoteMediaAttempt {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.media
}
func awaitMediaReady(t *testing.T, r *RemoteController) {
	t.Helper()
	deadline := time.After(2 * time.Second)
	for {
		r.mu.Lock()
		ready := r.media != nil && r.media.ready
		r.mu.Unlock()
		if ready {
			return
		}
		select {
		case <-deadline:
			t.Fatal("media not ready")
		case <-time.After(time.Millisecond):
		}
	}
}
func TestNegotiationDoesNotBlockRenewalAndLocalStopCancelsSetup(t *testing.T) {
	r, transport, connection, now, peer, configs := mediaControllerFixture(t, true)
	r.mediaStep(context.Background(), true)
	waitMedia(t, connection.entered)
	config := <-configs
	attempt := currentMedia(r)
	before := transport.leaseCalls
	r.mu.Lock()
	*now = now.Add(3 * time.Second)
	r.mu.Unlock()
	pollRemote(t, r, peer)
	renewed := make(chan struct{})
	go func() { r.Step(context.Background(), true); close(renewed) }()
	waitMedia(t, renewed)
	if transport.leaseCalls != before+1 {
		t.Fatal("negotiation blocked lease renewal")
	}
	if config.Authorize("view") || config.Authorize("control") {
		t.Fatal("setup granted capture or control")
	}
	if _, err := r.Handle(peer, RemoteAction{Operation: "remote_stop", SessionUUID: attempt.offer.SessionUUID}); err != nil {
		t.Fatal(err)
	}
	if attempt.ctx.Err() == nil || config.Authorize("negotiate") {
		t.Fatal("Stop did not revoke synchronously")
	}
	waitMedia(t, attempt.done)
	if !connection.Closed() || transport.published.Load() != 0 {
		t.Fatal("stopped negotiation published or leaked")
	}
}
func TestReadyMediaStillRequiresSeparateActiveDesktopWorker(t *testing.T) {
	r, transport, connection, _, peer, configs := mediaControllerFixture(t, false)
	r.mediaStep(context.Background(), true)
	awaitMediaReady(t, r)
	config := <-configs
	if !config.Authorize("negotiate") || config.Authorize("view") || config.Authorize("control") || config.Input(rtc.Input{}) == nil {
		t.Fatal("negotiation bypassed desktop activation")
	}
	if pollRemote(t, r, peer).Status != "allowed" || transport.published.Load() != 1 || connection.applied.Load() != 1 {
		t.Fatal("wrong negotiated state")
	}
	r.mediaStep(context.Background(), true)
	if transport.published.Load() != 1 {
		t.Fatal("multiple offers for same approval")
	}
}
func TestLateMediaAnswerCannotRestoreStoppedDesktop(t *testing.T) {
	r, transport, connection, _, peer, _ := mediaControllerFixture(t, false)
	transport.answerEntered = make(chan struct{})
	transport.releaseAnswer = make(chan struct{})
	r.mediaStep(context.Background(), true)
	waitMedia(t, transport.answerEntered)
	attempt := currentMedia(r)
	_, err := r.Handle(peer, RemoteAction{Operation: "remote_stop", SessionUUID: attempt.offer.SessionUUID})
	if err != nil {
		t.Fatal(err)
	}
	close(transport.releaseAnswer)
	waitMedia(t, attempt.done)
	if connection.applied.Load() != 0 || !connection.Closed() {
		t.Fatal("late answer restored media")
	}
}
func TestNegotiatedMediaClosesOnOSLeaseConnectionAndRelayLoss(t *testing.T) {
	for _, reason := range []string{"locked", "helper", "lease", "offline", "relay", "disconnected"} {
		t.Run(reason, func(t *testing.T) {
			r, _, connection, now, _, configs := mediaControllerFixture(t, false)
			r.mediaStep(context.Background(), true)
			awaitMediaReady(t, r)
			config := <-configs
			attempt := currentMedia(r)
			connected := true
			r.mu.Lock()
			switch reason {
			case "locked":
				r.check = func(RemotePeer) bool { return false }
			case "helper":
				*now = now.Add(7 * time.Second)
			case "lease":
				*now = now.Add(16 * time.Second)
				r.lastLocal = *now
			case "offline":
				connected = false
			case "relay":
				attempt.relayUntil = *now
			case "disconnected":
				connection.Close()
			}
			r.mu.Unlock()
			r.mediaStep(context.Background(), connected)
			waitMedia(t, attempt.done)
			if config.Authorize("negotiate") || !connection.Closed() {
				t.Fatal("media survived lost authority")
			}
			r.mu.Lock()
			remaining := r.offer
			r.mu.Unlock()
			if remaining != nil {
				t.Fatal("session still locally approved")
			}
		})
	}
}
func TestMissingRelayFailsClosedWithoutRetryingAnotherDestination(t *testing.T) {
	r, transport, _, _, peer, _ := mediaControllerFixture(t, false)
	transport.relayFailure = true
	r.mediaStep(context.Background(), true)
	attempt := currentMedia(r)
	waitMedia(t, attempt.done)
	if pollRemote(t, r, peer).Status != "stopped" || transport.published.Load() != 0 {
		t.Fatal("missing relay did not stop")
	}
	r.mediaStep(context.Background(), true)
	if currentMedia(r) != nil {
		t.Fatal("failed session resumed")
	}
}
func TestServiceMediaLoopCancelsAndJoinsPendingNegotiation(t *testing.T) {
	r, _, connection, _, _, _ := mediaControllerFixture(t, true)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { defer close(done); r.runMedia(ctx, func() bool { return true }) }()
	waitMedia(t, connection.entered)
	cancel()
	waitMedia(t, done)
	if !connection.Closed() {
		t.Fatal("shutdown leaked media")
	}
}

func TestDisconnectedTransportDoesNotWaitForSetupTimeout(t *testing.T) {
	r, _, connection, _, _, _ := mediaControllerFixture(t, false)
	connection.holdReady = true
	r.mediaStep(context.Background(), true)
	waitMedia(t, connection.entered)
	attempt := currentMedia(r)
	connection.Close()
	waitMedia(t, attempt.done)
	r.mu.Lock()
	status := r.status
	r.mu.Unlock()
	if status != "stopped" {
		t.Fatal("disconnected handshake retained consent")
	}
}
