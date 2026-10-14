package cctv

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"io"
	"sync"
	"time"
)

type Media interface{ Close() error }
type Factory func(context.Context, Grant, Source, Turn, Description) (Media, Description, string)
type session struct {
	grant       Grant
	reply       Reply
	media       Media
	cancel      context.CancelFunc
	deadline    time.Time
	iceDeadline time.Time
	terminal    bool
}
type Worker struct {
	discovery  hikvisionDiscoveryState
	mu         sync.Mutex
	collector  string
	site       int64
	key        ed25519.PublicKey
	configured bool
	sessions   map[string]*session
	factory    Factory
	now        func() time.Time
	emit       func(Reply)
}

func New(factory Factory) *Worker {
	if factory == nil {
		factory = OpenMedia
	}
	return &Worker{sessions: map[string]*session{}, factory: factory, now: time.Now, discovery: hikvisionDiscoveryState{receipts: map[string]hikvisionDiscoveryReceipt{}, run: func(ctx context.Context, source httpsTunnelSource) (hikvisionInventory, error) {
		return discoverHikvision(ctx, source, nil)
	}, runRecorder: func(ctx context.Context, source httpsTunnelSource) (hikvisionRecorderInventory, error) {
		return discoverHikvisionRecorder(ctx, source, nil)
	}}}
}
func reason(s string) *string { return &s }
func (w *Worker) closeLocked(s *session, status, code string) {
	if s.terminal {
		return
	}
	s.terminal = true
	s.cancel()
	if s.media != nil {
		_ = s.media.Close()
		s.media = nil
	}
	s.reply.Status = status
	s.reply.Reason = reason(code)
	s.reply.Answer = nil
}
func (w *Worker) Close() {
	w.mu.Lock()
	defer w.mu.Unlock()
	w.discovery.stopped = true
	if w.discovery.cancel != nil {
		w.discovery.cancel()
	}
	for _, s := range w.sessions {
		w.closeLocked(s, "stopped", "worker_unavailable")
	}
}
func (w *Worker) Handle(ctx context.Context, b []byte) Reply {
	m, e := decodeMessage(b)
	out := Reply{Version: 1, Request: m.Request, Session: m.Session, Generation: m.Generation, Status: "failed", Reason: reason("worker_unavailable")}
	if e != nil {
		return out
	}
	w.mu.Lock()
	if m.Operation == "configure" {
		defer w.mu.Unlock()
		k, e := base64.StdEncoding.Strict().DecodeString(m.Key)
		if w.configured || e != nil || len(k) != ed25519.PublicKeySize || !uuid.MatchString(m.Collector) || m.Site <= 0 {
			return out
		}
		w.collector, w.site, w.key, w.configured = m.Collector, m.Site, k, true
		out.Status = "requested"
		out.Reason = nil
		return out
	}
	if !w.configured {
		w.mu.Unlock()
		return out
	}
	if m.Operation == "health" || m.Operation == "health_hikvision_live" || m.Operation == "health_hikvision_recorder_discovery" {
		w.mu.Unlock()
		out.Status = "requested"
		out.Reason = nil
		return out
	}
	if m.Operation == "close" {
		defer w.mu.Unlock()
		s := w.sessions[m.Session]
		if m.Generation == 0 {
			return out
		}
		if s == nil {
			// The private authenticated collector may close before its queued
			// Open arrives. Retain a bounded tombstone so that Open cannot
			// resurrect the peer after the close acknowledgement.
			if len(w.sessions) >= 256 {
				out.Reason = reason("capacity_unavailable")
				return out
			}
			out.Status, out.Reason = "stopped", reason("stopped")
			w.sessions[m.Session] = &session{grant: Grant{Session: m.Session, Generation: m.Generation}, reply: out, terminal: true}
			return out
		}
		// A renewal may have been issued centrally but never delivered here.
		// Closing is authority reduction and does not require that generation
		// to have opened or renewed a peer.
		w.closeLocked(s, "stopped", "stopped")
		out = s.reply
		out.Request, out.Generation = m.Request, m.Generation
		return out
	}
	g, e := verify(m.Grant, w.key, w.collector, w.site, w.now())
	if e != nil {
		w.mu.Unlock()
		out.Reason = reason("authority_lost")
		return out
	}
	out.Session, out.Generation = g.Session, g.Generation
	var source Source
	var turn Turn
	var ice time.Time
	if m.Operation == "open" {
		source, e = decodeMediaSource(m.Source, g)
		if e != nil || Closed(m.Turn, "urls", "username", "credential", "expires_at", "relay_cidrs", "relay_port_min", "relay_port_max") != nil || json.Unmarshal(m.Turn, &turn) != nil || hash(m.Source) != g.SourceHash || hash(m.Turn) != g.TurnHash || hash([]byte(m.Offer.SDP)) != g.OfferHash || m.Offer.Type != "offer" {
			w.mu.Unlock()
			out.Reason = reason("authority_lost")
			return out
		}
		if _, e = sourceURL(source, g); e != nil {
			w.mu.Unlock()
			out.Reason = reason("source_unavailable")
			return out
		}
		absolute, _ := instant(g.SessionExpires)
		ice, e = instant(turn.ExpiresAt)
		if e != nil || ice.After(absolute) || !ice.After(w.now()) {
			w.mu.Unlock()
			out.Reason = reason("transport_unavailable")
			return out
		}
	}
	expiry, _ := instant(g.Expires)
	existing := w.sessions[g.Session]
	if existing != nil {
		if g.Generation < existing.grant.Generation {
			w.mu.Unlock()
			out.Reason = reason("authority_lost")
			return out
		}
		if g.Generation == existing.grant.Generation {
			if g != existing.grant {
				w.mu.Unlock()
				return out
			}
			out = existing.reply
			out.Request = m.Request
			w.mu.Unlock()
			return out
		}
		old := existing.grant
		old.Generation = g.Generation
		old.Issued = g.Issued
		old.Expires = g.Expires
		if g != old || existing.terminal || !w.now().Before(existing.deadline) || (m.Operation != "renew" && m.Operation != "open") {
			w.mu.Unlock()
			out.Reason = reason("authority_lost")
			return out
		}
		expires, _ := instant(g.Expires)
		existing.grant = g
		existing.deadline = w.now().Add(expires.Sub(w.now()))
		if existing.iceDeadline.Before(existing.deadline) {
			existing.deadline = existing.iceDeadline
		}
		existing.reply.Generation = g.Generation
		out = existing.reply
		out.Request = m.Request
		w.mu.Unlock()
		return out
	}
	if m.Operation != "open" || len(w.sessions) >= 256 {
		w.mu.Unlock()
		out.Reason = reason("capacity_unavailable")
		return out
	}
	active := 0
	cameras := map[int64]bool{}
	for _, s := range w.sessions {
		if !s.terminal {
			active++
			cameras[s.grant.Camera] = true
		}
	}
	cameras[g.Camera] = true
	if active >= 4 || len(cameras) > 2 {
		w.mu.Unlock()
		out.Reason = reason("capacity_unavailable")
		return out
	}
	deadline := w.now().Add(expiry.Sub(w.now()))
	if ice.Before(expiry) {
		deadline = w.now().Add(ice.Sub(w.now()))
	}
	run, cancel := context.WithCancel(ctx)
	s := &session{grant: g, reply: out, cancel: cancel, deadline: deadline, iceDeadline: w.now().Add(ice.Sub(w.now()))}
	s.reply.Status = "negotiating"
	s.reply.Reason = nil
	w.sessions[g.Session] = s
	w.mu.Unlock()
	go w.watch(run, s)
	media, answer, code := w.factory(run, g, source, turn, m.Offer)
	w.mu.Lock()
	defer w.mu.Unlock()
	if s.terminal || !w.now().Before(s.deadline) {
		if media != nil {
			_ = media.Close()
		}
		w.closeLocked(s, "expired", "lease_expired")
		return s.reply
	}
	if code != "" {
		if media != nil {
			_ = media.Close()
		}
		w.closeLocked(s, "failed", code)
		return s.reply
	}
	s.media = media
	s.reply.Status = "ready"
	s.reply.Answer = &answer
	return s.reply
}
func (w *Worker) watch(ctx context.Context, s *session) {
	timer := time.NewTicker(50 * time.Millisecond)
	defer timer.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
			w.mu.Lock()
			expires, _ := instant(s.grant.Expires)
			absolute, _ := instant(s.grant.SessionExpires)
			if !w.now().Before(s.deadline) || !w.now().Before(expires) || !w.now().Before(absolute) {
				w.closeLocked(s, "expired", "lease_expired")
				v := s.reply
				emit := w.emit
				w.mu.Unlock()
				if emit != nil {
					emit(v)
				}
				return
			}
			w.mu.Unlock()
		}
	}
}
func Serve(parent context.Context, input io.Reader, output io.Writer, factory Factory) error {
	ctx, cancel := context.WithCancel(parent)
	defer cancel()
	w := New(factory)
	defer w.Close()
	var write sync.Mutex
	emit := func(v any) {
		write.Lock()
		defer write.Unlock()
		if WriteFrame(output, v) != nil {
			cancel()
		}
	}
	w.emit = func(v Reply) { emit(v) }
	type frame struct {
		b []byte
		e error
	}
	frames := make(chan frame)
	go func() {
		for {
			b, e := ReadFrame(input)
			if e != nil {
				cancel()
			}
			select {
			case frames <- frame{b, e}:
			case <-ctx.Done():
				return
			}
			if e != nil {
				return
			}
		}
	}()
	for {
		select {
		case <-ctx.Done():
			return nil
		case f := <-frames:
			if f.e == io.EOF {
				return nil
			}
			if f.e != nil {
				return ErrProtocol
			}
			var v any
			message, messageErr := decodeMessage(f.b)
			if messageErr == nil && message.Operation == hikvisionRecorderDiscoveryOperation {
				v = w.DiscoverHikvisionRecorder(ctx, f.b)
			} else if messageErr == nil && message.Operation == hikvisionDiscoveryOperation {
				v = w.DiscoverHikvision(ctx, f.b)
			} else {
				v = w.Handle(ctx, f.b)
			}
			clear(f.b)
			if ctx.Err() != nil {
				return nil
			}
			emit(v)
		}
	}
}
