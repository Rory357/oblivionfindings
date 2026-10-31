package agent

import (
	"bytes"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"math/rand"
	"os"
	"strings"
	"sync"
	"testing"
	"unicode/utf8"
)

func outputFixture(t *testing.T, limit int) (*scriptOutput, *scriptOutputWriter, *scriptOutputWriter) {
	t.Helper()
	o, err := newScriptOutput(limit)
	if err != nil {
		t.Fatal(err)
	}
	a, _ := o.writer(0)
	b, _ := o.writer(1)
	return o, a, b
}

func TestScriptOutputNormalizationAndBudget(t *testing.T) {
	cases := []struct {
		name      string
		limit     int
		input     []byte
		want      string
		truncated bool
	}{
		{"empty_zero", 0, nil, "", false},
		{"discard_zero", 0, []byte("private"), "", true},
		{"exact_limit", 3, []byte("abc"), "abc", false},
		{"overflow", 3, []byte("abcd"), "abc", true},
		{"scalar_boundary", 3, []byte("a😀z"), "a", true},
		{"unicode", 64, []byte("Māori 😀"), "Māori 😀", false},
		{"invalid_sequence", 64, []byte{0xe2, 'x', 0xff}, "�x�", false},
		{"unfinished", 64, []byte{0xf0, 0x9f}, "��", false},
		{"replacement_budget", 2, []byte{0xff}, "", true},
		{"control_and_nul", 64, []byte("a\x00\r\n\tb"), "a\x00\r\n\tb", false},
	}
	var exports []map[string]any
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			// Every split position must normalize identically, including inside
			// multibyte and invalid sequences.
			for split := 0; split <= len(tc.input); split++ {
				o, a, b := outputFixture(t, tc.limit)
				for _, p := range [][]byte{tc.input[:split], tc.input[split:]} {
					n, err := a.Write(p)
					if n != len(p) || err != nil {
						t.Fatal("output must continue draining", n, err)
					}
				}
				if err := a.finish(nil); err != nil {
					t.Fatal(err)
				}
				if err := b.finish(io.EOF); err != nil {
					t.Fatal(err)
				}
				s, err := o.seal()
				if err != nil || string(s.data) != tc.want || s.truncated != tc.truncated || !utf8.Valid(s.data) || len(s.data) > tc.limit {
					t.Fatalf("split %d: unexpected bounded output", split)
				}
				if len(s.data) == 0 {
					if s.sha256 != nil {
						t.Fatal("empty output has no digest")
					}
				} else {
					digest := sha256.Sum256(s.data)
					if s.sha256 == nil || *s.sha256 != hex.EncodeToString(digest[:]) {
						t.Fatal("digest must bind exact retained bytes")
					}
				}
				if split == 0 {
					exports = append(exports, map[string]any{"name": tc.name, "limit": tc.limit,
						"body_base64": base64.StdEncoding.EncodeToString(s.data), "sha256": s.sha256,
						"size_bytes": len(s.data), "truncated": s.truncated})
				}
			}
		})
	}
	if path := os.Getenv("OBLI_SCRIPT_OUTPUT_EXPORT"); path != "" {
		raw, err := json.MarshalIndent(exports, "", "  ")
		if err != nil || os.WriteFile(path, raw, 0600) != nil {
			t.Fatal("could not preserve native output fixture")
		}
	}
}

func TestScriptOutputKeepsStreamDecodersIndependent(t *testing.T) {
	o, a, b := outputFixture(t, 64)
	_, _ = a.Write([]byte{0xf0, 0x9f})
	_, _ = b.Write([]byte("error"))
	_, _ = a.Write([]byte{0x98, 0x80})
	_ = a.finish(nil)
	_ = b.finish(nil)
	s, err := o.seal()
	if err != nil || string(s.data) != "error😀" || s.truncated {
		t.Fatal("partial stdout scalar was mixed with stderr")
	}
	// Mutating a caller's snapshot cannot corrupt retained evidence.
	s.data[0] = 'X'
	*s.sha256 = "changed"
	again, err := o.seal()
	if err != nil || string(again.data) != "error😀" || *again.sha256 == "changed" {
		t.Fatal("snapshot is not isolated")
	}
	if n, err := a.Write([]byte("after")); n != 0 || !errors.Is(err, errScriptOutputClosed) {
		t.Fatal("sealed output accepted a write")
	}
}

