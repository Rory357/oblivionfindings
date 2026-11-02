package hubupdate

import (
	"bytes"
	"context"
	"errors"
	"time"
)

// Records is the saved State, guarded by a lock shared with the watcher.
type Records interface {
	Lock(ctx context.Context) (unlock func(), err error)
	Load() (State, error)
	Save(State) error
}

// Switcher points the service at a release folder: the Windows service configuration, or
// the Linux "current" link.
type Switcher interface {
	Switch(ctx context.Context, stage string) error
}

// Host is what the Windows service or the Linux launcher provides.
type Host interface {
	Records
	Switcher
	Running() Running
	ReadInstructions() ([]byte, error)
	// Fetch downloads the artifact with the PHP hub's update-fetch command and returns the file.
	Fetch(ctx context.Context, artifact Artifact) (string, error)
	// Install checks the download's signature and unpacks it into a new release folder
	// beside the running one.
	Install(ctx context.Context, download string, target Target, artifact Artifact, running Running) (Release, error)
	// HandOver restarts into the release the service now points at. When watchUntil isn't
	// zero, the running (known good) release also arranges to check the trial at that time,
	// so a new release that can't even start still gets rolled back.
	HandOver(ctx context.Context, watchUntil time.Time) error
	Report(ctx context.Context, r Report) error
	RemoveStage(stage string) error
	Discard(download string)
}

// Action tells the cycle loop what to do next.
type Action int

const (
	Continue Action = iota
	Wait            // a release is on trial: keep cycling at the normal pace, no back-off
	Restart         // the service now points at another release: stop so it can start
)

// WatchGrace: the watcher checks a little after the deadline, so a check-in right at the
// deadline still counts.
const WatchGrace = 15 * time.Second

// ErrNoReleaseKey: this build wasn't given the publisher's key, so it can't check updates.
var ErrNoReleaseKey = errors.New("this hub build has no release key")

type Updater struct {
	Host     Host
	Platform string // windows or linux
	Arch     string
	Now      func() time.Time
	Log      func(string)
}

// good: exit codes 0 (done), 2 (upload deferred) and 4 (waiting for approval) all mean the
// release ran a whole cycle.
func good(code uint32, err error) bool { return err == nil && (code == 0 || code == 2 || code == 4) }

// AfterCycle runs after every collection cycle.
func (u *Updater) AfterCycle(ctx context.Context, code uint32, cycleErr error) Action {
	unlock, err := u.Host.Lock(ctx)
	if err != nil {
		return Continue
	}
	defer unlock()
	state, err := u.Host.Load()
	if err != nil {
		u.log("The hub update record can't be read, so updates are paused.")
		return Continue
	}
	running := u.Host.Running()
	ok := good(code, cycleErr)
	if state.Pending != nil {
		return u.trial(ctx, &state, running, ok)
	}
	before, _ := state.Encode()
	u.flush(ctx, &state)
	u.tidy(&state, running, ok)
	if after, _ := state.Encode(); !bytes.Equal(before, after) {
		// Only when something changed: a Pi's SD card shouldn't be written every minute.
		_ = u.save(state)
	}
	if !ok {
		return Continue
	}
	return u.maybeUpdate(ctx, &state, running)
}

// StartupFailed is called when this release can't start its checks. If it's the release on
// trial, the hub goes straight back to the old one.
func (u *Updater) StartupFailed(ctx context.Context) Action {
	unlock, err := u.Host.Lock(ctx)
	if err != nil {
		return Continue
	}
	defer unlock()
	state, err := u.Host.Load()
	if err != nil || state.Pending == nil || u.Host.Running().Stage != state.Pending.To.Stage {
		return Continue
	}
	return u.rollBack(ctx, &state, "Version "+state.Pending.To.Version+" could not start, so the hub went back to "+state.Pending.From.Version+".", true)
}

