package hubupdate

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"
)

// fakeHost plays the Windows service or Linux launcher: one shared record, a service that
// points at a release folder, and lists of what happened.
type fakeHost struct {
	running      Running
	instructions Instructions
	state        State
	pointsAt     string
	newStage     string
	now          *time.Time

	fetchErr, installErr, switchErr, handOverErr, reportErr, saveErr error
	switchFailsFor                                                   string

	fetched, discarded, removed []string
	switches                    []string
	handOvers                   []time.Time
	reports                     []Report
	saves                       int
}

func newFakeHost(now *time.Time) *fakeHost {
	h := &fakeHost{now: now, running: runningFixture, pointsAt: runningFixture.Stage, newStage: "r-41-" + strings.Repeat("d", 32), state: State{Schema: 1}}
	h.instructions = instructionsFixture(*now)
	return h
}

func (h *fakeHost) Lock(context.Context) (func(), error) { return func() {}, nil }
func (h *fakeHost) Load() (State, error) {
	data, _ := h.state.Encode()
	return DecodeState(data)
}
func (h *fakeHost) Save(s State) error {
	if h.saveErr != nil {
		return h.saveErr
	}
	h.saves++
	h.state = s
	return nil
}
func (h *fakeHost) Running() Running { return h.running }
func (h *fakeHost) ReadInstructions() ([]byte, error) {
	h.instructions.ReceivedAt = h.now.Add(-time.Minute).Format(time.RFC3339)
	return json.Marshal(h.instructions)
}
func (h *fakeHost) Fetch(_ context.Context, a Artifact) (string, error) {
	h.fetched = append(h.fetched, a.ID)
	return "download-" + a.ID, h.fetchErr
}
func (h *fakeHost) Install(_ context.Context, download string, target Target, _ Artifact, _ Running) (Release, error) {
	*h.now = h.now.Add(3 * time.Minute) // a slow download and unpack
	return Release{Stage: h.newStage, Version: target.Version, Revision: target.ReleaseRevision}, h.installErr
}
func (h *fakeHost) Switch(_ context.Context, stage string) error {
	if h.switchErr != nil || stage == h.switchFailsFor {
		return errors.New("switch failed")
	}
	h.switches = append(h.switches, stage)
	h.pointsAt = stage
	return nil
}
func (h *fakeHost) HandOver(_ context.Context, watchUntil time.Time) error {
	if h.handOverErr != nil {
		return h.handOverErr
	}
	h.handOvers = append(h.handOvers, watchUntil)
	return nil
}
func (h *fakeHost) Report(_ context.Context, r Report) error {
	if h.reportErr != nil {
		return h.reportErr
	}
	h.reports = append(h.reports, r)
	return nil
}
func (h *fakeHost) RemoveStage(stage string) error { h.removed = append(h.removed, stage); return nil }
func (h *fakeHost) Discard(download string)        { h.discarded = append(h.discarded, download) }

// restart: the service starts again from whatever it points at.
func (h *fakeHost) restart(version string) {
	h.running = Running{Stage: h.pointsAt, Version: version, Revision: StageRevision(h.pointsAt)}
	h.instructions.RunningVersion = version
}

func updaterFixture(t *testing.T) (*Updater, *fakeHost, *time.Time) {
	t.Helper()
	now := nightInAuckland
	host := newFakeHost(&now)
	return &Updater{Host: host, Platform: "windows", Arch: "amd64", Now: func() time.Time { return now }}, host, &now
}

