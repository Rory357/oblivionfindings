package servicehost

import (
	"context"
	"errors"
	"golang.org/x/sys/windows/svc"
	"testing"
	"time"
)

type serviceResult struct {
	specific bool
	code     uint32
}

func startTestHandler(t *testing.T, worker func(context.Context, func()) error) (chan svc.ChangeRequest, chan svc.Status, chan serviceResult) {
	t.Helper()
	handler := &serviceHandler{worker: worker, startTimeout: time.Second, stopTimeout: 40 * time.Millisecond}
	requests := make(chan svc.ChangeRequest, 4)
	statuses := make(chan svc.Status, 20)
	done := make(chan serviceResult, 1)
	go func() {
		specific, code := handler.Execute(nil, requests, statuses)
		done <- serviceResult{specific, code}
	}()
	return requests, statuses, done
}
func nextStatus(t *testing.T, statuses <-chan svc.Status, state svc.State) svc.Status {
	t.Helper()
	select {
	case s := <-statuses:
		if s.State != state {
			t.Fatalf("state %d, want %d", s.State, state)
		}
		return s
	case <-time.After(2 * time.Second):
		t.Fatal("service status timeout")
		return svc.Status{}
	}
}
func result(t *testing.T, done <-chan serviceResult) serviceResult {
	t.Helper()
	select {
	case r := <-done:
		return r
	case <-time.After(2 * time.Second):
		t.Fatal("service did not terminate")
		return serviceResult{}
	}
}
func TestServiceSignalsReadinessAndCancelsForStopOrShutdown(t *testing.T) {
	for _, command := range []svc.Cmd{svc.Stop, svc.Shutdown} {
		requests, statuses, done := startTestHandler(t, func(ctx context.Context, ready func()) error { ready(); <-ctx.Done(); return ctx.Err() })
		nextStatus(t, statuses, svc.StartPending)
		running := nextStatus(t, statuses, svc.Running)
		if running.Accepts != svc.AcceptStop|svc.AcceptShutdown {
			t.Fatal("service did not advertise shutdown support")
		}
		requests <- svc.ChangeRequest{Cmd: svc.Interrogate}
		nextStatus(t, statuses, svc.Running)
		requests <- svc.ChangeRequest{Cmd: command}
		nextStatus(t, statuses, svc.StopPending)
		r := result(t, done)
		if r.specific || r.code != 0 {
			t.Fatalf("clean cancellation failed: %+v", r)
		}
	}
}
func TestServiceDoesNotReportReadyWhenInitializationFails(t *testing.T) {
	_, statuses, done := startTestHandler(t, func(context.Context, func()) error { return errors.New("invalid protected state") })
	nextStatus(t, statuses, svc.StartPending)
	r := result(t, done)
	if !r.specific || r.code == 0 {
		t.Fatal("initialization failure appeared successful")
	}
	select {
	case s := <-statuses:
		t.Fatalf("unexpected ready state: %+v", s)
	default:
	}
}
func TestServiceBoundsAnUnresponsiveWorkerDuringStop(t *testing.T) {
	release := make(chan struct{})
	defer close(release)
	requests, statuses, done := startTestHandler(t, func(_ context.Context, ready func()) error { ready(); <-release; return nil })
	nextStatus(t, statuses, svc.StartPending)
	nextStatus(t, statuses, svc.Running)
	requests <- svc.ChangeRequest{Cmd: svc.Stop}
	nextStatus(t, statuses, svc.StopPending)
	r := result(t, done)
	if !r.specific || r.code == 0 {
		t.Fatal("unresponsive worker falsely stopped successfully")
	}
}
func TestServiceFailsWhenWorkerExitsWithoutStop(t *testing.T) {
	release := make(chan struct{})
	_, statuses, done := startTestHandler(t, func(_ context.Context, ready func()) error { ready(); <-release; return nil })
	nextStatus(t, statuses, svc.StartPending)
	nextStatus(t, statuses, svc.Running)
	close(release)
	r := result(t, done)
	if !r.specific || r.code == 0 {
		t.Fatal("unexpected worker exit appeared healthy")
	}
}
func TestServiceCannotBeLaunchedAsAnInteractiveProcess(t *testing.T) {
	if err := Run("test"); err == nil {
		t.Fatal("interactive service host was accepted")
	}
}

