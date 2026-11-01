package collectorhost

import (
	"context"
	"errors"
	"reflect"
	"strings"
	"sync"
	"testing"
	"time"

	"oblivion.local/endpoint-agent/internal/hubupdate"
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
	err := runCycles(context.Background(), fakeCycle{check: func(context.Context) error { return errors.New("secret") }, cycle: func(context.Context) (uint32, error) { called = true; return 0, nil }}, func() { ready = true }, func(string) {}, testTiming, nil)
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
	}, testTiming, nil)
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
	err := runCycles(ctx, fake, func() {}, func(string) {}, testTiming, nil)
	if !timedOut || !errors.Is(err, context.Canceled) || calls < 1 || calls > 2 {
		t.Fatal(err, calls, timedOut)
	}
}
func TestUnsafeInstallationStopsInsteadOfRetrying(t *testing.T) {
	calls := 0
	err := runCycles(context.Background(), fakeCycle{check: func(context.Context) error { return nil }, cycle: func(context.Context) (uint32, error) { calls++; return 0, errInstallation }}, func() {}, func(string) {}, testTiming, nil)
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
	if runCycles(context.Background(), fakeCycle{}, func() {}, func(string) {}, invalid, nil) == nil {
		t.Fatal("invalid timing accepted")
	}
}

type fakeUpdates struct {
	codes         []uint32
	action        hubupdate.Action
	startupFailed int
}

func (f *fakeUpdates) AfterCycle(_ context.Context, code uint32, _ error) hubupdate.Action {
	f.codes = append(f.codes, code)
	return f.action
}
func (f *fakeUpdates) StartupFailed(context.Context) hubupdate.Action {
	f.startupFailed++
	return hubupdate.Restart
}

func TestUpdateHookRunsAfterEveryCycleAndOnStartupFailure(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	updates := &fakeUpdates{action: hubupdate.Continue}
	codes := []uint32{2, 1, 0}
	calls := 0
	fake := fakeCycle{check: func(context.Context) error { return nil }, cycle: func(context.Context) (uint32, error) {
		code := codes[calls]
		calls++
		if calls == len(codes) {
			defer cancel()
		}
		return code, nil
	}}
	if err := runCycles(ctx, fake, func() {}, func(string) {}, testTiming, updates); !errors.Is(err, context.Canceled) {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(updates.codes, []uint32{2, 1}) && !reflect.DeepEqual(updates.codes, []uint32{2, 1, 0}) {
		t.Fatal(updates.codes)
	}
	failing := &fakeUpdates{}
	err := runCycles(context.Background(), fakeCycle{check: func(context.Context) error { return errors.New("broken") }, cycle: nil}, func() {}, func(string) {}, testTiming, failing)
	if err == nil || failing.startupFailed != 1 || len(failing.codes) != 0 {
		t.Fatal(err, failing)
	}
}

func TestReleaseOnTrialIsCheckedWithoutBackingOff(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	timing := timings{50 * time.Millisecond, 30 * time.Millisecond, 5 * time.Millisecond, 400 * time.Millisecond}
	updates := &fakeUpdates{action: hubupdate.Wait}
	var started []time.Time
	fake := fakeCycle{check: func(context.Context) error { return nil }, cycle: func(context.Context) (uint32, error) {
		started = append(started, time.Now())
		if len(started) == 7 {
			cancel()
		}
		return 1, nil // failing cycles would normally back off: 5, 10, 20, 40, 80, 160 ms
	}}
	_ = runCycles(ctx, fake, func() {}, func(string) {}, timing, updates)
	if len(started) != 7 || started[6].Sub(started[0]) > 200*time.Millisecond {
		t.Fatal("backed off during a trial", len(started), started[len(started)-1].Sub(started[0]))
	}
}