func TestScriptOutputDrainsConcurrentPipesAfterBudget(t *testing.T) {
	o, a, b := outputFixture(t, 4096)
	var wg sync.WaitGroup
	for i, w := range []*scriptOutputWriter{a, b} {
		r, pipe := io.Pipe()
		wg.Add(2)
		go func(w *scriptOutputWriter) {
			defer wg.Done()
			n, err := io.CopyBuffer(w, r, make([]byte, 4096))
			_ = r.Close()
			if n != 2*1024*1024 || err != nil {
				t.Error("pipe did not fully drain")
			}
			if err := w.finish(err); err != nil {
				t.Error(err)
			}
		}(w)
		go func(value byte) {
			defer wg.Done()
			block := bytes.Repeat([]byte{value}, 4096)
			for j := 0; j < 512; j++ {
				if n, err := pipe.Write(block); n != len(block) || err != nil {
					t.Error("producer blocked or failed after limit")
					break
				}
			}
			_ = pipe.Close()
		}(byte('a' + i))
	}
	wg.Wait()
	s, err := o.seal()
	if err != nil || len(s.data) != 4096 || !s.truncated || cap(o.data) != 4096 {
		t.Fatal("combined output budget was not enforced")
	}
}

func TestScriptOutputIncompleteAndReadFailure(t *testing.T) {
	o, a, b := outputFixture(t, 64)
	if _, err := o.seal(); !errors.Is(err, errScriptOutputIncomplete) {
		t.Fatal("unfinished readers accepted")
	}
	_, _ = a.Write([]byte("partial"))
	_ = a.finish(errors.New("private detail must not escape"))
	if _, err := o.seal(); !errors.Is(err, errScriptOutputIncomplete) {
		t.Fatal("second reader still open")
	}
	_ = b.finish(nil)
	s, err := o.seal()
	if !errors.Is(err, errScriptOutputIncomplete) || strings.Contains(err.Error(), "private") || string(s.data) != "partial" {
		t.Fatal("failed reader was hidden or disclosed its error")
	}
	if err := b.finish(nil); !errors.Is(err, errScriptOutputClosed) {
		t.Fatal("reader completion must be one-shot")
	}
}

func TestScriptOutputRejectsInvalidLimitsAndStreams(t *testing.T) {
	for _, limit := range []int{-1, 65537} {
		if _, err := newScriptOutput(limit); err == nil {
			t.Fatal("invalid limit accepted")
		}
	}
	o, _, _ := outputFixture(t, 65536)
	for _, stream := range []int{-1, 2} {
		if _, err := o.writer(stream); err == nil {
			t.Fatal("invalid stream accepted")
		}
	}
}

func TestScriptOutputArbitraryByteChunks(t *testing.T) {
	random := rand.New(rand.NewSource(20261020))
	raw := make([]byte, 2048)
	_, _ = random.Read(raw)
	raw = append(raw, []byte(" Māori 😀")...)
	var normalized []byte
	for remaining := raw; len(remaining) > 0; {
		r, size := utf8.DecodeRune(remaining)
		normalized = utf8.AppendRune(normalized, r)
		remaining = remaining[size:]
	}
	for _, limit := range []int{0, 1, 2, 3, 4, 4096, 65536} {
		expected := normalized
		if len(expected) > limit {
			expected = expected[:limit]
			for !utf8.Valid(expected) {
				expected = expected[:len(expected)-1]
			}
		}
		for chunk := 1; chunk <= 17; chunk++ {
			o, a, b := outputFixture(t, limit)
			for start := 0; start < len(raw); start += chunk {
				end := min(start+chunk, len(raw))
				if n, err := a.Write(raw[start:end]); n != end-start || err != nil {
					t.Fatal("chunk was not drained")
				}
			}
			_ = a.finish(nil)
			_ = b.finish(nil)
			s, err := o.seal()
			if err != nil || !bytes.Equal(s.data, expected) || s.truncated != (len(normalized) > len(expected)) {
				t.Fatalf("normalization differs at limit %d, chunk size %d", limit, chunk)
			}
		}
	}
}