func TestServiceCancelsStartupThatNeverBecomesReady(t *testing.T) {
	var events []string
	failures := 0
	handler := &serviceHandler{
		reportState:   func(state string) { events = append(events, state) },
		reportFailure: func() { failures++ },
		worker:        func(ctx context.Context, _ func()) error { <-ctx.Done(); return ctx.Err() },
		startTimeout:  20 * time.Millisecond, stopTimeout: time.Second,
	}
	requests := make(chan svc.ChangeRequest)
	statuses := make(chan svc.Status, 10)
	done := make(chan serviceResult, 1)
	go func() {
		specific, code := handler.Execute(nil, requests, statuses)
		done <- serviceResult{specific, code}
	}()
	nextStatus(t, statuses, svc.StartPending)
	nextStatus(t, statuses, svc.StopPending)
	r := result(t, done)
	if !r.specific || r.code == 0 || failures != 1 || len(events) != 0 {
		t.Fatal("startup timeout appeared successful or logged a requested stop", r, failures, events)
	}
}

func TestLifecycleReportsOnlyConfirmedLocalReadinessAndRequestedStop(t *testing.T) {
	var events []string
	h := &serviceHandler{worker: func(ctx context.Context, ready func()) error { ready(); <-ctx.Done(); return ctx.Err() }, stopTimeout: time.Second, startTimeout: time.Second, reportState: func(state string) { events = append(events, state) }}
	requests := make(chan svc.ChangeRequest, 1)
	statuses := make(chan svc.Status, 8)
	done := make(chan serviceResult, 1)
	go func() { specific, code := h.Execute(nil, requests, statuses); done <- serviceResult{specific, code} }()
	nextStatus(t, statuses, svc.StartPending)
	nextStatus(t, statuses, svc.Running)
	requests <- svc.ChangeRequest{Cmd: svc.Stop}
	nextStatus(t, statuses, svc.StopPending)
	if r := result(t, done); r.code != 0 {
		t.Fatal(r)
	}
	if len(events) != 2 || events[0] != "ready" || events[1] != "stopped" {
		t.Fatal("lifecycle logging was misleading", events)
	}
}
func TestFailedWorkerNeverReportsLocalReadinessOrCleanStop(t *testing.T) {
	var events []string
	failed := 0
	h := &serviceHandler{worker: func(context.Context, func()) error { return errors.New("private upstream details") }, stopTimeout: time.Second, startTimeout: time.Second, reportState: func(state string) { events = append(events, state) }, reportFailure: func() { failed++ }}
	requests := make(chan svc.ChangeRequest, 1)
	statuses := make(chan svc.Status, 8)
	done := make(chan serviceResult, 1)
	go func() { specific, code := h.Execute(nil, requests, statuses); done <- serviceResult{specific, code} }()
	nextStatus(t, statuses, svc.StartPending)
	if r := result(t, done); r.code == 0 || failed != 1 || len(events) != 0 {
		t.Fatal("failed worker reported healthy lifecycle", r, events, failed)
	}
}
func TestServiceActionSessionChangeInvalidatesConsent(t *testing.T) {
	requests := make(chan svc.ChangeRequest, 2)
	statuses := make(chan svc.Status, 16)
	done := make(chan struct{})
	changes := 0
	handler := &serviceHandler{worker: func(ctx context.Context, ready func()) error { ready(); <-ctx.Done(); return nil }, sessionChange: func() { changes++ }, startTimeout: time.Second, stopTimeout: time.Second}
	go func() { handler.Execute(nil, requests, statuses); close(done) }()
	for {
		status := <-statuses
		if status.State == svc.Running {
			if status.Accepts&svc.AcceptSessionChange == 0 {
				t.Fatal("SCM session withdrawal notification missing")
			}
			break
		}
	}
	requests <- svc.ChangeRequest{Cmd: svc.SessionChange}
	requests <- svc.ChangeRequest{Cmd: svc.Stop}
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("service stop did not finish")
	}
	if changes != 1 {
		t.Fatal("Windows session event did not invalidate action consent")
	}
}