func TestUpdateInstallsReportsAndTidiesTheOldReleaseAfterTheSecondGoodCycle(t *testing.T) {
	u, host, now := updaterFixture(t)
	// The old release was installed by this updater too, so its folder may go afterwards.
	host.state.Installed = &Release{Stage: runningFixture.Stage, Version: "1.3.0", Revision: 40}
	if action := u.AfterCycle(context.Background(), 0, nil); action != Restart {
		t.Fatal("expected a restart into the new release", action)
	}
	trial := host.state.Pending
	if trial == nil || trial.To.Stage != host.newStage || trial.From.Stage != runningFixture.Stage || host.pointsAt != host.newStage {
		t.Fatalf("%+v %s", trial, host.pointsAt)
	}
	// The deadline counts from the switch (after the three-minute download), not the decision.
	if !trial.Deadline.Equal(now.Add(5*time.Minute)) || len(host.handOvers) != 1 || !host.handOvers[0].Equal(trial.Deadline) {
		t.Fatalf("deadline %v, hand-overs %v", trial.Deadline, host.handOvers)
	}
	if len(host.discarded) != 1 || host.fetched[0] != "windows-site-package-amd64" {
		t.Fatal(host.discarded, host.fetched)
	}

	host.restart("1.3.1")
	*now = now.Add(90 * time.Second)
	if action := u.AfterCycle(context.Background(), 2, nil); action != Continue {
		t.Fatal(action)
	}
	if host.state.Pending != nil || host.state.Installed.Stage != host.newStage || len(host.reports) != 1 ||
		host.reports[0] != (Report{Outcome: "installed", From: "1.3.0", To: "1.3.1"}) {
		t.Fatalf("%+v %+v", host.state, host.reports)
	}
	// Second good cycle, but the old release's watcher may still be running from it.
	*now = now.Add(time.Minute)
	u.AfterCycle(context.Background(), 0, nil)
	if len(host.removed) != 0 {
		t.Fatal("removed the old release while its watcher could still be running", host.removed)
	}
	*now = now.Add(5 * time.Minute)
	u.AfterCycle(context.Background(), 0, nil)
	if len(host.removed) != 1 || host.removed[0] != runningFixture.Stage || len(host.state.Cleanups) != 0 {
		t.Fatal(host.removed, host.state.Cleanups)
	}
}

func TestUpdateKeepsTheInstallersOwnReleaseFolder(t *testing.T) {
	u, host, now := updaterFixture(t)
	u.AfterCycle(context.Background(), 0, nil)
	host.restart("1.3.1")
	for i := 0; i < 4; i++ {
		*now = now.Add(4 * time.Minute)
		u.AfterCycle(context.Background(), 0, nil)
	}
	if len(host.removed) != 0 {
		t.Fatal("removed a release folder the installer made", host.removed)
	}
}

func TestBrokenReleaseRollsBackAtTheDeadlineAndIsNotRetriedTheSameNight(t *testing.T) {
	u, host, now := updaterFixture(t)
	u.AfterCycle(context.Background(), 0, nil)
	host.restart("1.3.1")
	for i := 0; i < 4; i++ {
		*now = now.Add(time.Minute)
		if action := u.AfterCycle(context.Background(), 1, nil); action != Wait {
			t.Fatal("expected to keep waiting before the deadline", i, action)
		}
	}
	*now = now.Add(90 * time.Second)
	if action := u.AfterCycle(context.Background(), 1, nil); action != Restart {
		t.Fatal("expected a restart into the old release", action)
	}
	if host.pointsAt != runningFixture.Stage || host.state.Pending != nil || host.state.Failure == nil || host.state.Failure.Version != "1.3.1" {
		t.Fatalf("%s %+v", host.pointsAt, host.state)
	}
	if len(host.handOvers) != 2 || !host.handOvers[1].IsZero() {
		t.Fatal("rollback restart should not arm another watcher", host.handOvers)
	}
	// The broken release may not get its report through; the old one sends it.
	host.restart("1.3.0")
	*now = now.Add(time.Minute)
	if action := u.AfterCycle(context.Background(), 0, nil); action != Continue {
		t.Fatal(action)
	}
	if len(host.reports) != 1 || host.reports[0].Outcome != "rolled_back" || host.reports[0].From != "1.3.0" || host.reports[0].To != "1.3.1" || host.reports[0].Detail == "" {
		t.Fatalf("%+v", host.reports)
	}
	if len(host.fetched) != 1 {
		t.Fatal("retried the failed version the same night", host.fetched)
	}
	// Two good cycles later the failed folder goes.
	*now = now.Add(2 * time.Minute)
	u.AfterCycle(context.Background(), 0, nil)
	if len(host.removed) != 1 || host.removed[0] != host.newStage {
		t.Fatal(host.removed)
	}
	// The next night it's tried again.
	*now = now.Add(24 * time.Hour)
	u.AfterCycle(context.Background(), 0, nil)
	if len(host.fetched) != 2 {
		t.Fatal("did not retry the next night", host.fetched)
	}
}

