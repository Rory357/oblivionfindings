package agent

import (
	"context"
	"encoding/json"
	"oblivion.local/endpoint-agent/internal/rtc"
	"strings"
	"testing"
	"time"
)

func openDesktopFixture(t *testing.T, control bool) (*RemoteController, *runtimePeerFixture, *time.Time, RemotePeer, DesktopView, rtc.Config) {
	t.Helper()
	r, transport, connection, now, peer, configs := mediaControllerFixture(t, false)
	if control {
		r.offer.AccessMode = "control"
		transport.offer.AccessMode = "control"
		transport.relay.AccessMode = "control"
	}
	r.mediaStep(context.Background(), true)
	awaitMediaReady(t, r)
	config := <-configs
	view, err := r.Desktop(peer, DesktopAction{Operation: "desktop_open", SessionUUID: r.offer.SessionUUID})
	if err != nil || view.Status != "starting" || len(view.WorkerToken) != 64 || config.Authorize("view") {
		t.Fatal("desktop opening bypassed signed activation", err)
	}
	r.Step(context.Background(), true)
	view, err = r.Desktop(peer, DesktopAction{Operation: "desktop_poll", SessionUUID: view.SessionUUID, WorkerToken: view.WorkerToken})
	if err != nil || view.Status != "active" || !config.Authorize("view") {
		t.Fatal("confirmed worker did not activate", err)
	}
	return r, connection, now, peer, view, config
}
func TestDesktopWorkerRequiresCurrentLogonTokenAndSignedActivation(t *testing.T) {
	r, _, _, peer, view, config := openDesktopFixture(t, false)
	if config.Authorize("control") {
		t.Fatal("view-only worker gained input")
	}
	for _, wrong := range []RemotePeer{{SID: "other", LogonSID: peer.LogonSID, SessionID: peer.SessionID}, {SID: peer.SID, LogonSID: "new", SessionID: peer.SessionID}, {SID: peer.SID, LogonSID: peer.LogonSID, SessionID: 9}} {
		if _, err := r.Desktop(wrong, DesktopAction{Operation: "desktop_poll", SessionUUID: view.SessionUUID, WorkerToken: view.WorkerToken}); err == nil {
			t.Fatal("cross-logon desktop")
		}
	}
	if _, err := r.Desktop(peer, DesktopAction{Operation: "desktop_poll", SessionUUID: view.SessionUUID, WorkerToken: strings.Repeat("f", 64)}); err == nil {
		t.Fatal("forged worker")
	}
	if config.Input(rtc.Input{Version: 1, Sequence: 1, Type: "move"}) == nil {
		t.Fatal("view-only input accepted")
	}
}
func TestDesktopInputPreservesZeroFieldsBoundsQueueAndExpiresWorker(t *testing.T) {
	r, _, now, peer, view, config := openDesktopFixture(t, true)
	if !config.Authorize("control") {
		t.Fatal("approved control not available")
	}
	input := rtc.Input{Version: 1, Sequence: 1, Type: "button_down", Button: 0, X: 0, Y: 0}
	if config.Input(input) != nil {
		t.Fatal("input not queued")
	}
	got, err := r.Desktop(peer, DesktopAction{Operation: "desktop_poll", SessionUUID: view.SessionUUID, WorkerToken: view.WorkerToken})
	if err != nil || len(got.Inputs) != 1 {
		t.Fatal("input not delivered", err)
	}
	raw, _ := json.Marshal(got.Inputs[0])
	decoded, err := rtc.DecodeInput(raw)
	if err != nil || decoded != input {
		t.Fatal("zero fields lost between RTC and native worker", string(raw), err)
	}
	r.mu.Lock()
	*now = now.Add(3 * time.Second)
	r.lastLocal = *now
	r.mu.Unlock()
	if _, err = r.Desktop(peer, DesktopAction{Operation: "desktop_poll", SessionUUID: view.SessionUUID, WorkerToken: view.WorkerToken}); err == nil || config.Authorize("view") {
		t.Fatal("stale worker resumed")
	}
}
func TestDesktopInputOverflowStopsInsteadOfLosingReleaseEvents(t *testing.T) {
	r, _, _, _, _, config := openDesktopFixture(t, true)
	for i := 1; i <= 128; i++ {
		if config.Input(rtc.Input{Version: 1, Sequence: uint64(i), Type: "move"}) != nil {
			t.Fatal("queue rejected bounded input")
		}
	}
	if config.Input(rtc.Input{Version: 1, Sequence: 129, Type: "key_up", Key: 65}) == nil || config.Authorize("control") {
		t.Fatal("input overflow retained session")
	}
	r.mu.Lock()
	retained := len(r.desktopInputs)
	r.mu.Unlock()
	if retained != 0 {
		t.Fatal("terminal input retained")
	}
}
func TestDesktopFramesRejectReplayAndLateStopCannotRearmWorker(t *testing.T) {
	r, connection, _, peer, view, _ := openDesktopFixture(t, false)
	connection.frameFn = func() error { return nil }
	frame := DesktopAction{Operation: "desktop_frame", SessionUUID: view.SessionUUID, WorkerToken: view.WorkerToken, Sequence: 1, Width: 1, Height: 1, JPEG: []byte{1}}
	if _, err := r.Desktop(peer, frame); err != nil {
		t.Fatal("frame transport failed", err)
	}
	if _, err := r.Desktop(peer, frame); err == nil {
		t.Fatal("frame replay accepted")
	}
	connection.frameFn = func() error {
		_, err := r.Handle(peer, RemoteAction{Operation: "remote_stop", SessionUUID: view.SessionUUID})
		return err
	}
	frame.Sequence = 2
	if _, err := r.Desktop(peer, frame); err == nil {
		t.Fatal("late frame reply restored stopped worker")
	}
	if _, err := r.Desktop(peer, DesktopAction{Operation: "desktop_open", SessionUUID: view.SessionUUID}); err == nil {
		t.Fatal("stopped worker reopened")
	}
}