func (u *Updater) trial(ctx context.Context, state *State, running Running, ok bool) Action {
	p := *state.Pending
	now := u.now()
	if running.Stage != p.To.Stage {
		// Still the old release: the new one hasn't taken over yet.
		if now.After(p.Deadline) {
			u.rollBack(ctx, state, "Version "+p.To.Version+" did not start, so the hub stayed on "+p.From.Version+".", false)
			return Continue
		}
		return Wait
	}
	if ok && !now.After(p.Deadline) {
		ownsFrom := state.Installed != nil && state.Installed.Stage == p.From.Stage
		installed := p.To
		state.Pending = nil
		state.Installed = &installed
		if ownsFrom {
			// The old release folder goes after a second good cycle, once the watcher that
			// runs from it has finished.
			state.tidyLater(Cleanup{Stage: p.From.Stage, NotBefore: p.Deadline.Add(time.Minute), GoodCycles: 1})
		}
		state.queue(Report{Outcome: "installed", From: p.From.Version, To: p.To.Version})
		if u.save(*state) != nil {
			return Wait
		}
		u.log("Updated to " + p.To.Version + ".")
		u.flush(ctx, state)
		_ = u.save(*state)
		return Continue
	}
	if now.After(p.Deadline) {
		return u.rollBack(ctx, state, lateDetail(p), true)
	}
	return Wait
}

func lateDetail(p Trial) string {
	return "Version " + p.To.Version + " didn't check in within the time allowed, so the hub went back to " + p.From.Version + "."
}

// rollBack points the service back at the old release and, if asked, restarts into it.
func (u *Updater) rollBack(ctx context.Context, state *State, detail string, restart bool) Action {
	p := *state.Pending
	if !goBack(ctx, u.Host, u.Host, state, u.now(), detail) {
		u.log("Could not go back to version " + p.From.Version + " yet; trying again.")
		return Continue
	}
	u.log("Update to " + p.To.Version + " failed. " + detail)
	if !restart {
		return Continue
	}
	if err := u.Host.HandOver(ctx, time.Time{}); err != nil {
		u.log("Could not restart into version " + p.From.Version + "; it will start after the next restart.")
		return Continue
	}
	return Restart
}

// goBack is shared by the running release and the watcher. The record is only changed once
// the service points at the old release again; until then the next check tries again.
func goBack(ctx context.Context, records Records, switcher Switcher, state *State, now time.Time, detail string) bool {
	p := *state.Pending
	if switcher.Switch(ctx, p.From.Stage) != nil {
		return false
	}
	state.Pending = nil
	state.Failure = &Failure{Version: p.To.Version, Revision: p.To.Revision, At: now}
	state.tidyLater(Cleanup{Stage: p.To.Stage, NotBefore: now.Add(2 * time.Minute)})
	state.queue(Report{Outcome: "rolled_back", From: p.From.Version, To: p.To.Version, Detail: detail})
	// If this save fails, the record still names the trial; the next check switches back
	// again (harmless) and records it then.
	_ = records.Save(*state)
	return true
}

func (u *Updater) maybeUpdate(ctx context.Context, state *State, running Running) Action {
	data, err := u.Host.ReadInstructions()
	if err != nil {
		return Continue
	}
	in, err := ParseInstructions(data)
	if err != nil {
		return Continue
	}
	if running.Version == "" {
		running.Version = in.RunningVersion
	}
	if running.Version != in.RunningVersion {
		// These instructions came from another release's heartbeat; wait for this one's.
		return Continue
	}
	d := Decide(in, running, state.Failure, u.Platform, u.Arch, u.now())
	if !d.Update {
		return Continue
	}
	u.log("Updating to version " + d.Target.Version + ".")
	from := Release{Stage: running.Stage, Version: running.Version, Revision: running.Revision}
	fail := func(detail string) Action {
		state.Failure = &Failure{Version: d.Target.Version, Revision: d.Target.ReleaseRevision, At: u.now()}
		state.queue(Report{Outcome: "failed", From: running.Version, To: d.Target.Version, Detail: detail})
		u.log("Update to version " + d.Target.Version + " failed: " + detail)
		_ = u.save(*state)
		u.flush(ctx, state)
		_ = u.save(*state)
		return Continue
	}
	if running.Stage == "" {
		return fail("This hub was installed before self-updates. Run the hub installer once more to turn them on.")
	}
	download, err := u.Host.Fetch(ctx, d.Artifact)
	if err != nil {
		return fail("The new version could not be downloaded.")
	}
	release, err := u.Host.Install(ctx, download, d.Target, d.Artifact, running)
	u.Host.Discard(download)
	if errors.Is(err, ErrNoReleaseKey) {
		return fail("This hub build has no release key, so it can't check updates.")
	}
	if err != nil {
		return fail("The new version failed its signature or file checks, so it wasn't installed.")
	}
	// The download can take a while: the deadline counts from the switch, not the decision.
	state.Pending = &Trial{To: release, From: from, Deadline: u.now().Add(d.RollbackAfter)}
	if u.save(*state) != nil {
		state.Pending = nil
		_ = u.Host.RemoveStage(release.Stage)
		return fail("The hub could not save its update record.")
	}
	if u.Host.Switch(ctx, release.Stage) != nil {
		state.Pending = nil
		_ = u.Host.RemoveStage(release.Stage)
		return fail("The service could not be pointed at the new version.")
	}
	if u.Host.HandOver(ctx, state.Pending.Deadline) != nil {
		if u.Host.Switch(ctx, from.Stage) != nil {
			// Still pointing at the new release: the trial stays, and its deadline brings
			// the hub back.
			return Wait
		}
		state.Pending = nil
		_ = u.Host.RemoveStage(release.Stage)
		return fail("The hub could not restart into the new version.")
	}
	return Restart
}