func TestNewReleaseThatCannotStartItsChecksRollsBackAtOnce(t *testing.T) {
	u, host, now := updaterFixture(t)
	u.AfterCycle(context.Background(), 0, nil)
	host.restart("1.3.1")
	*now = now.Add(20 * time.Second)
	if action := u.StartupFailed(context.Background()); action != Restart {
		t.Fatal(action)
	}
	if host.pointsAt != runningFixture.Stage || host.state.Pending != nil || host.state.Reports[0].Outcome != "rolled_back" {
		t.Fatalf("%s %+v", host.pointsAt, host.state)
	}
	// Outside a trial, a startup failure changes nothing.
	host.restart("1.3.0")
	if action := u.StartupFailed(context.Background()); action != Continue || len(host.switches) != 2 {
		t.Fatal(action, host.switches)
	}
}

func TestWatcherRollsBackANewReleaseThatNeverStarted(t *testing.T) {
	u, host, now := updaterFixture(t)
	u.AfterCycle(context.Background(), 0, nil)
	deadline := host.state.Pending.Deadline
	clock := func() time.Time { return *now }

	// The new release never runs. The watcher checks just after the deadline.
	*now = deadline.Add(WatchGrace + time.Second)
	restart, err := Watch(context.Background(), host, clock)
	if err != nil || !restart || host.pointsAt != runningFixture.Stage || host.state.Pending != nil || host.state.Reports[0].Outcome != "rolled_back" {
		t.Fatalf("%v %v %s %+v", restart, err, host.pointsAt, host.state)
	}
	// A second watcher finds nothing to do.
	if restart, err = Watch(context.Background(), host, clock); restart || err != nil {
		t.Fatal(restart, err)
	}
}

func TestWatcherLeavesAReleaseThatCheckedIn(t *testing.T) {
	u, host, now := updaterFixture(t)
	u.AfterCycle(context.Background(), 0, nil)
	deadline := host.state.Pending.Deadline
	host.restart("1.3.1")
	*now = now.Add(time.Minute)
	u.AfterCycle(context.Background(), 0, nil)
	*now = deadline.Add(time.Minute)
	if restart, err := Watch(context.Background(), host, func() time.Time { return *now }); restart || err != nil || host.pointsAt != host.newStage {
		t.Fatal(restart, err, host.pointsAt)
	}
}

func TestWatcherWaitsForTheDeadline(t *testing.T) {
	u, host, _ := updaterFixture(t)
	u.AfterCycle(context.Background(), 0, nil)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()
	// Real clock: the deadline is far away, so the watcher is still waiting when cancelled.
	restart, err := Watch(ctx, host, func() time.Time { return host.state.Pending.Deadline.Add(-time.Hour) })
	if restart || !errors.Is(err, context.DeadlineExceeded) || host.pointsAt != host.newStage {
		t.Fatal(restart, err, host.pointsAt)
	}
}

func TestOldReleaseStillRunningAfterTheDeadlineStaysAndReportsIt(t *testing.T) {
	u, host, now := updaterFixture(t)
	u.AfterCycle(context.Background(), 0, nil)
	// The restart never happened: the old release is still cycling.
	*now = now.Add(time.Minute)
	if action := u.AfterCycle(context.Background(), 0, nil); action != Wait {
		t.Fatal(action)
	}
	*now = now.Add(5 * time.Minute)
	if action := u.AfterCycle(context.Background(), 0, nil); action != Continue {
		t.Fatal(action)
	}
	if host.pointsAt != runningFixture.Stage || host.state.Pending != nil || !strings.Contains(host.state.Reports[0].Detail, "did not start") {
		t.Fatalf("%s %+v", host.pointsAt, host.state)
	}
}

func TestFailuresBeforeTheSwitchReportFailedAndChangeNothing(t *testing.T) {
	cases := map[string]func(*fakeHost){
		"download":  func(h *fakeHost) { h.fetchErr = errors.New("offline") },
		"signature": func(h *fakeHost) { h.installErr = errors.New("bad signature") },
		"no key":    func(h *fakeHost) { h.installErr = ErrNoReleaseKey },
		"switch":    func(h *fakeHost) { h.switchErr = errors.New("access denied") },
		"hand-over": func(h *fakeHost) { h.handOverErr = errors.New("cannot start helper") },
	}
	for name, breakIt := range cases {
		u, host, _ := updaterFixture(t)
		breakIt(host)
		if action := u.AfterCycle(context.Background(), 0, nil); action != Continue {
			t.Errorf("%s: %v", name, action)
		}
		if host.pointsAt != runningFixture.Stage || host.state.Pending != nil || host.state.Failure == nil {
			t.Errorf("%s: %s %+v", name, host.pointsAt, host.state)
		}
		if len(host.reports) != 1 || host.reports[0].Outcome != "failed" || host.reports[0].To != "1.3.1" || host.reports[0].Detail == "" {
			t.Errorf("%s: %+v", name, host.reports)
		}
		if (name == "switch" || name == "hand-over") && (len(host.removed) != 1 || host.removed[0] != host.newStage) {
			t.Errorf("%s: unused release folder kept %v", name, host.removed)
		}
	}
}

