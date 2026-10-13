package agent

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

type fakeRemote struct {
	offer      *RemoteOffer
	now        *time.Time
	decisions  []string
	stops      int
	leaseCalls int
	fail       bool
	onDecide   func()
}

func (f *fakeRemote) Offer(context.Context) (*RemoteOffer, error) {
	if f.fail {
		return nil, errors.New("offline")
	}
	if f.offer == nil {
		return nil, nil
	}
	copy := *f.offer
	return &copy, nil
}
func (f *fakeRemote) Decide(_ context.Context, _ RemoteOffer, _ string, decision string) error {
	f.decisions = append(f.decisions, decision)
	if f.onDecide != nil {
		f.onDecide()
	}
	if f.fail {
		return errors.New("uncertain")
	}
	return nil
}
func (f *fakeRemote) Lease(_ context.Context, offer RemoteOffer, desktop string, activate bool) (RemoteLease, error) {
	f.leaseCalls++
	if f.fail {
		return RemoteLease{}, errors.New("offline")
	}
	status := "allowed"
	if activate {
		status = "active"
	}
	return RemoteLease{Status: status, ExpiresAt: f.now.Add(15 * time.Second).Format(time.RFC3339)}, nil
}
func (f *fakeRemote) Stop(context.Context, string) error {
	f.stops++
	if f.fail {
		return errors.New("offline")
	}
	return nil
}
func localRemoteFixture(t *testing.T) (*RemoteController, *fakeRemote, *time.Time, RemotePeer) {
	t.Helper()
	offer, _, now := remoteFixture()
	peer := RemotePeer{SID: "S-user", LogonSID: "S-logon", SessionID: 2}
	r := NewRemoteController(func(p RemotePeer) bool { return p == peer })
	r.clock = func() time.Time { return now }
	f := &fakeRemote{offer: &offer, now: &now}
	r.bind("https://care.example.test", f)
	r.Step(context.Background(), true)
	return r, f, &now, peer
}
func pollRemote(t *testing.T, r *RemoteController, p RemotePeer) RemoteView {
	t.Helper()
	v, e := r.Handle(p, RemoteAction{Operation: "remote_poll"})
	if e != nil {
		t.Fatal(e)
	}
	return v
}
func answerRemote(r *RemoteController, p RemotePeer, v RemoteView, d string) (RemoteView, error) {
	return r.Handle(p, RemoteAction{Operation: "remote_decide", SessionUUID: v.SessionUUID, ConsentToken: v.ConsentToken, Decision: d})
}
func TestRemoteConsentBoundToLogonAndFreshLocalNonce(t *testing.T) {
	r, f, _, peer := localRemoteFixture(t)
	v := pollRemote(t, r, peer)
	if v.Status != "requested" || len(v.ConsentToken) != 64 || v.ConsentToken == f.offer.Challenge {
		t.Fatal("consent not separately bound")
	}
	for _, wrong := range []RemotePeer{{SID: "other", LogonSID: peer.LogonSID, SessionID: peer.SessionID}, {SID: peer.SID, LogonSID: "new-logon", SessionID: peer.SessionID}, {SID: peer.SID, LogonSID: peer.LogonSID, SessionID: 9}} {
		if _, err := answerRemote(r, wrong, v, "allow"); err == nil {
			t.Fatal("another Windows logon approved")
		}
	}
	bad := v
	bad.ConsentToken = strings.Repeat("b", 64)
	if _, err := answerRemote(r, peer, bad, "allow"); err == nil {
		t.Fatal("wrong nonce")
	}
	got, err := answerRemote(r, peer, v, "allow")
	if err != nil || got.Status != "waiting" || len(f.decisions) != 0 {
		t.Fatal("local answer falsely claimed upstream approval", err)
	}
	r.Step(context.Background(), true)
	got = pollRemote(t, r, peer)
	if got.Status != "allowed" || got.ConsentToken != "" || len(f.decisions) != 1 || f.decisions[0] != "allow" {
		t.Fatal("expected confirmed non-active approval")
	}
	if _, err = answerRemote(r, peer, v, "allow"); err == nil {
		t.Fatal("replayed local nonce")
	}
}
func TestLocalStopWinsAgainstInFlightApprovalAndReofferedRequest(t *testing.T) {
	r, f, now, peer := localRemoteFixture(t)
	v := pollRemote(t, r, peer)
	_, _ = answerRemote(r, peer, v, "allow")
	f.onDecide = func() {
		got, err := r.Handle(peer, RemoteAction{Operation: "remote_stop", SessionUUID: v.SessionUUID})
		if err != nil || got.Status != "stopped" {
			t.Fatal("local stop failed", err)
		}
	}
	r.Step(context.Background(), true)
	if f.leaseCalls != 0 || pollRemote(t, r, peer).Status != "stopped" {
		t.Fatal("late approval revived consent")
	}
	r.Step(context.Background(), true)
	*now = now.Add(3 * time.Second)
	r.Step(context.Background(), true)
	if r.offer != nil || len(f.decisions) != 1 || f.stops != 1 {
		t.Fatal("stopped offer was accepted again")
	}
}
func TestRemoteApprovalEndsOnLocalExpiryLockOrConnectionLoss(t *testing.T) {
	for _, kind := range []string{"helper-exit", "locked", "lease-expired", "offline"} {
		t.Run(kind, func(t *testing.T) {
			r, f, now, peer := localRemoteFixture(t)
			v := pollRemote(t, r, peer)
			_, _ = answerRemote(r, peer, v, "allow")
			r.Step(context.Background(), true)
			connected := true
			switch kind {
			case "helper-exit":
				*now = now.Add(7 * time.Second)
			case "locked":
				r.check = func(RemotePeer) bool { return false }
			case "lease-expired":
				*now = now.Add(16 * time.Second)
				r.lastLocal = *now
			case "offline":
				connected = false
			}
			r.Step(context.Background(), connected)
			if r.offer != nil || !r.leaseUntil.IsZero() || f.stops != 1 {
				t.Fatal("local grant survived loss of authority")
			}
		})
	}
}
func TestDeclineAndUnattendedCannotBecomeApproval(t *testing.T) {
	r, f, _, peer := localRemoteFixture(t)
	v := pollRemote(t, r, peer)
	got, err := answerRemote(r, peer, v, "decline")
	if err != nil || got.Status != "declined" {
		t.Fatal(err)
	}
	r.Step(context.Background(), true)
	if len(f.decisions) != 1 || f.decisions[0] != "decline" || f.leaseCalls != 0 {
		t.Fatal("decline not recorded as decline")
	}
	r2, f2, now, _ := localRemoteFixture(t)
	r2.offer = nil
	f2.offer.ConsentMode = "unattended"
	*now = now.Add(3 * time.Second)
	r2.Step(context.Background(), true)
	if r2.offer != nil || len(f2.decisions) != 0 {
		t.Fatal("unattended silently approved")
	}
}
func TestWithdrawnOfferAndUncertainDecisionFailClosed(t *testing.T) {
	for _, uncertain := range []bool{false, true} {
		t.Run(map[bool]string{false: "withdrawn", true: "uncertain"}[uncertain], func(t *testing.T) {
			r, f, now, peer := localRemoteFixture(t)
			v := pollRemote(t, r, peer)
			if uncertain {
				_, _ = answerRemote(r, peer, v, "allow")
				f.fail = true
			} else {
				f.offer = nil
				*now = now.Add(3 * time.Second)
			}
			r.Step(context.Background(), true)
			if r.offer != nil || f.leaseCalls != 0 {
				t.Fatal("unconfirmed session acquired a lease")
			}
		})
	}
}

func TestRemotePromptCannotChangeBehindTheLocalApproval(t *testing.T) {
	r, f, now, peer := localRemoteFixture(t)
	original := pollRemote(t, r, peer)
	f.offer.AccessMode = "control"
	*now = now.Add(3 * time.Second)
	r.Step(context.Background(), true)
	if r.offer != nil {
		t.Fatal("Changed offer preserved local consent")
	}
	if _, err := answerRemote(r, peer, original, "allow"); err == nil {
		t.Fatal("Old prompt approved a different capability")
	}
}
func TestLocalStopWorksWhileWindowsIsAlreadyLocked(t *testing.T) {
	r, _, _, peer := localRemoteFixture(t)
	original := pollRemote(t, r, peer)
	r.check = func(RemotePeer) bool { return false }
	got, err := r.Handle(peer, RemoteAction{Operation: "remote_stop", SessionUUID: original.SessionUUID})
	if err != nil || got.Status != "stopped" || r.offer != nil {
		t.Fatal("Lock prevented local stop")
	}
}
