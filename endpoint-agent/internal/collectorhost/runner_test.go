package collectorhost

import (
	"context"
	"errors"
	"reflect"
	"strings"
	"sync"
	"testing"
	"time"
)

type fakeCycle struct {
	check func(context.Context) error
	cycle func(context.Context) (uint32, error)
}

func (f fakeCycle) Check(c context.Context) error           { return f.check(c) }
func (f fakeCycle) Cycle(c context.Context) (uint32, error) { return f.cycle(c) }

var testTiming = timings{50 * time.Millisecond, 30 * time.Millisecond, 5 * time.Millisecond, 20 * time.Millisecond}

func TestCycleStartupFailureDoesNotReportReadyOrRun(t *testing.T) {
	ready, called := false, false
	err := runCycles(context.Background(), fakeCycle{check: func(context.Context) error { return errors.New("secret") }, cycle: func(context.Context) (uint32, error) { called = true; return 0, nil }}, func() { ready = true }, func(string) {}, testTiming)
	if err == nil || ready || called || strings.Contains(err.Error(), "secret") {
		t.Fatal("invalid startup accepted", err)
	}
}
func TestCyclesAreSequentialAndRecoverFromDeferredUpload(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var messages []string
	codes := []uint32{2, 2, 0}
	calls := 0
	active := false
	ready := false
	fake := fakeCycle{check: func(context.Context) error { return nil }, cycle: func(c context.Context) (uint32, error) {
		if !ready || active {
			t.Fatal("overlap or premature cycle")
		}
		active = true
		defer func() { active = false }()
		if _, ok := c.Deadline(); !ok {
			t.Fatal("no cycle deadline")
		}
		code := codes[calls]
		calls++
		return code, nil
	}}
	err := runCycles(ctx, fake, func() { ready = true }, func(s string) {
		messages = append(messages, s)
		if strings.Contains(s, "cycle completed") {
			cancel()
		}
	}, testTiming)
	if !errors.Is(err, context.Canceled) || calls != 3 || len(messages) != 3 || !strings.Contains(messages[1], "deferred") || !strings.Contains(messages[2], "completed") {
		t.Fatal(err, calls, messages)
	}
}
func TestCycleTimeoutRetainsRetryAndCancellationStopsWork(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var once sync.Once
	timedOut := false
	calls := 0
	fake := fakeCycle{check: func(context.Context) error { return nil }, cycle: func(c context.Context) (uint32, error) {
		calls++
		<-c.Done()
		if calls == 1 {
			timedOut = errors.Is(c.Err(), context.DeadlineExceeded)
			once.Do(func() { time.AfterFunc(8*time.Millisecond, cancel) })
		}
		return 0, c.Err()
	}}
	err := runCycles(ctx, fake, func() {}, func(string) {}, testTiming)
	if !timedOut || !errors.Is(err, context.Canceled) || calls < 1 || calls > 2 {
		t.Fatal(err, calls, timedOut)
	}
}
func TestUnsafeInstallationStopsInsteadOfRetrying(t *testing.T) {
	calls := 0
	err := runCycles(context.Background(), fakeCycle{check: func(context.Context) error { return nil }, cycle: func(context.Context) (uint32, error) { calls++; return 0, errInstallation }}, func() {}, func(string) {}, testTiming)
	if !errors.Is(err, errInstallation) || calls != 1 {
		t.Fatal(err, calls)
	}
}
func TestInvalidServiceTimingIsRejected(t *testing.T) {
	invalid := testTiming
	invalid.maximumBackoff = time.Nanosecond
	if reflect.DeepEqual(invalid, testTiming) {
		t.Fatal("fixture")
	}
	if runCycles(context.Background(), fakeCycle{}, func() {}, func(string) {}, invalid) == nil {
		t.Fatal("invalid timing accepted")
	}
}
