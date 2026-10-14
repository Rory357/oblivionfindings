package cctv

import "testing"

func TestCloseBeforeOpenRetainsBoundedTombstoneAndDoesNotResurrectPeer(t *testing.T) {
	w, g, open, key, media := fixture(t)
	defer w.Close()
	close := Message{Version: 1, Operation: "close", Request: g.Session, Session: g.Session, Generation: g.Generation}
	r := w.Handle(t.Context(), wire(t, close))
	if r.Status != "stopped" || r.Generation != g.Generation || len(w.sessions) != 1 {
		t.Fatal("unknown close was not retained", r)
	}
	if r := w.Handle(t.Context(), wire(t, open)); r.Status == "ready" || r.Status == "negotiating" {
		t.Fatal("late open resurrected closed peer", r)
	}
	g.Generation++
	open.Grant = sign(key, g)
	if r := w.Handle(t.Context(), wire(t, open)); r.Status == "ready" || r.Status == "negotiating" {
		t.Fatal("later grant resurrected closed peer", r)
	}
	if media.closed.Load() {
		t.Fatal("factory was called for a closed absent peer")
	}
	if r := w.Handle(t.Context(), wire(t, close)); r.Status != "stopped" || len(w.sessions) != 1 {
		t.Fatal("close retry changed receipt", r)
	}
	// Never evict a process receipt to acknowledge another unknown close.
	for len(w.sessions) < 256 {
		w.sessions[string(rune(len(w.sessions)))] = &session{terminal: true}
	}
	close.Session = "55555555-5555-4555-8555-555555555555"
	if r := w.Handle(t.Context(), wire(t, close)); r.Status != "failed" || r.Reason == nil || *r.Reason != "capacity_unavailable" || len(w.sessions) != 256 {
		t.Fatal("close receipt capacity was not bounded", r)
	}
}

func TestCloseAcknowledgesUndeliveredRenewalGenerationWithoutExtendingPeer(t *testing.T) {
	w, g, open, _, media := fixture(t)
	defer w.Close()
	if r := w.Handle(t.Context(), wire(t, open)); r.Status != "ready" {
		t.Fatal(r)
	}
	deadline := w.sessions[g.Session].deadline
	close := Message{Version: 1, Operation: "close", Request: g.Session, Session: g.Session, Generation: g.Generation + 1}
	r := w.Handle(t.Context(), wire(t, close))
	if r.Status != "stopped" || r.Generation != close.Generation || !media.closed.Load() || w.sessions[g.Session].deadline != deadline {
		t.Fatal("undelivered renewal prevented close", r)
	}
	if r := w.Handle(t.Context(), wire(t, open)); r.Status == "ready" {
		t.Fatal("closed peer reopened", r)
	}
}
