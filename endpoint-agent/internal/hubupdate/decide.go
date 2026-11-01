package hubupdate

import (
	"strconv"
	"strings"
	"time"

	// The update window is in the server's time zone (Pacific/Auckland). Hub PCs and Pis
	// don't always carry a time zone database, so the build brings its own.
	_ "time/tzdata"
)

const (
	// RetryAfterFailure: a version that failed is tried again the next night, not in a loop.
	RetryAfterFailure = 20 * time.Hour
	// MaxInstructionsAge: older instructions may predate a hold, so they're not acted on.
	MaxInstructionsAge = 30 * time.Minute
	defaultRollback    = 5 * time.Minute
)

// Running is the release this process runs from.
type Running struct {
	Stage    string // release folder; "" when the install predates self-updates
	Version  string
	Revision uint64 // 0 when unknown
}

// Decision says whether to update now, and if not, why not (in plain words).
type Decision struct {
	Update        bool
	Reason        string
	Target        Target
	Artifact      Artifact
	RollbackAfter time.Duration
}

func wait(reason string) Decision { return Decision{Reason: reason} }

// Decide applies D3. An update starts only when the hub is approved, updates aren't held,
// the target is newer than what's running, the local time is inside the update window (or an
// admin pressed "Check now"), and this version didn't fail in the last 20 hours.
func Decide(in Instructions, running Running, last *Failure, platform, arch string, now time.Time) Decision {
	if in.Approval != "approved" {
		return wait("waiting for an admin to approve this hub")
	}
	received, err := time.Parse(time.RFC3339, in.ReceivedAt)
	if err != nil || now.Sub(received) > MaxInstructionsAge || received.Sub(now) > 5*time.Minute {
		return wait("update instructions are out of date")
	}
	if in.Update == nil {
		return wait("no update instructions")
	}
	if in.Update.Held {
		return wait("updates are on hold")
	}
	target := in.Update.Target
	if target == nil {
		return wait("no release to install yet")
	}
	if !newer(*target, running) {
		return wait("up to date")
	}
	inside, err := insideWindow(in.Update.Window, now)
	if err != nil {
		return wait("the update window is invalid")
	}
	// "Check now" skips only the night window. Approval, hold, the ring's target and the
	// 20-hour wait after a failure still apply.
	if !inside && !in.Update.CheckNow {
		return wait("outside the update window")
	}
	if last != nil && last.Version == target.Version && last.Revision == target.ReleaseRevision && now.Sub(last.At) < RetryAfterFailure {
		return wait("this version failed recently; it will be tried again tomorrow night")
	}
	artifact, ok := pickArtifact(target.Artifacts, platform, arch)
	if !ok {
		return wait("no release file for this computer")
	}
	rollback := defaultRollback
	if minutes := in.Update.RollbackAfterMinutes; minutes >= 1 && minutes <= 60 {
		rollback = time.Duration(minutes) * time.Minute
	}
	return Decision{Update: true, Reason: "updating to " + target.Version, Target: *target, Artifact: artifact, RollbackAfter: rollback}
}

// newer: a higher version wins; for the same version a higher release revision wins, but
// only when the running revision is known.
func newer(target Target, running Running) bool {
	order, ok := compareVersions(target.Version, running.Version)
	if !ok || target.ReleaseRevision == 0 {
		return false
	}
	if order != 0 {
		return order > 0
	}
	return running.Revision > 0 && target.ReleaseRevision > running.Revision
}

// insideWindow: start is included, end isn't, and a window like 22:00–02:00 runs past midnight.
func insideWindow(w Window, now time.Time) (bool, error) {
	location, err := time.LoadLocation(w.Timezone)
	if err != nil || w.Timezone == "" {
		return false, errInstructions
	}
	start, ok1 := minuteOfDay(w.Start)
	end, ok2 := minuteOfDay(w.End)
	if !ok1 || !ok2 {
		return false, errInstructions
	}
	local := now.In(location)
	minute := local.Hour()*60 + local.Minute()
	switch {
	case start == end:
		return false, nil
	case start < end:
		return minute >= start && minute < end, nil
	default:
		return minute >= start || minute < end, nil
	}
}

func minuteOfDay(clock string) (int, bool) {
	hours, minutes, found := strings.Cut(clock, ":")
	if !found || len(hours) != 2 || len(minutes) != 2 {
		return 0, false
	}
	h, err1 := strconv.Atoi(hours)
	m, err2 := strconv.Atoi(minutes)
	if err1 != nil || err2 != nil || h < 0 || h > 23 || m < 0 || m > 59 {
		return 0, false
	}
	return h*60 + m, true
}

// pickArtifact chooses the signed package for this machine. Windows hubs take a .zip release
// package, preferring the site-only one (smaller); Linux hubs take their .tar.gz.
func pickArtifact(artifacts []Artifact, platform, arch string) (Artifact, bool) {
	suffix := ".zip"
	if platform == "linux" {
		suffix = ".tar.gz"
	} else if platform != "windows" {
		return Artifact{}, false
	}
	var found Artifact
	ok := false
	for _, a := range artifacts {
		if !a.valid() || a.Architecture != arch || !strings.HasSuffix(a.Filename, suffix) {
			continue
		}
		if !ok || strings.HasPrefix(a.ID, "windows-site-package-") {
			found, ok = a, true
		}
	}
	return found, ok
}