// tidy removes old release folders after two good cycles. The running folder is never removed.
func (u *Updater) tidy(state *State, running Running, ok bool) {
	now := u.now()
	var kept []Cleanup
	for _, c := range state.Cleanups {
		if c.Stage != running.Stage {
			if ok {
				c.GoodCycles++
			}
			if c.GoodCycles >= 2 && !now.Before(c.NotBefore) && u.Host.RemoveStage(c.Stage) == nil {
				continue
			}
		}
		kept = append(kept, c)
	}
	state.Cleanups = kept
}

// flush sends queued reports in order and stops at the first one that doesn't get through.
func (u *Updater) flush(ctx context.Context, state *State) {
	for len(state.Reports) > 0 {
		if u.Host.Report(ctx, state.Reports[0]) != nil {
			return
		}
		state.Reports = state.Reports[1:]
	}
}

func (u *Updater) save(state State) error {
	err := u.Host.Save(state)
	if err != nil {
		u.log("The hub update record could not be saved.")
	}
	return err
}

func (u *Updater) now() time.Time {
	if u.Now != nil {
		return u.Now()
	}
	return time.Now()
}

func (u *Updater) log(message string) {
	if u.Log != nil {
		u.Log(message)
	}
}

// Watcher is what the watcher needs: the record and the switch.
type Watcher interface {
	Records
	Switcher
}

// Watch runs from the old, known-good release (the Windows update helper, or the Linux
// timer). It waits for the trial's deadline and, if the new release still hasn't checked
// in, points the service back at the old release. It returns true when the caller should
// now restart the service.
func Watch(ctx context.Context, host Watcher, now func() time.Time) (bool, error) {
	state, err := lockedLoad(ctx, host)
	if err != nil || state.Pending == nil {
		return false, err
	}
	trial := *state.Pending
	if delay := trial.Deadline.Add(WatchGrace).Sub(now()); delay > 0 {
		timer := time.NewTimer(delay)
		select {
		case <-ctx.Done():
			timer.Stop()
			return false, ctx.Err()
		case <-timer.C:
		}
	}
	unlock, err := host.Lock(ctx)
	if err != nil {
		return false, err
	}
	defer unlock()
	state, err = host.Load()
	if err != nil {
		return false, err
	}
	if state.Pending == nil || state.Pending.To.Stage != trial.To.Stage || !now().After(state.Pending.Deadline) {
		return false, nil
	}
	if !goBack(ctx, host, host, &state, now(), lateDetail(*state.Pending)) {
		return false, errors.New("could not point the service back at the previous version")
	}
	return true, nil
}

func lockedLoad(ctx context.Context, records Records) (State, error) {
	unlock, err := records.Lock(ctx)
	if err != nil {
		return State{}, err
	}
	defer unlock()
	return records.Load()
}
