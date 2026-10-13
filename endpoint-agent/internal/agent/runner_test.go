package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"testing"
	"time"
)

func runnerFixture(t *testing.T) (*Store, State) {
	t.Helper()
	store := newTestStore(t)
	state := State{Version: 1, AgentUUID: testAgentUUID, Credential: strings.Repeat("a", 64), Sequence: 7, PendingHeartbeat: json.RawMessage("{\"sequence\":7}")}
	if err := store.Save(state); err != nil {
		t.Fatal(err)
	}
	return store, state
}
func TestRunnerRetriesWithBoundedBackoffAndCommitsAcknowledgement(t *testing.T) {
	store, state := runnerFixture(t)
	original := append([]byte{}, state.PendingHeartbeat...)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var waits []time.Duration
	var messages []string
	calls := 0
	deps := runnerDependencies{
		prepare: func(_ context.Context, _ *Store, s *State, _ string) ([]byte, error) { return s.PendingHeartbeat, nil },
		send: func(_ context.Context, s State, payload []byte) (Acknowledgement, error) {
			calls++
			if !bytes.Equal(payload, original) || s.Sequence != 7 {
				t.Fatal("retry changed retained report")
			}
			if calls <= 5 {
				return Acknowledgement{}, errors.New("private upstream details must not be logged")
			}
			return Acknowledgement{AgentUUID: s.AgentUUID, Sequence: s.Sequence, NextSeconds: 60}, nil
		},
		wait: func(_ context.Context, delay time.Duration) error {
			waits = append(waits, delay)
			if calls == 6 {
				cancel()
				return ctx.Err()
			}
			return nil
		},
		report: func(s string) { messages = append(messages, s) },
	}
	if err := runLoop(ctx, store, &state, "test", deps); err != nil {
		t.Fatal(err)
	}
	expected := []time.Duration{0, 30 * time.Second, 60 * time.Second, 120 * time.Second, 240 * time.Second, 300 * time.Second, 60 * time.Second}
	if !reflect.DeepEqual(waits, expected) {
		t.Fatalf("retry scheduling: %v", waits)
	}
	persisted, err := store.Load()
	if err != nil || len(persisted.PendingHeartbeat) != 0 || len(state.PendingHeartbeat) != 0 || persisted.Sequence != 7 {
		t.Fatal("acknowledgement was not durably committed")
	}
	if len(messages) != 5 || strings.Contains(strings.Join(messages, " "), "private") {
		t.Fatal("unsafe retry reporting")
	}
}
func TestRunnerStopsOnRejectionWithoutDiscardingPendingReport(t *testing.T) {
	for _, code := range []int{401, 403, 404, 409, 422} {
		t.Run(strconv.Itoa(code), func(t *testing.T) {
			store, state := runnerFixture(t)
			calls := 0
			deps := runnerDependencies{
				prepare: func(_ context.Context, _ *Store, s *State, _ string) ([]byte, error) { return s.PendingHeartbeat, nil },
				send: func(context.Context, State, []byte) (Acknowledgement, error) {
					calls++
					return Acknowledgement{}, &HTTPError{Status: code}
				},
				wait: func(context.Context, time.Duration) error { return nil },
			}
			if err := runLoop(context.Background(), store, &state, "test", deps); !errors.Is(err, ErrReviewRequired) {
				t.Fatalf("expected review for status %d, got %v", code, err)
			}
			saved, _ := store.Load()
			if calls != 1 || len(saved.PendingHeartbeat) == 0 {
				t.Fatal("rejected identity retried or lost retained report")
			}
		})
	}
}
func TestRunnerDoesNotForgetReportAfterBadAckOrFailedCommit(t *testing.T) {
	for _, failSave := range []bool{false, true} {
		t.Run(map[bool]string{false: "wrong acknowledgement", true: "failed state write"}[failSave], func(t *testing.T) {
			store, state := runnerFixture(t)
			realDir := store.dir
			deps := runnerDependencies{
				prepare: func(_ context.Context, _ *Store, s *State, _ string) ([]byte, error) { return s.PendingHeartbeat, nil },
				send: func(_ context.Context, s State, _ []byte) (Acknowledgement, error) {
					ack := Acknowledgement{AgentUUID: s.AgentUUID, Sequence: s.Sequence, NextSeconds: 60}
					if failSave {
						store.dir = filepath.Join(realDir, "missing")
					} else {
						ack.Sequence++
					}
					return ack, nil
				},
				wait: func(context.Context, time.Duration) error { return nil },
			}
			if err := runLoop(context.Background(), store, &state, "test", deps); err == nil {
				t.Fatal("invalid acknowledgement or failed commit accepted")
			}
			store.dir = realDir
			saved, err := store.Load()
			if err != nil || len(saved.PendingHeartbeat) == 0 || len(state.PendingHeartbeat) == 0 {
				t.Fatal("uncommitted acknowledgement discarded report")
			}
		})
	}
}
func TestRunnerCancellationInterruptsWaitAndProbe(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := waitFor(ctx, time.Hour); !errors.Is(err, context.Canceled) {
		t.Fatal("cancelled wait did not stop")
	}
	store, state := runnerFixture(t)
	ctx, cancel = context.WithCancel(context.Background())
	defer cancel()
	deps := runnerDependencies{
		prepare: func(ctx context.Context, _ *Store, _ *State, _ string) ([]byte, error) {
			cancel()
			return nil, ctx.Err()
		},
		send: func(context.Context, State, []byte) (Acknowledgement, error) {
			t.Fatal("sent after cancelled collection")
			return Acknowledgement{}, nil
		},
		wait: func(context.Context, time.Duration) error { return nil },
	}
	if err := runLoop(ctx, store, &state, "test", deps); err != nil {
		t.Fatal(err)
	}
	saved, _ := store.Load()
	if len(saved.PendingHeartbeat) == 0 {
		t.Fatal("cancelled worker discarded pending state")
	}
}
