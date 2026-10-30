package setup

import (
	"context"
	"errors"
	"reflect"
	"strings"
	"testing"
	"time"
)

type fakeHost struct {
	calls                  []string
	receipts               []Journal
	failStep               string
	failPersistAt          int
	persistCount           int
	rollbackFails          bool
	cleanupContextValid    bool
	cancelAt               string
	cancel                 context.CancelFunc
	identity               string
	running, service, tray bool
}

func (h *fakeHost) step(ctx context.Context, name string) error {
	h.calls = append(h.calls, name)
	if name == h.cancelAt && h.cancel != nil {
		h.cancel()
	}
	if name == h.failStep {
		return errors.New("simulated secret from upstream must not be disclosed")
	}
	return nil
}
func (h *fakeHost) Persist(j *Journal) error {
	h.persistCount++
	if h.persistCount == h.failPersistAt {
		return errors.New("disk unavailable")
	}
	h.receipts = append(h.receipts, *j)
	return nil
}
func (h *fakeHost) Stage(ctx context.Context, j *Journal) error { return h.step(ctx, "staging") }
func (h *fakeHost) Enrol(ctx context.Context, j *Journal) error {
	if err := h.step(ctx, "enrolment"); err != nil {
		return err
	}
	if h.identity == "" {
		h.identity = j.AgentUUID
	}
	if h.identity != j.AgentUUID {
		return errors.New("identity changed")
	}
	return nil
}
func (h *fakeHost) RegisterService(ctx context.Context, j *Journal) error {
	if h.identity == "" {
		return errors.New("service attempted before enrolment")
	}
	h.service = true
	return h.step(ctx, "service_registration")
}
func (h *fakeHost) RegisterTray(ctx context.Context, j *Journal) error {
	h.tray = true
	return h.step(ctx, "tray_registration")
}
func (h *fakeHost) StartService(ctx context.Context, j *Journal) error {
	h.running = true
	return h.step(ctx, "service_start")
}
func (h *fakeHost) Rollback(ctx context.Context, j *Journal) error {
	h.calls = append(h.calls, "rollback")
	deadline, bounded := ctx.Deadline()
	h.cleanupContextValid = ctx.Err() == nil && bounded && time.Until(deadline) > 0 && time.Until(deadline) <= 30*time.Second
	if h.rollbackFails {
		return errors.New("cleanup interrupted")
	}
	h.running = false
	h.service = false
	h.tray = false
	return nil
}
func newJournal() Journal {
	return Journal{Schema: 1, Attempt: strings.Repeat("a", 32), Stage: "r-7-" + strings.Repeat("b", 32), Version: "1.2.3", Revision: 7, ManifestSHA256: strings.Repeat("f", 64), Architecture: "amd64", Role: "computer", ServerURL: "https://care.example.test", AgentUUID: "01234567-89ab-4cde-8f01-23456789abcd", Phase: "planned"}
}

func TestInstallCommitsOnlyAfterReady(t *testing.T) {
	j := newJournal()
	h := &fakeHost{}
	if err := Run(context.Background(), &j, h); err != nil {
		t.Fatal(err)
	}
	expected := []string{"staging", "enrolment", "service_registration", "tray_registration", "service_start"}
	if !reflect.DeepEqual(h.calls, expected) || !h.running || j.Phase != "installed" {
		t.Fatal("installation did not activate in order")
	}
	if h.receipts[len(h.receipts)-1].Phase != "installed" {
		t.Fatal("missing final receipt")
	}
	for _, r := range h.receipts[:len(h.receipts)-1] {
		if r.Phase == "installed" {
			t.Fatal("premature installation success")
		}
	}
}
func TestInstallFailureRollsBackAndPreservesBoundIdentity(t *testing.T) {
	for _, step := range []string{"staging", "enrolment", "service_registration", "tray_registration", "service_start"} {
		t.Run(step, func(t *testing.T) {
			j := newJournal()
			before := j
			h := &fakeHost{failStep: step}
			err := Run(context.Background(), &j, h)
			if err == nil || !strings.Contains(err.Error(), step) || strings.Contains(err.Error(), "secret") {
				t.Fatal("failure missing or leaked upstream content", err)
			}
			if h.running || h.service || h.tray || !h.cleanupContextValid || j.RecoveryRequired || j.Phase == "installed" {
				t.Fatal("failed attempt remained active")
			}
			if j.Attempt != before.Attempt || j.AgentUUID != before.AgentUUID || j.ServerURL != before.ServerURL || j.ManifestSHA256 != before.ManifestSHA256 {
				t.Fatal("rollback changed installation binding")
			}
			h.failStep = ""
			if err = Run(context.Background(), &j, h); err != nil {
				t.Fatal("same installation retry failed", err)
			}
			if h.identity != before.AgentUUID || j.Phase != "installed" {
				t.Fatal("retry lost identity")
			}
		})
	}
}
func TestInstallJournalFailuresNeverReportSuccess(t *testing.T) {
	for position := 1; position <= 6; position++ {
		j := newJournal()
		h := &fakeHost{failPersistAt: position}
		if err := Run(context.Background(), &j, h); err == nil {
			t.Fatal("missing receipt reported success", position)
		}
		if j.Phase == "installed" || h.running || h.service || h.tray || j.FailedStep != "journal_write" {
			t.Fatal("activation retained after receipt failure", position)
		}
		if err := Run(context.Background(), &j, h); err != nil {
			t.Fatal("receipt recovery did not retry", position, err)
		}
	}
}
func TestInstallCancellationHasIndependentCleanupDeadline(t *testing.T) {
	for _, step := range []string{"before", "staging", "enrolment", "service_start"} {
		t.Run(step, func(t *testing.T) {
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			j := newJournal()
			h := &fakeHost{cancelAt: step, cancel: cancel}
			if step == "before" {
				cancel()
			}
			if err := Run(ctx, &j, h); err == nil {
				t.Fatal("cancelled install succeeded")
			}
			if !h.cleanupContextValid || h.running || j.Phase == "installed" || j.FailedStep != "interrupted" {
				t.Fatal("cancellation skipped bounded cleanup")
			}
		})
	}
}
func TestInstallRequiresRecoveryWhenRollbackFails(t *testing.T) {
	j := newJournal()
	h := &fakeHost{failStep: "service_start", rollbackFails: true}
	if err := Run(context.Background(), &j, h); err == nil || !j.RecoveryRequired {
		t.Fatal("incomplete rollback not recorded")
	}
	count := len(h.calls)
	if err := Run(context.Background(), &j, h); err == nil || len(h.calls) != count {
		t.Fatal("recovery-needed installation changed on retry")
	}
}
func TestInstallDoesNotReinstallCompletedReceipt(t *testing.T) {
	j := newJournal()
	j.Phase = "installed"
	h := &fakeHost{}
	if err := Run(context.Background(), &j, h); err == nil || len(h.calls) != 0 {
		t.Fatal("completed installation was overwritten")
	}
}