func TestHandOverFailureThatCannotSwitchBackLeavesTheTrialToItsDeadline(t *testing.T) {
	u, host, now := updaterFixture(t)
	host.handOverErr = errors.New("cannot start helper")
	host.switchFailsFor = runningFixture.Stage
	if action := u.AfterCycle(context.Background(), 0, nil); action != Wait {
		t.Fatal(action)
	}
	if host.state.Pending == nil || host.pointsAt != host.newStage {
		t.Fatal("trial should stay so its deadline brings the hub back", host.state)
	}
	host.switchFailsFor = ""
	*now = now.Add(6 * time.Minute)
	u.AfterCycle(context.Background(), 0, nil)
	if host.pointsAt != runningFixture.Stage || host.state.Pending != nil {
		t.Fatal(host.pointsAt, host.state)
	}
}

func TestReportsWaitUntilTheyGetThrough(t *testing.T) {
	u, host, now := updaterFixture(t)
	host.fetchErr = errors.New("offline")
	host.reportErr = errors.New("offline")
	u.AfterCycle(context.Background(), 0, nil)
	if len(host.state.Reports) != 1 {
		t.Fatal(host.state.Reports)
	}
	host.reportErr = nil
	*now = now.Add(time.Minute)
	u.AfterCycle(context.Background(), 1, nil) // even after a failed cycle
	if len(host.state.Reports) != 0 || len(host.reports) != 1 {
		t.Fatal(host.state.Reports, host.reports)
	}
}

func TestNoUpdateAfterABadCycleOrFromStaleInstructions(t *testing.T) {
	u, host, _ := updaterFixture(t)
	u.AfterCycle(context.Background(), 1, nil)
	u.AfterCycle(context.Background(), 0, errors.New("timeout"))
	host.instructions.RunningVersion = "1.2.0" // written by another release's heartbeat
	u.AfterCycle(context.Background(), 0, nil)
	if len(host.fetched) != 0 {
		t.Fatal(host.fetched)
	}
}

func TestLegacyInstallReportsThatTheInstallerMustRunOnce(t *testing.T) {
	u, host, _ := updaterFixture(t)
	host.running.Stage = ""
	u.AfterCycle(context.Background(), 0, nil)
	if len(host.fetched) != 0 || len(host.reports) != 1 || !strings.Contains(host.reports[0].Detail, "installer") {
		t.Fatal(host.fetched, host.reports)
	}
}

func TestStateRecordRejectsUnsafeFolderNames(t *testing.T) {
	for _, data := range []string{
		`{"schema":1,"pending":{"to":{"stage":"..\\..\\Windows","version":"1.3.1","revision":1},"from":{"stage":"r-1-` + strings.Repeat("a", 32) + `","version":"1.3.0","revision":1},"deadline":"2026-11-01T00:00:00Z"}}`,
		`{"schema":1,"cleanups":[{"stage":"/","not_before":"2026-11-01T00:00:00Z","good_cycles":2}]}`,
		`{"schema":2}`,
		`{"schema":1,"surprise":true}`,
	} {
		if _, err := DecodeState([]byte(data)); err == nil {
			t.Errorf("accepted %s", data)
		}
	}
	if s, err := DecodeState(nil); err != nil || s.Schema != 1 {
		t.Fatal(s, err)
	}
}

func TestQuietCyclesDoNotRewriteTheRecord(t *testing.T) {
	u, host, now := updaterFixture(t)
	host.running = Running{Stage: host.newStage, Version: "1.3.1", Revision: 41} // already on the target
	host.instructions.RunningVersion = "1.3.1"
	for i := 0; i < 3; i++ {
		*now = now.Add(time.Minute)
		if action := u.AfterCycle(context.Background(), 0, nil); action != Continue {
			t.Fatal(action)
		}
	}
	if host.saves != 0 || len(host.fetched) != 0 {
		t.Fatal("rewrote the record or fetched on quiet cycles", host.saves, host.fetched)
	}
}
