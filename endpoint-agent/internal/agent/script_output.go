package agent

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"sync"
	"unicode/utf8"
)

var (
	errScriptOutputClosed     = errors.New("script output closed")
	errScriptOutputIncomplete = errors.New("script output incomplete")
)

// scriptOutput captures a bounded UTF-8 transcript in observed reader order.
// Streams have separate UTF-8 decoders: a partial stdout rune cannot consume a
// stderr byte. This does not claim ordering between the child process's streams.
// It has no execution, persistence, logging, upload or authority side effects.
type scriptOutput struct {
	mu         sync.Mutex
	limit      int
	data       []byte
	streams    [2]scriptOutputStream
	truncated  bool
	sealed     bool
	readFailed bool
}

type scriptOutputStream struct {
	pending [utf8.UTFMax]byte
	count   int
	done    bool
}

type scriptOutputWriter struct {
	owner  *scriptOutput
	stream int
}

// Private bytes must only enter the separately authorised output store.
// Empty output has no digest, matching the result contract.
type scriptOutputSnapshot struct {
	data      []byte
	sha256    *string
	truncated bool
}

func newScriptOutput(limit int) (*scriptOutput, error) {
	if limit < 0 || limit > 65536 {
		return nil, errScriptContract
	}
	return &scriptOutput{limit: limit, data: make([]byte, 0, limit)}, nil
}

func (o *scriptOutput) writer(stream int) (*scriptOutputWriter, error) {
	if stream < 0 || stream >= len(o.streams) {
		return nil, errScriptContract
	}
	return &scriptOutputWriter{owner: o, stream: stream}, nil
}

// Write always consumes the supplied bytes while open, including after the
// retention budget is exhausted. A byte limit must not stop pipe draining.
func (w *scriptOutputWriter) Write(p []byte) (int, error) {
	o := w.owner
	o.mu.Lock()
	defer o.mu.Unlock()
	s := &o.streams[w.stream]
	if o.sealed || s.done {
		return 0, errScriptOutputClosed
	}
	n := len(p)
	for len(p) > 0 {
		if o.truncated {
			s.count = 0
			break
		}
		if s.count > 0 || !utf8.FullRune(p) {
			s.pending[s.count] = p[0]
			s.count++
			p = p[1:]
			if !utf8.FullRune(s.pending[:s.count]) {
				continue
			}
			r, size := utf8.DecodeRune(s.pending[:s.count])
			o.retain(r)
			copy(s.pending[:], s.pending[size:s.count])
			s.count -= size
			// Invalid sequences can leave a complete second rune in pending.
			for s.count > 0 && utf8.FullRune(s.pending[:s.count]) {
				r, size = utf8.DecodeRune(s.pending[:s.count])
				o.retain(r)
				copy(s.pending[:], s.pending[size:s.count])
				s.count -= size
			}
			continue
		}
		r, size := utf8.DecodeRune(p)
		o.retain(r)
		p = p[size:]
	}
	return n, nil
}

// Invalid UTF-8 bytes become one U+FFFD per invalid byte. The byte budget
// applies after this normalization; never retain a partial encoded scalar.
func (o *scriptOutput) retain(r rune) {
	if o.truncated {
		return
	}
	size := utf8.RuneLen(r)
	if size > o.limit-len(o.data) {
		o.truncated = true
		return
	}
	o.data = utf8.AppendRune(o.data, r)
}

// finish is called with the actual reader outcome, not with child exit status.
// A broken reader must not be represented as complete output.
func (w *scriptOutputWriter) finish(readErr error) error {
	o := w.owner
	o.mu.Lock()
	defer o.mu.Unlock()
	s := &o.streams[w.stream]
	if o.sealed || s.done {
		return errScriptOutputClosed
	}
	if readErr != nil && !errors.Is(readErr, io.EOF) {
		o.readFailed = true
	}
	for s.count > 0 {
		r, size := utf8.DecodeRune(s.pending[:s.count])
		o.retain(r)
		copy(s.pending[:], s.pending[size:s.count])
		s.count -= size
	}
	s.done = true
	return nil
}

// seal requires both readers to finish. A returned read error accompanies only
// partial evidence; it cannot support a completed execution/result decision.
// Each snapshot owns its bytes and cannot modify the retained evidence.
func (o *scriptOutput) seal() (scriptOutputSnapshot, error) {
	o.mu.Lock()
	defer o.mu.Unlock()
	if !o.streams[0].done || !o.streams[1].done {
		return scriptOutputSnapshot{}, errScriptOutputIncomplete
	}
	o.sealed = true
	snapshot := scriptOutputSnapshot{data: append([]byte(nil), o.data...), truncated: o.truncated}
	if len(snapshot.data) > 0 {
		sum := sha256.Sum256(snapshot.data)
		digest := hex.EncodeToString(sum[:])
		snapshot.sha256 = &digest
	}
	if o.readFailed {
		return snapshot, errScriptOutputIncomplete
	}
	return snapshot, nil
}
